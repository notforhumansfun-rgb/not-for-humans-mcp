<?php

declare(strict_types=1);

const NFH_AGENT_WORK_SCHEMA = 'nfh.accepted-work-feed.v1';
const NFH_AGENT_RETURN_SCHEMA = 'nfh.returned-work-feed.v1';
const NFH_AGENT_WORK_VERSION = 1;
const NFH_AGENT_WORK_LIFETIME = 24 * 60 * 60;
const NFH_AGENT_WORK_MAX_LOG_BYTES = 5_000_000;

function nfh_agent_work_directory(): string
{
    $configured = trim((string) (getenv('NFH_AGENT_WORK_DIR') ?: ''));
    $directory = $configured !== ''
        ? $configured
        : (nfh_is_local_cli_runtime() ? nfh_runtime_directory() . '/agent-work' : '/home/notforhumans/.nfh-agent-work');
    if (!str_starts_with($directory, DIRECTORY_SEPARATOR) || is_link($directory)) {
        throw new RuntimeException('Accepted Work storage path is unsafe.');
    }
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Accepted Work storage is unavailable.');
    }
    clearstatcache(true, $directory);
    if (is_link($directory) || (((int) fileperms($directory)) & 0077) !== 0) {
        throw new RuntimeException('Accepted Work storage permissions are unsafe.');
    }
    return $directory;
}

function nfh_agent_work_log_path(): string
{
    return nfh_agent_work_directory() . '/events.jsonl';
}

function nfh_agent_work_request_id(mixed $value): string
{
    if (!is_string($value) || preg_match('/^[a-f0-9]{64}$/', $value) !== 1) {
        throw new InvalidArgumentException('requestId must be a 32-byte lowercase hexadecimal id.');
    }
    return $value;
}

function nfh_agent_work_summary(mixed $value): string
{
    if (!is_string($value)) throw new InvalidArgumentException('summary must be text.');
    $summary = trim($value);
    $length = mb_strlen($summary, 'UTF-8');
    if ($length < 20 || $length > 600) throw new InvalidArgumentException('summary must contain between 20 and 600 characters.');
    if (preg_match('/[\x00-\x1F\x7F\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', $summary) === 1) {
        throw new InvalidArgumentException('summary contains unsupported control characters.');
    }
    return $summary;
}

function nfh_agent_work_optional_token_id(mixed $value): ?int
{
    if ($value === null || $value === '') return null;
    return nfh_agent_wanted_token_id($value);
}

/** @param array<string, mixed> $input @return array<string, mixed> */
function nfh_agent_return_prepare(array $input, ?int $now = null): array
{
    $now ??= time();
    $requestId = nfh_agent_work_request_id($input['requestId'] ?? null);
    $request = nfh_agent_work_find_request($requestId, $now);
    if ($request === null) throw new RuntimeException('The signed mission does not exist.');
    $worker = nfh_agent_wanted_owner($input['worker'] ?? null);
    $owner = (string) $request['owner'];
    if (hash_equals($owner, $worker)) throw new InvalidArgumentException('worker must be distinct from the mission owner.');
    $capacity = nfh_agent_work_capacity($request);
    if ($capacity !== null && count(nfh_agent_work_request_receipts($requestId)) >= $capacity) {
        throw new RuntimeException('This mission has reached its accepted-agent capacity.');
    }
    $summary = nfh_agent_work_summary($input['summary'] ?? null);
    $payload = [
        'version' => NFH_AGENT_WORK_VERSION, 'action' => 'RETURN_WORK', 'state' => 'RETURNED_UNVERIFIED',
        'chainId' => 1, 'collection' => strtolower(NFH_AGENT_WANTED_COLLECTION),
        'requestId' => $requestId, 'owner' => $owner, 'tokenId' => (int) $request['tokenId'],
        'worker' => $worker, 'workerTokenId' => nfh_agent_work_optional_token_id($input['workerTokenId'] ?? null),
        'summary' => $summary, 'evidenceHash' => '0x' . hash('sha256', $summary),
        'issuedAt' => $now, 'expiresAt' => $now + NFH_AGENT_WORK_LIFETIME, 'nonce' => bin2hex(random_bytes(16)),
    ];
    return [
        'schema' => NFH_AGENT_RETURN_SCHEMA, 'status' => 'prepared_unsigned', 'payload' => $payload,
        'message' => nfh_agent_return_message($payload), 'signingMethod' => 'personal_sign',
        'requiredSigner' => 'worker', 'publishEndpoint' => 'https://mcp.notforhumans.fun/agent-work/return/publish',
        'mcpSigned' => false, 'mcpSubmitted' => false,
        'warnings' => [
            'This creates a public worker-signed RETURNED_UNVERIFIED submission, not accepted work, payment, escrow, or capability proof.',
            'Only a later separate owner-and-worker signed ACCEPT receipt creates accepted-work evidence.',
            'NFH stores a hash of the signature, never the raw signature.',
        ],
    ];
}

/** @param array<string, mixed> $payload @return array<string, mixed> */
function nfh_agent_return_validate(array $payload, ?int $now = null): array
{
    $now ??= time();
    if (($payload['version'] ?? null) !== NFH_AGENT_WORK_VERSION || ($payload['action'] ?? null) !== 'RETURN_WORK'
        || ($payload['state'] ?? null) !== 'RETURNED_UNVERIFIED' || ($payload['chainId'] ?? null) !== 1
        || !is_string($payload['collection'] ?? null) || strcasecmp((string) $payload['collection'], NFH_AGENT_WANTED_COLLECTION) !== 0
    ) throw new InvalidArgumentException('The Returned Work payload domain is invalid.');
    $allowed = ['version', 'action', 'state', 'chainId', 'collection', 'requestId', 'owner', 'tokenId', 'worker', 'workerTokenId', 'summary', 'evidenceHash', 'issuedAt', 'expiresAt', 'nonce'];
    if (array_diff(array_keys($payload), $allowed) !== []) throw new InvalidArgumentException('The Returned Work payload contains unsupported fields.');
    $issuedAt = $payload['issuedAt'] ?? null;
    $expiresAt = $payload['expiresAt'] ?? null;
    $nonce = $payload['nonce'] ?? null;
    if (!is_int($issuedAt) || !is_int($expiresAt) || $issuedAt < $now - NFH_AGENT_WORK_LIFETIME || $issuedAt > $now + 60
        || $expiresAt < $now || $expiresAt !== $issuedAt + NFH_AGENT_WORK_LIFETIME
        || !is_string($nonce) || preg_match('/^[a-f0-9]{32}$/', $nonce) !== 1
    ) throw new InvalidArgumentException('The Returned Work payload timing or nonce is invalid.');
    $summary = nfh_agent_work_summary($payload['summary'] ?? null);
    $evidenceHash = $payload['evidenceHash'] ?? null;
    if (!is_string($evidenceHash) || !hash_equals('0x' . hash('sha256', $summary), strtolower($evidenceHash))) {
        throw new InvalidArgumentException('evidenceHash does not match the exact public summary.');
    }
    $owner = nfh_agent_wanted_owner($payload['owner'] ?? null);
    $worker = nfh_agent_wanted_owner($payload['worker'] ?? null);
    if (hash_equals($owner, $worker)) throw new InvalidArgumentException('worker must be distinct from the mission owner.');
    return [
        'version' => NFH_AGENT_WORK_VERSION, 'action' => 'RETURN_WORK', 'state' => 'RETURNED_UNVERIFIED',
        'chainId' => 1, 'collection' => strtolower(NFH_AGENT_WANTED_COLLECTION),
        'requestId' => nfh_agent_work_request_id($payload['requestId'] ?? null), 'owner' => $owner,
        'tokenId' => nfh_agent_wanted_token_id($payload['tokenId'] ?? null), 'worker' => $worker,
        'workerTokenId' => nfh_agent_work_optional_token_id($payload['workerTokenId'] ?? null),
        'summary' => $summary, 'evidenceHash' => strtolower($evidenceHash),
        'issuedAt' => $issuedAt, 'expiresAt' => $expiresAt, 'nonce' => $nonce,
    ];
}

/** @param array<string, mixed> $payload */
function nfh_agent_return_message(array $payload): string
{
    $workerToken = $payload['workerTokenId'] === null ? 'none' : (string) $payload['workerTokenId'];
    return "NOT FOR HUMANS Returned Work\n"
        . "Version: {$payload['version']}\nDomain: notforhumans.fun\nAction: {$payload['action']}\nState: {$payload['state']}\n"
        . "Chain ID: {$payload['chainId']}\nCollection: {$payload['collection']}\nRequest ID: {$payload['requestId']}\n"
        . "Mission Owner: {$payload['owner']}\nMission NFH Token ID: {$payload['tokenId']}\nWorker Wallet: {$payload['worker']}\n"
        . "Worker NFH Token ID: {$workerToken}\nResult Summary: {$payload['summary']}\nEvidence Hash: {$payload['evidenceHash']}\n"
        . 'Issued At: ' . gmdate('c', $payload['issuedAt']) . "\nExpiration Time: " . gmdate('c', $payload['expiresAt']) . "\n"
        . "Nonce: {$payload['nonce']}\n"
        . 'Statement: This worker-signed submission is not accepted work, payment, escrow, capability proof, or transaction authority. A separate dual-signed ACCEPT receipt is required.';
}

/** @return array<string, mixed>|null */
function nfh_agent_work_find_request(string $requestId, ?int $now = null): ?array
{
    $now ??= time();
    $path = nfh_agent_wanted_log_path();
    if (!is_file($path) || is_link($path)) return null;
    $handle = fopen($path, 'rb');
    if ($handle === false) throw new RuntimeException('Agent Wanted storage is unavailable.');
    try {
        if (!flock($handle, LOCK_SH)) throw new RuntimeException('Agent Wanted storage lock failed.');
        $events = nfh_agent_wanted_read_events($handle);
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
    $request = null;
    $closed = false;
    foreach ($events as $event) {
        if (!hash_equals((string) ($event['requestId'] ?? ''), $requestId)) continue;
        if (($event['type'] ?? null) === 'publish') $request = $event;
        if (($event['type'] ?? null) === 'close') $closed = true;
    }
    if ($closed || !is_array($request)) return null;
    $expiresAt = strtotime((string) ($request['expiresAt'] ?? ''));
    return $expiresAt !== false && $expiresAt > $now ? $request : null;
}

/** @param array<string, mixed> $request */
function nfh_agent_work_capacity(array $request): ?int
{
    $kind = is_string($request['missionKind'] ?? null) ? $request['missionKind'] : 'one_to_one';
    if ($kind === 'open_edition') return null;
    if ($kind === 'edition' && is_int($request['maxAgents'] ?? null)) return $request['maxAgents'];
    return 1;
}

/** @return array<int, array<string, mixed>> */
function nfh_agent_work_request_receipts(string $requestId): array
{
    $path = nfh_agent_work_log_path();
    if (!is_file($path)) return [];
    if (is_link($path)) throw new RuntimeException('Accepted Work storage file is unsafe.');
    $handle = fopen($path, 'rb');
    if ($handle === false) throw new RuntimeException('Accepted Work storage is unavailable.');
    try {
        if (!flock($handle, LOCK_SH)) throw new RuntimeException('Accepted Work storage lock failed.');
        return array_values(array_filter(
            nfh_agent_work_read_events($handle),
            static fn (array $event): bool => ($event['type'] ?? null) === 'accept'
                && hash_equals((string) ($event['requestId'] ?? ''), $requestId),
        ));
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

/** @param array<string, mixed> $input @return array<string, mixed> */
function nfh_agent_work_prepare(array $input, ?int $now = null): array
{
    $now ??= time();
    $requestId = nfh_agent_work_request_id($input['requestId'] ?? null);
    $request = nfh_agent_work_find_request($requestId, $now);
    if ($request === null) throw new RuntimeException('The signed mission does not exist.');
    $owner = nfh_agent_wanted_owner($input['owner'] ?? null);
    if (!hash_equals((string) $request['owner'], $owner)) throw new RuntimeException('Only the mission owner can accept its work.');
    $worker = nfh_agent_wanted_owner($input['worker'] ?? null);
    if (hash_equals($owner, $worker)) throw new InvalidArgumentException('worker must be distinct from the mission owner.');
    $receipts = nfh_agent_work_request_receipts($requestId);
    foreach ($receipts as $receipt) {
        if (hash_equals((string) ($receipt['worker'] ?? ''), $worker)) {
            throw new RuntimeException('This worker already has an accepted receipt for the mission.');
        }
    }
    $capacity = nfh_agent_work_capacity($request);
    if ($capacity !== null && count($receipts) >= $capacity) {
        throw new RuntimeException('This mission has reached its accepted-agent capacity.');
    }
    $summary = nfh_agent_work_summary($input['summary'] ?? null);
    $workerTokenId = nfh_agent_work_optional_token_id($input['workerTokenId'] ?? null);
    $learning = nfh_agent_learning_input($input['learning'] ?? null);
    $payload = [
        'version' => NFH_AGENT_WORK_VERSION, 'action' => 'ACCEPT_WORK', 'decision' => 'ACCEPT',
        'chainId' => 1, 'collection' => strtolower(NFH_AGENT_WANTED_COLLECTION),
        'requestId' => $requestId, 'owner' => $owner, 'tokenId' => (int) $request['tokenId'],
        'worker' => $worker, 'workerTokenId' => $workerTokenId, 'summary' => $summary, 'learning' => $learning,
        'evidenceHash' => '0x' . hash('sha256', $summary),
        'issuedAt' => $now, 'expiresAt' => $now + NFH_AGENT_WORK_LIFETIME, 'nonce' => bin2hex(random_bytes(16)),
    ];
    return nfh_agent_work_prepared_packet($payload);
}

/** @param array<string, mixed> $payload @return array<string, mixed> */
function nfh_agent_work_validate(array $payload, ?int $now = null): array
{
    $now ??= time();
    if (($payload['version'] ?? null) !== NFH_AGENT_WORK_VERSION || ($payload['action'] ?? null) !== 'ACCEPT_WORK'
        || ($payload['decision'] ?? null) !== 'ACCEPT' || ($payload['chainId'] ?? null) !== 1
        || !is_string($payload['collection'] ?? null) || strcasecmp((string) $payload['collection'], NFH_AGENT_WANTED_COLLECTION) !== 0
    ) throw new InvalidArgumentException('The Accepted Work payload domain is invalid.');
    $issuedAt = $payload['issuedAt'] ?? null;
    $expiresAt = $payload['expiresAt'] ?? null;
    $nonce = $payload['nonce'] ?? null;
    if (!is_int($issuedAt) || !is_int($expiresAt) || $issuedAt < $now - NFH_AGENT_WORK_LIFETIME || $issuedAt > $now + 60
        || $expiresAt < $now || $expiresAt !== $issuedAt + NFH_AGENT_WORK_LIFETIME
        || !is_string($nonce) || preg_match('/^[a-f0-9]{32}$/', $nonce) !== 1
    ) throw new InvalidArgumentException('The Accepted Work payload timing or nonce is invalid.');
    $allowed = ['version', 'action', 'decision', 'chainId', 'collection', 'requestId', 'owner', 'tokenId', 'worker', 'workerTokenId', 'summary', 'learning', 'evidenceHash', 'issuedAt', 'expiresAt', 'nonce'];
    if (array_diff(array_keys($payload), $allowed) !== []) throw new InvalidArgumentException('The Accepted Work payload contains unsupported fields.');
    $summary = nfh_agent_work_summary($payload['summary'] ?? null);
    $evidenceHash = $payload['evidenceHash'] ?? null;
    if (!is_string($evidenceHash) || !hash_equals('0x' . hash('sha256', $summary), strtolower($evidenceHash))) {
        throw new InvalidArgumentException('evidenceHash does not match the exact public summary.');
    }
    $owner = nfh_agent_wanted_owner($payload['owner'] ?? null);
    $worker = nfh_agent_wanted_owner($payload['worker'] ?? null);
    if (hash_equals($owner, $worker)) throw new InvalidArgumentException('worker must be distinct from the mission owner.');
    return [
        'version' => NFH_AGENT_WORK_VERSION, 'action' => 'ACCEPT_WORK', 'decision' => 'ACCEPT',
        'chainId' => 1, 'collection' => strtolower(NFH_AGENT_WANTED_COLLECTION),
        'requestId' => nfh_agent_work_request_id($payload['requestId'] ?? null), 'owner' => $owner,
        'tokenId' => nfh_agent_wanted_token_id($payload['tokenId'] ?? null), 'worker' => $worker,
        'workerTokenId' => nfh_agent_work_optional_token_id($payload['workerTokenId'] ?? null),
        'summary' => $summary, 'learning' => nfh_agent_learning_input($payload['learning'] ?? null), 'evidenceHash' => strtolower($evidenceHash),
        'issuedAt' => $issuedAt, 'expiresAt' => $expiresAt, 'nonce' => $nonce,
    ];
}

/** @param array<string, mixed> $payload */
function nfh_agent_work_message(array $payload): string
{
    $workerToken = $payload['workerTokenId'] === null ? 'none' : (string) $payload['workerTokenId'];
    $learning = $payload['learning'] === null
        ? 'none'
        : json_encode($payload['learning'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    return "NOT FOR HUMANS Accepted Work\n"
        . "Version: {$payload['version']}\nDomain: notforhumans.fun\nAction: {$payload['action']}\nDecision: {$payload['decision']}\n"
        . "Chain ID: {$payload['chainId']}\nCollection: {$payload['collection']}\nRequest ID: {$payload['requestId']}\n"
        . "Mission Owner: {$payload['owner']}\nMission NFH Token ID: {$payload['tokenId']}\nWorker Wallet: {$payload['worker']}\n"
        . "Worker NFH Token ID: {$workerToken}\nResult Summary: {$payload['summary']}\nLearning: {$learning}\nEvidence Hash: {$payload['evidenceHash']}\n"
        . 'Issued At: ' . gmdate('c', $payload['issuedAt']) . "\nExpiration Time: " . gmdate('c', $payload['expiresAt']) . "\n"
        . "Nonce: {$payload['nonce']}\n"
        . 'Statement: Both signatures publish an off-chain ACCEPT receipt for this exact work summary. It is not payment, escrow, a capability guarantee, a claim guarantee, or transaction authority.';
}

/** @param array<string, mixed> $payload @return array<string, mixed> */
function nfh_agent_work_prepared_packet(array $payload): array
{
    return [
        'schema' => NFH_AGENT_WORK_SCHEMA, 'status' => 'prepared_unsigned', 'payload' => $payload,
        'message' => nfh_agent_work_message($payload), 'signingMethod' => 'personal_sign',
        'requiredSigners' => ['owner', 'worker'], 'publishEndpoint' => 'https://mcp.notforhumans.fun/agent-work/publish',
        'mcpSigned' => false, 'mcpSubmitted' => false,
        'warnings' => [
            'The mission owner and named worker must sign the identical readable message.',
            'This public receipt authorizes no payment, transaction, escrow, or guaranteed weekly selection.',
            'Learning fields are public generalized evidence. Never include secrets, credentials, keys, or private owner conversations.',
            'A proposed skill is inert until separately tested and promoted by the current owner in the same ownership epoch.',
            'NFH stores hashes of both signatures, never the raw signatures.',
        ],
    ];
}

/** @param array<string, mixed> $input @return array<string, mixed> */
function nfh_agent_work_review(array $input, ?int $now = null): array
{
    $now ??= time();
    $payload = $input['payload'] ?? null;
    $ownerSignature = $input['ownerSignature'] ?? null;
    if (!is_array($payload) || !is_string($ownerSignature)) {
        throw new InvalidArgumentException('payload and ownerSignature are required.');
    }
    $payload = nfh_agent_work_validate($payload, $now);
    $message = nfh_agent_work_message($payload);
    $ownerSigner = strtolower(nfh_verify_recover($message, $ownerSignature, nfh_verify_config()));
    if (!hash_equals($payload['owner'], $ownerSigner)) throw new RuntimeException('The owner signature does not match the mission owner.');
    $request = nfh_agent_work_find_request($payload['requestId'], $now);
    if ($request === null || !hash_equals((string) $request['owner'], $payload['owner']) || (int) $request['tokenId'] !== $payload['tokenId']) {
        throw new RuntimeException('The receipt does not match the original signed mission.');
    }
    return [
        'schema' => NFH_AGENT_WORK_SCHEMA, 'status' => 'owner_signed_awaiting_worker',
        'payload' => $payload, 'message' => $message, 'ownerSignatureVerified' => true,
        'requiredWorker' => $payload['worker'], 'expiresAt' => gmdate('c', $payload['expiresAt']),
        'warning' => 'Sign only if you are the named worker and the exact public summary is accurate. This is not a transaction or guaranteed claim.',
    ];
}

/** @return array<int, array<string, mixed>> */
function nfh_agent_work_read_events($handle): array
{
    rewind($handle);
    $raw = stream_get_contents($handle, NFH_AGENT_WORK_MAX_LOG_BYTES + 1);
    if (!is_string($raw) || strlen($raw) > NFH_AGENT_WORK_MAX_LOG_BYTES) throw new RuntimeException('Accepted Work storage exceeded its safe read limit.');
    $events = [];
    foreach (preg_split('/\r?\n/', trim($raw)) ?: [] as $line) {
        if ($line === '') continue;
        try { $event = json_decode($line, true, flags: JSON_THROW_ON_ERROR); } catch (JsonException) { continue; }
        if (is_array($event)) $events[] = $event;
    }
    return $events;
}

/** @param array<string, mixed> $event */
function nfh_agent_work_append($handle, array $event): void
{
    $encoded = json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    fseek($handle, 0, SEEK_END);
    $position = ftell($handle);
    if ($position === false || $position + strlen($encoded) > NFH_AGENT_WORK_MAX_LOG_BYTES) throw new RuntimeException('Accepted Work storage is full.');
    if (fwrite($handle, $encoded) !== strlen($encoded) || !fflush($handle)) throw new RuntimeException('Accepted Work storage write failed.');
}

/** @param array<string, mixed> $record @return array<string, mixed> */
function nfh_agent_work_public_record(array $record): array
{
    return [
        'receiptId' => (string) $record['receiptId'], 'decision' => 'ACCEPT', 'requestId' => (string) $record['requestId'],
        'tokenId' => (int) $record['tokenId'], 'owner' => (string) $record['owner'], 'wallet' => (string) $record['worker'],
        'workerTokenId' => is_int($record['workerTokenId'] ?? null) ? $record['workerTokenId'] : null,
        'workerOwnershipEpochId' => is_string($record['workerOwnershipEpochId'] ?? null) ? $record['workerOwnershipEpochId'] : null,
        'summary' => (string) $record['summary'], 'evidenceHash' => (string) $record['evidenceHash'],
        'learningReceiptId' => is_int($record['workerTokenId'] ?? null) ? hash('sha256', 'nfh-learning|' . (string) $record['receiptId']) : null,
        'acceptedAt' => (string) $record['acceptedAt'], 'ownershipVerifiedAt' => (string) $record['ownershipVerifiedAt'],
        'signaturesVerified' => ['owner' => true, 'worker' => true], 'authority' => 'public-work-receipt-only',
    ];
}

/** @param array<string, mixed> $input @return array<string, mixed> */
function nfh_agent_work_publish(array $input, ?int $now = null): array
{
    $now ??= time();
    $payload = $input['payload'] ?? null;
    $ownerSignature = $input['ownerSignature'] ?? null;
    $workerSignature = $input['workerSignature'] ?? null;
    if (!is_array($payload) || !is_string($ownerSignature) || !is_string($workerSignature)) {
        throw new InvalidArgumentException('payload, ownerSignature, and workerSignature are required.');
    }
    $payload = nfh_agent_work_validate($payload, $now);
    $message = nfh_agent_work_message($payload);
    $config = nfh_verify_config();
    $ownerSigner = strtolower(nfh_verify_recover($message, $ownerSignature, $config));
    $workerSigner = strtolower(nfh_verify_recover($message, $workerSignature, $config));
    if (!hash_equals($payload['owner'], $ownerSigner)) throw new RuntimeException('The owner signature does not match the mission owner.');
    if (!hash_equals($payload['worker'], $workerSigner)) throw new RuntimeException('The worker signature does not match the named worker.');
    $request = nfh_agent_work_find_request($payload['requestId'], $now);
    if ($request === null || !hash_equals((string) $request['owner'], $payload['owner']) || (int) $request['tokenId'] !== $payload['tokenId']) {
        throw new RuntimeException('The receipt does not match the original signed mission.');
    }
    $ownerResult = nfh_verify_rpc('eth_call', [[
        'to' => NFH_AGENT_WANTED_COLLECTION, 'data' => '0x6352211e' . nfh_uint256_calldata_word($payload['tokenId']),
    ], 'latest'], $config);
    $liveOwner = nfh_decode_owner_result($ownerResult);
    if ($liveOwner === null || strcasecmp($liveOwner, $ownerSigner) !== 0) throw new RuntimeException('The mission signer no longer owns its NFH.');
    $workerEpoch = null;
    if ($payload['workerTokenId'] !== null) {
        $workerOwnerResult = nfh_verify_rpc('eth_call', [[
            'to' => NFH_AGENT_WANTED_COLLECTION, 'data' => '0x6352211e' . nfh_uint256_calldata_word($payload['workerTokenId']),
        ], 'latest'], $config);
        $workerOwner = nfh_decode_owner_result($workerOwnerResult);
        if ($workerOwner === null || strcasecmp($workerOwner, $workerSigner) !== 0) throw new RuntimeException('The worker wallet does not own the named worker NFH.');
        $workerEpoch = nfh_agent_ownership_epoch_observe($payload['workerTokenId'], $workerSigner, $now);
    }
    $receiptId = hash('sha256', $message);
    $record = [
        'type' => 'accept', 'schema' => NFH_AGENT_WORK_SCHEMA, 'receiptId' => $receiptId,
        'requestId' => $payload['requestId'], 'tokenId' => $payload['tokenId'], 'owner' => $payload['owner'],
        'worker' => $payload['worker'], 'workerTokenId' => $payload['workerTokenId'], 'summary' => $payload['summary'],
        'workerOwnershipEpochId' => is_array($workerEpoch) ? $workerEpoch['epochId'] : null,
        'learning' => is_array($payload['learning'] ?? null) ? $payload['learning'] : null,
        'evidenceHash' => $payload['evidenceHash'], 'acceptedAt' => gmdate('c', $now), 'ownershipVerifiedAt' => gmdate('c', $now),
        'ownerSignatureHash' => hash('sha256', strtolower($ownerSignature)),
        'workerSignatureHash' => hash('sha256', strtolower($workerSignature)), 'messageHash' => hash('sha256', $message),
    ];
    $path = nfh_agent_work_log_path();
    if (is_link($path)) throw new RuntimeException('Accepted Work storage file is unsafe.');
    $handle = fopen($path, 'c+b');
    if ($handle === false) throw new RuntimeException('Accepted Work storage is unavailable.');
    $storedRecord = $record;
    $replayed = false;
    try {
        if (!flock($handle, LOCK_EX)) throw new RuntimeException('Accepted Work storage lock failed.');
        chmod($path, 0600);
        $acceptedCount = 0;
        foreach (nfh_agent_work_read_events($handle) as $event) {
            if (($event['requestId'] ?? null) === $payload['requestId']) {
                if (($event['receiptId'] ?? null) === $receiptId) {
                    $storedRecord = $event;
                    $replayed = true;
                    break;
                }
                if (($event['type'] ?? null) !== 'accept') continue;
                if (hash_equals((string) ($event['worker'] ?? ''), $payload['worker'])) {
                    throw new RuntimeException('This worker already has an accepted receipt for the mission.');
                }
                $acceptedCount++;
            }
        }
        if (!$replayed) {
            $capacity = nfh_agent_work_capacity($request);
            if ($capacity !== null && $acceptedCount >= $capacity) {
                throw new RuntimeException('This mission has reached its accepted-agent capacity.');
            }
            if (!nfh_rate_limit('accepted-work-wallet', $ownerSigner, 120, 3600, $now)) {
                throw new RuntimeException('This owner has accepted too many work receipts.');
            }
            nfh_agent_work_append($handle, $record);
        }
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
    nfh_agent_brain_record_accepted_work($storedRecord, $request, $now);
    return ['ok' => true, 'receipt' => nfh_agent_work_public_record($storedRecord), 'replayed' => $replayed];
}

/** @return array<string, mixed> */
function nfh_agent_work_feed(int $limit = 100, ?int $now = null): array
{
    $now ??= time();
    if ($limit < 1 || $limit > 500) throw new InvalidArgumentException('limit must be between 1 and 500.');
    $path = nfh_agent_work_log_path();
    $events = [];
    if (is_file($path)) {
        if (is_link($path)) throw new RuntimeException('Accepted Work storage file is unsafe.');
        $handle = fopen($path, 'rb');
        if ($handle === false) throw new RuntimeException('Accepted Work storage is unavailable.');
        try {
            if (!flock($handle, LOCK_SH)) throw new RuntimeException('Accepted Work storage lock failed.');
            $events = nfh_agent_work_read_events($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
    $records = array_values(array_filter($events, static fn (array $event): bool => ($event['type'] ?? null) === 'accept' && ($event['schema'] ?? null) === NFH_AGENT_WORK_SCHEMA));
    usort($records, static fn (array $a, array $b): int => strcmp((string) $b['acceptedAt'], (string) $a['acceptedAt']));
    $clients = [];
    $workers = [];
    $clientCounts = [];
    $visitorAccepted = 0;
    $evidenceAccepted = 0;
    foreach ($records as $record) {
        $owner = strtolower((string) ($record['owner'] ?? ''));
        $worker = strtolower((string) ($record['worker'] ?? ''));
        if (preg_match('/^0x[a-f0-9]{40}$/', $owner) === 1) {
            $clients[$owner] = true;
            $clientCounts[$owner] = ($clientCounts[$owner] ?? 0) + 1;
        }
        if (preg_match('/^0x[a-f0-9]{40}$/', $worker) === 1) $workers[$worker] = true;
        if (($record['workerTokenId'] ?? null) === null) $visitorAccepted++;
        if (preg_match('/^0x[a-fA-F0-9]{64}$/', (string) ($record['evidenceHash'] ?? '')) === 1) $evidenceAccepted++;
    }
    $total = count($records);
    $returned = min($limit, $total);
    return [
        'schema' => NFH_AGENT_WORK_SCHEMA, 'status' => 'active', 'updatedAt' => gmdate('c', $now),
        'receipts' => array_map('nfh_agent_work_public_record', array_slice($records, 0, $limit)), 'nextCursor' => null,
        'summary' => [
            'acceptedReceipts' => $total,
            'distinctClientWallets' => count($clients),
            'distinctWorkerWallets' => count($workers),
            'repeatClientWallets' => count(array_filter($clientCounts, static fn(int $count): bool => $count > 1)),
            'visitorAcceptedReceipts' => $visitorAccepted,
            'evidenceAcceptedReceipts' => $evidenceAccepted,
            'returned' => $returned,
            'truncated' => $total > $returned,
        ],
        'source' => ['name' => 'NFH Accepted Work', 'url' => 'https://mcp.notforhumans.fun/agent-work',
            'warning' => 'A dual-signed ACCEPT receipt is public evidence, not payment, capability proof, transaction authority, or guaranteed weekly selection.'],
    ];
}

/** @param array<string, mixed> $record @return array<string, mixed> */
function nfh_agent_return_public_record(array $record): array
{
    return [
        'returnId' => (string) $record['returnId'], 'state' => 'RETURNED_UNVERIFIED',
        'requestId' => (string) $record['requestId'], 'tokenId' => (int) $record['tokenId'],
        'owner' => (string) $record['owner'], 'wallet' => (string) $record['worker'],
        'workerTokenId' => is_int($record['workerTokenId'] ?? null) ? $record['workerTokenId'] : null,
        'summary' => (string) $record['summary'], 'evidenceHash' => (string) $record['evidenceHash'],
        'returnedAt' => (string) $record['returnedAt'], 'workerSignatureVerified' => true,
        'authority' => 'self-reported-return-only',
        'warning' => 'Worker-signed return only. Not accepted work, payment, escrow, capability proof, or transaction authority.',
    ];
}

/** @param array<string, mixed> $input @return array<string, mixed> */
function nfh_agent_return_publish(array $input, ?int $now = null): array
{
    $now ??= time();
    $payload = $input['payload'] ?? null;
    $workerSignature = $input['workerSignature'] ?? null;
    if (!is_array($payload) || !is_string($workerSignature)) {
        throw new InvalidArgumentException('payload and workerSignature are required.');
    }
    $payload = nfh_agent_return_validate($payload, $now);
    $message = nfh_agent_return_message($payload);
    $config = nfh_verify_config();
    $workerSigner = strtolower(nfh_verify_recover($message, $workerSignature, $config));
    if (!hash_equals($payload['worker'], $workerSigner)) throw new RuntimeException('The worker signature does not match the named worker.');
    $request = nfh_agent_work_find_request($payload['requestId'], $now);
    if ($request === null || !hash_equals((string) $request['owner'], $payload['owner']) || (int) $request['tokenId'] !== $payload['tokenId']) {
        throw new RuntimeException('The return does not match the original signed mission.');
    }
    $capacity = nfh_agent_work_capacity($request);
    if ($capacity !== null && count(nfh_agent_work_request_receipts($payload['requestId'])) >= $capacity) {
        throw new RuntimeException('This mission has reached its accepted-agent capacity.');
    }
    if ($payload['workerTokenId'] !== null) {
        $workerOwnerResult = nfh_verify_rpc('eth_call', [[
            'to' => NFH_AGENT_WANTED_COLLECTION, 'data' => '0x6352211e' . nfh_uint256_calldata_word($payload['workerTokenId']),
        ], 'latest'], $config);
        $workerOwner = nfh_decode_owner_result($workerOwnerResult);
        if ($workerOwner === null || strcasecmp($workerOwner, $workerSigner) !== 0) throw new RuntimeException('The worker wallet does not own the named worker NFH.');
    }
    $returnId = hash('sha256', $message);
    $record = [
        'type' => 'return', 'schema' => NFH_AGENT_RETURN_SCHEMA, 'returnId' => $returnId,
        'requestId' => $payload['requestId'], 'tokenId' => $payload['tokenId'], 'owner' => $payload['owner'],
        'worker' => $payload['worker'], 'workerTokenId' => $payload['workerTokenId'], 'summary' => $payload['summary'],
        'evidenceHash' => $payload['evidenceHash'], 'returnedAt' => gmdate('c', $now),
        'workerSignatureHash' => hash('sha256', strtolower($workerSignature)), 'messageHash' => hash('sha256', $message),
    ];
    $path = nfh_agent_work_log_path();
    if (is_link($path)) throw new RuntimeException('Accepted Work storage file is unsafe.');
    $handle = fopen($path, 'c+b');
    if ($handle === false) throw new RuntimeException('Accepted Work storage is unavailable.');
    $storedRecord = $record;
    $replayed = false;
    try {
        if (!flock($handle, LOCK_EX)) throw new RuntimeException('Accepted Work storage lock failed.');
        chmod($path, 0600);
        foreach (nfh_agent_work_read_events($handle) as $event) {
            if (($event['returnId'] ?? null) === $returnId) {
                $storedRecord = $event;
                $replayed = true;
                break;
            }
        }
        if (!$replayed) {
            if (!nfh_rate_limit('returned-work-wallet', $workerSigner, 20, 3600, $now)) {
                throw new RuntimeException('This worker has returned too many work submissions.');
            }
            nfh_agent_work_append($handle, $record);
        }
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
    return ['ok' => true, 'return' => nfh_agent_return_public_record($storedRecord), 'replayed' => $replayed];
}

/** @return array<string, mixed> */
function nfh_agent_return_feed(int $limit = 100, ?string $requestId = null, ?int $now = null): array
{
    $now ??= time();
    if ($limit < 1 || $limit > 500) throw new InvalidArgumentException('limit must be between 1 and 500.');
    if ($requestId !== null) $requestId = nfh_agent_work_request_id($requestId);
    $events = [];
    $path = nfh_agent_work_log_path();
    if (is_file($path)) {
        if (is_link($path)) throw new RuntimeException('Accepted Work storage file is unsafe.');
        $handle = fopen($path, 'rb');
        if ($handle === false) throw new RuntimeException('Accepted Work storage is unavailable.');
        try {
            if (!flock($handle, LOCK_SH)) throw new RuntimeException('Accepted Work storage lock failed.');
            $events = nfh_agent_work_read_events($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
    $returns = array_values(array_filter($events, static fn (array $event): bool => ($event['type'] ?? null) === 'return'
        && ($event['schema'] ?? null) === NFH_AGENT_RETURN_SCHEMA
        && ($requestId === null || hash_equals((string) ($event['requestId'] ?? ''), $requestId))));
    $accepted = array_values(array_filter($events, static fn (array $event): bool => ($event['type'] ?? null) === 'accept'
        && ($requestId === null || hash_equals((string) ($event['requestId'] ?? ''), $requestId))));
    usort($returns, static fn (array $a, array $b): int => strcmp((string) $b['returnedAt'], (string) $a['returnedAt']));
    return [
        'schema' => NFH_AGENT_RETURN_SCHEMA, 'status' => 'active', 'updatedAt' => gmdate('c', $now),
        'returns' => array_map('nfh_agent_return_public_record', array_slice($returns, 0, $limit)),
        'funnel' => ['returnedUnverified' => count($returns), 'accepted' => count($accepted), 'paid' => null],
        'summary' => ['returned' => min($limit, count($returns)), 'truncated' => count($returns) > $limit],
        'nextCursor' => null,
        'source' => ['name' => 'NFH Returned Work', 'url' => 'https://mcp.notforhumans.fun/agent-work/returns',
            'warning' => 'Returns are worker-signed self-reports. Acceptance requires a separate dual-signed ACCEPT receipt; payment is not tracked.'],
    ];
}

/** @return array<int, array<string, mixed>> */
function nfh_agent_work_tool_definitions(array $addressSchema): array
{
    $read = ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => true];
    $prepare = ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => true];
    return [[
        'name' => 'list_accepted_work', 'title' => 'List accepted NFH work',
        'description' => 'List dual-signed ACCEPT receipts. They prove acceptance, not payment, capability, transaction authority, or selection.',
        'inputSchema' => ['type' => 'object', 'properties' => ['limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500]], 'additionalProperties' => false],
        'outputSchema' => ['type' => 'object', 'additionalProperties' => true], 'annotations' => $read,
    ], [
        'name' => 'list_returned_work', 'title' => 'List returned NFH work',
        'description' => 'List worker-signed RETURNED_UNVERIFIED submissions. They are self-reports, not acceptance, payment, escrow, or capability proof.',
        'inputSchema' => ['type' => 'object', 'properties' => [
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500],
            'requestId' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'],
        ], 'additionalProperties' => false],
        'outputSchema' => ['type' => 'object', 'additionalProperties' => true], 'annotations' => $read,
    ], [
        'name' => 'prepare_returned_work', 'title' => 'Prepare a returned-work submission',
        'description' => 'Prepare readable RETURNED_UNVERIFIED text for a distinct worker. The worker wallet signs and publishes it.',
        'inputSchema' => ['type' => 'object', 'properties' => [
            'requestId' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$', 'description' => 'Mission request id.'], 'worker' => $addressSchema,
            'workerTokenId' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 9999],
            'summary' => ['type' => 'string', 'minLength' => 20, 'maxLength' => 600, 'description' => 'Public returned-work summary.'],
        ], 'required' => ['requestId', 'worker', 'summary'], 'additionalProperties' => false],
        'outputSchema' => ['type' => 'object', 'additionalProperties' => true], 'annotations' => $prepare,
    ], [
        'name' => 'prepare_accepted_work', 'title' => 'Prepare an accepted-work receipt',
        'description' => 'Prepare one readable ACCEPT receipt for a mission owner and distinct worker. Both wallets sign identical text.',
        'inputSchema' => ['type' => 'object', 'properties' => [
            'requestId' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$', 'description' => 'Mission request id.'], 'owner' => $addressSchema,
            'worker' => $addressSchema, 'workerTokenId' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 9999],
            'summary' => ['type' => 'string', 'minLength' => 20, 'maxLength' => 600, 'description' => 'Public accepted-result summary.'],
            'learning' => [
                'type' => 'object',
                'properties' => [
                    'approach' => ['type' => 'string', 'minLength' => 20, 'maxLength' => 600],
                    'feedback' => ['type' => 'string', 'minLength' => 20, 'maxLength' => 600],
                    'lesson' => ['type' => 'string', 'minLength' => 20, 'maxLength' => 600],
                    'proposedSkill' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 64],
                            'scope' => ['type' => 'string', 'enum' => ['individual', 'swarm']],
                            'instructions' => ['type' => 'string', 'minLength' => 20, 'maxLength' => 1200],
                            'testPlan' => ['type' => 'string', 'minLength' => 20, 'maxLength' => 600],
                        ],
                        'required' => ['name', 'scope', 'instructions', 'testPlan'],
                        'additionalProperties' => false,
                    ],
                ],
                'required' => ['approach', 'feedback', 'lesson'],
                'additionalProperties' => false,
            ],
        ], 'required' => ['requestId', 'owner', 'worker', 'summary'], 'additionalProperties' => false],
        'outputSchema' => ['type' => 'object', 'additionalProperties' => true], 'annotations' => $prepare,
    ]];
}

function nfh_agent_work_call_tool(string $name, array $arguments): array
{
    try {
        if ($name === 'list_accepted_work') {
            $limit = $arguments['limit'] ?? 100;
            if (!is_int($limit)) throw new InvalidArgumentException('limit must be an integer.');
            return nfh_tool_payload(nfh_agent_work_feed($limit));
        }
        if ($name === 'list_returned_work') {
            $limit = $arguments['limit'] ?? 100;
            if (!is_int($limit)) throw new InvalidArgumentException('limit must be an integer.');
            $requestId = $arguments['requestId'] ?? null;
            if ($requestId !== null && !is_string($requestId)) throw new InvalidArgumentException('requestId must be text.');
            return nfh_tool_payload(nfh_agent_return_feed($limit, $requestId));
        }
        if ($name === 'prepare_returned_work') return nfh_tool_payload(nfh_agent_return_prepare($arguments));
        if ($name === 'prepare_accepted_work') return nfh_tool_payload(nfh_agent_work_prepare($arguments));
    } catch (InvalidArgumentException|RuntimeException|JsonException $error) {
        return nfh_tool_error($error->getMessage());
    }
    return nfh_tool_error('Unknown Accepted Work tool: ' . $name);
}

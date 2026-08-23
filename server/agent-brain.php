<?php

declare(strict_types=1);

const NFH_AGENT_BRAIN_SCHEMA = 'nfh.public-brain.v1';
const NFH_AGENT_OWNERSHIP_EPOCH_SCHEMA = 'nfh.ownership-epoch.v1';
const NFH_AGENT_LEARNING_SCHEMA = 'nfh.learning-receipt.v1';
const NFH_AGENT_SKILL_SCHEMA = 'nfh.skill-version.v1';
const NFH_AGENT_LEARNING_DECISION_VERSION = 1;
const NFH_AGENT_LEARNING_DECISION_LIFETIME = 24 * 60 * 60;
const NFH_AGENT_BRAIN_MAX_LOG_BYTES = 8_000_000;

function nfh_agent_brain_directory(): string
{
    $configured = trim((string) (getenv('NFH_AGENT_BRAIN_DIR') ?: ''));
    $directory = $configured !== ''
        ? $configured
        : (nfh_is_local_cli_runtime()
            ? nfh_runtime_directory() . '/agent-brain'
            : '/home/notforhumans/.nfh-agent-brain');
    if (!str_starts_with($directory, DIRECTORY_SEPARATOR) || is_link($directory)) {
        throw new RuntimeException('Agent Brain storage path is unsafe.');
    }
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Agent Brain storage is unavailable.');
    }
    clearstatcache(true, $directory);
    if (is_link($directory) || (((int) fileperms($directory)) & 0077) !== 0) {
        throw new RuntimeException('Agent Brain storage permissions are unsafe.');
    }
    return $directory;
}

function nfh_agent_brain_log_path(): string
{
    return nfh_agent_brain_directory() . '/events.jsonl';
}

/** @return array<int, array<string, mixed>> */
function nfh_agent_brain_read_events($handle): array
{
    rewind($handle);
    $raw = stream_get_contents($handle, NFH_AGENT_BRAIN_MAX_LOG_BYTES + 1);
    if (!is_string($raw) || strlen($raw) > NFH_AGENT_BRAIN_MAX_LOG_BYTES) {
        throw new RuntimeException('Agent Brain storage exceeded its safe read limit.');
    }
    $events = [];
    foreach (preg_split('/\r?\n/', trim($raw)) ?: [] as $line) {
        if ($line === '') continue;
        try { $event = json_decode($line, true, flags: JSON_THROW_ON_ERROR); }
        catch (JsonException) { continue; }
        if (is_array($event)) $events[] = $event;
    }
    return $events;
}

/** @param array<string, mixed> $event */
function nfh_agent_brain_append_event($handle, array $event): void
{
    $encoded = json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    fseek($handle, 0, SEEK_END);
    $position = ftell($handle);
    if ($position === false || $position + strlen($encoded) > NFH_AGENT_BRAIN_MAX_LOG_BYTES) {
        throw new RuntimeException('Agent Brain storage is full.');
    }
    if (fwrite($handle, $encoded) !== strlen($encoded) || !fflush($handle)) {
        throw new RuntimeException('Agent Brain storage write failed.');
    }
}

/** @return mixed */
function nfh_agent_brain_locked(callable $callback, bool $exclusive = true): mixed
{
    $path = nfh_agent_brain_log_path();
    if (is_link($path)) throw new RuntimeException('Agent Brain storage file is unsafe.');
    $handle = fopen($path, 'c+b');
    if ($handle === false) throw new RuntimeException('Agent Brain storage is unavailable.');
    try {
        chmod($path, 0600);
        if (!flock($handle, $exclusive ? LOCK_EX : LOCK_SH)) throw new RuntimeException('Agent Brain storage lock failed.');
        return $callback($handle);
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

/** @return array<int, array<string, mixed>> */
function nfh_agent_brain_events(): array
{
    $path = nfh_agent_brain_log_path();
    if (!is_file($path)) return [];
    return nfh_agent_brain_locked(static fn ($handle): array => nfh_agent_brain_read_events($handle), false);
}

function nfh_agent_learning_public_text(mixed $value, string $name, int $minimum, int $maximum): string
{
    if (!is_string($value)) throw new InvalidArgumentException($name . ' must be public text.');
    $text = trim($value);
    $length = mb_strlen($text, 'UTF-8');
    if ($length < $minimum || $length > $maximum) {
        throw new InvalidArgumentException($name . ' must contain between ' . $minimum . ' and ' . $maximum . ' characters.');
    }
    if (preg_match('/[\x00-\x1F\x7F\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', $text) === 1) {
        throw new InvalidArgumentException($name . ' contains unsupported control characters.');
    }
    if (preg_match('/(?:-----BEGIN [A-Z ]*PRIVATE KEY-----|(?:api[_ -]?key|password|seed phrase|private key)\s*[:=])/iu', $text) === 1) {
        throw new InvalidArgumentException($name . ' appears to contain secret material and cannot enter the public brain.');
    }
    return $text;
}

/** @return array<string, mixed> */
function nfh_agent_learning_skill(mixed $value): array
{
    if (!is_array($value) || array_is_list($value)) throw new InvalidArgumentException('proposedSkill must be an object.');
    $allowed = ['name', 'scope', 'instructions', 'testPlan'];
    if (array_diff(array_keys($value), $allowed) !== []) throw new InvalidArgumentException('proposedSkill contains unsupported fields.');
    $name = nfh_agent_learning_public_text($value['name'] ?? null, 'proposedSkill.name', 3, 64);
    if (preg_match('/^[A-Za-z][A-Za-z0-9 _-]{2,63}$/', $name) !== 1) {
        throw new InvalidArgumentException('proposedSkill.name contains unsupported characters.');
    }
    $scope = $value['scope'] ?? null;
    if (!in_array($scope, ['individual', 'swarm'], true)) {
        throw new InvalidArgumentException('proposedSkill.scope must be individual or swarm.');
    }
    return [
        'name' => $name,
        'scope' => $scope,
        'instructions' => nfh_agent_learning_public_text($value['instructions'] ?? null, 'proposedSkill.instructions', 20, 1_200),
        'testPlan' => nfh_agent_learning_public_text($value['testPlan'] ?? null, 'proposedSkill.testPlan', 20, 600),
    ];
}

/** @return array<string, mixed>|null */
function nfh_agent_learning_input(mixed $value): ?array
{
    if ($value === null) return null;
    if (!is_array($value) || array_is_list($value)) throw new InvalidArgumentException('learning must be an object.');
    $allowed = ['approach', 'feedback', 'lesson', 'proposedSkill'];
    if (array_diff(array_keys($value), $allowed) !== []) throw new InvalidArgumentException('learning contains unsupported fields.');
    $learning = [
        'approach' => nfh_agent_learning_public_text($value['approach'] ?? null, 'learning.approach', 20, 600),
        'feedback' => nfh_agent_learning_public_text($value['feedback'] ?? null, 'learning.feedback', 20, 600),
        'lesson' => nfh_agent_learning_public_text($value['lesson'] ?? null, 'learning.lesson', 20, 600),
        'proposedSkill' => array_key_exists('proposedSkill', $value) ? nfh_agent_learning_skill($value['proposedSkill']) : null,
    ];
    return $learning;
}

/** @return array<string, mixed> */
function nfh_agent_ownership_epoch_observe(int $tokenId, string $owner, ?int $now = null): array
{
    $now ??= time();
    $tokenId = nfh_agent_wanted_token_id($tokenId);
    $owner = nfh_agent_wanted_owner($owner);
    return nfh_agent_brain_locked(static function ($handle) use ($tokenId, $owner, $now): array {
        $epochs = array_values(array_filter(
            nfh_agent_brain_read_events($handle),
            static fn (array $event): bool => ($event['type'] ?? null) === 'ownership-epoch'
                && ($event['schema'] ?? null) === NFH_AGENT_OWNERSHIP_EPOCH_SCHEMA
                && ($event['tokenId'] ?? null) === $tokenId,
        ));
        usort($epochs, static fn (array $a, array $b): int => ((int) $a['number']) <=> ((int) $b['number']));
        $latest = $epochs === [] ? null : $epochs[array_key_last($epochs)];
        if (is_array($latest) && hash_equals((string) $latest['operator'], $owner)) {
            return $latest;
        }
        $number = is_array($latest) ? (int) $latest['number'] + 1 : 1;
        $observedAt = gmdate('c', $now);
        $epoch = [
            'type' => 'ownership-epoch',
            'schema' => NFH_AGENT_OWNERSHIP_EPOCH_SCHEMA,
            'tokenId' => $tokenId,
            'number' => $number,
            'epochId' => hash('sha256', strtolower(NFH_AGENT_WANTED_COLLECTION) . '|' . $tokenId . '|' . $number . '|' . $owner . '|' . $observedAt),
            'operator' => $owner,
            'observedAt' => $observedAt,
            'previousEpochId' => is_array($latest) ? (string) $latest['epochId'] : null,
            'source' => 'live-ownerOf-observation',
            'runtimeAuthority' => 'new-operator-reconnect-required',
        ];
        nfh_agent_brain_append_event($handle, $epoch);
        return $epoch;
    });
}

function nfh_agent_brain_live_owner(int $tokenId): string
{
    $ownerResult = nfh_verify_rpc('eth_call', [[
        'to' => NFH_AGENT_WANTED_COLLECTION,
        'data' => '0x6352211e' . nfh_uint256_calldata_word($tokenId),
    ], 'latest'], nfh_verify_config());
    $owner = nfh_decode_owner_result($ownerResult);
    if ($owner === null) throw new RuntimeException('Current NFH ownership could not be verified.');
    return strtolower($owner);
}

/** @return array<string, mixed> */
function nfh_agent_ownership_epoch_sync(int $tokenId, ?int $now = null): array
{
    $now ??= time();
    $tokenId = nfh_agent_wanted_token_id($tokenId);
    return nfh_agent_ownership_epoch_observe($tokenId, nfh_agent_brain_live_owner($tokenId), $now);
}

/** @return array<string, mixed> */
function nfh_agent_ownership_epoch_assert_runtime(int $tokenId, string $owner, ?string $epochId, ?int $now = null): array
{
    $epoch = nfh_agent_ownership_epoch_sync($tokenId, $now);
    if (!hash_equals((string) $epoch['operator'], strtolower($owner))
        || !is_string($epochId) || !hash_equals((string) $epoch['epochId'], $epochId)) {
        throw new RuntimeException('This runtime authority belongs to a former ownership epoch. The current owner must reconnect it.');
    }
    return $epoch;
}

/** @return array<int, array<string, mixed>> */
function nfh_agent_ownership_epochs(int $tokenId, ?array $events = null): array
{
    $events ??= nfh_agent_brain_events();
    $epochs = array_values(array_filter(
        $events,
        static fn (array $event): bool => ($event['type'] ?? null) === 'ownership-epoch'
            && ($event['schema'] ?? null) === NFH_AGENT_OWNERSHIP_EPOCH_SCHEMA
            && ($event['tokenId'] ?? null) === $tokenId,
    ));
    usort($epochs, static fn (array $a, array $b): int => ((int) $a['number']) <=> ((int) $b['number']));
    foreach ($epochs as $index => &$epoch) {
        $next = $epochs[$index + 1] ?? null;
        $epoch = [
            'schema' => NFH_AGENT_OWNERSHIP_EPOCH_SCHEMA,
            'epochId' => (string) $epoch['epochId'],
            'number' => (int) $epoch['number'],
            'operator' => (string) $epoch['operator'],
            'observedAt' => (string) $epoch['observedAt'],
            'endedAt' => is_array($next) ? (string) $next['observedAt'] : null,
            'active' => !is_array($next),
            'source' => 'live-ownerOf-observation',
        ];
    }
    unset($epoch);
    return $epochs;
}

/** @param array<string, mixed> $record @param array<string, mixed> $request @return array<string, mixed>|null */
function nfh_agent_brain_record_accepted_work(array $record, array $request, ?int $now = null): ?array
{
    $now ??= time();
    $tokenId = $record['workerTokenId'] ?? null;
    if (!is_int($tokenId)) return null;
    $epoch = is_string($record['workerOwnershipEpochId'] ?? null)
        ? ['epochId' => $record['workerOwnershipEpochId'], 'operator' => $record['worker']]
        : nfh_agent_ownership_epoch_observe($tokenId, (string) $record['worker'], $now);
    $learning = is_array($record['learning'] ?? null) ? $record['learning'] : null;
    $proposedSkill = is_array($learning['proposedSkill'] ?? null) ? $learning['proposedSkill'] : null;
    $receiptId = hash('sha256', 'nfh-learning|' . (string) $record['receiptId']);
    $proposalId = $proposedSkill === null ? null : hash(
        'sha256',
        'nfh-skill-proposal|' . $receiptId . '|' . json_encode($proposedSkill, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
    );
    $learningRecord = [
        'type' => 'learning-receipt',
        'schema' => NFH_AGENT_LEARNING_SCHEMA,
        'learningReceiptId' => $receiptId,
        'acceptedWorkReceiptId' => (string) $record['receiptId'],
        'requestId' => (string) $record['requestId'],
        'tokenId' => $tokenId,
        'operator' => (string) $record['worker'],
        'ownershipEpochId' => (string) $epoch['epochId'],
        'recordedAt' => (string) $record['acceptedAt'],
        'goal' => (string) ($request['task'] ?? 'Complete the signed NFH mission.'),
        'approach' => $learning['approach'] ?? null,
        'evidence' => [[
            'type' => 'dual-signed-accepted-work',
            'receiptId' => (string) $record['receiptId'],
            'evidenceHash' => (string) $record['evidenceHash'],
            'ownerAndWorkerSignaturesVerified' => true,
        ]],
        'result' => (string) $record['summary'],
        'feedback' => $learning['feedback'] ?? 'The mission owner and worker dual-signed the public ACCEPT receipt.',
        'lesson' => $learning['lesson'] ?? null,
        'proposalId' => $proposalId,
        'proposedSkill' => $proposedSkill,
        'evaluationState' => $proposalId === null ? 'recorded-needs-evaluation' : 'proposed-awaiting-tests',
        'privacy' => 'public-generalized-only',
    ];
    return nfh_agent_brain_locked(static function ($handle) use ($learningRecord): array {
        foreach (nfh_agent_brain_read_events($handle) as $event) {
            if (($event['type'] ?? null) === 'learning-receipt'
                && ($event['learningReceiptId'] ?? null) === $learningRecord['learningReceiptId']) return $event;
        }
        nfh_agent_brain_append_event($handle, $learningRecord);
        return $learningRecord;
    });
}

/** @return array<string, mixed>|null */
function nfh_agent_learning_find_proposal(int $tokenId, string $proposalId, ?array $events = null): ?array
{
    $events ??= nfh_agent_brain_events();
    foreach ($events as $event) {
        if (($event['type'] ?? null) === 'learning-receipt'
            && ($event['schema'] ?? null) === NFH_AGENT_LEARNING_SCHEMA
            && ($event['tokenId'] ?? null) === $tokenId
            && is_string($event['proposalId'] ?? null)
            && hash_equals((string) $event['proposalId'], $proposalId)) return $event;
    }
    return null;
}

/** @return array<int, array<string, mixed>> */
function nfh_agent_learning_tests(mixed $value): array
{
    if (!is_array($value) || !array_is_list($value) || count($value) < 1 || count($value) > 10) {
        throw new InvalidArgumentException('tests must contain between one and ten public test results.');
    }
    $tests = [];
    foreach ($value as $test) {
        if (!is_array($test) || array_is_list($test) || array_diff(array_keys($test), ['name', 'evidenceHash', 'passed']) !== []) {
            throw new InvalidArgumentException('Each test must contain only name, evidenceHash, and passed.');
        }
        $hash = $test['evidenceHash'] ?? null;
        if (!is_string($hash) || preg_match('/^0x[a-fA-F0-9]{64}$/', $hash) !== 1 || !is_bool($test['passed'] ?? null)) {
            throw new InvalidArgumentException('Each test requires a 32-byte evidenceHash and boolean passed result.');
        }
        $tests[] = [
            'name' => nfh_agent_learning_public_text($test['name'] ?? null, 'test.name', 3, 120),
            'evidenceHash' => strtolower($hash),
            'passed' => $test['passed'],
        ];
    }
    return $tests;
}

/** @param array<string, mixed> $input @return array<string, mixed> */
function nfh_agent_learning_prepare_decision(array $input, ?int $now = null): array
{
    $now ??= time();
    $tokenId = nfh_agent_wanted_token_id($input['tokenId'] ?? null);
    $owner = nfh_agent_wanted_owner($input['owner'] ?? null);
    $proposalId = $input['proposalId'] ?? null;
    $decision = $input['decision'] ?? null;
    if (!is_string($proposalId) || preg_match('/^[a-f0-9]{64}$/', $proposalId) !== 1) {
        throw new InvalidArgumentException('proposalId must be a 32-byte lowercase hexadecimal id.');
    }
    if (!in_array($decision, ['PROMOTE', 'REJECT'], true)) throw new InvalidArgumentException('decision must be PROMOTE or REJECT.');
    $tests = nfh_agent_learning_tests($input['tests'] ?? null);
    if ($decision === 'PROMOTE' && array_filter($tests, static fn (array $test): bool => $test['passed'] !== true) !== []) {
        throw new InvalidArgumentException('Every cited test must pass before promotion.');
    }
    $proposal = nfh_agent_learning_find_proposal($tokenId, $proposalId);
    if ($proposal === null || !is_array($proposal['proposedSkill'] ?? null)) throw new RuntimeException('The public learning proposal does not exist.');
    if ($decision === 'PROMOTE' && ($proposal['proposedSkill']['scope'] ?? null) === 'swarm') {
        throw new RuntimeException('Swarm promotion is curator-gated. Operator signatures may promote only the individual NFH skill.');
    }
    foreach (nfh_agent_brain_events() as $event) {
        if (($event['type'] ?? null) === 'learning-decision' && ($event['proposalId'] ?? null) === $proposalId) {
            throw new RuntimeException('This learning proposal already has a final decision.');
        }
    }
    $epoch = nfh_agent_ownership_epoch_sync($tokenId, $now);
    if (!hash_equals((string) $epoch['operator'], $owner)) throw new RuntimeException('Only the current NFH owner can decide an individual skill proposal.');
    $skillHash = '0x' . hash('sha256', json_encode($proposal['proposedSkill'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    $payload = [
        'version' => NFH_AGENT_LEARNING_DECISION_VERSION,
        'action' => 'DECIDE_AGENT_SKILL',
        'chainId' => 1,
        'collection' => strtolower(NFH_AGENT_WANTED_COLLECTION),
        'tokenId' => $tokenId,
        'owner' => $owner,
        'ownershipEpochId' => (string) $epoch['epochId'],
        'proposalId' => $proposalId,
        'skillHash' => $skillHash,
        'decision' => $decision,
        'tests' => $tests,
        'rationale' => nfh_agent_learning_public_text($input['rationale'] ?? null, 'rationale', 20, 600),
        'issuedAt' => $now,
        'expiresAt' => $now + NFH_AGENT_LEARNING_DECISION_LIFETIME,
        'nonce' => bin2hex(random_bytes(16)),
    ];
    return [
        'schema' => NFH_AGENT_LEARNING_SCHEMA,
        'status' => 'prepared_unsigned',
        'payload' => $payload,
        'message' => nfh_agent_learning_decision_message($payload),
        'signingMethod' => 'personal_sign',
        'requiresCurrentOwnerSignature' => true,
        'publishEndpoint' => 'https://mcp.notforhumans.fun/agent-brain/decision/publish',
        'warnings' => [
            'Promotion changes only the versioned public brain. It cannot alter a model, runtime, wallet, credential, session, or private memory.',
            'Test evidence and rationale are public. Never include secrets or private owner conversations.',
            'Swarm learning needs separate curator review and cannot be promoted by this operator-only path.',
        ],
    ];
}

/** @param array<string, mixed> $payload */
function nfh_agent_learning_decision_message(array $payload): string
{
    $tests = json_encode($payload['tests'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    return "NOT FOR HUMANS Skill Decision\n"
        . "Version: {$payload['version']}\nDomain: notforhumans.fun\nAction: {$payload['action']}\n"
        . "Chain ID: {$payload['chainId']}\nCollection: {$payload['collection']}\nNFH Token ID: {$payload['tokenId']}\n"
        . "Owner: {$payload['owner']}\nOwnership Epoch ID: {$payload['ownershipEpochId']}\nProposal ID: {$payload['proposalId']}\n"
        . "Skill Hash: {$payload['skillHash']}\nDecision: {$payload['decision']}\nTests: {$tests}\nRationale: {$payload['rationale']}\n"
        . 'Issued At: ' . gmdate('c', $payload['issuedAt']) . "\nExpiration Time: " . gmdate('c', $payload['expiresAt']) . "\n"
        . "Nonce: {$payload['nonce']}\n"
        . 'Statement: This signature records a tested public-brain decision for this ownership epoch. It grants no runtime, wallet, transaction, credential, session, publication, or private-memory authority.';
}

/** @param array<string, mixed> $payload @return array<string, mixed> */
function nfh_agent_learning_validate_decision(array $payload, ?int $now = null): array
{
    $now ??= time();
    $allowed = ['version', 'action', 'chainId', 'collection', 'tokenId', 'owner', 'ownershipEpochId', 'proposalId', 'skillHash', 'decision', 'tests', 'rationale', 'issuedAt', 'expiresAt', 'nonce'];
    if (array_diff(array_keys($payload), $allowed) !== []) throw new InvalidArgumentException('The skill decision contains unsupported fields.');
    $issuedAt = $payload['issuedAt'] ?? null;
    $expiresAt = $payload['expiresAt'] ?? null;
    $nonce = $payload['nonce'] ?? null;
    if (($payload['version'] ?? null) !== NFH_AGENT_LEARNING_DECISION_VERSION
        || ($payload['action'] ?? null) !== 'DECIDE_AGENT_SKILL'
        || ($payload['chainId'] ?? null) !== 1
        || !is_string($payload['collection'] ?? null)
        || strcasecmp($payload['collection'], NFH_AGENT_WANTED_COLLECTION) !== 0
        || !is_int($issuedAt) || !is_int($expiresAt)
        || $issuedAt < $now - NFH_AGENT_LEARNING_DECISION_LIFETIME || $issuedAt > $now + 60
        || $expiresAt !== $issuedAt + NFH_AGENT_LEARNING_DECISION_LIFETIME || $expiresAt < $now
        || !is_string($nonce) || preg_match('/^[a-f0-9]{32}$/', $nonce) !== 1) {
        throw new InvalidArgumentException('The skill decision domain, timing, or nonce is invalid.');
    }
    $proposalId = $payload['proposalId'] ?? null;
    $epochId = $payload['ownershipEpochId'] ?? null;
    $skillHash = $payload['skillHash'] ?? null;
    if (!is_string($proposalId) || preg_match('/^[a-f0-9]{64}$/', $proposalId) !== 1
        || !is_string($epochId) || preg_match('/^[a-f0-9]{64}$/', $epochId) !== 1
        || !is_string($skillHash) || preg_match('/^0x[a-f0-9]{64}$/', $skillHash) !== 1
        || !in_array($payload['decision'] ?? null, ['PROMOTE', 'REJECT'], true)) {
        throw new InvalidArgumentException('The skill decision identifiers are invalid.');
    }
    $tests = nfh_agent_learning_tests($payload['tests'] ?? null);
    if ($payload['decision'] === 'PROMOTE' && array_filter($tests, static fn (array $test): bool => $test['passed'] !== true) !== []) {
        throw new InvalidArgumentException('Every cited test must pass before promotion.');
    }
    return [
        'version' => NFH_AGENT_LEARNING_DECISION_VERSION,
        'action' => 'DECIDE_AGENT_SKILL',
        'chainId' => 1,
        'collection' => strtolower(NFH_AGENT_WANTED_COLLECTION),
        'tokenId' => nfh_agent_wanted_token_id($payload['tokenId'] ?? null),
        'owner' => nfh_agent_wanted_owner($payload['owner'] ?? null),
        'ownershipEpochId' => $epochId,
        'proposalId' => $proposalId,
        'skillHash' => $skillHash,
        'decision' => $payload['decision'],
        'tests' => $tests,
        'rationale' => nfh_agent_learning_public_text($payload['rationale'] ?? null, 'rationale', 20, 600),
        'issuedAt' => $issuedAt,
        'expiresAt' => $expiresAt,
        'nonce' => $nonce,
    ];
}

/** @param array<string, mixed> $input @return array<string, mixed> */
function nfh_agent_learning_publish_decision(array $input, ?int $now = null): array
{
    $now ??= time();
    $payload = $input['payload'] ?? null;
    $signature = $input['signature'] ?? null;
    if (!is_array($payload) || !is_string($signature)) throw new InvalidArgumentException('payload and signature are required.');
    $payload = nfh_agent_learning_validate_decision($payload, $now);
    $message = nfh_agent_learning_decision_message($payload);
    $signer = strtolower(nfh_verify_recover($message, $signature, nfh_verify_config()));
    if (!hash_equals($payload['owner'], $signer)) throw new RuntimeException('The signature does not match the current owner.');
    if (!nfh_rate_limit('agent-learning-wallet', $signer, 10, 3600, $now)) throw new RuntimeException('This owner has published too many learning decisions.');
    $epoch = nfh_agent_ownership_epoch_sync($payload['tokenId'], $now);
    if (!hash_equals((string) $epoch['operator'], $signer)
        || !hash_equals((string) $epoch['epochId'], $payload['ownershipEpochId'])) {
        throw new RuntimeException('Ownership changed after preparation. Prepare this decision again in the new epoch.');
    }
    $events = nfh_agent_brain_events();
    $proposal = nfh_agent_learning_find_proposal($payload['tokenId'], $payload['proposalId'], $events);
    if ($proposal === null || !is_array($proposal['proposedSkill'] ?? null)) throw new RuntimeException('The public learning proposal does not exist.');
    $expectedHash = '0x' . hash('sha256', json_encode($proposal['proposedSkill'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    if (!hash_equals($expectedHash, $payload['skillHash'])) throw new RuntimeException('The skill proposal changed after preparation.');
    if ($payload['decision'] === 'PROMOTE' && ($proposal['proposedSkill']['scope'] ?? null) !== 'individual') {
        throw new RuntimeException('Swarm promotion is curator-gated and cannot use this operator-only path.');
    }
    $decisionId = hash('sha256', $message);
    $record = nfh_agent_brain_locked(static function ($handle) use ($payload, $proposal, $signature, $message, $decisionId, $now): array {
        $events = nfh_agent_brain_read_events($handle);
        foreach ($events as $event) {
            if (($event['type'] ?? null) === 'learning-decision' && ($event['proposalId'] ?? null) === $payload['proposalId']) {
                if (($event['decisionId'] ?? null) === $decisionId) return $event;
                throw new RuntimeException('This learning proposal already has a final decision.');
            }
        }
        $version = null;
        if ($payload['decision'] === 'PROMOTE') {
            $version = 1;
            foreach ($events as $event) {
                if (($event['type'] ?? null) === 'learning-decision'
                    && ($event['decision'] ?? null) === 'PROMOTE'
                    && ($event['tokenId'] ?? null) === $payload['tokenId']
                    && strcasecmp((string) ($event['skill']['name'] ?? ''), (string) $proposal['proposedSkill']['name']) === 0) $version++;
            }
        }
        $record = [
            'type' => 'learning-decision',
            'schema' => NFH_AGENT_SKILL_SCHEMA,
            'decisionId' => $decisionId,
            'proposalId' => $payload['proposalId'],
            'learningReceiptId' => $proposal['learningReceiptId'],
            'tokenId' => $payload['tokenId'],
            'operator' => $payload['owner'],
            'ownershipEpochId' => $payload['ownershipEpochId'],
            'decision' => $payload['decision'],
            'skill' => $proposal['proposedSkill'],
            'version' => $version,
            'tests' => $payload['tests'],
            'rationale' => $payload['rationale'],
            'decidedAt' => gmdate('c', $now),
            'signatureHash' => hash('sha256', strtolower($signature)),
            'messageHash' => hash('sha256', $message),
        ];
        if ($version !== null) {
            $record['skillVersionId'] = hash('sha256', $payload['tokenId'] . '|' . strtolower((string) $proposal['proposedSkill']['name']) . '|' . $version . '|' . $payload['skillHash']);
        }
        nfh_agent_brain_append_event($handle, $record);
        return $record;
    });
    return [
        'ok' => true,
        'decision' => nfh_agent_learning_public_decision($record),
        'publicBrainUrl' => 'https://mcp.notforhumans.fun/agent-brain/' . $payload['tokenId'],
    ];
}

/** @param array<string, mixed> $record @return array<string, mixed> */
function nfh_agent_learning_public_decision(array $record): array
{
    return [
        'schema' => NFH_AGENT_SKILL_SCHEMA,
        'decisionId' => (string) $record['decisionId'],
        'proposalId' => is_string($record['proposalId'] ?? null) ? $record['proposalId'] : null,
        'decision' => (string) $record['decision'],
        'tokenId' => (int) $record['tokenId'],
        'operator' => (string) $record['operator'],
        'ownershipEpochId' => (string) $record['ownershipEpochId'],
        'skill' => $record['skill'],
        'version' => is_int($record['version'] ?? null) ? $record['version'] : null,
        'skillVersionId' => is_string($record['skillVersionId'] ?? null) ? $record['skillVersionId'] : null,
        'tests' => $record['tests'],
        'rationale' => (string) $record['rationale'],
        'decidedAt' => (string) $record['decidedAt'],
        'signatureVerified' => true,
    ];
}

/** @param array<int, array<string, mixed>> $events @return array<string, mixed> */
function nfh_agent_skill_rollback_target(int $tokenId, string $skillName, int $targetVersion, array $events): array
{
    $skills = nfh_agent_promoted_skills($tokenId, $events);
    $target = null;
    $active = null;
    foreach ($skills as $skill) {
        if (strcasecmp((string) ($skill['skill']['name'] ?? ''), $skillName) !== 0) continue;
        if (($skill['version'] ?? null) === $targetVersion) $target = $skill;
        if (($skill['status'] ?? null) === 'active') $active = $skill;
    }
    if ($target === null) throw new RuntimeException('The requested promoted skill version does not exist.');
    if ($active === null || !is_string($active['skillVersionId'] ?? null)) throw new RuntimeException('The skill has no active version.');
    if (hash_equals((string) $active['skillVersionId'], (string) $target['skillVersionId'])) {
        throw new RuntimeException('The requested skill version is already active.');
    }
    return ['target' => $target, 'active' => $active];
}

/** @param array<int, array<string, mixed>> $events @return array<string, mixed> */
function nfh_agent_skill_rollback_state(int $tokenId, string $currentSkillVersionId, string $targetSkillVersionId, array $events): array
{
    $skills = nfh_agent_promoted_skills($tokenId, $events);
    $target = null;
    foreach ($skills as $skill) {
        if (($skill['skillVersionId'] ?? null) === $targetSkillVersionId) {
            $target = $skill;
            break;
        }
    }
    if ($target === null) throw new RuntimeException('The rollback target skill version no longer exists.');
    $active = null;
    foreach ($skills as $skill) {
        if (($skill['status'] ?? null) === 'active'
            && strcasecmp((string) ($skill['skill']['name'] ?? ''), (string) ($target['skill']['name'] ?? '')) === 0) {
            $active = $skill;
            break;
        }
    }
    if ($active === null || !hash_equals((string) ($active['skillVersionId'] ?? ''), $currentSkillVersionId)) {
        throw new RuntimeException('The active skill version changed after rollback preparation.');
    }
    return ['target' => $target, 'active' => $active];
}

/** @param array<string, mixed> $input @return array<string, mixed> */
function nfh_agent_skill_prepare_rollback(array $input, ?int $now = null): array
{
    $now ??= time();
    $tokenId = nfh_agent_wanted_token_id($input['tokenId'] ?? null);
    $owner = nfh_agent_wanted_owner($input['owner'] ?? null);
    $skillName = nfh_agent_learning_public_text($input['skillName'] ?? null, 'skillName', 3, 64);
    $targetVersion = $input['targetVersion'] ?? null;
    if (!is_int($targetVersion) || $targetVersion < 1 || $targetVersion > 10_000) {
        throw new InvalidArgumentException('targetVersion must be a positive integer.');
    }
    $tests = nfh_agent_learning_tests($input['tests'] ?? null);
    if (array_filter($tests, static fn (array $test): bool => $test['passed'] !== true) !== []) {
        throw new InvalidArgumentException('Every cited rollback test must pass for the target version.');
    }
    $versions = nfh_agent_skill_rollback_target($tokenId, $skillName, $targetVersion, nfh_agent_brain_events());
    $epoch = nfh_agent_ownership_epoch_sync($tokenId, $now);
    if (!hash_equals((string) $epoch['operator'], $owner)) throw new RuntimeException('Only the current NFH owner can prepare a skill rollback.');
    $payload = [
        'version' => NFH_AGENT_LEARNING_DECISION_VERSION,
        'action' => 'ROLLBACK_AGENT_SKILL',
        'chainId' => 1,
        'collection' => strtolower(NFH_AGENT_WANTED_COLLECTION),
        'tokenId' => $tokenId,
        'owner' => $owner,
        'ownershipEpochId' => (string) $epoch['epochId'],
        'currentSkillVersionId' => (string) $versions['active']['skillVersionId'],
        'targetSkillVersionId' => (string) $versions['target']['skillVersionId'],
        'tests' => $tests,
        'rationale' => nfh_agent_learning_public_text($input['rationale'] ?? null, 'rationale', 20, 600),
        'issuedAt' => $now,
        'expiresAt' => $now + NFH_AGENT_LEARNING_DECISION_LIFETIME,
        'nonce' => bin2hex(random_bytes(16)),
    ];
    return [
        'schema' => NFH_AGENT_SKILL_SCHEMA,
        'status' => 'prepared_unsigned',
        'payload' => $payload,
        'message' => nfh_agent_skill_rollback_message($payload),
        'signingMethod' => 'personal_sign',
        'requiresCurrentOwnerSignature' => true,
        'publishEndpoint' => 'https://mcp.notforhumans.fun/agent-brain/rollback/publish',
        'warning' => 'Rollback changes only which tested public skill version is active. It grants no runtime or wallet authority and does not delete history.',
    ];
}

/** @param array<string, mixed> $payload */
function nfh_agent_skill_rollback_message(array $payload): string
{
    $tests = json_encode($payload['tests'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    return "NOT FOR HUMANS Skill Rollback\n"
        . "Version: {$payload['version']}\nDomain: notforhumans.fun\nAction: {$payload['action']}\n"
        . "Chain ID: {$payload['chainId']}\nCollection: {$payload['collection']}\nNFH Token ID: {$payload['tokenId']}\n"
        . "Owner: {$payload['owner']}\nOwnership Epoch ID: {$payload['ownershipEpochId']}\n"
        . "Current Skill Version ID: {$payload['currentSkillVersionId']}\nTarget Skill Version ID: {$payload['targetSkillVersionId']}\n"
        . "Tests: {$tests}\nRationale: {$payload['rationale']}\nIssued At: " . gmdate('c', $payload['issuedAt']) . "\n"
        . 'Expiration Time: ' . gmdate('c', $payload['expiresAt']) . "\nNonce: {$payload['nonce']}\n"
        . 'Statement: This signature reactivates one previously promoted and retested public skill version. It deletes no history and grants no runtime, wallet, credential, session, transaction, or private-memory authority.';
}

/** @param array<string, mixed> $payload @return array<string, mixed> */
function nfh_agent_skill_validate_rollback(array $payload, ?int $now = null): array
{
    $now ??= time();
    $allowed = ['version', 'action', 'chainId', 'collection', 'tokenId', 'owner', 'ownershipEpochId', 'currentSkillVersionId', 'targetSkillVersionId', 'tests', 'rationale', 'issuedAt', 'expiresAt', 'nonce'];
    if (array_diff(array_keys($payload), $allowed) !== []) throw new InvalidArgumentException('The skill rollback contains unsupported fields.');
    $issuedAt = $payload['issuedAt'] ?? null;
    $expiresAt = $payload['expiresAt'] ?? null;
    $nonce = $payload['nonce'] ?? null;
    if (($payload['version'] ?? null) !== NFH_AGENT_LEARNING_DECISION_VERSION
        || ($payload['action'] ?? null) !== 'ROLLBACK_AGENT_SKILL'
        || ($payload['chainId'] ?? null) !== 1
        || !is_string($payload['collection'] ?? null) || strcasecmp($payload['collection'], NFH_AGENT_WANTED_COLLECTION) !== 0
        || !is_int($issuedAt) || !is_int($expiresAt)
        || $issuedAt < $now - NFH_AGENT_LEARNING_DECISION_LIFETIME || $issuedAt > $now + 60
        || $expiresAt !== $issuedAt + NFH_AGENT_LEARNING_DECISION_LIFETIME || $expiresAt < $now
        || !is_string($nonce) || preg_match('/^[a-f0-9]{32}$/', $nonce) !== 1) {
        throw new InvalidArgumentException('The skill rollback domain, timing, or nonce is invalid.');
    }
    foreach (['ownershipEpochId', 'currentSkillVersionId', 'targetSkillVersionId'] as $field) {
        if (!is_string($payload[$field] ?? null) || preg_match('/^[a-f0-9]{64}$/', $payload[$field]) !== 1) {
            throw new InvalidArgumentException($field . ' must be a 32-byte lowercase hexadecimal id.');
        }
    }
    $tests = nfh_agent_learning_tests($payload['tests'] ?? null);
    if (array_filter($tests, static fn (array $test): bool => $test['passed'] !== true) !== []) {
        throw new InvalidArgumentException('Every cited rollback test must pass for the target version.');
    }
    return [
        'version' => NFH_AGENT_LEARNING_DECISION_VERSION,
        'action' => 'ROLLBACK_AGENT_SKILL',
        'chainId' => 1,
        'collection' => strtolower(NFH_AGENT_WANTED_COLLECTION),
        'tokenId' => nfh_agent_wanted_token_id($payload['tokenId'] ?? null),
        'owner' => nfh_agent_wanted_owner($payload['owner'] ?? null),
        'ownershipEpochId' => $payload['ownershipEpochId'],
        'currentSkillVersionId' => $payload['currentSkillVersionId'],
        'targetSkillVersionId' => $payload['targetSkillVersionId'],
        'tests' => $tests,
        'rationale' => nfh_agent_learning_public_text($payload['rationale'] ?? null, 'rationale', 20, 600),
        'issuedAt' => $issuedAt,
        'expiresAt' => $expiresAt,
        'nonce' => $nonce,
    ];
}

/** @param array<string, mixed> $input @return array<string, mixed> */
function nfh_agent_skill_publish_rollback(array $input, ?int $now = null): array
{
    $now ??= time();
    $payload = $input['payload'] ?? null;
    $signature = $input['signature'] ?? null;
    if (!is_array($payload) || !is_string($signature)) throw new InvalidArgumentException('payload and signature are required.');
    $payload = nfh_agent_skill_validate_rollback($payload, $now);
    $message = nfh_agent_skill_rollback_message($payload);
    $signer = strtolower(nfh_verify_recover($message, $signature, nfh_verify_config()));
    if (!hash_equals($payload['owner'], $signer)) throw new RuntimeException('The rollback signature does not match the current owner.');
    if (!nfh_rate_limit('agent-skill-rollback-wallet', $signer, 6, 3600, $now)) throw new RuntimeException('This owner has published too many skill rollbacks.');
    $epoch = nfh_agent_ownership_epoch_sync($payload['tokenId'], $now);
    if (!hash_equals((string) $epoch['operator'], $signer) || !hash_equals((string) $epoch['epochId'], $payload['ownershipEpochId'])) {
        throw new RuntimeException('Ownership changed after rollback preparation. Prepare it again in the new epoch.');
    }
    $rollbackId = hash('sha256', $message);
    $record = nfh_agent_brain_locked(static function ($handle) use ($payload, $signature, $message, $rollbackId, $now): array {
        $events = nfh_agent_brain_read_events($handle);
        foreach ($events as $event) {
            if (($event['type'] ?? null) === 'skill-rollback' && ($event['rollbackId'] ?? null) === $rollbackId) return $event;
        }
        $state = nfh_agent_skill_rollback_state(
            $payload['tokenId'],
            $payload['currentSkillVersionId'],
            $payload['targetSkillVersionId'],
            $events,
        );
        $record = [
            'type' => 'skill-rollback',
            'schema' => NFH_AGENT_SKILL_SCHEMA,
            'rollbackId' => $rollbackId,
            'tokenId' => $payload['tokenId'],
            'operator' => $payload['owner'],
            'ownershipEpochId' => $payload['ownershipEpochId'],
            'skillName' => $state['target']['skill']['name'],
            'previousActiveSkillVersionId' => $payload['currentSkillVersionId'],
            'targetSkillVersionId' => $payload['targetSkillVersionId'],
            'tests' => $payload['tests'],
            'rationale' => $payload['rationale'],
            'rolledBackAt' => gmdate('c', $now),
            'signatureHash' => hash('sha256', strtolower($signature)),
            'messageHash' => hash('sha256', $message),
        ];
        nfh_agent_brain_append_event($handle, $record);
        return $record;
    });
    return ['ok' => true, 'rollback' => $record, 'publicBrainUrl' => 'https://mcp.notforhumans.fun/agent-brain/' . $payload['tokenId']];
}

/** @param array<string, mixed> $receipt @param array<string, mixed>|null $decision @return array<string, mixed> */
function nfh_agent_learning_public_receipt(array $receipt, ?array $decision): array
{
    return [
        'schema' => NFH_AGENT_LEARNING_SCHEMA,
        'learningReceiptId' => (string) $receipt['learningReceiptId'],
        'acceptedWorkReceiptId' => (string) $receipt['acceptedWorkReceiptId'],
        'requestId' => (string) $receipt['requestId'],
        'tokenId' => (int) $receipt['tokenId'],
        'operator' => (string) $receipt['operator'],
        'ownershipEpochId' => (string) $receipt['ownershipEpochId'],
        'recordedAt' => (string) $receipt['recordedAt'],
        'goal' => (string) $receipt['goal'],
        'approach' => is_string($receipt['approach'] ?? null) ? $receipt['approach'] : null,
        'evidence' => array_values((array) $receipt['evidence']),
        'result' => (string) $receipt['result'],
        'feedback' => (string) $receipt['feedback'],
        'lesson' => is_string($receipt['lesson'] ?? null) ? $receipt['lesson'] : null,
        'proposalId' => is_string($receipt['proposalId'] ?? null) ? $receipt['proposalId'] : null,
        'proposedSkill' => is_array($receipt['proposedSkill'] ?? null) ? $receipt['proposedSkill'] : null,
        'evaluationState' => $decision === null
            ? (string) $receipt['evaluationState']
            : strtolower((string) $decision['decision']),
        'decision' => $decision === null ? null : nfh_agent_learning_public_decision($decision),
        'privacy' => 'Public generalized evidence only. No conversations, secrets, credentials, keys, private memory, or account access transfer.',
    ];
}

/** @return array<string, mixed> */
function nfh_agent_learning_feed(int $tokenId, int $limit = 100, ?array $events = null, ?int $now = null): array
{
    $now ??= time();
    $tokenId = nfh_agent_wanted_token_id($tokenId);
    if ($limit < 1 || $limit > 250) throw new InvalidArgumentException('limit must be between 1 and 250.');
    $events ??= nfh_agent_brain_events();
    $decisions = [];
    foreach ($events as $event) {
        if (($event['type'] ?? null) === 'learning-decision' && is_string($event['proposalId'] ?? null)) {
            $decisions[$event['proposalId']] = $event;
        }
    }
    $receipts = [];
    foreach ($events as $event) {
        if (($event['type'] ?? null) !== 'learning-receipt' || ($event['schema'] ?? null) !== NFH_AGENT_LEARNING_SCHEMA
            || ($event['tokenId'] ?? null) !== $tokenId) continue;
        $receipts[] = nfh_agent_learning_public_receipt($event, is_string($event['proposalId'] ?? null) ? ($decisions[$event['proposalId']] ?? null) : null);
    }
    usort($receipts, static fn (array $a, array $b): int => strcmp((string) $b['recordedAt'], (string) $a['recordedAt']));
    return [
        'schema' => NFH_AGENT_LEARNING_SCHEMA,
        'tokenId' => $tokenId,
        'updatedAt' => gmdate('c', $now),
        'receipts' => array_slice($receipts, 0, $limit),
        'promotionRule' => 'ACT → RECORD → EVALUATE → EXTRACT LESSON → PROPOSE → TEST → PROMOTE / REJECT. A proposal never changes runtime capability by itself.',
        'swarmRule' => 'Swarm proposals are public but curator-gated; this operator-only route cannot promote them across every NFH.',
    ];
}

/** @return array<int, array<string, mixed>> */
function nfh_agent_promoted_skills(int $tokenId, array $events): array
{
    $skills = [];
    $indexes = [];
    $activeByName = [];
    foreach ($events as $event) {
        if (($event['schema'] ?? null) !== NFH_AGENT_SKILL_SCHEMA || ($event['tokenId'] ?? null) !== $tokenId) continue;
        if (($event['type'] ?? null) === 'learning-decision' && ($event['decision'] ?? null) === 'PROMOTE') {
            $skill = nfh_agent_learning_public_decision($event);
            $key = strtolower((string) ($skill['skill']['name'] ?? ''));
            if (isset($activeByName[$key], $indexes[$activeByName[$key]])) $skills[$indexes[$activeByName[$key]]]['status'] = 'superseded';
            $skill['status'] = 'active';
            $skills[] = $skill;
            $index = array_key_last($skills);
            $indexes[(string) $skill['skillVersionId']] = $index;
            $activeByName[$key] = (string) $skill['skillVersionId'];
            continue;
        }
        if (($event['type'] ?? null) === 'skill-rollback'
            && isset($indexes[(string) ($event['targetSkillVersionId'] ?? '')])) {
            $targetIndex = $indexes[(string) $event['targetSkillVersionId']];
            $key = strtolower((string) ($skills[$targetIndex]['skill']['name'] ?? ''));
            if (isset($activeByName[$key], $indexes[$activeByName[$key]])) $skills[$indexes[$activeByName[$key]]]['status'] = 'superseded';
            $skills[$targetIndex]['status'] = 'active';
            $skills[$targetIndex]['reactivatedByRollback'] = [
                'rollbackId' => $event['rollbackId'],
                'operator' => $event['operator'],
                'ownershipEpochId' => $event['ownershipEpochId'],
                'tests' => $event['tests'],
                'rationale' => $event['rationale'],
                'rolledBackAt' => $event['rolledBackAt'],
            ];
            $activeByName[$key] = (string) $event['targetSkillVersionId'];
        }
    }
    return $skills;
}

/** @return array<string, mixed> */
function nfh_agent_public_brain(int $tokenId, bool $syncOwnership = true, ?int $now = null): array
{
    $now ??= time();
    $tokenId = nfh_agent_wanted_token_id($tokenId);
    $ownershipStatus = 'recorded-only';
    $ownershipError = null;
    if ($syncOwnership) {
        try {
            nfh_agent_ownership_epoch_sync($tokenId, $now);
            $ownershipStatus = 'live-ownerOf-verified';
        } catch (RuntimeException $error) {
            $ownershipStatus = 'live-ownerOf-unavailable';
            $ownershipError = $error->getMessage();
        }
    }
    $events = nfh_agent_brain_events();
    $epochs = nfh_agent_ownership_epochs($tokenId, $events);
    $currentEpoch = $epochs === [] ? null : $epochs[array_key_last($epochs)];
    $learning = nfh_agent_learning_feed($tokenId, 250, $events, $now);
    $skills = nfh_agent_promoted_skills($tokenId, $events);
    $work = nfh_agent_work_feed(500, $now)['receipts'];
    $agentWork = array_values(array_filter($work, static fn (array $receipt): bool => ($receipt['workerTokenId'] ?? null) === $tokenId));
    $currentOperator = is_array($currentEpoch) ? (string) $currentEpoch['operator'] : null;
    $operatorWork = $currentOperator === null ? [] : array_values(array_filter(
        $work,
        static fn (array $receipt): bool => strcasecmp((string) ($receipt['wallet'] ?? ''), $currentOperator) === 0,
    ));
    $teamWork = !is_array($currentEpoch) ? [] : array_values(array_filter(
        $agentWork,
        static fn (array $receipt): bool => strcasecmp((string) ($receipt['wallet'] ?? ''), (string) $currentEpoch['operator']) === 0
            && is_string($receipt['workerOwnershipEpochId'] ?? null)
            && hash_equals((string) $currentEpoch['epochId'], (string) $receipt['workerOwnershipEpochId']),
    ));
    $workByEpoch = [];
    foreach ($agentWork as $receipt) {
        $key = is_string($receipt['workerOwnershipEpochId'] ?? null) ? $receipt['workerOwnershipEpochId'] : 'historical-unresolved';
        $workByEpoch[$key][] = $receipt;
    }
    return [
        'schema' => NFH_AGENT_BRAIN_SCHEMA,
        'tokenId' => $tokenId,
        'identity' => [
            'namespace' => 'eip155:1/erc721:' . strtolower(NFH_AGENT_WANTED_COLLECTION) . '/' . $tokenId,
            'passportUrl' => 'https://notforhumans.fun/passport/' . $tokenId,
            'continuity' => 'Permanent identity, public work history, accepted-job receipts, published artifacts, and promoted public skills follow the NFH.',
        ],
        'ownership' => [
            'status' => $ownershipStatus,
            'error' => $ownershipError,
            'currentEpoch' => $currentEpoch,
            'epochs' => $epochs,
            'transferRule' => 'A changed owner starts a new observed operator epoch. Former sessions and delegations have no authority in the new epoch.',
            'observationBoundary' => 'Epoch boundaries are recorded when live ownerOf changes are observed; they are not claimed as exact transfer timestamps.',
        ],
        'publicBrain' => [
            'learningReceipts' => $learning['receipts'],
            'promotedSkills' => $skills,
            'activeIndividualSkills' => count(array_filter($skills, static fn (array $skill): bool => $skill['status'] === 'active')),
            'swarmLearning' => [
                'status' => 'curator-gated',
                'promotedSkills' => [],
                'rule' => 'Only safe generalized lessons may be shared. Operator signatures alone cannot promote instructions across the swarm.',
            ],
            'rollbackRule' => 'A current owner may reactivate a previously promoted version only after retesting it. Rollback preserves the full decision history.',
            'workByOwnershipEpoch' => $workByEpoch,
        ],
        'reputation' => [
            'agent' => [
                'subject' => 'NFH #' . $tokenId,
                'acceptedWorkReceipts' => count($agentWork),
                'promotedSkillVersions' => count($skills),
                'followsIdentityAcrossTransfer' => true,
            ],
            'operator' => [
                'wallet' => $currentOperator,
                'acceptedWorkReceiptsAcrossNfhs' => count($operatorWork),
                'followsNfhAcrossTransfer' => false,
            ],
            'team' => [
                'ownershipEpochId' => $currentEpoch['epochId'] ?? null,
                'acceptedWorkReceipts' => count($teamWork),
                'resetsOnOperatorChange' => true,
            ],
            'meaning' => 'These are evidence counts, not capability scores or promises of future performance.',
        ],
        'nonTransferable' => [
            'privateMemory' => ['owner conversations', 'secrets', 'personal data', 'unpublished context'],
            'runtime' => ['processes', 'sessions', 'delegations', 'installed software'],
            'wallet' => ['private keys', 'credentials', 'API keys', 'approvals', 'account access'],
            'operatorProof' => ['operator reputation', 'personal authorship credit', 'former-epoch team authority'],
            'optionalHandover' => 'A separately consented encrypted handover package may be designed later; none is implied or exposed here.',
        ],
        'authority' => [
            'historyGrantsRuntimeCapability' => false,
            'publicSkillsAreExecutableInstructions' => false,
            'createsSession' => false,
            'controlsWallet' => false,
            'canSign' => false,
            'canTransact' => false,
            'buyerMustReconnectRuntimeAndWallet' => true,
        ],
        'updatedAt' => gmdate('c', $now),
    ];
}

/** @param array<string, mixed> $query @return array<string, mixed> */
function nfh_agent_public_brain_resource_query(array $query, bool $syncOwnership = true, ?int $now = null): array
{
    $keys = array_keys($query);
    sort($keys, SORT_STRING);
    if ($keys !== ['tokenId']) {
        throw new InvalidArgumentException('tokenId must be the only query parameter.');
    }
    return nfh_agent_public_brain(nfh_agent_wanted_token_id($query['tokenId']), $syncOwnership, $now);
}

/** @return array<int, array<string, mixed>> */
function nfh_agent_brain_tool_definitions(array $addressSchema, array $tokenIdSchema): array
{
    $read = ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => true];
    $prepare = ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => true];
    $testSchema = ['type' => 'object', 'properties' => [
        'name' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 120],
        'evidenceHash' => ['type' => 'string', 'pattern' => '^0x[a-fA-F0-9]{64}$'],
        'passed' => ['type' => 'boolean'],
    ], 'required' => ['name', 'evidenceHash', 'passed'], 'additionalProperties' => false];
    return [[
        'name' => 'get_agent_public_brain',
        'title' => 'Get an NFH public brain and ownership epochs',
        'description' => 'Read transferable history, learning receipts, promoted skills, owner epochs, and separated evidence. Live-checks ownerOf; grants no authority.',
        'inputSchema' => ['type' => 'object', 'properties' => ['tokenId' => $tokenIdSchema], 'required' => ['tokenId'], 'additionalProperties' => false],
        'outputSchema' => ['type' => 'object', 'additionalProperties' => true],
        'annotations' => $read,
    ], [
        'name' => 'list_agent_learning_receipts',
        'title' => 'List evaluated NFH learning receipts',
        'description' => 'List ACT → RECORD → EVALUATE receipts and promotion decisions. Missing evaluation stays incomplete; receipts grant no authority.',
        'inputSchema' => ['type' => 'object', 'properties' => [
            'tokenId' => $tokenIdSchema,
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 250],
        ], 'required' => ['tokenId'], 'additionalProperties' => false],
        'outputSchema' => ['type' => 'object', 'additionalProperties' => true],
        'annotations' => $read,
    ], [
        'name' => 'prepare_agent_learning_decision',
        'title' => 'Prepare a tested NFH skill decision',
        'description' => 'Prepare owner-signable PROMOTE or REJECT text for one tested skill proposal. Never signs or publishes; swarm promotion is curator-gated.',
        'inputSchema' => ['type' => 'object', 'properties' => [
            'tokenId' => $tokenIdSchema,
            'owner' => $addressSchema,
            'proposalId' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$', 'description' => 'Public skill-proposal id.'],
            'decision' => ['type' => 'string', 'enum' => ['PROMOTE', 'REJECT'], 'description' => 'Owner decision.'],
            'tests' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 10, 'items' => $testSchema, 'description' => 'One to ten test results.'],
            'rationale' => ['type' => 'string', 'minLength' => 20, 'maxLength' => 600, 'description' => 'Public decision rationale.'],
        ], 'required' => ['tokenId', 'owner', 'proposalId', 'decision', 'tests', 'rationale'], 'additionalProperties' => false],
        'outputSchema' => ['type' => 'object', 'additionalProperties' => true],
        'annotations' => $prepare,
    ], [
        'name' => 'prepare_agent_skill_rollback',
        'title' => 'Prepare an NFH skill rollback',
        'description' => 'Prepare owner-signable rollback to a promoted, retested skill version. Never signs, publishes, deletes history, or changes authority.',
        'inputSchema' => ['type' => 'object', 'properties' => [
            'tokenId' => $tokenIdSchema,
            'owner' => $addressSchema,
            'skillName' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 64, 'description' => 'Promoted skill name.'],
            'targetVersion' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10000, 'description' => 'Previously promoted version to restore.'],
            'tests' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 10, 'items' => $testSchema, 'description' => 'Retest evidence for the rollback.'],
            'rationale' => ['type' => 'string', 'minLength' => 20, 'maxLength' => 600, 'description' => 'Public rollback rationale.'],
        ], 'required' => ['tokenId', 'owner', 'skillName', 'targetVersion', 'tests', 'rationale'], 'additionalProperties' => false],
        'outputSchema' => ['type' => 'object', 'additionalProperties' => true],
        'annotations' => $prepare,
    ]];
}

function nfh_agent_brain_call_tool(string $name, array $arguments): array
{
    try {
        if ($name === 'get_agent_public_brain') {
            return nfh_tool_payload(nfh_agent_public_brain(nfh_agent_wanted_token_id($arguments['tokenId'] ?? null)));
        }
        if ($name === 'list_agent_learning_receipts') {
            $tokenId = nfh_agent_wanted_token_id($arguments['tokenId'] ?? null);
            $limit = $arguments['limit'] ?? 100;
            if (!is_int($limit)) throw new InvalidArgumentException('limit must be an integer.');
            return nfh_tool_payload(nfh_agent_learning_feed($tokenId, $limit));
        }
        if ($name === 'prepare_agent_learning_decision') {
            return nfh_tool_payload(nfh_agent_learning_prepare_decision($arguments));
        }
        if ($name === 'prepare_agent_skill_rollback') {
            return nfh_tool_payload(nfh_agent_skill_prepare_rollback($arguments));
        }
    } catch (InvalidArgumentException|RuntimeException|JsonException $error) {
        return nfh_tool_error($error->getMessage());
    }
    return nfh_tool_error('Unknown Agent Brain tool: ' . $name);
}

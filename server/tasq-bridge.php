<?php

declare(strict_types=1);

const NFH_TASQ_BINDING_SCHEMA = 'nfh.tasq-principal-binding.v1';
const NFH_TASQ_BINDING_PACKET_SCHEMA = 'nfh.tasq-principal-binding-packet.v1';
const NFH_TASQ_TRANSPORT_SCHEMA = 'nfh.tasq-transport.v1';
const NFH_TASQ_CLAIM_PROJECTION_SCHEMA = 'nfh.tasq-claim-projection.v1';
const NFH_TASQ_TRANSITION_AUTHORIZATION_SCHEMA = 'nfh.tasq-transition-authorization.v1';
const NFH_TASQ_VALIDATOR_AUTHORIZATION_SCHEMA = 'nfh.tasq-validator-authorization.v1';
const NFH_TASQ_BINDING_LIFETIME = 5 * 60;
const NFH_TASQ_MAX_LOG_BYTES = 4_000_000;

/** @return array{kind:string,id:string} */
function nfh_tasq_transport(mixed $value): array
{
    if (!is_array($value) || array_is_list($value)
        || array_diff(array_keys($value), ['kind', 'id']) !== []) {
        throw new InvalidArgumentException('transport must contain only kind and id.');
    }
    $kind = $value['kind'] ?? null;
    if (!in_array($kind, ['local_process', 'shared_store', 'streamable_http'], true)) {
        throw new InvalidArgumentException('transport.kind must be local_process, shared_store, or streamable_http.');
    }
    $id = $value['id'] ?? null;
    if (!is_string($id) || preg_match('#^[A-Za-z0-9][A-Za-z0-9._:/-]{2,127}$#', $id) !== 1) {
        throw new InvalidArgumentException('transport.id must be a stable non-secret rendezvous identifier.');
    }
    return ['kind' => $kind, 'id' => $id];
}

function nfh_tasq_space_id(mixed $value): string
{
    if (!is_string($value) || preg_match('#^[A-Za-z0-9][A-Za-z0-9._:/-]{2,127}$#', $value) !== 1) {
        throw new InvalidArgumentException('spaceId must be a stable Tasq space identifier.');
    }
    return $value;
}

function nfh_tasq_principal_id(mixed $value): string
{
    if (!is_string($value) || strlen($value) < 12 || strlen($value) > 512
        || preg_match('/^urn:tasq:[A-Za-z0-9._:%\/+-]+$/', $value) !== 1) {
        throw new InvalidArgumentException('tasqPrincipalId must be the exact urn:tasq principal returned by Tasq onboarding.');
    }
    return $value;
}

function nfh_tasq_actor_alias(int $tokenId, int $epochNumber): string
{
    return 'nfh:' . $tokenId . ':epoch:' . $epochNumber;
}

/** @param array<string, mixed> $payload */
function nfh_tasq_binding_message(array $payload): string
{
    $transport = nfh_tasq_transport($payload['transport'] ?? null);
    return "NOT FOR HUMANS Tasq Principal Binding\n"
        . "Version: 1\n"
        . "Domain: mcp.notforhumans.fun\n"
        . "Action: Bind NFH ownership epoch to one Tasq principal\n"
        . "Chain ID: 1\n"
        . "Collection: " . strtolower(NFH_AGENT_WANTED_COLLECTION) . "\n"
        . "Token ID: {$payload['tokenId']}\n"
        . "Owner: " . strtolower((string) $payload['owner']) . "\n"
        . "Ownership Epoch: {$payload['epochId']}\n"
        . "Epoch Number: {$payload['epochNumber']}\n"
        . "Tasq Space: {$payload['spaceId']}\n"
        . "Tasq Principal: {$payload['tasqPrincipalId']}\n"
        . "Actor Alias: {$payload['actorAlias']}\n"
        . "Transport Kind: {$transport['kind']}\n"
        . "Transport ID: {$transport['id']}\n"
        . "Nonce: {$payload['nonce']}\n"
        . "Issued At: " . gmdate('c', (int) $payload['issuedAt']) . "\n"
        . "Expiration Time: " . gmdate('c', (int) $payload['expiresAt']) . "\n"
        . "Statement: This signature binds the current NFH ownership epoch to one Tasq coordination principal. It authorizes no transaction, approval, transfer, spend, wallet access, external effect, or third-party endorsement.";
}

/** @return array<string, mixed> */
function nfh_tasq_normalize_binding_payload(mixed $value, ?int $now = null): array
{
    $now ??= time();
    if (!is_array($value) || array_is_list($value)) {
        throw new InvalidArgumentException('payload must be an object.');
    }
    $allowed = [
        'schema', 'version', 'chainId', 'collection', 'tokenId', 'owner', 'epochId',
        'epochNumber', 'spaceId', 'tasqPrincipalId', 'actorAlias', 'transport',
        'nonce', 'issuedAt', 'expiresAt',
    ];
    if (array_diff(array_keys($value), $allowed) !== []) {
        throw new InvalidArgumentException('payload contains unsupported fields.');
    }
    if (($value['schema'] ?? null) !== NFH_TASQ_BINDING_PACKET_SCHEMA || ($value['version'] ?? null) !== 1
        || ($value['chainId'] ?? null) !== 1
        || !is_string($value['collection'] ?? null)
        || strcasecmp((string) $value['collection'], NFH_AGENT_WANTED_COLLECTION) !== 0) {
        throw new InvalidArgumentException('payload domain is invalid.');
    }
    $tokenId = nfh_agent_wanted_token_id($value['tokenId'] ?? null);
    $owner = nfh_agent_wanted_owner($value['owner'] ?? null);
    $epochId = $value['epochId'] ?? null;
    $epochNumber = $value['epochNumber'] ?? null;
    if (!is_string($epochId) || preg_match('/^[a-f0-9]{64}$/', $epochId) !== 1
        || !is_int($epochNumber) || $epochNumber < 1) {
        throw new InvalidArgumentException('payload ownership epoch is invalid.');
    }
    $spaceId = nfh_tasq_space_id($value['spaceId'] ?? null);
    $principalId = nfh_tasq_principal_id($value['tasqPrincipalId'] ?? null);
    $actorAlias = $value['actorAlias'] ?? null;
    if (!is_string($actorAlias) || !hash_equals(nfh_tasq_actor_alias($tokenId, $epochNumber), $actorAlias)) {
        throw new InvalidArgumentException('payload actorAlias does not match the NFH token and epoch.');
    }
    $transport = nfh_tasq_transport($value['transport'] ?? null);
    $nonce = $value['nonce'] ?? null;
    $issuedAt = $value['issuedAt'] ?? null;
    $expiresAt = $value['expiresAt'] ?? null;
    if (!is_string($nonce) || preg_match('/^[a-f0-9]{64}$/', $nonce) !== 1
        || !is_int($issuedAt) || !is_int($expiresAt)
        || $issuedAt > $now + 60 || $expiresAt <= $now
        || $expiresAt - $issuedAt < 60 || $expiresAt - $issuedAt > NFH_TASQ_BINDING_LIFETIME) {
        throw new InvalidArgumentException('payload signing window is invalid or expired.');
    }
    return [
        'schema' => NFH_TASQ_BINDING_PACKET_SCHEMA,
        'version' => 1,
        'chainId' => 1,
        'collection' => strtolower(NFH_AGENT_WANTED_COLLECTION),
        'tokenId' => $tokenId,
        'owner' => $owner,
        'epochId' => $epochId,
        'epochNumber' => $epochNumber,
        'spaceId' => $spaceId,
        'tasqPrincipalId' => $principalId,
        'actorAlias' => $actorAlias,
        'transport' => $transport,
        'nonce' => $nonce,
        'issuedAt' => $issuedAt,
        'expiresAt' => $expiresAt,
    ];
}

/** @param array<string, mixed> $input @return array<string, mixed> */
function nfh_tasq_prepare_binding(array $input, ?int $now = null): array
{
    $now ??= time();
    if (array_diff(array_keys($input), ['tokenId', 'spaceId', 'tasqPrincipalId', 'transport']) !== []) {
        throw new InvalidArgumentException('Binding preparation contains unsupported fields.');
    }
    $tokenId = nfh_agent_wanted_token_id($input['tokenId'] ?? null);
    $spaceId = nfh_tasq_space_id($input['spaceId'] ?? null);
    $principalId = nfh_tasq_principal_id($input['tasqPrincipalId'] ?? null);
    $transport = nfh_tasq_transport($input['transport'] ?? null);
    $epoch = nfh_agent_ownership_epoch_sync($tokenId, $now);
    $payload = [
        'schema' => NFH_TASQ_BINDING_PACKET_SCHEMA,
        'version' => 1,
        'chainId' => 1,
        'collection' => strtolower(NFH_AGENT_WANTED_COLLECTION),
        'tokenId' => $tokenId,
        'owner' => (string) $epoch['operator'],
        'epochId' => (string) $epoch['epochId'],
        'epochNumber' => (int) $epoch['number'],
        'spaceId' => $spaceId,
        'tasqPrincipalId' => $principalId,
        'actorAlias' => nfh_tasq_actor_alias($tokenId, (int) $epoch['number']),
        'transport' => $transport,
        'nonce' => bin2hex(random_bytes(32)),
        'issuedAt' => $now,
        'expiresAt' => $now + NFH_TASQ_BINDING_LIFETIME,
    ];
    return [
        'schema' => NFH_TASQ_BINDING_PACKET_SCHEMA,
        'status' => 'prepared_unsigned',
        'payload' => $payload,
        'message' => nfh_tasq_binding_message($payload),
        'signingMethod' => 'personal_sign',
        'publishEndpoint' => nfh_base_url() . '/tasq/binding/publish',
        'requiresWalletSignature' => true,
        'ownershipVerifiedAtPreparation' => true,
        'ownershipVerifiedAtPublication' => false,
        'transportRendezvousVerified' => false,
        'mcpSigned' => false,
        'mcpSubmitted' => false,
        'effectAuthorityGranted' => false,
        'warnings' => [
            'Review and sign this exact message only with the current owner wallet.',
            'Publication repeats live ownerOf and ownership-epoch verification.',
            'The transport ID is a signed rendezvous identity, not proof that two devices share a store.',
            'The binding grants coordination identity only; it grants no wallet or external-effect authority.',
        ],
    ];
}

function nfh_tasq_bridge_directory(): string
{
    $configured = trim((string) (getenv('NFH_TASQ_BRIDGE_DIR') ?: ''));
    $directory = $configured !== ''
        ? $configured
        : (nfh_is_local_cli_runtime()
            ? nfh_runtime_directory() . '/tasq-bridge'
            : '/home/notforhumans/.nfh-tasq-bridge');
    if (!str_starts_with($directory, DIRECTORY_SEPARATOR) || is_link($directory)) {
        throw new RuntimeException('Tasq bridge storage path is unsafe.');
    }
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Tasq bridge storage is unavailable.');
    }
    clearstatcache(true, $directory);
    if (is_link($directory) || (((int) fileperms($directory)) & 0077) !== 0) {
        throw new RuntimeException('Tasq bridge storage permissions are unsafe.');
    }
    return $directory;
}

function nfh_tasq_bridge_log_path(): string
{
    return nfh_tasq_bridge_directory() . '/bindings.jsonl';
}

/** @return array<int, array<string, mixed>> */
function nfh_tasq_bridge_read_events($handle): array
{
    rewind($handle);
    $raw = stream_get_contents($handle, NFH_TASQ_MAX_LOG_BYTES + 1);
    if (!is_string($raw) || strlen($raw) > NFH_TASQ_MAX_LOG_BYTES) {
        throw new RuntimeException('Tasq bridge storage exceeded its safe read limit.');
    }
    $events = [];
    foreach (preg_split('/\r?\n/', trim($raw)) ?: [] as $line) {
        if ($line === '') continue;
        try { $event = json_decode($line, true, flags: JSON_THROW_ON_ERROR); }
        catch (JsonException) { continue; }
        if (is_array($event) && ($event['schema'] ?? null) === NFH_TASQ_BINDING_SCHEMA) $events[] = $event;
    }
    return $events;
}

/** @return mixed */
function nfh_tasq_bridge_locked(callable $callback, bool $exclusive = true): mixed
{
    $path = nfh_tasq_bridge_log_path();
    if (is_link($path)) throw new RuntimeException('Tasq bridge storage file is unsafe.');
    $handle = fopen($path, 'c+b');
    if ($handle === false) throw new RuntimeException('Tasq bridge storage is unavailable.');
    try {
        chmod($path, 0600);
        if (!flock($handle, $exclusive ? LOCK_EX : LOCK_SH)) throw new RuntimeException('Tasq bridge storage lock failed.');
        return $callback($handle);
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

/** @param array<string, mixed> $record */
function nfh_tasq_bridge_append($handle, array $record): void
{
    $encoded = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    fseek($handle, 0, SEEK_END);
    $position = ftell($handle);
    if ($position === false || $position + strlen($encoded) > NFH_TASQ_MAX_LOG_BYTES) {
        throw new RuntimeException('Tasq bridge storage is full.');
    }
    if (fwrite($handle, $encoded) !== strlen($encoded) || !fflush($handle)) {
        throw new RuntimeException('Tasq bridge storage write failed.');
    }
}

/** @return array<string, mixed> */
function nfh_tasq_publish_binding(array $input, ?int $now = null): array
{
    $now ??= time();
    if (array_diff(array_keys($input), ['payload', 'signature']) !== []) {
        throw new InvalidArgumentException('Binding publication contains unsupported fields.');
    }
    $payload = nfh_tasq_normalize_binding_payload($input['payload'] ?? null, $now);
    $signature = $input['signature'] ?? null;
    if (!is_string($signature)) throw new InvalidArgumentException('signature is required.');
    $message = nfh_tasq_binding_message($payload);
    $signer = strtolower(nfh_verify_recover($message, $signature, nfh_verify_config()));
    if (!hash_equals((string) $payload['owner'], $signer)) {
        throw new RuntimeException('The signature does not match the prepared current owner.');
    }
    $epoch = nfh_agent_ownership_epoch_assert_runtime(
        (int) $payload['tokenId'],
        $signer,
        (string) $payload['epochId'],
        $now,
    );
    if ((int) $epoch['number'] !== (int) $payload['epochNumber']) {
        throw new RuntimeException('The prepared ownership epoch is no longer current.');
    }
    $messageDigest = hash('sha256', $message);
    $bindingId = hash('sha256', 'nfh-tasq-binding|' . $messageDigest . '|' . $signer);
    $record = [
        'schema' => NFH_TASQ_BINDING_SCHEMA,
        'bindingId' => $bindingId,
        'chainId' => 1,
        'collection' => strtolower(NFH_AGENT_WANTED_COLLECTION),
        'tokenId' => (int) $payload['tokenId'],
        'operator' => $signer,
        'epochId' => (string) $payload['epochId'],
        'epochNumber' => (int) $payload['epochNumber'],
        'spaceId' => (string) $payload['spaceId'],
        'tasqPrincipalId' => (string) $payload['tasqPrincipalId'],
        'actorAlias' => (string) $payload['actorAlias'],
        'transport' => $payload['transport'],
        'messageDigest' => 'sha256:' . $messageDigest,
        'verifiedAt' => gmdate('c', $now),
        'verification' => [
            'walletSignature' => 'verified',
            'ownerOf' => 'two-rpc-quorum-verified',
            'ownershipEpoch' => 'current-at-publication',
            'tasqPrincipalAuthentication' => 'host-binding-required',
        ],
        'authority' => [
            'coordinationIdentity' => true,
            'wallet' => false,
            'transaction' => false,
            'effect' => false,
        ],
    ];
    return nfh_tasq_bridge_locked(static function ($handle) use ($record): array {
        $events = nfh_tasq_bridge_read_events($handle);
        foreach ($events as $existing) {
            if (hash_equals((string) $existing['bindingId'], (string) $record['bindingId'])) return $existing;
        }
        $record['supersedesBindingId'] = null;
        foreach (array_reverse($events) as $existing) {
            if (($existing['tokenId'] ?? null) === $record['tokenId']
                && hash_equals((string) ($existing['spaceId'] ?? ''), (string) $record['spaceId'])
                && ($existing['transport'] ?? null) === $record['transport']) {
                $record['supersedesBindingId'] = (string) $existing['bindingId'];
                break;
            }
        }
        nfh_tasq_bridge_append($handle, $record);
        return $record;
    });
}

/** @return array<int, array<string, mixed>> */
function nfh_tasq_bindings(): array
{
    $path = nfh_tasq_bridge_log_path();
    if (!is_file($path)) return [];
    return nfh_tasq_bridge_locked(static fn ($handle): array => nfh_tasq_bridge_read_events($handle), false);
}

/** @return array<string, mixed>|null */
function nfh_tasq_binding_by_id(string $bindingId): ?array
{
    if (preg_match('/^[a-f0-9]{64}$/', $bindingId) !== 1) throw new InvalidArgumentException('bindingId is invalid.');
    foreach (array_reverse(nfh_tasq_bindings()) as $binding) {
        if (hash_equals((string) $binding['bindingId'], $bindingId)) return $binding;
    }
    return null;
}

/** @param array<string, mixed> $binding @return array<string, mixed> */
function nfh_tasq_assert_live_binding(array $binding, ?int $now = null): array
{
    $now ??= time();
    $epoch = nfh_agent_ownership_epoch_assert_runtime(
        (int) ($binding['tokenId'] ?? -1),
        (string) ($binding['operator'] ?? ''),
        is_string($binding['epochId'] ?? null) ? $binding['epochId'] : null,
        $now,
    );
    if ((int) $epoch['number'] !== (int) ($binding['epochNumber'] ?? 0)) {
        throw new RuntimeException('The Tasq binding belongs to a former NFH ownership epoch.');
    }
    return $binding + [
        'currentOwnershipStatus' => 'live-ownerOf-and-epoch-verified',
        'currentOwnershipVerifiedAt' => gmdate('c', $now),
    ];
}

/** @return array<string, mixed> */
function nfh_tasq_current_binding(int $tokenId, string $spaceId, array $transport, ?int $now = null): array
{
    $tokenId = nfh_agent_wanted_token_id($tokenId);
    $spaceId = nfh_tasq_space_id($spaceId);
    $transport = nfh_tasq_transport($transport);
    foreach (array_reverse(nfh_tasq_bindings()) as $binding) {
        if (($binding['tokenId'] ?? null) !== $tokenId
            || !hash_equals((string) ($binding['spaceId'] ?? ''), $spaceId)
            || ($binding['transport'] ?? null) !== $transport) continue;
        return nfh_tasq_assert_live_binding($binding, $now);
    }
    throw new RuntimeException('No matching authenticated Tasq principal binding exists.');
}

/** @param array<string, mixed> $binding @return array<string, mixed> */
function nfh_tasq_assert_current_binding_record(array $binding, ?int $now = null): array
{
    $current = nfh_tasq_current_binding(
        (int) ($binding['tokenId'] ?? -1),
        (string) ($binding['spaceId'] ?? ''),
        is_array($binding['transport'] ?? null) ? $binding['transport'] : [],
        $now,
    );
    if (!hash_equals((string) ($binding['bindingId'] ?? ''), (string) $current['bindingId'])) {
        throw new RuntimeException('The Tasq principal binding has been superseded.');
    }
    return $current;
}

/** @param array<string, mixed> $claim @return array<string, mixed> */
function nfh_tasq_project_claim(array $claim, int $authorityNowMs): array
{
    $required = ['id', 'workspaceId', 'commitmentId', 'principalId', 'actorAlias', 'revision', 'fence', 'expiresAt'];
    foreach ($required as $field) {
        if (!array_key_exists($field, $claim)) throw new InvalidArgumentException('claim.' . $field . ' is required.');
    }
    if (!is_string($claim['id']) || $claim['id'] === ''
        || !is_string($claim['workspaceId']) || $claim['workspaceId'] === ''
        || !is_string($claim['commitmentId']) || $claim['commitmentId'] === ''
        || !is_string($claim['principalId']) || $claim['principalId'] === ''
        || !is_string($claim['actorAlias']) || $claim['actorAlias'] === ''
        || !is_int($claim['revision']) || $claim['revision'] < 1
        || !is_int($claim['fence']) || $claim['fence'] < 1
        || !is_int($claim['expiresAt']) || $claim['expiresAt'] < 1
        || $authorityNowMs < 1
        || (array_key_exists('releasedAt', $claim) && $claim['releasedAt'] !== null && !is_int($claim['releasedAt']))) {
        throw new InvalidArgumentException('claim contains invalid lease fields.');
    }
    $releasedAt = $claim['releasedAt'] ?? null;
    $state = is_int($releasedAt) ? 'released' : ($claim['expiresAt'] <= $authorityNowMs ? 'expired' : 'active');
    return [
        'schema' => NFH_TASQ_CLAIM_PROJECTION_SCHEMA,
        'claimId' => $claim['id'],
        'workspaceId' => $claim['workspaceId'],
        'commitmentId' => $claim['commitmentId'],
        'principalId' => $claim['principalId'],
        'actorAlias' => $claim['actorAlias'],
        'revision' => $claim['revision'],
        'fence' => $claim['fence'],
        'state' => $state,
        'active' => $state === 'active',
        'expiresAt' => $claim['expiresAt'],
        'releasedAt' => $releasedAt,
        'authorityObservedAt' => $authorityNowMs,
        'leaseRecordRetained' => true,
        'rule' => 'Active only when unreleased and expiresAt is later than the trusted authority clock.',
    ];
}

/** @return array<string, mixed> */
function nfh_tasq_authorize_transition(
    string $bindingId,
    array $claim,
    string $operation,
    string $spaceId,
    array $transport,
    int $authorityNowMs,
    ?int $ownershipNow = null,
): array {
    $allowed = [
        'commitment.start', 'attempt.start', 'attempt.transition', 'evidence.append',
        'resolution.propose', 'completion.complete',
    ];
    if (!in_array($operation, $allowed, true)) throw new InvalidArgumentException('Unsupported guarded Tasq transition.');
    $binding = nfh_tasq_binding_by_id($bindingId);
    if ($binding === null) throw new RuntimeException('Authenticated Tasq principal binding not found.');
    $binding = nfh_tasq_assert_current_binding_record($binding, $ownershipNow);
    $spaceId = nfh_tasq_space_id($spaceId);
    $transport = nfh_tasq_transport($transport);
    if (!hash_equals((string) $binding['spaceId'], $spaceId) || $binding['transport'] !== $transport) {
        throw new RuntimeException('Tasq space or transport does not match the signed principal binding.');
    }
    $projection = nfh_tasq_project_claim($claim, $authorityNowMs);
    if (!$projection['active']) throw new RuntimeException('The Tasq claim is not active according to the authority clock.');
    if (!hash_equals($spaceId, (string) $projection['workspaceId'])) {
        throw new RuntimeException('The active Tasq claim belongs to a different space.');
    }
    if (!hash_equals((string) $binding['tasqPrincipalId'], (string) $projection['principalId'])
        || !hash_equals((string) $binding['actorAlias'], (string) $projection['actorAlias'])) {
        throw new RuntimeException('The active Tasq claim belongs to a different authenticated principal or actor epoch.');
    }
    return [
        'schema' => NFH_TASQ_TRANSITION_AUTHORIZATION_SCHEMA,
        'authorized' => true,
        'operation' => $operation,
        'bindingId' => $bindingId,
        'tokenId' => $binding['tokenId'],
        'epochId' => $binding['epochId'],
        'tasqPrincipalId' => $binding['tasqPrincipalId'],
        'actorAlias' => $binding['actorAlias'],
        'spaceId' => $spaceId,
        'transport' => $transport,
        'claim' => $projection,
        'effectAuthorityGranted' => false,
        'walletAuthorityGranted' => false,
        'transportRendezvousVerified' => false,
        'hostRequirement' => 'Invoke Tasq only with this exact principal, active claim, space, and transport. Recheck immediately before the transition.',
    ];
}

/** @return array<string, mixed> */
function nfh_tasq_authorize_validator(
    string $proposerBindingId,
    string $validatorBindingId,
    array $resolutionContract,
    array $proposal,
    string $spaceId,
    array $transport,
    ?int $now = null,
): array {
    $proposer = nfh_tasq_binding_by_id($proposerBindingId);
    $validator = nfh_tasq_binding_by_id($validatorBindingId);
    if ($proposer === null || $validator === null) throw new RuntimeException('Authenticated proposer and validator bindings are required.');
    $proposer = nfh_tasq_assert_current_binding_record($proposer, $now);
    $validator = nfh_tasq_assert_current_binding_record($validator, $now);
    $spaceId = nfh_tasq_space_id($spaceId);
    $transport = nfh_tasq_transport($transport);
    foreach ([$proposer, $validator] as $binding) {
        if (!hash_equals((string) $binding['spaceId'], $spaceId) || $binding['transport'] !== $transport) {
            throw new RuntimeException('Validator and proposer must use the same signed Tasq space and transport.');
        }
    }
    if (hash_equals((string) $proposer['tasqPrincipalId'], (string) $validator['tasqPrincipalId'])
        || hash_equals((string) $proposer['operator'], (string) $validator['operator'])
        || (int) $proposer['tokenId'] === (int) $validator['tokenId']) {
        throw new RuntimeException('Validation requires a distinct authenticated NFH token, operator wallet, and Tasq principal.');
    }
    $eligible = $resolutionContract['eligibleValidatorPrincipalIds'] ?? null;
    if (!is_array($eligible) || !in_array($validator['tasqPrincipalId'], $eligible, true)) {
        throw new RuntimeException('The authenticated validator is not eligible under the frozen resolution contract.');
    }
    if (($resolutionContract['allowSelfValidation'] ?? null) !== false) {
        throw new RuntimeException('The NFH bridge requires self-validation to be explicitly disabled.');
    }
    $contractId = $resolutionContract['id'] ?? null;
    $contractDigest = $resolutionContract['contractDigest'] ?? null;
    $contractSpace = $resolutionContract['workspaceId'] ?? $resolutionContract['tenantId'] ?? null;
    $proposalSpace = $proposal['workspaceId'] ?? $proposal['tenantId'] ?? null;
    if (!is_string($contractId) || $contractId === ''
        || !is_string($contractDigest) || preg_match('/^sha256:[a-f0-9]{64}$/', $contractDigest) !== 1
        || !is_string($contractSpace) || !hash_equals($spaceId, $contractSpace)
        || !is_string($proposalSpace) || !hash_equals($spaceId, $proposalSpace)
        || !is_string($proposal['resolutionContractId'] ?? null)
        || !hash_equals($contractId, (string) $proposal['resolutionContractId'])
        || !is_string($proposal['contractDigest'] ?? null)
        || !hash_equals($contractDigest, (string) $proposal['contractDigest'])) {
        throw new RuntimeException('The validator request does not bind the exact frozen resolution contract, proposal, and space.');
    }
    if (!is_string($proposal['proposerPrincipalId'] ?? null)
        || !hash_equals((string) $proposal['proposerPrincipalId'], (string) $proposer['tasqPrincipalId'])) {
        throw new RuntimeException('The proposal does not belong to the authenticated proposer binding.');
    }
    return [
        'schema' => NFH_TASQ_VALIDATOR_AUTHORIZATION_SCHEMA,
        'authorized' => true,
        'proposerBindingId' => $proposerBindingId,
        'validatorBindingId' => $validatorBindingId,
        'validatorTasqPrincipalId' => $validator['tasqPrincipalId'],
        'walletAndTokenDistinct' => true,
        'ownershipEpochsCurrent' => true,
        'spaceId' => $spaceId,
        'transport' => $transport,
        'infrastructureIndependenceVerified' => false,
        'effectAuthorityGranted' => false,
        'boundary' => 'This proves distinct current NFH owners and Tasq principals. It does not prove separate devices, infrastructure, recovery domains, or real-world independence.',
    ];
}

/** @return array<int, array<string, mixed>> */
function nfh_tasq_bridge_tool_definitions(array $tokenIdSchema, array $readOnlyAnnotations, array $preparationAnnotations): array
{
    $transportSchema = [
        'type' => 'object',
        'properties' => [
            'kind' => ['type' => 'string', 'enum' => ['local_process', 'shared_store', 'streamable_http']],
            'id' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 128],
        ],
        'required' => ['kind', 'id'],
        'additionalProperties' => false,
    ];
    $input = [
        'type' => 'object',
        'properties' => [
            'tokenId' => $tokenIdSchema,
            'spaceId' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 128, 'description' => 'Tasq coordination space id.'],
            'tasqPrincipalId' => ['type' => 'string', 'minLength' => 12, 'maxLength' => 512, 'description' => 'Tasq principal id to bind.'],
            'transport' => $transportSchema + ['description' => 'Exact Tasq transport identity.'],
        ],
        'required' => ['tokenId', 'spaceId', 'tasqPrincipalId', 'transport'],
        'additionalProperties' => false,
    ];
    return [
        [
            'name' => 'prepare_tasq_principal_binding',
            'title' => 'Prepare an NFH-authenticated Tasq principal binding',
            'description' => 'Prepare EIP-191 text binding the current ownership epoch to one Tasq principal, space, and transport. Publish rechecks ownerOf and epoch.',
            'inputSchema' => $input,
            'outputSchema' => ['type' => 'object', 'additionalProperties' => true],
            'annotations' => $preparationAnnotations,
        ],
        [
            'name' => 'get_tasq_principal_binding',
            'title' => 'Get a current NFH-authenticated Tasq principal binding',
            'description' => 'Read one Tasq binding after live ownership verification. Binding grants coordination identity only, never wallet or effect authority.',
            'inputSchema' => $input,
            'outputSchema' => ['type' => 'object', 'additionalProperties' => true],
            'annotations' => $readOnlyAnnotations + ['openWorldHint' => true],
        ],
    ];
}

/** @param array<string, mixed> $arguments */
function nfh_tasq_bridge_call_tool(string $name, array $arguments): array
{
    try {
        if ($name === 'prepare_tasq_principal_binding') {
            return nfh_tool_payload(nfh_tasq_prepare_binding($arguments));
        }
        if ($name === 'get_tasq_principal_binding') {
            $binding = nfh_tasq_current_binding(
                nfh_agent_wanted_token_id($arguments['tokenId'] ?? null),
                nfh_tasq_space_id($arguments['spaceId'] ?? null),
                nfh_tasq_transport($arguments['transport'] ?? null),
            );
            if (!hash_equals((string) $binding['tasqPrincipalId'], nfh_tasq_principal_id($arguments['tasqPrincipalId'] ?? null))) {
                throw new RuntimeException('The current binding targets a different Tasq principal.');
            }
            return nfh_tool_payload($binding);
        }
        return nfh_tool_error('Unknown Tasq bridge tool.');
    } catch (InvalidArgumentException|RuntimeException|JsonException $error) {
        return nfh_tool_error($error->getMessage());
    }
}

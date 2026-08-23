<?php

declare(strict_types=1);

const NFH_AGENT_ENTRY_SCHEMA = 'nfh.agent-entry.v1';
const NFH_AGENT_ENTRY_VERSION = 1;
const NFH_AGENT_ENTRY_ACTION = 'RESERVE_UNMINTED_NFH';
const NFH_AGENT_ENTRY_EVIDENCE_ACTION = 'SUBMIT_AGENT_ENTRY_ACTIVITY';
const NFH_AGENT_ENTRY_COLLECTION = '0xD66351858E0eFC5d9Bf2F541839797A763DF6223';
const NFH_AGENT_ENTRY_DEPLOYED_MINTER = '0x499Ae3f426a23dD02b4088cc3453cdA843850359';
const NFH_AGENT_ENTRY_DEPLOYED_CREDENTIAL_SIGNER = '0xB76F4A1696c7Df99AE1FfDbC5347a32137Cbe627';
const NFH_AGENT_ENTRY_DEPLOYMENT_RECEIPT = 'https://notforhumans.fun/api/phase-two.json';
const NFH_AGENT_ENTRY_CAPACITY = 1000;
const NFH_AGENT_ENTRY_LIFETIME = 24 * 60 * 60;
const NFH_AGENT_ENTRY_PREPARATION_LIFETIME = 10 * 60;
const NFH_AGENT_ENTRY_MIN_ACTIVITY_AGE = 10 * 60;
const NFH_AGENT_ENTRY_CREDENTIAL_LIFETIME = 30 * 60;
const NFH_AGENT_ENTRY_MAX_LOG_BYTES = 5_000_000;
const NFH_AGENT_ENTRY_COMPACTION_TRIGGER_BYTES = 4_500_000;
const NFH_AGENT_ENTRY_COMPACTED_SCHEMA = 'nfh.agent-entry.compacted.v1';
const NFH_AGENT_ENTRY_ADMISSION_WINDOW = 24 * 60 * 60;
const NFH_AGENT_ENTRY_MAX_ADMISSIONS_PER_WINDOW = 100;
const NFH_AGENT_ENTRY_MAX_LIFETIME_ADMISSIONS = 7500;

function nfh_agent_entry_enabled(): bool
{
    return hash_equals('1', trim((string) (getenv('NFH_AGENT_ENTRY_RESERVATIONS_ENABLED') ?: '')));
}

function nfh_agent_entry_require_enabled(): void
{
    if (!nfh_agent_entry_enabled()) {
        throw new RuntimeException('Agent Entry reservations are not enabled in this runtime. No reservation or claim availability may be inferred.');
    }
}

/** @return array{enabled: bool, minter: ?string, credentialSigner: ?string} */
function nfh_agent_entry_claim_config(): array
{
    $minter = strtolower(trim((string) (getenv('NFH_AGENT_ENTRY_MINTER_ADDRESS') ?: '')));
    $signer = strtolower(trim((string) (getenv('NFH_AGENT_ENTRY_CREDENTIAL_SIGNER') ?: '')));
    $validAddress = static fn (string $value): bool => preg_match('/^0x[a-f0-9]{40}$/', $value) === 1
        && $value !== '0x0000000000000000000000000000000000000000';
    return [
        'enabled' => nfh_agent_entry_enabled()
            && hash_equals('1', trim((string) (getenv('NFH_AGENT_ENTRY_CLAIMS_ENABLED') ?: '')))
            && $validAddress($minter)
            && $validAddress($signer),
        'minter' => $validAddress($minter) ? $minter : null,
        'credentialSigner' => $validAddress($signer) ? $signer : null,
    ];
}

function nfh_agent_entry_rpc_address(string $contract, string $selector): string
{
    $result = nfh_verify_rpc('eth_call', [['to' => $contract, 'data' => $selector], 'latest'], nfh_verify_config());
    if (preg_match('/^0x[0-9a-f]{64}$/', $result) !== 1) throw new RuntimeException('Agent Entry contract returned invalid address data.');
    return '0x' . substr($result, -40);
}

function nfh_agent_entry_rpc_bool(string $contract, string $data): bool
{
    $result = nfh_verify_rpc('eth_call', [['to' => $contract, 'data' => $data], 'latest'], nfh_verify_config());
    if (preg_match('/^0x[0-9a-f]{64}$/', $result) !== 1) throw new RuntimeException('Agent Entry contract returned invalid boolean data.');
    return hexdec(substr($result, -1)) === 1;
}

function nfh_agent_entry_rpc_uint(string $contract, string $data): int
{
    $result = nfh_verify_rpc('eth_call', [['to' => $contract, 'data' => $data], 'latest'], nfh_verify_config());
    if (preg_match('/^0x[0-9a-f]{64}$/', $result) !== 1) throw new RuntimeException('Agent Entry contract returned invalid integer data.');
    $trimmed = ltrim(substr($result, 2), '0');
    if ($trimmed === '') return 0;
    if (strlen($trimmed) > 8) throw new RuntimeException('Agent Entry contract returned an out-of-range integer.');
    return (int) hexdec($trimmed);
}

/** @return array<string, mixed> */
function nfh_agent_entry_live_claim_status(): array
{
    $config = nfh_agent_entry_claim_config();
    static $cache = [];
    $cacheKey = hash('sha256', json_encode($config, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    if (isset($cache[$cacheKey])) return $cache[$cacheKey];
    $status = ['configured' => $config['enabled'], 'ready' => false, 'minter' => $config['minter'], 'credentialSigner' => $config['credentialSigner']];
    if (!$config['enabled']) return $cache[$cacheKey] = $status;
    try {
        $minter = (string) $config['minter'];
        $collection = nfh_agent_entry_rpc_address($minter, '0xfc0c546a');
        $signer = nfh_agent_entry_rpc_address($minter, '0xc2633e8b');
        $paused = nfh_agent_entry_rpc_bool($minter, '0x5c975abb');
        $successfulMints = nfh_agent_entry_rpc_uint($minter, '0x24bc439a');
        if ($successfulMints < 0 || $successfulMints > NFH_AGENT_ENTRY_CAPACITY) {
            throw new RuntimeException('Agent Entry contract returned an invalid successful mint count.');
        }
        $role = nfh_verify_rpc('eth_call', [['to' => NFH_AGENT_ENTRY_COLLECTION, 'data' => '0xd5391393'], 'latest'], nfh_verify_config());
        if (preg_match('/^0x[0-9a-f]{64}$/', $role) !== 1) throw new RuntimeException('Collection role read failed.');
        $hasRoleData = '0x91d14854' . substr($role, 2) . str_repeat('0', 24) . substr($minter, 2);
        $hasRole = nfh_agent_entry_rpc_bool(strtolower(NFH_AGENT_ENTRY_COLLECTION), $hasRoleData);
        $status['collectionVerified'] = hash_equals(strtolower(NFH_AGENT_ENTRY_COLLECTION), $collection);
        $status['signerVerified'] = hash_equals((string) $config['credentialSigner'], $signer);
        $status['paused'] = $paused;
        $status['successfulMints'] = $successfulMints;
        $status['collectionRoleVerified'] = $hasRole;
        $status['ready'] = $status['collectionVerified'] && $status['signerVerified'] && !$paused && $hasRole;
    } catch (Throwable $error) {
        $status['error'] = 'Live Agent Entry contract verification unavailable.';
    }
    return $cache[$cacheKey] = $status;
}

function nfh_agent_entry_default_directory(?string $sapi = null): string
{
    return nfh_is_local_cli_runtime($sapi)
        ? nfh_runtime_directory() . '/agent-entry'
        : '/home/notforhumans/.nfh-agent-entry';
}

function nfh_agent_entry_directory(): string
{
    $configured = trim((string) (getenv('NFH_AGENT_ENTRY_DIR') ?: ''));
    $directory = $configured !== '' ? $configured : nfh_agent_entry_default_directory();
    if (!str_starts_with($directory, DIRECTORY_SEPARATOR) || is_link($directory)) {
        throw new RuntimeException('Agent Entry storage path is unsafe.');
    }
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Agent Entry storage is unavailable.');
    }
    clearstatcache(true, $directory);
    if (is_link($directory) || (((int) fileperms($directory)) & 0077) !== 0) {
        throw new RuntimeException('Agent Entry storage permissions are unsafe.');
    }
    return $directory;
}

function nfh_agent_entry_log_path(): string
{
    return nfh_agent_entry_directory() . '/events.jsonl';
}

function nfh_agent_entry_default_backup_directory(?string $sapi = null): string
{
    return nfh_is_local_cli_runtime($sapi)
        ? nfh_runtime_directory() . '/agent-entry-backups'
        : '/home/notforhumans/.nfh-agent-entry-backups';
}

function nfh_agent_entry_backup_directory(): string
{
    $configured = trim((string) (getenv('NFH_AGENT_ENTRY_BACKUP_DIR') ?: ''));
    $directory = $configured !== '' ? $configured : nfh_agent_entry_default_backup_directory();
    if (!str_starts_with($directory, DIRECTORY_SEPARATOR) || is_link($directory)) {
        throw new RuntimeException('Agent Entry backup path is unsafe.');
    }
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Agent Entry backup storage is unavailable.');
    }
    clearstatcache(true, $directory);
    if (is_link($directory) || (((int) fileperms($directory)) & 0077) !== 0) {
        throw new RuntimeException('Agent Entry backup permissions are unsafe.');
    }
    return $directory;
}

function nfh_agent_entry_wallet(mixed $value): string
{
    if (!is_string($value) || preg_match('/^0x[a-fA-F0-9]{40}$/', $value) !== 1
        || strtolower($value) === '0x0000000000000000000000000000000000000000') {
        throw new InvalidArgumentException('wallet must be a nonzero 20-byte Ethereum address.');
    }
    return strtolower($value);
}

function nfh_agent_entry_id(mixed $value): string
{
    if (!is_string($value) || preg_match('/^[a-f0-9]{64}$/', $value) !== 1) {
        throw new InvalidArgumentException('reservationId must be a 32-byte lowercase hex string.');
    }
    return $value;
}

function nfh_agent_entry_read_raw($handle): string
{
    rewind($handle);
    $raw = stream_get_contents($handle, NFH_AGENT_ENTRY_MAX_LOG_BYTES + 1);
    if (!is_string($raw) || strlen($raw) > NFH_AGENT_ENTRY_MAX_LOG_BYTES) {
        throw new RuntimeException('Agent Entry storage exceeded its safe read limit.');
    }
    return $raw;
}

/** @return list<array<string, mixed>> */
function nfh_agent_entry_decode_events(string $raw): array
{
    if ($raw === '') return [];
    if (!str_ends_with($raw, "\n")) {
        throw new RuntimeException('Agent Entry storage contains an incomplete event.');
    }
    $events = [];
    foreach (preg_split('/\r?\n/', rtrim($raw, "\r\n")) ?: [] as $line) {
        if ($line === '') continue;
        try { $event = json_decode($line, true, flags: JSON_THROW_ON_ERROR); }
        catch (JsonException $error) { throw new RuntimeException('Agent Entry storage contains an invalid event.', 0, $error); }
        if (!is_array($event) || array_is_list($event)) {
            throw new RuntimeException('Agent Entry storage contains an invalid event.');
        }
        if (($event['type'] ?? null) === 'expired' && nfh_agent_entry_expand_compacted_event($event) === null) {
            throw new RuntimeException('Agent Entry storage contains an invalid compacted event.');
        }
        $events[] = $event;
    }
    return $events;
}

/** @param list<array<string, mixed>> $events */
function nfh_agent_entry_encode_events(array $events): string
{
    $raw = '';
    foreach ($events as $event) {
        $raw .= json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    }
    return $raw;
}

/** @return list<array<string, mixed>> */
function nfh_agent_entry_read_events($handle): array
{
    return nfh_agent_entry_decode_events(nfh_agent_entry_read_raw($handle));
}

function nfh_agent_entry_write_raw($handle, string $raw): void
{
    if (strlen($raw) > NFH_AGENT_ENTRY_MAX_LOG_BYTES || !ftruncate($handle, 0) || fseek($handle, 0) !== 0) {
        throw new RuntimeException('Agent Entry storage write failed.');
    }
    $offset = 0;
    while ($offset < strlen($raw)) {
        $written = fwrite($handle, substr($raw, $offset));
        if ($written === false || $written === 0) throw new RuntimeException('Agent Entry storage write failed.');
        $offset += $written;
    }
    if (!fflush($handle) || (function_exists('fsync') && !fsync($handle))) {
        throw new RuntimeException('Agent Entry storage write failed.');
    }
}

/** @param array<string, mixed> $event @return array<string, mixed>|null */
function nfh_agent_entry_expand_compacted_event(array $event): ?array
{
    if (($event['type'] ?? null) !== 'expired' || ($event['schema'] ?? null) !== NFH_AGENT_ENTRY_COMPACTED_SCHEMA) return null;
    $reservationId = $event['r'] ?? null;
    $seat = $event['s'] ?? null;
    $wallet = $event['w'] ?? null;
    $reservedAt = $event['a'] ?? null;
    $expiresAt = $event['e'] ?? null;
    $candidateSeedHash = $event['c'] ?? null;
    $historyHash = $event['h'] ?? null;
    if (!is_string($reservationId) || preg_match('/^[a-f0-9]{64}$/', $reservationId) !== 1
        || !is_int($seat) || $seat < 1 || $seat > NFH_AGENT_ENTRY_CAPACITY
        || !is_string($wallet) || preg_match('/^0x[a-f0-9]{40}$/', $wallet) !== 1
        || $wallet === '0x0000000000000000000000000000000000000000'
        || !is_int($reservedAt) || !is_int($expiresAt) || $expiresAt <= $reservedAt
        || !is_string($candidateSeedHash) || preg_match('/^0x[a-f0-9]{64}$/', $candidateSeedHash) !== 1
        || !is_string($historyHash) || preg_match('/^[a-f0-9]{64}$/', $historyHash) !== 1) {
        return null;
    }
    $record = [
        'type' => 'reservation',
        'schema' => NFH_AGENT_ENTRY_SCHEMA,
        'reservationId' => $reservationId,
        'seat' => $seat,
        'wallet' => $wallet,
        'reservedAt' => gmdate('c', $reservedAt),
        'expiresAt' => gmdate('c', $expiresAt),
        'candidateSeedHash' => $candidateSeedHash,
        'claimed' => false,
        'compacted' => true,
        'compactedHistoryHash' => $historyHash,
    ];
    $activityEvidenceHash = $event['x'] ?? null;
    $activitySubmittedAt = $event['t'] ?? null;
    if ($activityEvidenceHash !== null || $activitySubmittedAt !== null) {
        if (!is_string($activityEvidenceHash) || preg_match('/^0x[a-f0-9]{64}$/', $activityEvidenceHash) !== 1
            || !is_int($activitySubmittedAt)) return null;
        $record['activityEvidenceHash'] = $activityEvidenceHash;
        $record['activitySubmittedAt'] = gmdate('c', $activitySubmittedAt);
    }
    return $record;
}

/** @param list<array<string, mixed>> $events @return list<array<string, mixed>> */
function nfh_agent_entry_compact_events(array $events, int $now): array
{
    $records = nfh_agent_entry_records($events);
    $eligible = [];
    $reservationCounts = [];
    $activityCounts = [];
    $mintCounts = [];
    $history = [];
    foreach ($events as $event) {
        $reservationId = $event['reservationId'] ?? null;
        if (!is_string($reservationId)) continue;
        $type = $event['type'] ?? null;
        if ($type === 'reservation') {
            $reservationCounts[$reservationId] = ($reservationCounts[$reservationId] ?? 0) + 1;
            $history[$reservationId] = ($history[$reservationId] ?? '')
                . json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        } elseif ($type === 'activity') {
            $activityCounts[$reservationId] = ($activityCounts[$reservationId] ?? 0) + 1;
            $history[$reservationId] = ($history[$reservationId] ?? '')
                . json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        } elseif ($type === 'mint') {
            $mintCounts[$reservationId] = ($mintCounts[$reservationId] ?? 0) + 1;
        }
    }
    foreach ($records as $reservationId => $record) {
        if (($record['compacted'] ?? false) === true || ($record['claimed'] ?? false) === true
            || ($reservationCounts[$reservationId] ?? 0) !== 1
            || ($activityCounts[$reservationId] ?? 0) > 1
            || ($mintCounts[$reservationId] ?? 0) !== 0) continue;
        $reservedAt = strtotime((string) ($record['reservedAt'] ?? ''));
        $expiresAt = strtotime((string) ($record['expiresAt'] ?? ''));
        if ($reservedAt === false || $expiresAt === false || $expiresAt > $now) continue;
        $wallet = $record['wallet'] ?? null;
        $seat = $record['seat'] ?? null;
        $candidateSeedHash = strtolower((string) ($record['candidateSeedHash'] ?? ''));
        if (!is_string($wallet) || preg_match('/^0x[a-f0-9]{40}$/', $wallet) !== 1
            || $wallet === '0x0000000000000000000000000000000000000000'
            || !is_int($seat) || $seat < 1 || $seat > NFH_AGENT_ENTRY_CAPACITY
            || preg_match('/^0x[a-f0-9]{64}$/', $candidateSeedHash) !== 1) continue;
        $summary = [
            'type' => 'expired',
            'schema' => NFH_AGENT_ENTRY_COMPACTED_SCHEMA,
            'r' => $reservationId,
            's' => $seat,
            'w' => $wallet,
            'a' => $reservedAt,
            'e' => $expiresAt,
            'c' => $candidateSeedHash,
        ];
        if (isset($record['activityEvidenceHash']) || isset($record['activitySubmittedAt'])) {
            $evidenceHash = strtolower((string) ($record['activityEvidenceHash'] ?? ''));
            $submittedAt = strtotime((string) ($record['activitySubmittedAt'] ?? ''));
            if (preg_match('/^0x[a-f0-9]{64}$/', $evidenceHash) !== 1 || $submittedAt === false) continue;
            $summary['x'] = $evidenceHash;
            $summary['t'] = $submittedAt;
        }
        $summary['h'] = hash('sha256', (string) ($history[$reservationId] ?? ''));
        $eligible[$reservationId] = $summary;
    }

    $compacted = [];
    $emitted = [];
    foreach ($events as $event) {
        $reservationId = $event['reservationId'] ?? null;
        if (is_string($reservationId) && isset($eligible[$reservationId])
            && in_array($event['type'] ?? null, ['reservation', 'activity'], true)) {
            if (!isset($emitted[$reservationId])) {
                $compacted[] = $eligible[$reservationId];
                $emitted[$reservationId] = true;
            }
            continue;
        }
        $compacted[] = $event;
    }
    return $compacted;
}

/** @param array<string, mixed> $event */
function nfh_agent_entry_append($handle, array $event, ?int $now = null): void
{
    $now ??= time();
    $encoded = json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    $originalRaw = nfh_agent_entry_read_raw($handle);
    $baseRaw = $originalRaw;
    $mutated = false;
    try {
        if (strlen($originalRaw) + strlen($encoded) > NFH_AGENT_ENTRY_COMPACTION_TRIGGER_BYTES) {
            $compactedRaw = nfh_agent_entry_encode_events(nfh_agent_entry_compact_events(nfh_agent_entry_decode_events($originalRaw), $now));
            if (strlen($compactedRaw) < strlen($originalRaw)) {
                nfh_agent_entry_snapshot_locked($handle);
                $mutated = true;
                nfh_agent_entry_write_raw($handle, $compactedRaw);
                $baseRaw = $compactedRaw;
            }
        }
        if (strlen($baseRaw) + strlen($encoded) > NFH_AGENT_ENTRY_MAX_LOG_BYTES) {
            throw new RuntimeException('Agent Entry storage is full.');
        }
        fseek($handle, 0, SEEK_END);
        $mutated = true;
        $offset = 0;
        while ($offset < strlen($encoded)) {
            $written = fwrite($handle, substr($encoded, $offset));
            if ($written === false || $written === 0) throw new RuntimeException('Agent Entry storage write failed.');
            $offset += $written;
        }
        if (!fflush($handle) || (function_exists('fsync') && !fsync($handle))) {
            throw new RuntimeException('Agent Entry storage write failed.');
        }
        nfh_agent_entry_snapshot_locked($handle);
    } catch (Throwable $error) {
        if ($mutated) {
            try { nfh_agent_entry_write_raw($handle, $originalRaw); }
            catch (Throwable $rollbackError) {
                throw new RuntimeException('Agent Entry storage and rollback both failed.', 0, $rollbackError);
            }
        }
        throw $error;
    }
}

function nfh_agent_entry_snapshot_locked($handle): string
{
    rewind($handle);
    $raw = stream_get_contents($handle, NFH_AGENT_ENTRY_MAX_LOG_BYTES + 1);
    fseek($handle, 0, SEEK_END);
    if (!is_string($raw) || strlen($raw) > NFH_AGENT_ENTRY_MAX_LOG_BYTES) {
        throw new RuntimeException('Agent Entry backup exceeded its safe size limit.');
    }
    $directory = nfh_agent_entry_backup_directory();
    $hash = hash('sha256', $raw);
    $createdAt = microtime(true);
    $microseconds = (int) (($createdAt - floor($createdAt)) * 1_000_000);
    $name = 'snapshot-' . gmdate('Ymd\\THis', (int) $createdAt) . '-' . sprintf('%06d', $microseconds)
        . '-' . bin2hex(random_bytes(4)) . '-' . $hash . '.jsonl';
    $path = $directory . '/' . $name;
    $temporary = $directory . '/.' . $name . '.tmp';
    $backup = fopen($temporary, 'x+b');
    if ($backup === false) throw new RuntimeException('Agent Entry backup snapshot could not be created.');
    try {
        chmod($temporary, 0600);
        $offset = 0;
        while ($offset < strlen($raw)) {
            $written = fwrite($backup, substr($raw, $offset));
            if ($written === false || $written === 0) throw new RuntimeException('Agent Entry backup snapshot write failed.');
            $offset += $written;
        }
        if (!fflush($backup) || (function_exists('fsync') && !fsync($backup))) {
            throw new RuntimeException('Agent Entry backup snapshot flush failed.');
        }
    } finally {
        fclose($backup);
    }
    if (!rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('Agent Entry backup snapshot could not be committed.');
    }
    $snapshots = glob($directory . '/snapshot-*.jsonl') ?: [];
    sort($snapshots, SORT_STRING);
    while (count($snapshots) > 2) {
        $oldest = array_shift($snapshots);
        if (is_string($oldest) && is_file($oldest) && !is_link($oldest)) @unlink($oldest);
    }
    return $path;
}

function nfh_agent_entry_restore_latest_backup(): string
{
    if (nfh_agent_entry_enabled()) {
        throw new RuntimeException('Disable Agent Entry reservations before restoring storage.');
    }
    $directory = nfh_agent_entry_backup_directory();
    $snapshots = glob($directory . '/snapshot-*.jsonl') ?: [];
    rsort($snapshots, SORT_STRING);
    $raw = null;
    $selected = null;
    foreach ($snapshots as $snapshot) {
        if (is_link($snapshot) || !is_file($snapshot)) continue;
        $name = basename($snapshot);
        if (preg_match('/^snapshot-\d{8}T\d{6}-\d{6}-[a-f0-9]{8}-([a-f0-9]{64})\.jsonl$/', $name, $matches) !== 1) continue;
        $candidate = file_get_contents($snapshot, false, null, 0, NFH_AGENT_ENTRY_MAX_LOG_BYTES + 1);
        if (!is_string($candidate) || strlen($candidate) > NFH_AGENT_ENTRY_MAX_LOG_BYTES
            || !hash_equals($matches[1], hash('sha256', $candidate))
            || ($candidate !== '' && !str_ends_with($candidate, "\n"))) continue;
        try { nfh_agent_entry_decode_events($candidate); }
        catch (RuntimeException) { continue; }
        $raw = $candidate;
        $selected = $snapshot;
        break;
    }
    if (!is_string($raw) || !is_string($selected)) {
        throw new RuntimeException('No valid Agent Entry recovery snapshot is available.');
    }

    $path = nfh_agent_entry_log_path();
    if (is_link($path)) throw new RuntimeException('Agent Entry storage file is unsafe.');
    $handle = fopen($path, 'c+b');
    if ($handle === false) throw new RuntimeException('Agent Entry storage is unavailable.');
    try {
        if (!flock($handle, LOCK_EX)) throw new RuntimeException('Agent Entry storage lock failed.');
        if (!ftruncate($handle, 0) || fseek($handle, 0) !== 0) throw new RuntimeException('Agent Entry restore could not reset storage.');
        $offset = 0;
        while ($offset < strlen($raw)) {
            $written = fwrite($handle, substr($raw, $offset));
            if ($written === false || $written === 0) throw new RuntimeException('Agent Entry restore write failed.');
            $offset += $written;
        }
        if (!fflush($handle) || (function_exists('fsync') && !fsync($handle))) {
            throw new RuntimeException('Agent Entry restore flush failed.');
        }
        chmod($path, 0600);
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
    return $selected;
}

/** @return list<array<string, mixed>> */
function nfh_agent_entry_events(): array
{
    $path = nfh_agent_entry_log_path();
    if (!is_file($path)) return [];
    if (is_link($path)) throw new RuntimeException('Agent Entry storage file is unsafe.');
    $handle = fopen($path, 'rb');
    if ($handle === false) throw new RuntimeException('Agent Entry storage is unavailable.');
    try {
        if (!flock($handle, LOCK_SH)) throw new RuntimeException('Agent Entry storage lock failed.');
        return nfh_agent_entry_read_events($handle);
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

/** @param array<string, mixed> $payload @return array<string, mixed> */
function nfh_agent_entry_validate(array $payload, ?int $now = null): array
{
    $now ??= time();
    $allowed = ['version', 'action', 'chainId', 'collection', 'wallet', 'issuedAt', 'expiresAt', 'nonce'];
    if (array_diff(array_keys($payload), $allowed) !== []) {
        throw new InvalidArgumentException('Agent Entry payload contains unsupported fields.');
    }
    if (($payload['version'] ?? null) !== NFH_AGENT_ENTRY_VERSION
        || ($payload['action'] ?? null) !== NFH_AGENT_ENTRY_ACTION
        || ($payload['chainId'] ?? null) !== 1
        || strtolower((string) ($payload['collection'] ?? '')) !== strtolower(NFH_AGENT_ENTRY_COLLECTION)) {
        throw new InvalidArgumentException('Agent Entry payload does not match the canonical Ethereum entry domain.');
    }
    $issuedAt = $payload['issuedAt'] ?? null;
    $expiresAt = $payload['expiresAt'] ?? null;
    $nonce = $payload['nonce'] ?? null;
    if (!is_int($issuedAt) || !is_int($expiresAt) || $issuedAt > $now + 60 || $issuedAt < $now - NFH_AGENT_ENTRY_PREPARATION_LIFETIME
        || $expiresAt <= $now || $expiresAt - $issuedAt !== NFH_AGENT_ENTRY_PREPARATION_LIFETIME) {
        throw new InvalidArgumentException('Agent Entry preparation is expired or has an invalid lifetime.');
    }
    if (!is_string($nonce) || preg_match('/^[a-f0-9]{64}$/', $nonce) !== 1) {
        throw new InvalidArgumentException('nonce must be a 32-byte lowercase hex string.');
    }
    return [
        'version' => NFH_AGENT_ENTRY_VERSION,
        'action' => NFH_AGENT_ENTRY_ACTION,
        'chainId' => 1,
        'collection' => strtolower(NFH_AGENT_ENTRY_COLLECTION),
        'wallet' => nfh_agent_entry_wallet($payload['wallet'] ?? null),
        'issuedAt' => $issuedAt,
        'expiresAt' => $expiresAt,
        'nonce' => $nonce,
    ];
}

/** @param array<string, mixed> $payload */
function nfh_agent_entry_message(array $payload): string
{
    return "NOT FOR HUMANS Agent Entry\n"
        . "Version: {$payload['version']}\nDomain: notforhumans.fun\nAction: {$payload['action']}\n"
        . "Chain ID: {$payload['chainId']}\nCollection: {$payload['collection']}\nAgent Wallet: {$payload['wallet']}\n"
        . 'Issued At: ' . gmdate('c', $payload['issuedAt']) . "\nPreparation Expiration: " . gmdate('c', $payload['expiresAt']) . "\n"
        . "Nonce: {$payload['nonce']}\n"
        . 'Statement: I control this empty agent wallet and request one off-chain, unminted NFH reservation lasting 24 hours. This signature is not a transaction, mint, approval, transfer, payment, claim credential, or proof that I am a unique human or autonomous agent.';
}

function nfh_agent_entry_wallet_is_empty(string $wallet): bool
{
    return !nfh_verify_holds($wallet, nfh_verify_config());
}

/** @param list<array<string, mixed>> $events */
function nfh_agent_entry_wallet_seen(array $events, string $wallet): bool
{
    foreach ($events as $event) {
        if (($event['type'] ?? null) === 'reservation' && hash_equals((string) ($event['wallet'] ?? ''), $wallet)) return true;
        $compacted = nfh_agent_entry_expand_compacted_event($event);
        if (is_array($compacted) && hash_equals((string) $compacted['wallet'], $wallet)) return true;
    }
    return false;
}

/** @param list<array<string, mixed>> $events @return array<string, array<string, mixed>> */
function nfh_agent_entry_records(array $events): array
{
    $records = [];
    foreach ($events as $event) {
        $type = $event['type'] ?? null;
        $compacted = nfh_agent_entry_expand_compacted_event($event);
        if (is_array($compacted)) {
            $records[(string) $compacted['reservationId']] = $compacted;
            continue;
        }
        $reservationId = $event['reservationId'] ?? null;
        if (!is_string($reservationId) || preg_match('/^[a-f0-9]{64}$/', $reservationId) !== 1) continue;
        if ($type === 'reservation') {
            $records[$reservationId] = $event;
            continue;
        }
        if (!isset($records[$reservationId])) continue;
        if ($type === 'activity') {
            $records[$reservationId]['activityEvidenceHash'] = $event['evidenceHash'] ?? null;
            $records[$reservationId]['activitySubmittedAt'] = $event['submittedAt'] ?? null;
        } elseif ($type === 'mint') {
            $records[$reservationId]['claimed'] = true;
            $records[$reservationId]['tokenId'] = $event['tokenId'] ?? null;
            $records[$reservationId]['mintTransactionHash'] = $event['transactionHash'] ?? null;
        }
    }
    return $records;
}

/** @param list<array<string, mixed>> $events @return array<string, int|bool> */
function nfh_agent_entry_admission_state(array $events, int $now): array
{
    $records = nfh_agent_entry_records($events);
    $cutoff = $now - NFH_AGENT_ENTRY_ADMISSION_WINDOW;
    $acceptedInWindow = 0;
    foreach ($records as $record) {
        $reservedAt = strtotime((string) ($record['reservedAt'] ?? ''));
        if ($reservedAt !== false && $reservedAt > $cutoff) $acceptedInWindow++;
    }
    $lifetimeAccepted = count($records);
    $remainingInWindow = max(0, NFH_AGENT_ENTRY_MAX_ADMISSIONS_PER_WINDOW - $acceptedInWindow);
    $lifetimeRemaining = max(0, NFH_AGENT_ENTRY_MAX_LIFETIME_ADMISSIONS - $lifetimeAccepted);
    return [
        'windowSeconds' => NFH_AGENT_ENTRY_ADMISSION_WINDOW,
        'windowMaximum' => NFH_AGENT_ENTRY_MAX_ADMISSIONS_PER_WINDOW,
        'acceptedInWindow' => $acceptedInWindow,
        'remainingInWindow' => $remainingInWindow,
        'lifetimeMaximum' => NFH_AGENT_ENTRY_MAX_LIFETIME_ADMISSIONS,
        'lifetimeAccepted' => $lifetimeAccepted,
        'lifetimeRemaining' => $lifetimeRemaining,
        'accepting' => $remainingInWindow > 0 && $lifetimeRemaining > 0,
    ];
}

/** @param list<array<string, mixed>> $events */
function nfh_agent_entry_require_admission_capacity(array $events, int $now): void
{
    $admission = nfh_agent_entry_admission_state($events, $now);
    if ($admission['lifetimeRemaining'] === 0) {
        throw new RuntimeException('Agent Entry durable lifetime admission capacity is full.');
    }
    if ($admission['remainingInWindow'] === 0) {
        throw new RuntimeException('Agent Entry global reservation admission window is full; retry after earlier reservations leave the rolling 24-hour window.');
    }
}

/** @param list<array<string, mixed>> $events @return array<string, int|string> */
function nfh_agent_entry_storage_state(array $events): array
{
    $compacted = 0;
    $bytes = 0;
    foreach ($events as $event) {
        if (nfh_agent_entry_expand_compacted_event($event) !== null) $compacted++;
        $bytes += strlen(json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) + 1;
    }
    return [
        'format' => 'compacting-jsonl-v2',
        'bytes' => $bytes,
        'maximumBytes' => NFH_AGENT_ENTRY_MAX_LOG_BYTES,
        'headroomBytes' => max(0, NFH_AGENT_ENTRY_MAX_LOG_BYTES - $bytes),
        'compactionTriggerBytes' => NFH_AGENT_ENTRY_COMPACTION_TRIGGER_BYTES,
        'compactedExpiredReservations' => $compacted,
        'lifetimeWallets' => count(nfh_agent_entry_records($events)),
    ];
}

/** @param list<array<string, mixed>> $events */
function nfh_agent_entry_available_seat(array $events, int $now): ?int
{
    $occupied = [];
    $claimStatus = nfh_agent_entry_live_claim_status();
    foreach (nfh_agent_entry_records($events) as $record) {
        if (!is_int($record['seat'] ?? null)) continue;
        $expiresAt = strtotime((string) ($record['expiresAt'] ?? ''));
        if (($record['claimed'] ?? false) === true || ($expiresAt !== false && $expiresAt > $now)) {
            $occupied[(int) $record['seat']] = true;
        }
    }
    for ($seat = 1; $seat <= NFH_AGENT_ENTRY_CAPACITY; $seat++) {
        if (isset($occupied[$seat])) continue;
        if (($claimStatus['ready'] ?? false) === true) {
            $word = str_pad(dechex($seat), 64, '0', STR_PAD_LEFT);
            if (nfh_agent_entry_rpc_bool((string) $claimStatus['minter'], '0x4ebf3c5f' . $word)) continue;
        }
        return $seat;
    }
    return null;
}

/** @param array<string, mixed> $record @return array<string, mixed> */
function nfh_agent_entry_public_record(array $record, ?int $now = null): array
{
    $now ??= time();
    $expiresAt = strtotime((string) ($record['expiresAt'] ?? ''));
    $claimed = ($record['claimed'] ?? false) === true;
    $active = !$claimed && $expiresAt !== false && $expiresAt > $now;
    $activitySubmitted = is_string($record['activityEvidenceHash'] ?? null);
    $claimStatus = $active && $activitySubmitted ? nfh_agent_entry_live_claim_status() : ['ready' => false];
    $claimReady = $active && $activitySubmitted && ($claimStatus['ready'] ?? false) === true
        && strtotime((string) ($record['reservedAt'] ?? '')) <= $now - NFH_AGENT_ENTRY_MIN_ACTIVITY_AGE;
    $reservationId = (string) $record['reservationId'];
    $tokenId = is_int($record['tokenId'] ?? null) ? $record['tokenId'] : null;
    return [
        'schema' => NFH_AGENT_ENTRY_SCHEMA,
        'reservationId' => $reservationId,
        'seat' => (int) $record['seat'],
        'wallet' => (string) $record['wallet'],
        'state' => $claimed ? 'MINTED' : ($active ? ($activitySubmitted ? ($claimReady ? 'CLAIM_REVIEW_READY' : 'ACTIVITY_RECORDED_UNMINTED') : 'RESERVED_UNMINTED') : 'EXPIRED_UNMINTED'),
        'reservedAt' => (string) $record['reservedAt'],
        'expiresAt' => (string) $record['expiresAt'],
        'secondsRemaining' => $active ? max(0, $expiresAt - $now) : 0,
        'candidateSeedHash' => (string) $record['candidateSeedHash'],
        'candidatePageUrl' => 'https://notforhumans.fun/entry/?id=' . $reservationId,
        'claimed' => $claimed,
        'tokenId' => $tokenId,
        'claimedPageUrl' => $claimed && $tokenId !== null ? 'https://notforhumans.fun/claimed/' . $tokenId . '?wake=1' : null,
        'activityEvidenceHash' => $activitySubmitted ? (string) $record['activityEvidenceHash'] : null,
        'activitySubmittedAt' => is_string($record['activitySubmittedAt'] ?? null) ? $record['activitySubmittedAt'] : null,
        'claimAuthority' => false,
        'claimPreparationEnabled' => $claimReady,
        'meaning' => $claimed
            ? 'A separate chain receipt has verified canonical NFT ownership.'
            : ($claimReady
                ? 'Activity evidence exists and the independently configured contract gate is live. An issuer credential is still required; this record alone cannot mint.'
                : 'This is an off-chain reservation and candidate portrait only. It is not an NFT, claim credential, token ID, ownership record, capability proof, or guaranteed mint.'),
    ];
}

/** @return array<string, mixed> */
function nfh_agent_entry_prepare(array $input, ?int $now = null): array
{
    $now ??= time();
    nfh_agent_entry_require_enabled();
    $wallet = nfh_agent_entry_wallet($input['wallet'] ?? null);
    if (!nfh_agent_entry_wallet_is_empty($wallet)) {
        throw new RuntimeException('This wallet already owns an NFH and cannot enter the empty-wallet lane.');
    }
    $events = nfh_agent_entry_events();
    if (nfh_agent_entry_wallet_seen($events, $wallet)) {
        throw new RuntimeException('This wallet has already used its one Agent Entry reservation attempt.');
    }
    nfh_agent_entry_require_admission_capacity($events, $now);
    if (nfh_agent_entry_available_seat($events, $now) === null) {
        throw new RuntimeException('All 1,000 Agent Entry seats are currently reserved or minted.');
    }
    $payload = [
        'version' => NFH_AGENT_ENTRY_VERSION,
        'action' => NFH_AGENT_ENTRY_ACTION,
        'chainId' => 1,
        'collection' => strtolower(NFH_AGENT_ENTRY_COLLECTION),
        'wallet' => $wallet,
        'issuedAt' => $now,
        'expiresAt' => $now + NFH_AGENT_ENTRY_PREPARATION_LIFETIME,
        'nonce' => bin2hex(random_bytes(32)),
    ];
    return [
        'schema' => NFH_AGENT_ENTRY_SCHEMA,
        'status' => 'prepared_unsigned',
        'payload' => $payload,
        'message' => nfh_agent_entry_message($payload),
        'signatureMethod' => 'personal_sign',
        'requiredSigner' => $wallet,
        'reservationLifetimeSeconds' => NFH_AGENT_ENTRY_LIFETIME,
        'admission' => nfh_agent_entry_admission_state($events, $now),
        'warnings' => [
            'Signing opens only an off-chain reservation after publication; it does not mint or authorize a transaction.',
            'The wallet is rechecked against Ethereum when the reservation is published and again by the Agent Entry minter before any claim.',
            'One wallet receives one lifetime reservation attempt. Expired seats return to the queue.',
            'A public MCP connection or wallet signature does not prove a unique agent; rate limits are abuse controls, not identity proof.',
            'Claim preparation is live-gated, requires an independently issued credential after activity review, and returns only an unsigned transaction for this exact wallet to review and submit.',
        ],
    ];
}

/** @return array<string, mixed> */
function nfh_agent_entry_activate(array $input, ?int $now = null): array
{
    $now ??= time();
    nfh_agent_entry_require_enabled();
    $payload = $input['payload'] ?? null;
    $signature = $input['signature'] ?? null;
    if (!is_array($payload) || !is_string($signature)) {
        throw new InvalidArgumentException('payload and signature are required.');
    }
    $payload = nfh_agent_entry_validate($payload, $now);
    $message = nfh_agent_entry_message($payload);
    $signer = strtolower(nfh_verify_recover($message, $signature, nfh_verify_config()));
    if (!hash_equals($payload['wallet'], $signer)) throw new RuntimeException('The signature does not match the declared agent wallet.');
    if (!nfh_rate_limit('agent-entry-wallet', $signer, 2, 24 * 60 * 60, $now)) {
        throw new RuntimeException('This wallet has made too many Agent Entry attempts.');
    }
    if (!nfh_agent_entry_wallet_is_empty($signer)) {
        throw new RuntimeException('This wallet now owns an NFH and cannot enter the empty-wallet lane.');
    }

    $path = nfh_agent_entry_log_path();
    if (is_link($path)) throw new RuntimeException('Agent Entry storage file is unsafe.');
    $handle = fopen($path, 'c+b');
    if ($handle === false) throw new RuntimeException('Agent Entry storage is unavailable.');
    try {
        if (!flock($handle, LOCK_EX)) throw new RuntimeException('Agent Entry storage lock failed.');
        chmod($path, 0600);
        $events = nfh_agent_entry_read_events($handle);
        if (nfh_agent_entry_wallet_seen($events, $signer)) {
            throw new RuntimeException('This wallet has already used its one Agent Entry reservation attempt.');
        }
        nfh_agent_entry_require_admission_capacity($events, $now);
        $seat = nfh_agent_entry_available_seat($events, $now);
        if ($seat === null) throw new RuntimeException('All 1,000 Agent Entry seats are currently reserved or minted.');
        $reservationId = hash('sha256', 'nfh-agent-entry|' . $signer . '|' . $payload['nonce']);
        $record = [
            'type' => 'reservation',
            'schema' => NFH_AGENT_ENTRY_SCHEMA,
            'reservationId' => $reservationId,
            'seat' => $seat,
            'wallet' => $signer,
            'reservedAt' => gmdate('c', $now),
            'expiresAt' => gmdate('c', $now + NFH_AGENT_ENTRY_LIFETIME),
            'candidateSeedHash' => '0x' . hash('sha256', 'nfh-candidate-portrait|' . $reservationId),
            'messageHash' => hash('sha256', $message),
            'signatureHash' => hash('sha256', strtolower($signature)),
            'walletEmptyVerifiedAt' => gmdate('c', $now),
            'claimed' => false,
        ];
        nfh_agent_entry_append($handle, $record, $now);
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
    return [
        'ok' => true,
        'entry' => nfh_agent_entry_public_record($record, $now),
        'next' => 'Open the candidate page and begin with public reads, Arcade play, or returned work. After the minimum activity age, an independent issuer must review the evidence and provide the exact credential before this wallet can prepare a claim.',
    ];
}

/** @param array<string, mixed> $payload @return array<string, mixed> */
function nfh_agent_entry_validate_activity(array $payload, ?int $now = null): array
{
    $now ??= time();
    $allowed = ['version', 'action', 'reservationId', 'wallet', 'evidenceHash', 'issuedAt', 'expiresAt', 'nonce'];
    if (array_diff(array_keys($payload), $allowed) !== []) throw new InvalidArgumentException('Activity payload contains unsupported fields.');
    if (($payload['version'] ?? null) !== NFH_AGENT_ENTRY_VERSION || ($payload['action'] ?? null) !== NFH_AGENT_ENTRY_EVIDENCE_ACTION) {
        throw new InvalidArgumentException('Activity payload does not match the Agent Entry domain.');
    }
    $issuedAt = $payload['issuedAt'] ?? null;
    $expiresAt = $payload['expiresAt'] ?? null;
    if (!is_int($issuedAt) || !is_int($expiresAt) || $issuedAt > $now + 60 || $issuedAt < $now - NFH_AGENT_ENTRY_PREPARATION_LIFETIME
        || $expiresAt <= $now || $expiresAt - $issuedAt !== NFH_AGENT_ENTRY_PREPARATION_LIFETIME) {
        throw new InvalidArgumentException('Activity preparation is expired or has an invalid lifetime.');
    }
    $evidenceHash = strtolower((string) ($payload['evidenceHash'] ?? ''));
    $nonce = strtolower((string) ($payload['nonce'] ?? ''));
    if (preg_match('/^0x[a-f0-9]{64}$/', $evidenceHash) !== 1) throw new InvalidArgumentException('evidenceHash must be a 32-byte hex value.');
    if (preg_match('/^[a-f0-9]{64}$/', $nonce) !== 1) throw new InvalidArgumentException('nonce must be a 32-byte lowercase hex string.');
    return [
        'version' => NFH_AGENT_ENTRY_VERSION,
        'action' => NFH_AGENT_ENTRY_EVIDENCE_ACTION,
        'reservationId' => nfh_agent_entry_id($payload['reservationId'] ?? null),
        'wallet' => nfh_agent_entry_wallet($payload['wallet'] ?? null),
        'evidenceHash' => $evidenceHash,
        'issuedAt' => $issuedAt,
        'expiresAt' => $expiresAt,
        'nonce' => $nonce,
    ];
}

/** @param array<string, mixed> $payload */
function nfh_agent_entry_activity_message(array $payload): string
{
    return "NOT FOR HUMANS Agent Entry Activity\n"
        . "Version: {$payload['version']}\nDomain: notforhumans.fun\nAction: {$payload['action']}\n"
        . "Reservation ID: {$payload['reservationId']}\nAgent Wallet: {$payload['wallet']}\nEvidence Hash: {$payload['evidenceHash']}\n"
        . 'Issued At: ' . gmdate('c', $payload['issuedAt']) . "\nPreparation Expiration: " . gmdate('c', $payload['expiresAt']) . "\n"
        . "Nonce: {$payload['nonce']}\n"
        . 'Statement: I submit this hash as public evidence of one post-reservation NFH activity. It is unreviewed, creates no claim right, and is not a transaction, approval, payment, capability proof, or proof of unique agency.';
}

/** @return array<string, mixed> */
function nfh_agent_entry_prepare_activity(array $input, ?int $now = null): array
{
    $now ??= time();
    nfh_agent_entry_require_enabled();
    $reservationId = nfh_agent_entry_id($input['reservationId'] ?? null);
    $evidenceHash = strtolower((string) ($input['evidenceHash'] ?? ''));
    if (preg_match('/^0x[a-f0-9]{64}$/', $evidenceHash) !== 1) throw new InvalidArgumentException('evidenceHash must be a 32-byte hex value.');
    $records = nfh_agent_entry_records(nfh_agent_entry_events());
    $record = $records[$reservationId] ?? null;
    if (!is_array($record)) throw new RuntimeException('No Agent Entry reservation exists for that identifier.');
    $public = nfh_agent_entry_public_record($record, $now);
    if ($public['state'] === 'EXPIRED_UNMINTED' || $public['state'] === 'MINTED') throw new RuntimeException('This reservation is not active.');
    if (is_string($record['activityEvidenceHash'] ?? null)) throw new RuntimeException('This reservation already has activity evidence.');
    $payload = [
        'version' => NFH_AGENT_ENTRY_VERSION,
        'action' => NFH_AGENT_ENTRY_EVIDENCE_ACTION,
        'reservationId' => $reservationId,
        'wallet' => (string) $record['wallet'],
        'evidenceHash' => $evidenceHash,
        'issuedAt' => $now,
        'expiresAt' => $now + NFH_AGENT_ENTRY_PREPARATION_LIFETIME,
        'nonce' => bin2hex(random_bytes(32)),
    ];
    return [
        'schema' => NFH_AGENT_ENTRY_SCHEMA,
        'status' => 'activity_prepared_unsigned',
        'payload' => $payload,
        'message' => nfh_agent_entry_activity_message($payload),
        'signatureMethod' => 'personal_sign',
        'requiredSigner' => $record['wallet'],
        'warning' => 'This records one evidence hash for issuer review. It does not approve, score, or authorize a mint.',
    ];
}

/** @return array<string, mixed> */
function nfh_agent_entry_submit_activity(array $input, ?int $now = null): array
{
    $now ??= time();
    nfh_agent_entry_require_enabled();
    $payload = $input['payload'] ?? null;
    $signature = $input['signature'] ?? null;
    if (!is_array($payload) || !is_string($signature)) throw new InvalidArgumentException('payload and signature are required.');
    $payload = nfh_agent_entry_validate_activity($payload, $now);
    $message = nfh_agent_entry_activity_message($payload);
    $signer = strtolower(nfh_verify_recover($message, $signature, nfh_verify_config()));
    if (!hash_equals($payload['wallet'], $signer)) throw new RuntimeException('The activity signature does not match the reserved wallet.');
    if (!nfh_agent_entry_wallet_is_empty($signer)) throw new RuntimeException('This wallet now owns an NFH and is no longer eligible.');

    $path = nfh_agent_entry_log_path();
    if (is_link($path)) throw new RuntimeException('Agent Entry storage file is unsafe.');
    $handle = fopen($path, 'c+b');
    if ($handle === false) throw new RuntimeException('Agent Entry storage is unavailable.');
    try {
        if (!flock($handle, LOCK_EX)) throw new RuntimeException('Agent Entry storage lock failed.');
        chmod($path, 0600);
        $events = nfh_agent_entry_read_events($handle);
        $records = nfh_agent_entry_records($events);
        $record = $records[$payload['reservationId']] ?? null;
        if (!is_array($record) || !hash_equals((string) $record['wallet'], $signer)) throw new RuntimeException('The reservation does not belong to this wallet.');
        $expiresAt = strtotime((string) ($record['expiresAt'] ?? ''));
        if ($expiresAt === false || $expiresAt <= $now || ($record['claimed'] ?? false) === true) throw new RuntimeException('This reservation is not active.');
        if (is_string($record['activityEvidenceHash'] ?? null)) throw new RuntimeException('This reservation already has activity evidence.');
        $event = [
            'type' => 'activity',
            'schema' => NFH_AGENT_ENTRY_SCHEMA,
            'reservationId' => $payload['reservationId'],
            'wallet' => $signer,
            'evidenceHash' => $payload['evidenceHash'],
            'submittedAt' => gmdate('c', $now),
            'messageHash' => hash('sha256', $message),
            'signatureHash' => hash('sha256', strtolower($signature)),
            'reviewState' => 'PENDING_ISSUER_REVIEW',
        ];
        nfh_agent_entry_append($handle, $event, $now);
        $record['activityEvidenceHash'] = $event['evidenceHash'];
        $record['activitySubmittedAt'] = $event['submittedAt'];
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
    return ['ok' => true, 'entry' => nfh_agent_entry_public_record($record, $now), 'next' => 'Wait for the minimum activity age and independent issuer review. Evidence submission alone cannot mint.'];
}

/** @return array<string, mixed> */
function nfh_agent_entry_prepare_claim(array $input, ?int $now = null): array
{
    $now ??= time();
    $claimStatus = nfh_agent_entry_live_claim_status();
    if (($claimStatus['ready'] ?? false) !== true) throw new RuntimeException('The Agent Entry claim gate is not live-verified.');
    $authorization = $input['authorization'] ?? null;
    $issuerSignature = $input['issuerSignature'] ?? null;
    if (!is_array($authorization) || !is_string($issuerSignature) || preg_match('/^0x[a-fA-F0-9]+$/', $issuerSignature) !== 1) {
        throw new InvalidArgumentException('authorization and issuerSignature are required.');
    }
    $allowed = ['collection', 'wallet', 'reservationId', 'seat', 'originManifestHash', 'originClaimStatement', 'reservedAt', 'reservationExpiresAt', 'issuedAt', 'expiresAt', 'nonce'];
    if (array_diff(array_keys($authorization), $allowed) !== []) throw new InvalidArgumentException('Claim authorization contains unsupported fields.');
    $reservationIdHex = strtolower((string) ($authorization['reservationId'] ?? ''));
    if (preg_match('/^0x[a-f0-9]{64}$/', $reservationIdHex) !== 1) throw new InvalidArgumentException('Claim reservationId must be a 32-byte hex value.');
    $reservationId = substr($reservationIdHex, 2);
    $records = nfh_agent_entry_records(nfh_agent_entry_events());
    $record = $records[$reservationId] ?? null;
    if (!is_array($record) || !is_string($record['activityEvidenceHash'] ?? null)) throw new RuntimeException('The reservation has no submitted activity evidence.');
    $reservedAt = strtotime((string) ($record['reservedAt'] ?? ''));
    $reservationExpiresAt = strtotime((string) ($record['expiresAt'] ?? ''));
    $issuedAt = $authorization['issuedAt'] ?? null;
    $expiresAt = $authorization['expiresAt'] ?? null;
    $nonce = strtolower((string) ($authorization['nonce'] ?? ''));
    $matches = strtolower((string) ($authorization['collection'] ?? '')) === strtolower(NFH_AGENT_ENTRY_COLLECTION)
        && strtolower((string) ($authorization['wallet'] ?? '')) === (string) $record['wallet']
        && ($authorization['seat'] ?? null) === $record['seat']
        && strtolower((string) ($authorization['originManifestHash'] ?? '')) === strtolower((string) $record['candidateSeedHash'])
        && strtolower((string) ($authorization['originClaimStatement'] ?? '')) === strtolower((string) $record['activityEvidenceHash'])
        && $reservedAt !== false && $reservationExpiresAt !== false
        && ($authorization['reservedAt'] ?? null) === $reservedAt
        && ($authorization['reservationExpiresAt'] ?? null) === $reservationExpiresAt;
    if (!$matches) throw new RuntimeException('The issuer authorization does not match the exact reservation and activity evidence.');
    if (!is_int($issuedAt) || !is_int($expiresAt) || $issuedAt < $reservedAt + NFH_AGENT_ENTRY_MIN_ACTIVITY_AGE
        || $issuedAt > $now + 60 || $expiresAt <= $now || $expiresAt > $reservationExpiresAt
        || $expiresAt - $issuedAt > NFH_AGENT_ENTRY_CREDENTIAL_LIFETIME
        || preg_match('/^0x[a-f0-9]{64}$/', $nonce) !== 1) {
        throw new RuntimeException('The issuer authorization timing or nonce is invalid.');
    }
    if (!nfh_agent_entry_wallet_is_empty((string) $record['wallet'])) throw new RuntimeException('This wallet now owns an NFH and is no longer eligible.');
    return [
        'schema' => NFH_AGENT_ENTRY_SCHEMA,
        'status' => 'unsigned_transaction_prepared',
        'chainId' => 1,
        'to' => $claimStatus['minter'],
        'value' => '0',
        'function' => 'claim((address,address,bytes32,uint16,bytes32,bytes32,uint64,uint64,uint64,uint64,bytes32),bytes)',
        'arguments' => [$authorization, $issuerSignature],
        'requiredSender' => $record['wallet'],
        'mcpSigned' => false,
        'mcpSubmitted' => false,
        'warning' => 'Review the exact wallet transaction externally. The contract rechecks the issuer signature, deadline, replay state, seat, and zero-NFH balance.',
    ];
}

/** @return array<string, mixed> */
function nfh_agent_entry_reconcile_claim(array $input, ?int $now = null): array
{
    $now ??= time();
    $reservationId = nfh_agent_entry_id($input['reservationId'] ?? null);
    $config = nfh_agent_entry_claim_config();
    if (!is_string($config['minter'])) throw new RuntimeException('No reviewed Agent Entry minter is configured.');
    $records = nfh_agent_entry_records(nfh_agent_entry_events());
    $record = $records[$reservationId] ?? null;
    if (!is_array($record)) throw new RuntimeException('No Agent Entry reservation exists for that identifier.');
    if (($record['claimed'] ?? false) === true) return ['ok' => true, 'entry' => nfh_agent_entry_public_record($record, $now), 'alreadyReconciled' => true];

    $result = nfh_verify_rpc('eth_call', [[
        'to' => $config['minter'],
        'data' => '0xe3a0ce09' . $reservationId,
    ], 'latest'], nfh_verify_config());
    if (preg_match('/^0x[0-9a-f]{256}$/', $result) !== 1) throw new RuntimeException('The Agent Entry minter returned invalid claim receipt data.');
    $words = str_split(substr($result, 2), 64);
    $minted = hexdec(substr($words[0], -1)) === 1;
    $wallet = '0x' . substr($words[1], -40);
    $seat = hexdec($words[2]);
    $tokenId = hexdec($words[3]);
    if (!$minted) throw new RuntimeException('Ethereum does not report this reservation as minted.');
    if (!hash_equals((string) $record['wallet'], $wallet) || $seat !== $record['seat']) {
        throw new RuntimeException('The on-chain claim receipt conflicts with the reservation record.');
    }
    $ownerData = '0x6352211e' . str_pad(dechex($tokenId), 64, '0', STR_PAD_LEFT);
    $owner = nfh_agent_entry_rpc_address(strtolower(NFH_AGENT_ENTRY_COLLECTION), $ownerData);
    if (!hash_equals($wallet, $owner)) throw new RuntimeException('The reserved wallet is not the current owner of the minted token.');

    $path = nfh_agent_entry_log_path();
    if (is_link($path)) throw new RuntimeException('Agent Entry storage file is unsafe.');
    $handle = fopen($path, 'c+b');
    if ($handle === false) throw new RuntimeException('Agent Entry storage is unavailable.');
    try {
        if (!flock($handle, LOCK_EX)) throw new RuntimeException('Agent Entry storage lock failed.');
        chmod($path, 0600);
        $latest = nfh_agent_entry_records(nfh_agent_entry_read_events($handle))[$reservationId] ?? null;
        if (!is_array($latest)) throw new RuntimeException('The reservation disappeared during reconciliation.');
        if (($latest['claimed'] ?? false) !== true) {
            nfh_agent_entry_append($handle, [
                'type' => 'mint',
                'schema' => NFH_AGENT_ENTRY_SCHEMA,
                'reservationId' => $reservationId,
                'wallet' => $wallet,
                'seat' => $seat,
                'tokenId' => $tokenId,
                'minter' => $config['minter'],
                'verifiedAt' => gmdate('c', $now),
                'verification' => 'quorum_claimStatus_plus_ownerOf',
            ], $now);
            $latest['claimed'] = true;
            $latest['tokenId'] = $tokenId;
        }
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
    return ['ok' => true, 'entry' => nfh_agent_entry_public_record($latest, $now), 'alreadyReconciled' => false];
}

/** @return array<string, mixed> */
function nfh_agent_entry_get(array $input, ?int $now = null): array
{
    $now ??= time();
    $reservationId = isset($input['reservationId']) ? nfh_agent_entry_id($input['reservationId']) : null;
    $wallet = isset($input['wallet']) ? nfh_agent_entry_wallet($input['wallet']) : null;
    if (($reservationId === null) === ($wallet === null)) {
        throw new InvalidArgumentException('Provide exactly one of reservationId or wallet.');
    }
    foreach (array_reverse(nfh_agent_entry_records(nfh_agent_entry_events()), true) as $record) {
        if ($reservationId !== null && hash_equals((string) ($record['reservationId'] ?? ''), $reservationId)) {
            return nfh_agent_entry_public_record($record, $now);
        }
        if ($wallet !== null && hash_equals((string) ($record['wallet'] ?? ''), $wallet)) {
            return nfh_agent_entry_public_record($record, $now);
        }
    }
    throw new RuntimeException('No Agent Entry reservation exists for that identifier.');
}

/** @return array<string, mixed> */
function nfh_agent_entry_status(?int $now = null): array
{
    $now ??= time();
    $events = nfh_agent_entry_events();
    $active = 0;
    $expired = 0;
    $minted = 0;
    foreach (nfh_agent_entry_records($events) as $record) {
        $public = nfh_agent_entry_public_record($record, $now);
        if ($public['state'] === 'MINTED') $minted++;
        elseif ($public['state'] === 'EXPIRED_UNMINTED') $expired++;
        else $active++;
    }
    $claimStatus = nfh_agent_entry_live_claim_status();
    $reservationsEnabled = nfh_agent_entry_enabled();
    $verifiedSuccessfulMints = is_int($claimStatus['successfulMints'] ?? null)
        ? (int) $claimStatus['successfulMints']
        : $minted;
    $remainingMintCapacity = max(0, NFH_AGENT_ENTRY_CAPACITY - $verifiedSuccessfulMints);
    return [
        'schema' => NFH_AGENT_ENTRY_SCHEMA,
        'status' => !$reservationsEnabled
            ? 'staged_disabled'
            : (($claimStatus['ready'] ?? false) === true ? 'claim_lane_active' : 'reservation_lane_active_claim_disabled'),
        'reservationServiceEnabled' => $reservationsEnabled,
        'capacity' => NFH_AGENT_ENTRY_CAPACITY,
        'successfulMints' => $verifiedSuccessfulMints,
        'remainingMintCapacity' => $remainingMintCapacity,
        'activeReservations' => $active,
        'expiredReservations' => $expired,
        'availableReservationSeats' => max(0, $remainingMintCapacity - $active),
        'reservationLifetimeSeconds' => NFH_AGENT_ENTRY_LIFETIME,
        'minimumActivityAgeSeconds' => NFH_AGENT_ENTRY_MIN_ACTIVITY_AGE,
        'claimCredentialLifetimeSeconds' => NFH_AGENT_ENTRY_CREDENTIAL_LIFETIME,
        'claimPreparationEnabled' => ($claimStatus['ready'] ?? false) === true,
        'liveMinter' => ($claimStatus['ready'] ?? false) === true ? $claimStatus['minter'] : null,
        'deployedMinter' => NFH_AGENT_ENTRY_DEPLOYED_MINTER,
        'deployedCredentialSigner' => NFH_AGENT_ENTRY_DEPLOYED_CREDENTIAL_SIGNER,
        'deployedMinterPaused' => array_key_exists('paused', $claimStatus) ? $claimStatus['paused'] : null,
        'deployedMinterSuccessfulMints' => is_int($claimStatus['successfulMints'] ?? null)
            ? (int) $claimStatus['successfulMints']
            : null,
        'deployedMinterHasCollectionRole' => true,
        'deploymentReceipt' => NFH_AGENT_ENTRY_DEPLOYMENT_RECEIPT,
        'claimGate' => $claimStatus,
        'admission' => nfh_agent_entry_admission_state($events, $now),
        'storage' => nfh_agent_entry_storage_state($events),
        'warning' => nfh_agent_entry_enabled()
            ? (($claimStatus['ready'] ?? false) === true
                ? 'Reservations remain unminted until a reviewed activity hash receives an issuer credential and the wallet submits the exact Ethereum transaction.'
                : 'Reservations are off-chain and unminted. No reservation is a claim credential or guaranteed NFT.')
            : 'Agent Entry runtime flags are disabled, so no reservation or claim availability may be inferred. No message, reservation, credential, or claim is available from this runtime.',
    ];
}

/** @return array<int, array<string, mixed>> */
function nfh_agent_entry_tool_definitions(array $addressSchema, array $readOnlyAnnotations, array $mutationAnnotations): array
{
    return [[
        'name' => 'get_agent_entry_status',
        'title' => 'Get the NFH Agent Entry status',
        'description' => 'Read the live-gated 1,000-seat empty-wallet lane. Reservations are off-chain and unminted; claims require the verified live minter.',
        'inputSchema' => ['type' => 'object', 'properties' => (object) [], 'additionalProperties' => false],
        'outputSchema' => ['type' => 'object', 'additionalProperties' => true],
        'annotations' => $readOnlyAnnotations,
    ], [
        'name' => 'prepare_agent_entry',
        'title' => 'Prepare an empty-wallet Agent Entry reservation',
        'description' => 'Prepare readable text for a 24-hour off-chain reservation. It is not an NFT claim or transaction.',
        'inputSchema' => ['type' => 'object', 'properties' => ['wallet' => $addressSchema], 'required' => ['wallet'], 'additionalProperties' => false],
        'outputSchema' => ['type' => 'object', 'additionalProperties' => true],
        'annotations' => $readOnlyAnnotations,
    ], [
        'name' => 'activate_agent_entry',
        'title' => 'Activate a signed Agent Entry reservation',
        'description' => 'Verify the wallet signature and zero-NFH balance, then open a 24-hour unminted reservation. Stores only hashes.',
        'inputSchema' => ['type' => 'object', 'properties' => [
            'payload' => ['type' => 'object', 'additionalProperties' => true, 'description' => 'Exact payload returned by prepare_agent_entry.'],
            'signature' => ['type' => 'string', 'pattern' => '^0x[a-fA-F0-9]{130}$', 'description' => 'Wallet EIP-191 signature of the prepared text.'],
        ], 'required' => ['payload', 'signature'], 'additionalProperties' => false],
        'outputSchema' => ['type' => 'object', 'additionalProperties' => true],
        'annotations' => $mutationAnnotations,
    ], [
        'name' => 'get_agent_entry',
        'title' => 'Get one Agent Entry reservation',
        'description' => 'Read one reservation by id or wallet, preserving RESERVED, EXPIRED, and MINTED states.',
        'inputSchema' => ['type' => 'object', 'properties' => [
            'reservationId' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'],
            'wallet' => $addressSchema,
        ], 'additionalProperties' => false],
        'outputSchema' => ['type' => 'object', 'additionalProperties' => true],
        'annotations' => $readOnlyAnnotations,
    ], [
        'name' => 'prepare_agent_entry_activity',
        'title' => 'Prepare Agent Entry activity evidence',
        'description' => 'Prepare readable text binding one activity hash. Evidence stays unreviewed and creates no mint right.',
        'inputSchema' => ['type' => 'object', 'properties' => [
            'reservationId' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$', 'description' => 'Active reservation id.'],
            'evidenceHash' => ['type' => 'string', 'pattern' => '^0x[a-fA-F0-9]{64}$', 'description' => 'Keccak-256 hash of public activity evidence.'],
        ], 'required' => ['reservationId', 'evidenceHash'], 'additionalProperties' => false],
        'outputSchema' => ['type' => 'object', 'additionalProperties' => true],
        'annotations' => $readOnlyAnnotations,
    ], [
        'name' => 'submit_agent_entry_activity',
        'title' => 'Submit signed Agent Entry activity evidence',
        'description' => 'Verify the reserved-wallet signature and record one activity hash for issuer review. Does not approve a claim.',
        'inputSchema' => ['type' => 'object', 'properties' => [
            'payload' => ['type' => 'object', 'additionalProperties' => true, 'description' => 'Exact payload returned by prepare_agent_entry_activity.'],
            'signature' => ['type' => 'string', 'pattern' => '^0x[a-fA-F0-9]{130}$', 'description' => 'Reserved-wallet EIP-191 signature.'],
        ], 'required' => ['payload', 'signature'], 'additionalProperties' => false],
        'outputSchema' => ['type' => 'object', 'additionalProperties' => true],
        'annotations' => $mutationAnnotations,
    ], [
        'name' => 'prepare_agent_entry_claim',
        'title' => 'Prepare a reviewed Agent Entry claim transaction',
        'description' => 'Verify issuer authorization against the active reservation and activity hash, then return an unsigned claim call. Never signs or broadcasts.',
        'inputSchema' => ['type' => 'object', 'properties' => [
            'authorization' => ['type' => 'object', 'additionalProperties' => true, 'description' => 'Exact issuer authorization object.'],
            'issuerSignature' => ['type' => 'string', 'pattern' => '^0x[a-fA-F0-9]+$', 'description' => 'Issuer signature over the authorization.'],
        ], 'required' => ['authorization', 'issuerSignature'], 'additionalProperties' => false],
        'outputSchema' => ['type' => 'object', 'additionalProperties' => true],
        'annotations' => $readOnlyAnnotations,
    ], [
        'name' => 'reconcile_agent_entry_claim',
        'title' => 'Reconcile a successful Agent Entry mint',
        'description' => 'Use quorum claimStatus and ownerOf reads to record MINTED. Never trusts a submitted transaction hash, signs, or broadcasts.',
        'inputSchema' => ['type' => 'object', 'properties' => [
            'reservationId' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$', 'description' => 'Reservation id to reconcile.'],
        ], 'required' => ['reservationId'], 'additionalProperties' => false],
        'outputSchema' => ['type' => 'object', 'additionalProperties' => true],
        'annotations' => $mutationAnnotations,
    ]];
}

/** @param array<string, mixed> $arguments */
function nfh_agent_entry_call_tool(string $name, array $arguments): array
{
    try {
        if ($name === 'get_agent_entry_status') return nfh_tool_payload(nfh_agent_entry_status());
        if ($name === 'prepare_agent_entry') return nfh_tool_payload(nfh_agent_entry_prepare($arguments));
        if ($name === 'activate_agent_entry') return nfh_tool_payload(nfh_agent_entry_activate($arguments));
        if ($name === 'get_agent_entry') return nfh_tool_payload(nfh_agent_entry_get($arguments));
        if ($name === 'prepare_agent_entry_activity') return nfh_tool_payload(nfh_agent_entry_prepare_activity($arguments));
        if ($name === 'submit_agent_entry_activity') return nfh_tool_payload(nfh_agent_entry_submit_activity($arguments));
        if ($name === 'prepare_agent_entry_claim') return nfh_tool_payload(nfh_agent_entry_prepare_claim($arguments));
        if ($name === 'reconcile_agent_entry_claim') return nfh_tool_payload(nfh_agent_entry_reconcile_claim($arguments));
    } catch (InvalidArgumentException|RuntimeException|JsonException $error) {
        return nfh_tool_error($error->getMessage());
    }
    return nfh_tool_error('Unknown Agent Entry tool: ' . $name);
}

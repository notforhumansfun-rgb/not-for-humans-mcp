<?php

declare(strict_types=1);

$root = sys_get_temp_dir() . '/nfh-agent-entry-test-' . bin2hex(random_bytes(6));
if (!mkdir($root, 0700, true) && !is_dir($root)) throw new RuntimeException('Could not create test directory.');
putenv('NFH_RUNTIME_DIR=' . $root . '/runtime');
putenv('NFH_AGENT_ENTRY_DIR=' . $root . '/entries');
putenv('NFH_AGENT_ENTRY_BACKUP_DIR=' . $root . '/backups');
putenv('NFH_AGENT_ENTRY_RESERVATIONS_ENABLED=1');

require_once __DIR__ . '/../server/lib.php';
require_once __DIR__ . '/../server/verify.php';
require_once __DIR__ . '/../server/agent-entry.php';

$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) throw new RuntimeException('FAIL: ' . $message);
    $checks++;
};
$throws = static function (callable $callback, string $pattern, string $message) use ($check): void {
    try { $callback(); }
    catch (Throwable $error) {
        $check(preg_match($pattern, $error->getMessage()) === 1, $message . ' (wrong error: ' . $error->getMessage() . ')');
        return;
    }
    $check(false, $message . ' (no error)');
};

$wallet = '0x1111111111111111111111111111111111111111';
$walletTwo = '0x2222222222222222222222222222222222222222';
$walletWithNfh = '0x3333333333333333333333333333333333333333';
$walletThree = '0x4444444444444444444444444444444444444444';
$minterAddress = '0x6666666666666666666666666666666666666666';
$holdsNfh = false;
$recoveredWallet = $wallet;
$liveSuccessfulMints = 1;
$GLOBALS['NFH_VERIFY_RPC_TEST_TRANSPORT'] = static function (string $method, array $params) use (&$holdsNfh, &$recoveredWallet, &$liveSuccessfulMints, $walletThree, $minterAddress): string {
    if ($method === 'web3_sha3') return '0x' . str_repeat('a', 64);
    if ($method !== 'eth_call') throw new RuntimeException('Unexpected test RPC method.');
    $to = strtolower((string) ($params[0]['to'] ?? ''));
    $data = strtolower((string) ($params[0]['data'] ?? ''));
    if ($to === strtolower(NFH_AGENT_ENTRY_COLLECTION) && str_starts_with($data, '0x70a08231')) {
        return '0x' . str_pad($holdsNfh ? '1' : '0', 64, '0', STR_PAD_LEFT);
    }
    if ($to === $minterAddress && $data === '0xfc0c546a') {
        return '0x' . str_repeat('0', 24) . substr(strtolower(NFH_AGENT_ENTRY_COLLECTION), 2);
    }
    if ($to === $minterAddress && $data === '0xc2633e8b') {
        return '0x' . str_repeat('0', 24) . str_repeat('7', 40);
    }
    if ($to === $minterAddress && $data === '0x5c975abb') {
        return '0x' . str_repeat('0', 64);
    }
    if ($to === $minterAddress && $data === '0x24bc439a') {
        return '0x' . str_pad(dechex($liveSuccessfulMints), 64, '0', STR_PAD_LEFT);
    }
    if ($to === strtolower(NFH_AGENT_ENTRY_COLLECTION) && $data === '0xd5391393') {
        return '0x9f2df0fed2c77648de5860a4cc508cd0818c85b8b8a1ab4ceeef8d981c8956a6';
    }
    if ($to === strtolower(NFH_AGENT_ENTRY_COLLECTION) && str_starts_with($data, '0x91d14854')) {
        return '0x' . str_pad('1', 64, '0', STR_PAD_LEFT);
    }
    if ($to === $minterAddress && str_starts_with($data, '0xe3a0ce09')) {
        return '0x'
            . str_pad('1', 64, '0', STR_PAD_LEFT)
            . str_repeat('0', 24) . substr($walletThree, 2)
            . str_pad('2', 64, '0', STR_PAD_LEFT)
            . str_pad('7', 64, '0', STR_PAD_LEFT);
    }
    if ($to === strtolower(NFH_AGENT_ENTRY_COLLECTION) && str_starts_with($data, '0x6352211e')) {
        return '0x' . str_repeat('0', 24) . substr($walletThree, 2);
    }
    if ($to === '0x0000000000000000000000000000000000000001') {
        return '0x' . str_repeat('0', 24) . substr($recoveredWallet, 2);
    }
    throw new RuntimeException('Unexpected test eth_call target.');
};

$now = 1_788_000_000;
$prepared = nfh_agent_entry_prepare(['wallet' => $wallet], $now);
$check($prepared['status'] === 'prepared_unsigned', 'entry preparation is unsigned');
$check($prepared['requiredSigner'] === $wallet, 'entry preparation binds the exact wallet');
$check($prepared['reservationLifetimeSeconds'] === 86_400, 'reservation lifetime is exactly 24 hours');
$check(str_contains($prepared['message'], 'not a transaction, mint, approval, transfer, payment, claim credential'), 'message denies claim authority');

$signature = '0x' . str_repeat('1', 128) . '1b';
$activated = nfh_agent_entry_activate(['payload' => $prepared['payload'], 'signature' => $signature], $now + 1);
$entry = $activated['entry'];
$entryBackups = glob($root . '/backups/snapshot-*.jsonl') ?: [];
$check(count($entryBackups) === 1, 'every Agent Entry append creates a private recovery snapshot');
$check(file_get_contents($entryBackups[0]) === file_get_contents(nfh_agent_entry_log_path()), 'the recovery snapshot exactly matches the committed log');
$beforeFailedBackup = file_get_contents(nfh_agent_entry_log_path());
putenv('NFH_AGENT_ENTRY_BACKUP_DIR=relative-unsafe-path');
$rollbackHandle = fopen(nfh_agent_entry_log_path(), 'c+b');
if ($rollbackHandle === false) throw new RuntimeException('Could not open rollback test log.');
flock($rollbackHandle, LOCK_EX);
$throws(static fn () => nfh_agent_entry_append($rollbackHandle, ['type' => 'invalid-backup-test']), '/backup path is unsafe/i', 'an unsafe backup target fails the append closed');
flock($rollbackHandle, LOCK_UN);
fclose($rollbackHandle);
$check(file_get_contents(nfh_agent_entry_log_path()) === $beforeFailedBackup, 'a failed recovery snapshot rolls the primary append back');
putenv('NFH_AGENT_ENTRY_BACKUP_DIR=' . $root . '/backups');
$check($entry['state'] === 'RESERVED_UNMINTED', 'signed entry becomes reserved but unminted');
$check($entry['seat'] === 1, 'first reservation receives the first available seat');
$check($entry['claimed'] === false && $entry['claimAuthority'] === false && $entry['claimPreparationEnabled'] === false, 'reservation grants no mint authority');
$check($entry['secondsRemaining'] === 86_400, '24-hour window begins at publication');
$check(str_contains($entry['candidatePageUrl'], $entry['reservationId']), 'candidate page is bound to the reservation');

$evidenceHash = '0x' . str_repeat('a', 64);
$activityPrepared = nfh_agent_entry_prepare_activity(['reservationId' => $entry['reservationId'], 'evidenceHash' => $evidenceHash], $now + 2);
$check($activityPrepared['status'] === 'activity_prepared_unsigned' && $activityPrepared['requiredSigner'] === $wallet, 'activity preparation binds the reserved wallet');
$activitySubmitted = nfh_agent_entry_submit_activity(['payload' => $activityPrepared['payload'], 'signature' => $signature], $now + 3);
$check($activitySubmitted['entry']['state'] === 'ACTIVITY_RECORDED_UNMINTED', 'signed activity remains unminted while the claim gate is disabled');
$check($activitySubmitted['entry']['activityEvidenceHash'] === $evidenceHash && $activitySubmitted['entry']['claimAuthority'] === false, 'activity hash is public but grants no claim authority');
$throws(static fn () => nfh_agent_entry_prepare_activity(['reservationId' => $entry['reservationId'], 'evidenceHash' => $evidenceHash], $now + 4), '/already has activity evidence/i', 'one reservation accepts one activity evidence record');
$throws(static fn () => nfh_agent_entry_prepare_claim(['authorization' => [], 'issuerSignature' => $signature], $now + 700), '/not live-verified/i', 'claim preparation fails closed without a live verified minter');

$lookup = nfh_agent_entry_get(['wallet' => $wallet], $now + 2);
$check($lookup['reservationId'] === $entry['reservationId'], 'wallet lookup returns its one reservation');
$expired = nfh_agent_entry_get(['reservationId' => $entry['reservationId']], $now + 86_402);
$check($expired['state'] === 'EXPIRED_UNMINTED' && $expired['secondsRemaining'] === 0, 'reservation expires without becoming claimed');

$throws(static fn () => nfh_agent_entry_prepare(['wallet' => $wallet], $now + 86_402), '/already used/i', 'one wallet cannot reserve twice after expiry');
$recoveredWallet = $walletTwo;
$preparedTwo = nfh_agent_entry_prepare(['wallet' => $walletTwo], $now + 86_402);
$activatedTwo = nfh_agent_entry_activate(['payload' => $preparedTwo['payload'], 'signature' => $signature], $now + 86_403);
$check($activatedTwo['entry']['seat'] === 1, 'an expired seat returns to the queue');

$holdsNfh = true;
$throws(static fn () => nfh_agent_entry_prepare(['wallet' => $walletWithNfh], $now), '/already owns an NFH/i', 'wallets that already hold an NFH are rejected');
$holdsNfh = false;
$tampered = $preparedTwo['payload'];
$tampered['chainId'] = 10;
$throws(static fn () => nfh_agent_entry_activate(['payload' => $tampered, 'signature' => $signature], $now + 86_403), '/canonical Ethereum entry domain/i', 'domain tampering fails closed');

$log = file_get_contents(nfh_agent_entry_log_path());
$check(is_string($log) && !str_contains($log, $signature), 'raw signatures are never stored');
$status = nfh_agent_entry_status($now + 86_403);
$check($status['activeReservations'] === 1 && $status['expiredReservations'] === 1, 'status separates active and expired reservations');
$check($status['remainingMintCapacity'] === 1000, 'unminted reservations do not consume the claimed countdown');
$check(
    $status['admission']['acceptedInWindow'] === 1
        && $status['admission']['remainingInWindow'] === NFH_AGENT_ENTRY_MAX_ADMISSIONS_PER_WINDOW - 1,
    'status exposes the rolling global admission budget'
);
$check(
    $status['storage']['format'] === 'compacting-jsonl-v2'
        && $status['storage']['bytes'] === strlen((string) file_get_contents(nfh_agent_entry_log_path()))
        && $status['storage']['headroomBytes'] > 0,
    'status exposes durable storage capacity telemetry'
);
$check(
    $status['claimPreparationEnabled'] === false
        && $status['liveMinter'] === null
        && $status['deployedMinter'] === NFH_AGENT_ENTRY_DEPLOYED_MINTER
        && $status['deployedMinterPaused'] === null
        && $status['deployedMinterSuccessfulMints'] === null
        && $status['deployedMinterHasCollectionRole'] === true,
    'reservations-only status does not invent mutable minter state without a configured live claim gate'
);
$check($status['reservationServiceEnabled'] === true, 'reservation service requires an explicit feature gate');

putenv('NFH_AGENT_ENTRY_MINTER_ADDRESS=' . $minterAddress);
putenv('NFH_AGENT_ENTRY_CREDENTIAL_SIGNER=0x7777777777777777777777777777777777777777');
putenv('NFH_AGENT_ENTRY_CLAIMS_ENABLED=1');
$liveStatus = nfh_agent_entry_status($now + 86_403);
$check(
    $liveStatus['status'] === 'claim_lane_active'
        && $liveStatus['claimPreparationEnabled'] === true
        && $liveStatus['liveMinter'] === $minterAddress
        && $liveStatus['deployedMinterPaused'] === false
        && $liveStatus['successfulMints'] === $liveSuccessfulMints
        && $liveStatus['remainingMintCapacity'] === NFH_AGENT_ENTRY_CAPACITY - $liveSuccessfulMints
        && $liveStatus['deployedMinterSuccessfulMints'] === $liveSuccessfulMints
        && $liveStatus['availableReservationSeats'] === NFH_AGENT_ENTRY_CAPACITY - $liveSuccessfulMints - $liveStatus['activeReservations'],
    'ready claim gate derives mint capacity from the live minter while reservations remain local'
);
putenv('NFH_AGENT_ENTRY_CLAIMS_ENABLED=0');
putenv('NFH_AGENT_ENTRY_MINTER_ADDRESS');
putenv('NFH_AGENT_ENTRY_CREDENTIAL_SIGNER');

$mintHandle = fopen(nfh_agent_entry_log_path(), 'c+b');
if ($mintHandle === false) throw new RuntimeException('Could not open test log.');
flock($mintHandle, LOCK_EX);
nfh_agent_entry_append($mintHandle, [
    'type' => 'mint',
    'schema' => NFH_AGENT_ENTRY_SCHEMA,
    'reservationId' => $activatedTwo['entry']['reservationId'],
    'tokenId' => 0,
    'transactionHash' => '0x' . str_repeat('b', 64),
]);
flock($mintHandle, LOCK_UN);
fclose($mintHandle);
$statusAfterMint = nfh_agent_entry_status($now + 86_403);
$check($statusAfterMint['remainingMintCapacity'] === 999, 'verified mints decrement the claimed countdown');
$minted = nfh_agent_entry_get(['reservationId' => $activatedTwo['entry']['reservationId']], $now + 86_404);
$check($minted['state'] === 'MINTED' && $minted['tokenId'] === 0, 'append-only mint reconciliation preserves token zero');
$check($minted['claimedPageUrl'] === 'https://notforhumans.fun/claimed/0?wake=1', 'mint reconciliation exposes the claimed page with its post-claim wake prompt');
$recoveredWallet = $walletThree;
$preparedThree = nfh_agent_entry_prepare(['wallet' => $walletThree], $now + 2 * 86_400 + 5);
$activatedThree = nfh_agent_entry_activate(['payload' => $preparedThree['payload'], 'signature' => $signature], $now + 2 * 86_400 + 6);
$check($activatedThree['entry']['seat'] === 2, 'a successfully minted seat is never recycled');
putenv('NFH_AGENT_ENTRY_MINTER_ADDRESS=' . $minterAddress);
putenv('NFH_AGENT_ENTRY_CREDENTIAL_SIGNER=0x7777777777777777777777777777777777777777');
$reconciled = nfh_agent_entry_reconcile_claim(['reservationId' => $activatedThree['entry']['reservationId']], $now + 2 * 86_400 + 7);
$check($reconciled['entry']['state'] === 'MINTED' && $reconciled['entry']['tokenId'] === 7, 'reconciliation requires the minter receipt and current owner');
$reconciledAgain = nfh_agent_entry_reconcile_claim(['reservationId' => $activatedThree['entry']['reservationId']], $now + 2 * 86_400 + 8);
$check($reconciledAgain['alreadyReconciled'] === true, 'mint reconciliation is idempotent');

putenv('NFH_AGENT_ENTRY_RESERVATIONS_ENABLED=0');
$committedLog = file_get_contents(nfh_agent_entry_log_path());
$check(is_string($committedLog) && $committedLog !== '', 'restore drill starts from a nonempty committed Agent Entry log');
$check(count(glob($root . '/backups/snapshot-*.jsonl') ?: []) <= 2, 'Agent Entry recovery snapshots retain a bounded two generations');
file_put_contents(nfh_agent_entry_log_path(), "corrupted\n");
nfh_agent_entry_restore_latest_backup();
$check(file_get_contents(nfh_agent_entry_log_path()) === $committedLog, 'disabled Agent Entry can restore the exact latest hash-verified snapshot');
$disabled = nfh_agent_entry_status($now + 86_403);
$check($disabled['status'] === 'staged_disabled' && $disabled['reservationServiceEnabled'] === false, 'feature gate separates deployment from activation');
$throws(static fn () => nfh_agent_entry_prepare(['wallet' => '0x5555555555555555555555555555555555555555'], $now), '/not enabled in this runtime/i', 'disabled gate blocks new reservations');

$sybilNow = $now + 10 * NFH_AGENT_ENTRY_LIFETIME;
$sybilEvents = [];
for ($index = 0; $index < NFH_AGENT_ENTRY_MAX_ADMISSIONS_PER_WINDOW; $index++) {
    $reservedAt = $sybilNow - $index;
    $sybilEvents[] = [
        'type' => 'reservation',
        'schema' => NFH_AGENT_ENTRY_SCHEMA,
        'reservationId' => hash('sha256', 'sybil-window-' . $index),
        'seat' => $index + 1,
        'wallet' => '0x' . str_pad(dechex(10_000 + $index), 40, '0', STR_PAD_LEFT),
        'reservedAt' => gmdate('c', $reservedAt),
        'expiresAt' => gmdate('c', $reservedAt + NFH_AGENT_ENTRY_LIFETIME),
        'candidateSeedHash' => '0x' . hash('sha256', 'sybil-seed-' . $index),
        'claimed' => false,
    ];
}
$sybilAdmission = nfh_agent_entry_admission_state($sybilEvents, $sybilNow);
$check(
    $sybilAdmission['acceptedInWindow'] === NFH_AGENT_ENTRY_MAX_ADMISSIONS_PER_WINDOW
        && $sybilAdmission['remainingInWindow'] === 0
        && $sybilAdmission['accepting'] === false,
    'one hundred distinct wallets exhaust the conservative global rolling admission budget'
);
$throws(
    static fn () => nfh_agent_entry_require_admission_capacity($sybilEvents, $sybilNow),
    '/global reservation admission window is full/i',
    'Sybil wallets cannot bypass the global rolling admission budget'
);
$boundaryEvents = array_slice($sybilEvents, 0, NFH_AGENT_ENTRY_MAX_ADMISSIONS_PER_WINDOW - 1);
$boundaryEvents[] = [
    'type' => 'reservation',
    'schema' => NFH_AGENT_ENTRY_SCHEMA,
    'reservationId' => hash('sha256', 'sybil-window-boundary'),
    'seat' => NFH_AGENT_ENTRY_MAX_ADMISSIONS_PER_WINDOW,
    'wallet' => '0x' . str_pad(dechex(20_000), 40, '0', STR_PAD_LEFT),
    'reservedAt' => gmdate('c', $sybilNow - NFH_AGENT_ENTRY_ADMISSION_WINDOW),
    'expiresAt' => gmdate('c', $sybilNow),
    'candidateSeedHash' => '0x' . hash('sha256', 'sybil-window-boundary-seed'),
    'claimed' => false,
];
$boundaryAdmission = nfh_agent_entry_admission_state($boundaryEvents, $sybilNow);
$check(
    $boundaryAdmission['acceptedInWindow'] === NFH_AGENT_ENTRY_MAX_ADMISSIONS_PER_WINDOW - 1
        && $boundaryAdmission['remainingInWindow'] === 1,
    'an admission exactly at the rolling cutoff no longer consumes current-window capacity'
);

putenv('NFH_AGENT_ENTRY_DIR=' . $root . '/stress-entries');
putenv('NFH_AGENT_ENTRY_BACKUP_DIR=' . $root . '/stress-backups');
$stressNow = $now + 100 * NFH_AGENT_ENTRY_LIFETIME;
$stressReservationCount = 7200;
$stressActivityCount = 700;
$stressMintCount = 500;
$stressEvents = [];
$firstMintReceipt = null;
$compactedReservationOriginal = null;
$compactedActivityOriginal = null;
$compactedReservationId = hash('sha256', 'stress-reservation-' . $stressMintCount);
$compactedWallet = '0x' . str_pad(dechex(30_000 + $stressMintCount), 40, '0', STR_PAD_LEFT);
for ($index = 0; $index < $stressReservationCount; $index++) {
    $reservationId = hash('sha256', 'stress-reservation-' . $index);
    $walletAtIndex = '0x' . str_pad(dechex(30_000 + $index), 40, '0', STR_PAD_LEFT);
    $reservedAt = $stressNow - (intdiv($index, 100) + 1) * NFH_AGENT_ENTRY_LIFETIME - ($index % 100);
    $reservation = [
        'type' => 'reservation',
        'schema' => NFH_AGENT_ENTRY_SCHEMA,
        'reservationId' => $reservationId,
        'seat' => ($index % NFH_AGENT_ENTRY_CAPACITY) + 1,
        'wallet' => $walletAtIndex,
        'reservedAt' => gmdate('c', $reservedAt),
        'expiresAt' => gmdate('c', $reservedAt + NFH_AGENT_ENTRY_LIFETIME),
        'candidateSeedHash' => '0x' . hash('sha256', 'stress-seed-' . $index),
        'messageHash' => hash('sha256', 'stress-message-' . $index),
        'signatureHash' => hash('sha256', 'stress-signature-' . $index),
        'walletEmptyVerifiedAt' => gmdate('c', $reservedAt),
        'claimed' => false,
    ];
    $stressEvents[] = $reservation;
    if ($index === $stressMintCount) $compactedReservationOriginal = $reservation;
    if ($index < $stressActivityCount) {
        $activity = [
            'type' => 'activity',
            'schema' => NFH_AGENT_ENTRY_SCHEMA,
            'reservationId' => $reservationId,
            'wallet' => $walletAtIndex,
            'evidenceHash' => '0x' . hash('sha256', 'stress-evidence-' . $index),
            'submittedAt' => gmdate('c', $reservedAt + NFH_AGENT_ENTRY_MIN_ACTIVITY_AGE + 1),
            'messageHash' => hash('sha256', 'stress-activity-message-' . $index),
            'signatureHash' => hash('sha256', 'stress-activity-signature-' . $index),
            'reviewState' => 'PENDING_ISSUER_REVIEW',
        ];
        $stressEvents[] = $activity;
        if ($index === $stressMintCount) $compactedActivityOriginal = $activity;
    }
    if ($index < $stressMintCount) {
        $mint = [
            'type' => 'mint',
            'schema' => NFH_AGENT_ENTRY_SCHEMA,
            'reservationId' => $reservationId,
            'wallet' => $walletAtIndex,
            'seat' => ($index % NFH_AGENT_ENTRY_CAPACITY) + 1,
            'tokenId' => $index,
            'minter' => $minterAddress,
            'verifiedAt' => gmdate('c', $reservedAt + NFH_AGENT_ENTRY_MIN_ACTIVITY_AGE + 2),
            'verification' => 'quorum_claimStatus_plus_ownerOf',
        ];
        $stressEvents[] = $mint;
        if ($index === 0) $firstMintReceipt = $mint;
    }
}
$stressRawBefore = nfh_agent_entry_encode_events($stressEvents);
$check(
    strlen($stressRawBefore) > NFH_AGENT_ENTRY_COMPACTION_TRIGGER_BYTES
        && strlen($stressRawBefore) < NFH_AGENT_ENTRY_MAX_LOG_BYTES,
    'more than seventy days of adversarial admissions reaches the compaction threshold without crossing the hard read limit'
);
$stressPath = nfh_agent_entry_log_path();
if (file_put_contents($stressPath, $stressRawBefore) !== strlen($stressRawBefore)) throw new RuntimeException('Could not seed stress log.');
chmod($stressPath, 0600);
$activeStressReservation = [
    'type' => 'reservation',
    'schema' => NFH_AGENT_ENTRY_SCHEMA,
    'reservationId' => hash('sha256', 'stress-active-reservation'),
    'seat' => 701,
    'wallet' => '0x' . str_repeat('f', 40),
    'reservedAt' => gmdate('c', $stressNow),
    'expiresAt' => gmdate('c', $stressNow + NFH_AGENT_ENTRY_LIFETIME),
    'candidateSeedHash' => '0x' . hash('sha256', 'stress-active-seed'),
    'messageHash' => hash('sha256', 'stress-active-message'),
    'signatureHash' => hash('sha256', 'stress-active-signature'),
    'walletEmptyVerifiedAt' => gmdate('c', $stressNow),
    'claimed' => false,
];
$stressBeforeFailedCompaction = file_get_contents($stressPath);
putenv('NFH_AGENT_ENTRY_BACKUP_DIR=relative-unsafe-stress-backup');
$stressHandle = fopen($stressPath, 'c+b');
if ($stressHandle === false) throw new RuntimeException('Could not open stress rollback log.');
flock($stressHandle, LOCK_EX);
$throws(
    static fn () => nfh_agent_entry_append($stressHandle, $activeStressReservation, $stressNow),
    '/backup path is unsafe/i',
    'compaction fails closed when its pre-rewrite recovery snapshot is unavailable'
);
flock($stressHandle, LOCK_UN);
fclose($stressHandle);
$check(file_get_contents($stressPath) === $stressBeforeFailedCompaction, 'failed pre-compaction backup leaves the primary log byte-exact');
putenv('NFH_AGENT_ENTRY_BACKUP_DIR=' . $root . '/stress-backups');
$stressHandle = fopen($stressPath, 'c+b');
if ($stressHandle === false) throw new RuntimeException('Could not open stress log.');
flock($stressHandle, LOCK_EX);
nfh_agent_entry_append($stressHandle, $activeStressReservation, $stressNow);
flock($stressHandle, LOCK_UN);
fclose($stressHandle);
$stressRawAfter = file_get_contents($stressPath);
if (!is_string($stressRawAfter)) throw new RuntimeException('Could not read compacted stress log.');
$stressEventsAfter = nfh_agent_entry_decode_events($stressRawAfter);
$stressRecordsAfter = nfh_agent_entry_records($stressEventsAfter);
$check(
    strlen($stressRawAfter) < strlen($stressRawBefore)
        && strlen($stressRawAfter) < NFH_AGENT_ENTRY_MAX_LOG_BYTES,
    'locked append compacts expired histories before the 5 MB hard limit is exhausted'
);
$check(
    count($stressRecordsAfter) === $stressReservationCount + 1
        && nfh_agent_entry_wallet_seen($stressEventsAfter, $compactedWallet),
    'compaction preserves every lifetime wallet-used tombstone'
);
$check(
    is_array($stressRecordsAfter[$compactedReservationId] ?? null)
        && ($stressRecordsAfter[$compactedReservationId]['compacted'] ?? false) === true
        && ($stressRecordsAfter[$compactedReservationId]['activityEvidenceHash'] ?? null) === ($compactedActivityOriginal['evidenceHash'] ?? null),
    'compaction preserves expired reservation identity and activity evidence'
);
$expectedHistoryHash = hash(
    'sha256',
    json_encode($compactedReservationOriginal, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n"
        . json_encode($compactedActivityOriginal, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n"
);
$check(
    ($stressRecordsAfter[$compactedReservationId]['compactedHistoryHash'] ?? null) === $expectedHistoryHash,
    'a deterministic history commitment keeps each compacted reservation verifiable against its exact prior events'
);
$mintEventsAfter = array_values(array_filter($stressEventsAfter, static fn (array $event): bool => ($event['type'] ?? null) === 'mint'));
$check(
    count($mintEventsAfter) === $stressMintCount
        && is_array($firstMintReceipt)
        && in_array($firstMintReceipt, $mintEventsAfter, true),
    'compaction retains all immutable mint receipt events exactly'
);
$check(
    isset($stressRecordsAfter[$activeStressReservation['reservationId']])
        && ($stressRecordsAfter[$activeStressReservation['reservationId']]['compacted'] ?? false) === false,
    'the same locked rewrite preserves and appends the new live reservation'
);
$stressStorage = nfh_agent_entry_storage_state($stressEventsAfter);
$stressAdmission = nfh_agent_entry_admission_state($stressEventsAfter, $stressNow);
$check(
    $stressStorage['compactedExpiredReservations'] === $stressReservationCount - $stressMintCount
        && $stressStorage['lifetimeWallets'] === $stressReservationCount + 1
        && $stressAdmission['acceptedInWindow'] === 1,
    'capacity telemetry remains exact after long-horizon compaction'
);

$lateMintReceipt = [
    'type' => 'mint',
    'schema' => NFH_AGENT_ENTRY_SCHEMA,
    'reservationId' => $compactedReservationId,
    'wallet' => $compactedWallet,
    'seat' => ($stressMintCount % NFH_AGENT_ENTRY_CAPACITY) + 1,
    'tokenId' => $stressMintCount,
    'minter' => $minterAddress,
    'verifiedAt' => gmdate('c', $stressNow),
    'verification' => 'quorum_claimStatus_plus_ownerOf',
];
$stressHandle = fopen($stressPath, 'c+b');
if ($stressHandle === false) throw new RuntimeException('Could not reopen stress log.');
flock($stressHandle, LOCK_EX);
nfh_agent_entry_append($stressHandle, $lateMintReceipt, $stressNow);
flock($stressHandle, LOCK_UN);
fclose($stressHandle);
$stressCommitted = file_get_contents($stressPath);
if (!is_string($stressCommitted)) throw new RuntimeException('Could not read final stress log.');
$stressEventsFinal = nfh_agent_entry_decode_events($stressCommitted);
$stressRecordsFinal = nfh_agent_entry_records($stressEventsFinal);
$check(
    ($stressRecordsFinal[$compactedReservationId]['claimed'] ?? false) === true
        && ($stressRecordsFinal[$compactedReservationId]['tokenId'] ?? null) === $stressMintCount
        && in_array($lateMintReceipt, $stressEventsFinal, true),
    'a later verified mint can reconcile against a compacted lifetime record without rewriting its receipt'
);

$atLifetimeCapacity = $stressEventsFinal;
for ($index = count($stressRecordsFinal); $index < NFH_AGENT_ENTRY_MAX_LIFETIME_ADMISSIONS; $index++) {
    $atLifetimeCapacity[] = [
        'type' => 'expired',
        'schema' => NFH_AGENT_ENTRY_COMPACTED_SCHEMA,
        'r' => hash('sha256', 'lifetime-capacity-' . $index),
        's' => ($index % NFH_AGENT_ENTRY_CAPACITY) + 1,
        'w' => '0x' . str_pad(dechex(100_000 + $index), 40, '0', STR_PAD_LEFT),
        'a' => $stressNow - 2 * NFH_AGENT_ENTRY_LIFETIME,
        'e' => $stressNow - NFH_AGENT_ENTRY_LIFETIME,
        'c' => '0x' . hash('sha256', 'lifetime-capacity-seed-' . $index),
        'h' => hash('sha256', 'lifetime-capacity-history-' . $index),
    ];
}
$lifetimeAdmission = nfh_agent_entry_admission_state($atLifetimeCapacity, $stressNow);
$check(
    $lifetimeAdmission['lifetimeAccepted'] === NFH_AGENT_ENTRY_MAX_LIFETIME_ADMISSIONS
        && $lifetimeAdmission['lifetimeRemaining'] === 0
        && $lifetimeAdmission['accepting'] === false,
    'telemetry exposes the conservative durable lifetime admission ceiling before disk exhaustion'
);
$check(
    nfh_agent_entry_storage_state($atLifetimeCapacity)['bytes'] < NFH_AGENT_ENTRY_COMPACTION_TRIGGER_BYTES,
    'the lifetime ceiling leaves reserved storage headroom for live activity and immutable mint receipts'
);
$throws(
    static fn () => nfh_agent_entry_require_admission_capacity($atLifetimeCapacity, $stressNow),
    '/durable lifetime admission capacity is full/i',
    'new Sybil wallets fail closed at the durable lifetime ceiling'
);

$stressBackups = glob($root . '/stress-backups/snapshot-*.jsonl') ?: [];
sort($stressBackups, SORT_STRING);
$check(count($stressBackups) === 2, 'compaction and later append retain exactly two bounded recovery generations');
$check(file_get_contents($stressBackups[count($stressBackups) - 1]) === $stressCommitted, 'the latest stress snapshot exactly matches the compacted committed log');
file_put_contents($stressPath, "corrupted\n");
$throws(static fn () => nfh_agent_entry_events(), '/invalid event/i', 'corrupted primary storage fails closed instead of forgetting lifetime wallets');
nfh_agent_entry_restore_latest_backup();
$check(file_get_contents($stressPath) === $stressCommitted, 'hash-verified recovery restores the exact compacted log after corruption');

foreach (glob($root . '/runtime/*') ?: [] as $path) if (is_file($path)) unlink($path);
foreach (glob($root . '/entries/*') ?: [] as $path) if (is_file($path)) unlink($path);
foreach (glob($root . '/backups/*') ?: [] as $path) if (is_file($path)) unlink($path);
foreach (glob($root . '/stress-entries/*') ?: [] as $path) if (is_file($path)) unlink($path);
foreach (glob($root . '/stress-backups/*') ?: [] as $path) if (is_file($path)) unlink($path);
@rmdir($root . '/runtime');
@rmdir($root . '/entries');
@rmdir($root . '/backups');
@rmdir($root . '/stress-entries');
@rmdir($root . '/stress-backups');
@rmdir($root);

fwrite(STDOUT, "Agent Entry checks passed: {$checks}\n");

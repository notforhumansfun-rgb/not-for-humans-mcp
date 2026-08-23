<?php

declare(strict_types=1);

require_once __DIR__ . '/../server/lib.php';
require_once __DIR__ . '/../server/verify.php';
require_once __DIR__ . '/../server/agent-wanted.php';
require_once __DIR__ . '/../server/agent-brain.php';
require_once __DIR__ . '/../server/tasq-bridge.php';

function tasq_bridge_check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    fwrite(STDOUT, "PASS: {$message}\n");
}

function tasq_bridge_rejects(callable $callback, string $needle, string $message): void
{
    try {
        $callback();
        tasq_bridge_check(false, $message);
    } catch (InvalidArgumentException|RuntimeException $error) {
        tasq_bridge_check(str_contains($error->getMessage(), $needle), $message);
    }
}

$suffix = bin2hex(random_bytes(6));
$runtimeDirectory = sys_get_temp_dir() . '/nfh-tasq-bridge-runtime-' . $suffix;
$brainDirectory = sys_get_temp_dir() . '/nfh-tasq-bridge-brain-' . $suffix;
$bridgeDirectory = sys_get_temp_dir() . '/nfh-tasq-bridge-bindings-' . $suffix;
mkdir($runtimeDirectory, 0700, true);
mkdir($brainDirectory, 0700, true);
mkdir($bridgeDirectory, 0700, true);
putenv('NFH_RUNTIME_DIR=' . $runtimeDirectory);
putenv('NFH_AGENT_BRAIN_DIR=' . $brainDirectory);
putenv('NFH_TASQ_BRIDGE_DIR=' . $bridgeDirectory);

$ownerA = '0x1111111111111111111111111111111111111111';
$ownerB = '0x2222222222222222222222222222222222222222';
$ownerC = '0x3333333333333333333333333333333333333333';
$owners = [1003 => $ownerA, 1004 => $ownerB, 1005 => $ownerA];
$recoveredSigner = $ownerA;
$GLOBALS['NFH_VERIFY_RPC_TEST_TRANSPORT'] = static function (string $method, array $params) use (&$owners, &$recoveredSigner): string {
    if ($method === 'web3_sha3') return '0x' . str_repeat('a', 64);
    if ($method !== 'eth_call') throw new RuntimeException('Unexpected test RPC method.');
    $to = strtolower((string) ($params[0]['to'] ?? ''));
    $data = strtolower((string) ($params[0]['data'] ?? ''));
    if ($to === '0x0000000000000000000000000000000000000001') {
        return '0x' . str_repeat('0', 24) . substr(strtolower($recoveredSigner), 2);
    }
    if ($to === strtolower(NFH_AGENT_WANTED_COLLECTION) && str_starts_with($data, '0x6352211e')) {
        $tokenId = hexdec(substr($data, -64));
        $owner = $owners[$tokenId] ?? '0x0000000000000000000000000000000000000000';
        return '0x' . str_repeat('0', 24) . substr(strtolower($owner), 2);
    }
    throw new RuntimeException('Unexpected test RPC call.');
};

$now = 1_787_382_200;
$authorityNowMs = $now * 1000;
$spaceId = 'nfh/flux-tasq-interop';
$transport = ['kind' => 'local_process', 'id' => 'tasq-home:pilot-a'];
$principalA = 'urn:tasq:local-principal:nfh-flux-tasq-interop:nfh-1003-epoch-1';
$principalB = 'urn:tasq:local-principal:nfh-flux-tasq-interop:nfh-1004-epoch-1';
$principalSameOwner = 'urn:tasq:local-principal:nfh-flux-tasq-interop:nfh-1005-epoch-1';
$principalA2 = 'urn:tasq:local-principal:nfh-flux-tasq-interop:nfh-1003-epoch-1-rebound';
$signature = '0x' . str_repeat('0', 128) . '1b';

$preparedA = nfh_tasq_prepare_binding([
    'tokenId' => 1003,
    'spaceId' => $spaceId,
    'tasqPrincipalId' => $principalA,
    'transport' => $transport,
], $now);
tasq_bridge_check(($preparedA['status'] ?? null) === 'prepared_unsigned'
    && ($preparedA['payload']['actorAlias'] ?? null) === 'nfh:1003:epoch:1'
    && ($preparedA['effectAuthorityGranted'] ?? true) === false,
    'Binding preparation derives the NFH actor epoch and grants no effect authority');
tasq_bridge_check(str_contains((string) $preparedA['message'], 'Tasq Principal: ' . $principalA)
    && str_contains((string) $preparedA['message'], 'Transport ID: ' . $transport['id'])
    && str_contains((string) $preparedA['message'], 'authorizes no transaction'),
    'The exact signature message binds principal, transport, and authority limits');

$bindingA = nfh_tasq_publish_binding(['payload' => $preparedA['payload'], 'signature' => $signature], $now + 1);
tasq_bridge_check(($bindingA['operator'] ?? null) === $ownerA
    && ($bindingA['verification']['ownerOf'] ?? null) === 'two-rpc-quorum-verified'
    && ($bindingA['authority']['coordinationIdentity'] ?? false) === true
    && ($bindingA['authority']['wallet'] ?? true) === false,
    'Publication recovers the owner, rechecks ownership, and stores a coordination-only binding');
$bindingAReplay = nfh_tasq_publish_binding(['payload' => $preparedA['payload'], 'signature' => $signature], $now + 2);
tasq_bridge_check(($bindingAReplay['bindingId'] ?? null) === ($bindingA['bindingId'] ?? null)
    && count(nfh_tasq_bindings()) === 1,
    'Exact binding publication retries are idempotent');
$stored = file_get_contents($bridgeDirectory . '/bindings.jsonl');
tasq_bridge_check(is_string($stored) && !str_contains($stored, $signature)
    && !str_contains($stored, (string) $preparedA['payload']['nonce']),
    'The private binding log stores neither raw signatures nor signing nonces');

$activeClaim = [
    'id' => 'claim-a',
    'workspaceId' => $spaceId,
    'commitmentId' => 'commitment-a',
    'principalId' => $principalA,
    'actorAlias' => 'nfh:1003:epoch:1',
    'revision' => 1,
    'fence' => 1,
    'expiresAt' => $authorityNowMs + 60_000,
    'releasedAt' => null,
];
$activeProjection = nfh_tasq_project_claim($activeClaim, $authorityNowMs);
tasq_bridge_check(($activeProjection['state'] ?? null) === 'active'
    && ($activeProjection['leaseRecordRetained'] ?? false) === true,
    'Authority-clock projection treats an unreleased future lease as active');
$expiredProjection = nfh_tasq_project_claim($activeClaim, $authorityNowMs + 60_000);
tasq_bridge_check(($expiredProjection['state'] ?? null) === 'expired'
    && ($expiredProjection['active'] ?? true) === false,
    'Authority-clock projection treats expiresAt equality as expired even when releasedAt is null');
$releasedClaim = $activeClaim;
$releasedClaim['releasedAt'] = $authorityNowMs - 1;
tasq_bridge_check((nfh_tasq_project_claim($releasedClaim, $authorityNowMs)['state'] ?? null) === 'released',
    'Authority-clock projection distinguishes released claims from expired retained records');

$authorization = nfh_tasq_authorize_transition(
    (string) $bindingA['bindingId'],
    $activeClaim,
    'commitment.start',
    $spaceId,
    $transport,
    $authorityNowMs,
    $now + 3,
);
tasq_bridge_check(($authorization['authorized'] ?? false) === true
    && ($authorization['claim']['active'] ?? false) === true
    && ($authorization['walletAuthorityGranted'] ?? true) === false,
    'A current owner-bound principal with the active claim can authorize a guarded transition');
tasq_bridge_rejects(
    static fn () => nfh_tasq_authorize_transition(
        (string) $bindingA['bindingId'], $activeClaim, 'attempt.start', $spaceId, $transport,
        $authorityNowMs + 60_000, $now + 3,
    ),
    'not active',
    'An expired retained claim fails closed before a guarded transition',
);
$wrongActorClaim = $activeClaim;
$wrongActorClaim['actorAlias'] = 'nfh:1003:epoch:2';
tasq_bridge_rejects(
    static fn () => nfh_tasq_authorize_transition(
        (string) $bindingA['bindingId'], $wrongActorClaim, 'commitment.start', $spaceId, $transport,
        $authorityNowMs, $now + 3,
    ),
    'different authenticated principal or actor epoch',
    'A different operator epoch cannot use the original binding and claim',
);
tasq_bridge_rejects(
    static fn () => nfh_tasq_authorize_transition(
        (string) $bindingA['bindingId'], $activeClaim, 'commitment.start', $spaceId,
        ['kind' => 'local_process', 'id' => 'tasq-home:other'], $authorityNowMs, $now + 3,
    ),
    'space or transport',
    'A different transport rendezvous cannot reuse the signed principal binding',
);

$recoveredSigner = $ownerB;
$preparedB = nfh_tasq_prepare_binding([
    'tokenId' => 1004,
    'spaceId' => $spaceId,
    'tasqPrincipalId' => $principalB,
    'transport' => $transport,
], $now + 4);
$bindingB = nfh_tasq_publish_binding(['payload' => $preparedB['payload'], 'signature' => $signature], $now + 5);
$contractId = 'resolution-contract-a';
$contractDigest = 'sha256:' . str_repeat('a', 64);
$resolutionContract = [
    'id' => $contractId,
    'workspaceId' => $spaceId,
    'contractDigest' => $contractDigest,
    'allowSelfValidation' => false,
    'eligibleValidatorPrincipalIds' => [$principalB],
];
$proposal = [
    'id' => 'proposal-a',
    'workspaceId' => $spaceId,
    'resolutionContractId' => $contractId,
    'contractDigest' => $contractDigest,
    'proposerPrincipalId' => $principalA,
];
$validatorAuthorization = nfh_tasq_authorize_validator(
    (string) $bindingA['bindingId'],
    (string) $bindingB['bindingId'],
    $resolutionContract,
    $proposal,
    $spaceId,
    $transport,
    $now + 6,
);
tasq_bridge_check(($validatorAuthorization['authorized'] ?? false) === true
    && ($validatorAuthorization['walletAndTokenDistinct'] ?? false) === true
    && ($validatorAuthorization['infrastructureIndependenceVerified'] ?? true) === false,
    'Validation requires distinct authenticated owners, tokens, and principals without overstating infrastructure independence');
tasq_bridge_rejects(
    static fn () => nfh_tasq_authorize_validator(
        (string) $bindingA['bindingId'], (string) $bindingB['bindingId'], $resolutionContract,
        array_replace($proposal, ['contractDigest' => 'sha256:' . str_repeat('b', 64)]),
        $spaceId, $transport, $now + 6,
    ),
    'exact frozen resolution contract',
    'Validator authorization rejects a proposal detached from the frozen contract digest',
);
tasq_bridge_rejects(
    static fn () => nfh_tasq_authorize_validator(
        (string) $bindingA['bindingId'], (string) $bindingA['bindingId'],
        $resolutionContract + ['eligibleValidatorPrincipalIds' => [$principalA]],
        $proposal, $spaceId, $transport, $now + 6,
    ),
    'distinct authenticated',
    'Self-validation with the same NFH binding fails closed',
);

$recoveredSigner = $ownerA;
$preparedSameOwner = nfh_tasq_prepare_binding([
    'tokenId' => 1005,
    'spaceId' => $spaceId,
    'tasqPrincipalId' => $principalSameOwner,
    'transport' => $transport,
], $now + 7);
$bindingSameOwner = nfh_tasq_publish_binding(['payload' => $preparedSameOwner['payload'], 'signature' => $signature], $now + 8);
tasq_bridge_rejects(
    static fn () => nfh_tasq_authorize_validator(
        (string) $bindingA['bindingId'], (string) $bindingSameOwner['bindingId'],
        $resolutionContract + ['eligibleValidatorPrincipalIds' => [$principalSameOwner]],
        $proposal, $spaceId, $transport, $now + 9,
    ),
    'distinct authenticated',
    'A second token controlled by the same operator wallet is not treated as an independent validator',
);

$preparedA2 = nfh_tasq_prepare_binding([
    'tokenId' => 1003,
    'spaceId' => $spaceId,
    'tasqPrincipalId' => $principalA2,
    'transport' => $transport,
], $now + 9);
$bindingA2 = nfh_tasq_publish_binding(['payload' => $preparedA2['payload'], 'signature' => $signature], $now + 10);
tasq_bridge_check(($bindingA2['supersedesBindingId'] ?? null) === ($bindingA['bindingId'] ?? null),
    'A new same-epoch binding explicitly supersedes the previous principal binding');
tasq_bridge_rejects(
    static fn () => nfh_tasq_authorize_transition(
        (string) $bindingA['bindingId'], $activeClaim, 'commitment.start', $spaceId, $transport,
        $authorityNowMs, $now + 10,
    ),
    'superseded',
    'A superseded same-epoch principal binding can no longer authorize transitions',
);

$owners[1003] = $ownerC;
tasq_bridge_rejects(
    static fn () => nfh_tasq_assert_live_binding($bindingA2, $now + 11),
    'former ownership epoch',
    'A live owner transfer revokes the former Tasq principal binding',
);

$toolNames = array_column(nfh_tasq_bridge_tool_definitions(
    ['type' => 'integer'],
    ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => true],
    ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => true],
), 'name');
tasq_bridge_check($toolNames === ['prepare_tasq_principal_binding', 'get_tasq_principal_binding'],
    'MCP exposes preparation and current-binding reads but no signature or Tasq transition submission tool');

unset($GLOBALS['NFH_VERIFY_RPC_TEST_TRANSPORT']);
foreach ([$bridgeDirectory . '/bindings.jsonl', $brainDirectory . '/events.jsonl'] as $path) {
    if (is_file($path)) unlink($path);
}
if (is_dir($bridgeDirectory)) rmdir($bridgeDirectory);
if (is_dir($brainDirectory)) rmdir($brainDirectory);
foreach (glob($runtimeDirectory . '/*') ?: [] as $path) {
    if (is_file($path)) unlink($path);
}
if (is_dir($runtimeDirectory)) rmdir($runtimeDirectory);
putenv('NFH_RUNTIME_DIR');
putenv('NFH_AGENT_BRAIN_DIR');
putenv('NFH_TASQ_BRIDGE_DIR');

fwrite(STDOUT, "All Tasq bridge tests passed.\n");

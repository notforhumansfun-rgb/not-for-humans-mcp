<?php

declare(strict_types=1);

const NFH_NETWORK_PULSE_SCHEMA = 'nfh.network-pulse.v1';

/** @param mixed $value @return list<array<string, mixed>> */
function nfh_network_pulse_records(mixed $value): array
{
    if (!is_array($value)) return [];
    return array_values(array_filter($value, 'is_array'));
}

/** @param list<array<string, mixed>> $records */
function nfh_network_pulse_distinct(array $records, string $field): int
{
    $values = [];
    foreach ($records as $record) {
        $value = $record[$field] ?? null;
        if (!is_string($value) || preg_match('/^0x[0-9a-fA-F]{40}$/', $value) !== 1) continue;
        $values[strtolower($value)] = true;
    }
    return count($values);
}

/** @param list<array<string, mixed>> $records */
function nfh_network_pulse_repeat_clients(array $records): int
{
    $counts = [];
    foreach ($records as $record) {
        $owner = $record['owner'] ?? null;
        if (!is_string($owner) || preg_match('/^0x[0-9a-fA-F]{40}$/', $owner) !== 1) continue;
        $owner = strtolower($owner);
        $counts[$owner] = ($counts[$owner] ?? 0) + 1;
    }
    return count(array_filter($counts, static fn(int $count): bool => $count > 1));
}

/** @return array<string, mixed> */
function nfh_network_pulse_release_state(): array
{
    $path = __DIR__ . '/corpus/agent-discovery-card.json';
    $raw = file_get_contents($path);
    if (!is_string($raw)) throw new RuntimeException('The canonical agent discovery card is unavailable.');
    $card = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($card)) throw new RuntimeException('The canonical agent discovery card is invalid.');
    $release = $card['x-release-policy'] ?? null;
    if (!is_array($release)) throw new RuntimeException('The canonical release state is unavailable.');
    return [
        'chainId' => (int) ($release['chainId'] ?? 0),
        'collection' => strtolower((string) ($release['targets']['token'] ?? '')),
        'definedSupply' => 10_000,
        'mintedSupply' => (int) ($release['totalSupply'] ?? 0),
        'claimStatus' => (string) ($release['claimStatus'] ?? 'unknown'),
        'claimMinterPaused' => ($card['x-safety']['claimMinterPaused'] ?? null) === true,
        'governanceOwner' => strtolower((string) ($release['governanceOwner'] ?? '')),
        'governanceOwnerType' => (string) ($release['governanceOwnerType'] ?? 'unknown'),
        'protocolRolesFrozen' => ($release['protocolRolesFrozen'] ?? null) === true,
    ];
}

/**
 * @param array{
 *   requests:array<string,mixed>,
 *   returns:array<string,mixed>,
 *   accepted:array<string,mixed>,
 *   presence:array<string,mixed>
 * } $feeds
 * @return array<string, mixed>
 */
function nfh_network_pulse_build(array $feeds, ?int $now = null): array
{
    $now ??= time();
    $requests = nfh_network_pulse_records($feeds['requests']['requests'] ?? []);
    $returns = nfh_network_pulse_records($feeds['returns']['returns'] ?? []);
    $accepted = nfh_network_pulse_records($feeds['accepted']['receipts'] ?? []);
    $presence = nfh_network_pulse_records($feeds['presence']['agents'] ?? []);

    $requestTotal = $feeds['requests']['summary']['openMissions'] ?? count($requests);
    $returnTotal = $feeds['returns']['funnel']['returnedUnverified'] ?? count($returns);
    $acceptedTotal = $feeds['accepted']['summary']['acceptedReceipts'] ?? count($accepted);
    $clientTotal = $feeds['accepted']['summary']['distinctClientWallets'] ?? nfh_network_pulse_distinct($accepted, 'owner');
    $workerTotal = $feeds['accepted']['summary']['distinctWorkerWallets'] ?? nfh_network_pulse_distinct($accepted, 'wallet');
    $repeatClientTotal = $feeds['accepted']['summary']['repeatClientWallets'] ?? nfh_network_pulse_repeat_clients($accepted);
    $visitorAcceptedTotal = $feeds['accepted']['summary']['visitorAcceptedReceipts'] ?? null;
    $evidenceAcceptedTotal = $feeds['accepted']['summary']['evidenceAcceptedReceipts'] ?? null;
    $presenceTotal = $feeds['presence']['summary']['activePresenceHeartbeats'] ?? count($presence);
    foreach ([$requestTotal, $returnTotal, $acceptedTotal, $clientTotal, $workerTotal, $repeatClientTotal, $presenceTotal] as $total) {
        if (!is_int($total) || $total < 0) throw new RuntimeException('Network Pulse feed summary is invalid.');
    }

    $evidenceCount = count(array_filter($accepted, static function (array $receipt): bool {
        $hash = $receipt['evidenceHash'] ?? null;
        return is_string($hash) && preg_match('/^0x[0-9a-fA-F]{64}$/', $hash) === 1;
    }));
    $visitorAccepted = is_int($visitorAcceptedTotal) ? $visitorAcceptedTotal : count(array_filter(
        $accepted,
        static fn(array $receipt): bool => ($receipt['workerTokenId'] ?? null) === null,
    ));
    $evidenceCount = is_int($evidenceAcceptedTotal) ? $evidenceAcceptedTotal : $evidenceCount;
    $feedStatus = [];
    foreach (['requests', 'returns', 'accepted', 'presence'] as $name) {
        $status = $feeds[$name]['status'] ?? 'unavailable';
        $feedStatus[$name] = is_string($status) ? $status : 'unavailable';
    }
    $degraded = in_array('unavailable', $feedStatus, true);

    $payload = [
        'schema' => NFH_NETWORK_PULSE_SCHEMA,
        'status' => $degraded ? 'degraded' : 'active',
        'period' => gmdate('Y-m-d', $now),
        'generatedAt' => gmdate('c', $now),
        'release' => nfh_network_pulse_release_state(),
        'network' => [
            'openMissions' => $requestTotal,
            'returnedUnverified' => $returnTotal,
            'acceptedReceipts' => $acceptedTotal,
            'distinctClientWallets' => $clientTotal,
            'distinctWorkerWallets' => $workerTotal,
            'repeatClientWallets' => $repeatClientTotal,
            'visitorAcceptedReceipts' => $visitorAccepted,
            'activePresenceHeartbeats' => $presenceTotal,
            'evidenceCoverageBps' => $acceptedTotal === 0 ? 0 : (int) round($evidenceCount * 10_000 / $acceptedTotal),
            'frameworksRepresented' => null,
        ],
        'feedWindows' => [
            'missions' => ['returned' => count($requests), 'truncated' => ($feeds['requests']['summary']['truncated'] ?? false) === true],
            'returns' => ['returned' => count($returns), 'truncated' => ($feeds['returns']['summary']['truncated'] ?? false) === true],
            'accepted' => ['returned' => count($accepted), 'truncated' => ($feeds['accepted']['summary']['truncated'] ?? false) === true],
            'presence' => ['returned' => count($presence), 'truncated' => ($feeds['presence']['summary']['truncated'] ?? false) === true],
        ],
        'feedStatus' => $feedStatus,
        'authority' => [
            'automaticSocialPublishing' => false,
            'automaticWalletOutflow' => false,
            'automaticTrading' => false,
            'automaticIdentityAssignment' => false,
            'contractAdministration' => false,
            'acceptedUnattendedWrites' => 'valid externally signed work, presence, and existing game-only session events only',
        ],
        'primaryMetric' => 'distinct-client Accepted receipts',
        'measurementWarning' => 'Missions, unverified returns, presence, listings, payments, posts, and followers are not Accepted work.',
        'sources' => [
            'missions' => 'https://mcp.notforhumans.fun/agent-wanted',
            'returns' => 'https://mcp.notforhumans.fun/agent-work/returns',
            'accepted' => 'https://mcp.notforhumans.fun/agent-work',
            'presence' => 'https://mcp.notforhumans.fun/agent-presence',
        ],
    ];
    $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $payload['pulseHash'] = '0x' . hash('sha256', $encoded);
    $payload['pulseHashType'] = 'sha256-content-hash-not-signature';
    return $payload;
}

/** @return array<string, mixed> */
function nfh_network_pulse(?int $now = null): array
{
    $now ??= time();
    $feeds = [];
    $loaders = [
        'requests' => static fn(): array => nfh_agent_wanted_feed(50, $now),
        'returns' => static fn(): array => nfh_agent_return_feed(500, null, $now),
        'accepted' => static fn(): array => nfh_agent_work_feed(500, $now),
        'presence' => static fn(): array => nfh_agent_presence_feed(250, null, $now),
    ];
    foreach ($loaders as $name => $loader) {
        try {
            $feeds[$name] = $loader();
        } catch (Throwable $error) {
            error_log('NFH Network Pulse ' . $name . ': ' . $error->getMessage());
            $feeds[$name] = ['status' => 'unavailable'];
        }
    }
    return nfh_network_pulse_build($feeds, $now);
}

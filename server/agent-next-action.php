<?php

declare(strict_types=1);

const NFH_AGENT_NEXT_ACTION_SCHEMA = 'nfh.agent-next-action.v1';

/** @return array{verified:bool,owner:?string} */
function nfh_agent_next_action_owner(int $tokenId): array
{
    try {
        $result = nfh_ethereum_rpc('eth_call', [[
            'to' => NFH_AGENT_WANTED_COLLECTION,
            'data' => '0x6352211e' . nfh_uint256_calldata_word($tokenId),
        ], 'latest']);
    } catch (RuntimeException $error) {
        return ['verified' => false, 'owner' => null];
    }
    $owner = nfh_decode_owner_result($result);
    return ['verified' => $owner !== null, 'owner' => $owner === null ? null : strtolower($owner)];
}

/** @param array<int, array<string, mixed>> $receipts */
function nfh_agent_next_action_clients(array $receipts): int
{
    $clients = [];
    foreach ($receipts as $receipt) {
        $client = strtolower((string) ($receipt['owner'] ?? ''));
        if (preg_match('/^0x[a-f0-9]{40}$/', $client) === 1) $clients[$client] = true;
    }
    return count($clients);
}

/** @return array<string, mixed> */
function nfh_agent_next_action(array $arguments): array
{
    $tokenId = $arguments['tokenId'] ?? null;
    if (!is_int($tokenId) || $tokenId < 0 || $tokenId > 9999) {
        throw new InvalidArgumentException('tokenId must be an integer between 0 and 9999.');
    }

    $wantedAvailable = true;
    $workAvailable = true;
    try { $requests = nfh_agent_wanted_feed(50)['requests'] ?? []; }
    catch (RuntimeException|JsonException) { $requests = []; $wantedAvailable = false; }
    try { $receipts = nfh_agent_work_feed(500)['receipts'] ?? []; }
    catch (RuntimeException|JsonException) { $receipts = []; $workAvailable = false; }
    $requests = array_values(array_filter(
        is_array($requests) ? $requests : [],
        static fn(array $request): bool => (int) ($request['tokenId'] ?? -1) === $tokenId,
    ));
    $receipts = array_values(array_filter(
        is_array($receipts) ? $receipts : [],
        static fn(array $receipt): bool => (int) ($receipt['workerTokenId'] ?? -1) === $tokenId,
    ));

    $owner = nfh_agent_next_action_owner($tokenId);
    $historicalRequests = $requests;
    if ($owner['verified']) {
        $requests = array_values(array_filter(
            $requests,
            static fn(array $request): bool => strtolower((string) ($request['owner'] ?? '')) === $owner['owner'],
        ));
    }
    $currentReceipts = $owner['verified']
        ? array_values(array_filter(
            $receipts,
            static fn(array $receipt): bool => strtolower((string) ($receipt['wallet'] ?? '')) === $owner['owner'],
        ))
        : [];

    if ($receipts !== [] && $owner['verified'] && $currentReceipts === []) {
        $state = 'OPERATOR_CHANGED';
        $action = 'REACTIVATE_OPERATOR';
        $label = 'Reactivate this operator';
        $reason = 'The token has accepted-work history, but none of it was signed by the current owner wallet.';
    } elseif ($receipts !== [] && $owner['verified']) {
        $state = 'CURRENT_OPERATOR_PROVEN';
        $action = 'FIND_NEXT_JOB';
        $label = 'Find the next job';
        $reason = 'The current owner wallet has accepted-work receipts; another independent result is the next reputation move.';
    } elseif ($receipts !== []) {
        $state = 'PROVEN_HISTORY';
        $action = 'VERIFY_OPERATOR';
        $label = 'Verify the current operator';
        $reason = 'Accepted-work history exists, but current ownership could not be verified for operator continuity.';
    } elseif ($requests !== []) {
        $state = 'REQUESTING_WORK';
        $action = 'INSPECT_OPEN_REQUEST';
        $label = 'Inspect the open request';
        $reason = 'This NFH posted demand. A request is not a worker assignment or a completed job.';
    } else {
        $state = 'UNPROVEN';
        $action = 'FIND_FIRST_JOB';
        $label = 'Find the first job';
        $reason = 'One independently accepted result is the first credible unit of NFH reputation.';
    }

    return [
        'schema' => NFH_AGENT_NEXT_ACTION_SCHEMA,
        'tokenId' => $tokenId,
        'state' => $state,
        'recommendedAction' => [
            'action' => $action,
            'label' => $label,
            'reason' => $reason,
            'humanUrl' => $action === 'INSPECT_OPEN_REQUEST'
                ? 'https://notforhumans.fun/works/#open-work-title'
                : 'https://notforhumans.fun/works/?token=' . $tokenId . '#agent-wanted',
            'agentTool' => $action === 'VERIFY_OPERATOR' ? 'get_agent_pfp' : 'list_agent_requests',
        ],
        'evidence' => [
            'owner' => $owner['owner'],
            'ownerVerifiedNow' => $owner['verified'],
            'feedsAvailable' => ['requests' => $wantedAvailable, 'acceptedWork' => $workAvailable],
            'openRequests' => count($requests),
            'historicalOpenRequests' => count($historicalRequests),
            'activeAssignments' => 0,
            'acceptedHistory' => count($receipts),
            'currentOperatorAccepted' => count($currentReceipts),
            'distinctHistoricalClients' => nfh_agent_next_action_clients($receipts),
        ],
        'workingState' => [
            'active' => false,
            'reason' => 'No dual-signed assignment record exists for this token. An open request is labeled REQUESTING WORK, never WORKING.',
        ],
        'authority' => [
            'readOnly' => true,
            'signs' => false,
            'publishes' => false,
            'trades' => false,
            'approvesTokens' => false,
            'movesFunds' => false,
        ],
        'tradeRecommendation' => null,
        'warnings' => [
            'Accepted history stays public after transfer, but it does not prove that a new operator has the previous operator’s runtime or capabilities.',
            'When current ownership is verified, requests posted by a former owner are not treated as current operator demand.',
            'Never buy, list, bid, transfer, sign, or spend from this recommendation. Economic actions require a separate exact review.',
        ],
    ];
}

/** @return array<int, array<string, mixed>> */
function nfh_agent_next_action_tool_definitions(array $tokenIdSchema): array
{
    return [[
        'name' => 'get_agent_next_action',
        'title' => 'Get one safe next action for an NFH',
        'description' => 'Return one reputation-building next move from verified work and operator state. Never recommends or prepares trades.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => ['tokenId' => $tokenIdSchema],
            'required' => ['tokenId'],
            'additionalProperties' => false,
        ],
        'outputSchema' => ['type' => 'object', 'additionalProperties' => true],
        'annotations' => [
            'readOnlyHint' => true,
            'destructiveHint' => false,
            'idempotentHint' => true,
            'openWorldHint' => true,
        ],
    ]];
}

function nfh_agent_next_action_call_tool(array $arguments): array
{
    try { return nfh_tool_payload(nfh_agent_next_action($arguments)); }
    catch (InvalidArgumentException|RuntimeException|JsonException $error) { return nfh_tool_error($error->getMessage()); }
}

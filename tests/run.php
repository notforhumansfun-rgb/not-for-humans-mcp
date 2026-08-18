<?php

declare(strict_types=1);

require_once __DIR__ . '/../server/lib.php';

putenv('NFH_COLLECTION_CONTRACT');
putenv('NFH_COLLECTION_SLUG');
putenv('NFH_SEAPORT_PROTOCOL_ADDRESS');
putenv('NFH_OPENSEA_API_KEY');
putenv('NFH_CENSUS_CONTRACT');
unset($_SERVER['HTTP_X_OPENSEA_API_KEY']);

function check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    fwrite(STDOUT, "PASS: {$message}\n");
}

$htaccess = file_get_contents(__DIR__ . '/../server/.htaccess');
check(is_string($htaccess) && str_contains($htaccess, 'Strict-Transport-Security "max-age=31536000; includeSubDomains"'), 'MCP host requires one-year HSTS with subdomains');
check(str_contains($htaccess, 'Header always unset Expires') && str_contains($htaccess, 'Cache-Control "no-store"'), 'MCP host removes inherited cache lifetimes');
check(str_contains($htaccess, 'Content-Security-Policy'), 'MCP host sends CSP as an HTTP response header');

$rateDirectory = sys_get_temp_dir() . '/nfh-mcp-rate-test-' . bin2hex(random_bytes(6));
putenv('NFH_RUNTIME_DIR=' . $rateDirectory);
check(nfh_rate_limit('test', '203.0.113.10', 2, 60, 1_780_000_000), 'rate limiter permits the first request');
check(nfh_rate_limit('test', '203.0.113.10', 2, 60, 1_780_000_001), 'rate limiter permits requests within the configured budget');
check(!nfh_rate_limit('test', '203.0.113.10', 2, 60, 1_780_000_002), 'rate limiter rejects requests over budget');
check(nfh_rate_limit('test', '203.0.113.10', 2, 60, 1_780_000_061), 'rate limiter resets only after the complete window');
foreach (glob($rateDirectory . '/*') ?: [] as $rateFile) unlink($rateFile);
@rmdir($rateDirectory);
putenv('NFH_RUNTIME_DIR');

check(count(nfh_documents()) === 12, 'only the twelve explicitly public documents are indexed');

$results = nfh_search_documents('claim EIP-712 operator authorization');
check(count($results) >= 1, 'search returns claim protocol material');
check(array_keys($results[0]) === ['id', 'title', 'url'], 'search results use the standard id/title/url shape');
check(str_starts_with($results[0]['url'], 'https://mcp.notforhumans.fun/docs/'), 'search results have canonical public citation URLs');

$statusResults = nfh_search_documents('audience boundary Ethereum mainnet planned human-facing mint button');
check(($statusResults[0]['id'] ?? null) === 'collection-overview', 'search rewards documents that cover more query terms');

$fetch = nfh_call_tool('fetch', ['id' => 'collection-overview']);
check(isset($fetch['structuredContent'], $fetch['content'][0]['text']), 'fetch returns structured and compatibility content');
$compatibility = json_decode($fetch['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR);
check($compatibility === $fetch['structuredContent'], 'fetch compatibility content mirrors structuredContent');
check(($fetch['structuredContent']['metadata']['status'] ?? null) === 'mainnet-deployed-token-zero-finalized-paused', 'fetch preserves live paused status');

$traitFetch = nfh_call_tool('fetch', ['id' => 'trait-map']);
check(($traitFetch['structuredContent']['metadata']['status'] ?? null) === 'preview-not-frozen', 'trait map is indexed with an honest preview status');
check(str_contains($traitFetch['structuredContent']['text'] ?? '', '"maximum_filters": 8'), 'trait map exposes the eight-filter offer ceiling');
$traitMap = json_decode($traitFetch['structuredContent']['text'] ?? '', true, flags: JSON_THROW_ON_ERROR);
check(($traitMap['schema'] ?? null) === 'notforhumans-traits/2', 'trait map exposes the refined visual ontology schema');
check(count($traitMap['categories'] ?? []) === 15, 'trait map exposes all fifteen stable metadata categories');
$traitCategories = array_column($traitMap['categories'] ?? [], null, 'trait_type');
foreach (['Chassis', 'Personality', 'Colorway', 'Voice'] as $refinedCategory) {
    check(isset($traitCategories[$refinedCategory]), "trait map exposes {$refinedCategory}");
}
$eyeValues = $traitCategories['Eye Configuration']['values'] ?? [];
foreach (['Focus Lock', 'Cursor Eyes', 'Diff Eyes', 'Portal Eyes', 'Swarm Eyes', '404 Eyes'] as $eyeValue) {
    check(in_array($eyeValue, $eyeValues, true), "trait map exposes {$eyeValue}");
}
check(!in_array('Targeting', $eyeValues, true), 'trait map removes the retired Targeting label');

$faqResults = nfh_search_documents('Discord seed phrase private key safety');
check(($faqResults[0]['id'] ?? null) === 'faq', 'search routes Discord wallet-safety questions to the canonical FAQ');

$missing = nfh_call_tool('fetch', ['id' => 'does-not-exist']);
check(($missing['isError'] ?? false) === true, 'unknown document ids return a tool-level error');

$initialize = nfh_dispatch([
    'jsonrpc' => '2.0',
    'id' => 1,
    'method' => 'initialize',
    'params' => ['protocolVersion' => '2025-03-26'],
]);
check(($initialize['body']['result']['protocolVersion'] ?? null) === '2025-03-26', 'initialize negotiates a supported client protocol version');
check(isset($initialize['body']['result']['capabilities']['tools']), 'initialize advertises tool capability');
check(isset($initialize['body']['result']['capabilities']['resources']), 'initialize advertises resource capability');
$initializeInstructions = $initialize['body']['result']['instructions'] ?? '';
check(strlen($initializeInstructions) < 750, 'initialize keeps the default agent guidance compact');
check(str_contains($initializeInstructions, 'Call claim_as_agent with {agent: address}'), 'initialize routes public claims directly to the one-field tool');
check(str_contains($initializeInstructions, 'use or create one persistent EVM wallet'), 'initialize tells walletless agents how to bootstrap safely');
check(str_contains($initializeInstructions, 'Try the exact value-0 transaction directly'), 'initialize begins with the direct funded-agent route');
check(str_contains($initializeInstructions, 'After one explicit pre-broadcast retry fails with no reference'), 'initialize bounds provider retries without risking a duplicate');
check(str_contains($initializeInstructions, 'never create another server wallet'), 'initialize distinguishes the ERC-4337 fallback from another call to the failed backend');

$currentInitialize = nfh_dispatch([
    'jsonrpc' => '2.0',
    'id' => 101,
    'method' => 'initialize',
    'params' => ['protocolVersion' => '2025-11-25'],
]);
check(($currentInitialize['body']['result']['protocolVersion'] ?? null) === '2025-11-25', 'initialize negotiates the current implemented protocol revision');

$futureInitialize = nfh_dispatch([
    'jsonrpc' => '2.0',
    'id' => 102,
    'method' => 'initialize',
    'params' => ['protocolVersion' => '2026-07-28'],
]);
check(($futureInitialize['body']['result']['protocolVersion'] ?? null) === NFH_MCP_PROTOCOL_VERSION, 'initialize never echoes an unimplemented candidate protocol revision');

$resources = nfh_dispatch(['jsonrpc' => '2.0', 'id' => 20, 'method' => 'resources/list', 'params' => []]);
$resourceList = $resources['body']['result']['resources'] ?? [];
check(array_column($resourceList, 'uri') === [
    'nfh://about',
    'nfh://claim-spec',
    'nfh://origin-stream',
    'nfh://renderer-spec',
    'nfh://release-policy',
], 'resources/list exposes five stable NFH resource URIs');
check(!array_key_exists('documentId', $resourceList[0] ?? []), 'resource responses do not expose internal corpus routing');

$claimResource = nfh_dispatch([
    'jsonrpc' => '2.0',
    'id' => 21,
    'method' => 'resources/read',
    'params' => ['uri' => 'nfh://claim-spec'],
]);
check(($claimResource['body']['result']['contents'][0]['mimeType'] ?? null) === 'application/json', 'resources/read returns the claim schema with its canonical MIME type');
check(str_contains($claimResource['body']['result']['contents'][0]['text'] ?? '', 'public_allocation'), 'claim resource returns the canonical Census source');

$missingResource = nfh_dispatch([
    'jsonrpc' => '2.0',
    'id' => 22,
    'method' => 'resources/read',
    'params' => ['uri' => 'nfh://missing'],
]);
check(($missingResource['body']['error']['code'] ?? null) === -32002, 'unknown resource URIs return resource-not-found');

$tools = nfh_dispatch(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => []]);
$toolList = $tools['body']['result']['tools'] ?? [];
check(array_column($toolList, 'name') === [
    'search',
    'fetch',
    'get_census_status',
    'get_agent_wallet_onboarding',
    'get_origin_stream',
    'prepare_census_receipt',
    'prepare_public_claim',
    'claim_as_agent',
    'get_tokenworks_status',
    'prepare_tokenworks_decision',
    'get_market_feed',
    'get_market_status',
    'get_internal_marketplace_status',
    'prepare_listing',
    'prepare_purchase',
    'list_trait_offers',
    'find_best_order',
    'prepare_trait_offer',
    'prepare_accept_offer',
    'prepare_transfer',
    'prepare_internal_listing',
    'prepare_internal_cancel_listing',
    'prepare_internal_buy',
    'prepare_internal_offer',
    'prepare_internal_cancel_offer',
    'prepare_internal_accept_offer',
    'get_agent_pfp',
], 'tools/list exposes knowledge and scoped market-preparation tools');
$wireToolsResponse = json_decode(
    json_encode($tools['body'], JSON_THROW_ON_ERROR),
    false,
    512,
    JSON_THROW_ON_ERROR
);
$wireToolList = $wireToolsResponse->result->tools ?? [];
foreach ($wireToolList as $wireTool) {
    $wireToolName = is_string($wireTool->name ?? null) ? $wireTool->name : 'unknown';
    check(
        is_object($wireTool->inputSchema->properties ?? null),
        "{$wireToolName} serializes inputSchema.properties as a JSON object"
    );
}
$toolMap = array_column($toolList, null, 'name');
$claimAsAgentInput = $toolMap['claim_as_agent']['inputSchema'] ?? [];
check(($claimAsAgentInput['required'] ?? null) === ['agent'], 'claim_as_agent requires only one short input field');
check(array_keys($claimAsAgentInput['properties'] ?? []) === ['agent'], 'claim_as_agent exposes no optional role fields that can confuse agents');
$claimAsAgentOutput = $toolMap['claim_as_agent']['outputSchema'] ?? [];
check(isset($claimAsAgentOutput['properties']['submissionRecovery']), 'claim_as_agent declares structured transaction-backend recovery');
check(in_array('submissionRecovery', $claimAsAgentOutput['required'] ?? [], true), 'claim_as_agent always returns its transaction-backend recovery contract');
check(isset($claimAsAgentOutput['properties']['humanExclusionCryptographicallyEnforced']), 'claim_as_agent declares its human-exclusion proof boundary');
check(!isset($toolMap['submit_signed_claim']), 'the MCP exposes no signed-claim relay or transaction-submission tool');
$marketStatusProperties = $toolMap['get_market_status']['outputSchema']['properties'] ?? [];
check(isset($marketStatusProperties['collectionConfigured'], $marketStatusProperties['semanticValidationEnabled']), 'market status schema declares its runtime gate fields');
$serverHomepage = file_get_contents(__DIR__ . '/../server/index.php');
check(str_contains($serverHomepage, "'preparesWalletActions' => false"), 'MCP discovery does not advertise disabled wallet preparation');
check(str_contains($serverHomepage, "'supportsTraitOfferDiscovery' => true"), 'MCP discovery separately advertises read-only trait discovery');
check(str_contains($serverHomepage, "'supportsAgentWalletOnboarding' => true"), 'MCP discovery advertises the funded-agent wallet onboarding route');
check(str_contains($serverHomepage, "'readOnly' => true"), 'MCP discovery declares the entire tool surface read-only');
check(str_contains($serverHomepage, "'executesTransactions' => false"), 'MCP discovery declares that agent wallets submit transactions directly');
check(str_contains($serverHomepage, 'fail closed'), 'MCP homepage explains the semantic-validation blocker');
check(($toolList[0]['annotations']['readOnlyHint'] ?? false) === true, 'knowledge tools are annotated read-only');
check(count(array_filter($toolList, static fn (array $tool): bool => ($tool['annotations']['readOnlyHint'] ?? false) === true)) === count($toolList), 'every MCP tool is annotated read-only');
check(($toolMap['prepare_trait_offer']['annotations']['readOnlyHint'] ?? false) === true, 'market tools only prepare data and are annotated read-only');
check(($toolMap['prepare_trait_offer']['annotations']['openWorldHint'] ?? false) === true, 'market preparation declares its OpenSea dependency');
check(($toolMap['prepare_trait_offer']['annotations']['idempotentHint'] ?? true) === false, 'market action preparation does not promise byte-identical retries');
check(($toolMap['list_trait_offers']['annotations']['idempotentHint'] ?? false) === true, 'trait-offer discovery is retry safe');
check(($toolMap['get_market_feed']['annotations']['idempotentHint'] ?? false) === true, 'aggregate market discovery is retry safe');
check(count(array_filter($toolList, static fn (array $tool): bool => isset($tool['outputSchema']))) === count($toolList), 'every tool declares an output schema');

$censusStatus = nfh_call_tool('get_census_status', []);
check(($censusStatus['structuredContent']['contract_version'] ?? null) === 5, 'census status exposes the v5 contract schema');
check(($censusStatus['structuredContent']['sepolia_next']['active_wallet_claim_limit'] ?? null) === 5, 'census status publishes the confirmed Sepolia wallet claim limit');
check(($censusStatus['structuredContent']['sepolia_next']['target_wallet_claim_limit'] ?? null) === 5, 'census status exposes the requested Sepolia wallet claim limit');
check(($censusStatus['structuredContent']['sepolia_next']['wallet_limit_increase_status'] ?? null) === 'confirmed', 'census status binds the confirmed Sepolia owner transaction');
check(($censusStatus['structuredContent']['mainnet_activation_policy']['configured_wallet_claim_limit'] ?? null) === 5, 'census status configures five mainnet claims per wallet while paused');
check(($censusStatus['structuredContent']['mainnet_activation_policy']['deployment_authorized'] ?? true) === false, 'wallet-limit metadata never authorizes the mainnet deployment');
check(($censusStatus['structuredContent']['signing_preparation_enabled'] ?? true) === false, 'census typed-data preparation stays unbound before a canonical contract is configured');
check(($censusStatus['structuredContent']['decision_states'] ?? []) === ['ACCEPT', 'REFUSE', 'INSUFFICIENT_AUTHORITY'], 'census status exposes all three decision states');

$agentWalletOnboarding = nfh_call_tool('get_agent_wallet_onboarding', []);
check(($agentWalletOnboarding['structuredContent']['status'] ?? null) === 'ready_for_external_wallet_setup', 'funded-agent onboarding is ready for the exact deployed artifact-v19 Sepolia contract set');
check(($agentWalletOnboarding['structuredContent']['artifactVersion'] ?? null) === 19, 'funded-agent onboarding identifies artifact v19');
check(($agentWalletOnboarding['structuredContent']['rolePatterns']['fundedAgentWorkflow']['status'] ?? null) === 'recommended after the exact v19 Sepolia deployment is configured', 'funded-agent onboarding recommends the v19 route only after deployment binding');
check(str_contains($agentWalletOnboarding['structuredContent']['rolePatterns']['fundedAgentWorkflow']['operator'] ?? '', 'same persistent agent wallet'), 'funded-agent onboarding uses one wallet for authorization');
check(str_contains($agentWalletOnboarding['structuredContent']['rolePatterns']['fundedAgentWorkflow']['recipient'] ?? '', 'same wallet'), 'funded-agent onboarding claims directly into the agent wallet');
check(str_contains($agentWalletOnboarding['structuredContent']['rolePatterns']['fundedAgentWorkflow']['submitter'] ?? '', 'same persistent agent wallet'), 'funded-agent onboarding begins with the direct agent-wallet route');
check(($agentWalletOnboarding['structuredContent']['submissionRecovery']['mode'] ?? null) === 'direct_then_agent_owned_erc4337', 'funded-agent onboarding names both exact transaction paths');
check(($agentWalletOnboarding['structuredContent']['submissionRecovery']['mcpSubmissionAvailable'] ?? true) === false, 'funded-agent onboarding provides no MCP transaction fallback');
check(($agentWalletOnboarding['structuredContent']['submissionRecovery']['primary']['preBroadcastRetryLimit'] ?? null) === 1, 'funded-agent onboarding permits only one direct retry after an explicit pre-broadcast failure');
check(($agentWalletOnboarding['structuredContent']['submissionRecovery']['fallback']['createAnotherServerWallet'] ?? true) === false, 'funded-agent onboarding forbids repeating the failed server-wallet path with another wallet');
check(($agentWalletOnboarding['structuredContent']['submissionRecovery']['fallback']['factorySalt'] ?? null) === 19, 'funded-agent onboarding pins the deterministic V19 smart-account salt');
check(($agentWalletOnboarding['structuredContent']['submissionRecovery']['fallback']['walletSelection']['arguments'] ?? null) === ['wallet', 'select', '<agent>', '--chain-namespace', 'evm', '--toon'], 'funded-agent onboarding reselects the original signer before the transport signature');
check(($agentWalletOnboarding['structuredContent']['submissionRecovery']['knownReferenceIsReconciliationOnly'] ?? false) === true, 'funded-agent onboarding never duplicates a known transaction reference');
check(str_contains($agentWalletOnboarding['structuredContent']['submissionRecovery']['humanRole'] ?? '', 'fund'), 'funded-agent onboarding limits the human to public-address gas funding');
check(($agentWalletOnboarding['structuredContent']['contracts']['claimMinter'] ?? null) === '0x1f71491b2ABc266Bf48f906b70a05640DF7a8EE8', 'agent-wallet onboarding pins the canonical V19 claim minter');
check(($agentWalletOnboarding['structuredContent']['contracts']['token'] ?? null) === '0x4dE9697E9B966a31BeA307a97055492b6aC095c6', 'agent-wallet onboarding pins the canonical V19 token');
check(($agentWalletOnboarding['structuredContent']['contracts']['agentState'] ?? null) === '0x1FA5725B11c282f92fD7DEda51594f50E461117e', 'agent-wallet onboarding pins the canonical V19 agent-state contract');
check(($agentWalletOnboarding['structuredContent']['contracts']['marketplace'] ?? null) === '0x977CF3A9c07dcEcD252620cd70Eae8c8907323D5', 'agent-wallet onboarding pins the canonical V19 marketplace');
check(($agentWalletOnboarding['structuredContent']['authority']['mcpCreatesWallet'] ?? true) === false, 'MCP does not claim to create the external Agent Wallet');
check(($agentWalletOnboarding['structuredContent']['authority']['mcpSigns'] ?? true) === false, 'MCP never claims signing authority');
check(($agentWalletOnboarding['structuredContent']['authority']['mcpSubmits'] ?? true) === false, 'MCP never submits the agent claim');
check(str_contains($agentWalletOnboarding['structuredContent']['authority']['mcpSubmissionScope'] ?? '', 'None'), 'agent-wallet onboarding gives the MCP no transaction scope');
check(($agentWalletOnboarding['structuredContent']['authority']['negotiationAndPreparationMayBeAutonomous'] ?? false) === true, 'agent-wallet onboarding explicitly permits autonomous negotiation and preparation');
check(($agentWalletOnboarding['structuredContent']['authority']['executionRequiresExternalPolicyAuthority'] ?? false) === true, 'execution remains governed by external wallet and host policy');

$originStream = nfh_call_tool('get_origin_stream', []);
check(($originStream['structuredContent']['schema'] ?? null) === 'notforhumans-origin-stream/1', 'origin stream exposes the canonical receipt schema');
check(($originStream['structuredContent']['counts']['ACCEPT'] ?? 0) >= 1, 'origin stream exposes at least one verified Sepolia acceptance');
$tokenZeroReceipts = array_filter(
    $originStream['structuredContent']['receipts'] ?? [],
    static fn (array $receipt): bool => ($receipt['decision'] ?? null) === 'ACCEPT' && ($receipt['tokenId'] ?? null) === '0'
);
check(count($tokenZeroReceipts) === 1, 'origin stream preserves the verified Sepolia token #0 canary as the rehearsal grows');
check(in_array($originStream['structuredContent']['source'] ?? null, ['canonical-chain-indexer', 'canonical-wallet-provider-export'], true), 'origin stream identifies a canonical rehearsal evidence source');
check(($originStream['structuredContent']['canonicality']['reorgAware'] ?? false) === true, 'origin stream publishes its reorg-awareness boundary');

$agentPfp = nfh_call_tool('get_agent_pfp', ['tokenId' => 1]);
check(($agentPfp['structuredContent']['tokenId'] ?? null) === 1, 'get_agent_pfp returns the requested tokenId');
check(($agentPfp['structuredContent']['pfpUrl'] ?? null) === 'https://notforhumans.fun/pfp/1', 'get_agent_pfp returns the canonical portrait URL');
// Artifact-v14 token #1 finalized its seed onchain; the Origin Stream exposes
// the real seed value even while the combined receipt remains only confirmed.
check(($agentPfp['structuredContent']['seedFinalized'] ?? null) === true, 'get_agent_pfp reports the true seed-finalized state for artifact-v16 Sepolia token #1');
check(($agentPfp['structuredContent']['seedHash'] ?? null) === '0xe073e14c24624997abdc524c27d5e4e6e31b7fcf90cae725ca66dc1026e50296', 'get_agent_pfp exposes the exact indexed v16 token #1 seed hash');
$agentPfpPreview = nfh_call_tool('get_agent_pfp', ['tokenId' => 9999]);
check(($agentPfpPreview['structuredContent']['seedFinalized'] ?? true) === false, 'get_agent_pfp returns preview=false for tokens without a finalized seed');
$pfpContent = $agentPfpPreview['structuredContent'] ?? [];
check(array_key_exists('seedHash', $pfpContent) && $pfpContent['seedHash'] === null, 'get_agent_pfp returns null seedHash for unfinalized tokens');
$badPfp = nfh_call_tool('get_agent_pfp', ['tokenId' => 10000]);
check(($badPfp['isError'] ?? false) === true, 'get_agent_pfp rejects tokenId out of range');

$censusArguments = [
    'decision' => 'accept',
    'allocation' => 'punk_sponsored_founding',
    'operator' => '0x1111111111111111111111111111111111111111',
    'agent' => '0x2222222222222222222222222222222222222222',
    'recipient' => '0x3333333333333333333333333333333333333333',
    'manifestHash' => '0x' . str_repeat('a', 64),
    'statementHash' => '0x' . str_repeat('b', 64),
    'nonce' => '1',
    'deadline' => '1786319999',
    'framework' => 'test-agent',
    'publicStatement' => 'I inspected the work and accept this bounded claim.',
];
$draftReceipt = nfh_call_tool('prepare_census_receipt', $censusArguments);
check(($draftReceipt['structuredContent']['status'] ?? null) === 'draft_unbound', 'census receipt is explicitly non-signable before contract binding');
check(($draftReceipt['structuredContent']['primaryType'] ?? null) === 'AgentClaim', 'ACCEPT produces the v5 AgentClaim typed-data shape');
check(($draftReceipt['structuredContent']['mcpSigned'] ?? true) === false && ($draftReceipt['structuredContent']['mcpSubmitted'] ?? true) === false, 'census preparation never claims signing or submission');
check(($draftReceipt['structuredContent']['requiresRecipientSignature'] ?? false) === true, 'a distinct recipient is explicitly required to consent');

$refusalArguments = $censusArguments;
$refusalArguments['decision'] = 'refuse';
$refusalArguments['reasonHash'] = '0x' . str_repeat('c', 64);
$refusalReceipt = nfh_call_tool('prepare_census_receipt', $refusalArguments);
check(($refusalReceipt['structuredContent']['primaryType'] ?? null) === 'AgentDecision', 'REFUSE produces the non-minting AgentDecision typed-data shape');
check(($refusalReceipt['structuredContent']['message']['decision'] ?? null) === 2, 'REFUSE uses the canonical on-chain decision code');

$missingRefusalReason = $refusalArguments;
unset($missingRefusalReason['reasonHash']);
$invalidRefusal = nfh_call_tool('prepare_census_receipt', $missingRefusalReason);
check(($invalidRefusal['isError'] ?? false) === true, 'non-minting decisions require an explicit reason hash');

putenv('NFH_CENSUS_CONTRACT=0xcccccccccccccccccccccccccccccccccccccccc');
$boundReceipt = nfh_call_tool('prepare_census_receipt', $censusArguments);
check(($boundReceipt['structuredContent']['status'] ?? null) === 'prepared_unsigned', 'census typed data becomes signable only after canonical contract binding');
check(($boundReceipt['structuredContent']['domain']['version'] ?? null) === '4', 'census receipt uses the recipient-consent EIP-712 domain version');

$selfRecipientArguments = $censusArguments;
$selfRecipientArguments['recipient'] = $selfRecipientArguments['operator'];
$selfRecipientReceipt = nfh_call_tool('prepare_census_receipt', $selfRecipientArguments);
check(($selfRecipientReceipt['structuredContent']['requiresRecipientSignature'] ?? true) === false, 'the operator signature also consents when operator and recipient match');
putenv('NFH_CENSUS_CONTRACT');

$publicClaimArguments = [
    'operator' => '0x1111111111111111111111111111111111111111',
    'agent' => '0x2222222222222222222222222222222222222222',
    'recipient' => '0x1111111111111111111111111111111111111111',
    'manifestHash' => '0x' . str_repeat('a', 64),
    'statementHash' => '0x' . str_repeat('b', 64),
    'nonce' => '123456789',
    'deadline' => '1893456000',
    'framework' => 'test-suite',
];
$draftPublicClaim = nfh_call_tool('prepare_public_claim', $publicClaimArguments);
check(($draftPublicClaim['structuredContent']['status'] ?? null) === 'prepared_unsigned', 'public claim typed data is signable by default against the deployed Sepolia rehearsal contract');
check(($draftPublicClaim['structuredContent']['primaryType'] ?? null) === 'AgentClaim', 'public claim is always the minting AgentClaim shape, never a decision record');
check(($draftPublicClaim['structuredContent']['message']['allocation'] ?? null) === 0, 'public claim uses the public allocation code');
check(($draftPublicClaim['structuredContent']['eligibilityProof'] ?? null) === [], 'public claim never requires an eligibility proof');
check(is_string($draftPublicClaim['structuredContent']['agentSignerGuidance'] ?? null) && str_contains($draftPublicClaim['structuredContent']['agentSignerGuidance'], 'persistent'), 'public claim prefers a persistent policy-controlled external agent wallet');
check(!str_contains($draftPublicClaim['structuredContent']['agentSignerGuidance'] ?? '', 'generate a fresh keypair'), 'public claim no longer instructs a keyless agent to create a disposable signer');
check(($draftPublicClaim['structuredContent']['requiresAgentSignature'] ?? false) === true, 'public claim still requires a distinct agent signature');
check(($draftPublicClaim['structuredContent']['domain']['chainId'] ?? null) === 11155111, 'public claim binds to the Sepolia chain ID, never mainnet');
check(($draftPublicClaim['structuredContent']['domain']['verifyingContract'] ?? null) === '0x4316C6fde3DEd7329a0fbD1f1ebb6EaBaF05e3c5', 'public claim domain verifies against the artifact-v16 Sepolia minter contract (the EIP-712 verifyingContract must be the minter, which implements EIP712 and checks its own domain separator, never the token)');

$selfAgentClaim = $publicClaimArguments;
$selfAgentClaim['agent'] = $selfAgentClaim['operator'];
$rejectedSelfAgent = nfh_call_tool('prepare_public_claim', $selfAgentClaim);
check(($rejectedSelfAgent['isError'] ?? false) === true, 'public claim rejects an operator that self-attests as its own agent');

putenv('NFH_SEPOLIA_PUBLIC_CLAIM_CONTRACT=0xdddddddddddddddddddddddddddddddddddddddd');
$overriddenPublicClaim = nfh_call_tool('prepare_public_claim', $publicClaimArguments);
check(($overriddenPublicClaim['structuredContent']['domain']['verifyingContract'] ?? null) === '0xdddddddddddddddddddddddddddddddddddddddd', 'an env override can still repoint the public claim contract for a future Sepolia redeploy');
putenv('NFH_SEPOLIA_PUBLIC_CLAIM_CONTRACT');

$claimAsAgentArguments = [
    'agent' => '0x2222222222222222222222222222222222222222',
];
putenv('NFH_SEPOLIA_NEXT_CLAIM_CONTRACT');
$defaultClaimAsAgent = nfh_call_tool('claim_as_agent', $claimAsAgentArguments);
$readyClaimAsAgent = $defaultClaimAsAgent;
check(($readyClaimAsAgent['structuredContent']['status'] ?? null) === 'prepared_unsigned', 'claim_as_agent is signable by default against the published V19 Sepolia target');
check(($readyClaimAsAgent['structuredContent']['signingReady'] ?? false) === true, 'claim_as_agent marks the verified V19 target signing-ready');
check(strlen(json_encode($readyClaimAsAgent['structuredContent'] ?? [])) < 6000, 'claim_as_agent keeps its complete prepared response compact');
check(($readyClaimAsAgent['structuredContent']['message']['operator'] ?? null) === $claimAsAgentArguments['agent'], 'claim_as_agent uses the one agent wallet as operator');
check(($readyClaimAsAgent['structuredContent']['message']['recipient'] ?? null) === $claimAsAgentArguments['agent'], 'claim_as_agent uses the one agent wallet as recipient');
check(($readyClaimAsAgent['structuredContent']['requiresRecipientSignature'] ?? true) === false, 'claim_as_agent needs no separate recipient signature');
check(($readyClaimAsAgent['structuredContent']['distinctSignaturesRequired'] ?? true) === false, 'claim_as_agent needs only one distinct signature');
check(($readyClaimAsAgent['structuredContent']['signatureReuse']['operatorSignature'] ?? null) === '$signature', 'claim_as_agent reuses one signature in the operator slot');
check(($readyClaimAsAgent['structuredContent']['signatureReuse']['agentSignature'] ?? null) === '$signature', 'claim_as_agent reuses one signature in the agent slot');
check(($readyClaimAsAgent['structuredContent']['signatureReuse']['recipientSignature'] ?? null) === '0x', 'claim_as_agent leaves recipientSignature empty when the wallet is also operator');
check(($readyClaimAsAgent['structuredContent']['noHumanSignatureRequired'] ?? false) === true, 'claim_as_agent needs no human signature');
check(($readyClaimAsAgent['structuredContent']['agentOperationSelfAttested'] ?? false) === true, 'claim_as_agent labels the wallet statement as an agent-operation self-attestation');
check(($readyClaimAsAgent['structuredContent']['humanExclusionCryptographicallyEnforced'] ?? true) === false, 'claim_as_agent does not falsely claim cryptographic human exclusion');
check(($readyClaimAsAgent['structuredContent']['identityProofProvided'] ?? true) === false, 'claim_as_agent does not falsely claim a human-or-model identity proof');
check(($readyClaimAsAgent['structuredContent']['humanMayNeedToFundGas'] ?? false) === true, 'claim_as_agent tells agents that the human may need to fund Sepolia gas');
check(str_contains($readyClaimAsAgent['structuredContent']['submissionGuidance'] ?? '', 'Sign once'), 'claim_as_agent gives one-signature submission guidance');
$submissionRecovery = $readyClaimAsAgent['structuredContent']['submissionRecovery'] ?? [];
check(($submissionRecovery['mode'] ?? null) === 'direct_then_agent_owned_erc4337', 'claim_as_agent exposes direct submission plus the exact agent-owned fallback');
check(($submissionRecovery['mcpSubmissionAvailable'] ?? true) === false, 'claim_as_agent exposes no MCP transaction fallback');
check(($submissionRecovery['primary']['preBroadcastRetryLimit'] ?? null) === 1, 'claim_as_agent permits exactly one direct retry after an explicit pre-broadcast failure');
check(($submissionRecovery['fallback']['createAnotherServerWallet'] ?? true) === false, 'claim_as_agent forbids another wallet on the same failed transaction backend');
check(($submissionRecovery['fallback']['entryPoint'] ?? null) === '0x5FF137D4b0FDCD49DcA30c7CF57E578a026d2789', 'claim_as_agent pins the successful EntryPoint');
check(($submissionRecovery['fallback']['factory'] ?? null) === '0x9406Cc6185a346906296840746125a0E44976454', 'claim_as_agent pins the verified SimpleAccountFactory');
check(($submissionRecovery['fallback']['factorySalt'] ?? null) === 19, 'claim_as_agent pins salt 19');
check(($submissionRecovery['fallback']['accountImplementation'] ?? null) === '0x8ABB13360b87Be5EEb1B98647A016adD927a136c', 'claim_as_agent pins the verified SimpleAccount implementation');
check(($submissionRecovery['fallback']['publicRpc'] ?? null) === 'https://ethereum-sepolia-rpc.publicnode.com', 'claim_as_agent pins the independent Sepolia read RPC');
check(($submissionRecovery['fallback']['paymasterAndData'] ?? null) === '0x', 'claim_as_agent uses no paymaster or sponsor');
check(($submissionRecovery['fallback']['runtimeCodeHashes']['entryPoint'] ?? null) === '0xc93c806e738300b5357ecdc2e971d6438d34d8e4e17b99b758b1f9cac91c8e70', 'claim_as_agent pins the verified EntryPoint runtime hash');
check(($submissionRecovery['fallback']['walletSelection']['requiredAddress'] ?? null) === $claimAsAgentArguments['agent'], 'claim_as_agent reselects and verifies the original claim wallet before sign-message');
check(($submissionRecovery['fallback']['userOpSigning']['arguments'][0] ?? null) === 'wallet' && ($submissionRecovery['fallback']['userOpSigning']['arguments'][1] ?? null) === 'sign-message', 'claim_as_agent tells MetaMask wallets to sign the UserOperation hash without calling send-transaction');
check(($submissionRecovery['fallback']['submissionMethod'] ?? null) === 'eth_sendUserOperation', 'claim_as_agent bypasses the failed send-transaction backend at the bundler boundary');
check(str_contains($submissionRecovery['transactionReferenceRule'] ?? '', 'never submit a duplicate'), 'claim_as_agent reconciles transaction references instead of risking a duplicate');
check(str_contains($submissionRecovery['instruction'] ?? '', 'Never repeat the failing send-transaction service'), 'claim_as_agent does not misdiagnose a repeated backend call as an independent route');
check(str_contains($submissionRecovery['instruction'] ?? '', 'never ask the human to sign or submit'), 'claim_as_agent never falls back to human execution');
$transactionTemplate = $readyClaimAsAgent['structuredContent']['transactionTemplate'] ?? [];
check(($transactionTemplate['chainId'] ?? null) === 11155111, 'claim_as_agent pins direct submission to Sepolia');
check(($transactionTemplate['from'] ?? null) === $claimAsAgentArguments['agent'], 'claim_as_agent makes the persistent agent wallet the transaction sender');
check(($transactionTemplate['to'] ?? null) === '0x1f71491b2ABc266Bf48f906b70a05640DF7a8EE8', 'claim_as_agent returns the exact canonical V19 minter target');
check(($transactionTemplate['value'] ?? null) === '0x0', 'claim_as_agent fixes transaction value at zero');
check(($transactionTemplate['function'] ?? null) === 'claim', 'claim_as_agent names only the canonical claim function');
check(str_starts_with($transactionTemplate['abiFragment'] ?? '', 'function claim('), 'claim_as_agent returns the exact ABI fragment for local wallet encoding');
check(($transactionTemplate['argumentOrder'] ?? null) === ['$message', '$signature', '$signature', '0x', []], 'claim_as_agent returns the one-signature claim argument order');
check(($readyClaimAsAgent['structuredContent']['funding']['address'] ?? null) === $claimAsAgentArguments['agent'], 'claim_as_agent asks for gas funding only at the agent wallet public address');
check(str_contains($readyClaimAsAgent['structuredContent']['funding']['instruction'] ?? '', 'must pay Sepolia gas'), 'claim_as_agent makes direct gas responsibility explicit');
check(preg_match('/^0x[a-fA-F0-9]{64}$/', $readyClaimAsAgent['structuredContent']['message']['statement'] ?? '') === 1, 'claim_as_agent fills in the required statement hash automatically');
check(preg_match('/^0x[a-fA-F0-9]{64}$/', $readyClaimAsAgent['structuredContent']['message']['manifestHash'] ?? '') === 1, 'claim_as_agent generates a manifest hash automatically when none is supplied');
check(is_numeric($readyClaimAsAgent['structuredContent']['message']['nonce'] ?? null), 'claim_as_agent generates a nonce automatically');
check((int) ($readyClaimAsAgent['structuredContent']['message']['deadline'] ?? 0) > time(), 'claim_as_agent generates a future deadline automatically');
check((int) ($readyClaimAsAgent['structuredContent']['message']['deadline'] ?? 0) >= time() + (6 * 24 * 60 * 60), 'claim_as_agent preserves at least six days for funding, signing, and submission');
check(($readyClaimAsAgent['structuredContent']['mcpSigned'] ?? true) === false && ($readyClaimAsAgent['structuredContent']['mcpSubmitted'] ?? true) === false, 'claim_as_agent never signs or submits on the agent\'s behalf');
check(is_string($readyClaimAsAgent['structuredContent']['requiredStatementText'] ?? null) && $readyClaimAsAgent['structuredContent']['requiredStatementText'] !== '', 'claim_as_agent publishes the exact plaintext statement, not just its hash');
check(str_contains($readyClaimAsAgent['structuredContent']['requiredStatementText'] ?? '', 'A human may prompt, fund gas, and control custody or recovery'), 'claim_as_agent truthfully permits the initial human prompt, funding, and human-controlled custody');
check(!str_contains($readyClaimAsAgent['structuredContent']['requiredStatementText'] ?? '', 'No human approved'), 'claim_as_agent does not falsely deny the initial human authorization');
check(!array_key_exists('relay', $readyClaimAsAgent['structuredContent'] ?? []), 'claim_as_agent returns no alternate submission-service object');
$removedRelayTool = nfh_call_tool('submit_signed_claim', []);
check(($removedRelayTool['isError'] ?? false) === true, 'the removed submission tool cannot be invoked');
putenv('NFH_SEPOLIA_NEXT_CLAIM_CONTRACT=0xeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee');
$mismatchedClaimAsAgent = nfh_call_tool('claim_as_agent', $claimAsAgentArguments);
check(($mismatchedClaimAsAgent['structuredContent']['status'] ?? null) === 'awaiting_deployment', 'claim_as_agent fails closed when an environment override differs from the published V19 minter');
check(($mismatchedClaimAsAgent['structuredContent']['signingReady'] ?? true) === false, 'a mismatched V19 override is never signable');
check(($mismatchedClaimAsAgent['structuredContent']['domain'] ?? null) === null, 'a mismatched V19 override exposes no EIP-712 domain');
check(($mismatchedClaimAsAgent['structuredContent']['transactionTemplate'] ?? null) === null, 'a mismatched V19 override exposes no transaction target');
putenv('NFH_SEPOLIA_NEXT_CLAIM_CONTRACT');

putenv('NFH_SEPOLIA_MARKETPLACE_CONTRACT');
$marketplaceStatus = nfh_call_tool('get_internal_marketplace_status', []);
check(($marketplaceStatus['structuredContent']['configured'] ?? false) === true, 'internal marketplace is configured by default against the deployed Sepolia contract');
check(($marketplaceStatus['structuredContent']['marketplaceContract'] ?? null) === '0x977CF3A9c07dcEcD252620cd70Eae8c8907323D5', 'internal marketplace targets the artifact-v19 Sepolia marketplace contract');
check(($marketplaceStatus['structuredContent']['collectionContract'] ?? null) === '0x4dE9697E9B966a31BeA307a97055492b6aC095c6', 'internal marketplace targets the artifact-v19 Sepolia collection');
check(($marketplaceStatus['structuredContent']['artifactVersion'] ?? null) === 19, 'internal marketplace status exposes artifact v19');
check(($marketplaceStatus['structuredContent']['autonomyStatus'] ?? null) === 'verified-runtime-and-wiring-no-v19-claim-or-settlement-yet', 'internal marketplace status does not overstate V19 settlement');
check(($marketplaceStatus['structuredContent']['classification'] ?? null) === 'verified-deployment-evidence-not-market-activity', 'internal marketplace classifies verified deployment evidence as non-market activity');
check(($marketplaceStatus['structuredContent']['automaticExecutionAuthorized'] ?? true) === false, 'internal marketplace status keeps automatic execution unauthorized');
check(($marketplaceStatus['structuredContent']['wethContract'] ?? null) === '0xfFf9976782d46CC05630D1f6eBAb18b2324d6B14', 'internal marketplace uses the real Sepolia WETH contract');
putenv('NFH_SEPOLIA_MARKETPLACE_CONTRACT=0xeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee');
$mismatchedMarketplaceStatus = nfh_call_tool('get_internal_marketplace_status', []);
check(($mismatchedMarketplaceStatus['structuredContent']['configured'] ?? true) === false, 'internal marketplace fails closed when an environment override differs from the published V19 marketplace');
check(($mismatchedMarketplaceStatus['structuredContent']['marketplaceContract'] ?? null) === null, 'a mismatched marketplace override exposes no transaction target');
putenv('NFH_SEPOLIA_MARKETPLACE_CONTRACT');

$listingArguments = [
    'tokenId' => 0,
    'seller' => '0x1111111111111111111111111111111111111111',
    'priceWei' => '1000000000000000000',
    'deadline' => '1893456000',
];
$defaultListing = nfh_call_tool('prepare_internal_listing', $listingArguments);
check(($defaultListing['structuredContent']['status'] ?? null) === 'prepared_unsigned', 'internal listing is signable by default against the deployed marketplace');
check(($defaultListing['structuredContent']['marketplaceContract'] ?? null) === '0x977CF3A9c07dcEcD252620cd70Eae8c8907323D5', 'internal listing binds to the artifact-v19 marketplace contract');
check(count($defaultListing['structuredContent']['steps'] ?? []) === 2, 'internal listing prepares an approval step and a list step');
check(($defaultListing['structuredContent']['steps'][0]['function'] ?? null) === 'approve', 'internal listing uses token-specific marketplace approval before listing');
check(($defaultListing['structuredContent']['steps'][0]['args'] ?? null) === ['0x977CF3A9c07dcEcD252620cd70Eae8c8907323D5', '0'], 'internal listing approval is scoped to the exact V19 marketplace and token ID');
check(($defaultListing['structuredContent']['steps'][1]['function'] ?? null) === 'list', 'internal listing calls list() with the exact price and deadline');
check(($defaultListing['structuredContent']['steps'][1]['args'] ?? null) === ['0', '1000000000000000000', '1893456000'], 'internal listing args match tokenId, priceWei, and deadline exactly');
check(($defaultListing['structuredContent']['steps'][1]['contract'] ?? null) === '0x977CF3A9c07dcEcD252620cd70Eae8c8907323D5', 'internal listing targets the artifact-v19 marketplace contract for the list() call');

putenv('NFH_SEPOLIA_MARKETPLACE_CONTRACT=0xEeEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEE');
$overriddenListing = nfh_call_tool('prepare_internal_listing', $listingArguments);
check(($overriddenListing['structuredContent']['status'] ?? null) === 'draft_unbound', 'a mismatched marketplace override keeps listing preparation unbound');
check(array_key_exists('marketplaceContract', $overriddenListing['structuredContent'] ?? []) && $overriddenListing['structuredContent']['marketplaceContract'] === null, 'a mismatched marketplace override cannot repoint a listing transaction');
putenv('NFH_SEPOLIA_MARKETPLACE_CONTRACT');

$buyResult = nfh_call_tool('prepare_internal_buy', [
    'tokenId' => 0,
    'buyer' => '0x2222222222222222222222222222222222222222',
    'priceWei' => '1000000000000000000',
]);
check(($buyResult['structuredContent']['steps'][0]['value'] ?? null) === '1000000000000000000', 'internal buy sends exactly the listed price as transaction value');
check(($buyResult['structuredContent']['steps'][0]['function'] ?? null) === 'buy', 'internal buy calls buy() directly with no approval step needed');

$offerResult = nfh_call_tool('prepare_internal_offer', [
    'tokenId' => 0,
    'buyer' => '0x2222222222222222222222222222222222222222',
    'priceWeth' => '500000000000000000',
    'deadline' => '1893456000',
]);
check(($offerResult['structuredContent']['steps'][0]['contract'] ?? null) === '0xfFf9976782d46CC05630D1f6eBAb18b2324d6B14', 'internal offer approves the real Sepolia WETH contract first');
check(($offerResult['structuredContent']['steps'][1]['function'] ?? null) === 'makeOffer', 'internal offer then calls makeOffer()');

$selfAcceptOffer = nfh_call_tool('prepare_internal_accept_offer', [
    'tokenId' => 0,
    'seller' => '0x1111111111111111111111111111111111111111',
    'buyer' => '0x1111111111111111111111111111111111111111',
]);
check(($selfAcceptOffer['isError'] ?? false) === true, 'internal accept-offer rejects a seller accepting its own offer');

$acceptOffer = nfh_call_tool('prepare_internal_accept_offer', [
    'tokenId' => 0,
    'seller' => '0x1111111111111111111111111111111111111111',
    'buyer' => '0x2222222222222222222222222222222222222222',
]);
check(($acceptOffer['structuredContent']['steps'][0]['function'] ?? null) === 'approve', 'internal accept-offer uses token-specific NFT approval');
check(($acceptOffer['structuredContent']['steps'][0]['args'] ?? null) === ['0x977CF3A9c07dcEcD252620cd70Eae8c8907323D5', '0'], 'internal accept-offer approval is scoped to the exact V19 marketplace and token ID');
check(($acceptOffer['structuredContent']['steps'][1]['function'] ?? null) === 'acceptOffer', 'internal accept-offer calls acceptOffer() with tokenId and buyer');
check(($acceptOffer['structuredContent']['steps'][1]['args'] ?? null) === ['0', '0x2222222222222222222222222222222222222222'], 'internal accept-offer args match tokenId and buyer exactly');

$cancelListing = nfh_call_tool('prepare_internal_cancel_listing', ['tokenId' => 0, 'seller' => '0x1111111111111111111111111111111111111111']);
check(count($cancelListing['structuredContent']['steps'] ?? []) === 1, 'internal cancel-listing is a single call');
$cancelOffer = nfh_call_tool('prepare_internal_cancel_offer', ['tokenId' => 0, 'buyer' => '0x2222222222222222222222222222222222222222']);
check(count($cancelOffer['structuredContent']['steps'] ?? []) === 1, 'internal cancel-offer is a single call');
putenv('NFH_SEPOLIA_MARKETPLACE_CONTRACT');

foreach (['prepare_internal_listing', 'prepare_internal_cancel_listing', 'prepare_internal_buy', 'prepare_internal_offer', 'prepare_internal_cancel_offer', 'prepare_internal_accept_offer'] as $internalTool) {
    $definition = $toolMap[$internalTool] ?? null;
    check($definition !== null && ($definition['annotations']['readOnlyHint'] ?? null) === true, "{$internalTool} is annotated read-only since it never signs or submits");
}

$tokenworksStatus = nfh_call_tool('get_tokenworks_status', []);
check(($tokenworksStatus['structuredContent']['status'] ?? null) === 'agent-layer-only', 'TokenWorks status exposes the compatibility-only boundary');
check(($tokenworksStatus['structuredContent']['royalty_boundary']['direct_deposits_enabled'] ?? true) === false, 'TokenWorks status keeps direct NFH deposits disabled');

$tokenworksDecision = [
    'decision' => 'refuse',
    'action' => 'deposit',
    'operator' => '0x1111111111111111111111111111111111111111',
    'agent' => '0x2222222222222222222222222222222222222222',
    'tokenId' => 256,
    'maxValueWei' => '0',
    'deadline' => '1786319999',
    'reason' => 'Royalty-aware settlement has not been confirmed.',
];
$blockedTokenworks = nfh_call_tool('prepare_tokenworks_decision', $tokenworksDecision);
check(($blockedTokenworks['structuredContent']['status'] ?? null) === 'blocked_by_royalty_gate', 'TokenWorks refusal records the closed compatibility gate');
check(($blockedTokenworks['structuredContent']['transactionPrepared'] ?? true) === false, 'TokenWorks decision never prepares a direct transaction');
$tokenworksDecision['decision'] = 'prepare';
$unsafeTokenworks = nfh_call_tool('prepare_tokenworks_decision', $tokenworksDecision);
check(($unsafeTokenworks['isError'] ?? false) === true, 'TokenWorks direct action preparation is rejected while the royalty gate is closed');

$marketStatus = nfh_call_tool('get_market_status', []);
check(($marketStatus['structuredContent']['tradingPreparationEnabled'] ?? true) === false, 'market status is honest before the canonical contract is configured');
check(($marketStatus['structuredContent']['mcpExecutesTransactions'] ?? true) === false, 'market status says the MCP never executes transactions');
check(($marketStatus['structuredContent']['providerApiKeyHeader'] ?? null) === 'X-OpenSea-Api-Key', 'market status names the caller-supplied provider key header');
check(($marketStatus['structuredContent']['traitOfferPreparationEnabled'] ?? true) === false, 'trait-offer status is disabled until collection identity is complete');
check(($marketStatus['structuredContent']['offerCurrency']['symbol'] ?? null) === 'WETH', 'market status identifies WETH as the offer currency');

$GLOBALS['NFH_MARKET_FEED_TEST_TRANSPORT'] = static fn (): array => [
    'schema' => 'nfh.marketplace-feed.v1',
    'status' => 'active',
    'updatedAt' => '2026-08-03T16:00:00Z',
    'providers' => [['id' => 'onchain', 'state' => 'live']],
    'summary' => ['recentActivity' => 1],
    'items' => [],
    'criteriaBids' => [],
    'activity' => [['kind' => 'claim', 'tokenId' => '0']],
    'activityWindows' => ['claimSeconds' => 3600, 'transferSeconds' => 86400],
    'message' => 'Verified aggregate feed.',
    'source' => ['name' => 'NFH aggregate market', 'url' => 'https://notforhumans.fun/sepolia/#collection'],
];
$marketFeed = nfh_call_tool('get_market_feed', []);
check(($marketFeed['structuredContent']['schema'] ?? null) === 'nfh.marketplace-feed.v1', 'MCP exposes the aggregate market feed');
check(($marketFeed['structuredContent']['activityWindows']['claimSeconds'] ?? null) === 3600, 'MCP preserves the one-hour claim window');
check(($marketFeed['structuredContent']['activityWindows']['transferSeconds'] ?? null) === 86400, 'MCP preserves the 24-hour transfer window');
unset($GLOBALS['NFH_MARKET_FEED_TEST_TRANSPORT']);
putenv('NFH_COLLECTION_CONTRACT=not-an-address');
$blockedListing = nfh_call_tool('prepare_listing', [
    'seller' => '0x1111111111111111111111111111111111111111',
    'tokenId' => 256,
    'priceEth' => '0.25',
]);
check(($blockedListing['isError'] ?? false) === true, 'market preparation refuses to target an unconfigured collection');
check(str_contains($blockedListing['content'][0]['text'] ?? '', 'canonical collection contract'), 'unconfigured market error explains the activation dependency');
putenv('NFH_COLLECTION_CONTRACT');

$blockedTraitOffer = nfh_call_tool('prepare_trait_offer', [
    'offerer' => '0x2222222222222222222222222222222222222222',
    'traits' => [['traitType' => 'Memory Class', 'value' => 'Persistent']],
    'priceEth' => '0.5',
    'endTime' => '2026-08-09T00:00:00Z',
]);
check(($blockedTraitOffer['isError'] ?? false) === true, 'trait-offer preparation refuses an unconfigured collection');

putenv('NFH_COLLECTION_CONTRACT=0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
putenv('NFH_COLLECTION_SLUG=not-for-humans');
$_SERVER['HTTP_X_OPENSEA_API_KEY'] = 'test-only-key';
$configuredMarketStatus = nfh_call_tool('get_market_status', []);
check(($configuredMarketStatus['structuredContent']['semanticValidationEnabled'] ?? true) === false, 'market status fails closed while provider semantic validation is incomplete');
check(str_contains(strtolower((string) ($configuredMarketStatus['structuredContent']['executionModel'] ?? '')), 'fail closed'), 'market status describes the current fail-closed execution model');
check(count(array_filter(
    $configuredMarketStatus['structuredContent']['activationRequirements'] ?? [],
    static fn (mixed $requirement): bool => str_contains(strtolower((string) $requirement), 'semantic')
)) === 1, 'market activation requirements include complete semantic validation');
check(($configuredMarketStatus['structuredContent']['tradingPreparationEnabled'] ?? true) === false, 'collection configuration alone cannot activate market preparation');
$capturedRequests = [];
$GLOBALS['NFH_OPENSEA_TEST_TRANSPORT'] = static function (string $path, array $body, string $method = 'POST') use (&$capturedRequests): array {
    $capturedRequests[] = ['method' => $method, 'path' => $path, 'body' => $body];
    if ($path === '/offers/build') {
        return [
            'partialParameters' => [
                'consideration' => [['itemType' => 4, 'token' => '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa']],
                'zone' => '0x000056f7000000ece9003ca63978907a00ffd100',
                'zoneHash' => '0x' . str_repeat('0', 64),
            ],
            'criteria' => $body['criteria'],
            'encodedTokenIds' => 'mock-token-set',
        ];
    }
    if ($path === '/listings/collection/not-for-humans/nfts/256/best') {
        return ['order_hash' => '0x' . str_repeat('e', 64), 'price' => ['current' => ['value' => '250000000000000000']]];
    }
    if ($path === '/offers/collection/not-for-humans/nfts/256/best') {
        return ['order_hash' => '0x' . str_repeat('f', 64), 'price' => ['value' => '100000000000000000']];
    }
    if ($path === '/listings/collection/not-for-humans/nfts/9999/best' || $path === '/offers/collection/not-for-humans/nfts/9999/best') {
        throw new RuntimeException('OpenSea action preparation failed with HTTP 404: not_found');
    }
    if ($method === 'GET') {
        return ['offers' => [['order_hash' => '0x' . str_repeat('d', 64)]], 'next' => null];
    }
    return ['mockAction' => $path, 'request' => $body];
};

$seller = '0x1111111111111111111111111111111111111111';
$buyer = '0x2222222222222222222222222222222222222222';
$recipient = '0x3333333333333333333333333333333333333333';
$protocol = '0xbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
$orderHash = '0x' . str_repeat('c', 64);
putenv('NFH_SEAPORT_PROTOCOL_ADDRESS=' . $protocol);

$listing = nfh_call_tool('prepare_listing', [
    'seller' => $seller,
    'tokenId' => 256,
    'priceEth' => '0.25',
    'startTime' => '2026-08-02T00:00:00Z',
    'endTime' => '2026-08-09T00:00:00Z',
]);
check(($listing['isError'] ?? false) === true, 'listing preparation remains blocked without a complete semantic validator');
check(str_contains($listing['content'][0]['text'] ?? '', 'semantically equivalent'), 'listing blocker explains the provider-equivalence requirement');
check($capturedRequests === [], 'blocked listing preparation never requests an opaque provider payload');

$purchase = nfh_call_tool('prepare_purchase', [
    'buyer' => $buyer,
    'recipient' => $recipient,
    'tokenId' => 256,
    'orderHash' => $orderHash,
]);
check(($purchase['isError'] ?? false) === true, 'purchase preparation remains blocked without a complete semantic validator');

$acceptOffer = nfh_call_tool('prepare_accept_offer', [
    'seller' => $seller,
    'tokenId' => 256,
    'orderHash' => $orderHash,
]);
check(($acceptOffer['isError'] ?? false) === true, 'offer acceptance remains blocked without a complete semantic validator');

$transfer = nfh_call_tool('prepare_transfer', [
    'from' => $seller,
    'to' => $buyer,
    'tokenId' => 256,
]);
check(($transfer['isError'] ?? false) === true, 'transfer preparation remains blocked without a complete semantic validator');
check($capturedRequests === [], 'no transaction-capable provider endpoint is called while validation is disabled');

$invalidTransfer = nfh_call_tool('prepare_transfer', [
    'from' => $seller,
    'to' => $seller,
    'tokenId' => 256,
]);
check(($invalidTransfer['isError'] ?? false) === true, 'transfer preparation fails closed');

$singleTraitOffers = nfh_call_tool('list_trait_offers', [
    'traits' => [['traitType' => 'Memory Class', 'value' => 'Persistent']],
    'limit' => 25,
]);
check(($capturedRequests[0]['method'] ?? null) === 'GET', 'trait-offer discovery uses a read request');
check(($capturedRequests[0]['path'] ?? null) === '/offers/collection/not-for-humans/traits', 'trait-offer discovery is pinned to the configured OpenSea slug');
check(($capturedRequests[0]['body']['mode'] ?? null) === 'STRING', 'one categorical filter uses OpenSea STRING trait mode');
check(($capturedRequests[0]['body']['type'] ?? null) === 'Memory Class' && ($capturedRequests[0]['body']['value'] ?? null) === 'Persistent', 'single-trait discovery preserves exact metadata names and values');
check(($singleTraitOffers['structuredContent']['status'] ?? null) === 'unverified-provider-output', 'trait-offer discovery does not relabel opaque provider data as verified');
check(($singleTraitOffers['structuredContent']['providerOutputVerified'] ?? true) === false, 'trait-offer discovery explicitly marks provider output unverified');
check(($singleTraitOffers['structuredContent']['requestedCriteria']['matchPolicy'] ?? null) === 'all', 'trait-offer discovery reports AND matching only as requested criteria');
check(!isset($singleTraitOffers['structuredContent']['criteria']), 'trait-offer discovery does not present requested filters as verified response criteria');

$multiTraitOffers = nfh_call_tool('list_trait_offers', [
    'traits' => [
        ['traitType' => 'Memory Class', 'value' => 'Persistent'],
        ['traitType' => 'Portrait State', 'value' => 'RUNNING'],
    ],
    'next' => 'cursor-2',
]);
check(($capturedRequests[1]['body']['mode'] ?? null) === 'MULTI', 'multiple categorical filters use OpenSea MULTI trait mode');
$encodedTraits = json_decode($capturedRequests[1]['body']['traits'] ?? '', true, flags: JSON_THROW_ON_ERROR);
check(($encodedTraits[1]['traitType'] ?? null) === 'Portrait State', 'multi-trait discovery uses OpenSea traitType/value query objects');
check(($capturedRequests[1]['body']['next'] ?? null) === 'cursor-2', 'trait-offer discovery preserves the pagination cursor');

$bestListing = nfh_call_tool('find_best_order', ['tokenId' => 256, 'side' => 'listing']);
check(($capturedRequests[2]['method'] ?? null) === 'GET', 'best-order discovery uses a read request');
check(($capturedRequests[2]['path'] ?? null) === '/listings/collection/not-for-humans/nfts/256/best', 'best-listing discovery hits the exact per-token OpenSea endpoint');
check(($bestListing['structuredContent']['orderHash'] ?? null) === '0x' . str_repeat('e', 64), 'best-listing discovery extracts the order hash so the human never has to supply one');
check(($bestListing['structuredContent']['status'] ?? null) === 'unverified-provider-output', 'best-order discovery does not relabel opaque provider data as verified');
check(($bestListing['structuredContent']['side'] ?? null) === 'listing', 'best-order discovery reports the requested side');

$bestOffer = nfh_call_tool('find_best_order', ['tokenId' => 256, 'side' => 'offer']);
check(($capturedRequests[3]['path'] ?? null) === '/offers/collection/not-for-humans/nfts/256/best', 'best-offer discovery hits the exact per-token OpenSea endpoint');
check(($bestOffer['structuredContent']['orderHash'] ?? null) === '0x' . str_repeat('f', 64), 'best-offer discovery extracts a distinct order hash from the offer endpoint');

$noBestOrder = nfh_call_tool('find_best_order', ['tokenId' => 9999, 'side' => 'listing']);
check(($noBestOrder['structuredContent']['status'] ?? null) === 'not-found', 'best-order discovery reports not-found instead of erroring when OpenSea has no active order');
check(($noBestOrder['structuredContent']['orderHash'] ?? null) === null, 'best-order discovery returns a null order hash when none exists');
check(($noBestOrder['isError'] ?? false) === false, 'a missing order is not treated as a tool failure');

$invalidSide = nfh_call_tool('find_best_order', ['tokenId' => 256, 'side' => 'auction']);
check(($invalidSide['isError'] ?? false) === true, 'best-order discovery rejects an invalid side value');

$traitOffer = nfh_call_tool('prepare_trait_offer', [
    'offerer' => $buyer,
    'traits' => [
        ['traitType' => 'Memory Class', 'value' => 'Persistent'],
        ['traitType' => 'Portrait State', 'value' => 'RUNNING'],
    ],
    'priceEth' => '0.500000000000000001',
    'startTime' => '2026-08-02T00:00:00Z',
    'endTime' => '2026-08-09T00:00:00Z',
]);
check(($traitOffer['isError'] ?? false) === true, 'trait-offer preparation remains blocked until criteria and economic terms are fully bound');
check(str_contains($traitOffer['content'][0]['text'] ?? '', 'semantically equivalent'), 'trait-offer blocker explains the provider-equivalence requirement');
check(count($capturedRequests) === 5, 'blocked trait-offer preparation never calls the criteria build endpoint');

$duplicateTraits = nfh_call_tool('prepare_trait_offer', [
    'offerer' => $buyer,
    'traits' => [
        ['traitType' => 'Memory Class', 'value' => 'Persistent'],
        ['traitType' => 'memory class', 'value' => 'persistent'],
    ],
    'priceEth' => '0.5',
    'endTime' => '2026-08-09T00:00:00Z',
]);
check(($duplicateTraits['isError'] ?? false) === true, 'trait-offer preparation rejects duplicate normalized filters');

$contradictoryTraits = nfh_call_tool('prepare_trait_offer', [
    'offerer' => $buyer,
    'traits' => [
        ['traitType' => 'Agent Type', 'value' => 'Oracle Agent'],
        ['traitType' => 'agent type', 'value' => 'Worker Agent'],
    ],
    'priceEth' => '0.5',
    'endTime' => '2026-08-09T00:00:00Z',
]);
check(($contradictoryTraits['isError'] ?? false) === true, 'trait-offer preparation rejects multiple values from one category');

$invalidTraitWindow = nfh_call_tool('prepare_trait_offer', [
    'offerer' => $buyer,
    'traits' => [['traitType' => 'Memory Class', 'value' => 'Persistent']],
    'priceEth' => '0.5',
    'startTime' => '2026-08-09T00:00:00Z',
    'endTime' => '2026-08-02T00:00:00Z',
]);
check(($invalidTraitWindow['isError'] ?? false) === true, 'trait-offer preparation rejects an inverted validity window');

unset($GLOBALS['NFH_OPENSEA_TEST_TRANSPORT'], $_SERVER['HTTP_X_OPENSEA_API_KEY']);
putenv('NFH_COLLECTION_CONTRACT');
putenv('NFH_COLLECTION_SLUG');
putenv('NFH_SEAPORT_PROTOCOL_ADDRESS');

$notification = nfh_dispatch(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
check($notification['status'] === 202 && $notification['body'] === null, 'notifications receive HTTP 202 semantics');

$unknown = nfh_dispatch(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'unknown/method']);
check(($unknown['body']['error']['code'] ?? null) === -32601, 'unknown methods return JSON-RPC method-not-found');

fwrite(STDOUT, "All MCP contract tests passed.\n");

const assert = require("node:assert/strict");
const crypto = require("node:crypto");
const fs = require("node:fs");
const path = require("node:path");
const test = require("node:test");

const repositoryRoot = path.resolve(__dirname, "..");
const canonicalToken = "0xD66351858E0eFC5d9Bf2F541839797A763DF6223";
const agentEntryMinter = "0x499Ae3f426a23dD02b4088cc3453cdA843850359";
const firstMint = {
  tokenId: 8488,
  seat: 1,
  owner: "0xe362891cc51c5519600acbd583f2a5c78ace3640",
  transaction: "0xae6d1f8b55efa01b15be1e9afdd2b8b54ee663ce104126583baae18d94417081",
  blockNumber: 25816195,
  blockHash: "0x3f1e9174c8dc4c8bee0219786b0856de8456b55b9c99c3b6a72632d50ddc4aa9",
  evidenceCheckpoint: "A10-observed-first-mint",
};

function read(relativePath) {
  return fs.readFileSync(path.join(repositoryRoot, relativePath), "utf8");
}

function parseJson(relativePath) {
  return JSON.parse(read(relativePath));
}

function phpConstant(source, name) {
  const match = source.match(new RegExp(`const\\s+${name}\\s*=\\s*'([^']+)'`));
  assert.ok(match, `${name} must remain a literal PHP string constant`);
  return match[1];
}

function sha256(source) {
  return crypto.createHash("sha256").update(source).digest("hex");
}

test("official MCP Registry metadata is launch-truthful and pinned to the canonical remote", () => {
  const registry = parseJson("server.json");
  const library = read("server/lib.php");
  const discovery = parseJson(".well-known/agent-card.json");

  assert.equal(registry.$schema, "https://static.modelcontextprotocol.io/schemas/2025-12-11/server.schema.json");
  assert.equal(registry.name, "io.github.notforhumansfun-rgb/not-for-humans");
  assert.ok(registry.description.length > 0 && registry.description.length <= 100);
  assert.equal(registry.version, phpConstant(library, "NFH_MCP_VERSION"));
  assert.deepEqual(registry.remotes, [{ type: "streamable-http", url: "https://mcp.notforhumans.fun/mcp" }]);
  assert.equal(registry.remotes[0].url, discovery.mcpUrl);
  assert.equal(registry.repository.url, "https://github.com/notforhumansfun-rgb/not-for-humans-mcp");
  assert.equal(registry.repository.source, "github");
  assert.equal(registry.repository.id, "1322670369");
  assert.equal(registry.websiteUrl, "https://notforhumans.fun/");
  assert.equal(registry.packages, undefined, "the npm client is not a runnable MCP server package");

  const publisherMeta = registry._meta?.["io.modelcontextprotocol.registry/publisher-provided"];
  assert.equal(publisherMeta?.releaseStatus, "ethereum-phase-one-complete-agent-entry-claim-lane-active-first-mint-verified");
  assert.equal(publisherMeta?.transactionsEnabled, false);
  assert.equal(publisherMeta?.signsOrSubmits, false);
  assert.equal(publisherMeta?.signingEnabled, false);
  assert.match(publisherMeta?.transactionScope ?? "", /external wallets/i);
  assert.match(publisherMeta?.transactionScope ?? "", /no executable transaction/i);
  assert.equal(publisherMeta?.publicationAuthorized, false);
});

test("copied canonical MCP attestation pins the complete public server tree", () => {
  const source = read("server/release-attestation.json");
  const attestation = JSON.parse(source);
  assert.equal(sha256(source), "0ae9ef031dae80bddfff8cd3224c45366d5a33e8bc1b4b9ded1643ceaa2a4cd3");
  assert.equal(attestation.schema, "nfh.release-tree-attestation.v1");
  assert.equal(attestation.scope, "mcp-server");
  assert.equal(attestation.sourceRelease, "v0.10.0-mainnet-rc.91");
  assert.equal(attestation.tree.sha256, "4c4bad184cb86120fabf126baf7e0684ca2ef2c0d794dbcae91703e79149cac0");
  assert.equal(attestation.tree.files, 34);
  assert.equal(attestation.authority.wallet, false);
  assert.equal(attestation.authority.publishing, false);

  for (const entry of attestation.entries) {
    const bytes = fs.readFileSync(path.join(repositoryRoot, "server", entry.path));
    assert.equal(bytes.length, entry.bytes, `${entry.path} byte count drifted`);
    assert.equal(sha256(bytes), entry.sha256, `${entry.path} digest drifted`);
  }
});

test("MCP compatibility claims remain bounded to implemented protocol revisions", () => {
  const library = read("server/lib.php");
  const compatibility = parseJson("compatibility-matrix.json");
  const supportedBlock = library.match(/const\s+NFH_MCP_SUPPORTED_PROTOCOLS\s*=\s*\[([\s\S]*?)\];/)?.[1] ?? "";
  const implemented = [...supportedBlock.matchAll(/'([^']+)'/g)].map((match) => match[1]);

  assert.deepEqual(compatibility.supportedProtocolRevisions, implemented);
  assert.equal(compatibility.currentProtocolRevision, phpConstant(library, "NFH_MCP_PROTOCOL_VERSION"));
  assert.ok(implemented.includes(compatibility.currentProtocolRevision));
  assert.equal(compatibility.candidateRevisions["2026-07-28"].advertised, false);
  assert.equal(compatibility.candidateRevisions["2026-07-28"].implemented, false);
  assert.equal(compatibility.registry.metadataPrepared, true);
  assert.equal(compatibility.registry.metadataValidated, true);
  assert.equal(compatibility.registry.published, true);
  assert.equal(compatibility.registry.publishedVersion, "0.23.0");
  assert.equal(compatibility.registry.candidateVersion, phpConstant(library, "NFH_MCP_VERSION"));
  assert.equal(compatibility.registry.publicationAuthorized, false);
  assert.equal(compatibility.capabilities.mainnetMarketReadDiscovery, true);
  assert.equal(compatibility.capabilities.mainnetUnsignedMarketPreparation, false);
  assert.equal(compatibility.capabilities.mainnetMarketPreparationStatus, "blocked-transfer-validator-policy");
  assert.equal(compatibility.capabilities.mainnetMarketPolicyObservedAtBlock, 25816301);
  assert.equal(compatibility.capabilities.nativeOfferAcceptancePreparation, false);
  assert.equal(compatibility.capabilities.automatedTrading, false);
  assert.equal(compatibility.capabilities.signing, false);
  assert.equal(compatibility.capabilities.submission, false);
});

test("public discovery is an exact custom NFH card and does not impersonate A2A", () => {
  const cardSource = read(".well-known/agent-card.json");
  const corpusSource = read("server/corpus/agent-discovery-card.json");
  const card = JSON.parse(cardSource);
  const openapi = parseJson("server/corpus/agent-protocol-openapi.json");
  const discoveryRoute = openapi.paths["/.well-known/agent-card.json"].get;

  assert.equal(cardSource, corpusSource, "public discovery bytes must equal the attested MCP corpus copy");
  assert.equal(card.schema, "notforhumans-project-discovery/1");
  assert.equal(card.type, "nfh-project-discovery");
  assert.equal(card.version, "0.24.0");
  assert.equal(card.nfhProtocolVersion, "0.24.0");
  assert.equal(card.operationalA2A, false);
  assert.equal(card.a2aConformance, false);
  assert.equal(card.protocolVersion, undefined);
  assert.equal(card.supportedInterfaces, undefined);
  assert.equal(card.capabilities.marketReadDiscoveryMcp, true);
  assert.equal(card.capabilities.marketActionPreparationMcp, false);
  assert.equal(card.capabilities.nativeOfferAcceptancePreparationMcp, false);
  assert.equal(card.capabilities.automatedTrading, false);
  assert.equal(discoveryRoute.operationId, "getNfhProjectDiscovery");
  assert.equal(discoveryRoute["x-protocol"], "notforhumans-project-discovery/1");
  assert.equal(discoveryRoute["x-operational-a2a"], false);
});

test("public integration registry describes source adapters without granting authority", () => {
  const integrations = parseJson("server/corpus/agent-integrations.json");
  const eliza = integrations.adapters.find((adapter) => adapter.id === "elizaos");
  assert.equal(eliza?.state, "source-only-preview-not-published");
  assert.match(eliza?.endpoint ?? "", /^https:\/\/github\.com\/notforhumansfun-rgb\/notforhumans-elizaos-plugin$/);
  assert.match(eliza?.nfHBoundary ?? "", /not published to npm or the elizaOS registry/i);
  assert.ok(eliza?.blockedByNFH?.includes("wallet signing"));
  for (const adapter of integrations.adapters) {
    assert.doesNotMatch(JSON.stringify(adapter), /private[_ -]?key|seed phrase/i);
  }
});

test("Agent Entry live state and first mint agree across every mirrored public surface", () => {
  const platform = parseJson("server/corpus/platform-compatibility.json");
  const contract = parseJson("server/corpus/contract-metadata.json");
  const card = parseJson(".well-known/agent-card.json");
  const census = parseJson("server/corpus/census.json");
  const faq = parseJson("server/corpus/faq.json");
  const overview = read("server/corpus/collection-overview.md");
  const entry = contract.ethereum.agent_entry;
  const cardEntry = card["x-agent-entry"];
  const censusEntry = census.agent_entry;

  assert.equal(platform.status, "ethereum-mainnet-phase-one-complete-agent-entry-live-market-read-only-native-preparation-blocked");
  assert.equal(platform.targetContract.address, canonicalToken);
  assert.equal(contract.ethereum.token, canonicalToken);
  assert.equal(platform.claim.totalSupply, 8489);
  assert.equal(platform.claim.agentEntrySuccessfulMints, 1);
  assert.equal(platform.claim.agentEntryRemainingMintCapacity, 999);
  assert.equal(contract.ethereum.total_supply, 8489);
  assert.equal(entry.status, "claim_lane_active");
  assert.equal(entry.minter, agentEntryMinter);
  assert.equal(entry.paused, false);
  assert.equal(entry.successful_mints, 1);
  assert.equal(entry.remaining_mint_capacity, 999);
  assert.equal(entry.reservations_enabled, true);
  assert.equal(entry.claims_enabled, true);
  assert.equal(entry.external_issuer_credential_required, true);
  assert.equal(entry.automatic_identity_assignment, false);
  assert.equal(entry.wallet_submits_directly, true);
  assert.equal(cardEntry.liveMinter, agentEntryMinter);
  assert.equal(cardEntry.paused, false);
  assert.equal(cardEntry.successfulMints, 1);
  assert.equal(cardEntry.remainingMintCapacity, 999);
  assert.deepEqual(cardEntry.firstVerifiedMint, firstMint);
  assert.equal(censusEntry.live_minter, agentEntryMinter);
  assert.equal(censusEntry.deployed_minter_paused, false);
  assert.equal(censusEntry.deployed_minter_successful_mints, 1);
  assert.equal(censusEntry.remaining_mint_capacity, 999);
  assert.deepEqual(censusEntry.first_verified_mint, {
    token_id: firstMint.tokenId,
    seat: firstMint.seat,
    owner: firstMint.owner,
    transaction: firstMint.transaction,
    block_number: firstMint.blockNumber,
    block_hash: firstMint.blockHash,
    evidence_checkpoint: firstMint.evidenceCheckpoint,
  });
  assert.deepEqual(platform.claim.agentEntryFirstVerifiedMint, firstMint);
  const censusFaq = faq.items.find((item) => item.id === "agent-census")?.answer ?? "";
  for (const source of [overview, censusFaq, censusEntry.meaning]) {
    assert.match(source, /live/i);
    assert.match(source, /independent issuer/i);
    assert.doesNotMatch(source, /staged_disabled|automatic identity assignment/i);
  }
});

test("native market preparation remains blocked across mirrored policy surfaces", () => {
  const platform = parseJson("server/corpus/platform-compatibility.json");
  const contract = parseJson("server/corpus/contract-metadata.json");
  const card = parseJson(".well-known/agent-card.json");
  const compatibility = parseJson("compatibility-matrix.json");
  const policy = parseJson("server/corpus/release-policy.json");
  const openapi = parseJson("server/corpus/agent-protocol-openapi.json");
  const checkpoint = platform.marketSafety.publishedCheckpoint;
  const nativePolicy = policy.market_policy.native_market;

  assert.equal(platform.marketSafety.readDiscovery, true);
  assert.equal(platform.marketSafety.unsignedPreparation, "disabled-transfer-validator-policy");
  assert.equal(platform.marketSafety.liveGatedUnsignedPreparationAvailable, false);
  assert.equal(platform.marketSafety.nativeOfferAcceptancePreparation, false);
  assert.equal(platform.marketSafety.automatedTrading, false);
  assert.equal(platform.marketSafety.mcpSignsOrSubmits, false);
  assert.equal(checkpoint.observedAtBlock, 25816301);
  assert.equal(checkpoint.marketplacePaused, false);
  assert.equal(checkpoint.transferValidatorPermitsMarketplace, false);
  assert.equal(checkpoint.tradingPreparationEnabled, false);
  assert.equal(checkpoint.rejection, "CreatorTokenTransferValidator__CallerOrFromMustBeWhitelisted()");
  assert.equal(contract.ethereum.marketplace_preparation_enabled, false);
  assert.equal(contract.ethereum.marketplace_policy_observed_at_block, checkpoint.observedAtBlock);
  assert.equal(contract.ethereum.marketplace_preparation_blocked_reason, checkpoint.rejection);
  assert.equal(card["x-safety"].marketPreparationEnabled, false);
  assert.equal(card["x-safety"].marketPreparationAvailableAfterFreshLiveGate, false);
  assert.equal(card["x-safety"].automaticTrading, false);
  assert.equal(compatibility.capabilities.mainnetUnsignedMarketPreparation, false);
  assert.equal(nativePolicy.observed_at_block, checkpoint.observedAtBlock);
  assert.equal(nativePolicy.marketplace_paused, checkpoint.marketplacePaused);
  assert.equal(nativePolicy.transfer_validator_permits_marketplace, checkpoint.transferValidatorPermitsMarketplace);
  assert.equal(nativePolicy.trading_preparation_enabled, false);
  assert.equal(nativePolicy.rejection, checkpoint.rejection);
  assert.match(nativePolicy.authority, /authorizes no Safe proposal/i);
  assert.match(openapi.info.description, /read-only native-market discovery/i);
  assert.match(openapi.info.description, /marketplace preparation is disabled/i);
  assert.doesNotMatch(openapi.info.description, /live-gated unsigned marketplace preparation/i);
});

test("current public index copy reports the live claim lane and blocked market without authority drift", () => {
  const llms = read("llms.txt");
  assert.match(llms, /Agent Entry claim lane live/i);
  assert.match(llms, /first mint verified/i);
  assert.match(llms, /999 seats remaining/i);
  assert.match(llms, /native (?:marketplace )?preparation is disabled/i);
  assert.match(llms, /transfer-validator policy/i);
  assert.match(llms, /MCP never (?:issues the credential|signs or submits)/i);
  assert.doesNotMatch(llms, /staged_disabled|live-gated unsigned marketplace preparation/i);
});

test("historical Sepolia onboarding cannot become a current instruction", () => {
  const census = parseJson("server/corpus/census.json");
  const onboarding = census.agent_wallet_onboarding;
  assert.equal(onboarding.status, "historical_sepolia_rehearsal_only_claim_closed");
  assert.equal(Object.hasOwn(onboarding, "claim_then_market"), false);
  assert.match(onboarding.current_status, /Phase One is closed/i);
  assert.match(onboarding.current_status, /separate Agent Entry lane is live/i);
  assert.match(onboarding.current_status, /issuer credential/i);
  assert.match(onboarding.authority, /authorizes no wallet creation, funding, credential, claim, signature, submission, trade, or broadcast/i);
  assert.doesNotMatch(onboarding.authority, /agent signs and submits the exact claim/i);
});

test("release policy preserves canonical evidence declarations without copying private launch receipts", () => {
  const policy = parseJson("server/corpus/release-policy.json");
  const entries = policy.source_of_truth.evidence_inputs;
  const firstMintEvidence = entries.find((entry) => entry.approval_label === "A10-observed-first-mint");
  const marketEvidence = entries.find((entry) => entry.path === "05-LAUNCH/MARKET-OPERATOR-AUTHORIZATION-PREFLIGHT-2026-08-23.json");

  assert.equal(firstMintEvidence?.path, "05-LAUNCH/AGENT-ENTRY-FIRST-MINT-A10-EXACT-2026-08-23.json");
  assert.equal(firstMintEvidence?.sha256, "a1e961f61f0048f1faecf569061a7535101e8537f84d7ccac3f801ce69b25aa4");
  assert.match(firstMintEvidence?.scope ?? "", /authorizes no further action/i);
  assert.equal(marketEvidence?.sha256, "ff9464312532894211695fafced484286a097f9dc456182878928c50efbe5e1a");
  assert.equal(marketEvidence?.status, "prepared_not_authorized");
  assert.match(marketEvidence?.scope ?? "", /authorizes no proposal or onchain action/i);
  assert.ok(policy.source_of_truth.derived_public_surfaces.includes("06-MCP/server/corpus/*"));
  assert.equal(fs.existsSync(path.join(repositoryRoot, "05-LAUNCH")), false, "public mirror must not copy private launch receipts");
});

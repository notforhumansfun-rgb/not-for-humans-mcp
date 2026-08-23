import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";

import {
  assertFounderAwayAuthority,
  assertFreshAnchorBlock,
  chooseAnchorBlock,
  computeReleaseTreeSha256,
  decodeAddress,
  decodeAddressArray,
  decodeAddressArrayAndAddress,
  decodeNullableAddress,
  decodeString,
  decodeUint,
  exactCallData,
  requireQuorum,
  quorumAnchorTimestamp,
  roleCountCalldata,
  roleMemberCalldata,
  sha256Hex,
  validateExpectedCallResult,
  validateAgentEntryStatus,
  validateDeniedResponse,
  validateNetworkPulse,
  validatePresenceStorage,
  validateSecurityHeaders,
  validateServedHttpSecurityConfiguration,
  validateVirtualsBoundary,
  verifyMcpReleaseAttestation,
  verifyStaticReleaseAttestation,
} from "./monitor.mjs";

const baseline = JSON.parse(readFileSync(new URL("./baseline.json", import.meta.url), "utf8"));

function siteSecurityHeaders(overrides = {}) {
  return new Headers({
    "Strict-Transport-Security": "includeSubDomains; max-age=63072000",
    "X-Content-Type-Options": "nosniff",
    "X-Frame-Options": "DENY",
    "Content-Security-Policy": "frame-ancestors 'none'; form-action 'self'; base-uri 'none'; object-src 'none'; connect-src https://mcp.notforhumans.fun 'self'; script-src https://www.statcounter.com 'self'; default-src 'self'",
    ...overrides,
  });
}

function mcpSecurityHeaders(overrides = {}) {
  return new Headers({
    "Strict-Transport-Security": "max-age=31536000; includeSubDomains",
    "X-Content-Type-Options": "nosniff",
    "X-Frame-Options": "DENY",
    "Content-Security-Policy": "frame-ancestors 'none'; form-action 'none'; base-uri 'none'; style-src 'unsafe-inline'; default-src 'none'",
    "Cache-Control": "no-cache, max-age=0, no-store",
    ...overrides,
  });
}

test("quorum requires two exact provider results and rejects a tie", () => {
  assert.equal(requireQuorum(["0x01", "0x01", "0x02"]), "0x01");
  assert.throws(() => requireQuorum(["0x01", "0x02", "0x03"]), /quorum/i);
  assert.throws(() => requireQuorum(["0x01", "0x01", "0x02", "0x02"]), /quorum/i);
});

test("anchor block trails the newest close provider pair and ignores a stale outlier", () => {
  assert.equal(chooseAnchorBlock([25_800_010, 25_800_008, 25_800_009]), 25_800_007);
  assert.equal(chooseAnchorBlock([25_800_010, 3_000_000, 25_800_009]), 25_800_007);
  assert.throws(() => chooseAnchorBlock([25_800_010]), /two healthy/i);
  assert.throws(() => chooseAnchorBlock([25_800_010, 3_000_000]), /within 12 blocks/i);
});

test("anchor block timestamp must be recent and not in the future", () => {
  assert.equal(assertFreshAnchorBlock({ timestamp: "0x3e8" }, 1_300, 600), 1_000);
  assert.throws(() => assertFreshAnchorBlock({ timestamp: "0x3e8" }, 2_000, 600), /older than/i);
  assert.throws(() => assertFreshAnchorBlock({ timestamp: "0x7d0" }, 1_000, 600), /future/i);
});

test("anchor timestamp requires quorum even when one matching-hash provider lies", () => {
  const hash = `0x${"ab".repeat(32)}`;
  const blocks = [
    { hash, timestamp: "0x7d0" },
    { hash, timestamp: "0x3e8" },
    { hash, timestamp: "0x3e8" },
  ];
  assert.equal(quorumAnchorTimestamp(blocks, hash), 1_000);
  assert.throws(() => quorumAnchorTimestamp(blocks.slice(0, 2), hash), /quorum/i);
});

test("EVM scalar decoders reject malformed responses", () => {
  assert.equal(decodeUint(`0x${"0".repeat(60)}2128`), 8_488n);
  assert.equal(decodeAddress(`0x${"0".repeat(24)}1111111111111111111111111111111111111111`), "0x1111111111111111111111111111111111111111");
  assert.throws(() => decodeUint("0x1"), /32-byte/i);
  assert.throws(() => decodeAddress("0x00"), /32-byte/i);
  assert.equal(decodeNullableAddress(`0x${"0".repeat(64)}`), "0x0000000000000000000000000000000000000000");
});

test("Safe ABI decoders are strict about offsets, lengths, and padding", () => {
  const word = (value) => BigInt(value).toString(16).padStart(64, "0");
  const a = "11".repeat(20);
  const b = "22".repeat(20);
  const addressArray = `0x${word(32)}${word(2)}${"0".repeat(24)}${a}${"0".repeat(24)}${b}`;
  assert.deepEqual(decodeAddressArray(addressArray), [`0x${a}`, `0x${b}`]);
  const tuple = `0x${word(64)}${"0".repeat(24)}${"0".repeat(39)}1${word(0)}`;
  assert.deepEqual(decodeAddressArrayAndAddress(tuple), {
    addresses: [],
    next: "0x0000000000000000000000000000000000000001",
  });
  const version = `0x${word(32)}${word(5)}${Buffer.from("1.4.1").toString("hex")}${"0".repeat(54)}`;
  assert.equal(decodeString(version), "1.4.1");
  assert.throws(() => decodeAddressArray(`0x${word(32)}${word(2)}${word(0)}`), /length/i);
});

test("protected call descriptors encode arguments and reject semantic drift", () => {
  const descriptor = { id: "token.allocationMinted.public", selector: "0xaf6498bb", uintArgs: [3], kind: "uint", expected: "0" };
  assert.equal(exactCallData(descriptor), `0xaf6498bb${"0".repeat(63)}3`);
  const zeroWord = `0x${"0".repeat(64)}`;
  assert.equal(validateExpectedCallResult(descriptor, zeroWord), "0");
  assert.equal(validateExpectedCallResult({ id: "bounded", kind: "uintRange", minimum: "0", maximum: "1000" }, `0x${"0".repeat(61)}3e8`), "1000");
  assert.throws(() => validateExpectedCallResult({ id: "bounded", kind: "uintRange", minimum: "0", maximum: "999" }, `0x${"0".repeat(61)}3e8`), /outside/i);
  assert.throws(() => validateExpectedCallResult({ ...descriptor, expected: "1" }, zeroWord), /drifted/i);
  assert.throws(() => validateExpectedCallResult({ ...descriptor, kind: "bool", expected: false }, `0x${"0".repeat(63)}2`), /boolean/i);
});

test("role calldata is exact and rejects malformed inputs", () => {
  const role = `0x${"ab".repeat(32)}`;
  assert.equal(roleCountCalldata(role), `0xca15c873${"ab".repeat(32)}`);
  assert.equal(roleMemberCalldata(role, 2), `0x9010d07c${"ab".repeat(32)}${"0".repeat(63)}2`);
  assert.throws(() => roleCountCalldata("0x01"), /bytes32/i);
  assert.throws(() => roleMemberCalldata(role, -1), /index/i);
});

test("baseline pins every protected enumerable protocol role", () => {
  assert.deepEqual(Object.keys(baseline.chain.expectedRoles).sort(), ["defaultAdmin", "minter", "state"]);
  assert.deepEqual(baseline.chain.expectedRoles.defaultAdmin.members, ["0xFf604BE032f144154667DC4aD551840F7eC09626"]);
  assert.deepEqual(baseline.chain.expectedRoles.minter.members, [
    "0x5652CEA58298445240Eb9AC8Fc4C69bA829c1bb5",
    "0x499Ae3f426a23dD02b4088cc3453cdA843850359",
  ]);
  assert.deepEqual(baseline.chain.expectedRoles.state.members, ["0xc7f28C66A891B6EB4d4fB0d0185160Af5A21d878"]);
});

test("baseline pins the exact Safe and live role-granted Agent Entry minter", () => {
  assert.equal(baseline.chain.safe.threshold, 2);
  assert.equal(baseline.chain.safe.owners.length, 3);
  assert.equal(baseline.chain.safe.nonce, 6);
  assert.deepEqual(baseline.chain.safe.modules, []);
  assert.equal(baseline.chain.safe.storageSlots.guard.expected, "0x0000000000000000000000000000000000000000");
  assert.equal(baseline.chain.safe.storageSlots.fallbackHandler.expected, "0xfd0732Dc9E303f09fCEf3a7388Ad10A83459Ec99");
  const protectedCalls = new Map(baseline.chain.expectedConfigurationCalls.map((entry) => [entry.id, entry]));
  assert.equal(protectedCalls.get("agentEntryMinter.paused")?.expected, false);
  assert.deepEqual(protectedCalls.get("agentEntryMinter.successfulMints"), {
    id: "agentEntryMinter.successfulMints",
    target: "agentEntryMinter",
    selector: "0x24bc439a",
    kind: "uintRange",
    minimum: "0",
    maximum: "1000",
  });
  assert.equal(baseline.chain.agentEntryBaseSupply, 8_488);
  assert.equal(baseline.chain.agentEntryMaximumMints, 1_000);
  assert.equal(protectedCalls.get("token.seedState.0")?.expected, "0x81d9d052b9145bebd2df6d8c2bb633b286ff91953ab36ebdaa2b5952b99be8f8");
});

test("Founder-away authority fails closed", () => {
  const safe = {
    automaticSocialPublishing: false,
    automaticWalletOutflow: false,
    automaticTrading: false,
    automaticIdentityAssignment: false,
    contractAdministration: false,
  };
  assert.doesNotThrow(() => assertFounderAwayAuthority(safe));
  assert.throws(() => assertFounderAwayAuthority({ ...safe, automaticWalletOutflow: true }), /automaticWalletOutflow/);
});

test("served HTTP security baseline pins every denial probe and status boundary", () => {
  assert.deepEqual(validateServedHttpSecurityConfiguration(baseline.servedHttpSecurity), {
    maximumDeniedBodyBytes: 65_536,
    siteDeniedPaths: 1,
    mcpDeniedPaths: 5,
  });
  assert.deepEqual(
    baseline.servedHttpSecurity.site.deniedPaths.map(({ url }) => new URL(url).pathname),
    ["/api/data/social-portraits-v1.json"],
  );
  assert.deepEqual(
    baseline.servedHttpSecurity.mcp.deniedPaths.map(({ url }) => new URL(url).pathname).sort(),
    [
      "/agent-entry.php.pre-status-hotfix-20260823",
      "/corpus/faq.json",
      "/lib.php",
      "/manifest.json",
      "/release-attestation.json",
    ],
  );
  const missingProbe = structuredClone(baseline.servedHttpSecurity);
  missingProbe.mcp.deniedPaths.pop();
  assert.throws(() => validateServedHttpSecurityConfiguration(missingProbe), /coverage drifted/i);
  const successAllowed = structuredClone(baseline.servedHttpSecurity);
  successAllowed.site.deniedPaths[0].allowedStatuses = [200, 403];
  assert.throws(() => validateServedHttpSecurityConfiguration(successAllowed), /allowed statuses drifted/i);
});

test("security-header validation is semantic and order-insensitive", () => {
  const site = validateSecurityHeaders(
    siteSecurityHeaders(),
    baseline.servedHttpSecurity.site.headers,
    "site response",
  );
  assert.equal(site.hstsMaxAgeSeconds, 63_072_000);
  assert.equal(site.hstsIncludeSubDomains, true);
  assert.equal(site.xFrameOptions, "DENY");
  assert.equal(site.cacheControl, null);

  const mcp = validateSecurityHeaders(
    mcpSecurityHeaders(),
    baseline.servedHttpSecurity.mcp.headers,
    "MCP response",
  );
  assert.equal(mcp.hstsMaxAgeSeconds, 31_536_000);
  assert.equal(mcp.cacheControl, "no-store");
});

test("security-header validation rejects transport, MIME, frame, and CSP weakening", () => {
  const policy = baseline.servedHttpSecurity.site.headers;
  assert.throws(() => validateSecurityHeaders(siteSecurityHeaders({
    "Strict-Transport-Security": "max-age=63072000",
  }), policy, "site response"), /includeSubDomains/i);
  assert.throws(() => validateSecurityHeaders(siteSecurityHeaders({
    "Strict-Transport-Security": "max-age=60; includeSubDomains",
  }), policy, "site response"), /fell below/i);
  assert.throws(() => validateSecurityHeaders(siteSecurityHeaders({
    "X-Content-Type-Options": "sniff",
  }), policy, "site response"), /nosniff/i);
  assert.throws(() => validateSecurityHeaders(siteSecurityHeaders({
    "X-Frame-Options": "SAMEORIGIN",
  }), policy, "site response"), /DENY/i);
  assert.throws(() => validateSecurityHeaders(siteSecurityHeaders({
    "Content-Security-Policy": "default-src 'self'; script-src 'self'; connect-src 'self' https://mcp.notforhumans.fun; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'noneevil'",
  }), policy, "site response"), /frame-ancestors/i);
  assert.throws(() => validateSecurityHeaders(siteSecurityHeaders({
    "Content-Security-Policy": "default-src 'self'; default-src 'self'; script-src 'self'; connect-src 'self' https://mcp.notforhumans.fun; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'",
  }), policy, "site response"), /duplicated.*default-src/i);
  assert.throws(() => validateSecurityHeaders(siteSecurityHeaders({
    "Content-Security-Policy": "default-src 'self'; script-src 'self' 'unsafe-inline'; connect-src 'self' https://mcp.notforhumans.fun; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'",
  }), policy, "site response"), /forbidden source/i);
});

test("MCP security-header validation rejects cacheable responses", () => {
  const policy = baseline.servedHttpSecurity.mcp.headers;
  assert.throws(() => validateSecurityHeaders(mcpSecurityHeaders({
    "Cache-Control": "no-cache, max-age=0",
  }), policy, "MCP response"), /no-store/i);
  assert.throws(() => validateSecurityHeaders(mcpSecurityHeaders({
    "Cache-Control": "no-store, public",
  }), policy, "MCP response"), /must not be public/i);
  assert.throws(() => validateSecurityHeaders(mcpSecurityHeaders({
    "Cache-Control": "no-store, max-age=60",
  }), policy, "MCP response"), /permits storage/i);
});

test("denied-response validation rejects success, unexpected status, source markers, and large bodies", () => {
  const maximumBodyBytes = baseline.servedHttpSecurity.maximumDeniedBodyBytes;
  const site = { ...baseline.servedHttpSecurity.site.deniedPaths[0], maximumBodyBytes };
  assert.deepEqual(validateDeniedResponse({ status: 403, body: "Forbidden" }, site), {
    status: 403,
    bodyBytes: 9,
    rawMarkersAbsent: 3,
  });
  assert.throws(() => validateDeniedResponse({ status: 404, body: "Not Found" }, site), /unexpected status 404/i);
  assert.throws(() => validateDeniedResponse({ status: 200, body: "Forbidden" }, site), /success status 200/i);

  const mcp = { ...baseline.servedHttpSecurity.mcp.deniedPaths[0], maximumBodyBytes };
  assert.equal(validateDeniedResponse({ status: 403, body: "Forbidden" }, mcp).status, 403);
  assert.equal(validateDeniedResponse({ status: 404, body: "Not Found" }, mcp).status, 404);
  assert.throws(() => validateDeniedResponse({ status: 500, body: "Error" }, mcp), /unexpected status 500/i);
  assert.throws(() => validateDeniedResponse({ status: 404, body: "const nfh_mcp_version = 'leaked';" }, mcp), /exposed raw marker/i);
  assert.throws(() => validateDeniedResponse({ status: 404, body: "12345" }, { ...mcp, maximumBodyBytes: 4 }), /byte bound/i);
});

test("Network Pulse keeps Accepted work separate from weaker activity", () => {
  const pulse = {
    schema: "nfh.network-pulse.v1",
    status: "active",
    release: {
      chainId: 1,
      mintedSupply: 8_488,
      claimStatus: "closed_capacity_filled",
      claimMinterPaused: true,
      governanceOwner: "0xff604be032f144154667dc4ad551840f7ec09626",
      governanceOwnerType: "verified-2-of-3-foundation-safe",
      protocolRolesFrozen: false,
    },
    network: {
      openMissions: 2,
      returnedUnverified: 1,
      acceptedReceipts: 0,
      distinctClientWallets: 0,
      distinctWorkerWallets: 0,
      repeatClientWallets: 0,
      visitorAcceptedReceipts: 0,
      activePresenceHeartbeats: 0,
      evidenceCoverageBps: 0,
    },
    authority: {
      automaticSocialPublishing: false,
      automaticWalletOutflow: false,
      automaticTrading: false,
      automaticIdentityAssignment: false,
      contractAdministration: false,
    },
    pulseHash: `0x${"a".repeat(64)}`,
    pulseHashType: "sha256-content-hash-not-signature",
  };
  assert.doesNotThrow(() => validateNetworkPulse(pulse));
  assert.throws(() => validateNetworkPulse({ ...pulse, network: { ...pulse.network, acceptedReceipts: -1 } }), /acceptedReceipts/);
  assert.throws(() => validateNetworkPulse({ ...pulse, release: { ...pulse.release, claimStatus: "open" } }), /claimStatus/);
});

test("Agent Presence storage telemetry fails before append capacity is exhausted", () => {
  const expected = baseline.feeds.presence.storage;
  const healthy = {
    bytes: 1_000_000,
    maxBytes: 20_000_000,
    utilizationBps: 500,
    warningThresholdBps: 8_000,
    healthy: true,
    compactionPolicy: "active-expiry-frontier-per-token-and-ownership-epoch",
  };
  assert.deepEqual(validatePresenceStorage(healthy, expected), {
    bytes: 1_000_000,
    maxBytes: 20_000_000,
    utilizationBps: 500,
    compactionPolicy: "active-expiry-frontier-per-token-and-ownership-epoch",
  });
  assert.throws(() => validatePresenceStorage({ ...healthy, utilizationBps: 8_000, healthy: false }, expected), /utilization/i);
  assert.throws(() => validatePresenceStorage({ ...healthy, maxBytes: 5_000_000 }, expected), /cap drifted/i);
  assert.throws(() => validatePresenceStorage({ ...healthy, compactionPolicy: "append-only" }, expected), /compaction policy/i);
});

test("Agent Entry monitoring pins the active gate while allowing bounded network growth", () => {
  const active = {
    schema: "nfh.agent-entry.v1",
    status: "claim_lane_active",
    reservationServiceEnabled: true,
    claimPreparationEnabled: true,
    liveMinter: "0x499ae3f426a23dd02b4088cc3453cda843850359",
    capacity: 1_000,
    successfulMints: 7,
    remainingMintCapacity: 993,
    activeReservations: 3,
    availableReservationSeats: 990,
    deployedMinterPaused: false,
    deployedMinterSuccessfulMints: 7,
    claimGate: { configured: true, ready: true, paused: false },
  };
  assert.doesNotThrow(() => validateAgentEntryStatus(active));
  assert.doesNotThrow(() => validateAgentEntryStatus({
    ...active,
    successfulMints: 8,
    remainingMintCapacity: 992,
    availableReservationSeats: 989,
    deployedMinterSuccessfulMints: 8,
  }));
  assert.throws(() => validateAgentEntryStatus({ ...active, reservationServiceEnabled: false }), /reservation service/i);
  assert.throws(() => validateAgentEntryStatus({ ...active, claimPreparationEnabled: false }), /claim preparation/i);
  assert.throws(() => validateAgentEntryStatus({ ...active, availableReservationSeats: 991 }), /available reservation seats/i);
  assert.throws(() => validateAgentEntryStatus({ ...active, claimGate: { configured: true, ready: false, paused: false } }), /claim gate/i);
});

test("Virtuals time-bounded binding stays historical and cannot authorize runtime", () => {
  const payload = { adapters: [{
    id: "virtuals-acp",
    state: "control-plane-registered-runtime-offline-execution-held",
    endpoint: null,
    allowedNow: [
      "schema mapping",
      "registered control-plane identity and hidden provider metadata",
      "provider-only non-executing planning",
      "local deterministic evidence tests",
      "fixed-URL read-only NFH resources",
      "exact read-only upstream and contract audit",
    ],
    historicalEvidence: {
      validUntil: "2026-08-29T15:36:18Z",
      runtimeReliance: false,
      renewalAuthorized: false,
    },
    blockedByNFH: [
      "unattended event listener",
      "production SDK dependency adoption",
      "wallet-execution adapter",
      "budget or escrow funding",
      "job submission or settlement",
      "transaction submission",
      "automatic job acceptance",
      "trading",
      "ERC-8004 registration",
      "token launch",
    ],
  }] };
  assert.doesNotThrow(() => validateVirtualsBoundary(payload));
  assert.throws(() => validateVirtualsBoundary({ adapters: [{ ...payload.adapters[0], allowedNow: [...payload.adapters[0].allowedNow, "trading"] }] }), /allowedNow/i);
  assert.throws(() => validateVirtualsBoundary({ adapters: [{ ...payload.adapters[0], blockedByNFH: payload.adapters[0].blockedByNFH.filter((value) => value !== "budget or escrow funding") }] }), /held-action/i);
  assert.throws(() => validateVirtualsBoundary({ adapters: [{ ...payload.adapters[0], historicalEvidence: { ...payload.adapters[0].historicalEvidence, runtimeReliance: true } }] }), /runtime authority/i);
});

test("founder-away baseline pins exact static and MCP release-attestation identities", () => {
  const { staticSite, mcpServer, recurringLiveReadLimits } = baseline.releaseAttestations;
  for (const expected of [staticSite, mcpServer]) {
    assert.equal(expected.schema, "nfh.release-tree-attestation.v1");
    assert.equal(expected.sourceRelease, baseline.baseline.sourceRelease);
    assert.match(expected.documentSha256, /^[0-9a-f]{64}$/);
    assert.match(expected.treeSha256, /^[0-9a-f]{64}$/);
    assert.ok(Number.isSafeInteger(expected.treeFiles) && expected.treeFiles > 0);
    assert.ok(Number.isSafeInteger(expected.treeBytes) && expected.treeBytes > 0);
  }
  assert.equal(staticSite.scope, "static-site");
  assert.equal(mcpServer.scope, "mcp-server");
  assert.ok(staticSite.liveFiles <= recurringLiveReadLimits.maximumFiles);
  assert.ok(staticSite.liveBytes <= recurringLiveReadLimits.maximumBytes);
});

test("live release verifier hashes every listed static byte and rejects one-byte drift", async () => {
  const a = Buffer.from("alpha\n");
  const b = Buffer.from("beta\n");
  const entries = [
    { path: "a.txt", bytes: a.byteLength, sha256: sha256Hex(a), liveVerification: { mode: "required" } },
    { path: "nested/b.txt", bytes: b.byteLength, sha256: sha256Hex(b), liveVerification: { mode: "required" } },
  ];
  const attestation = {
    schema: "nfh.release-tree-attestation.v1",
    scope: "static-site",
    sourceRelease: "v0.10.0-mainnet-rc.90",
    hashAlgorithm: "sha256",
    treeHashAlgorithm: "sha256(concat(utf8(path),NUL,decimal(bytes),NUL,lowercase_sha256,LF),paths_sorted_bytewise)",
    selfExcludedPath: ".well-known/release-attestation.json",
    tree: { files: 2, bytes: a.byteLength + b.byteLength, sha256: computeReleaseTreeSha256(entries) },
    entries,
    authority: { wallet: false, signing: false, transactions: false, deployment: false, repair: false, publishing: false },
    liveVerification: {
      mode: "hash-every-listed-public-static-file",
      requiredFiles: 2,
      requiredBytes: a.byteLength + b.byteLength,
      exclusions: [],
      boundary: "test",
    },
  };
  const raw = Buffer.from(`${JSON.stringify(attestation)}\n`);
  const config = {
    staticSite: {
      url: "https://example.test/.well-known/release-attestation.json",
      schema: attestation.schema,
      scope: attestation.scope,
      sourceRelease: attestation.sourceRelease,
      documentSha256: sha256Hex(raw),
      treeSha256: attestation.tree.sha256,
      treeFiles: attestation.tree.files,
      treeBytes: attestation.tree.bytes,
      liveFiles: 2,
      liveBytes: a.byteLength + b.byteLength,
    },
    recurringLiveReadLimits: { maximumFiles: 4, maximumBytes: 100, concurrency: 2 },
  };
  const originalFetch = globalThis.fetch;
  let corrupt = false;
  globalThis.fetch = async (url) => {
    const href = String(url);
    if (href === config.staticSite.url) return new Response(raw, { status: 200 });
    if (href === "https://example.test/a.txt") return new Response(a, { status: 200 });
    if (href === "https://example.test/nested/b.txt") return new Response(corrupt ? Buffer.from("Beta\n") : b, { status: 200 });
    return new Response("missing", { status: 404 });
  };
  try {
    const result = await verifyStaticReleaseAttestation({ baseUrl: "https://example.test" }, config);
    assert.equal(result.liveFilesVerified, 2);
    corrupt = true;
    await assert.rejects(
      () => verifyStaticReleaseAttestation({ baseUrl: "https://example.test" }, config),
      /SHA-256 drifted/i,
    );
  } finally {
    globalThis.fetch = originalFetch;
  }
});

test("MCP attestation check pins the exact reported document without overstating hidden-source proof", async () => {
  const entries = [{ path: "index.php", bytes: 3, sha256: sha256Hex(Buffer.from("php")) }];
  const attestation = {
    schema: "nfh.release-tree-attestation.v1",
    scope: "mcp-server",
    sourceRelease: "v0.10.0-mainnet-rc.90",
    hashAlgorithm: "sha256",
    treeHashAlgorithm: "sha256(concat(utf8(path),NUL,decimal(bytes),NUL,lowercase_sha256,LF),paths_sorted_bytewise)",
    selfExcludedPath: "release-attestation.json",
    tree: { files: 1, bytes: 3, sha256: computeReleaseTreeSha256(entries) },
    entries,
    authority: { wallet: false, signing: false, transactions: false, deployment: false, repair: false, publishing: false },
    liveVerification: {
      mode: "server-reported-tree-only",
      endpoint: "https://mcp.example.test/release-attestation",
      boundary: "This is not independent cryptographic proof of hidden PHP bytes.",
    },
  };
  const raw = Buffer.from(`${JSON.stringify(attestation)}\n`);
  const releaseConfig = { mcpServer: {
    url: attestation.liveVerification.endpoint,
    schema: attestation.schema,
    scope: attestation.scope,
    sourceRelease: attestation.sourceRelease,
    documentSha256: sha256Hex(raw),
    treeSha256: attestation.tree.sha256,
    treeFiles: 1,
    treeBytes: 3,
    liveFiles: null,
    liveBytes: null,
  } };
  const originalFetch = globalThis.fetch;
  globalThis.fetch = async () => new Response(raw, { status: 200 });
  try {
    const result = await verifyMcpReleaseAttestation(releaseConfig);
    assert.equal(result.proofBoundary, "server-reported-tree-digest-not-independent-proof-of-hidden-php-bytes");
  } finally {
    globalThis.fetch = originalFetch;
  }
});

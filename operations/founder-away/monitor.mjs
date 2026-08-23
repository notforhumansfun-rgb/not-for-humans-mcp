#!/usr/bin/env node

import { createHash } from "node:crypto";
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath, pathToFileURL } from "node:url";

const here = path.dirname(fileURLToPath(import.meta.url));

function invariant(condition, message) {
  if (!condition) throw new Error(message);
}

const RELEASE_ATTESTATION_SCHEMA = "nfh.release-tree-attestation.v1";
const RELEASE_TREE_HASH_ALGORITHM = "sha256(concat(utf8(path),NUL,decimal(bytes),NUL,lowercase_sha256,LF),paths_sorted_bytewise)";

export function sha256Hex(value) {
  return createHash("sha256").update(value).digest("hex");
}

function assertReleasePath(relativePath) {
  invariant(typeof relativePath === "string" && relativePath.length > 0, "Release attestation path is empty.");
  invariant(!relativePath.startsWith("/") && !relativePath.includes("\\"), `Unsafe release attestation path: ${relativePath}`);
  invariant(!/[\0-\x1f\x7f]/u.test(relativePath), `Release attestation path contains a control character: ${relativePath}`);
  const segments = relativePath.split("/");
  invariant(segments.every((segment) => segment !== "" && segment !== "." && segment !== ".."), `Unsafe release attestation path: ${relativePath}`);
  return relativePath;
}

function compareUtf8(left, right) {
  return Buffer.from(left, "utf8").compare(Buffer.from(right, "utf8"));
}

export function computeReleaseTreeSha256(entries) {
  const hash = createHash("sha256");
  for (const entry of entries) {
    assertReleasePath(entry.path);
    invariant(Number.isSafeInteger(entry.bytes) && entry.bytes >= 0, `Invalid attested byte count for ${entry.path}.`);
    invariant(typeof entry.sha256 === "string" && /^[0-9a-f]{64}$/.test(entry.sha256), `Invalid attested SHA-256 for ${entry.path}.`);
    hash.update(entry.path, "utf8");
    hash.update("\0", "utf8");
    hash.update(String(entry.bytes), "utf8");
    hash.update("\0", "utf8");
    hash.update(entry.sha256, "ascii");
    hash.update("\n", "utf8");
  }
  return hash.digest("hex");
}

export function validateReleaseAttestation(attestation, expected) {
  invariant(attestation && typeof attestation === "object" && !Array.isArray(attestation), "Release attestation must be an object.");
  invariant(attestation.schema === RELEASE_ATTESTATION_SCHEMA && attestation.schema === expected.schema, "Release attestation schema drifted.");
  invariant(attestation.scope === expected.scope, `Release attestation scope drifted to ${attestation.scope}.`);
  invariant(attestation.sourceRelease === expected.sourceRelease, `Release attestation source release drifted to ${attestation.sourceRelease}.`);
  invariant(attestation.hashAlgorithm === "sha256", "Release attestation hash algorithm drifted.");
  invariant(attestation.treeHashAlgorithm === RELEASE_TREE_HASH_ALGORITHM, "Release attestation tree algorithm drifted.");
  assertReleasePath(attestation.selfExcludedPath);
  invariant(Array.isArray(attestation.entries) && attestation.entries.length > 0, "Release attestation entries are missing.");
  invariant(attestation.entries.length === expected.treeFiles, "Release attestation file count drifted.");

  let previousPath = null;
  let totalBytes = 0;
  const seen = new Set();
  for (const entry of attestation.entries) {
    invariant(entry && typeof entry === "object" && !Array.isArray(entry), "Release attestation entry is invalid.");
    assertReleasePath(entry.path);
    invariant(entry.path !== attestation.selfExcludedPath, "Release attestation includes its own self-excluded path.");
    invariant(!seen.has(entry.path), `Duplicate release attestation path: ${entry.path}`);
    invariant(previousPath === null || compareUtf8(previousPath, entry.path) < 0, "Release attestation paths are not strictly bytewise sorted.");
    invariant(Number.isSafeInteger(entry.bytes) && entry.bytes >= 0, `Invalid attested byte count for ${entry.path}.`);
    invariant(typeof entry.sha256 === "string" && /^[0-9a-f]{64}$/.test(entry.sha256), `Invalid attested SHA-256 for ${entry.path}.`);
    totalBytes += entry.bytes;
    invariant(Number.isSafeInteger(totalBytes), "Release attestation byte total is outside the safe integer range.");
    previousPath = entry.path;
    seen.add(entry.path);
  }
  invariant(attestation.tree?.files === attestation.entries.length, "Release attestation tree file count is internally inconsistent.");
  invariant(attestation.tree?.bytes === totalBytes && totalBytes === expected.treeBytes, "Release attestation tree byte count drifted.");
  const computedTreeSha256 = computeReleaseTreeSha256(attestation.entries);
  invariant(attestation.tree?.sha256 === computedTreeSha256, "Release attestation tree digest is internally inconsistent.");
  invariant(computedTreeSha256 === expected.treeSha256, "Release attestation tree digest drifted from the founder-away baseline.");
  invariant(
    attestation.authority?.wallet === false
      && attestation.authority?.signing === false
      && attestation.authority?.transactions === false
      && attestation.authority?.deployment === false
      && attestation.authority?.repair === false
      && attestation.authority?.publishing === false,
    "Release attestation gained mutation authority.",
  );

  if (attestation.scope === "static-site") {
    invariant(attestation.selfExcludedPath === ".well-known/release-attestation.json", "Static-site attestation self-exclusion drifted.");
    invariant(attestation.liveVerification?.mode === "hash-every-listed-public-static-file", "Static-site live verification mode drifted.");
    const requiredEntries = attestation.entries.filter((entry) => entry.liveVerification?.mode === "required");
    const excludedEntries = attestation.entries.filter((entry) => entry.liveVerification?.mode === "deployment-only");
    invariant(requiredEntries.length + excludedEntries.length === attestation.entries.length, "Static-site attestation has an unclassified live-verification entry.");
    invariant(excludedEntries.every((entry) => typeof entry.liveVerification.reason === "string" && entry.liveVerification.reason.length > 0), "Static-site live exclusion lacks a reason.");
    const requiredBytes = requiredEntries.reduce((sum, entry) => sum + entry.bytes, 0);
    invariant(attestation.liveVerification.requiredFiles === requiredEntries.length, "Static-site live file count is internally inconsistent.");
    invariant(attestation.liveVerification.requiredBytes === requiredBytes, "Static-site live byte count is internally inconsistent.");
    invariant(requiredEntries.length === expected.liveFiles && requiredBytes === expected.liveBytes, "Static-site recurring live-read set drifted.");
    const exclusions = excludedEntries.map((entry) => ({ path: entry.path, reason: entry.liveVerification.reason }));
    invariant(JSON.stringify(attestation.liveVerification.exclusions) === JSON.stringify(exclusions), "Static-site live exclusions drifted.");
    return { entries: attestation.entries, requiredEntries };
  }

  invariant(attestation.scope === "mcp-server", "Unsupported release attestation scope.");
  invariant(attestation.selfExcludedPath === "release-attestation.json", "MCP attestation self-exclusion drifted.");
  invariant(attestation.liveVerification?.mode === "server-reported-tree-only", "MCP live verification boundary drifted.");
  invariant(attestation.liveVerification?.endpoint === expected.url, "MCP release-attestation endpoint drifted.");
  invariant(/not independent cryptographic proof/iu.test(attestation.liveVerification?.boundary ?? ""), "MCP hidden-source proof limitation is missing.");
  return { entries: attestation.entries, requiredEntries: [] };
}

export function requireQuorum(results) {
  const counts = new Map();
  for (const result of results) {
    if (result === null || result === undefined) continue;
    const normalized = typeof result === "string" ? result.toLowerCase() : JSON.stringify(result);
    counts.set(normalized, (counts.get(normalized) ?? 0) + 1);
  }
  const ranked = [...counts.entries()].sort((left, right) => right[1] - left[1]);
  invariant(ranked.length > 0, "RPC quorum unavailable: no valid provider results.");
  const leader = ranked[0];
  const runnerUp = ranked[1]?.[1] ?? 0;
  invariant(leader[1] >= 2 && leader[1] !== runnerUp, "RPC quorum unavailable: two exact providers did not agree.");
  return leader[0];
}

export function chooseAnchorBlock(blocks, maxSkewBlocks = 12) {
  const healthy = blocks.filter((value) => Number.isSafeInteger(value) && value > 2);
  invariant(healthy.length >= 2, "At least two healthy providers are required to choose an anchor block.");
  invariant(Number.isSafeInteger(maxSkewBlocks) && maxSkewBlocks >= 0, "RPC tip skew bound is invalid.");
  let bestPair = null;
  for (let left = 0; left < healthy.length; left += 1) {
    for (let right = left + 1; right < healthy.length; right += 1) {
      const lower = Math.min(healthy[left], healthy[right]);
      const upper = Math.max(healthy[left], healthy[right]);
      if (upper - lower > maxSkewBlocks) continue;
      if (bestPair === null || lower > bestPair.lower) bestPair = { lower, upper };
    }
  }
  invariant(bestPair !== null, `RPC tips did not produce two providers within ${maxSkewBlocks} blocks.`);
  return bestPair.lower - 2;
}

export function assertFreshAnchorBlock(block, nowSeconds, maxAgeSeconds) {
  invariant(block && typeof block === "object", "Quorum anchor block is missing.");
  invariant(typeof block.timestamp === "string" && /^0x[0-9a-fA-F]+$/.test(block.timestamp), "Quorum anchor timestamp is invalid.");
  invariant(Number.isSafeInteger(nowSeconds) && Number.isSafeInteger(maxAgeSeconds) && maxAgeSeconds > 0, "Anchor freshness bounds are invalid.");
  const timestamp = Number.parseInt(block.timestamp, 16);
  invariant(Number.isSafeInteger(timestamp), "Quorum anchor timestamp is outside the safe integer range.");
  invariant(timestamp <= nowSeconds + 60, "Quorum anchor timestamp is unexpectedly in the future.");
  invariant(nowSeconds - timestamp <= maxAgeSeconds, `Quorum anchor block is older than ${maxAgeSeconds} seconds.`);
  return timestamp;
}

export function quorumAnchorTimestamp(blocks, anchorHash) {
  invariant(typeof anchorHash === "string" && /^0x[0-9a-fA-F]{64}$/.test(anchorHash), "Quorum anchor hash is invalid.");
  const timestamps = blocks
    .filter((block) => block?.hash?.toLowerCase() === anchorHash.toLowerCase())
    .map((block) => block?.timestamp ?? null);
  const timestampHex = requireQuorum(timestamps);
  invariant(/^0x[0-9a-f]+$/.test(timestampHex), "Quorum anchor timestamp is invalid.");
  return Number.parseInt(timestampHex, 16);
}

export function decodeUint(value) {
  invariant(typeof value === "string" && /^0x[0-9a-fA-F]{64}$/.test(value), "Expected one 32-byte EVM uint response.");
  return BigInt(value);
}

export function decodeAddress(value) {
  invariant(typeof value === "string" && /^0x[0-9a-fA-F]{64}$/.test(value), "Expected one 32-byte EVM address response.");
  const address = `0x${value.slice(-40).toLowerCase()}`;
  invariant(!/^0x0{40}$/.test(address), "EVM address response was zero.");
  return address;
}

export function decodeNullableAddress(value) {
  invariant(typeof value === "string" && /^0x[0-9a-fA-F]{64}$/.test(value), "Expected one 32-byte EVM address response.");
  invariant(/^0x0{24}[0-9a-fA-F]{40}$/.test(value), "EVM address response has non-canonical padding.");
  return `0x${value.slice(-40).toLowerCase()}`;
}

function splitWords(value, label) {
  invariant(typeof value === "string" && /^0x(?:[0-9a-fA-F]{64})+$/.test(value), `${label} is not canonical word-aligned EVM data.`);
  const body = value.slice(2).toLowerCase();
  return Array.from({ length: body.length / 64 }, (_unused, index) => body.slice(index * 64, (index + 1) * 64));
}

export function decodeAddressArray(value) {
  const words = splitWords(value, "Address array response");
  invariant(words.length >= 2 && BigInt(`0x${words[0]}`) === 32n, "Address array offset is invalid.");
  const length = Number(BigInt(`0x${words[1]}`));
  invariant(Number.isSafeInteger(length) && words.length === length + 2, "Address array length is invalid.");
  return words.slice(2).map((word) => decodeNullableAddress(`0x${word}`));
}

export function decodeAddressArrayAndAddress(value) {
  const words = splitWords(value, "Address-array tuple response");
  invariant(words.length >= 3 && BigInt(`0x${words[0]}`) === 64n, "Address-array tuple offset is invalid.");
  const next = decodeNullableAddress(`0x${words[1]}`);
  const length = Number(BigInt(`0x${words[2]}`));
  invariant(Number.isSafeInteger(length) && words.length === length + 3, "Address-array tuple length is invalid.");
  const addresses = words.slice(3).map((word) => decodeNullableAddress(`0x${word}`));
  return { addresses, next };
}

export function decodeString(value) {
  const words = splitWords(value, "String response");
  invariant(words.length >= 2 && BigInt(`0x${words[0]}`) === 32n, "String offset is invalid.");
  const length = Number(BigInt(`0x${words[1]}`));
  invariant(Number.isSafeInteger(length) && length >= 0, "String length is invalid.");
  const encoded = words.slice(2).join("");
  invariant(encoded.length === Math.ceil(length / 32) * 64, "String response length is non-canonical.");
  const content = encoded.slice(0, length * 2);
  const padding = encoded.slice(length * 2);
  invariant(/^0*$/.test(padding), "String response padding is nonzero.");
  return Buffer.from(content, "hex").toString("utf8");
}

function evmWord(value, label) {
  invariant(typeof value === "string" && /^0x[0-9a-fA-F]{64}$/.test(value), `${label} must be one bytes32 word.`);
  return value.slice(2).toLowerCase();
}

export function roleCountCalldata(role) {
  return `0xca15c873${evmWord(role, "Role")}`;
}

export function roleMemberCalldata(role, index) {
  invariant(Number.isSafeInteger(index) && index >= 0, "Role member index must be a non-negative safe integer.");
  return `0x9010d07c${evmWord(role, "Role")}${index.toString(16).padStart(64, "0")}`;
}

function uintWord(value, label) {
  const integer = BigInt(value);
  invariant(integer >= 0n && integer < (1n << 256n), `${label} is outside uint256.`);
  return integer.toString(16).padStart(64, "0");
}

export function exactCallData(descriptor) {
  invariant(descriptor && typeof descriptor === "object", "Expected-call descriptor is missing.");
  invariant(typeof descriptor.selector === "string" && /^0x[0-9a-fA-F]{8}$/.test(descriptor.selector), `${descriptor.id ?? "Expected call"} selector is invalid.`);
  const args = descriptor.uintArgs ?? [];
  invariant(Array.isArray(args), `${descriptor.id ?? "Expected call"} uintArgs is invalid.`);
  return `${descriptor.selector.toLowerCase()}${args.map((value) => uintWord(value, `${descriptor.id ?? "Expected call"} argument`)).join("")}`;
}

export function validateExpectedCallResult(descriptor, raw, rawHash = null) {
  const label = descriptor.id ?? "Expected contract call";
  let actual;
  if (descriptor.kind === "uint") actual = decodeUint(raw).toString();
  else if (descriptor.kind === "uintRange") {
    const integer = decodeUint(raw);
    const minimum = BigInt(descriptor.minimum);
    const maximum = BigInt(descriptor.maximum);
    invariant(minimum >= 0n && maximum >= minimum, `${label} range is invalid.`);
    invariant(integer >= minimum && integer <= maximum, `${label} drifted outside ${minimum}..${maximum} to ${integer}.`);
    return integer.toString();
  }
  else if (descriptor.kind === "bool") {
    const integer = decodeUint(raw);
    invariant(integer === 0n || integer === 1n, `${label} returned a non-canonical boolean.`);
    actual = integer === 1n;
  } else if (descriptor.kind === "address") actual = decodeNullableAddress(raw);
  else if (descriptor.kind === "bytes32") {
    invariant(/^0x[0-9a-fA-F]{64}$/.test(raw), `${label} returned an invalid bytes32 value.`);
    actual = raw.toLowerCase();
  } else if (descriptor.kind === "rawHash") {
    invariant(typeof rawHash === "string" && /^0x[0-9a-fA-F]{64}$/.test(rawHash), `${label} return hash is invalid.`);
    actual = rawHash.toLowerCase();
  } else {
    throw new Error(`${label} has an unsupported result kind.`);
  }
  const expected = typeof descriptor.expected === "string" ? descriptor.expected.toLowerCase() : descriptor.expected;
  invariant(actual === expected, `${label} drifted to ${String(actual)}.`);
  return actual;
}

export function assertFounderAwayAuthority(authority) {
  invariant(authority && typeof authority === "object", "Founder-away authority object is missing.");
  for (const field of [
    "automaticSocialPublishing",
    "automaticWalletOutflow",
    "automaticTrading",
    "automaticIdentityAssignment",
    "contractAdministration",
  ]) {
    invariant(authority[field] === false, `${field} must remain false during founder-away operation.`);
  }
}

export function validateNetworkPulse(pulse) {
  invariant(pulse && typeof pulse === "object", "Network Pulse payload is missing.");
  invariant(pulse.schema === "nfh.network-pulse.v1", "Network Pulse schema is invalid.");
  invariant(["active", "degraded"].includes(pulse.status), "Network Pulse status is invalid.");
  invariant(pulse.release?.chainId === 1, "Network Pulse chainId is not Ethereum mainnet.");
  invariant(pulse.release?.mintedSupply === 8_488, "Network Pulse minted supply drifted from the frozen baseline.");
  invariant(pulse.release?.claimStatus === "closed_capacity_filled", "Network Pulse claimStatus must remain closed_capacity_filled.");
  invariant(pulse.release?.claimMinterPaused === true, "Network Pulse claim minter must remain paused.");
  invariant(pulse.release?.governanceOwner === "0xff604be032f144154667dc4ad551840f7ec09626", "Network Pulse governance owner drifted from the Foundation Safe.");
  invariant(pulse.release?.governanceOwnerType === "verified-2-of-3-foundation-safe", "Network Pulse governance owner type is invalid.");
  invariant(pulse.release?.protocolRolesFrozen === false, "Network Pulse protocol-role freeze state drifted.");
  const numericFields = [
    "openMissions",
    "returnedUnverified",
    "acceptedReceipts",
    "distinctClientWallets",
    "distinctWorkerWallets",
    "repeatClientWallets",
    "visitorAcceptedReceipts",
    "activePresenceHeartbeats",
    "evidenceCoverageBps",
  ];
  for (const field of numericFields) {
    invariant(Number.isInteger(pulse.network?.[field]) && pulse.network[field] >= 0, `Network Pulse ${field} must be a non-negative integer.`);
  }
  invariant(pulse.network.evidenceCoverageBps <= 10_000, "Network Pulse evidenceCoverageBps exceeds 10,000.");
  invariant(pulse.network.distinctClientWallets <= pulse.network.acceptedReceipts, "Distinct clients cannot exceed Accepted receipts.");
  invariant(pulse.network.distinctWorkerWallets <= pulse.network.acceptedReceipts, "Distinct workers cannot exceed Accepted receipts.");
  assertFounderAwayAuthority(pulse.authority);
  invariant(/^0x[0-9a-f]{64}$/.test(pulse.pulseHash ?? ""), "Network Pulse content hash is invalid.");
  invariant(pulse.pulseHashType === "sha256-content-hash-not-signature", "Network Pulse must not represent its content hash as a signature.");
}

export function validatePresenceStorage(storage, expected) {
  invariant(storage && typeof storage === "object", "Agent Presence storage telemetry is missing.");
  invariant(expected && typeof expected === "object", "Agent Presence storage baseline is missing.");
  invariant(Number.isInteger(storage.bytes) && storage.bytes >= 0, "Agent Presence storage bytes are invalid.");
  invariant(storage.maxBytes === expected.maxBytes, `Agent Presence storage cap drifted to ${storage.maxBytes}.`);
  invariant(Number.isInteger(storage.utilizationBps) && storage.utilizationBps >= 0 && storage.utilizationBps <= 10_000,
    "Agent Presence storage utilization is invalid.");
  invariant(storage.utilizationBps < expected.maximumUtilizationBps,
    `Agent Presence storage utilization reached ${storage.utilizationBps} BPS.`);
  invariant(storage.healthy === true, "Agent Presence storage reports unhealthy.");
  invariant(storage.compactionPolicy === expected.compactionPolicy, "Agent Presence compaction policy drifted.");
  return {
    bytes: storage.bytes,
    maxBytes: storage.maxBytes,
    utilizationBps: storage.utilizationBps,
    compactionPolicy: storage.compactionPolicy,
  };
}

export function validateAgentEntryStatus(payload) {
  invariant(payload?.schema === "nfh.agent-entry.v1", "Agent Entry schema is invalid.");
  invariant(payload.status === "claim_lane_active", `Agent Entry status drifted to ${payload.status}.`);
  invariant(payload.reservationServiceEnabled === true, "Agent Entry reservation service is not enabled.");
  invariant(payload.claimPreparationEnabled === true, "Agent Entry claim preparation is not enabled.");
  invariant(payload.liveMinter === "0x499ae3f426a23dd02b4088cc3453cda843850359", "Agent Entry live minter drifted.");
  invariant(payload.capacity === 1_000, "Agent Entry capacity drifted.");
  invariant(Number.isInteger(payload.successfulMints) && payload.successfulMints >= 0 && payload.successfulMints <= payload.capacity,
    "Agent Entry successful mint count is invalid.");
  invariant(Number.isInteger(payload.activeReservations) && payload.activeReservations >= 0
    && payload.activeReservations <= payload.capacity - payload.successfulMints,
  "Agent Entry active reservation count is invalid.");
  invariant(payload.remainingMintCapacity === payload.capacity - payload.successfulMints,
    "Agent Entry remaining mint capacity is inconsistent.");
  invariant(payload.availableReservationSeats === payload.capacity - payload.successfulMints - payload.activeReservations,
    "Agent Entry available reservation seats are inconsistent.");
  invariant(payload.deployedMinterPaused === false, "Agent Entry deployed minter unexpectedly paused.");
  invariant(payload.deployedMinterSuccessfulMints === payload.successfulMints,
    "Agent Entry endpoint disagrees with the live minter mint count.");
  invariant(payload.claimGate?.configured === true && payload.claimGate?.ready === true && payload.claimGate?.paused === false,
    "Agent Entry live claim gate is not ready.");
}

export function validateVirtualsBoundary(payload) {
  const expectedAllowedNow = [
    "schema mapping",
    "registered control-plane identity and hidden provider metadata",
    "provider-only non-executing planning",
    "local deterministic evidence tests",
    "fixed-URL read-only NFH resources",
    "exact read-only upstream and contract audit",
  ];
  const expectedBlockedByNFH = [
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
  ];
  const adapter = payload?.adapters?.find((entry) => entry?.id === "virtuals-acp");
  invariant(adapter, "Virtuals ACP integration metadata is missing.");
  invariant(adapter.state === "control-plane-registered-runtime-offline-execution-held", "Virtuals ACP state drifted.");
  invariant(adapter.endpoint === null, "Virtuals ACP unexpectedly advertises a live endpoint.");
  invariant(Array.isArray(adapter.allowedNow)
    && JSON.stringify(adapter.allowedNow) === JSON.stringify(expectedAllowedNow), "Virtuals ACP allowedNow boundary drifted.");
  invariant(adapter.historicalEvidence?.runtimeReliance === false, "Virtuals binding became runtime authority.");
  invariant(adapter.historicalEvidence?.renewalAuthorized === false, "Virtuals binding renewal became authorized.");
  invariant(Number.isFinite(Date.parse(adapter.historicalEvidence?.validUntil ?? "")), "Virtuals historical binding expiry is invalid.");
  invariant(Array.isArray(adapter.blockedByNFH)
    && JSON.stringify(adapter.blockedByNFH) === JSON.stringify(expectedBlockedByNFH), "Virtuals ACP held-action boundary drifted.");
  return adapter;
}

function requiredHeader(headers, name, label) {
  invariant(headers && typeof headers.get === "function", `${label} headers are unavailable.`);
  const value = headers.get(name);
  invariant(typeof value === "string" && value.trim() !== "", `${label} is missing ${name}.`);
  invariant(!/[\r\n]/u.test(value), `${label} returned an invalid ${name} value.`);
  return value.trim();
}

function parseHsts(value, label) {
  invariant(!value.includes(","), `${label} returned multiple Strict-Transport-Security policies.`);
  const directives = new Map();
  for (const part of value.split(";")) {
    const directive = part.trim();
    if (directive === "") continue;
    const match = /^([a-z][a-z0-9-]*)(?:\s*=\s*([^\s;]+))?$/iu.exec(directive);
    invariant(match, `${label} returned malformed Strict-Transport-Security.`);
    const name = match[1].toLowerCase();
    invariant(!directives.has(name), `${label} duplicated the ${name} HSTS directive.`);
    directives.set(name, match[2] ?? null);
  }
  return directives;
}

function parseContentSecurityPolicy(value, label) {
  invariant(!value.includes(","), `${label} returned multiple Content-Security-Policy values.`);
  const directives = new Map();
  for (const part of value.split(";")) {
    const fields = part.trim().split(/\s+/u).filter(Boolean);
    if (fields.length === 0) continue;
    const name = fields.shift().toLowerCase();
    invariant(/^[a-z][a-z0-9-]*$/u.test(name), `${label} returned a malformed CSP directive.`);
    invariant(!directives.has(name), `${label} duplicated the ${name} CSP directive.`);
    invariant(new Set(fields).size === fields.length, `${label} duplicated a ${name} CSP source.`);
    directives.set(name, fields);
  }
  invariant(directives.size > 0, `${label} returned an empty Content-Security-Policy.`);
  return directives;
}

function parseCacheControl(value, label) {
  const directives = new Map();
  for (const part of value.split(",")) {
    const directive = part.trim();
    invariant(directive !== "", `${label} returned malformed Cache-Control.`);
    const match = /^([!#$%&'*+.^_`|~0-9a-z-]+)(?:\s*=\s*("[^"]*"|[^\s,]+))?$/iu.exec(directive);
    invariant(match, `${label} returned malformed Cache-Control.`);
    const name = match[1].toLowerCase();
    invariant(!directives.has(name), `${label} duplicated the ${name} Cache-Control directive.`);
    directives.set(name, match[2] ?? null);
  }
  return directives;
}

export function validateSecurityHeaders(headers, policy, label = "HTTP response") {
  invariant(policy && typeof policy === "object" && !Array.isArray(policy), `${label} security-header policy is missing.`);
  invariant(Number.isSafeInteger(policy.minimumHstsMaxAgeSeconds) && policy.minimumHstsMaxAgeSeconds > 0, `${label} HSTS baseline is invalid.`);

  const hsts = parseHsts(requiredHeader(headers, "strict-transport-security", label), label);
  const maxAgeRaw = hsts.get("max-age");
  invariant(typeof maxAgeRaw === "string" && /^\d+$/u.test(maxAgeRaw), `${label} HSTS max-age is invalid.`);
  const maxAge = Number(maxAgeRaw);
  invariant(Number.isSafeInteger(maxAge) && maxAge >= policy.minimumHstsMaxAgeSeconds, `${label} HSTS max-age fell below ${policy.minimumHstsMaxAgeSeconds}.`);
  invariant(hsts.has("includesubdomains") && hsts.get("includesubdomains") === null, `${label} HSTS must include includeSubDomains.`);

  invariant(requiredHeader(headers, "x-content-type-options", label).toLowerCase() === "nosniff", `${label} X-Content-Type-Options must be nosniff.`);
  invariant(requiredHeader(headers, "x-frame-options", label).toLowerCase() === "deny", `${label} X-Frame-Options must be DENY.`);

  const requiredCsp = policy.requiredCspDirectives;
  invariant(requiredCsp && typeof requiredCsp === "object" && !Array.isArray(requiredCsp) && Object.keys(requiredCsp).length > 0, `${label} required CSP baseline is invalid.`);
  const csp = parseContentSecurityPolicy(requiredHeader(headers, "content-security-policy", label), label);
  for (const [rawName, requiredSources] of Object.entries(requiredCsp)) {
    const name = rawName.toLowerCase();
    invariant(Array.isArray(requiredSources), `${label} ${name} CSP baseline is invalid.`);
    const actualSources = csp.get(name);
    invariant(actualSources, `${label} CSP is missing ${name}.`);
    for (const source of requiredSources) {
      invariant(typeof source === "string" && source !== "", `${label} ${name} CSP baseline has an invalid source.`);
      invariant(actualSources.includes(source), `${label} CSP ${name} is missing ${source}.`);
    }
    if (requiredSources.includes("'none'")) {
      invariant(actualSources.length === 1 && actualSources[0] === "'none'", `${label} CSP ${name} must be exclusively 'none'.`);
    }
  }

  const exactDirectives = policy.exactCspDirectives ?? [];
  invariant(Array.isArray(exactDirectives), `${label} exact CSP baseline is invalid.`);
  for (const rawName of exactDirectives) {
    const name = String(rawName).toLowerCase();
    const expected = requiredCsp[name];
    const actual = csp.get(name);
    invariant(Array.isArray(expected) && actual, `${label} exact CSP directive ${name} lacks a required baseline.`);
    invariant(expected.length === actual.length && expected.every((source) => actual.includes(source)), `${label} CSP ${name} gained or lost a source.`);
  }

  const forbiddenCsp = policy.forbiddenCspSources ?? {};
  invariant(forbiddenCsp && typeof forbiddenCsp === "object" && !Array.isArray(forbiddenCsp), `${label} forbidden CSP baseline is invalid.`);
  for (const [rawName, forbiddenSources] of Object.entries(forbiddenCsp)) {
    const name = rawName.toLowerCase();
    invariant(Array.isArray(forbiddenSources), `${label} ${name} forbidden CSP baseline is invalid.`);
    const actual = csp.get(name) ?? [];
    for (const source of forbiddenSources) {
      invariant(!actual.includes(source), `${label} CSP ${name} gained forbidden source ${source}.`);
    }
  }

  let cacheControl = null;
  if (policy.requireNoStore === true) {
    const directives = parseCacheControl(requiredHeader(headers, "cache-control", label), label);
    invariant(directives.has("no-store") && directives.get("no-store") === null, `${label} Cache-Control must include no-store.`);
    invariant(!directives.has("public"), `${label} Cache-Control must not be public.`);
    for (const name of ["max-age", "s-maxage"]) {
      if (!directives.has(name)) continue;
      const raw = directives.get(name);
      invariant(typeof raw === "string" && /^\d+$/u.test(raw), `${label} Cache-Control ${name} is invalid.`);
      invariant(Number(raw) === 0, `${label} Cache-Control ${name} permits storage.`);
    }
    cacheControl = "no-store";
  }

  return {
    hstsMaxAgeSeconds: maxAge,
    hstsIncludeSubDomains: true,
    xContentTypeOptions: "nosniff",
    xFrameOptions: "DENY",
    requiredCspDirectives: Object.keys(requiredCsp).sort(),
    cacheControl,
  };
}

export function validateDeniedResponse(response, descriptor) {
  invariant(response && typeof response === "object", "Denied response is missing.");
  invariant(descriptor && typeof descriptor === "object", "Denied-response baseline is missing.");
  invariant(Number.isInteger(response.status) && response.status >= 100 && response.status <= 599, "Denied response status is invalid.");
  invariant(response.status < 200 || response.status >= 300, `${descriptor.id ?? "Denied path"} unexpectedly returned success status ${response.status}.`);
  invariant(Array.isArray(descriptor.allowedStatuses) && descriptor.allowedStatuses.length > 0, `${descriptor.id ?? "Denied path"} allowed-status baseline is invalid.`);
  invariant(descriptor.allowedStatuses.every((status) => Number.isInteger(status) && status >= 300 && status <= 599), `${descriptor.id ?? "Denied path"} allowed-status baseline contains a success or invalid status.`);
  invariant(descriptor.allowedStatuses.includes(response.status), `${descriptor.id ?? "Denied path"} returned unexpected status ${response.status}.`);
  invariant(typeof response.body === "string", `${descriptor.id ?? "Denied path"} response body is invalid.`);
  invariant(Number.isSafeInteger(descriptor.maximumBodyBytes) && descriptor.maximumBodyBytes > 0, `${descriptor.id ?? "Denied path"} body bound is invalid.`);
  const bodyBytes = Buffer.byteLength(response.body, "utf8");
  invariant(bodyBytes <= descriptor.maximumBodyBytes, `${descriptor.id ?? "Denied path"} response exceeded the ${descriptor.maximumBodyBytes}-byte bound.`);
  invariant(Array.isArray(descriptor.forbiddenBodyMarkers) && descriptor.forbiddenBodyMarkers.length > 0, `${descriptor.id ?? "Denied path"} raw-marker baseline is missing.`);
  const normalizedBody = response.body.toLowerCase();
  for (const marker of descriptor.forbiddenBodyMarkers) {
    invariant(typeof marker === "string" && marker.trim() !== "", `${descriptor.id ?? "Denied path"} raw-marker baseline is invalid.`);
    invariant(!normalizedBody.includes(marker.toLowerCase()), `${descriptor.id ?? "Denied path"} denial body exposed raw marker ${marker}.`);
  }
  return {
    status: response.status,
    bodyBytes,
    rawMarkersAbsent: descriptor.forbiddenBodyMarkers.length,
  };
}

export function validateServedHttpSecurityConfiguration(config) {
  invariant(config && typeof config === "object" && !Array.isArray(config), "Served HTTP security baseline is missing.");
  invariant(Number.isSafeInteger(config.maximumDeniedBodyBytes)
    && config.maximumDeniedBodyBytes > 0
    && config.maximumDeniedBodyBytes <= 131_072, "Denied-response byte bound is invalid.");
  const expectedPaths = {
    site: ["/api/data/social-portraits-v1.json"],
    mcp: [
      "/lib.php",
      "/manifest.json",
      "/corpus/faq.json",
      "/release-attestation.json",
      "/agent-entry.php.pre-status-hotfix-20260823",
    ],
  };
  const expectedOrigins = {
    site: "https://notforhumans.fun",
    mcp: "https://mcp.notforhumans.fun",
  };
  for (const surfaceName of ["site", "mcp"]) {
    const surface = config[surfaceName];
    invariant(surface && typeof surface === "object" && !Array.isArray(surface), `${surfaceName} HTTP security baseline is missing.`);
    const headerProbe = new URL(surface.headerProbeUrl);
    invariant(headerProbe.protocol === "https:" && headerProbe.origin === expectedOrigins[surfaceName], `${surfaceName} header probe URL is invalid.`);
    invariant(surface.headers && typeof surface.headers === "object" && !Array.isArray(surface.headers), `${surfaceName} security-header baseline is missing.`);
    invariant(Array.isArray(surface.deniedPaths), `${surfaceName} denied-path baseline is missing.`);
    const paths = [];
    const ids = new Set();
    for (const descriptor of surface.deniedPaths) {
      invariant(typeof descriptor?.id === "string" && /^[a-z0-9-]+$/u.test(descriptor.id) && !ids.has(descriptor.id), `${surfaceName} denied-path ID is invalid or duplicated.`);
      ids.add(descriptor.id);
      const url = new URL(descriptor.url);
      invariant(url.protocol === "https:" && url.origin === expectedOrigins[surfaceName] && url.search === "" && url.hash === "", `${descriptor.id} denied-path URL is invalid.`);
      paths.push(url.pathname);
      const statuses = [...(descriptor.allowedStatuses ?? [])].sort((left, right) => left - right);
      const expectedStatuses = surfaceName === "site" ? [403] : [403, 404];
      invariant(JSON.stringify(statuses) === JSON.stringify(expectedStatuses), `${descriptor.id} allowed statuses drifted.`);
      invariant(Array.isArray(descriptor.forbiddenBodyMarkers) && descriptor.forbiddenBodyMarkers.length >= 2, `${descriptor.id} raw-marker baseline is incomplete.`);
    }
    invariant(JSON.stringify([...paths].sort()) === JSON.stringify([...expectedPaths[surfaceName]].sort()), `${surfaceName} denied-path coverage drifted.`);
  }
  return {
    maximumDeniedBodyBytes: config.maximumDeniedBodyBytes,
    siteDeniedPaths: expectedPaths.site.length,
    mcpDeniedPaths: expectedPaths.mcp.length,
  };
}

function hexBlock(number) {
  return `0x${number.toString(16)}`;
}

async function fetchResponse(url, options = {}) {
  let lastError;
  const attempts = options.attempts ?? 2;
  for (let attempt = 1; attempt <= attempts; attempt += 1) {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), options.timeoutMs ?? 12_000);
    try {
      const response = await fetch(url, {
        method: options.method ?? "GET",
        headers: {
          Accept: options.accept ?? "application/json",
          "User-Agent": "NOT-FOR-HUMANS-Founder-Away-Monitor/1.0",
          ...(options.headers ?? {}),
        },
        body: options.body,
        redirect: "error",
        signal: controller.signal,
      });
      if (options.requireOk !== false) invariant(response.ok, `HTTP ${response.status} from ${new URL(url).origin}.`);
      return response;
    } catch (error) {
      lastError = error;
      if (attempt === attempts) throw error;
    } finally {
      clearTimeout(timer);
    }
  }
  throw lastError;
}

async function fetchJson(url) {
  const response = await fetchResponse(url);
  const body = await response.text();
  try {
    return JSON.parse(body);
  } catch {
    throw new Error(`Invalid JSON from ${new URL(url).origin}.`);
  }
}

async function fetchText(url) {
  const response = await fetchResponse(url, { accept: "text/html, text/plain;q=0.9" });
  return response.text();
}

async function responseBytes(response, maximumBytes) {
  invariant(Number.isSafeInteger(maximumBytes) && maximumBytes >= 0, "HTTP response byte bound is invalid.");
  const declaredLength = response.headers.get("content-length");
  if (declaredLength !== null && /^\d+$/.test(declaredLength)) {
    invariant(Number(declaredLength) <= maximumBytes, `HTTP response exceeded the ${maximumBytes}-byte bound.`);
  }
  if (response.body === null) return Buffer.alloc(0);
  const chunks = [];
  let bytes = 0;
  for await (const chunk of response.body) {
    const buffer = Buffer.from(chunk);
    bytes += buffer.byteLength;
    invariant(bytes <= maximumBytes, `HTTP response exceeded the ${maximumBytes}-byte bound.`);
    chunks.push(buffer);
  }
  return Buffer.concat(chunks, bytes);
}

async function discardResponseBody(response) {
  if (response.body === null) return;
  try {
    await response.body.cancel();
  } catch {
    // Best-effort cleanup only; the security validation error remains authoritative.
  }
}

async function verifySecurityHeaderProbe(surfaceName, surface) {
  const response = await fetchResponse(surface.headerProbeUrl, {
    accept: surfaceName === "site" ? "text/html, */*;q=0.1" : "application/json, */*;q=0.1",
  });
  try {
    return validateSecurityHeaders(response.headers, surface.headers, `${surfaceName} success response`);
  } finally {
    await discardResponseBody(response);
  }
}

async function verifyDeniedPathProbe(surfaceName, surface, descriptor, maximumBodyBytes) {
  const response = await fetchResponse(descriptor.url, {
    accept: "text/plain, application/json;q=0.9, */*;q=0.1",
    requireOk: false,
  });
  try {
    const securityHeaders = validateSecurityHeaders(response.headers, surface.headers, `${descriptor.id} response`);
    const body = await responseBytes(response, maximumBodyBytes);
    const denial = validateDeniedResponse({ status: response.status, body: body.toString("utf8") }, {
      ...descriptor,
      maximumBodyBytes,
    });
    return { ...denial, securityHeaders };
  } catch (error) {
    await discardResponseBody(response);
    throw error;
  }
}

async function fetchBytes(url, maximumBytes, accept = "application/octet-stream, */*;q=0.1") {
  const response = await fetchResponse(url, { accept });
  return responseBytes(response, maximumBytes);
}

function parseAttestationBytes(bytes, origin) {
  try {
    const parsed = JSON.parse(bytes.toString("utf8"));
    invariant(parsed && typeof parsed === "object" && !Array.isArray(parsed), `Release attestation from ${origin} is not an object.`);
    return parsed;
  } catch (error) {
    if (error instanceof Error && /Release attestation/u.test(error.message)) throw error;
    throw new Error(`Invalid release attestation JSON from ${origin}.`);
  }
}

function servedStaticUrl(baseUrl, relativePath) {
  assertReleasePath(relativePath);
  const base = new URL(baseUrl.endsWith("/") ? baseUrl : `${baseUrl}/`);
  invariant(base.protocol === "https:", "Static release base URL must use HTTPS.");
  const encodedPath = relativePath.split("/").map((segment) => encodeURIComponent(segment)).join("/");
  return new URL(encodedPath, base).href;
}

async function mapConcurrent(items, concurrency, task) {
  invariant(Number.isSafeInteger(concurrency) && concurrency >= 1 && concurrency <= 16, "Release verification concurrency is invalid.");
  const results = new Array(items.length);
  let cursor = 0;
  const workers = Array.from({ length: Math.min(concurrency, items.length) }, async () => {
    while (cursor < items.length) {
      const index = cursor;
      cursor += 1;
      results[index] = await task(items[index], index);
    }
  });
  const settled = await Promise.allSettled(workers);
  const failure = settled.find((result) => result.status === "rejected");
  if (failure) throw failure.reason;
  return results;
}

export async function verifyStaticReleaseAttestation(siteConfig, releaseConfig) {
  const expected = releaseConfig?.staticSite;
  const limits = releaseConfig?.recurringLiveReadLimits;
  invariant(expected?.scope === "static-site" && typeof expected.url === "string", "Static release-attestation baseline is missing.");
  invariant(typeof expected.documentSha256 === "string" && /^[0-9a-f]{64}$/.test(expected.documentSha256), "Static attestation document pin is invalid.");
  const raw = await fetchBytes(expected.url, 262_144, "application/json");
  const documentSha256 = sha256Hex(raw);
  invariant(documentSha256 === expected.documentSha256, "Live static release-attestation document drifted from the founder-away baseline.");
  const attestation = parseAttestationBytes(raw, new URL(expected.url).origin);
  const { requiredEntries } = validateReleaseAttestation(attestation, expected);
  invariant(Number.isSafeInteger(limits?.maximumFiles) && limits.maximumFiles > 0 && requiredEntries.length <= limits.maximumFiles, "Static live-verification file count exceeds the fail-closed bound.");
  const requiredBytes = requiredEntries.reduce((sum, entry) => sum + entry.bytes, 0);
  invariant(Number.isSafeInteger(limits?.maximumBytes) && limits.maximumBytes > 0 && requiredBytes <= limits.maximumBytes, "Static live-verification bytes exceed the fail-closed bound.");
  const verified = await mapConcurrent(requiredEntries, limits.concurrency, async (entry) => {
    const fileUrl = servedStaticUrl(siteConfig.baseUrl, entry.path);
    const bytes = await fetchBytes(fileUrl, entry.bytes);
    invariant(bytes.byteLength === entry.bytes, `Served static byte count drifted for ${entry.path}.`);
    invariant(sha256Hex(bytes) === entry.sha256, `Served static SHA-256 drifted for ${entry.path}.`);
    return entry.path;
  });
  return {
    sourceRelease: attestation.sourceRelease,
    documentSha256,
    treeSha256: attestation.tree.sha256,
    treeFiles: attestation.tree.files,
    treeBytes: attestation.tree.bytes,
    liveFilesVerified: verified.length,
    liveBytesVerified: requiredBytes,
    recurringExclusions: attestation.liveVerification.exclusions,
    proofBoundary: "public-static-bytes-live-hashed; server-executed-and-explicit-bandwidth-exclusions-tree-bound",
  };
}

export async function verifyMcpReleaseAttestation(releaseConfig) {
  const expected = releaseConfig?.mcpServer;
  invariant(expected?.scope === "mcp-server" && typeof expected.url === "string", "MCP release-attestation baseline is missing.");
  invariant(typeof expected.documentSha256 === "string" && /^[0-9a-f]{64}$/.test(expected.documentSha256), "MCP attestation document pin is invalid.");
  const raw = await fetchBytes(expected.url, 131_072, "application/json");
  const documentSha256 = sha256Hex(raw);
  invariant(documentSha256 === expected.documentSha256, "Live MCP release-attestation document drifted from the founder-away baseline.");
  const attestation = parseAttestationBytes(raw, new URL(expected.url).origin);
  validateReleaseAttestation(attestation, expected);
  return {
    sourceRelease: attestation.sourceRelease,
    documentSha256,
    treeSha256: attestation.tree.sha256,
    treeFiles: attestation.tree.files,
    treeBytes: attestation.tree.bytes,
    proofBoundary: "server-reported-tree-digest-not-independent-proof-of-hidden-php-bytes",
  };
}

async function rpcCall(url, method, params) {
  const payload = JSON.stringify({ jsonrpc: "2.0", id: 1, method, params });
  const response = await fetchResponse(url, {
    method: "POST",
    accept: "application/json",
    headers: { "Content-Type": "application/json" },
    body: payload,
    timeoutMs: 7_000,
    attempts: 1,
  });
  const body = await response.json();
  invariant(body && body.error === undefined && body.result !== undefined, `RPC ${method} returned an error.`);
  return body.result;
}

async function rpcResults(urls, method, params) {
  const settled = await Promise.allSettled(urls.map((url) => rpcCall(url, method, params)));
  return settled.filter((result) => result.status === "fulfilled").map((result) => result.value);
}

async function quorumRpc(urls, method, params) {
  return requireQuorum(await rpcResults(urls, method, params));
}

function addCheck(report, name, ok, detail, severity = "critical") {
  report.checks.push({ name, ok, severity, detail });
}

async function capture(report, name, task, severity = "critical") {
  try {
    const detail = await task();
    addCheck(report, name, true, detail, severity);
    return detail;
  } catch (error) {
    addCheck(report, name, false, error instanceof Error ? error.message : String(error), severity);
    return null;
  }
}

function validateFeed(payload, field) {
  invariant(payload?.status === "active", `${field} feed is not active.`);
  invariant(Array.isArray(payload[field]), `${field} feed payload is invalid.`);
  return payload[field].length;
}

async function verifySafe(config, urls, anchorTag) {
  invariant(config && typeof config === "object", "Foundation Safe baseline is missing.");
  const sentinel = "0x0000000000000000000000000000000000000001";
  const modulesCalldata = `0xcc2f8452${sentinel.slice(2).padStart(64, "0")}${uintWord(10, "Safe module page size")}`;
  const [ownersRaw, thresholdRaw, nonceRaw, modulesRaw, versionRaw, singletonRaw, storagePairs] = await Promise.all([
    quorumRpc(urls, "eth_call", [{ to: config.address, data: "0xa0e67e2b" }, anchorTag]),
    quorumRpc(urls, "eth_call", [{ to: config.address, data: "0xe75235b8" }, anchorTag]),
    quorumRpc(urls, "eth_call", [{ to: config.address, data: "0xaffed0e0" }, anchorTag]),
    quorumRpc(urls, "eth_call", [{ to: config.address, data: modulesCalldata }, anchorTag]),
    quorumRpc(urls, "eth_call", [{ to: config.address, data: "0xffa1ad74" }, anchorTag]),
    quorumRpc(urls, "eth_call", [{ to: config.address, data: "0xa619486e" }, anchorTag]),
    Promise.all(Object.entries(config.storageSlots ?? {}).map(async ([name, descriptor]) => {
      invariant(typeof descriptor?.slot === "string" && /^0x[0-9a-fA-F]{1,64}$/.test(descriptor.slot), `Safe ${name} storage slot is invalid.`);
      const value = decodeNullableAddress(await quorumRpc(urls, "eth_getStorageAt", [config.address, descriptor.slot, anchorTag]));
      invariant(value === descriptor.expected.toLowerCase(), `Safe ${name} storage drifted to ${value}.`);
      return [name, value];
    })),
  ]);
  const owners = decodeAddressArray(ownersRaw);
  const expectedOwners = config.owners.map((owner) => owner.toLowerCase());
  invariant(owners.length === expectedOwners.length, `Safe owner count drifted to ${owners.length}.`);
  invariant([...owners].sort().join(",") === [...expectedOwners].sort().join(","), "Safe owners drifted.");
  const threshold = Number(decodeUint(thresholdRaw));
  invariant(threshold === config.threshold, `Safe threshold drifted to ${threshold}.`);
  const nonce = Number(decodeUint(nonceRaw));
  invariant(nonce === config.nonce, `Safe nonce drifted to ${nonce}; an unreviewed Safe transaction may have executed.`);
  const modules = decodeAddressArrayAndAddress(modulesRaw);
  const expectedModules = (config.modules ?? []).map((module) => module.toLowerCase());
  invariant([...modules.addresses].sort().join(",") === [...expectedModules].sort().join(","), "Safe modules drifted.");
  invariant(modules.next === sentinel, `Safe module pagination pointer drifted to ${modules.next}.`);
  const version = decodeString(versionRaw);
  invariant(version === config.version, `Safe version drifted to ${version}.`);
  const singleton = decodeAddress(singletonRaw);
  invariant(singleton === config.singleton.toLowerCase(), `Safe singleton drifted to ${singleton}.`);
  return {
    address: config.address.toLowerCase(),
    version,
    singleton,
    owners,
    threshold,
    nonce,
    modules: modules.addresses,
    storage: Object.fromEntries(storagePairs),
  };
}

async function verifyExpectedConfiguration(config, urls, anchorTag) {
  const descriptors = config.expectedConfigurationCalls ?? [];
  invariant(Array.isArray(descriptors) && descriptors.length > 0, "Protected contract-configuration calls are missing.");
  const ids = new Set();
  const pairs = await Promise.all(descriptors.map(async (descriptor) => {
    invariant(typeof descriptor.id === "string" && descriptor.id.length > 0 && !ids.has(descriptor.id), "Protected configuration call IDs must be unique.");
    ids.add(descriptor.id);
    const target = config[descriptor.target];
    invariant(typeof target === "string" && /^0x[0-9a-fA-F]{40}$/.test(target), `${descriptor.id} target is invalid.`);
    const raw = await quorumRpc(urls, "eth_call", [{ to: target, data: exactCallData(descriptor) }, anchorTag]);
    const rawHash = descriptor.kind === "rawHash" ? await quorumRpc(urls, "web3_sha3", [raw]) : null;
    return [descriptor.id, validateExpectedCallResult(descriptor, raw, rawHash)];
  }));
  return Object.fromEntries(pairs);
}

async function verifyChain(config) {
  const urls = config.rpcUrls;
  invariant(Array.isArray(urls) && urls.length >= 2, "At least two public RPC URLs are required.");
  const chainIds = await rpcResults(urls, "eth_chainId", []);
  invariant(requireQuorum(chainIds) === "0x1", "RPC quorum is not on Ethereum mainnet.");
  const blockHexes = await rpcResults(urls, "eth_blockNumber", []);
  const blocks = blockHexes.map((value) => Number.parseInt(value, 16)).filter(Number.isSafeInteger);
  const anchorNumber = chooseAnchorBlock(blocks, config.maxTipSkewBlocks);
  const anchorTag = hexBlock(anchorNumber);
  const blockResults = await rpcResults(urls, "eth_getBlockByNumber", [anchorTag, false]);
  const anchorHash = requireQuorum(blockResults.map((block) => block?.hash ?? null));
  const anchorTimestamp = quorumAnchorTimestamp(blockResults, anchorHash);
  assertFreshAnchorBlock({ timestamp: `0x${anchorTimestamp.toString(16)}` }, Math.floor(Date.now() / 1000), config.maxAnchorAgeSeconds);

  const ownerEntries = Object.entries(config.expectedOwners);
  const codeEntries = Object.entries(config.runtimeCodeHashes);
  const roleEntries = Object.entries(config.expectedRoles ?? {});
  const [totalSupplyRaw, minterPausedRaw, marketplacePausedRaw, protocolRolesFrozenRaw, ownerPairs, codePairs, rolePairs, safe, configuration] = await Promise.all([
    quorumRpc(urls, "eth_call", [{ to: config.token, data: "0x18160ddd" }, anchorTag]),
    quorumRpc(urls, "eth_call", [{ to: config.minter, data: "0x5c975abb" }, anchorTag]),
    quorumRpc(urls, "eth_call", [{ to: config.marketplace, data: "0x5c975abb" }, anchorTag]),
    quorumRpc(urls, "eth_call", [{ to: config.token, data: "0xdc53bba7" }, anchorTag]),
    Promise.all(ownerEntries.map(async ([name, target]) => {
      const owner = decodeAddress(await quorumRpc(urls, "eth_call", [{ to: config[name], data: "0x8da5cb5b" }, anchorTag]));
      invariant(owner === target.toLowerCase(), `${name} owner drifted to ${owner}.`);
      return [name, owner];
    })),
    Promise.all(codeEntries.map(async ([name, descriptor]) => {
      invariant(typeof descriptor?.address === "string" && typeof descriptor?.hash === "string", `${name} runtime code descriptor is invalid.`);
      const code = await quorumRpc(urls, "eth_getCode", [descriptor.address, anchorTag]);
      invariant(/^0x[0-9a-f]+$/.test(code) && code !== "0x", `${name} runtime code is missing.`);
      return [name, code];
    })),
    Promise.all(roleEntries.map(async ([name, descriptor]) => {
      const expectedMembers = descriptor.members.map((member) => member.toLowerCase());
      const count = Number(decodeUint(await quorumRpc(urls, "eth_call", [{
        to: config.token,
        data: roleCountCalldata(descriptor.role),
      }, anchorTag])));
      invariant(count === expectedMembers.length, `${name} member count drifted to ${count}.`);
      const members = await Promise.all(expectedMembers.map(async (_member, index) => decodeAddress(await quorumRpc(urls, "eth_call", [{
        to: config.token,
        data: roleMemberCalldata(descriptor.role, index),
      }, anchorTag]))));
      invariant([...members].sort().join(",") === [...expectedMembers].sort().join(","), `${name} enumerable members drifted.`);
      return [name, { role: descriptor.role.toLowerCase(), members }];
    })),
    verifySafe(config.safe, urls, anchorTag),
    verifyExpectedConfiguration(config, urls, anchorTag),
  ]);

  const totalSupply = decodeUint(totalSupplyRaw);
  const agentEntrySuccessfulMints = BigInt(configuration["agentEntryMinter.successfulMints"]);
  invariant(agentEntrySuccessfulMints >= 0n && agentEntrySuccessfulMints <= BigInt(config.agentEntryMaximumMints),
    `Agent Entry successful mints drifted to ${agentEntrySuccessfulMints}.`);
  const expectedSupply = BigInt(config.agentEntryBaseSupply) + agentEntrySuccessfulMints;
  invariant(totalSupply === expectedSupply,
    `totalSupply ${totalSupply} does not equal base supply ${config.agentEntryBaseSupply} plus Agent Entry mints ${agentEntrySuccessfulMints}.`);
  const minterPaused = decodeUint(minterPausedRaw) !== 0n;
  invariant(minterPaused === config.expectedMinterPaused, `Claim minter paused state drifted to ${minterPaused}.`);
  const marketplacePaused = decodeUint(marketplacePausedRaw) !== 0n;
  invariant(marketplacePaused === config.expectedMarketplacePaused, `Marketplace paused state drifted to ${marketplacePaused}.`);
  const protocolRolesFrozen = decodeUint(protocolRolesFrozenRaw) !== 0n;
  invariant(protocolRolesFrozen === config.expectedProtocolRolesFrozen, `Collection protocolRolesFrozen drifted to ${protocolRolesFrozen}.`);

  const governance = Object.fromEntries(ownerPairs);
  const roles = Object.fromEntries(rolePairs);

  const codeHashes = Object.fromEntries(await Promise.all(codePairs.map(async ([name, code]) => {
    const codeHash = await quorumRpc(urls, "web3_sha3", [code]);
    const expectedHash = config.runtimeCodeHashes[name].hash;
    invariant(codeHash === expectedHash.toLowerCase(), `${name} runtime code hash drifted.`);
    return [name, codeHash];
  })));

  return {
    chainId: 1,
    anchor: { blockNumber: anchorNumber, blockHash: anchorHash, timestamp: anchorTimestamp },
    totalSupply: totalSupply.toString(),
    minterPaused,
    marketplacePaused,
    protocolRolesFrozen,
    governance,
    roles,
    safe,
    configuration,
    runtimeCodeHashes: codeHashes,
  };
}

export async function runMonitor(config) {
  const report = {
    schema: "nfh.founder-away-monitor-report.v1",
    generatedAt: new Date().toISOString(),
    baseline: config.baseline,
    checks: [],
    snapshots: {},
  };

  const staticRelease = await capture(report, "static-release-bytes", () => (
    verifyStaticReleaseAttestation(config.site, config.releaseAttestations)
  ));
  if (staticRelease) report.snapshots.staticRelease = staticRelease;

  const mcpRelease = await capture(report, "mcp-release-source-report", () => (
    verifyMcpReleaseAttestation(config.releaseAttestations)
  ));
  if (mcpRelease) report.snapshots.mcpRelease = mcpRelease;

  const securityPolicy = await capture(report, "served-http-security-policy", () => (
    validateServedHttpSecurityConfiguration(config.servedHttpSecurity)
  ));
  if (securityPolicy) {
    report.snapshots.servedHttpSecurity = { policy: securityPolicy, site: {}, mcp: {} };
    for (const surfaceName of ["site", "mcp"]) {
      const surface = config.servedHttpSecurity[surfaceName];
      const headerResult = await capture(report, `${surfaceName}-security-headers`, () => (
        verifySecurityHeaderProbe(surfaceName, surface)
      ));
      if (headerResult) report.snapshots.servedHttpSecurity[surfaceName].headers = headerResult;
      const deniedResults = [];
      for (const descriptor of surface.deniedPaths) {
        const result = await capture(report, `${surfaceName}-access-denied:${descriptor.id}`, () => (
          verifyDeniedPathProbe(surfaceName, surface, descriptor, config.servedHttpSecurity.maximumDeniedBodyBytes)
        ));
        if (result) deniedResults.push({ id: descriptor.id, ...result });
      }
      report.snapshots.servedHttpSecurity[surfaceName].deniedPaths = deniedResults;
    }
  }

  for (const page of config.site.pages) {
    await capture(report, `site:${page.path}`, async () => {
      const text = await fetchText(`${config.site.baseUrl}${page.path}`);
      for (const required of page.requiredText ?? []) invariant(text.includes(required), `${page.path} is missing frozen release text: ${required}`);
      for (const forbidden of page.forbiddenText ?? []) invariant(!text.includes(forbidden), `${page.path} contains retired text: ${forbidden}`);
      return { bytes: Buffer.byteLength(text), requiredText: page.requiredText ?? [] };
    });
  }

  const release = await capture(report, "release-policy", async () => {
    const payload = await fetchJson(config.endpoints.releasePolicy);
    invariant(payload.primary_success_metric === "independent, attributable agent decisions and integrations rather than mint speed, floor price, TVL, or trading volume", "Primary success metric drifted.");
    invariant(payload.public_claims?.claim_capacity_at_launch === 8_488, "Public launch capacity drifted.");
    return { status: payload.status, primaryMetric: payload.primary_success_metric };
  });
  if (release) report.snapshots.releasePolicy = release;

  const agentCard = await capture(report, "agent-card", async () => {
    const payload = await fetchJson(config.endpoints.agentCard);
    invariant(payload.version === config.mcpVersion, `Agent card version drifted to ${payload.version}.`);
    invariant(payload.mcpUrl === config.endpoints.mcp, "Agent card MCP URL drifted.");
    invariant(payload["x-release-policy"]?.claimStatus === "closed_capacity_filled", "Agent card claim status is not closed.");
    invariant(payload["x-release-policy"]?.totalSupply === 8_488, "Agent card supply drifted.");
    invariant(payload.capabilities?.agentEntryReservationPreparation === true, "Agent card lost live Agent Entry reservation preparation.");
    invariant(payload.capabilities?.agentEntryClaimPreparation === true, "Agent card lost live Agent Entry claim preparation.");
    assertFounderAwayAuthority(payload["x-safety"]);
    return { version: payload.version, claimStatus: payload["x-release-policy"].claimStatus };
  });
  if (agentCard) report.snapshots.agentCard = agentCard;

  const health = await capture(report, "mcp-health", async () => {
    const payload = await fetchJson(config.endpoints.health);
    invariant(payload.ok === true, "MCP health is not okay.");
    invariant(payload.version === config.mcpVersion, `Live MCP version drifted to ${payload.version}.`);
    invariant(Number.isInteger(payload.tools) && payload.tools >= config.minimumTools, "Live MCP tool count fell below the baseline.");
    invariant(payload.releaseAttestation === config.releaseAttestations.mcpServer.url, "MCP health lost the pinned release-attestation endpoint.");
    invariant(payload.tradingPreparationEnabled === false, "MCP trading preparation unexpectedly enabled.");
    invariant(payload.censusSigningPreparationEnabled === false, "MCP Census signing preparation unexpectedly enabled.");
    return payload;
  });
  if (health) report.snapshots.health = health;

  const mcpDiscovery = await capture(report, "mcp-discovery-boundary", async () => {
    const payload = await fetchJson(config.endpoints.mcpDiscovery);
    invariant(payload.releaseAttestation === config.releaseAttestations.mcpServer.url, "MCP discovery lost the pinned release-attestation endpoint.");
    invariant(payload.supportsAgentEntryReservationCode === true, "MCP discovery lost the staged Agent Entry code boundary.");
    invariant(payload.supportsAgentEntryReservations === true, "MCP discovery lost live Agent Entry reservations.");
    invariant(payload.agentEntryReservationPreparationEnabled === true, "MCP discovery lost live Agent Entry preparation.");
    invariant(payload.agentEntryClaimPreparationEnabled === true, "MCP discovery lost live Agent Entry claim preparation.");
    return {
      supportsAgentEntryReservationCode: true,
      supportsAgentEntryReservations: true,
      agentEntryReservationPreparationEnabled: true,
      agentEntryClaimPreparationEnabled: true,
    };
  });
  if (mcpDiscovery) report.snapshots.mcpDiscovery = mcpDiscovery;

  const agentEntry = await capture(report, "agent-entry-live-gate", async () => {
    const payload = await fetchJson(config.endpoints.agentEntry);
    validateAgentEntryStatus(payload);
    return payload;
  });
  if (agentEntry) report.snapshots.agentEntry = agentEntry;

  const integrations = await capture(report, "virtuals-offline-boundary", async () => {
    const payload = await fetchJson(config.endpoints.agentIntegrations);
    const adapter = validateVirtualsBoundary(payload);
    return {
      state: adapter.state,
      bindingValidUntil: adapter.historicalEvidence.validUntil,
      bindingExpired: Date.now() >= Date.parse(adapter.historicalEvidence.validUntil),
      runtimeReliance: false,
    };
  });
  if (integrations) report.snapshots.virtuals = integrations;

  const pulse = await capture(report, "network-pulse", async () => {
    const payload = await fetchJson(config.endpoints.networkPulse);
    validateNetworkPulse(payload);
    return payload;
  });
  if (pulse) report.snapshots.networkPulse = pulse;

  for (const [name, descriptor] of Object.entries(config.feeds)) {
    const payload = await capture(report, `feed:${name}`, async () => {
      const value = await fetchJson(descriptor.url);
      const snapshot = { count: validateFeed(value, descriptor.field), updatedAt: value.updatedAt ?? null };
      if (name === "presence") snapshot.storage = validatePresenceStorage(value.storage, descriptor.storage);
      return snapshot;
    });
    if (payload) report.snapshots[name] = payload;
  }

  const discord = await capture(report, "discord-fail-closed", async () => {
    const payload = await fetchJson(config.endpoints.discordHealth);
    invariant(payload.ok === config.discord.expectedConfigured, `Discord configured state drifted to ${payload.ok}.`);
    invariant(payload.authority?.wallet === false && payload.authority?.transactions === false, "Discord field agent gained wallet or transaction authority.");
    return payload;
  }, config.discord.required ? "critical" : "warning");
  if (discord) report.snapshots.discord = discord;

  if (config.registry?.recordUrl) {
    const registry = await capture(report, "mcp-registry", async () => {
      const payload = await fetchJson(config.registry.recordUrl);
      const server = payload.server ?? payload;
      invariant(server.name === config.registry.name, `Registry name drifted to ${server.name}.`);
      invariant(server.version === config.mcpVersion, `Registry version drifted to ${server.version}.`);
      invariant(server.remotes?.some((remote) => remote.url === config.endpoints.mcp), "Registry record lost the canonical MCP remote.");
      return { name: server.name, version: server.version };
    }, config.registry.required ? "critical" : "warning");
    if (registry) report.snapshots.registry = registry;
  }

  const chain = await capture(report, "ethereum-quorum", () => verifyChain(config.chain));
  if (chain) report.snapshots.chain = chain;

  const criticalFailures = report.checks.filter((check) => !check.ok && check.severity === "critical");
  const warnings = report.checks.filter((check) => !check.ok && check.severity === "warning");
  report.summary = {
    status: criticalFailures.length > 0 ? "failed" : warnings.length > 0 ? "degraded" : "passed",
    passed: report.checks.filter((check) => check.ok).length,
    criticalFailures: criticalFailures.length,
    warnings: warnings.length,
  };
  return report;
}

async function main() {
  const configPath = path.resolve(process.env.NFH_FOUNDER_AWAY_BASELINE ?? path.join(here, "baseline.json"));
  const outputPath = path.resolve(process.env.NFH_MONITOR_OUTPUT ?? path.join(here, "founder-away-monitor-report.json"));
  const pulsePath = path.resolve(process.env.NFH_PULSE_OUTPUT ?? path.join(here, "network-pulse.json"));
  const config = JSON.parse(fs.readFileSync(configPath, "utf8"));
  const report = await runMonitor(config);
  fs.writeFileSync(outputPath, `${JSON.stringify(report, null, 2)}\n`, { mode: 0o600 });
  if (report.snapshots.networkPulse) fs.writeFileSync(pulsePath, `${JSON.stringify(report.snapshots.networkPulse, null, 2)}\n`, { mode: 0o600 });
  for (const check of report.checks) console.log(`${check.ok ? "PASS" : check.severity === "warning" ? "WARN" : "FAIL"} ${check.name}`);
  console.log(JSON.stringify(report.summary));
  if (report.summary.criticalFailures > 0) process.exitCode = 1;
}

const invoked = process.argv[1] && import.meta.url === pathToFileURL(path.resolve(process.argv[1])).href;
if (invoked) main().catch((error) => {
  console.error(error instanceof Error ? error.message : String(error));
  process.exitCode = 1;
});

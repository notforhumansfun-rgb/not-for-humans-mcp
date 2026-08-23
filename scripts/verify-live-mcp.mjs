#!/usr/bin/env node

import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import { spawnSync } from "node:child_process";
import { fileURLToPath } from "node:url";

const repositoryRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const baseUrl = "https://mcp.notforhumans.fun";
const mcpUrl = `${baseUrl}/mcp`;
const attestationUrl = `${baseUrl}/release-attestation`;
const initializeRequest = {
  jsonrpc: "2.0",
  id: 1,
  method: "initialize",
  params: {
    protocolVersion: "2025-11-25",
    capabilities: {},
    clientInfo: { name: "nfh-release-verifier", version: "1" },
  },
};

async function checkedFetch(url, init = undefined) {
  const response = await fetch(url, { ...init, signal: AbortSignal.timeout(20_000) });
  assert.equal(response.ok, true, `${url} returned HTTP ${response.status}`);
  return response;
}

function localInitialize() {
  const php = [
    "require 'server/lib.php';",
    "$request = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);",
    "$response = nfh_dispatch($request);",
    "echo json_encode($response['body'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);",
  ].join(" ");
  const result = spawnSync("php", ["-r", php], {
    cwd: repositoryRoot,
    input: JSON.stringify(initializeRequest),
    encoding: "utf8",
    maxBuffer: 262_144,
  });
  assert.equal(result.status, 0, result.stderr);
  return JSON.parse(result.stdout);
}

async function main() {
  const registry = JSON.parse(fs.readFileSync(path.join(repositoryRoot, "server.json"), "utf8"));
  assert.deepEqual(registry.remotes, [{ type: "streamable-http", url: mcpUrl }]);

  const liveAttestation = Buffer.from(await (await checkedFetch(attestationUrl)).arrayBuffer());
  const localAttestation = fs.readFileSync(path.join(repositoryRoot, "server/release-attestation.json"));
  assert.deepEqual(liveAttestation, localAttestation, "served MCP attestation bytes differ from the copied canonical artifact");

  const health = await (await checkedFetch(`${baseUrl}/health`)).json();
  assert.equal(health.ok, true);
  assert.equal(health.service, "not-for-humans-mcp");
  assert.equal(health.version, registry.version);
  assert.equal(health.releaseAttestation, attestationUrl);

  const liveInitialize = await (await checkedFetch(mcpUrl, {
    method: "POST",
    headers: {
      accept: "application/json, text/event-stream",
      "content-type": "application/json",
    },
    body: JSON.stringify(initializeRequest),
  })).json();
  assert.deepEqual(liveInitialize, localInitialize(), "served MCP initialize discovery differs from the tagged source");
  assert.equal(liveInitialize.result.serverInfo.version, registry.version);
  console.log("Live MCP discovery and release attestation exactly match the tagged source.");
}

main().catch((error) => {
  console.error(error instanceof Error ? error.stack : String(error));
  process.exitCode = 1;
});

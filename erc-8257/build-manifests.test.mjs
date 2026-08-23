import assert from "node:assert/strict";
import { mkdtemp, readFile, writeFile } from "node:fs/promises";
import os from "node:os";
import path from "node:path";
import test from "node:test";
import canonicalize from "canonicalize";
import { keccak256, toUtf8Bytes } from "ethers";
import { buildManifests, normalizeCreatorAddress } from "./build-manifests.mjs";
import { toolDefinitions } from "./tool-definitions.mjs";
import { verifyPublished } from "./verify-published.mjs";

test("creator binding rejects missing, malformed, and zero addresses", () => {
  assert.throws(() => normalizeCreatorAddress(""), /required/);
  assert.throws(() => normalizeCreatorAddress("not-an-address"), /valid EVM address/);
  assert.throws(() => normalizeCreatorAddress("0x0000000000000000000000000000000000000000"), /zero address/);
});

test("build emits one origin-bound, NFC, lowercase manifest and JCS hash per focused MCP tool", async () => {
  const outDir = await mkdtemp(path.join(os.tmpdir(), "nfh-erc8257-"));
  const creator = "0x1234567890abcdef1234567890abcdef12345678";
  const registrations = await buildManifests({ creator, outDir, clean: false });
  assert.equal(registrations.length, toolDefinitions.length);
  assert.equal(new Set(registrations.map((item) => item.slug)).size, toolDefinitions.length);

  for (const registration of registrations) {
    const raw = await readFile(path.join(outDir, `${registration.slug}.json`));
    assert.notDeepEqual([...raw.subarray(0, 3)], [0xef, 0xbb, 0xbf]);
    const manifest = JSON.parse(raw.toString("utf8"));
    assert.equal(manifest.version, "0.24.0");
    assert.equal(manifest.creatorAddress, creator);
    assert.equal(new URL(manifest.endpoint).origin, new URL(registration.metadataURI).origin);
    assert.equal(registration.manifestHash, keccak256(toUtf8Bytes(canonicalize(manifest))));
    assert.equal(registration.registerOnchain, false);
    assert.equal(manifest["fun.notforhumans.mcp"].signs, false);
    assert.equal(manifest["fun.notforhumans.mcp"].broadcasts, false);
  }
});

test("published-manifest preflight detects exact files, origin binding, and JCS hash drift", async () => {
  const rootDir = await mkdtemp(path.join(os.tmpdir(), "nfh-erc8257-published-"));
  const outDir = path.join(rootDir, "manifests");
  const creator = "0x1234567890abcdef1234567890abcdef12345678";
  const registrations = await buildManifests({ creator, outDir, clean: false });
  const bundlePath = path.join(rootDir, "registration-bundle.json");
  const bundle = {
    schema: "notforhumans-erc8257-registration-bundle/1",
    status: "manifests-published-locally-not-registered",
    standardStatus: "draft",
    registrations
  };
  await writeFile(bundlePath, `${JSON.stringify(bundle, null, 2)}\n`, "utf8");
  assert.deepEqual(
    await verifyPublished({ manifestDir: outDir, bundlePath, allowAbsent: false }),
    { status: "published-locally-not-registered", count: toolDefinitions.length }
  );

  const firstPath = path.join(outDir, `${registrations[0].slug}.json`);
  const first = JSON.parse(await readFile(firstPath, "utf8"));
  first.description = `${first.description} drift`;
  await writeFile(firstPath, `${JSON.stringify(first, null, 2)}\n`, "utf8");
  await assert.rejects(
    verifyPublished({ manifestDir: outDir, bundlePath, allowAbsent: false }),
    /JCS hash mismatch/
  );
});

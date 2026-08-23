import assert from "node:assert/strict";
import { createHash } from "node:crypto";
import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import { spawnSync } from "node:child_process";
import test from "node:test";
import { fileURLToPath } from "node:url";

const here = path.dirname(fileURLToPath(import.meta.url));
const projectRoot = path.resolve(here, "../..");
const serverRoot = path.join(projectRoot, "server");
const attestationPath = path.join(serverRoot, "release-attestation.json");
const baselinePath = path.join(here, "baseline.json");
const selfExcludedPath = "release-attestation.json";

function sha256(value) {
  return createHash("sha256").update(value).digest("hex");
}

function compareUtf8(left, right) {
  return Buffer.from(left, "utf8").compare(Buffer.from(right, "utf8"));
}

function assertSafeRelativePath(relativePath) {
  assert.equal(typeof relativePath, "string");
  assert.ok(relativePath.length > 0);
  assert.ok(!relativePath.startsWith("/") && !relativePath.includes("\\"));
  assert.ok(!/[\0-\x1f\x7f]/u.test(relativePath));
  assert.ok(relativePath.split("/").every((segment) => segment !== "" && segment !== "." && segment !== ".."));
}

function walkRegularFiles(currentDirectory = serverRoot, output = []) {
  const entries = fs.readdirSync(currentDirectory, { withFileTypes: true })
    .sort((left, right) => compareUtf8(left.name, right.name));
  for (const entry of entries) {
    const absolutePath = path.join(currentDirectory, entry.name);
    const stats = fs.lstatSync(absolutePath);
    const relativePath = path.relative(serverRoot, absolutePath).split(path.sep).join("/");
    assertSafeRelativePath(relativePath);
    assert.equal(stats.isSymbolicLink(), false, `Server tree contains a symlink: ${relativePath}`);
    if (stats.isDirectory()) walkRegularFiles(absolutePath, output);
    else {
      assert.equal(stats.isFile(), true, `Server tree contains a non-regular entry: ${relativePath}`);
      output.push(relativePath);
    }
  }
  return output;
}

function computeTreeSha256(entries) {
  const hash = createHash("sha256");
  for (const entry of entries) {
    assertSafeRelativePath(entry.path);
    hash.update(entry.path, "utf8");
    hash.update("\0", "utf8");
    hash.update(String(entry.bytes), "utf8");
    hash.update("\0", "utf8");
    hash.update(entry.sha256, "ascii");
    hash.update("\n", "utf8");
  }
  return hash.digest("hex");
}

function invokeAttestation(root) {
  const inline = [
    '$_SERVER["REQUEST_METHOD"] = "GET";',
    '$_SERVER["REQUEST_URI"] = "/release-attestation";',
    '$_SERVER["REMOTE_ADDR"] = "127.0.0.1";',
    `require ${JSON.stringify(path.join(root, "index.php"))};`,
  ].join(" ");
  return spawnSync("php", ["-r", inline], { encoding: null, maxBuffer: 262_144 });
}

test("copied canonical MCP attestation exactly covers the public server mirror", () => {
  const raw = fs.readFileSync(attestationPath);
  const attestation = JSON.parse(raw.toString("utf8"));
  const baseline = JSON.parse(fs.readFileSync(baselinePath, "utf8"));
  const pinned = baseline.releaseAttestations.mcpServer;

  assert.equal(attestation.schema, "nfh.release-tree-attestation.v1");
  assert.equal(attestation.scope, "mcp-server");
  assert.equal(attestation.sourceRelease, baseline.baseline.sourceRelease);
  assert.equal(attestation.selfExcludedPath, selfExcludedPath);
  assert.equal(attestation.liveVerification.mode, "server-reported-tree-only");
  assert.equal(attestation.authority.wallet, false);
  assert.equal(attestation.authority.signing, false);
  assert.equal(attestation.authority.transactions, false);
  assert.equal(attestation.authority.deployment, false);
  assert.equal(attestation.authority.repair, false);
  assert.equal(attestation.authority.publishing, false);

  const actualPaths = walkRegularFiles()
    .filter((relativePath) => relativePath !== selfExcludedPath)
    .sort(compareUtf8);
  assert.deepEqual(attestation.entries.map((entry) => entry.path), actualPaths);

  for (const entry of attestation.entries) {
    const contents = fs.readFileSync(path.join(serverRoot, entry.path));
    assert.equal(contents.byteLength, entry.bytes, `${entry.path} byte count drifted`);
    assert.equal(sha256(contents), entry.sha256, `${entry.path} content drifted`);
  }

  assert.equal(attestation.tree.files, attestation.entries.length);
  assert.equal(attestation.tree.bytes, attestation.entries.reduce((sum, entry) => sum + entry.bytes, 0));
  assert.equal(attestation.tree.sha256, computeTreeSha256(attestation.entries));
  assert.equal(pinned.documentSha256, sha256(raw));
  assert.equal(pinned.treeSha256, attestation.tree.sha256);
  assert.equal(pinned.treeFiles, attestation.tree.files);
  assert.equal(pinned.treeBytes, attestation.tree.bytes);
  assert.equal(pinned.sourceRelease, attestation.sourceRelease);
});
test("release-attestation route returns exact bytes only while its local tree matches", () => {
  const result = invokeAttestation(serverRoot);
  assert.equal(result.status, 0, result.stderr.toString("utf8"));
  assert.deepEqual(result.stdout, fs.readFileSync(attestationPath));

  const temporaryRoot = fs.mkdtempSync(path.join(os.tmpdir(), "nfh-public-mcp-attestation-"));
  const copiedServer = path.join(temporaryRoot, "server");
  try {
    fs.cpSync(serverRoot, copiedServer, { recursive: true });
    fs.writeFileSync(path.join(copiedServer, "unexpected.php"), "<?php // not attested\n");
    const rejected = invokeAttestation(copiedServer);
    assert.equal(rejected.status, 0, rejected.stderr.toString("utf8"));
    assert.deepEqual(JSON.parse(rejected.stdout.toString("utf8")), {
      error: "The release attestation is temporarily unavailable.",
    });
  } finally {
    fs.rmSync(temporaryRoot, { recursive: true, force: true });
  }
});

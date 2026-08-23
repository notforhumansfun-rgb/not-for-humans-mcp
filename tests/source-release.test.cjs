const assert = require("node:assert/strict");
const fs = require("node:fs");
const os = require("node:os");
const path = require("node:path");
const { spawnSync } = require("node:child_process");
const test = require("node:test");

const repositoryRoot = path.resolve(__dirname, "..");
const expectedCommit = "ab".repeat(20);
const commitAssignmentPattern = /const expectedMonorepoCommit = (?:unresolvedMonorepoCommitPin|'[0-9a-f]{40}');/;

function run(root, args = [], env = {}) {
  return spawnSync(process.execPath, ["scripts/source-release.mjs", ...args], {
    cwd: root,
    env: {
      ...process.env,
      NFH_MONOREPO_RELEASE: "v0.10.0-mainnet-rc.91",
      NFH_MONOREPO_COMMIT: expectedCommit,
      ...env,
    },
    encoding: "utf8",
  });
}

function git(root, args) {
  const result = spawnSync("git", args, { cwd: root, encoding: "utf8" });
  assert.equal(result.status, 0, result.stderr);
  return result.stdout.trim();
}

function fixture({ pinCommit = true } = {}) {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), "nfh-source-release-"));
  fs.mkdirSync(path.join(root, "scripts"), { recursive: true });
  fs.mkdirSync(path.join(root, "server"), { recursive: true });
  fs.writeFileSync(path.join(root, ".gitignore"), "node_modules/\n");
  fs.writeFileSync(path.join(root, "README.md"), "fixture\n");
  fs.writeFileSync(path.join(root, "server/example.txt"), "canonical\n");

  let script = fs.readFileSync(path.join(repositoryRoot, "scripts/source-release.mjs"), "utf8");
  assert.match(script, commitAssignmentPattern);
  script = script.replace(
    commitAssignmentPattern,
    pinCommit
      ? `const expectedMonorepoCommit = '${expectedCommit}';`
      : "const expectedMonorepoCommit = unresolvedMonorepoCommitPin;",
  );
  fs.writeFileSync(path.join(root, "scripts/source-release.mjs"), script);

  git(root, ["init", "-q"]);
  git(root, ["add", "."]);
  git(root, [
    "-c", "user.name=NFH Test",
    "-c", "user.email=nfh-test@example.invalid",
    "-c", "commit.gpgsign=false",
    "commit", "-qm", "fixture",
  ]);
  return root;
}

test("source-release generation and verification bind every tracked byte and accepted Git mode", () => {
  const root = fixture();
  try {
    const generated = run(root);
    assert.equal(generated.status, 0, generated.stderr);
    git(root, ["add", "source-release.json"]);

    const manifest = JSON.parse(fs.readFileSync(path.join(root, "source-release.json"), "utf8"));
    assert.equal(manifest.schema, "nfh.public-mcp-source-release.v1");
    assert.equal(manifest.publicReleaseTag, "v0.24.0");
    assert.equal(manifest.monorepoRelease, "v0.10.0-mainnet-rc.91");
    assert.equal(manifest.monorepoCommit, expectedCommit);
    assert.equal(manifest.fileCount, Object.keys(manifest.files).length);
    assert.deepEqual(Object.keys(manifest.fileModes), Object.keys(manifest.files));
    assert.equal(manifest.fileModes["server/example.txt"], "100644");
    assert.ok(manifest.files["server/example.txt"]);
    assert.ok(manifest.files["scripts/source-release.mjs"]);
    assert.equal(manifest.files["source-release.json"], undefined);
    assert.match(manifest.exactTrackedExclusions["source-release.json"], /self-referential/i);

    const verified = run(root, ["--verify"]);
    assert.equal(verified.status, 0, verified.stderr);

    fs.writeFileSync(path.join(root, "server/example.txt"), "drifted\n");
    const drifted = run(root, ["--verify"]);
    assert.notEqual(drifted.status, 0);
    assert.match(drifted.stderr, /does not match the checked-out files/i);
  } finally {
    fs.rmSync(root, { recursive: true, force: true });
  }
});

test("a newly tracked root file cannot escape the source manifest", () => {
  const root = fixture();
  try {
    const generated = run(root);
    assert.equal(generated.status, 0, generated.stderr);
    git(root, ["add", "source-release.json"]);

    fs.writeFileSync(path.join(root, "NEW-TRACKED-ROOT-FILE.txt"), "must be bound\n");
    git(root, ["add", "NEW-TRACKED-ROOT-FILE.txt"]);
    const stale = run(root, ["--verify"]);
    assert.notEqual(stale.status, 0);
    assert.match(stale.stderr, /does not match the checked-out files/i);

    const regenerated = run(root);
    assert.equal(regenerated.status, 0, regenerated.stderr);
    const manifest = JSON.parse(fs.readFileSync(path.join(root, "source-release.json"), "utf8"));
    assert.ok(manifest.files["NEW-TRACKED-ROOT-FILE.txt"]);
    assert.equal(manifest.fileModes["NEW-TRACKED-ROOT-FILE.txt"], "100644");
  } finally {
    fs.rmSync(root, { recursive: true, force: true });
  }
});

test("source-release rejects tracked symlinks, gitlinks, and dependency trees", () => {
  const root = fixture();
  try {
    fs.symlinkSync("example.txt", path.join(root, "server/link.txt"));
    git(root, ["add", "server/link.txt"]);
    const symlinked = run(root);
    assert.notEqual(symlinked.status, 0);
    assert.match(symlinked.stderr, /disallowed mode 120000/i);

    git(root, ["rm", "--cached", "server/link.txt"]);
    fs.unlinkSync(path.join(root, "server/link.txt"));
    const commit = git(root, ["rev-parse", "HEAD"]);
    git(root, ["update-index", "--add", "--cacheinfo", `160000,${commit},vendor/submodule`]);
    const gitlink = run(root);
    assert.notEqual(gitlink.status, 0);
    assert.match(gitlink.stderr, /disallowed mode 160000/i);

    git(root, ["update-index", "--force-remove", "vendor/submodule"]);
    fs.mkdirSync(path.join(root, "server/node_modules"), { recursive: true });
    fs.writeFileSync(path.join(root, "server/node_modules/hidden.php"), "<?php // forbidden\n");
    git(root, ["add", "-f", "server/node_modules/hidden.php"]);
    const trackedDependencyTree = run(root);
    assert.notEqual(trackedDependencyTree.status, 0);
    assert.match(trackedDependencyTree.stderr, /tracked dependency tree/i);
  } finally {
    fs.rmSync(root, { recursive: true, force: true });
  }
});

test("source-release blocks an unresolved origin pin and rejects any non-exact origin", () => {
  const unresolvedRoot = fixture({ pinCommit: false });
  const pinnedRoot = fixture();
  try {
    const unresolved = run(unresolvedRoot);
    assert.notEqual(unresolved.status, 0);
    assert.match(unresolved.stderr, /final canonical commit pin is unresolved/i);

    const wrongRelease = run(pinnedRoot, [], { NFH_MONOREPO_RELEASE: "v0.10.0-mainnet-rc.90" });
    assert.notEqual(wrongRelease.status, 0);
    assert.match(wrongRelease.stderr, /must be v0\.10\.0-mainnet-rc\.91/i);

    const wrongCommit = run(pinnedRoot, [], { NFH_MONOREPO_COMMIT: "cd".repeat(20) });
    assert.notEqual(wrongCommit.status, 0);
    assert.match(wrongCommit.stderr, /must be the pinned final canonical commit/i);
  } finally {
    fs.rmSync(unresolvedRoot, { recursive: true, force: true });
    fs.rmSync(pinnedRoot, { recursive: true, force: true });
  }
});

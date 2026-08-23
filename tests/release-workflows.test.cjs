const assert = require("node:assert/strict");
const test = require("node:test");

const { readSources, verifyReleaseWorkflows } = require("../scripts/verify-release-workflows.cjs");

function mutate(sources, target, needle, replacement = "") {
  assert.ok(sources[target].includes(needle), `test mutation target is missing: ${needle}`);
  return { ...sources, [target]: sources[target].replace(needle, replacement) };
}

test("current hosted and publication workflows preserve the complete release gate", () => {
  assert.equal(verifyReleaseWorkflows(readSources()), true);
});

const adversarialMutations = [
  ["registry approval", "registry", "approve_registry_publication:", "approval_removed:"],
  ["Registry protected environment", "registry", "name: nfh-mcp-registry-publication", "name: unprotected"],
  ["Registry publication authorization", "registry", ".publicationAuthorized == true", ".publicationAuthorized != true"],
  ["Registry annotated tag", "registry", "git cat-file -t refs/tags/v0.24.0", "git rev-parse refs/tags/v0.24.0"],
  ["Registry canonical source binding", "registry", "node scripts/source-release.mjs --verify", "true"],
  ["Registry protected-main commit equality", "registry", 'test "$DISPATCH_COMMIT" = "$EXPECTED_PUBLIC_COMMIT"', "true"],
  ["Registry executing-workflow equality", "registry", 'test "$WORKFLOW_SOURCE_COMMIT" = "$EXPECTED_PUBLIC_COMMIT"', "true"],
  ["Registry protected-main tip equality", "registry", "git rev-parse refs/remotes/origin/main", "git rev-parse HEAD"],
  ["Registry served equality", "registry", "node scripts/verify-live-mcp.mjs", "true"],
  ["Registry publisher checksum", "registry", "ab128162b0616090b47cf245afe0a23f3ef08936fdce19074f5ba0a4469281ac", "0".repeat(64)],
  ["Registry validation", "registry", '"$RUNNER_TEMP/mcp-publisher" validate', "true"],
  ["npm approval", "npm", "approve_npm_publication:", "approval_removed:"],
  ["npm protected environment", "npm", "name: nfh-npm-publication", "name: unprotected"],
  ["npm exact name", "npm", '.name == "@notforhumans/mcp"', '.name != "@notforhumans/mcp"'],
  ["npm exact version", "npm", '.version == "0.1.0-preview.3"', '.version != "0.1.0-preview.3"'],
  ["npm dist-tag", "npm", '.publishConfig.tag == "preview"', '.publishConfig.tag != "preview"'],
  ["npm unused version", "npm", "https://registry.npmjs.org/%40notforhumans%2Fmcp/0.1.0-preview.3", "https://registry.npmjs.org/unknown"],
  ["npm protected-main equality", "npm", 'test "$DISPATCH_COMMIT" = "$EXPECTED_PUBLIC_COMMIT"', "true"],
  ["npm tarball seal", "npm", 'echo "$EXPECTED_TARBALL_SHA256  npm-payload/notforhumans-mcp-0.1.0-preview.3.tgz" | sha256sum --check', "true"],
  ["npm provenance", "npm", "--access public --tag preview --provenance", "--access public --tag preview"],
  ["hosted tag pin", "monitor", "ref: v0.24.0", "ref: main"],
  ["hosted canonical release", "monitor", '.monorepoRelease == "v0.10.0-mainnet-rc.91"', "true"],
  ["hosted attestation test", "monitor", "node --test operations/founder-away/release-attestation.test.mjs", "true"],
  ["full secret scan", "fullGate", "python3 -m unittest test-scan-server-secrets.py", "true"],
  ["full MCP tests", "fullGate", "php tests/run.php", "true"],
];

for (const [name, target, needle, replacement] of adversarialMutations) {
  test(`workflow verifier rejects removal of ${name}`, () => {
    const sources = readSources();
    assert.throws(() => verifyReleaseWorkflows(mutate(sources, target, needle, replacement)));
  });
}

test("workflow verifier rejects unpinned third-party actions", () => {
  const sources = readSources();
  const mutated = mutate(sources, "npm", "actions/checkout@11d5960a326750d5838078e36cf38b85af677262", "actions/checkout@v4");
  assert.throws(() => verifyReleaseWorkflows(mutated), /not pinned/);
});

test("workflow verifier rejects adding OIDC authority to unprivileged npm verification", () => {
  const sources = readSources();
  const mutated = mutate(
    sources,
    "npm",
    "    permissions:\n      contents: read",
    "    permissions:\n      contents: read\n      id-token: write",
  );
  assert.throws(() => verifyReleaseWorkflows(mutated));
});

test("workflow verifier rejects continue-on-error on a required Registry gate", () => {
  const sources = readSources();
  const mutated = mutate(
    sources,
    "registry",
    "      - name: Verify exact served MCP discovery and attestation\n        run: node scripts/verify-live-mcp.mjs",
    "      - name: Verify exact served MCP discovery and attestation\n        continue-on-error: true\n        run: node scripts/verify-live-mcp.mjs",
  );
  assert.throws(() => verifyReleaseWorkflows(mutated));
});

test("workflow verifier rejects an added rogue Registry job", () => {
  const sources = readSources();
  const mutated = {
    ...sources,
    registry: `${sources.registry}\n  rogue-publisher:\n    runs-on: ubuntu-24.04\n    steps:\n      - run: true\n`,
  };
  assert.throws(() => verifyReleaseWorkflows(mutated));
});

test("workflow verifier rejects an alternate tokenized npm publication job", () => {
  const sources = readSources();
  const mutated = {
    ...sources,
    npm: sources.npm.replace(
      "jobs:\n",
      "jobs:\n  rogue-publisher:\n    runs-on: ubuntu-24.04\n    permissions:\n      id-token: write\n    steps:\n      - run: npx npm-cli publish\n",
    ),
  };
  assert.throws(() => verifyReleaseWorkflows(mutated));
});

test("workflow verifier rejects any byte appended to the privileged Registry command", () => {
  const sources = readSources();
  const mutated = mutate(
    sources,
    "registry",
    '        run: \'"$RUNNER_TEMP/mcp-publisher" publish\'',
    '        run: \'"$RUNNER_TEMP/mcp-publisher" publish; echo unexpected\'',
  );
  assert.throws(() => verifyReleaseWorkflows(mutated), /reviewed SHA-256/);
});

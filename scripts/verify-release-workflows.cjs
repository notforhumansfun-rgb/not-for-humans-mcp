#!/usr/bin/env node

const assert = require("node:assert/strict");
const { createHash } = require("node:crypto");
const fs = require("node:fs");
const path = require("node:path");

let yaml;
try {
  yaml = require("../erc-8257/node_modules/js-yaml");
} catch {
  throw new Error("Workflow verification requires `npm ci --prefix erc-8257` first.");
}

const repositoryRoot = path.resolve(__dirname, "..");
const workflowPaths = {
  registry: ".github/workflows/publish-mcp-registry.yml",
  npm: ".github/workflows/npm-publish.yml",
  monitor: ".github/workflows/founder-away-monitor.yml",
};
const exactWorkflowHashes = {
  registry: "36f822d1da4beb36a6d6216d87f2ecb20fa2265deae4543427c4876e6dc1348a",
  npm: "16a02bed9684066eeca4b3a91c92f1716650f5e28aff3b801fe9bc0bfc10c57b",
  monitor: "35d65d531fc19cdbedf51a5fc9725a2d88d6f0b5453600a81c49b8521e7cbf3a",
};

function invariant(condition, message) {
  if (!condition) throw new Error(message);
}

function includes(source, needle, label) {
  invariant(source.includes(needle), `${label} is missing.`);
}

function exactKeys(value, expected, label) {
  invariant(value && typeof value === "object" && !Array.isArray(value), `${label} must be a mapping.`);
  const actual = Object.keys(value).sort();
  assert.deepEqual(actual, [...expected].sort(), `${label} has an unexpected key graph.`);
}

function exactValue(actual, expected, label) {
  assert.deepEqual(actual, expected, `${label} differs from the reviewed value.`);
}

function parseWorkflow(source, label) {
  invariant(!/(^|\s)(?:&|\*)[A-Za-z0-9_-]+/mu.test(source), `${label} must not use YAML anchors or aliases.`);
  invariant(!source.includes("!!"), `${label} must not use explicit YAML tags.`);
  let parsed;
  try {
    parsed = yaml.load(source, { filename: label });
  } catch (error) {
    throw new Error(`${label} is not valid YAML: ${error.message}`);
  }
  exactKeys(parsed, ["name", "on", "permissions", "concurrency", "jobs"], `${label} top level`);
  rejectProperty(parsed, "continue-on-error", label);
  rejectSecretReferences(parsed, label);
  return parsed;
}

function rejectProperty(value, forbidden, label, location = label) {
  if (!value || typeof value !== "object") return;
  if (Array.isArray(value)) {
    value.forEach((entry, index) => rejectProperty(entry, forbidden, label, `${location}[${index}]`));
    return;
  }
  for (const [key, entry] of Object.entries(value)) {
    invariant(key !== forbidden, `${label} contains forbidden ${forbidden} at ${location}.`);
    rejectProperty(entry, forbidden, label, `${location}.${key}`);
  }
}

function rejectSecretReferences(value, label) {
  if (typeof value === "string") {
    invariant(!/\bsecrets\s*\./u.test(value), `${label} must not receive repository or environment secrets.`);
    invariant(!/\b(?:NODE_AUTH_TOKEN|NPM_TOKEN|GITHUB_TOKEN)\b/u.test(value), `${label} must not reference a publication token.`);
    return;
  }
  if (!value || typeof value !== "object") return;
  for (const entry of Object.values(value)) rejectSecretReferences(entry, label);
}

function assertPinnedActions(workflow, label, expectedActions) {
  const actualActions = [];
  for (const [jobName, job] of Object.entries(workflow.jobs)) {
    invariant(Array.isArray(job.steps), `${label} job ${jobName} must have steps.`);
    for (const step of job.steps) {
      if (!("uses" in step)) continue;
      invariant(typeof step.uses === "string", `${label} job ${jobName} has a dynamic action.`);
      const match = /^([^@\s]+)@([0-9a-f]{40})$/u.exec(step.uses);
      invariant(match, `${label} action ${step.uses} is not pinned to an exact commit.`);
      actualActions.push(match[1]);
    }
  }
  assert.deepEqual(actualActions, expectedActions, `${label} has an unexpected executable action graph.`);
}

function assertExactSteps(job, expectedNames, label) {
  invariant(Array.isArray(job.steps), `${label} steps must be an array.`);
  const names = job.steps.map((step) => step.name ?? null);
  assert.deepEqual(names, expectedNames, `${label} has an unexpected step graph.`);
  for (const [index, step] of job.steps.entries()) {
    const allowed = ["name", "id", "if", "uses", "with", "env", "working-directory", "run"];
    for (const key of Object.keys(step)) {
      invariant(allowed.includes(key), `${label} step ${index + 1} contains unexpected key ${key}.`);
    }
  }
}

function verifyManualInput(workflow, approvalInput, label) {
  exactKeys(workflow.on, ["workflow_dispatch"], `${label} triggers`);
  const dispatch = workflow.on.workflow_dispatch;
  exactKeys(dispatch, ["inputs"], `${label} manual dispatch`);
  exactKeys(dispatch.inputs, ["release_commit", approvalInput], `${label} manual inputs`);
  exactValue(dispatch.inputs.release_commit.required, true, `${label} release commit requirement`);
  exactValue(dispatch.inputs.release_commit.type, "string", `${label} release commit type`);
  exactValue(dispatch.inputs[approvalInput].required, true, `${label} approval requirement`);
  exactValue(dispatch.inputs[approvalInput].default, false, `${label} deny-by-default approval`);
  exactValue(dispatch.inputs[approvalInput].type, "boolean", `${label} approval type`);
}

function verifyExactHash(source, expected, label) {
  const actual = createHash("sha256").update(source).digest("hex");
  invariant(actual === expected, `${label} bytes differ from the reviewed SHA-256 ${expected}.`);
}

function verifyReleaseBindingRun(run, label) {
  const required = [
    'test "$GITHUB_REF" = "refs/heads/main"',
    'test "$GITHUB_REF_PROTECTED" = "true"',
    'test "$DISPATCH_COMMIT" = "$EXPECTED_PUBLIC_COMMIT"',
    'test "$WORKFLOW_SOURCE_COMMIT" = "$EXPECTED_PUBLIC_COMMIT"',
    'git cat-file -t refs/tags/v0.24.0',
    'git rev-list -n 1 refs/tags/v0.24.0',
    'git rev-parse refs/remotes/origin/main',
    "node scripts/source-release.mjs --verify",
  ];
  for (const command of required) includes(run, command, `${label} command ${command}`);
}

function verifyRegistryWorkflow(source) {
  const workflow = parseWorkflow(source, "Registry publish workflow");
  verifyManualInput(workflow, "approve_registry_publication", "Registry publish workflow");
  exactValue(workflow.permissions, { contents: "read" }, "Registry top-level permissions");
  exactValue(workflow.concurrency, { group: "nfh-mcp-registry-v0.24.0", "cancel-in-progress": false }, "Registry concurrency");
  exactKeys(workflow.jobs, ["verify-release", "publish", "post-publish"], "Registry jobs");

  const verify = workflow.jobs["verify-release"];
  const publish = workflow.jobs.publish;
  const post = workflow.jobs["post-publish"];
  exactValue(verify["runs-on"], "ubuntu-24.04", "Registry verifier runner");
  exactValue(verify.permissions, { contents: "read" }, "Registry verifier permissions");
  exactValue(verify.if, "${{ inputs.approve_registry_publication == true }}", "Registry verifier approval condition");
  exactValue(verify.outputs, { payload_sha256: "${{ steps.payload.outputs.sha256 }}" }, "Registry sealed-payload output");
  invariant(!("environment" in verify), "Unprivileged Registry verification must not cross the protected environment.");
  assertExactSteps(verify, [
    "Checkout exact public release tag without publication authority",
    "Use pinned Node runtime",
    "Bind the annotated tag and executing workflow to protected main",
    "Run the exact public-release verification gate",
    "Verify exact served MCP discovery and attestation",
    "Require explicit metadata authorization on this immutable release",
    "Require the immutable Registry version to be unused",
    "Install checksum-pinned MCP publisher 1.7.9 for unprivileged validation",
    "Seal the exact Registry payload",
    "Retain the exact verified Registry payload for the approval boundary",
  ], "Registry unprivileged verifier");
  verifyReleaseBindingRun(verify.steps[2].run, "Registry source binding");

  exactValue(publish.needs, "verify-release", "Registry privileged dependency");
  exactValue(publish.if, "${{ inputs.approve_registry_publication == true }}", "Registry privileged approval condition");
  exactValue(publish["runs-on"], "ubuntu-24.04", "Registry publisher runner");
  exactValue(publish.environment, {
    name: "nfh-mcp-registry-publication",
    url: "https://registry.modelcontextprotocol.io/v0.1/servers/io.github.notforhumansfun-rgb%2Fnot-for-humans/versions/0.24.0",
  }, "Registry protected environment");
  exactValue(publish.permissions, { actions: "read", "id-token": "write" }, "Registry privileged permissions");
  assertExactSteps(publish, [
    "Download only the sealed Registry payload",
    "Recheck the protected approval boundary and exact payload",
    "Install checksum-pinned MCP publisher 1.7.9",
    "Validate the sealed payload against the official Registry",
    "Authenticate with GitHub OIDC",
    "Publish the sealed payload",
  ], "Registry privileged publisher");
  invariant(!publish.steps.some((step) => step.uses?.startsWith("actions/checkout@")), "Registry privileged publisher must not check out repository source.");
  invariant(!publish.steps.some((step) => step.uses?.startsWith("actions/setup-node@")), "Registry privileged publisher must not execute repository JavaScript.");

  exactValue(post.needs, ["verify-release", "publish"], "Registry post-publication dependencies");
  exactValue(post.if, "${{ success() }}", "Registry post-publication condition");
  exactValue(post["runs-on"], "ubuntu-24.04", "Registry post-publication runner");
  exactValue(post.permissions, { contents: "read" }, "Registry post-publication permissions");
  invariant(!("environment" in post), "Registry post-publication audit must not retain the protected environment.");
  assertExactSteps(post, [
    "Checkout the exact published source without OIDC authority",
    "Use pinned Node runtime",
    "Recheck the exact published source without OIDC authority",
    "Deep-compare the official Registry record with the published payload",
    "Verify exact served MCP discovery and attestation again",
    "Complete the live founder-away gate",
    "Retain exact post-publication evidence for 60 days",
  ], "Registry post-publication audit");
  verifyReleaseBindingRun(post.steps[2].run, "Registry post-publication source binding");

  assertPinnedActions(workflow, "Registry publish workflow", [
    "actions/checkout",
    "actions/setup-node",
    "actions/upload-artifact",
    "actions/download-artifact",
    "actions/checkout",
    "actions/setup-node",
    "actions/upload-artifact",
  ]);
  includes(source, "ab128162b0616090b47cf245afe0a23f3ef08936fdce19074f5ba0a4469281ac", "Registry publisher checksum");
  includes(source, '.publicationAuthorized == true', "Registry explicit metadata authorization gate");
  includes(source, "jq -e --slurpfile expected server.json '.server == $expected[0]'", "Registry exact published-record comparison");
  invariant((source.match(/"\$RUNNER_TEMP\/mcp-publisher" publish/gu) ?? []).length === 1, "Registry workflow must contain exactly one publication primitive.");
  invariant(!/\bnpm(?:-cli)?\s+publish\b/iu.test(source), "Registry workflow must not contain an npm publication primitive.");
  verifyExactHash(source, exactWorkflowHashes.registry, "Registry publish workflow");
}

function verifyNpmWorkflow(source) {
  const workflow = parseWorkflow(source, "npm publish workflow");
  verifyManualInput(workflow, "approve_npm_publication", "npm publish workflow");
  exactValue(workflow.permissions, { contents: "read" }, "npm top-level permissions");
  exactValue(workflow.concurrency, { group: "nfh-npm-preview-3", "cancel-in-progress": false }, "npm concurrency");
  exactKeys(workflow.jobs, ["verify-release", "publish", "post-publish"], "npm jobs");

  const verify = workflow.jobs["verify-release"];
  const publish = workflow.jobs.publish;
  const post = workflow.jobs["post-publish"];
  exactValue(verify.if, "${{ inputs.approve_npm_publication == true }}", "npm verifier approval condition");
  exactValue(verify["runs-on"], "ubuntu-24.04", "npm verifier runner");
  exactValue(verify.permissions, { contents: "read" }, "npm verifier permissions");
  exactValue(verify.outputs, { tarball_sha256: "${{ steps.package.outputs.sha256 }}" }, "npm sealed-tarball output");
  invariant(!("environment" in verify), "Unprivileged npm verification must not cross the protected environment.");
  assertExactSteps(verify, [
    "Checkout exact public release tag without publication authority",
    "Use pinned Node runtime",
    "Bind the annotated tag and executing workflow to protected main",
    "Run the exact public-release verification gate",
    "Verify exact served MCP discovery and attestation",
    "Require exact unused npm package metadata",
    "Build inspect and seal the exact package tarball",
    "Retain only the sealed npm payload for approval",
  ], "npm unprivileged verifier");
  verifyReleaseBindingRun(verify.steps[2].run, "npm source binding");

  exactValue(publish.needs, "verify-release", "npm privileged dependency");
  exactValue(publish.if, "${{ inputs.approve_npm_publication == true }}", "npm privileged approval condition");
  exactValue(publish["runs-on"], "ubuntu-24.04", "npm publisher runner");
  exactValue(publish.environment, {
    name: "nfh-npm-publication",
    url: "https://www.npmjs.com/package/@notforhumans/mcp/v/0.1.0-preview.3",
  }, "npm protected environment");
  exactValue(publish.permissions, { actions: "read", "id-token": "write" }, "npm privileged permissions");
  assertExactSteps(publish, [
    "Download only the sealed npm payload",
    "Use pinned Node runtime for trusted publishing",
    "Recheck the protected approval boundary and exact tarball",
    "Publish the sealed preview with npm trusted publishing",
  ], "npm privileged publisher");
  invariant(!publish.steps.some((step) => step.uses?.startsWith("actions/checkout@")), "npm privileged publisher must not check out repository source.");

  exactValue(post.needs, ["verify-release", "publish"], "npm post-publication dependencies");
  exactValue(post.if, "${{ success() }}", "npm post-publication condition");
  exactValue(post["runs-on"], "ubuntu-24.04", "npm post-publication runner");
  exactValue(post.permissions, { contents: "read" }, "npm post-publication permissions");
  invariant(!("environment" in post), "npm post-publication audit must not retain the protected environment.");
  assertExactSteps(post, [
    "Checkout the exact published source without OIDC authority",
    "Use pinned Node runtime",
    "Recheck exact published source without OIDC authority",
    "Authenticate the published version and preview dist-tag",
    "Retain exact post-publication evidence for 60 days",
  ], "npm post-publication audit");
  verifyReleaseBindingRun(post.steps[2].run, "npm post-publication source binding");

  assertPinnedActions(workflow, "npm publish workflow", [
    "actions/checkout",
    "actions/setup-node",
    "actions/upload-artifact",
    "actions/download-artifact",
    "actions/setup-node",
    "actions/checkout",
    "actions/setup-node",
    "actions/upload-artifact",
  ]);
  includes(source, 'https://registry.npmjs.org/%40notforhumans%2Fmcp/0.1.0-preview.3', "npm unused-version preflight");
  includes(source, 'npm-payload/notforhumans-mcp-0.1.0-preview.3.tgz', "npm exact tarball path");
  invariant((source.match(/npm publish npm-payload\/notforhumans-mcp-0\.1\.0-preview\.3\.tgz --access public --tag preview --provenance/gu) ?? []).length === 1, "npm workflow must contain exactly one reviewed publication primitive.");
  invariant(!/\b(?:NODE_AUTH_TOKEN|NPM_TOKEN)\b/u.test(source), "npm workflow must not reference a publication token.");
  verifyExactHash(source, exactWorkflowHashes.npm, "npm publish workflow");
}

function verifyMonitorWorkflow(source) {
  const workflow = parseWorkflow(source, "Hosted monitor workflow");
  exactValue(workflow.permissions, { contents: "read" }, "Hosted monitor permissions");
  exactValue(workflow.concurrency, { group: "nfh-founder-away-monitor", "cancel-in-progress": false }, "Hosted monitor concurrency");
  exactKeys(workflow.jobs, ["verify-public-network"], "Hosted monitor jobs");
  const monitor = workflow.jobs["verify-public-network"];
  exactValue(monitor["runs-on"], "ubuntu-24.04", "Hosted monitor runner");
  assertPinnedActions(workflow, "Hosted monitor workflow", [
    "actions/checkout",
    "actions/setup-node",
    "actions/upload-artifact",
  ]);
  includes(source, 'cron: "*/15 * * * *"', "hosted monitor schedule");
  includes(source, "ref: v0.24.0", "hosted monitor immutable release tag");
  includes(source, "node-version: 24.8.0", "hosted monitor exact Node runtime");
  includes(source, '.monorepoRelease == "v0.10.0-mainnet-rc.91"', "hosted monitor exact canonical release gate");
  includes(source, "node scripts/source-release.mjs --verify", "hosted monitor source-release verification");
  includes(source, "node --test operations/founder-away/release-attestation.test.mjs", "hosted monitor local attestation verification");
  includes(source, "node operations/founder-away/monitor.mjs", "hosted public-network monitor");
  invariant(!source.includes("id-token: write"), "hosted monitor must not receive OIDC publication authority.");
  verifyExactHash(source, exactWorkflowHashes.monitor, "Hosted monitor workflow");
}

function verifyFullGate(source) {
  const requiredCommands = [
    "git status --porcelain --untracked-files=all",
    "node scripts/source-release.mjs --verify",
    "php tests/run.php",
    "php tests/agent-entry-run.php",
    "php tests/mainnet-offer-price-binding-run.php",
    "php tests/tasq-bridge-run.php",
    "node --test tests/registry-metadata.test.cjs",
    "node --test tests/release-workflows.test.cjs",
    "node --test tests/source-release.test.cjs",
    "node --test operations/founder-away/release-attestation.test.mjs",
    "node --test operations/founder-away/monitor.test.mjs",
    "node --test operations/founder-away/seed-missions.test.mjs",
    "python3 -m unittest test-scan-server-secrets.py",
    "npm ci --prefix erc-8257 --ignore-scripts --no-audit --no-fund",
    "npm test --prefix erc-8257",
    "npm ci --prefix npm-package --ignore-scripts --no-audit --no-fund",
    "npm test --prefix npm-package",
    "npm ci --prefix integrations/metamask-agent-wallet --ignore-scripts --no-audit --no-fund",
    "npm test --prefix integrations/metamask-agent-wallet",
    "git diff --check",
  ];
  for (const command of requiredCommands) includes(source, command, `full release gate command ${command}`);
}

function verifyReleaseWorkflows(sources) {
  verifyRegistryWorkflow(sources.registry);
  verifyNpmWorkflow(sources.npm);
  verifyMonitorWorkflow(sources.monitor);
  verifyFullGate(sources.fullGate);
  return true;
}

function readSources() {
  return {
    registry: fs.readFileSync(path.join(repositoryRoot, workflowPaths.registry), "utf8"),
    npm: fs.readFileSync(path.join(repositoryRoot, workflowPaths.npm), "utf8"),
    monitor: fs.readFileSync(path.join(repositoryRoot, workflowPaths.monitor), "utf8"),
    fullGate: fs.readFileSync(path.join(repositoryRoot, "scripts/verify-public-release.sh"), "utf8"),
  };
}

if (require.main === module) {
  verifyReleaseWorkflows(readSources());
  console.log("Public release workflows preserve the exact reviewed graph and approval boundaries.");
}

module.exports = { readSources, verifyReleaseWorkflows };

const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const test = require("node:test");

const testDirectory = __dirname;
const mcpRoot = path.resolve(testDirectory, "..");
const repositoryRoot = mcpRoot;

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

test("public MCP metadata is launch-truthful and pinned to the canonical remote", () => {
  const library = read("server/lib.php");
  const discovery = parseJson(".well-known/agent-card.json");
  const compatibility = parseJson("compatibility-matrix.json");

  assert.equal(compatibility.transport.endpoint, discovery.mcpUrl);
  assert.equal(compatibility.registry.name, "fun.notforhumans/mcp");
  assert.equal(compatibility.currentProtocolRevision, phpConstant(library, "NFH_MCP_PROTOCOL_VERSION"));
  assert.equal(compatibility.capabilities.signing, false);
  assert.equal(compatibility.capabilities.submission, false);
  assert.equal(discovery.walletPolicy.mcpSigns, false);
  assert.equal(discovery.walletPolicy.mcpBroadcasts, false);
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
  assert.equal(compatibility.registry.published, false);
  assert.equal(compatibility.registry.publicationAuthorized, false);
  assert.match(compatibility.evidence.officialRegistrySchemaValidation, /^passed-/);
  assert.equal(compatibility.capabilities.signing, false);
  assert.equal(compatibility.capabilities.submission, false);
  assert.equal(compatibility.capabilities.mainnetSubmission, false);
  assert.equal(compatibility.capabilities.arbitraryTransactionSubmission, false);
  assert.equal(compatibility.capabilities.a2aTasks, false);
  assert.equal(compatibility.capabilities.erc8004Registration, false);
});

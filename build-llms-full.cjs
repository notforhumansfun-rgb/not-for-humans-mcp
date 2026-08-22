const fs = require("node:fs");
const path = require("node:path");

const projectRoot = path.resolve(__dirname, "..");
const serverRoot = path.resolve(__dirname, "server");
const manifest = JSON.parse(fs.readFileSync(path.join(serverRoot, "manifest.json"), "utf8"));
const output = path.join(projectRoot, "03-PRODUCTION/site/llms-full.txt");

const sections = [
  "# NOT FOR HUMANS — complete public machine-readable corpus",
  "",
  "> Generated from the exact 12-document public MCP manifest. Historical private workspace files are intentionally excluded.",
  "",
  "Canonical project: https://notforhumans.fun/",
  "MCP: https://mcp.notforhumans.fun/mcp",
  "Release status: Ethereum mainnet deployed paused; claims and marketplace pending activation",
  "",
  "Stable MCP resources:",
  "- nfh://about",
  "- nfh://claim-spec",
  "- nfh://origin-stream",
  "- nfh://renderer-spec",
  "- nfh://release-policy",
];

for (const entry of manifest) {
  const source = path.join(serverRoot, "corpus", path.basename(entry.file));
  const text = fs.readFileSync(source, "utf8").trim();
  sections.push(
    "",
    "---",
    "",
    `## ${entry.title}`,
    "",
    `Document ID: ${entry.id}`,
    `Status: ${entry.status}`,
    `Canonical source: ${entry.sourceUrl}`,
    `Content type: ${entry.contentType}`,
    "",
    text,
  );
}

fs.writeFileSync(output, `${sections.join("\n")}\n`, "utf8");
console.log(`Wrote ${manifest.length} public documents to ${output}`);

import { mkdir, rm, writeFile } from "node:fs/promises";
import path from "node:path";
import { fileURLToPath, pathToFileURL } from "node:url";
import canonicalize from "canonicalize";
import { getAddress, keccak256, toUtf8Bytes, ZeroAddress } from "ethers";
import { toolDefinitions } from "./tool-definitions.mjs";

export const MANIFEST_TYPE = "https://ercs.ethereum.org/ERCS/erc-8257#tool-manifest-v1";
export const ENDPOINT = "https://mcp.notforhumans.fun/mcp";
export const MANIFEST_ORIGIN = "https://mcp.notforhumans.fun";

function nfcWalk(value, location = "manifest") {
  if (typeof value === "string") {
    if (value !== value.normalize("NFC")) {
      throw new Error(`${location} contains a string that is not NFC-normalized.`);
    }
    return;
  }
  if (Array.isArray(value)) {
    value.forEach((entry, index) => nfcWalk(entry, `${location}[${index}]`));
    return;
  }
  if (value && typeof value === "object") {
    Object.entries(value).forEach(([key, entry]) => {
      nfcWalk(key, `${location} key`);
      nfcWalk(entry, `${location}.${key}`);
    });
  }
}

export function normalizeCreatorAddress(value) {
  if (typeof value !== "string" || value.trim() === "") {
    throw new Error("A production creator address is required (--creator or NFH_ERC8257_CREATOR_ADDRESS). The ERC binds every manifest to registerTool msg.sender.");
  }
  let normalized;
  try {
    normalized = getAddress(value).toLowerCase();
  } catch {
    throw new Error("The ERC-8257 creator address is not a valid EVM address.");
  }
  if (normalized === ZeroAddress) {
    throw new Error("The zero address cannot be used as the ERC-8257 creator.");
  }
  return normalized;
}

export function makeManifest(definition, creatorAddress) {
  const manifest = {
    type: MANIFEST_TYPE,
    name: definition.name,
    description: definition.description,
    endpoint: ENDPOINT,
    inputs: definition.inputs,
    outputs: definition.outputs,
    version: "0.8.2",
    tags: definition.tags,
    creatorAddress,
    "fun.notforhumans.mcp": {
      transport: "streamable-http",
      rpcMethod: "tools/call",
      toolName: definition.mcpTool,
      authority: "prepare-only",
      signs: false,
      broadcasts: false
    }
  };
  validateManifest(definition.slug, manifest);
  return manifest;
}

export function validateManifest(slug, manifest) {
  if (!/^[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?$/.test(slug)) {
    throw new Error(`Invalid ERC-8257 slug: ${slug}`);
  }
  if (manifest.type !== MANIFEST_TYPE) {
    throw new Error(`Unknown manifest type for ${slug}.`);
  }
  if (!/^0x[0-9a-f]{40}$/.test(manifest.creatorAddress) || manifest.creatorAddress === ZeroAddress) {
    throw new Error(`Invalid lowercase, nonzero creatorAddress for ${slug}.`);
  }
  const endpoint = new URL(manifest.endpoint);
  const metadataURI = new URL(`/.well-known/ai-tool/${slug}.json`, MANIFEST_ORIGIN);
  if (endpoint.protocol !== "https:" || endpoint.origin !== metadataURI.origin) {
    throw new Error(`Endpoint and metadata URI origins differ for ${slug}.`);
  }
  if (!Array.isArray(manifest.tags) || manifest.tags.length > 16 || new Set(manifest.tags).size !== manifest.tags.length) {
    throw new Error(`Invalid tags for ${slug}.`);
  }
  for (const tag of manifest.tags) {
    if (!/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/.test(tag) || tag.length > 32) {
      throw new Error(`Invalid tag ${tag} for ${slug}.`);
    }
  }
  nfcWalk(manifest);
  const canonical = canonicalize(manifest);
  if (typeof canonical !== "string") {
    throw new Error(`JCS canonicalization failed for ${slug}.`);
  }
  return canonical;
}

export async function buildManifests({ creator, outDir, clean = true }) {
  const creatorAddress = normalizeCreatorAddress(creator);
  await mkdir(outDir, { recursive: true });
  if (clean) {
    // Remove only the manifest files we are about to generate — never recurse into arbitrary paths.
    for (const definition of toolDefinitions) {
      await rm(path.join(outDir, `${definition.slug}.json`), { force: true });
    }
  }

  const registrations = [];
  for (const definition of toolDefinitions) {
    const manifest = makeManifest(definition, creatorAddress);
    const canonical = validateManifest(definition.slug, manifest);
    const manifestHash = keccak256(toUtf8Bytes(canonical));
    const filename = `${definition.slug}.json`;
    const metadataURI = `${MANIFEST_ORIGIN}/.well-known/ai-tool/${filename}`;
    await writeFile(path.join(outDir, filename), `${JSON.stringify(manifest, null, 2)}\n`, { encoding: "utf8", flag: "wx" });
    registrations.push({
      slug: definition.slug,
      creatorAddress,
      endpoint: ENDPOINT,
      metadataURI,
      manifestHash,
      mcpTool: definition.mcpTool,
      accessPredicate: null,
      registerOnchain: false
    });
  }
  return registrations;
}

function parseArgs(argv) {
  const parsed = { publish: false, creator: process.env.NFH_ERC8257_CREATOR_ADDRESS ?? "", outDir: "" };
  for (let index = 0; index < argv.length; index += 1) {
    const argument = argv[index];
    if (argument === "--creator") parsed.creator = argv[++index] ?? "";
    else if (argument === "--out") parsed.outDir = argv[++index] ?? "";
    else if (argument === "--publish") parsed.publish = true;
    else throw new Error(`Unknown argument: ${argument}`);
  }
  return parsed;
}

async function main() {
  const here = path.dirname(fileURLToPath(import.meta.url));
  const args = parseArgs(process.argv.slice(2));
  if (args.publish) {
    const approved = process.env.NFH_ERC8257_PRODUCTION_CREATOR ?? "";
    if (normalizeCreatorAddress(approved) !== normalizeCreatorAddress(args.creator)) {
      throw new Error("Publication refused: NFH_ERC8257_PRODUCTION_CREATOR must exactly match the requested creator address.");
    }
  }
  const outDir = args.publish
    ? path.resolve(here, "../server/.well-known/ai-tool")
    : path.resolve(args.outDir || path.join(here, "generated"));

  // Prevent --out from pointing outside the project tree.
  const projectRoot = path.resolve(here, "../..");
  const resolvedOut = path.resolve(outDir);
  if (resolvedOut !== projectRoot && !resolvedOut.startsWith(projectRoot + path.sep)) {
    throw new Error(`Output directory must be within the project root (${projectRoot}). Got: ${resolvedOut}`);
  }

  const registrations = await buildManifests({ creator: args.creator, outDir });
  const bundle = {
    schema: "notforhumans-erc8257-registration-bundle/1",
    status: args.publish ? "manifests-published-locally-not-registered" : "local-preview-not-published-not-registered",
    standardStatus: "draft",
    generatedAt: new Date().toISOString(),
    registrations
  };
  const bundlePath = path.resolve(here, "registration-bundle.json");
  await writeFile(bundlePath, `${JSON.stringify(bundle, null, 2)}\n`, "utf8");
  process.stdout.write(`Generated ${registrations.length} JCS-hashed ERC-8257 draft manifests in ${outDir}\n`);
  process.stdout.write(`Registration bundle: ${bundlePath}\n`);
}

const invokedPath = process.argv[1] ? pathToFileURL(path.resolve(process.argv[1])).href : "";
if (import.meta.url === invokedPath) {
  main().catch((error) => {
    process.stderr.write(`${error.message}\n`);
    process.exitCode = 1;
  });
}

import { readdir, readFile } from "node:fs/promises";
import path from "node:path";
import { fileURLToPath, pathToFileURL } from "node:url";
import { keccak256, toUtf8Bytes } from "ethers";
import { MANIFEST_ORIGIN, validateManifest } from "./build-manifests.mjs";

export async function verifyPublished({ manifestDir, bundlePath, allowAbsent = true }) {
  let filenames;
  try {
    filenames = (await readdir(manifestDir)).filter((name) => name.endsWith(".json")).sort();
  } catch (error) {
    if (allowAbsent && error && error.code === "ENOENT") {
      return { status: "not-published", count: 0 };
    }
    throw error;
  }
  if (filenames.length === 0 && allowAbsent) return { status: "not-published", count: 0 };

  const bundle = JSON.parse(await readFile(bundlePath, "utf8"));
  if (bundle.status !== "manifests-published-locally-not-registered") {
    throw new Error("Published manifests require a local-only, not-registered production bundle.");
  }
  const registrations = Array.isArray(bundle.registrations) ? bundle.registrations : [];
  const expected = registrations.map((item) => `${item.slug}.json`).sort();
  if (JSON.stringify(filenames) !== JSON.stringify(expected)) {
    throw new Error("Published manifest filenames do not exactly match the registration bundle.");
  }

  for (const registration of registrations) {
    if (registration.registerOnchain !== false) {
      throw new Error(`Registration bundle must remain unregistered for ${registration.slug}.`);
    }
    const filename = `${registration.slug}.json`;
    const bytes = await readFile(path.join(manifestDir, filename));
    if (bytes.length >= 3 && bytes[0] === 0xef && bytes[1] === 0xbb && bytes[2] === 0xbf) {
      throw new Error(`UTF-8 BOM is forbidden in ${filename}.`);
    }
    const manifest = JSON.parse(bytes.toString("utf8"));
    const canonical = validateManifest(registration.slug, manifest);
    const actualHash = keccak256(toUtf8Bytes(canonical));
    const expectedURI = `${MANIFEST_ORIGIN}/.well-known/ai-tool/${filename}`;
    if (registration.metadataURI !== expectedURI || registration.endpoint !== manifest.endpoint) {
      throw new Error(`Origin binding mismatch for ${filename}.`);
    }
    if (registration.creatorAddress !== manifest.creatorAddress || registration.manifestHash !== actualHash) {
      throw new Error(`Creator or JCS hash mismatch for ${filename}.`);
    }
  }
  return { status: "published-locally-not-registered", count: registrations.length };
}

async function main() {
  const here = path.dirname(fileURLToPath(import.meta.url));
  const result = await verifyPublished({
    manifestDir: path.resolve(here, "../server/.well-known/ai-tool"),
    bundlePath: path.resolve(here, "registration-bundle.json"),
    allowAbsent: true
  });
  process.stdout.write(`ERC-8257 manifest preflight: ${result.status} (${result.count})\n`);
}

const invokedPath = process.argv[1] ? pathToFileURL(path.resolve(process.argv[1])).href : "";
if (import.meta.url === invokedPath) {
  main().catch((error) => {
    process.stderr.write(`${error.message}\n`);
    process.exitCode = 1;
  });
}

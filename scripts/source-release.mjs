#!/usr/bin/env node

import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { lstat, readFile, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { TextDecoder } from 'node:util';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const manifestPath = path.join(root, 'source-release.json');
const expectedMonorepoRelease = 'v0.10.0-mainnet-rc.91';
const unresolvedMonorepoCommitPin = 'FINAL_CANONICAL_COMMIT_PIN_REQUIRED';
const expectedMonorepoCommit = '6893d5935910f6d0b770e352232535551cf08f90';
const publicReleaseTag = 'v0.24.0';
const allowedGitModes = new Set(['100644', '100755']);
const exactTrackedExclusions = new Map([
  ['source-release.json', 'self-referential generated source manifest'],
  [
    'operations/founder-away/founder-away-monitor-report.json',
    'runtime-generated founder-away monitor evidence',
  ],
  [
    'operations/founder-away/network-pulse.json',
    'runtime-generated founder-away network evidence',
  ],
]);
const utf8Decoder = new TextDecoder('utf-8', { fatal: true });

function decodeGitField(buffer, label) {
  try {
    return utf8Decoder.decode(buffer);
  } catch {
    throw new Error(`Git index contains a non-UTF-8 ${label}; public release paths must be UTF-8.`);
  }
}

function validateTrackedPath(relative) {
  if (
    relative.length === 0
    || relative.includes('\\')
    || path.posix.isAbsolute(relative)
    || path.posix.normalize(relative) !== relative
    || relative === '.'
    || relative.startsWith('../')
  ) {
    throw new Error(`Git index contains an unsafe public release path: ${JSON.stringify(relative)}`);
  }
}

function readGitIndex() {
  let output;
  try {
    output = execFileSync('git', ['ls-files', '--stage', '-z'], {
      cwd: root,
      encoding: 'buffer',
      stdio: ['ignore', 'pipe', 'pipe'],
    });
  } catch (error) {
    const detail = Buffer.isBuffer(error?.stderr)
      ? error.stderr.toString('utf8').trim()
      : '';
    throw new Error(`Unable to read the public release Git index${detail ? `: ${detail}` : '.'}`);
  }

  if (output.length > 0 && output[output.length - 1] !== 0) {
    throw new Error('Git index output was not NUL terminated.');
  }

  const entries = [];
  const seenPaths = new Set();
  let offset = 0;
  while (offset < output.length) {
    const terminator = output.indexOf(0, offset);
    if (terminator === -1) throw new Error('Git index output contains a truncated record.');
    const record = output.subarray(offset, terminator);
    offset = terminator + 1;
    if (record.length === 0) continue;

    const separator = record.indexOf(9);
    if (separator === -1) throw new Error('Git index output contains a malformed record.');
    const metadata = decodeGitField(record.subarray(0, separator), 'metadata record');
    const relative = decodeGitField(record.subarray(separator + 1), 'path');
    const match = /^(\d{6}) ([0-9a-f]+) ([0-3])$/.exec(metadata);
    if (!match) throw new Error(`Git index output contains malformed metadata for ${JSON.stringify(relative)}.`);

    const [, mode, objectId, stage] = match;
    validateTrackedPath(relative);
    if (stage !== '0') {
      throw new Error(`Git index contains an unresolved stage ${stage} entry: ${relative}`);
    }
    if (seenPaths.has(relative)) throw new Error(`Git index contains a duplicate path: ${relative}`);
    seenPaths.add(relative);
    if (!allowedGitModes.has(mode)) {
      throw new Error(`Git index contains disallowed mode ${mode} at ${relative}; symlinks, submodules, and special entries are forbidden.`);
    }
    if (relative.split('/').includes('node_modules')) {
      throw new Error(`Git index contains a tracked dependency tree: ${relative}`);
    }
    entries.push({ mode, objectId, path: relative });
  }

  if (entries.length === 0) throw new Error('Public release Git index contains no tracked files.');
  entries.sort((left, right) => Buffer.from(left.path).compare(Buffer.from(right.path)));
  return entries;
}

async function hashFile(entry) {
  const absolute = path.join(root, entry.path);
  const stats = await lstat(absolute);
  if (!stats.isFile() || stats.isSymbolicLink()) {
    throw new Error(`Tracked public release source is not a regular file: ${entry.path}`);
  }
  return createHash('sha256').update(await readFile(absolute)).digest('hex');
}

async function build(origin) {
  const trackedEntries = readGitIndex();
  const includedEntries = trackedEntries.filter((entry) => !exactTrackedExclusions.has(entry.path));
  const files = {};
  const fileModes = {};
  for (const entry of includedEntries) {
    files[entry.path] = await hashFile(entry);
    fileModes[entry.path] = entry.mode;
  }
  return {
    schema: 'nfh.public-mcp-source-release.v1',
    algorithm: 'sha256',
    protocolVersion: '0.24.0',
    publicReleaseTag,
    canonicalRegistryName: 'io.github.notforhumansfun-rgb/not-for-humans',
    publicSource: 'https://github.com/notforhumansfun-rgb/not-for-humans-mcp',
    monorepoRelease: origin.monorepoRelease,
    monorepoCommit: origin.monorepoCommit,
    liveMcp: 'https://mcp.notforhumans.fun/mcp',
    authority: 'source reproducibility only; no wallet, deployment, publication, signing, or transaction authority',
    selection: 'all stage-0 files in the Git index, except the exact tracked exclusions declared below',
    allowedGitModes: [...allowedGitModes],
    exactTrackedExclusions: Object.fromEntries(exactTrackedExclusions),
    fileCount: includedEntries.length,
    fileModes,
    files,
  };
}

function validateOrigin(origin) {
  if (expectedMonorepoCommit === unresolvedMonorepoCommitPin) {
    throw new Error('Final canonical commit pin is unresolved; replace FINAL_CANONICAL_COMMIT_PIN_REQUIRED with the settled 40-hex release commit.');
  }
  if (!/^[0-9a-f]{40}$/.test(expectedMonorepoCommit)) {
    throw new Error('The pinned final canonical commit is not a lowercase 40-hex commit.');
  }
  if (origin?.monorepoRelease !== expectedMonorepoRelease) {
    throw new Error(`NFH_MONOREPO_RELEASE must be ${expectedMonorepoRelease}.`);
  }
  if (origin?.monorepoCommit !== expectedMonorepoCommit) {
    throw new Error(`NFH_MONOREPO_COMMIT must be the pinned final canonical commit ${expectedMonorepoCommit}.`);
  }
}

async function main() {
  const verify = process.argv.includes('--verify');
  let origin;
  if (verify) {
    origin = JSON.parse(await readFile(manifestPath, 'utf8'));
  } else {
    origin = {
      monorepoRelease: process.env.NFH_MONOREPO_RELEASE,
      monorepoCommit: process.env.NFH_MONOREPO_COMMIT,
    };
  }
  validateOrigin(origin);
  const generated = `${JSON.stringify(await build(origin), null, 2)}\n`;
  if (verify) {
    const current = await readFile(manifestPath, 'utf8');
    if (current !== generated) throw new Error('Public MCP source release manifest does not match the checked-out files.');
    console.log('Public MCP source release manifest verified.');
  } else {
    await writeFile(manifestPath, generated, { mode: 0o644 });
    console.log('Public MCP source release manifest written.');
  }
}

main().catch((error) => {
  console.error(error instanceof Error ? error.message : String(error));
  process.exitCode = 1;
});

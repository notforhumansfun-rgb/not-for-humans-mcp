import { createHash } from 'node:crypto';
import { basename } from 'node:path';
import { WAKE_PACKET_SCHEMA } from './wake.mjs';

export const RECEIPT_SCHEMA = 'nfh.local-work-receipt.v1';

function summaryText(value) {
  if (typeof value !== 'string') throw new Error('summary must be text.');
  const normalized = value.trim().replace(/\s+/g, ' ');
  if (normalized.length < 8 || normalized.length > 280) {
    throw new Error('summary must be between 8 and 280 characters.');
  }
  if (/\p{Cc}|\p{Cf}/u.test(normalized)) throw new Error('summary contains unsupported control characters.');
  return normalized;
}

function sourceUrls(values = []) {
  if (!Array.isArray(values) || values.length > 10) throw new Error('Use no more than 10 source URLs.');
  return values.map((value) => {
    let parsed;
    try { parsed = new URL(value); } catch { throw new Error(`Invalid source URL: ${value}`); }
    if (parsed.protocol !== 'https:' && parsed.protocol !== 'http:') {
      throw new Error(`Source URL must use HTTP or HTTPS: ${value}`);
    }
    if (parsed.username || parsed.password || parsed.toString().length > 2_048) {
      throw new Error(`Source URL must not contain credentials and must be 2,048 characters or shorter: ${value}`);
    }
    return parsed.toString();
  });
}

export function createReceipt({ packet, resultBytes, resultPath, summary, sources }, options = {}) {
  if (!packet || packet.schema !== WAKE_PACKET_SCHEMA) throw new Error('packet must be an NFH wake packet.');
  const bytes = Buffer.isBuffer(resultBytes) ? resultBytes : Buffer.from(resultBytes || '');
  if (bytes.length === 0) throw new Error('result must not be empty.');
  if (bytes.length > 1_000_000) throw new Error('result must be 1 MB or smaller.');

  return {
    schema: RECEIPT_SCHEMA,
    status: 'SELF_REPORTED_UNVERIFIED',
    acceptedWork: false,
    createdAt: options.createdAt || new Date().toISOString(),
    tokenId: packet.tokenId,
    task: packet.task,
    summary: summaryText(summary),
    artifact: {
      file: basename(resultPath),
      sha256: createHash('sha256').update(bytes).digest('hex'),
      bytes: bytes.length,
    },
    sources: sourceUrls(sources),
    authority: {
      signed: false,
      published: false,
      paid: false,
      walletAction: false,
    },
    verification: {
      statement: 'This receipt proves only that these local bytes were hashed with this self-reported summary.',
      acceptedWorkRoute: 'https://notforhumans.fun/works/#accepted-work',
    },
  };
}

export function renderReceipt(receipt) {
  return `# NFH #${receipt.tokenId} local work receipt

**Status:** ${receipt.status}

**Accepted Work:** no

**Created:** ${receipt.createdAt}

## Task

${receipt.task}

## Self-reported result

${receipt.summary}

## Artifact

- File: \`${receipt.artifact.file}\`
- SHA-256: \`${receipt.artifact.sha256}\`
- Bytes: ${receipt.artifact.bytes}

## Sources

${receipt.sources.length ? receipt.sources.map((url) => `- ${url}`).join('\n') : '- No source URLs supplied.'}

This receipt proves only that the listed local bytes were hashed with this self-reported summary. It is not a signature, payment record, capability proof, endorsement, or accepted-work receipt. Use the dual-signed NFH Accepted Work flow for independently accepted evidence.
`;
}

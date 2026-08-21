import { validateEndpoint } from './mcp.mjs';

export const DEFAULT_PRESENCE_BASE = 'https://mcp.notforhumans.fun/agent-presence';

function validDate(value, label) {
  if (typeof value !== 'string') throw new Error(`Holder proof ${label} is missing.`);
  const milliseconds = Date.parse(value);
  if (!Number.isFinite(milliseconds)) throw new Error(`Holder proof ${label} is invalid.`);
  return milliseconds;
}

export async function getHolderPresence(tokenId, expectedOwner, options = {}) {
  const base = validateEndpoint(options.presenceBase || DEFAULT_PRESENCE_BASE).replace(/\/$/, '');
  if (base !== DEFAULT_PRESENCE_BASE && options.allowNonCanonical !== true) {
    throw new Error('Holder proof must come from the canonical NFH Agent Presence service.');
  }
  const fetchImpl = options.fetchImpl || globalThis.fetch;
  if (typeof fetchImpl !== 'function') throw new Error('A Fetch-compatible function is required.');

  const response = await fetchImpl(`${base}/${tokenId}`, {
    method: 'GET',
    headers: {
      Accept: 'application/json',
      'User-Agent': 'NFH-Wake-Kit/0.2',
    },
  });
  if (!response.ok) throw new Error(`NFH holder-proof request failed with HTTP ${response.status}.`);
  const body = await response.json();
  if (body?.schema !== 'nfh.agent-presence.v1' || !Array.isArray(body.agents)) {
    throw new Error('The holder-proof service returned an unsupported response.');
  }

  const record = body.agents.find((item) => item?.tokenId === tokenId);
  const passportUrl = `https://notforhumans.fun/passport/?token=${tokenId}#presence-title`;
  if (!record) {
    throw new Error(`A fresh owner signature is required. Open ${passportUrl}, connect the owner wallet, and choose Wake this NFH.`);
  }
  if (record.mode !== 'owner-heartbeat') {
    throw new Error('A delegated-agent heartbeat does not satisfy the collector gate. The current NFT owner must sign directly.');
  }
  if (record.active !== true || record.signatureVerified !== true) {
    throw new Error('The NFH owner heartbeat is not active and signature-verified.');
  }
  if (typeof record.owner !== 'string' || record.owner.toLowerCase() !== expectedOwner.toLowerCase()) {
    throw new Error('The heartbeat signer does not match the current NFH owner.');
  }

  const now = options.now instanceof Date ? options.now.getTime() : (options.now ?? Date.now());
  const verifiedAt = validDate(record.ownershipVerifiedAt, 'ownershipVerifiedAt');
  const expiresAt = validDate(record.expiresAt, 'expiresAt');
  if (verifiedAt > now + 60_000 || expiresAt <= now || expiresAt - verifiedAt > 31 * 60_000) {
    throw new Error('The NFH owner heartbeat is expired or outside the 30-minute holder-proof window.');
  }

  return {
    required: true,
    status: 'HOLDER_VERIFIED_AT_WAKE',
    tokenId,
    owner: record.owner.toLowerCase(),
    method: 'owner-signed-agent-presence-plus-live-ownerOf',
    mode: record.mode,
    signatureVerified: true,
    ownershipVerifiedAt: record.ownershipVerifiedAt,
    expiresAt: record.expiresAt,
    ownershipEpochId: record.ownershipEpochId || null,
    sourceUrl: `${base}/${tokenId}`,
    passportUrl,
    limitations: 'This proves the current owner signed a short-lived participation heartbeat. It grants no transaction, spending, marketplace, posting, or account authority.',
  };
}

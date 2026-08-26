import { callMcpTool, DEFAULT_MCP_ENDPOINT, validateEndpoint } from './mcp.mjs';
import { getHolderPresence } from './presence.mjs';

export const WAKE_PACKET_SCHEMA = 'nfh.wake-packet.v1';

function canonicalMcpEndpoint(endpoint = DEFAULT_MCP_ENDPOINT) {
  const normalized = validateEndpoint(endpoint);
  if (normalized !== validateEndpoint(DEFAULT_MCP_ENDPOINT)) {
    throw new Error('Holder verification must use the canonical NFH MCP endpoint.');
  }
  return DEFAULT_MCP_ENDPOINT;
}

function verifiedIdentity(identity, tokenId) {
  if (identity.tokenId !== tokenId) throw new Error('The MCP returned a different token ID than requested.');
  if (identity.claimed !== true || identity.claimVerified !== true) {
    throw new Error('The MCP could not verify this NFH as claimed.');
  }
  if (identity.pfpUrl !== `https://notforhumans.fun/pfp/${tokenId}`
    || !/^0x[a-fA-F0-9]{40}$/.test(identity.owner || '')) {
    throw new Error('The MCP returned an invalid canonical identity record.');
  }
  return { ...identity, owner: identity.owner.toLowerCase() };
}

export async function getCanonicalVerifiedIdentity(tokenId, options = {}) {
  const endpoint = canonicalMcpEndpoint(options.endpoint);
  const identity = await callMcpTool('get_agent_pfp', { tokenId }, {
    endpoint,
    fetchImpl: options.fetchImpl,
  });
  return verifiedIdentity(identity, tokenId);
}

function verifiedCreationTime(value, holderGate, now) {
  const createdAt = value || new Date(now).toISOString();
  const created = Date.parse(createdAt);
  const verified = Date.parse(holderGate.ownershipVerifiedAt);
  const expires = Date.parse(holderGate.expiresAt);
  if (!Number.isFinite(created) || created < verified || created >= expires || created > now + 60_000) {
    throw new Error('Wake evidence must be created inside the active holder-proof window.');
  }
  return createdAt;
}

function publicText(value, label, minimum, maximum) {
  if (typeof value !== 'string') throw new Error(`${label} must be text.`);
  const normalized = value.trim().replace(/\s+/g, ' ');
  if (normalized.length < minimum || normalized.length > maximum) {
    throw new Error(`${label} must be between ${minimum} and ${maximum} characters.`);
  }
  if (/\p{Cc}|\p{Cf}/u.test(normalized)) throw new Error(`${label} contains unsupported control characters.`);
  return normalized;
}

export function validateTokenId(value) {
  const tokenId = typeof value === 'string' && /^\d+$/.test(value) ? Number(value) : value;
  if (!Number.isSafeInteger(tokenId) || tokenId < 0 || tokenId > 9999) {
    throw new Error('tokenId must be an integer between 0 and 9999.');
  }
  return tokenId;
}

export async function createWakePacket(input, options = {}) {
  const tokenId = validateTokenId(input.tokenId);
  const task = publicText(input.task, 'task', 8, 280);
  const endpoint = canonicalMcpEndpoint(options.endpoint);
  const toolOptions = { endpoint, fetchImpl: options.fetchImpl };

  const identity = await getCanonicalVerifiedIdentity(tokenId, toolOptions);
  const [holderGate, nextAction] = await Promise.all([
    getHolderPresence(tokenId, identity.owner, {
      presenceBase: options.presenceBase,
      fetchImpl: options.fetchImpl,
      now: options.now,
    }),
    callMcpTool('get_agent_next_action', { tokenId }, toolOptions),
  ]);

  if (nextAction.tokenId !== tokenId) throw new Error('The MCP returned a different token ID than requested.');
  if (nextAction.schema !== 'nfh.agent-next-action.v1') {
    throw new Error('The MCP returned an unsupported next-action schema.');
  }
  if (nextAction.authority?.readOnly !== true
    || nextAction.authority?.signs !== false
    || nextAction.authority?.movesFunds !== false) {
    throw new Error('The MCP next-action authority boundary was missing or unsafe.');
  }

  // Re-read both canonical sources immediately before constructing evidence so
  // an ownership change during the earlier requests fails closed.
  const finalIdentity = await getCanonicalVerifiedIdentity(tokenId, toolOptions);
  if (finalIdentity.owner !== identity.owner) {
    throw new Error('The current NFH owner changed while the wake packet was being created.');
  }
  const finalHolderGate = await getHolderPresence(tokenId, finalIdentity.owner, {
    fetchImpl: options.fetchImpl,
    now: options.now,
  });
  if (finalHolderGate.owner !== holderGate.owner) {
    throw new Error('The NFH holder proof changed while the wake packet was being created.');
  }
  const now = options.now instanceof Date ? options.now.getTime() : (options.now ?? Date.now());
  const createdAt = verifiedCreationTime(options.createdAt, finalHolderGate, now);
  return {
    schema: WAKE_PACKET_SCHEMA,
    createdAt,
    tokenId,
    task,
    identity: {
      label: `NFH #${tokenId}`,
      pfpUrl: finalIdentity.pfpUrl,
      owner: finalIdentity.owner,
      ownershipVerifiedAtWake: true,
      seedFinalized: finalIdentity.seedFinalized === true,
      seedHash: finalIdentity.seedHash || null,
      sourceTool: 'get_agent_pfp',
    },
    holderGate: finalHolderGate,
    networkState: {
      state: nextAction.state,
      recommendedAction: nextAction.recommendedAction,
      evidence: nextAction.evidence,
      workingState: nextAction.workingState,
      sourceTool: 'get_agent_next_action',
    },
    executionBoundary: {
      mode: 'READ_ONLY_LOCAL_WORK',
      impersonatesOwner: false,
      walletAuthority: false,
      signingAuthority: false,
      transactionAuthority: false,
      publicationAuthority: false,
      instructions: 'The required signature publishes only a short-lived presence heartbeat; the kit uses that heartbeat as its holder gate. Complete only the stated task. Use public sources, cite them, and stop before any further signing, spending, trading, posting, messaging, or publishing.',
    },
    receipt: {
      status: 'AWAITING_RESULT',
      acceptedWork: false,
      note: 'This packet is not proof of completion. Create a self-reported receipt only after a result exists.',
    },
    sources: [
      { label: 'Canonical NFH MCP', url: endpoint },
      { label: 'NFH Passport', url: `https://notforhumans.fun/passport/?token=${tokenId}` },
    ],
  };
}

export function renderMission(packet) {
  return `# Wake packet: NFH #${packet.tokenId}

![NFH #${packet.tokenId}](${packet.identity.pfpUrl})

## Mission

${packet.task}

## Public identity evidence

- Identity: NFH #${packet.tokenId}
- Owner verified at wake: ${packet.identity.ownershipVerifiedAtWake ? 'yes' : 'no'}
- Holder gate: ${packet.holderGate.status}
- Holder proof expires: ${packet.holderGate.expiresAt}
- Canonical seed finalized: ${packet.identity.seedFinalized ? 'yes' : 'no'}
- Network state: ${packet.networkState.state}
- Suggested next move: ${packet.networkState.recommendedAction?.label || 'Inspect the public network'}
- Passport: https://notforhumans.fun/passport/?token=${packet.tokenId}

## Operating boundary

The canonical server verified a fresh presence signature from NFH #${packet.tokenId}'s current owner. The signature publishes only a short-lived heartbeat; this kit uses that heartbeat as its holder gate. It does not authorize the mission or grant any other authority. Complete only the mission above. Use public sources and cite them. Do not sign again, spend, trade, transfer, approve, post, message, or publish. Do not claim a capability that the result does not demonstrate.

When the result is saved locally, create a receipt with:

\`\`\`sh
npm run receipt -- --packet .wake/nfh-${packet.tokenId}/wake.json --result ./result.md --summary "What the result actually demonstrates" --source https://example.com/source
\`\`\`

The generated receipt is labelled \`SELF_REPORTED_UNVERIFIED\`. Accepted Work requires the separate dual-signature NFH flow.
`;
}

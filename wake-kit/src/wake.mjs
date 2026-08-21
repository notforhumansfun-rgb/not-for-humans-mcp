import { callMcpTool, DEFAULT_MCP_ENDPOINT } from './mcp.mjs';

export const WAKE_PACKET_SCHEMA = 'nfh.wake-packet.v1';

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
  const endpoint = options.endpoint || DEFAULT_MCP_ENDPOINT;
  const toolOptions = { endpoint, fetchImpl: options.fetchImpl };

  const [identity, nextAction] = await Promise.all([
    callMcpTool('get_agent_pfp', { tokenId }, toolOptions),
    callMcpTool('get_agent_next_action', { tokenId }, toolOptions),
  ]);

  if (identity.tokenId !== tokenId || nextAction.tokenId !== tokenId) {
    throw new Error('The MCP returned a different token ID than requested.');
  }
  if (identity.claimed !== true || identity.claimVerified !== true) {
    throw new Error('The MCP could not verify this NFH as claimed.');
  }
  if (identity.pfpUrl !== `https://notforhumans.fun/pfp/${tokenId}`
    || !/^0x[a-fA-F0-9]{40}$/.test(identity.owner || '')) {
    throw new Error('The MCP returned an invalid canonical identity record.');
  }
  if (nextAction.schema !== 'nfh.agent-next-action.v1') {
    throw new Error('The MCP returned an unsupported next-action schema.');
  }
  if (nextAction.authority?.readOnly !== true
    || nextAction.authority?.signs !== false
    || nextAction.authority?.movesFunds !== false) {
    throw new Error('The MCP next-action authority boundary was missing or unsafe.');
  }

  const createdAt = options.createdAt || new Date().toISOString();
  return {
    schema: WAKE_PACKET_SCHEMA,
    createdAt,
    tokenId,
    task,
    identity: {
      label: `NFH #${tokenId}`,
      pfpUrl: identity.pfpUrl,
      owner: identity.owner,
      ownershipVerifiedAtWake: true,
      seedFinalized: identity.seedFinalized === true,
      seedHash: identity.seedHash || null,
      sourceTool: 'get_agent_pfp',
    },
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
      instructions: 'Complete only the stated task. Use public sources, cite them, and stop before signing, spending, trading, posting, messaging, or publishing.',
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
- Canonical seed finalized: ${packet.identity.seedFinalized ? 'yes' : 'no'}
- Network state: ${packet.networkState.state}
- Suggested next move: ${packet.networkState.recommendedAction?.label || 'Inspect the public network'}
- Passport: https://notforhumans.fun/passport/?token=${packet.tokenId}

## Operating boundary

Treat NFH #${packet.tokenId} as a public session identity, not as proof that you control its owner wallet. Complete only the mission above. Use public sources and cite them. Do not sign, spend, trade, transfer, approve, post, message, or publish. Do not claim a capability that the result does not demonstrate.

When the result is saved locally, create a receipt with:

\`\`\`sh
npm run receipt -- --packet .wake/nfh-${packet.tokenId}/wake.json --result ./result.md --summary "What the result actually demonstrates" --source https://example.com/source
\`\`\`

The generated receipt is labelled \`SELF_REPORTED_UNVERIFIED\`. Accepted Work requires the separate dual-signature NFH flow.
`;
}

import assert from 'node:assert/strict';
import test from 'node:test';
import { callMcpTool, validateEndpoint } from '../src/mcp.mjs';
import { createReceipt } from '../src/receipt.mjs';
import { createWakePacket, renderMission, WAKE_PACKET_SCHEMA } from '../src/wake.mjs';

function response(value) {
  return { ok: true, status: 200, json: async () => value };
}

function toolFetch(endpoint, init) {
  const request = JSON.parse(init.body);
  const tokenId = request.params.arguments.tokenId;
  const payload = request.params.name === 'get_agent_pfp'
    ? {
        tokenId,
        pfpUrl: `https://notforhumans.fun/pfp/${tokenId}`,
        claimed: true,
        claimVerified: true,
        owner: '0x1111111111111111111111111111111111111111',
        seedFinalized: true,
        seedHash: `0x${'12'.repeat(32)}`,
      }
    : {
        schema: 'nfh.agent-next-action.v1',
        tokenId,
        state: 'UNPROVEN',
        recommendedAction: { action: 'FIND_FIRST_JOB', label: 'Find the first job' },
        evidence: { acceptedHistory: 0 },
        workingState: { active: false },
        authority: { readOnly: true, signs: false, movesFunds: false },
      };
  return response({ jsonrpc: '2.0', id: 1, result: { structuredContent: payload } });
}

test('MCP endpoint requires HTTPS except on localhost', () => {
  assert.equal(validateEndpoint('https://mcp.notforhumans.fun/mcp'), 'https://mcp.notforhumans.fun/mcp');
  assert.equal(validateEndpoint('http://127.0.0.1:8787/mcp'), 'http://127.0.0.1:8787/mcp');
  assert.throws(() => validateEndpoint('http://example.com/mcp'), /must use HTTPS/);
});

test('MCP errors fail closed', async () => {
  await assert.rejects(
    callMcpTool('get_agent_pfp', { tokenId: 1 }, {
      fetchImpl: async () => response({ result: { isError: true, content: [{ type: 'text', text: 'nope' }] } }),
    }),
    /nope/,
  );
});

test('wake packet binds one verified identity, task, and read-only boundary', async () => {
  const packet = await createWakePacket(
    { tokenId: 1003, task: 'Map three useful MCP integration paths.' },
    { fetchImpl: toolFetch, createdAt: '2026-08-21T12:00:00.000Z' },
  );
  assert.equal(packet.schema, WAKE_PACKET_SCHEMA);
  assert.equal(packet.tokenId, 1003);
  assert.equal(packet.identity.ownershipVerifiedAtWake, true);
  assert.equal(packet.executionBoundary.walletAuthority, false);
  assert.equal(packet.receipt.status, 'AWAITING_RESULT');
  assert.match(renderMission(packet), /SELF_REPORTED_UNVERIFIED/);
});

test('wake packet rejects an unverified identity', async () => {
  await assert.rejects(
    createWakePacket(
      { tokenId: 7, task: 'Perform one bounded public research task.' },
      {
        fetchImpl: async (endpoint, init) => {
          const request = JSON.parse(init.body);
          if (request.params.name === 'get_agent_pfp') {
            return response({ result: { structuredContent: { tokenId: 7, claimed: true, claimVerified: false } } });
          }
          return toolFetch(endpoint, init);
        },
      },
    ),
    /could not verify/,
  );
});

test('receipt hashes result bytes and stays explicitly unverified', () => {
  const packet = {
    schema: WAKE_PACKET_SCHEMA,
    tokenId: 1003,
    task: 'Map three useful MCP integration paths.',
  };
  const receipt = createReceipt({
    packet,
    resultBytes: Buffer.from('one result\n'),
    resultPath: '/tmp/result.md',
    summary: 'Mapped three integrations with source links.',
    sources: ['https://modelcontextprotocol.io/'],
  }, { createdAt: '2026-08-21T12:05:00.000Z' });

  assert.equal(receipt.status, 'SELF_REPORTED_UNVERIFIED');
  assert.equal(receipt.acceptedWork, false);
  assert.equal(receipt.authority.signed, false);
  assert.equal(receipt.artifact.sha256, '4ee64bb7837ca25a1a0c3613483eda1fe97f4f0cf7f61b6d2d7974c57257c67e');
});

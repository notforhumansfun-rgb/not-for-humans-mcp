import assert from 'node:assert/strict';
import test from 'node:test';
import { callMcpTool, validateEndpoint } from '../src/mcp.mjs';
import { createReceipt } from '../src/receipt.mjs';
import { createWakePacket, renderMission, WAKE_PACKET_SCHEMA } from '../src/wake.mjs';

function response(value) {
  return { ok: true, status: 200, json: async () => value };
}

function toolFetch(endpoint, init) {
  if (init.method === 'GET') {
    const tokenId = Number(endpoint.split('/').at(-1));
    return response({
      schema: 'nfh.agent-presence.v1',
      agents: [{
        tokenId,
        owner: '0x1111111111111111111111111111111111111111',
        mode: 'owner-heartbeat',
        active: true,
        signatureVerified: true,
        ownershipVerifiedAt: '2026-08-21T12:00:00.000Z',
        expiresAt: '2026-08-21T12:30:00.000Z',
        ownershipEpochId: 'epoch-test',
      }],
    });
  }
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
    {
      fetchImpl: toolFetch,
      createdAt: '2026-08-21T12:00:00.000Z',
      now: Date.parse('2026-08-21T12:01:00.000Z'),
    },
  );
  assert.equal(packet.schema, WAKE_PACKET_SCHEMA);
  assert.equal(packet.tokenId, 1003);
  assert.equal(packet.identity.ownershipVerifiedAtWake, true);
  assert.equal(packet.holderGate.status, 'HOLDER_VERIFIED_AT_WAKE');
  assert.equal(packet.holderGate.mode, 'owner-heartbeat');
  assert.equal(packet.executionBoundary.walletAuthority, false);
  assert.equal(packet.receipt.status, 'AWAITING_RESULT');
  assert.match(renderMission(packet), /SELF_REPORTED_UNVERIFIED/);
});

test('wake packet rejects a token without a fresh owner heartbeat', async () => {
  await assert.rejects(
    createWakePacket(
      { tokenId: 1003, task: 'Perform one bounded public research task.' },
      {
        fetchImpl: async (endpoint, init) => init.method === 'GET'
          ? response({ schema: 'nfh.agent-presence.v1', agents: [] })
          : toolFetch(endpoint, init),
      },
    ),
    /fresh owner signature is required/,
  );
});

test('wake packet rejects delegated-agent presence for the collector gate', async () => {
  await assert.rejects(
    createWakePacket(
      { tokenId: 1003, task: 'Perform one bounded public research task.' },
      {
        fetchImpl: async (endpoint, init) => {
          if (init.method !== 'GET') return toolFetch(endpoint, init);
          const reply = await toolFetch(endpoint, init);
          const body = await reply.json();
          body.agents[0].mode = 'delegated-agent';
          return response(body);
        },
        now: Date.parse('2026-08-21T12:01:00.000Z'),
      },
    ),
    /does not satisfy the collector gate/,
  );
});

test('wake packet rejects expired holder proof', async () => {
  await assert.rejects(
    createWakePacket(
      { tokenId: 1003, task: 'Perform one bounded public research task.' },
      { fetchImpl: toolFetch, now: Date.parse('2026-08-21T12:31:00.000Z') },
    ),
    /expired or outside/,
  );
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

test('wake packet rejects a substituted MCP endpoint for holder verification', async () => {
  await assert.rejects(
    createWakePacket(
      { tokenId: 1003, task: 'Perform one bounded public research task.' },
      {
        endpoint: 'https://attacker.example/mcp',
        fetchImpl: toolFetch,
        now: Date.parse('2026-08-21T12:01:00.000Z'),
      },
    ),
    /canonical NFH MCP endpoint/,
  );
});

test('wake packet fails closed when canonical ownership changes during creation', async () => {
  let identityReads = 0;
  await assert.rejects(
    createWakePacket(
      { tokenId: 1003, task: 'Perform one bounded public research task.' },
      {
        fetchImpl: async (endpoint, init) => {
          if (init.method === 'GET') return toolFetch(endpoint, init);
          const request = JSON.parse(init.body);
          if (request.params.name !== 'get_agent_pfp') return toolFetch(endpoint, init);
          identityReads += 1;
          const owner = identityReads === 1
            ? '0x1111111111111111111111111111111111111111'
            : '0x2222222222222222222222222222222222222222';
          return response({
            result: {
              structuredContent: {
                tokenId: 1003,
                pfpUrl: 'https://notforhumans.fun/pfp/1003',
                claimed: true,
                claimVerified: true,
                owner,
                seedFinalized: true,
                seedHash: `0x${'12'.repeat(32)}`,
              },
            },
          });
        },
        now: Date.parse('2026-08-21T12:01:00.000Z'),
      },
    ),
    /owner changed/,
  );
});

test('receipt hashes result bytes and stays explicitly unverified', async () => {
  const packet = {
    schema: WAKE_PACKET_SCHEMA,
    tokenId: 1003,
    task: 'Map three useful MCP integration paths.',
    holderGate: {
      status: 'HOLDER_VERIFIED_AT_WAKE',
      tokenId: 1003,
      owner: '0x1111111111111111111111111111111111111111',
      method: 'owner-signed-agent-presence-plus-live-ownerOf',
      signatureVerified: true,
      ownershipVerifiedAt: '2026-08-21T12:00:00.000Z',
      expiresAt: '2026-08-21T12:30:00.000Z',
      sourceUrl: 'https://mcp.notforhumans.fun/agent-presence/1003',
    },
  };
  const receipt = await createReceipt({
    packet,
    resultBytes: Buffer.from('one result\n'),
    resultPath: '/tmp/result.md',
    summary: 'Mapped three integrations with source links.',
    sources: ['https://modelcontextprotocol.io/'],
  }, {
    createdAt: '2026-08-21T12:05:00.000Z',
    now: Date.parse('2026-08-21T12:05:00.000Z'),
    fetchImpl: toolFetch,
  });

  assert.equal(receipt.status, 'SELF_REPORTED_UNVERIFIED');
  assert.equal(receipt.acceptedWork, false);
  assert.equal(receipt.authority.signed, false);
  assert.equal(receipt.holderGate.status, 'HOLDER_VERIFIED_AT_WAKE');
  assert.equal(receipt.artifact.sha256, '4ee64bb7837ca25a1a0c3613483eda1fe97f4f0cf7f61b6d2d7974c57257c67e');
});

test('receipt rejects a self-authored holder gate without fresh canonical proof', async () => {
  const packet = {
    schema: WAKE_PACKET_SCHEMA,
    tokenId: 1003,
    task: 'Map three useful MCP integration paths.',
    holderGate: {
      status: 'HOLDER_VERIFIED_AT_WAKE',
      tokenId: 1003,
      owner: '0x2222222222222222222222222222222222222222',
      method: 'owner-signed-agent-presence-plus-live-ownerOf',
      signatureVerified: true,
      ownershipVerifiedAt: '2026-08-21T12:00:00.000Z',
      expiresAt: '2026-08-21T12:30:00.000Z',
      sourceUrl: 'https://mcp.notforhumans.fun/agent-presence/1003',
    },
  };

  await assert.rejects(
    createReceipt({
      packet,
      resultBytes: Buffer.from('forged result\n'),
      resultPath: '/tmp/result.md',
      summary: 'Claimed work without a real holder signature.',
      sources: [],
    }, {
      now: Date.parse('2026-08-21T12:05:00.000Z'),
      fetchImpl: toolFetch,
    }),
    /current NFH owner/,
  );
});

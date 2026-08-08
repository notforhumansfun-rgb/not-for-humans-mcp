import assert from 'node:assert/strict';
import test from 'node:test';
import { createClient } from '../src/client.js';

function mockFetch(handler) {
  return async (url, init) => {
    const body = JSON.parse(init.body);
    const result = handler({ url, init, body });
    return new Response(JSON.stringify({ jsonrpc: '2.0', id: body.id, result }), {
      status: 200,
      headers: { 'content-type': 'application/json' },
    });
  };
}

test('client rejects non-HTTPS remote endpoints', () => {
  assert.throws(() => createClient({ endpoint: 'http://example.com/mcp' }), /must use HTTPS/);
});

test('client allows local HTTP development', () => {
  assert.equal(createClient({ endpoint: 'http://127.0.0.1:8787/mcp' }).endpoint, 'http://127.0.0.1:8787/mcp');
});

test('provider credentials are rejected for noncanonical and local endpoints', () => {
  assert.throws(
    () => createClient({ endpoint: 'https://attacker.example/mcp', openSeaApiKey: 'test-only-key' }),
    /canonical NFH MCP endpoint/,
  );
  assert.throws(
    () => createClient({ endpoint: 'http://127.0.0.1:8787/mcp', openSeaApiKey: 'test-only-key' }),
    /canonical NFH MCP endpoint/,
  );
});

test('search uses tools/call and preserves exact arguments', async () => {
  const client = createClient({
    fetchImpl: mockFetch(({ body, init }) => {
      assert.equal(body.method, 'tools/call');
      assert.deepEqual(body.params, { name: 'search', arguments: { query: 'trait offers' } });
      assert.equal(init.headers['mcp-protocol-version'], '2025-06-18');
      assert.equal(init.headers['x-opensea-api-key'], undefined);
      return { structuredContent: { results: [] } };
    }),
  });
  const result = await client.search('trait offers');
  assert.deepEqual(result.structuredContent.results, []);
});

test('provider key is sent only when explicitly supplied', async () => {
  const client = createClient({
    openSeaApiKey: 'test-only-key',
    fetchImpl: mockFetch(({ init }) => {
      assert.equal(init.headers['x-opensea-api-key'], 'test-only-key');
      assert.equal(init.redirect, 'error');
      return { structuredContent: { tradingPreparationEnabled: false } };
    }),
  });
  await client.marketStatus();
});

test('resource convenience methods use the MCP resource protocol', async () => {
  const calls = [];
  const client = createClient({
    fetchImpl: mockFetch(({ body }) => {
      calls.push({ method: body.method, params: body.params });
      return body.method === 'resources/list'
        ? { resources: [{ uri: 'nfh://about' }] }
        : { contents: [{ uri: body.params.uri, text: 'NFH' }] };
    }),
  });
  const listed = await client.listResources();
  const read = await client.readResource('nfh://about');
  assert.equal(listed.resources[0].uri, 'nfh://about');
  assert.equal(read.contents[0].text, 'NFH');
  assert.deepEqual(calls, [
    { method: 'resources/list', params: {} },
    { method: 'resources/read', params: { uri: 'nfh://about' } },
  ]);
});

test('census and TokenWorks convenience methods call the bounded tools', async () => {
  const calls = [];
  const client = createClient({
    fetchImpl: mockFetch(({ body }) => {
      calls.push(body.params);
      return { structuredContent: { ok: true } };
    }),
  });
  await client.censusStatus();
  await client.prepareCensusReceipt({ decision: 'refuse' });
  await client.tokenworksStatus();
  await client.prepareTokenworksDecision({ decision: 'inspect' });
  assert.deepEqual(calls, [
    { name: 'get_census_status', arguments: {} },
    { name: 'prepare_census_receipt', arguments: { decision: 'refuse' } },
    { name: 'get_tokenworks_status', arguments: {} },
    { name: 'prepare_tokenworks_decision', arguments: { decision: 'inspect' } },
  ]);
});

test('MCP errors become client errors', async () => {
  const client = createClient({
    fetchImpl: async () => new Response(JSON.stringify({ jsonrpc: '2.0', id: 1, error: { code: -32601, message: 'No such method' } }), { status: 200 }),
  });
  await assert.rejects(() => client.request('missing'), /No such method/);
});

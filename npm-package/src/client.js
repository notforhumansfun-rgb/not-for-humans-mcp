const DEFAULT_ENDPOINT = 'https://mcp.notforhumans.fun/mcp';
const SUPPORTED_PROTOCOL = '2025-06-18';

function normalizeEndpoint(endpoint) {
  const url = new URL(endpoint || DEFAULT_ENDPOINT);
  if (url.protocol !== 'https:' && !['localhost', '127.0.0.1'].includes(url.hostname)) {
    throw new Error('The MCP endpoint must use HTTPS unless it is local development.');
  }
  return url.toString();
}

export class NotForHumansMcpClient {
  #endpoint;
  #fetch;
  #nextId = 1;
  #openSeaApiKey;

  constructor({ endpoint = DEFAULT_ENDPOINT, fetchImpl = fetch, openSeaApiKey = '' } = {}) {
    this.#endpoint = normalizeEndpoint(endpoint);
    this.#fetch = fetchImpl;
    this.#openSeaApiKey = openSeaApiKey;
  }

  get endpoint() {
    return this.#endpoint;
  }

  async request(method, params = {}) {
    const id = this.#nextId++;
    const headers = {
      accept: 'application/json, text/event-stream',
      'content-type': 'application/json',
      'mcp-protocol-version': SUPPORTED_PROTOCOL,
      'user-agent': '@notforhumans/mcp/0.1',
    };
    if (this.#openSeaApiKey) headers['x-opensea-api-key'] = this.#openSeaApiKey;

    const response = await this.#fetch(this.#endpoint, {
      method: 'POST',
      headers,
      body: JSON.stringify({ jsonrpc: '2.0', id, method, params }),
      signal: AbortSignal.timeout(15_000),
    });
    if (!response.ok) throw new Error(`MCP returned HTTP ${response.status}`);
    const payload = await response.json();
    if (payload.error) throw new Error(payload.error.message || 'MCP request failed');
    return payload.result;
  }

  initialize() {
    return this.request('initialize', {
      protocolVersion: SUPPORTED_PROTOCOL,
      capabilities: {},
      clientInfo: { name: '@notforhumans/mcp', version: '0.1.0-preview.1' },
    });
  }

  listTools() {
    return this.request('tools/list');
  }

  listResources() {
    return this.request('resources/list');
  }

  readResource(uri) {
    return this.request('resources/read', { uri });
  }

  callTool(name, args = {}) {
    return this.request('tools/call', { name, arguments: args });
  }

  search(query) {
    return this.callTool('search', { query });
  }

  fetchDocument(id) {
    return this.callTool('fetch', { id });
  }

  marketStatus() {
    return this.callTool('get_market_status', {});
  }

  censusStatus() {
    return this.callTool('get_census_status', {});
  }

  prepareCensusReceipt(receipt) {
    return this.callTool('prepare_census_receipt', receipt);
  }

  tokenworksStatus() {
    return this.callTool('get_tokenworks_status', {});
  }

  prepareTokenworksDecision(decision) {
    return this.callTool('prepare_tokenworks_decision', decision);
  }
}

export function createClient(options) {
  return new NotForHumansMcpClient(options);
}

export { DEFAULT_ENDPOINT };

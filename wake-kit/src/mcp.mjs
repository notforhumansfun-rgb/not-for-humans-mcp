export const DEFAULT_MCP_ENDPOINT = 'https://mcp.notforhumans.fun/mcp';

function validateEndpoint(endpoint) {
  let parsed;
  try {
    parsed = new URL(endpoint);
  } catch {
    throw new Error('MCP endpoint must be a valid URL.');
  }

  const local = parsed.hostname === 'localhost' || parsed.hostname === '127.0.0.1';
  if (parsed.protocol !== 'https:' && !(local && parsed.protocol === 'http:')) {
    throw new Error('MCP endpoint must use HTTPS (HTTP is allowed only for localhost).');
  }
  return parsed.toString();
}

function textError(result) {
  const block = Array.isArray(result?.content)
    ? result.content.find((item) => item?.type === 'text' && typeof item.text === 'string')
    : null;
  return block?.text || 'The MCP tool returned an error.';
}

export async function callMcpTool(name, args, options = {}) {
  const endpoint = validateEndpoint(options.endpoint || DEFAULT_MCP_ENDPOINT);
  const fetchImpl = options.fetchImpl || globalThis.fetch;
  if (typeof fetchImpl !== 'function') throw new Error('A Fetch-compatible function is required.');

  const response = await fetchImpl(endpoint, {
    method: 'POST',
    headers: {
      Accept: 'application/json, text/event-stream',
      'Content-Type': 'application/json',
      'User-Agent': 'NFH-Wake-Kit/0.1',
    },
    body: JSON.stringify({
      jsonrpc: '2.0',
      id: 1,
      method: 'tools/call',
      params: { name, arguments: args },
    }),
  });

  if (!response.ok) throw new Error(`MCP request failed with HTTP ${response.status}.`);
  const body = await response.json();
  if (body?.error) throw new Error(`MCP error: ${body.error.message || 'unknown error'}`);

  const result = body?.result;
  if (result?.isError) throw new Error(textError(result));
  if (result?.structuredContent && typeof result.structuredContent === 'object') {
    return result.structuredContent;
  }

  const block = Array.isArray(result?.content)
    ? result.content.find((item) => item?.type === 'text' && typeof item.text === 'string')
    : null;
  if (!block) throw new Error('MCP response did not contain structured content.');
  try {
    const parsed = JSON.parse(block.text);
    if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) throw new Error();
    return parsed;
  } catch {
    throw new Error('MCP response text was not a JSON object.');
  }
}

export { validateEndpoint };

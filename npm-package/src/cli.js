#!/usr/bin/env node
import { createClient, DEFAULT_ENDPOINT } from './client.js';

const [command = 'help', ...args] = process.argv.slice(2);
const endpoint = process.env.NFH_MCP_ENDPOINT || DEFAULT_ENDPOINT;
const client = createClient({ endpoint, openSeaApiKey: process.env.NFH_OPENSEA_API_KEY || '' });

const print = (value) => process.stdout.write(`${JSON.stringify(value, null, 2)}\n`);
const fail = (message) => {
  process.stderr.write(`${message}\n`);
  process.exitCode = 1;
};

function help() {
  process.stdout.write(`NOT FOR HUMANS MCP client\n\nUsage:\n  nfh-mcp status\n  nfh-mcp census\n  nfh-mcp tokenworks\n  nfh-mcp tools\n  nfh-mcp resources\n  nfh-mcp resource <nfh://uri>\n  nfh-mcp search <query>\n  nfh-mcp fetch <document-id>\n  nfh-mcp call <tool-name> '<json-arguments>'\n  nfh-mcp config [json|toml]\n\nThis client holds no wallet key, never signs, and never submits. The external agent wallet submits exact V19 Sepolia claims directly.\n`);
}

try {
  if (command === 'help' || command === '--help' || command === '-h') {
    help();
  } else if (command === 'status') {
    const [census, market, tokenworks] = await Promise.all([
      client.censusStatus(),
      client.marketStatus(),
      client.tokenworksStatus(),
    ]);
    print({ census, market, tokenworks });
  } else if (command === 'census') {
    print(await client.censusStatus());
  } else if (command === 'tokenworks') {
    print(await client.tokenworksStatus());
  } else if (command === 'tools') {
    print(await client.listTools());
  } else if (command === 'resources') {
    print(await client.listResources());
  } else if (command === 'resource') {
    if (!args[0]) throw new Error('resource requires an nfh:// URI');
    print(await client.readResource(args[0]));
  } else if (command === 'search') {
    if (!args.length) throw new Error('search requires a query');
    print(await client.search(args.join(' ')));
  } else if (command === 'fetch') {
    if (!args[0]) throw new Error('fetch requires a document id');
    print(await client.fetchDocument(args[0]));
  } else if (command === 'call') {
    if (!args[0]) throw new Error('call requires a tool name');
    const input = args[1] ? JSON.parse(args[1]) : {};
    print(await client.callTool(args[0], input));
  } else if (command === 'config') {
    const format = args[0] || 'json';
    if (format === 'toml') {
      process.stdout.write(`[mcp_servers.notforhumans]\nurl = "${endpoint}"\n`);
    } else if (format === 'json') {
      print({ mcpServers: { notforhumans: { type: 'streamable-http', url: endpoint } } });
    } else {
      throw new Error('config format must be json or toml');
    }
  } else {
    throw new Error(`Unknown command: ${command}`);
  }
} catch (error) {
  fail(error instanceof Error ? error.message : 'Unexpected error');
}

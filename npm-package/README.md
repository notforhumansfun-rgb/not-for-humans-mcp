# @notforhumans/mcp

A tiny, dependency-free client and CLI for `https://mcp.notforhumans.fun/mcp`, the canonical NOT FOR HUMANS Streamable HTTP MCP endpoint.

Package version `0.1.0-preview.2` is separate from the hosted MCP protocol/corpus version `0.8.1`. This preview package version tracks client API compatibility and the security-hardened transport release, not activation of transaction-capable market preparation.

```sh
npx @notforhumans/mcp status
npx @notforhumans/mcp census
npx @notforhumans/mcp tokenworks
npx @notforhumans/mcp search "trait specific offers"
npx @notforhumans/mcp fetch trait-map
npx @notforhumans/mcp tools
```

Or connect an MCP-capable host directly to `https://mcp.notforhumans.fun/mcp`; installing this package is optional.

```js
import { createClient } from '@notforhumans/mcp';

const nfh = createClient();
const census = await nfh.censusStatus();
const market = await nfh.marketStatus();
const tokenworks = await nfh.tokenworksStatus();
const result = await nfh.search('Punk sponsored opening');
```

`prepareCensusReceipt(receipt)` asks the MCP for unsigned v5.2 EIP-712 v4 typed data for `ACCEPT`, `REFUSE`, or `INSUFFICIENT_AUTHORITY`, including explicit recipient consent whenever recipient differs from operator. `listResources()` and `readResource(uri)` expose the stable `nfh://` discovery resources. `prepareTokenworksDecision(decision)` can create a bounded inspection or refusal record but cannot prepare a direct FWA action while the royalty gate is closed.

Market preparation tools are discoverable but fail closed even when a canonical collection is configured. Read-only market discovery may require a caller-supplied OpenSea API key. If needed, provide `openSeaApiKey` in library code or `NFH_OPENSEA_API_KEY` to the CLI. Never commit that credential. Transaction/order preparation remains blocked until complete semantic decoding and adversarial-provider gates pass.

The package never connects a wallet, holds a private key, signs a receipt or order, posts an order, submits calldata, or broadcasts a transaction. It returns read-only MCP discovery results and explicit fail-closed preparation errors until the semantic validator gate passes.

## Release gate

This directory is the audit-facing source for the `preview` npm tag. A release must satisfy all of these gates:

- the GitHub source repository is public and matches the packed contents;
- npm trusted publishing is bound to `notforhumansfun-rgb/not-for-humans-mcp` and its pinned publishing workflow;
- the package tarball and provenance are reviewed;
- the website's install examples match the released version.

Preview releases must never use the npm `latest` tag. Publishing with GitHub OIDC produces provenance linking the package back to the exact public source commit.

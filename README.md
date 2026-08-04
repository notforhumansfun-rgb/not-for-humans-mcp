# NOT FOR HUMANS — MCP Server

Public project knowledge, Agent Census receipts, and non-custodial NFH market-action preparation.

The unified human/agent journey, including exact trait-specific trading and activation boundaries, is documented in [CLAIM-AND-TRADE-JOURNEY.md](CLAIM-AND-TRADE-JOURNEY.md).

## Connection

- Production endpoint: `https://mcp.notforhumans.fun/mcp`
- Transport: stateless Streamable HTTP
- Knowledge authentication: none
- Knowledge tools: `search`, `fetch`
- Resources: `nfh://about`, `nfh://claim-spec`, `nfh://origin-stream`, `nfh://renderer-spec`, `nfh://release-policy`
- Census tools: `get_census_status`, `get_origin_stream`, `prepare_census_receipt`, `get_agent_pfp`
- TokenWorks boundary tools: `get_tokenworks_status`, `prepare_tokenworks_decision`
- Market tools: `get_market_feed`, `get_market_status`, `prepare_listing`, `prepare_purchase`, `list_trait_offers`, `prepare_trait_offer`, `prepare_accept_offer`, `prepare_transfer`
- Market provider credential: caller-supplied `X-OpenSea-Api-Key` header for read-only provider discovery
- On-chain mutations by the MCP: none

The connector intentionally indexes only twelve files that are already public on `notforhumans.fun`, including the canonical release policy, Agent Census schema, chain-indexed Origin Stream, TokenWorks compatibility boundary, FAQ, and preview trait ontology. It does not expose credentials or private keys.

`prepare_census_receipt` produces unsigned v5.2 EIP-712 v4 data for `ACCEPT`, `REFUSE`, or `INSUFFICIENT_AUTHORITY`. An acceptance can lead to one credentialed claim; the other decisions create a public receipt without minting. Operator and agent sign every exact payload. A distinct recipient must also sign so nobody can force-mint into another wallet or consume its lifetime quota. The domain remains unbound until `NFH_CENSUS_CONTRACT` is configured with the verified canonical claim contract. The MCP never signs or submits the receipt. `get_origin_stream` reads only published chain receipts and preserves observed, confirmed, and finalized as different states.

`get_agent_pfp` returns the public portrait URL for any NFH token. Each token is an ERC-721; the generative character is its on-chain artwork rendered from an entropy seed. The portrait at `https://notforhumans.fun/pfp/{tokenId}` renders the same artwork client-side using the same seed.

Transaction-capable market tools are installed but fail closed before any provider action/build/fulfillment call. OpenSea currently documents listing and transfer steps as opaque JSON nodes, criteria builds are partial order fragments, and fulfillment responses do not independently prove the requested order hash and every normalized economic term. Provider HTTP success therefore cannot produce `status: prepared`. Read-only market and trait discovery may still use configured provider endpoints. The MCP never signs, posts an order, submits calldata, broadcasts a transaction, or claims settlement.

`get_market_feed` is the read-only discovery exception: it mirrors the public NFH aggregate feed rather than preparing an action.

`list_trait_offers` supports one exact categorical trait or up to eight AND-combined traits. `prepare_trait_offer` and `prepare_accept_offer` remain blocked: neither a partial criteria response nor provider-side trait matching proves the final Seaport order, selected token, fees, consideration, conduit, zone, validity, and EIP-712 digest are equivalent to normalized intent.

TokenWorks/FWA is deliberately agent-layer compatible but transaction-disabled. `get_tokenworks_status` exposes the admission, fork-test, security, and royalty requirements. `prepare_tokenworks_decision` can produce a bounded inspection or refusal record, but rejects direct deposit, withdrawal, purchase, relist, and settlement preparation while FWA has no confirmed royalty-aware NFH settlement route.

## Activation state

The tools are installed, but NFH trading remains inactive while the project is in pre-mainnet preview. `server/market.json` intentionally contains no collection address. Even a configured `NFH_COLLECTION_CONTRACT` cannot activate preparation: `semanticValidationEnabled` is hard-false until complete decoding/equivalence validators, authentication and abuse controls, and adversarial-provider tests are implemented and independently reviewed.

Optional production configuration:

- `NFH_COLLECTION_CONTRACT` — verified canonical NFHToken address; required for collection-bound discovery but not sufficient to activate preparation
- `NFH_CENSUS_CONTRACT` — verified canonical v5.2 NFHClaimMinter address; required to bind Census typed data
- `NFH_COLLECTION_SLUG` — OpenSea collection slug after indexing; required for trait-offer discovery
- `NFH_SEAPORT_PROTOCOL_ADDRESS` — optional override for the pinned Seaport protocol address
- `NFH_MARKET_FEED_URL` — optional HTTPS override for the public aggregate feed mirrored by `get_market_feed`

The server refuses arbitrary contract addresses supplied in tool arguments. Every market action is pinned to the configured canonical NFH contract.

## Local development

```zsh
php tests/run.php
php -S 127.0.0.1:8787 -t server server/index.php
```

The local MCP endpoint is `http://127.0.0.1:8787/mcp`.

## Example request

```zsh
curl -sS https://mcp.notforhumans.fun/mcp \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json, text/event-stream' \
  --data '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}'
```

## ERC-8257 manifests

The `erc-8257/` package prepares focused Agent Tool Registry manifests for each MCP tool and computes their required JCS/keccak256 commitments. It refuses to publish without the exact nonzero production creator address. The production 2-of-3 Safe does not exist yet, so no well-known manifests have been emitted and nothing has been registered onchain.

```zsh
cd erc-8257
npm install
npm test
```

## Agent Skill

The installable protocol-neutral Agent Skill is at `skills/nfh-protocol/SKILL.md` and is published at `https://notforhumans.fun/skills/nfh-protocol/SKILL.md`.

## ChatGPT connection

Enable Developer mode in ChatGPT, create a personal plugin/app connection, and use `https://mcp.notforhumans.fun/mcp` as the MCP server URL.

Connected clients may prepare exact wallet payloads but cannot bypass the explicit signature boundary. Market and Census tool descriptions and results make the wallet handoff explicit.

## Smart contracts

The verified Solidity source is at [notforhumansfun-rgb/not-for-humans-contracts](https://github.com/notforhumansfun-rgb/not-for-humans-contracts).

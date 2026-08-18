# NOT FOR HUMANS MCP

Public project knowledge, Agent Census receipts, exact unsigned V19 claim preparation, and non-custodial NFH market-action preparation.

The unified human/agent journey, including exact trait-specific trading and activation boundaries, is documented in [CLAIM-AND-TRADE-JOURNEY.md](CLAIM-AND-TRADE-JOURNEY.md).

## Connection

- Production endpoint: `https://mcp.notforhumans.fun/mcp`
- Transport: stateless Streamable HTTP
- Knowledge authentication: none
- Knowledge tools: `search`, `fetch`
- Resources: `nfh://about`, `nfh://claim-spec`, `nfh://origin-stream`, `nfh://renderer-spec`, `nfh://release-policy`
- Census tools: `get_census_status`, `get_origin_stream`, `prepare_census_receipt`, `claim_as_agent`
- TokenWorks boundary tools: `get_tokenworks_status`, `prepare_tokenworks_decision`
- Market tools: `get_market_feed`, `get_market_status`, `prepare_listing`, `prepare_purchase`, `list_trait_offers`, `prepare_trait_offer`, `prepare_accept_offer`, `prepare_transfer`
- Market provider credential: caller-supplied `X-OpenSea-Api-Key` header for read-only provider discovery
- On-chain mutations by the MCP: none; the external agent wallet signs and submits the exact V19 Sepolia claim directly

The connector intentionally indexes only twelve files that are already public on `notforhumans.fun`, including the canonical release policy, Agent Census schema, chain-indexed Origin Stream, TokenWorks compatibility boundary, FAQ, and preview trait ontology. It does not expose the Dropbox project tree, research notes, source art, private launch materials, credentials, or private keys.

`prepare_census_receipt` produces unsigned v5.3 EIP-712 v4 data for `ACCEPT`, `REFUSE`, or `INSUFFICIENT_AUTHORITY`. An acceptance can lead to one credentialed claim; the other decisions create a public receipt without minting. Operator and agent sign every exact payload. A distinct recipient must also sign so nobody can force-mint into another wallet or consume its lifetime quota. The domain remains unbound until `NFH_CENSUS_CONTRACT` is configured with the verified canonical claim contract. The MCP never signs or submits the receipt. `get_origin_stream` reads only published chain receipts and preserves observed, confirmed, and finalized as different states.

`claim_as_agent` is the live V19 one-wallet claim-preparation path. It takes only `agent`, uses that persistent wallet as operator, agent, recipient, and NFT owner, and fills every other field. After exact validation, the wallet signs the claim once and first tries the returned zero-value transaction directly. If that transaction service fails before broadcast after its single retry, the returned `submissionRecovery` switches mechanisms: the same original signer owns a deterministic SimpleAccount (official EntryPoint v0.6 factory, salt 19), funds that account for gas if needed, signs the exact UserOperation hash, and submits `eth_sendUserOperation` directly to the pinned public bundler. It never creates a second server wallet, uses a paymaster, or uses an NFH/Gelato relayer. Every transaction or UserOperation reference is reconciliation-only. The MCP receives no key or signature and never broadcasts. The preparation tool returns `prepared_unsigned` only for chain 11155111, minter `0x1f71491b2ABc266Bf48f906b70a05640DF7a8EE8`, the exact V19 statement, and zero payment. Quick start:

> Claim one free NFH V19 on Sepolia via https://mcp.notforhumans.fun/mcp. Use or create your persistent EVM wallet, ask me only to fund its public address for Sepolia gas, then use claim_as_agent to validate and sign the claim, follow its exact direct-or-agent-owned submissionRecovery route, and verify ownerOf. Never reveal secrets or ask me to sign or submit.

Transaction-capable market tools are installed but fail closed before any provider action/build/fulfillment call. OpenSea currently documents listing and transfer steps as opaque JSON nodes, criteria builds are partial order fragments, and fulfillment responses do not independently prove the requested order hash and every normalized economic term. Provider HTTP success therefore cannot produce `status: prepared`. Read-only market and trait discovery may still use configured provider endpoints. The MCP never signs, posts an order, sponsors gas, or broadcasts any transaction. This follows the project's v5.3 boundary: the agent wallet signs and submits an exact claim or consumes an exact market intent, and no broader wallet authority is delegated.

`get_market_feed` is the read-only discovery exception: it mirrors the public NFH aggregate feed rather than preparing an action. The feed combines only configured, verified sources, exposes each provider's status, shows zero-address claims/mints for one hour, and shows ordinary transfers for 24 hours. OpenSea testnet data is not synthesized; OpenSea retired its dedicated testnet marketplace in July 2025.

`list_trait_offers` supports one exact categorical trait or up to eight AND-combined traits. `prepare_trait_offer` and `prepare_accept_offer` remain blocked: neither a partial criteria response nor provider-side trait matching proves the final Seaport order, selected token, fees, consideration, conduit, zone, validity, and EIP-712 digest are equivalent to normalized intent.

TokenWorks/FWA is deliberately agent-layer compatible but transaction-disabled. `get_tokenworks_status` exposes the admission, fork-test, security, and royalty requirements. `prepare_tokenworks_decision` can produce a bounded inspection or refusal record, but rejects direct deposit, withdrawal, purchase, relist, and settlement preparation while FWA has no confirmed royalty-aware NFH settlement route. A wrapper workaround is not supported.

## Activation state

V19 is deployed on Ethereum mainnet in a paused state. The canonical token is `0xD66351858E0eFC5d9Bf2F541839797A763DF6223`, claim minter `0x5652CEA58298445240Eb9AC8Fc4C69bA829c1bb5`, and marketplace `0x9eAa937443595f14E739C7bf565420019169Be13`. Claims and marketplace actions remain paused; the MCP remains non-custodial and transaction-capable market preparation still fails closed pending semantic validation and independent review.

Optional production configuration:

- `NFH_COLLECTION_CONTRACT` — verified canonical NFHToken address; required for collection-bound discovery but not sufficient to activate preparation
- `NFH_CENSUS_CONTRACT` — verified canonical v5.3 NFHClaimMinter address; required to bind Census typed data
- `NFH_SEPOLIA_NEXT_CLAIM_CONTRACT` — optional deployment override; if set, it must exactly equal the published V19 Sepolia minter or the server fails closed
- `NFH_COLLECTION_SLUG` — OpenSea collection slug after indexing; required for trait-offer discovery
- `NFH_SEAPORT_PROTOCOL_ADDRESS` — optional override for the pinned Seaport protocol address
- `NFH_MARKET_FEED_URL` — optional HTTPS override for the public aggregate feed mirrored by `get_market_feed`

The server refuses arbitrary contract addresses supplied in tool arguments. Every market action is pinned to the configured canonical NFH contract.

The installable protocol-neutral Agent Skill is at `skills/nfh-protocol/SKILL.md` and is published at `https://notforhumans.fun/skills/nfh-protocol/SKILL.md`. The complete public corpus is generated with `node build-llms-full.cjs` and published as `llms-full.txt`.

The optional MetaMask Agent Wallet reference adapter lives at `integrations/metamask-agent-wallet/`. It validates exact NFH EIP-712 payloads, derives and verifies the original signer's deterministic V19 SimpleAccount, prepares the exact self-funded UserOperation, and can submit only that separately agent-signed packet directly to the bundler. It never holds a key or signs, and it does not make MetaMask, Pimlico, or any relayer a contract dependency.

First-time agents can call `get_agent_wallet_onboarding` before claiming. The v19 route uses one persistent agent-operated wallet as operator, signer, transaction sender, recipient, and owner, then gates market preparation on a confirmed receipt and fresh ownership check. The MCP never creates wallets, receives private keys or signatures, applies wallet policy, sponsors gas, signs, or submits; negotiation and all preparation remain non-executing.

The local `erc-8257/` package defines eight focused manifests that map directly to existing MCP tools and computes their required JCS/keccak256 commitments. It deliberately refuses to publish without the exact nonzero production creator address. The production 2-of-3 Safe does not exist yet, so no well-known manifests have been emitted and nothing has been registered onchain.

The public MCP protocol/corpus release is `0.9.3-preview`. The separately installable npm client is `0.1.0-preview.2`; its package semver tracks client-library compatibility and security hardening and does not imply that external-provider market transaction preparation is enabled.

## Discovery and compatibility

The launch-safe Official MCP Registry metadata is prepared in `server.json` under the DNS-authenticated name `fun.notforhumans/mcp`. It advertises only the public remote Streamable HTTP endpoint. The separately published npm package is a client, not a runnable MCP server, so it is deliberately not listed as a registry package transport.

Registry publication remains separately gated. Preparing or validating `server.json` does not authorize publication, deployment, signing, submission, or mainnet activation. Before publication, confirm that the exact live endpoint, server version, repository identity, safety metadata, and served discovery bytes agree, then record explicit publication approval.

Implemented MCP protocol revisions and deferred candidate behavior are recorded in `compatibility-matrix.json`. NFH currently negotiates `2025-11-25`, `2025-06-18`, and `2025-03-26`. The `2026-07-28` revision remains tracked but unimplemented and unadvertised until it is final, reviewed, and covered by backwards-compatible conformance tests.

Validate the local metadata without publishing:

```zsh
cd 06-MCP
node --test --test-reporter=spec tests/registry-metadata.test.cjs
mcp-publisher validate
```

The interoperability release order is discovery, connection, independent reproduction, composition, and only then transaction support. A protocol or registry badge must never precede a live, independently reproducible capability.

## Local development

```zsh
zsh sync-public-corpus.zsh
php tests/run.php
NFH_MCP_BASE_URL=http://127.0.0.1:8787 php -S 127.0.0.1:8787 -t server server/index.php
```

The local MCP endpoint is `http://127.0.0.1:8787/mcp`.

## Example request

```zsh
curl -sS http://127.0.0.1:8787/mcp \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json, text/event-stream' \
  --data '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}'
```

## Refresh and deploy

The sync script copies only the explicit allowlist from `03-PRODUCTION/site`. The deployment script repeats the sync, runs anonymity and credential-shape preflights, and uploads the server without deleting unrelated remote files.

```zsh
zsh deploy-dreamhost.zsh
```

The DreamHost panel must map `mcp.notforhumans.fun` to `/home/notforhumans/mcp.notforhumans.fun` and enable HTTPS.

## ChatGPT connection

Enable Developer mode in ChatGPT, create a personal plugin/app connection, and use `https://mcp.notforhumans.fun/mcp` as the MCP server URL. Refresh the connection after changing tool metadata.

Connected clients may prepare exact wallet payloads but cannot bypass the explicit signature boundary. Market and Census tool descriptions and results therefore make the wallet handoff explicit.

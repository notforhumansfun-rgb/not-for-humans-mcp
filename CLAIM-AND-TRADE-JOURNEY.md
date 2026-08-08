# Claim and trade journey

## Funded-agent first contact

The additive agent-first route uses the existing v5.2 signer model; it does not weaken or replace the human-steward route.

1. An already-funded execution wallet becomes `operator` and pays Sepolia gas.
2. Create or reuse a distinct persistent policy-controlled Guard wallet. It becomes both `agent` and `recipient`.
3. Call `get_agent_wallet_onboarding`, then verify the pinned minter, token, marketplace, WETH, required role separation, and external wallet-policy boundary.
4. Call `get_census_status` and `prepare_public_claim` for allocation `0`, exact maximum payment `0`, and recipient equal to the Guard wallet.
5. Collect the required signatures through external wallet surfaces and submit from the funded operator wallet.
6. Confirm the receipt and `ownerOf(tokenId) == agent` before any market action.
7. Call `get_internal_marketplace_status`, then the exact `prepare_internal_*` tool needed.
8. Negotiation and preparation may continue autonomously. Signing and submission continue only within explicit external wallet and host policy. A new counterparty or broader budget requires a new approval boundary.

MetaMask Agent Wallet is the reference adapter, not a protocol dependency or endorsement. The NFH MCP never creates a wallet, receives credentials, applies policy, signs, or submits.

Status: implementation guide. Transaction-capable MCP tools remain disabled until the activation gates below pass.

## Product promise

A human steward should be able to give one exact task to an agent, understand one bounded result, and approve one final wallet payload. An agent should be able to discover state, prepare the same action through a supported venue, explain every economic term, and stop before signing or broadcasting.

The website and MCP do not receive private keys, seed phrases, wallet sessions, signatures, or unrestricted spending authority.

## Claim journey

1. Human or agent calls `get_census_status` and confirms chain, contracts, runtime hashes, eligibility, price, quota, nonce, deadline, statement, operator, agent, and recipient.
2. Agent chooses exactly one result: `ACCEPT`, `REFUSE`, or `INSUFFICIENT_AUTHORITY`.
3. `prepare_census_receipt` returns the complete typed-data payload and plain-language explanation.
4. Required authorizers review and sign the exact payload. ERC-1271 agents are validated against the current state.
5. Immediately before submission, recheck eligibility, nonce, expiry, quota, chain, recipient, and contract state.
6. Only after final authorization is collected may the operator freeze the matching eligibility root and submit the claim.
7. If any state changed, retain the prepared intent, explain the mismatch, and refresh only the stale fields instead of forcing the human to restart.

## Human trading journey

After mainnet deployment and marketplace indexing, the NFH site is the collection and trait discovery surface. A human can open the exact token or collection on:

- OpenSea
- Verse
- Raster

The marketplace owns its wallet connection, signatures, order submission, and transaction broadcast. NFH links must bind the canonical chain, collection, and token rather than relying on a provider search result.

## Agent trading journey

Agents may use `https://mcp.notforhumans.fun/mcp` or another venue adapter. Every adapter follows the same sequence:

1. Normalize the requested action and all material terms.
2. Fetch provider/order data as untrusted input.
3. Decode target, selector, calldata, typed data, approvals, and consideration.
4. Prove semantic equivalence to the normalized intent.
5. Return an unsigned payload, exact digest, expiry, simulation result, and human-readable economic summary.
6. Require the configured wallet policy and explicit approval.
7. Sign and broadcast outside the NFH MCP.

A provider HTTP success is never evidence that an action is safe or correctly prepared.

## Supported intent shapes

| Intent | Human route | MCP preparation tool | Required binding |
| --- | --- | --- | --- |
| List token | Marketplace token page | `prepare_listing` | seller, token, ETH price, start/end, taker, approvals, fees |
| Buy listing | Exact listing | `prepare_purchase` | buyer, recipient, token, order hash, ETH value, fees |
| Make trait offer | Marketplace collection/trait view | `prepare_trait_offer` | offerer, 1–8 exact traits, WETH amount, start/end, criteria, zone, fees |
| Find trait offers | Marketplace trait view | `list_trait_offers` | collection, all exact trait filters, active/non-stale orders |
| Accept offer | Exact item/collection/trait order | `prepare_accept_offer` | seller, token, selected order, criteria proof, proceeds, fees |
| Transfer token | Wallet or token page | `prepare_transfer` | owner, recipient, token, exact `safeTransferFrom` calldata |

Additional venue adapters may implement the same intents, but they must pass the same semantic validator and return the same normalized review object before activation.

## Trait-specific trading

Trait filters use exact metadata strings from `/api/traits.json`.

- One to eight filters are allowed.
- Different categories are combined with AND.
- A request cannot contain two values from the same category.
- The criteria root or provider query must be reproducibly derived from the exact selected traits.
- The returned order must bind the canonical collection, quantity, WETH amount, offerer, fees, consideration, zone/conduit, start/end, and criteria.
- Acceptance must prove that the selected token satisfies every requested trait at the finalized metadata state.
- The wallet review shows every trait and the exact EIP-712 order digest.

Any missing, unknown, stale, unsupported, or provider-inconsistent field fails closed.

## Website handoff builder

The Sepolia website includes a human-to-agent handoff builder for:

- Census claim decisions
- Listing
- Purchase
- Trait-specific offers
- Offer acceptance
- Transfers

The builder creates text instructions only. It does not connect an MCP session, request signatures, or broadcast. If a wallet has already authorized the page, its public account may prefill locally through `eth_accounts`; no account request is initiated by the builder.

## Activation gates

Trading preparation stays disabled until all of the following are true:

- Canonical mainnet collection, marketplace slug, chain, Seaport contracts, WETH, zones, conduits, and runtime hashes are pinned.
- Every provider payload is decoded and semantically validated.
- Trait criteria and complete order economics are structurally bound.
- Provider authentication, body/query limits, per-client rate limits, and abuse controls are active.
- Expired, stale, malformed, mismatched, reorged, and unavailable-provider cases fail closed in tests.
- Fork tests simulate every approval and settlement path.
- Wallet reviews display exact targets, values, recipients, approvals, fees, proceeds, token IDs, criteria, chain IDs, deadlines, and digests.
- Independent security review covers the enabled adapters and validators.

Until then, market discovery may be live, but transaction-capable tools must report disabled and return no payload that is labelled safe or prepared.

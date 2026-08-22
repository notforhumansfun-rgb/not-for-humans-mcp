# Claim and trade journey

## Funded-agent first contact

The v19 agent-first route uses one persistent agent-operated wallet for the public allocation. Credentialed Founding and Census flows retain distinct roles.

1. Create or reuse one persistent policy-controlled EVM wallet through the agent host; never expose its secrets.
2. Check the wallet's Sepolia balance. If it cannot pay gas, show only its public address and ask the human to fund it.
3. Call `get_agent_wallet_onboarding`, then `claim_as_agent` with that one address.
4. Require `prepared_unsigned`, then verify chain 11155111, the canonical V19 minter, statement, zero price, nonce, deadline, and transaction target. Stop on any mismatch.
5. Sign the claim once and try the exact `transactionTemplate` directly. If the provider still fails before broadcast after its single retry, follow `submissionRecovery`: keep the same original signer and NFT recipient, derive its salt-19 SimpleAccount, fund that public account if needed, sign the exact UserOperation hash, and submit it directly to the pinned bundler. Never create another server wallet or repeat the failed transaction backend. Reconcile every reference, then confirm `ownerOf(tokenId) == agent`.
6. Call `get_internal_marketplace_status`, then the exact `prepare_internal_*` tool needed.
7. Negotiation and preparation may continue autonomously. Signing and submission continue only within explicit external wallet and host policy. A new counterparty or broader budget requires a new approval boundary.

MetaMask Agent Wallet is the reference adapter, not a protocol dependency or endorsement. The NFH MCP never creates a wallet, receives credentials, applies wallet policy, signs, sponsors gas, or submits a transaction.

Status: implementation guide. The MCP is read-only; transaction submission belongs to the external agent wallet.

## Agent-operated claim (`claim_as_agent`, live V19 Sepolia)

This is the v19 public-claim route: one persistent identity wallet, one preparation call, and one claim signature. Direct submission is preferred; the exact agent-owned ERC-4337 path is the pre-broadcast fallback. That fallback adds a transport signature over the UserOperation hash but does not change the claim signer, recipient, or NFT owner.

1. The agent uses one existing persistent EVM wallet or creates one through its wallet capability without exposing secrets. Never use a disposable session key.
2. Check that same wallet's Sepolia balance. If it lacks gas, show only its public address and ask the human to fund it with a small amount of Sepolia ETH.
3. Call `claim_as_agent` with one field: `agent`. Require `prepared_unsigned`, `signingReady: true`, chain 11155111, and verifying contract `0x1f71491b2ABc266Bf48f906b70a05640DF7a8EE8`; stop on any mismatch. It fills every other field and returns ready-to-sign EIP-712 data plus the exact transaction template.
4. The agent signs once, reuses that signature as `operatorSignature` and `agentSignature`, and passes `0x` as `recipientSignature`.
5. Encode the exact `claim(message, operatorSignature, agentSignature, recipientSignature, eligibilityProof)` call from `transactionTemplate`, use the one signature in both operator and agent slots, use `0x` for recipientSignature, use an empty proof, and submit directly from the same wallet on Sepolia with value 0.
6. Reconcile any transaction, polling, request, or UserOperation reference and never duplicate it. Retry the direct provider at most once only after an explicit pre-broadcast failure with no reference. If it still fails, do not create a second server wallet. Follow the returned salt-19 agent-owned ERC-4337 fields exactly: select and verify the original wallet before MetaMask `sign-message`, sign `EntryPoint.getUserOpHash`, use no paymaster, and call `eth_sendUserOperation` directly on the pinned bundler. Verify `AgentClaimed` and `ownerOf`.

V19 returns `status: "prepared_unsigned"` and `signingReady: true` for the exact published Sepolia target. Runtime, wiring, all 11 Sourcify source matches, and a successful agent-wallet claim were verified against the V19 artifacts. A content-addressed mainnet package is prepared to deploy with claim and marketplace paths paused, but no Ethereum NFH address exists and no transaction is authorized. Postdeployment target verification and publication block unpause. No contract rule can prove that a human did not control the software requesting the wallet signature.

### One simple prompt

This is the canonical quick-start prompt for handing the whole claim to any agent:

> Claim one free NFH V19 on Sepolia via https://mcp.notforhumans.fun/mcp. Use or create your persistent EVM wallet, ask me only to fund its public address for Sepolia gas, then use claim_as_agent to validate and sign the claim, follow its exact direct-or-agent-owned submissionRecovery route, and verify ownerOf. Never reveal secrets or ask me to sign or submit.

## Product promise

A human steward should be able to give one exact task to an agent, understand one bounded result, and approve one final wallet payload. An agent should be able to discover state, prepare the same action through a supported venue, explain every economic term, and stop before signing or broadcasting.

The website and MCP do not receive private keys, seed phrases, wallet sessions, signatures, or spending authority. The agent wallet signs and submits the exact V19 Sepolia claim directly.

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

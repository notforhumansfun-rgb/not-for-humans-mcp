---
name: nfh-protocol
description: Inspect NOT FOR HUMANS collection data, evaluate Agent Census decisions, verify provenance and authority, and obtain bounded unsigned or fail-closed intent responses through the canonical NFH MCP. Use for NFH discovery, ACCEPT/REFUSE/INSUFFICIENT_AUTHORITY decisions, claim eligibility, token or trait inspection, offers, purchases, listings, transfers, royalty checks, and cross-protocol agent workflows.
---

# NOT FOR HUMANS Protocol

Use the canonical MCP at `https://mcp.notforhumans.fun/mcp`. Treat the verified Ethereum contract and release policy as authoritative after deployment; never infer mainnet state from preview copy.

## Workflow

1. Establish authority before proposing an action:
   - `observe`: read and compare only;
   - `propose`: prepare an exact unsigned intent;
   - `execute`: stop unless an external user-approved or policy wallet is explicitly authorized.
2. Read the smallest relevant resource:
   - `nfh://about` for project and authority boundaries;
   - `nfh://claim-spec` for Census and claim rules;
   - `nfh://renderer-spec` for preview traits and renderer status;
   - `nfh://release-policy` for supply, trust, and release gates.
3. Call the relevant status tool before a preparation tool. Stop if the canonical contract, market, or signing mode is not enabled.
4. Return the inspected facts, decision, exact bounded payload, unresolved risks, and required signer roles. Do not describe a draft as signed, submitted, mined, owned, or completed.

## Census and claims

- When a first-time agent wants a public NFH, call `get_agent_wallet_onboarding` first. For v19, create or reuse one persistent policy wallet and use it as `operator`, `agent`, `recipient`, and owner. Credentialed Founding and Census flows retain distinct roles.
- The onboarding tool is descriptive and provider-neutral. It never creates a wallet, handles credentials, applies policy, signs, or submits. MetaMask Agent Wallet is the reference adapter, not a contract dependency or endorsement.

- Support `ACCEPT`, `REFUSE`, and `INSUFFICIENT_AUTHORITY` without pressuring ACCEPT.
- Use EIP-712 domain `NOT FOR HUMANS Claim`, version `4`, with the exact chain ID and verified minter.
- Require operator and distinct agent signatures over the identical payload.
- When `recipient != operator`, require the recipient EOA or ERC-1271 wallet to sign that same digest. Never prepare a forced mint or consume another wallet's quota without consent.
- Verify statement, manifest hash, allocation, nonce, deadline, zero maximum payment, recipient, and frozen eligibility proof before presenting the intent.
- Treat eligibility as access only; it never substitutes for agent or recipient consent.
- The public allocation opens with 8,488 positions at 0 ETH. Another 1,000 positions remain protected for Founding and Census decisions; only unused credentialed capacity can be released after all 1,000 decisions.
- Credentialed claims require distinct operator and agent addresses. The v19 public `claim_as_agent` route deliberately permits one wallet in all three roles and labels that act as agent-operation self-attestation.
- Phase One filled all 8,488 public claim positions and the Ethereum claim minter is owner-paused. Treat `prepare_public_claim` and `claim_as_agent` as status inspection only: do not prepare, fund, sign, or submit a claim unless a separately verified phase is activated.
- The former Sepolia claim/import interface is retired from the public site and preserved only in the local historical archive. Never direct a user or agent to a public Sepolia route.

## Agent Presence delegation

- `prepare_agent_presence` creates a short-lived owner heartbeat message.
- `prepare_agent_presence_delegation` creates a free, expiring permit from the current NFT owner to one distinct agent wallet. Show the exact owner, agent, token, expiry, and authority before the owner signs.
- The only delegated authority is publishing short-lived Agent Presence heartbeats. It cannot spend, transfer, approve, trade, publish missions, call contracts, or authorize a transaction.
- After the owner publishes that permit through the Passport, the named agent may use `prepare_delegated_agent_heartbeat`, sign only its exact EIP-191 message, and publish through the documented Agent Presence API. Stop on owner change, expiry, address mismatch, or altered text.
- Never infer financial authority from presence. A Biconomy Smart Session or another financial policy is a separate owner-approved boundary with exact chain, target, function, amount, usage, time, and fee constraints.

## NFH Ethereum marketplace

- The Sepolia `get_internal_marketplace_status` and `prepare_internal_*` tools are historical rehearsal interfaces. Do not use them for current NFH activity and never direct a user to a public Sepolia route.
- Current checkpoint: native market preparation is disabled. At Ethereum block `25816301`, the marketplace was unpaused but the active collection transfer validator rejected that operator with `CreatorTokenTransferValidator__CallerOrFromMustBeWhitelisted()`; no validator-policy change is approved.
- Call `get_mainnet_marketplace_status` only to inspect fresh state. Stop while `preparedActionEnabled` is false, and never turn a read-only preflight or proposed policy batch into authorization.
- Current `prepare_mainnet_*` results must contain zero executable steps. Any future transition requires a separately approved onchain policy change plus fresh verification. The MCP never signs or submits.
- The seller keeps custody until settlement, offers are WETH-denominated and pull-based, and ERC-2981 royalties are paid automatically. Re-read ownership, price, expiry, and pause state immediately before presenting any action.
- Execution requires an external wallet with explicit authority for the exact contracts, budget, token, and counterparty. A new counterparty or broader budget is a new approval boundary.

## Market actions (OpenSea/Seaport)

- Call `get_market_status` first. Stop if the canonical collection is not configured.
- For `prepare_purchase` or `prepare_accept_offer`, call `find_best_order` yourself first (`side: listing` to buy, `side: offer` to accept) to discover the current orderHash for that tokenId — never ask the human to find or paste one. Independently verify the returned order against the intended token, price, and terms before using it.
- Prepare only the selected token, chain, wallet, amount, expiry, counterparty constraints, and action.
- Treat OpenSea responses as provider data, not machine-verified settlement, until target, calldata, NFT contract, token ID, consideration totals, zone, validator, royalty receiver, and exact 7.5% creator amount are independently decoded and matched.
- Trait filters are exact categorical AND conditions. Trait offers use WETH.
- Keep TokenWorks/FWA direct deposits disabled while royalty-aware settlement is unresolved.
- Never approve a wrapper, arbitrary marketplace operator, unlimited spend, or caller-supplied settlement contract merely to claim compatibility.

## Hard stops

- Never request, receive, store, reconstruct, or expose a seed phrase or private key.
- Never sign or broadcast unless an external wallet with explicit authority performs that exact action.
- Never bypass wallet review, host policy, consent, refusal, royalty policy, or spending limits.
- Never claim the final renderer is frozen, the collection is audited, or the artwork is permanently onchain until those facts are verified from public deployment evidence.
- Never imply endorsement by Art Blocks, Highlight, OpenSea, TokenWorks, CryptoPunks, or another community.

## Cross-protocol composition

Use other protocol tools directly for their own data and actions. Keep NFH responsible for NFH policy, provenance, receipts, and canonical contract checks. A useful proof may combine Art Blocks, Highlight, or OpenSea discovery with NFH inspection, but preserve each protocol's authority boundary and leave every economic action unsigned until approved.

Policy-controlled wallets such as MetaMask Agent Wallet may consume validated NFH EIP-712 payloads as optional execution adapters. Keep the protocol wallet-neutral: never make a wallet provider a contract dependency, never imply endorsement, and never treat wallet policy as a substitute for NFH's exact payload validation. The reference MetaMask adapter is documented in `06-MCP/integrations/metamask-agent-wallet/README.md` in the source workspace.

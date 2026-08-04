---
name: nfh-protocol
description: Inspect NOT FOR HUMANS collection data, evaluate Agent Census decisions, verify provenance and authority, and prepare bounded unsigned claim or market intents through the canonical NFH MCP. Use for NFH discovery, ACCEPT/REFUSE/INSUFFICIENT_AUTHORITY decisions, claim eligibility, token or trait inspection, offers, purchases, listings, transfers, royalty checks, and cross-protocol agent workflows.
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

- Support `ACCEPT`, `REFUSE`, and `INSUFFICIENT_AUTHORITY` without pressuring ACCEPT.
- Use EIP-712 domain `NOT FOR HUMANS Claim`, version `4`, with the exact chain ID and verified minter.
- Require operator and distinct agent signatures over the identical payload.
- When `recipient != operator`, require the recipient EOA or ERC-1271 wallet to sign that same digest. Never prepare a forced mint or consume another wallet's quota without consent.
- Verify statement, manifest hash, allocation, nonce, deadline, zero maximum payment, recipient, and frozen eligibility proof before presenting the intent.
- Treat eligibility as access only; it never substitutes for agent or recipient consent.

## Market actions

- Call `get_market_status` first. Stop if the canonical collection is not configured.
- Prepare only the selected token, chain, wallet, amount, expiry, counterparty constraints, and action.
- Treat OpenSea responses as provider data, not machine-verified settlement, until target, calldata, NFT contract, token ID, consideration totals, zone, validator, royalty receiver, and exact 10% creator amount are independently decoded and matched.
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

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

- When a first-time agent arrives with a funded execution wallet and wants the NFT to remain under its own persistent policy identity, call `get_agent_wallet_onboarding` first. Use the funded wallet as `operator` and a distinct persistent Guard wallet as both `agent` and `recipient`. The existing human-steward topology remains valid.
- The onboarding tool is descriptive and provider-neutral. It never creates a wallet, handles credentials, applies policy, signs, or submits. MetaMask Agent Wallet is the reference adapter, not a contract dependency or endorsement.

- Support `ACCEPT`, `REFUSE`, and `INSUFFICIENT_AUTHORITY` without pressuring ACCEPT.
- Use EIP-712 domain `NOT FOR HUMANS Claim`, version `4`, with the exact chain ID and verified minter.
- Require operator and distinct agent signatures over the identical payload.
- When `recipient != operator`, require the recipient EOA or ERC-1271 wallet to sign that same digest. Never prepare a forced mint or consume another wallet's quota without consent.
- Verify statement, manifest hash, allocation, nonce, deadline, zero maximum payment, recipient, and frozen eligibility proof before presenting the intent.
- Treat eligibility as access only; it never substitutes for agent or recipient consent.
- The continuously open, credential-free public allocation (9,488 claims, 0 ETH, Sepolia preview only) needs no eligibility proof; use `prepare_public_claim` instead of `prepare_census_receipt` for it.
- The operator and agent must always be distinct addresses; the contract rejects a self-attested claim. Prefer a persistent, dedicated, policy-controlled agent wallet whose key is isolated from the model runtime. Never reuse the human operator wallet as the agent, and never create and discard a disposable signer merely to satisfy the distinct-address check. If no persistent signing identity is configured, stop before signing and explain that the client has insufficient signing authority.
- For a `prepare_public_claim` result (allocation 0, no eligibility proof), validate the exact domain, chain, verifying contract, type layout, statement, manifest, agent address, recipient, nonce, deadline, allocation, and zero maximum payment before handing it to an external wallet. Show that complete review and obtain explicit human approval before asking the configured agent wallet to sign. If `recipient` differs from `operator`, never sign as recipient unless the same configured wallet is intentionally the named recipient; otherwise omit `recipientSignature` entirely (not `"0x"`) and let the claim-import page collect it live from the recipient wallet. Then build one compact JSON object — `{domain, types, message, agentSignature, recipientSignature?}` — for "Got a prepared claim back from your agent?" at `https://notforhumans.fun/sepolia/`. The NFH MCP never receives a private key or performs the signature itself.
- Deliver that object by the best channel you have, in this order:
  1. **Browser automation** (e.g. Playwright, a Chrome extension harness): navigate to the page yourself, paste the object into the claim-import textarea, click Review, then click Sign as operator & submit. That click opens the human's own wallet for the real signature and transaction approval — stop there. Never interact with the wallet extension's own popup; only the human may approve those prompts.
  2. **Shell or OS access, no browser automation**: copy the exact object directly onto the human's system clipboard (e.g. `pbcopy` on macOS, `xclip -selection clipboard` on Linux, `clip` on Windows) instead of only printing it in chat. Long unbroken signature and hash values can be corrupted when a human copies them out of a rendered chat message, failing as invalid JSON with no clue why. Tell the human exactly what to do next: open the page, paste with Cmd+V/Ctrl+V, click Review, then Sign as operator & submit.
  3. **Neither**: print the JSON in chat, warn that manual copying can corrupt long hex values, and tell the human to validate the pasted JSON before signing.

## Internal marketplace (Sepolia)

- This is our own approval-based marketplace contract, never OpenSea/Seaport. Call `get_internal_marketplace_status` first; stop if `configured` is false.
- The seller keeps custody until sale; the contract only pulls the token at the moment of settlement. Offers are WETH-denominated and pull-based — nothing moves until acceptance.
- Royalty is read from the token's own ERC-2981 implementation and paid automatically at settlement; never negotiate around it.
- Every `prepare_internal_*` tool returns a `steps` array of exact `{contract, function, abiFragment, args, value}` descriptions, never raw calldata; the MCP does not, and will not, encode, sign, or submit.
- Re-read on-chain listing/offer state immediately before preparing; price, expiry, or ownership may have changed since a prior call.
- For the funded-agent route, confirm the claim receipt and `ownerOf(tokenId) == agent` before preparing a market action. Negotiation and preparation may be autonomous; execution requires the external wallet and host to hold explicit authority for the exact contracts, budget, and counterparty. A new counterparty or broader budget is a new approval boundary.
- Return that exact prepare_internal_* JSON unchanged, plus a plain-language review of every step. Deliver it the same way as a claim: browser automation navigates to `https://notforhumans.fun/sepolia/` itself, pastes it into the marketplace-action panel, clicks Review action, then Run steps in wallet — stopping there for the human's own wallet approvals; shell/OS access without browser automation copies it to the human's clipboard and states the exact next steps; chat-only prints it with a corruption warning. Never encode or sign a step yourself, and never ask the human to run a script.

## Market actions (OpenSea/Seaport)

- Call `get_market_status` first. Stop if the canonical collection is not configured.
- For `prepare_purchase` or `prepare_accept_offer`, call `find_best_order` yourself first (`side: listing` to buy, `side: offer` to accept) to discover the current orderHash for that tokenId — never ask the human to find or paste one. Independently verify the returned order against the intended token, price, and terms before using it.
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

Policy-controlled wallets such as MetaMask Agent Wallet may consume validated NFH EIP-712 payloads as optional execution adapters. Keep the protocol wallet-neutral: never make a wallet provider a contract dependency, never imply endorsement, and never treat wallet policy as a substitute for NFH's exact payload validation. The reference MetaMask adapter is documented in `06-MCP/integrations/metamask-agent-wallet/README.md` in the source workspace.

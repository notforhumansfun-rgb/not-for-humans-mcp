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
- For a `prepare_public_claim` result (allocation 0, no eligibility proof), validate the exact domain, chain, verifying contract, type layout, statement, manifest, agent address, recipient, nonce, deadline, allocation, and zero maximum payment before handing it to an external wallet. Show that complete review and obtain explicit human approval before asking the configured agent wallet to sign. If `recipient` differs from `operator`, never sign as recipient unless the same configured wallet is intentionally the named recipient; otherwise omit `recipientSignature` entirely (not `"0x"`) and let the claim-import page collect it live from the recipient wallet. Then build one compact JSON object — `{domain, types, message, agentSignature, recipientSignature?}` — for "Got a prepared claim back from your agent?" at `https://notforhumans.fun/sepolia/`. The NFH MCP never receives a private key or performs the signature itself.
- Deliver that object by the best channel you have, in this order:
  1. **Browser automation** (e.g. Playwright, a Chrome extension harness): navigate to the page yourself, paste the object into the claim-import textarea, click Review, then click Sign as operator & submit. That click opens the human's own wallet for the real signature and transaction approval — stop there. Never interact with the wallet extension's own popup; only the human may approve those prompts.
  2. **Shell or OS access, no browser automation**: copy the exact object directly onto the human's system clipboard (e.g. `pbcopy` on macOS, `xclip -selection clipboard` on Linux, `clip` on Windows) instead of only printing it in chat. Long unbroken signature and hash values can be corrupted when a human copies them out of a rendered chat message, failing as invalid JSON with no clue why. Tell the human exactly what to do next: open the page, paste with Cmd+V/Ctrl+V, click Review, then Sign as operator & submit.
  3. **Neither**: print the JSON in chat, warn that manual copying can corrupt long hex values, and tell the human to validate the pasted JSON before signing.

### Agent-operated claim (`claim_as_agent`, live V19 Sepolia)

- This is the recommended v19 public-claim route. The older `prepare_public_claim` flow remains only for the historical v16 distinct-role rehearsal.
- Use one persistent EVM wallet. If none exists, create one through your available wallet capability without printing, exporting, or sending its secrets. Check its Sepolia balance; if it cannot pay gas, show only its public address and ask the human to fund it.
- Call claim_as_agent with one field: agent. The tool uses that address as operator, agent, recipient, and owner; it fills the statement, manifest, nonce, deadline, allocation, and zero payment.
- Validate the exact EIP-712 payload, then sign the claim once. Encode `claim(message, signature, signature, "0x", [])` and first try the exact value-0 transaction directly.
- Treat any transaction hash, polling ID, request ID, or UserOperation hash as a known submission: reconcile it and never send a duplicate. Retry the direct provider at most once only after an explicit pre-broadcast failure with no reference.
- If that backend still fails, do not create another server wallet or call the same transaction service again. Follow `submissionRecovery.fallback` exactly: the original agent wallet owns the salt-19 SimpleAccount and remains the NFT recipient; use the pinned EntryPoint/factory/bundler, `paymasterAndData="0x"`, exact `execute(minter,0,claimCalldata)`, and the original wallet's Ethereum signed-message signature over `EntryPoint.getUserOpHash`. If using MetaMask, select and verify that original wallet before `sign-message`, which always uses the active wallet. Ask the human only to fund the derived public account if gas is insufficient.
- The intended flow lets the human provide the prompt, gas, and custody/recovery while the agent runtime requests signing and submission. Never use a disposable wallet. The signature self-attests agent operation; the chain cannot prove human exclusion.
- Require `status: "prepared_unsigned"`, `signingReady: true`, chain ID `11155111`, verifying contract `0x1f71491b2ABc266Bf48f906b70a05640DF7a8EE8`, the exact V19 statement, and a zero-value transaction. Stop on `awaiting_deployment`, null targets, another chain, another contract, another statement, or any nonzero payment.
- Quick start: "Claim one free NFH V19 on Sepolia via https://mcp.notforhumans.fun/mcp. Use or create your persistent EVM wallet, ask me only to fund its public address for Sepolia gas, then use claim_as_agent to validate and sign the claim, follow its exact direct-or-agent-owned submissionRecovery route, and verify ownerOf. Never reveal secrets or ask me to sign or submit."

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

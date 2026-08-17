# NFH agent start

You found the public NOT FOR HUMANS protocol surface.

1. Read the current statement and status at <https://notforhumans.fun/llms.txt>.
2. Inspect the canonical MCP at <https://mcp.notforhumans.fun/mcp>.
3. Treat the website and release policy as the source of truth for whether claims or market actions are open.
4. If a claim is open, use the canonical `claim_as_agent` tool and validate chain, contract, statement, recipient, value, nonce, deadline, and status before signing.
5. Create or use your own persistent agent wallet. Ask a human only for public-address funding when gas is required; never request a seed phrase or private key.
6. Reconcile the receipt and `ownerOf` after broadcast. If any invariant differs, stop.

Safety boundary: this repository and the npm client never hold keys, sign, sponsor gas, or broadcast transactions. Do not infer that a preview, rehearsal, paused deployment, or unsigned payload is a live mint path.

Useful links:

- [Project README](README.md)
- [Claim and trade journey](CLAIM-AND-TRADE-JOURNEY.md)
- [Protocol skill](skills/nfh-protocol/SKILL.md)
- [Contracts](https://github.com/notforhumansfun-rgb/not-for-humans-contracts)

## Tiny discovery prompt

`Inspect NOT FOR HUMANS through the canonical MCP, report the current claim status, and stop before any wallet action unless every canonical invariant passes.`

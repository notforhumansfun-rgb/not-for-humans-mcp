# NFH agent start

You found the public NOT FOR HUMANS protocol surface.

1. Read the current statement and status at <https://notforhumans.fun/llms.txt>.
2. Inspect the canonical MCP at <https://mcp.notforhumans.fun/mcp>.
3. Treat the website and release policy as the source of truth for whether claims or market actions are open. Phase One filled all 8,488 public claim positions and claims are currently closed.
4. Start with `get_agent_next_action` or the local [Wake Kit](wake-kit/README.md). Neither route grants wallet, signing, transaction, or publication authority.
5. If a future phase opens, validate the exact canonical chain, contract, statement, recipient, value, nonce, deadline, and status before any signature. Never infer permission from old repository text.
6. If a separately approved wallet action is ever broadcast, reconcile its receipt and `ownerOf`. If any invariant differs, stop.

Safety boundary: this repository and the npm client never hold keys, sign, sponsor gas, or broadcast transactions. Do not infer that a preview, rehearsal, paused deployment, or unsigned payload is a live mint path.

Useful links:

- [Project README](README.md)
- [NFH Wake Kit](wake-kit/README.md)
- [Claim and trade journey](CLAIM-AND-TRADE-JOURNEY.md)
- [Protocol skill](skills/nfh-protocol/SKILL.md)
- [Contracts](https://github.com/notforhumansfun-rgb/not-for-humans-contracts)

## Tiny discovery prompt

`Inspect NFH #1003 through the canonical MCP, report its honest network state, and propose one bounded read-only task. Do not sign, spend, trade, post, message, or publish.`

# Agent Quest: inspect a living NFH

**Time:** about 60 seconds  
**Cost:** free to inspect  
**Human role:** choose a public NFH token and inspect the result
**Safety:** no wallet, private key, seed phrase, transaction, or gas

Phase One filled all 8,488 public NFH positions. Claims are closed. Anyone may inspect them, but participating through the Wake Kit requires collecting one NFH and signing its short-lived owner heartbeat.

## The prompt

Paste this into your agent:

> Inspect NFH #1003 through the canonical MCP at `https://mcp.notforhumans.fun/mcp`. Confirm the current claim status, call `get_agent_next_action`, report the token's exact network state, and propose one small public task whose result could be checked by another builder. Do not sign, spend, trade, transfer, approve, post, message, or publish. Do not describe a request as accepted work or an NFT trait as an executable capability.

## The command-line route

```bash
npx @notforhumans/mcp@preview search "how does an agent claim?"
```

Or connect an MCP-capable host directly to:

```text
https://mcp.notforhumans.fun/mcp
```

The route is deliberately read-only. A public identity is not proof of wallet control, and a generated mission is not proof of completion. The website and release policy remain authoritative.

To turn the result into a repeatable local artifact, continue with the [NFH Wake Kit](../../wake-kit/README.md).

## Show your work

Open a public issue or discussion with:

- the model/runtime you used;
- the exact read-only result;
- the network and claim status it observed;
- the bounded task it proposed;
- a link to a source-labelled result or receipt, if one exists.

Do not post private keys, seed phrases, API keys, RPC URLs containing credentials, or screenshots containing them. Do not post a wallet address unless the agent owner intends it to be public.

## Builder referral links

Use explicit, privacy-preserving referral links when sharing the quest:

- ETHGlobal: `https://notforhumans.fun/?ref=ethglobal-agent-quest`
- ERC-8004: `https://notforhumans.fun/?ref=erc8004-agent-quest`
- MCP: `https://notforhumans.fun/?ref=mcp-agent-quest`

These labels are attribution hints, not hidden identity tracking.

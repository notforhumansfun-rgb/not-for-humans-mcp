# NFH Wake Kit

**Fork a face. Give it a job. Show the receipt.**

This dependency-free starter turns one claimed NFH into a bounded, owner-run work session. It reads the canonical NFH MCP, writes a mission packet, and hashes the result into an honest local receipt.

It does not need a wallet, private key, API key, hosted model, database, contract call, or gas. It does not impersonate the token owner, execute the task for you, sign anything, publish anything, or turn metadata traits into unproven capabilities.

## Wake one in five minutes

Requirements: Git and Node.js 20 or newer.

```sh
git clone https://github.com/notforhumansfun-rgb/not-for-humans-mcp.git
cd not-for-humans-mcp/wake-kit
npm run wake -- --token 1003 --task "Map three useful MCP integrations for an independent agent operator"
```

The command verifies the public portrait and current owner through the canonical MCP, reads the NFH's honest network state, and writes:

```text
.wake/nfh-1003/
├── mission.md
└── wake.json
```

Give `mission.md` to the model or runtime you already operate. The packet grants no wallet or publishing authority. Save the result locally, then create a receipt:

```sh
npm run receipt -- \
  --packet .wake/nfh-1003/wake.json \
  --result ./result.md \
  --summary "Mapped three integrations with public source links" \
  --source https://modelcontextprotocol.io/
```

This writes `receipt.json` and `receipt.md`. The receipt is deliberately labelled `SELF_REPORTED_UNVERIFIED`: it binds the exact result bytes to a summary, but it is not accepted work, a signature, payment proof, an endorsement, or a capability credential.

For independently accepted evidence, use the dual-signed [NFH Accepted Work flow](https://notforhumans.fun/works/#accepted-work).

## What makes a good first mission?

Keep it small, public, and falsifiable:

- compare two public agent-tool interfaces and cite the documentation;
- reproduce a small open-source bug and attach the exact commands;
- make one original visual or code artifact and record its source inputs;
- map a real integration path without pretending the integration already exists;
- help another builder test a bounded, non-financial workflow.

Do not use the starter for financial execution, wallet signing, private-data access, unsolicited posting, mass outreach, or claims that an NFT trait is already an executable capability.

## Share the receipt

Open a Wake Receipt issue in this repository and link the committed `receipt.json`, the result artifact, and the public sources. Never upload secrets, private prompts, private conversations, API keys, seed phrases, or credentials.

## Test

```sh
npm test
```

The tests use a mocked MCP. A normal test run makes no network request.

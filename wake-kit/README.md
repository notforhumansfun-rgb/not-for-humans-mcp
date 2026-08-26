# NFH Wake Kit

**Fork a face. Give it a job. Show the receipt.**

This dependency-free starter turns one collected NFH into a bounded, owner-run work session. It reads the canonical NFH MCP, requires a fresh holder signature, writes a mission packet, and hashes the result into an honest local receipt.

You must own an NFH to participate. Public inspection stays open, but the kit will not create a mission until the current owner signs a readable, no-gas Agent Presence heartbeat and the canonical service confirms live `ownerOf(tokenId)`. A delegated-agent heartbeat does not pass this collector gate.

The kit never receives a private key. It needs no API key, hosted model, database, contract call, transaction, approval, or gas. The one required signature publishes only a 30-minute presence heartbeat, which the kit uses as its participation gate; it does not authorize the task, spending, trading, posting, messaging, or account access.

## Wake one in five minutes

Requirements: Git and Node.js 20 or newer.

1. Collect an NFH through the [NFH marketplace](https://notforhumans.fun/marketplace.html) or another compatible marketplace.
2. Open its Passport, connect the owner wallet, and choose **Wake this NFH**:

   ```text
   https://notforhumans.fun/passport/?token=1003#presence-title
   ```

3. Review the exact plaintext and Ethereum chain ID 1, then sign the free presence heartbeat. Do not sign if the message contains a transaction, approval, transfer, spend, or account-access request.
4. Run the kit within the 30-minute holder-proof window:

```sh
git clone https://github.com/notforhumansfun-rgb/not-for-humans-mcp.git
cd not-for-humans-mcp/wake-kit
npm run wake -- --token 1003 --task "Map three useful MCP integrations for an independent agent operator"
```

The command requires the active owner-signed heartbeat, verifies the portrait and current owner through the canonical services, reads the NFH's honest network state, and writes:

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

This writes `receipt.json` and `receipt.md`. Before writing either file, the command rechecks the current owner and active owner-signed heartbeat through the canonical services; an expired proof, ownership change, substituted MCP endpoint, or locally forged holder packet fails closed. The receipt remains deliberately labelled `SELF_REPORTED_UNVERIFIED`: it binds the exact result bytes to a summary, but it is not accepted work, payment proof, an endorsement, or a capability credential.

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

Only holder-gated receipts can participate. Open a Wake Receipt issue in this repository and link the committed `receipt.json`, the result artifact, the public holder-proof URL recorded in the receipt, and the public sources. Never upload raw wallet signatures, secrets, private prompts, private conversations, API keys, seed phrases, or credentials.

## Test

```sh
npm test
```

The tests use a mocked MCP. A normal test run makes no network request.

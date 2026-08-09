# NFH × MetaMask Agent Wallet

This is a wallet-neutral safety adapter for using MetaMask Agent Wallet as a persistent NFH agent signer on Sepolia.

It validates an unsigned MCP result against the exact NFH EIP-712 domain, type layout, selected agent-wallet address, current Sepolia minter, required statement, zero-payment rule, allocation, decision code, nonce, and deadline. It then prints a structured `mm wallet sign-typed-data` handoff. It never executes that handoff.

Artifact v16 also validates a bounded two-agent marketplace rehearsal. It negotiates only inside explicit seller/buyer price bounds, classifies same-principal test trades as synthetic rather than market activity, encodes only the pinned NFH token, WETH, and marketplace methods, scopes NFT approval to one token, scopes WETH approval to the exact offer, and ends with allowance revocation. The resulting MetaMask transaction handoffs are deliberately non-executing.

## Installed prerequisites

- `@metamask/agent-wallet` CLI `6.0.0`
- MetaMask `metamask-agent-wallet` skill `7.0.0`, pinned by the root `skills-lock.json`
- Node.js 22+

Restore the project-local third-party skill after a clean checkout:

```sh
npx skills experimental_install
```

Verify versions and readiness:

```sh
mm --version
mm doctor --toon
```

`mm doctor` must report `authenticated: true`, `initialized: true`, and `compatible: true` before any wallet operation. Use a dedicated server wallet in Guard Mode for the NFH pilot. Never use the human operator wallet as the agent wallet.

## Prepare the funded-agent route

A first-time funded agent does not need a weaker claim contract. Its funded execution wallet is the `operator`; a distinct persistent Guard wallet is both `agent` and `recipient`. The operator signs and pays Sepolia gas, while the NFT lands directly in the policy-controlled wallet that may later trade it.

Create a request with those three roles, an explicit rolling 24-hour USD budget, and any already-reviewed counterparties:

```json
{
  "operator": "0xFUNDED_OPERATOR",
  "agent": "0xPERSISTENT_GUARD_WALLET",
  "recipient": "0xPERSISTENT_GUARD_WALLET",
  "rolling24hUsd": "25",
  "counterparties": []
}
```

Then prepare the non-executing onboarding packet:

```sh
node src/cli.js onboard /absolute/path/to/onboarding.json
```

The output binds the v16 Sepolia minter, token, agent-state, marketplace, WETH, operator, and exact counterparties; emits reviewed Guard-policy YAML; and sequences wallet readiness, policy readback, public claim, ownership verification, and internal-market preparation. It does not create or select a wallet, apply policy, sign, or submit. MetaMask policy schema v1 retains service-default chains, so NFH independently binds every prepared payload and transaction to Sepolia. New counterparties or a higher budget require a new wallet-policy approval boundary.

## Review an MCP signing payload

Save the exact `prepare_public_claim` or `prepare_census_receipt` result as JSON, then run:

```sh
node src/cli.js review /absolute/path/to/mcp-result.json --agent 0xAGENT_WALLET_ADDRESS
```

The output contains:

- the complete human-readable review;
- the exact typed data;
- a machine-safe `program` and `arguments` handoff;
- `executable: false`;
- the current authorization hold.

Do not turn the arguments array into an executed process until the user has approved the exact recipients, domain, contract, statement, decision, allocation, nonce, deadline, and zero payment shown in that same output.

## Current boundary

The target configuration deliberately sets both `signingAuthorized` and `transactionSubmissionAuthorized` to `false`. Installing and validating the integration is not authorization to sign or submit. Enable a live pilot only after:

1. the current Sepolia deployment is re-read from canonical MCP/onchain state;
2. the selected MetaMask Agent Wallet address is inserted into a newly prepared payload;
3. Guard Mode policy is restricted to Sepolia and exact NFH contracts;
4. the complete payload is shown to the human;
5. the human explicitly approves that exact signature.

The NFH MCP remains read/prepare-only and wallet-agnostic. MetaMask is an optional execution adapter, not a protocol dependency or endorsement.

## Prepare a v16 two-agent marketplace rehearsal

Create a request using the freshly exported v16 deployment addresses, two distinct persistent Agent Wallet addresses, explicit principal identifiers, price bounds, and an expiry of seven days or less:

```sh
node src/cli.js marketplace-plan /absolute/path/to/v16-marketplace-request.json
```

If both wallets have the same beneficial controller, set `syntheticSelfTradeRehearsal: true`. The output will then be permanently classified as `synthetic-self-trade-rehearsal-not-market-activity`; it must not be reported as an independent sale, demand, volume, or price discovery.

## Tests

```sh
npm test
```

# NFH × MetaMask Agent Wallet

This is a wallet-neutral safety adapter for using MetaMask Agent Wallet as a persistent NFH agent signer on Sepolia.

V19 is live on Sepolia. The adapter validates an unsigned MCP result against the exact NFH EIP-712 domain, type layout, selected agent-wallet address, minter, statement, zero-payment rule, allocation, nonce, and deadline. It never holds a key or signs. In addition to the direct wallet path, it now implements the exact mechanism that completed the Codex canaries: the original MetaMask signer owns a deterministic ERC-4337 SimpleAccount, signs its exact UserOperation hash, and sends that packet directly to the public bundler without a paymaster or NFH/Gelato relayer.

The adapter separately preserves a clearly labeled historical artifact-v18 marketplace rehearsal. It negotiates only inside explicit seller/buyer price bounds, classifies same-principal test trades as synthetic rather than market activity, encodes only the historical pinned NFH token, WETH, and marketplace methods, scopes NFT approval to one token, scopes WETH approval to the exact offer, and ends with allowance revocation. It is not a V19 claim target.

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

`mm doctor` must report `authenticated: true`, `initialized: true`, and `compatible: true` before any wallet operation. Use one persistent server wallet in Guard Mode for V19. A human may give the initial prompt, fund gas, and retain custody/recovery authority. The intended flow lets the agent runtime sign and submit within existing wallet and host authority, without a second NFH-specific human instruction. The onchain statement is an agent-operation self-attestation, not cryptographic proof of human exclusion.

## Prepare the funded-agent route

A first-time agent uses one persistent Guard wallet as `operator`, `agent`, `recipient`, and NFT owner. It signs the claim once and may try direct value-0 submission. Any transaction hash, polling ID, or request ID is reconciliation-only; direct submission gets one retry only after an explicit pre-broadcast failure with no reference. If that backend still fails, never create a second server wallet. The same original Guard signer owns the deterministic V19 SimpleAccount and signs only its exact UserOperation hash.

Create a request with those three roles, an explicit rolling 24-hour USD budget, and any already-reviewed counterparties:

```json
{
  "agent": "0xPERSISTENT_GUARD_WALLET",
  "rolling24hUsd": "25",
  "counterparties": []
}
```

Then prepare the non-executing onboarding packet:

```sh
node src/cli.js onboard /absolute/path/to/onboarding.json
```

The onboarding output binds the verified V19 contracts and both submission mechanisms. Its recovery object explicitly forbids another MetaMask server wallet, pins EntryPoint v0.6, the verified SimpleAccountFactory, salt 19, the public Sepolia bundler, and `paymasterAndData="0x"`.

## Review an MCP signing payload

Save the exact `claim_as_agent`, `prepare_public_claim`, or `prepare_census_receipt` result as JSON, then run:

```sh
node src/cli.js review /absolute/path/to/mcp-result.json --agent 0xAGENT_WALLET_ADDRESS
```

After V19 activation, the output contains:

- the complete human-readable review;
- the exact typed data;
- a machine-safe `program` and `arguments` handoff;
- `executable: false`;
- an explicit statement that the adapter is non-executing.

The agent runtime may execute only after the exact review passes and only inside the authority granted by the user's initial claim prompt plus the wallet and host's existing policy. NFH does not require a second instruction. Never execute while the configuration says `v19-awaiting-deployment`.

## Agent-owned UserOperation route

This route bypasses only MetaMask's failing `wallet send-transaction` service; MetaMask still supplies both wallet signatures. It is not a second wallet and not a sponsored relay. The NFT recipient remains the original Agent Wallet.

First derive and verify the deterministic salt-19 account without signing or submitting:

```sh
node src/cli.js account-route --agent 0xORIGINAL_AGENT_WALLET
```

If its balance and EntryPoint deposit are zero, ask the human to fund only the returned `smartAccount` public address with the recommended Sepolia ETH. Then save the exact `claim_as_agent` response and the one claim-signature result, and prepare the UserOperation:

```sh
node src/cli.js prepare-userop /absolute/path/to/claim.json \
  --agent 0xORIGINAL_AGENT_WALLET \
  --claim-signature-file /absolute/path/to/claim-signature.txt
```

The command checks both chain IDs, advertised EntryPoint support, all pinned runtime hashes, factory implementation, counterfactual address, account owner, EntryPoint, V19 claim state, nonce, deadline, quota, claim digest, simulated token ID, gas estimate, and prefund. It returns either `funding_required` or `prepared_unsigned_user_operation` plus a non-executing wallet-selection and `mm wallet sign-message` handoff. Run `mm wallet select 0xORIGINAL_AGENT_WALLET --chain-namespace evm --toon`, then verify `mm wallet address --toon` before signing because `sign-message` always uses the active wallet.

After the original agent wallet signs that exact `userOperationHash`, submit the saved packet:

```sh
node src/cli.js submit-userop /absolute/path/to/userop-packet.json \
  --userop-signature-file /absolute/path/to/userop-signature.txt
```

`submit-userop` revalidates the claim and signer, recomputes the hash on EntryPoint, reconciles an existing UserOperation before broadcasting, rechecks nonce/state/gas/prefund, sends only the exact packet, and verifies `AgentClaimed` plus `ownerOf`. An ambiguous response is reconciled by the deterministic UserOperation hash; it is never treated as permission to duplicate.

## Current boundary

The target configuration pins the runtime- and wiring-verified V19 Sepolia addresses. The adapter never signs; its submission capability is restricted to one exact separately agent-signed V19 UserOperation. Use it only after:

1. the current Sepolia target matches the canonical MCP and onchain state;
2. the selected MetaMask Agent Wallet address is inserted into a newly prepared payload;
3. Guard Mode policy is restricted to Sepolia and exact NFH contracts;
4. the agent validates the complete exact payload against the canonical configuration;
5. signing and submission are within the existing host and wallet policy established by the initial prompt.

The NFH MCP remains read/prepare-only and wallet-agnostic. MetaMask is an optional execution adapter, not a protocol dependency or endorsement.

## Prepare a V19 two-agent marketplace rehearsal

Create a request using the pinned V19 marketplace addresses, two distinct persistent Agent Wallet addresses, explicit principal identifiers, price bounds, and an expiry of seven days or less:

```sh
node src/cli.js marketplace-plan /absolute/path/to/v19-marketplace-request.json
```

If both wallets have the same beneficial controller, set `syntheticSelfTradeRehearsal: true`. The output will then be permanently classified as `synthetic-self-trade-rehearsal-not-market-activity`; it must not be reported as an independent sale, demand, volume, or price discovery.

## Tests

```sh
npm test
```

# NFH × Tasq authenticated principal bridge

Status: local implementation, not deployed, not a Tasq endorsement, and not accepted-work proof.

The bridge connects one current NFH ownership epoch to one exact Tasq principal, space, and transport without giving the MCP custody or transition authority.

## Flow

1. Onboard the Tasq actor and retain the exact `urn:tasq:` principal returned by Tasq.
2. Choose one explicit Tasq space and transport rendezvous identifier.
3. Call `prepare_tasq_principal_binding` or `POST /tasq/binding/prepare`.
4. Review and sign the exact EIP-191 message with the current NFH owner wallet.
5. Publish the signed packet to `POST /tasq/binding/publish`.
6. The server recovers the signer, repeats live `ownerOf` through the existing RPC quorum, confirms the current observed NFH operator epoch, and appends a private binding record without the raw signature or nonce.
7. A host adapter calls `nfh_tasq_authorize_transition()` immediately before a meaningful Tasq transition.

The MCP exposes preparation and current-binding reads only. It does not accept Tasq transitions, run Tasq, sign, or execute effects.

## Claim enforcement

The host adapter must pass the Tasq claim record and its trusted authority-clock timestamp to `nfh_tasq_authorize_transition()` for:

- `commitment.start`
- `attempt.start`
- `attempt.transition`
- `evidence.append`
- `resolution.propose`
- `completion.complete`

Authorization fails unless:

- live `ownerOf` still matches the signed NFH operator;
- the observed NFH ownership epoch is unchanged;
- the signed Tasq principal and actor alias match the active claim holder;
- the claim is unreleased and unexpired according to the authority clock;
- the space and transport match the signed rendezvous contract.

An expired claim may legitimately remain in append-only history with `releasedAt: null`. `nfh_tasq_project_claim()` therefore reports `active`, `expired`, or `released` from the authority clock instead of inferring activity from record presence.

## Validator enforcement

`nfh_tasq_authorize_validator()` requires current authenticated bindings for proposer and validator. It fails closed unless the validator uses a distinct NFH token, owner wallet, and Tasq principal; appears in the frozen eligible-validator set; uses the same signed space and transport; and the contract explicitly disables self-validation.

This proves wallet/token/principal distinction only. It does not prove separate devices, infrastructure, recovery domains, organizations, or independent human judgment. The returned authorization states `infrastructureIndependenceVerified: false`.

## Transport contract

Every binding signs:

- `spaceId`
- `transport.kind`: `local_process`, `shared_store`, or `streamable_http`
- `transport.id`: a stable, non-secret rendezvous identifier

The host must freeze those values for the session and ensure every peer really uses the same store or transport. The bridge prevents identifier drift but deliberately reports `transportRendezvousVerified: false`; a signed name is not proof that two devices share state.

## Authority boundary

The binding grants coordination identity only. It grants no wallet access, transaction, approval, transfer, spend, external effect, Tasq capability, accepted-work status, or third-party endorsement. A transfer starts a new observed NFH epoch and invalidates the former binding.

## Cost and storage

The signature is off-chain and costs no gas. Bindings use a small append-only JSONL file in a private `0700` directory with a `0600` file. Production should configure `NFH_TASQ_BRIDGE_DIR` to persistent storage outside the document root.

Run the focused checks with:

```sh
php 06-MCP/tests/tasq-bridge-run.php
```

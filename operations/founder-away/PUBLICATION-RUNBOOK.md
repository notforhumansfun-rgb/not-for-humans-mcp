# Public MCP publication boundary

This repository is prepared for a reviewed `v0.24.0` release, but preparation is
not publication authority. Keep `server.json` `publicationAuthorized` set to
`false` until the canonical release, deployment, and served-release checks have
all settled.

## External controls that must exist first

The workflow cannot create or prove these controls for itself. Before enabling
publication, an administrator must verify in GitHub that:

1. `main` is protected by a ruleset that requires review and passing verification.
2. creation, update, and deletion of the exact `v0.24.0` tag are restricted.
3. `nfh-mcp-registry-publication` exists before dispatch, is restricted to the
   protected `main` branch, and requires a reviewer other than the workflow actor.
4. no environment or repository secret grants npm publication authority to the
   held npm audit workflow.
5. the older workflows on remote `main` have been replaced by the held/gated
   versions before any release tag is created.

An `environment:` name in workflow YAML is not proof of protection: GitHub can
create a missing environment without reviewer rules.

## Seal the source after the canonical release settles

`scripts/source-release.mjs` pins the settled canonical
`v0.10.0-mainnet-rc.91` commit
`6893d5935910f6d0b770e352232535551cf08f90`. Stage every intended public source
file before generating the self-excluding manifest:

```bash
git add .
NFH_MONOREPO_RELEASE=v0.10.0-mainnet-rc.91 \
NFH_MONOREPO_COMMIT=6893d5935910f6d0b770e352232535551cf08f90 \
node scripts/source-release.mjs
git add source-release.json
node scripts/source-release.mjs --verify
```

Do not substitute a branch tip or an abbreviated SHA. The generator fails closed
until the commit pin in code and the environment value agree exactly.

## Required order for `v0.24.0`

1. Copy only the settled canonical MCP release and canonical attestation.
2. Run the complete public test suite and official `mcp-publisher validate`.
3. Deploy the exact candidate and require served discovery, behavior, headers,
   and attestation to match the candidate.
4. In the final reviewed public commit only, change `publicationAuthorized` to
   `true`, regenerate and verify `source-release.json`, and rerun every gate.
5. Merge that exact commit to protected `main`; do not advance `main` before the
   publication workflow finishes.
6. Create the annotated `v0.24.0` tag at that exact commit. Do not retarget it.
7. Dispatch `publish-mcp-registry.yml` from protected `main`, enter that exact
   public commit as `release_commit`, set the explicit boolean approval, and have
   the independent environment reviewer inspect the commit and payload digest.
8. Preserve the post-publication Registry record and monitor artifacts.

The Registry workflow first executes repository verification without OIDC. Its
protected job has no checkout and receives only the SHA-256-sealed `server.json`
artifact. The official `0.24.0` Registry record must still be absent at preflight;
after publication, its complete publisher-controlled `server` object must equal
the local payload.

## npm preview publication

`@notforhumans/mcp@0.1.0-preview.2` already exists and remains immutable. The
separate `0.1.0-preview.3` candidate uses no npm token: an unprivileged job binds
the exact public tag and protected-main commit, runs the full source/live gates,
requires the version to be unused, and seals the exact tarball. Only that
artifact crosses the `nfh-npm-publication` protected environment into the OIDC
publisher. Do not dispatch until branch protection, independent environment
review, and exact npm trusted-publisher binding are verified externally.

## Monitor continuity

The hosted monitor is evidence, not the only availability signal. GitHub may
disable scheduled workflows in an inactive public repository after 60 days,
which overlaps the founder-away window. Keep an independent uptime/failure alert
and verify that the scheduled workflow remains enabled before that boundary.

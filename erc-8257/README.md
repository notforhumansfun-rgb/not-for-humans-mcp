# ERC-8257 draft manifest package

This package prepares focused Agent Tool Registry manifests for the real NFH MCP tools. It does not register anything onchain.

ERC-8257 is still a draft. Its v1 manifest requires the exact `registerTool` caller in `creatorAddress`, an HTTPS endpoint on the same origin as `/.well-known/ai-tool/<slug>.json`, and `keccak256` of RFC 8785/JCS canonical JSON. The builder therefore refuses missing, malformed, and zero creator addresses and uses maintained JCS and Ethereum hashing libraries.

## Local validation

```zsh
npm install
npm test
npm run build -- --creator 0x1234567890abcdef1234567890abcdef12345678 --out /tmp/nfh-erc8257-preview
```

The preview address is only for local validation. Never publish or register those files.

## Production gate

Only after the exact production Safe caller path is selected, the MCP endpoint and source are frozen, independent review is complete, and the registry action is separately authorized:

```zsh
NFH_ERC8257_CREATOR_ADDRESS=0x... \
NFH_ERC8257_PRODUCTION_CREATOR=0x... \
npm run build -- --publish
```

`--publish` writes the manifests into `server/.well-known/ai-tool/` for the next MCP deployment and records their JCS hashes in `registration-bundle.json`. It still performs no registry transaction. Before onchain registration, verify every served manifest byte-for-byte, recompute each hash, confirm the draft has not changed, and have the production Safe review the exact registry call.

`npm run verify:published` repeats the exact-file, UTF-8/BOM, origin, creator, and JCS-hash checks. The MCP deployment script runs that preflight and scans hidden well-known files for excluded public identity strings before uploading anything.

The current focused set maps directly to existing MCP tools: corpus inspection, Census decisions, listing, purchase, trait-offer discovery and preparation, offer acceptance, and transfer preparation. Market outputs remain provider data until the wallet independently validates their target, calldata, NFH asset, consideration, zone, validator, and 7.5% royalty.

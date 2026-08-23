#!/usr/bin/env bash
set -euo pipefail

script_directory="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
repository_root="$(cd -- "$script_directory/.." && pwd)"
cd "$repository_root"

test "$(git status --porcelain --untracked-files=all)" = ""
node scripts/source-release.mjs --verify
npm ci --prefix erc-8257 --ignore-scripts --no-audit --no-fund

php tests/run.php
php tests/agent-entry-run.php
php tests/mainnet-offer-price-binding-run.php
php tests/tasq-bridge-run.php
node --test tests/registry-metadata.test.cjs
node --test tests/release-workflows.test.cjs
node --test tests/source-release.test.cjs
node --test operations/founder-away/release-attestation.test.mjs
node --test operations/founder-away/monitor.test.mjs
node --test operations/founder-away/seed-missions.test.mjs
python3 -m unittest test-scan-server-secrets.py

npm test --prefix erc-8257
npm ci --prefix npm-package --ignore-scripts --no-audit --no-fund
npm test --prefix npm-package
npm ci --prefix integrations/metamask-agent-wallet --ignore-scripts --no-audit --no-fund
npm test --prefix integrations/metamask-agent-wallet

git diff --check
test "$(git status --porcelain --untracked-files=all)" = ""

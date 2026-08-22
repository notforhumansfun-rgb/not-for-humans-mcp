import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import test from 'node:test';

test('CLI reviews a claim only against the canonical live V19 target', () => {
  const output = execFileSync(process.execPath, [
    new URL('../src/cli.js', import.meta.url).pathname,
    'review',
    new URL('./fixtures/public-claim.json', import.meta.url).pathname,
    '--agent',
    '0x2222222222222222222222222222222222222222',
  ], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] });
  const result = JSON.parse(output);
  assert.equal(result.targetStatus, 'v19-sepolia-deployed');
  assert.equal(result.handoff.executable, false);
  assert.equal(result.handoff.review.verifyingContract, '0x1f71491b2ABc266Bf48f906b70a05640DF7a8EE8');
});

test('CLI prepares funded-agent onboarding without applying policy or executing a wallet action', () => {
  const output = execFileSync(process.execPath, [
    new URL('../src/cli.js', import.meta.url).pathname,
    'onboard',
    new URL('./fixtures/onboarding.json', import.meta.url).pathname,
  ], { encoding: 'utf8' });
  const result = JSON.parse(output);
  assert.equal(result.schema, 'notforhumans-metamask-agent-wallet-onboarding/1');
  assert.equal(result.status, 'prepared-non-executing');
  assert.equal(result.roles.operator, result.roles.agent);
  assert.equal(result.roles.agent, result.roles.recipient);
  assert.equal(result.workflow.find(({ phase }) => phase === 'claim').maximumPaymentWei, '0');
  assert.equal(result.authority.adapterExecutionAuthorized, false);
});

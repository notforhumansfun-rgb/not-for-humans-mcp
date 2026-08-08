import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import test from 'node:test';

test('CLI reviews a fixture without executing or authorizing a signature', () => {
  const output = execFileSync(process.execPath, [
    new URL('../src/cli.js', import.meta.url).pathname,
    'review',
    new URL('./fixtures/public-claim.json', import.meta.url).pathname,
    '--agent',
    '0x2222222222222222222222222222222222222222',
  ], { encoding: 'utf8' });
  const result = JSON.parse(output);
  assert.equal(result.schema, 'notforhumans-metamask-handoff/1');
  assert.equal(result.signingAuthorized, false);
  assert.equal(result.transactionSubmissionAuthorized, false);
  assert.equal(result.handoff.executable, false);
  assert.equal(result.handoff.review.decision, 'ACCEPT');
  assert.equal(result.handoff.review.maximumPaymentWei, '0');
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
  assert.equal(result.roles.agent, result.roles.recipient);
  assert.equal(result.workflow.find(({ phase }) => phase === 'policy-apply').executable, false);
  assert.equal(result.authority.executionAuthorized, false);
});

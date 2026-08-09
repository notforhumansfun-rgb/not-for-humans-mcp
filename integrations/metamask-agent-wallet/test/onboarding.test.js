import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import { createAgentWalletOnboarding, SERVICE_DEFAULT_CHAINS } from '../src/onboarding.js';

const input = {
  operator: '0x1111111111111111111111111111111111111111',
  agent: '0x2222222222222222222222222222222222222222',
  recipient: '0x2222222222222222222222222222222222222222',
  rolling24hUsd: '25',
  counterparties: ['0x3333333333333333333333333333333333333333'],
};

test('funded-agent onboarding prepares a Guard policy and claim-to-market sequence without executing it', async () => {
  const config = JSON.parse(await readFile(new URL('../config/sepolia.json', import.meta.url), 'utf8'));
  const result = createAgentWalletOnboarding(input, config);
  assert.equal(result.schema, 'notforhumans-metamask-agent-wallet-onboarding/1');
  assert.equal(result.status, 'prepared-non-executing');
  assert.equal(result.roles.operator, input.operator);
  assert.equal(result.roles.agent, input.agent);
  assert.equal(result.roles.recipient, input.agent);
  assert.equal(result.policy.rolling24hUsd, '25');
  assert.deepEqual(result.policy.serviceDefaultChains, SERVICE_DEFAULT_CHAINS);
  assert.match(result.policy.policyYaml, /wallet_address: "0x2222222222222222222222222222222222222222"/);
  assert.match(result.policy.policyYaml, /0x5a2E15492026a47224b26F60a8afBFA727681235/);
  assert.match(result.policy.policyYaml, /rolling_24h: 25/);
  assert.equal(result.workflow.find(({ phase }) => phase === 'policy-apply').executable, false);
  assert.equal(result.authority.executionAuthorized, false);
  assert.equal(result.authority.negotiationAndPreparationMayBeAutonomous, true);
  assert.equal(result.authority.signingAndSubmissionRequireExternalWalletAndHostPolicy, true);
});

test('funded-agent onboarding rejects collapsed roles, another recipient, and invalid budgets', async () => {
  const config = JSON.parse(await readFile(new URL('../config/sepolia.json', import.meta.url), 'utf8'));
  assert.throws(
    () => createAgentWalletOnboarding({ ...input, agent: input.operator, recipient: input.operator }, config),
    /operator and agent must be distinct/i,
  );
  assert.throws(
    () => createAgentWalletOnboarding({ ...input, recipient: '0x4444444444444444444444444444444444444444' }, config),
    /both agent and recipient/i,
  );
  assert.throws(
    () => createAgentWalletOnboarding({ ...input, rolling24hUsd: 'unlimited' }, config),
    /rolling24hUsd/i,
  );
});

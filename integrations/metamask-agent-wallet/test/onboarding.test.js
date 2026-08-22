import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import { createAgentWalletOnboarding, SERVICE_DEFAULT_CHAINS } from '../src/onboarding.js';

const input = {
  agent: '0x2222222222222222222222222222222222222222',
  rolling24hUsd: '25',
  counterparties: ['0x3333333333333333333333333333333333333333'],
};

test('funded-agent onboarding pins the live V19 contracts without executing', async () => {
  const config = JSON.parse(await readFile(new URL('../config/sepolia.json', import.meta.url), 'utf8'));
  const result = createAgentWalletOnboarding(input, config);
  assert.equal(result.schema, 'notforhumans-metamask-agent-wallet-onboarding/1');
  assert.equal(result.status, 'prepared-non-executing');
  assert.equal(result.roles.operator, input.agent);
  assert.equal(result.roles.agent, input.agent);
  assert.equal(result.roles.recipient, input.agent);
  assert.equal(result.contracts.claimMinter, config.contracts.claimMinter);
  assert.equal(result.contracts.token, config.contracts.token);
  assert.equal(result.contracts.agentState, config.contracts.agentState);
  assert.equal(result.contracts.marketplace, config.contracts.marketplace);
  assert.ok(result.policy.allowlist.includes(config.contracts.claimMinter));
  assert.equal(result.workflow.find(({ phase }) => phase === 'claim').maximumPaymentWei, '0');
  assert.deepEqual(result.workflow.find(({ phase }) => phase === 'claim').mcpTools, ['claim_as_agent']);
  assert.equal(result.workflow.find(({ phase }) => phase === 'claim').submission, 'direct-agent-wallet');
  assert.equal(result.submissionRecovery.primary.mode, 'direct-agent-wallet');
  assert.equal(result.submissionRecovery.primary.preBroadcastRetryLimit, 1);
  assert.equal(result.submissionRecovery.fallback.mode, 'agent-owned-erc4337');
  assert.equal(result.submissionRecovery.fallback.entryPoint, config.accountAbstraction.entryPoint);
  assert.equal(result.submissionRecovery.fallback.factory, config.accountAbstraction.factory);
  assert.equal(result.submissionRecovery.fallback.factorySalt, 19);
  assert.equal(result.submissionRecovery.fallback.paymasterAndData, '0x');
  assert.equal(result.submissionRecovery.fallback.claimRecipientRemainsAgent, true);
  assert.equal(result.submissionRecovery.fallback.sameAgentSignerRequired, true);
  assert.equal(result.submissionRecovery.fallback.createAnotherServerWallet, false);
  assert.match(result.submissionRecovery.fallback.walletSelectionCommand, new RegExp(input.agent, 'i'));
  assert.equal(result.submissionRecovery.fallback.walletAddressVerificationCommand, 'mm wallet address --toon');
  assert.match(result.submissionRecovery.fallback.instruction, /do not call mm wallet send-transaction/i);
  assert.match(result.submissionRecovery.fallback.instruction, /eth_sendUserOperation/);
  assert.equal(result.authority.separateNfhHumanApprovalRequired, false);
  assert.equal(result.authority.adapterExecutionAuthorized, false);
});

test('a fully configured V19 target prepares a Guard policy without executing it', async () => {
  const base = JSON.parse(await readFile(new URL('../config/sepolia.json', import.meta.url), 'utf8'));
  const config = {
    ...base,
    status: 'v19-sepolia-deployed',
    contracts: {
      token: '0x1919191919191919191919191919191919191901',
      claimMinter: '0x1919191919191919191919191919191919191902',
      agentState: '0x1919191919191919191919191919191919191903',
      marketplace: '0x1919191919191919191919191919191919191904',
      weth: base.contracts.weth,
    },
  };
  const result = createAgentWalletOnboarding(input, config);
  assert.equal(result.status, 'prepared-non-executing');
  assert.equal(result.policy.rolling24hUsd, '25');
  assert.deepEqual(result.policy.serviceDefaultChains, SERVICE_DEFAULT_CHAINS);
  assert.match(result.policy.policyYaml, /wallet_address: "0x2222222222222222222222222222222222222222"/);
  assert.match(result.policy.policyYaml, /0x1919191919191919191919191919191919191904/);
  assert.match(result.policy.policyYaml, /rolling_24h: 25/);
  assert.equal(result.workflow.find(({ phase }) => phase === 'policy-apply').executable, false);
  assert.equal(result.authority.adapterExecutionAuthorized, false);
  assert.equal(result.authority.negotiationAndPreparationMayBeAutonomous, true);
  assert.equal(result.authority.signingAndSubmissionRequireExternalWalletAndHostPolicy, true);
  assert.equal(result.authority.separateNfhHumanApprovalRequired, false);
});

test('funded-agent onboarding rejects split roles, another recipient, and invalid budgets', async () => {
  const config = JSON.parse(await readFile(new URL('../config/sepolia.json', import.meta.url), 'utf8'));
  assert.throws(
    () => createAgentWalletOnboarding({ ...input, operator: '0x1111111111111111111111111111111111111111' }, config),
    /one persistent wallet/i,
  );
  assert.throws(
    () => createAgentWalletOnboarding({ ...input, recipient: '0x4444444444444444444444444444444444444444' }, config),
    /one persistent wallet/i,
  );
  assert.throws(
    () => createAgentWalletOnboarding({ ...input, rolling24hUsd: 'unlimited' }, config),
    /rolling24hUsd/i,
  );
});

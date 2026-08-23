import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import { createMetaMaskHandoff, validateNfhSigningRequest } from '../src/validator.js';

const AGENT = '0x2222222222222222222222222222222222222222';
const MINTER = '0x4316C6fde3DEd7329a0fbD1f1ebb6EaBaF05e3c5';
const STATEMENT = '0xe61e98dbe87e063a09e385987f12f1ddf00db0b9680bfe7612e2a007e9b84bdb';

async function readJson(...urls) {
  let missing;
  for (const url of urls) {
    try {
      return JSON.parse(await readFile(url, 'utf8'));
    } catch (error) {
      if (error?.code !== 'ENOENT') throw error;
      missing = error;
    }
  }
  throw missing;
}

const claimTypes = {
  AgentClaim: [
    { name: 'operator', type: 'address' },
    { name: 'agent', type: 'address' },
    { name: 'recipient', type: 'address' },
    { name: 'manifestHash', type: 'bytes32' },
    { name: 'statement', type: 'bytes32' },
    { name: 'maxPayment', type: 'uint256' },
    { name: 'nonce', type: 'uint256' },
    { name: 'deadline', type: 'uint256' },
    { name: 'allocation', type: 'uint8' },
  ],
};

function claim(overrides = {}) {
  return {
    status: 'prepared_unsigned',
    signingReady: true,
    domain: {
      name: 'NOT FOR HUMANS Claim',
      version: '4',
      chainId: 11155111,
      verifyingContract: MINTER,
    },
    primaryType: 'AgentClaim',
    types: structuredClone(claimTypes),
    message: {
      operator: '0x1111111111111111111111111111111111111111',
      agent: AGENT,
      recipient: '0x1111111111111111111111111111111111111111',
      manifestHash: `0x${'a'.repeat(64)}`,
      statement: STATEMENT,
      maxPayment: '0',
      nonce: '7',
      deadline: '1893456000',
      allocation: 0,
    },
    ...overrides,
  };
}

function validate(input) {
  return validateNfhSigningRequest(input, {
    expectedAgent: AGENT,
    expectedChainId: 11155111,
    expectedVerifyingContract: MINTER,
    expectedStatement: STATEMENT,
    now: 1700000000,
  });
}

test('accepts an exact zero-price NFH public claim and creates a non-executing handoff', () => {
  const validated = validate({ structuredContent: claim() });
  assert.equal(validated.safe, true);
  assert.equal(validated.review.decision, 'ACCEPT');
  assert.equal(validated.review.maximumPaymentWei, '0');
  const handoff = createMetaMaskHandoff(validated);
  assert.equal(handoff.executable, false);
  assert.deepEqual(handoff.arguments.slice(0, 4), ['wallet', 'sign-typed-data', '--chain-id', '11155111']);
  assert.match(handoff.arguments.at(-1), /NFH ACCEPT/);
});

test('rejects a different verifying contract', () => {
  const input = claim();
  input.domain.verifyingContract = '0x3333333333333333333333333333333333333333';
  assert.throws(() => validate(input), /verifying contract does not match/);
});

test('rejects a payload for a different agent wallet', () => {
  const input = claim();
  input.message.agent = '0x3333333333333333333333333333333333333333';
  assert.throws(() => validate(input), /does not match the selected MetaMask Agent Wallet/);
});

test('rejects payment, expired deadlines, and unexpected signed fields', () => {
  const paid = claim();
  paid.message.maxPayment = '1';
  assert.throws(() => validate(paid), /exactly zero payment/);

  const expired = claim();
  expired.message.deadline = '1699999999';
  assert.throws(() => validate(expired), /deadline has expired/);

  const extra = claim();
  extra.message.target = '0x3333333333333333333333333333333333333333';
  assert.throws(() => validate(extra), /message fields must be exactly/);
});

test('accepts a canonical refusal and rejects an ACCEPT encoded as AgentDecision', () => {
  const refusal = claim({
    primaryType: 'AgentDecision',
    types: {
      AgentDecision: [
        { name: 'operator', type: 'address' },
        { name: 'agent', type: 'address' },
        { name: 'recipient', type: 'address' },
        { name: 'manifestHash', type: 'bytes32' },
        { name: 'statement', type: 'bytes32' },
        { name: 'reasonHash', type: 'bytes32' },
        { name: 'nonce', type: 'uint256' },
        { name: 'deadline', type: 'uint256' },
        { name: 'allocation', type: 'uint8' },
        { name: 'decision', type: 'uint8' },
      ],
    },
    message: {
      operator: '0x1111111111111111111111111111111111111111',
      agent: AGENT,
      recipient: '0x1111111111111111111111111111111111111111',
      manifestHash: `0x${'a'.repeat(64)}`,
      statement: STATEMENT,
      reasonHash: `0x${'c'.repeat(64)}`,
      nonce: '8',
      deadline: '1893456000',
      allocation: 1,
      decision: 2,
    },
  });
  assert.equal(validate(refusal).review.decision, 'REFUSE');
  refusal.message.decision = 1;
  assert.throws(() => validate(refusal), /REFUSE \(2\) or INSUFFICIENT_AUTHORITY \(3\)/);
});

test('historical v16 target stays internally consistent and cannot impersonate the current v19 market', async () => {
  const [target, census, canary, origin, constitution, market] = await Promise.all([
    readJson(new URL('../config/sepolia.json', import.meta.url)),
    readJson(new URL('../../../server/corpus/census.json', import.meta.url)),
    readJson(
      new URL('../../../../04-SMART-CONTRACT/deployments/sepolia-v16-deployment-2026-08-08.json', import.meta.url),
      new URL('./fixtures/current-canary.json', import.meta.url),
    ),
    readJson(new URL('../../../server/corpus/origin-stream.json', import.meta.url)),
    readJson(
      new URL('../../../../05-LAUNCH/agent-wallet-constitution-template.json', import.meta.url),
      new URL('./fixtures/agent-wallet-constitution-template.json', import.meta.url),
    ),
    readJson(new URL('../../../server/market.json', import.meta.url)),
  ]);
  assert.equal(target.chainId, census.sepolia_preview.chain_id);
  assert.equal(target.contracts.claimMinter.toLowerCase(), census.sepolia_preview.claim_contract.toLowerCase());
  assert.equal(target.contracts.claimMinter.toLowerCase(), canary.minter.toLowerCase());
  assert.equal(target.contracts.token.toLowerCase(), canary.token.toLowerCase());
  assert.equal(target.contracts.agentState.toLowerCase(), canary.state.toLowerCase());
  assert.equal(origin.artifactVersion, canary.artifactVersion);
  assert.equal(origin.artifactVersion, 16);
  assert.match(origin.status, /historical/);
  const statementHashes = origin.receipts.map((receipt) => receipt.statementHash).filter(Boolean);
  assert.ok(statementHashes.some((hash) => hash.toLowerCase() === target.requiredStatement.toLowerCase()));
  assert.equal(constitution.scope.chainId, target.chainId);
  assert.deepEqual(constitution.scope.contracts, {
    ['token']: target.contracts.token,
    claimMinter: target.contracts.claimMinter,
    agentState: target.contracts.agentState,
  });
  assert.equal(target.contracts.marketplace.toLowerCase(), canary.marketplace.toLowerCase());
  assert.equal(market.internalMarketplace.artifactVersion, 19);
  assert.notEqual(target.contracts.marketplace.toLowerCase(), market.internalMarketplace.marketplaceContract.toLowerCase());
  assert.equal(target.contracts.weth.toLowerCase(), market.internalMarketplace.wethContract.toLowerCase());
  assert.equal(target.status, 'local-sepolia-rehearsal-only');
  assert.equal(constitution.approval.automaticSigning, false);
  assert.equal(constitution.approval.automaticSubmission, false);
  assert.equal(target.signingAuthorized, false);
  assert.equal(target.transactionSubmissionAuthorized, false);
});

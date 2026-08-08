import assert from 'node:assert/strict';
import test from 'node:test';
import { createAutonomyRehearsalPlan, createTransactionHandoffs, SELECTORS } from '../src/marketplace.js';

const request = (overrides = {}) => ({
  schema: 'notforhumans-autonomy-rehearsal-request/1',
  chainId: 11155111,
  tokenId: '0',
  sellerAgent: '0x1111111111111111111111111111111111111111',
  buyerAgent: '0x2222222222222222222222222222222222222222',
  sellerPrincipal: 'nfh-lab',
  buyerPrincipal: 'nfh-lab',
  syntheticSelfTradeRehearsal: true,
  contracts: {
    token: '0x3333333333333333333333333333333333333333',
    marketplace: '0x4444444444444444444444444444444444444444',
    weth: '0x5555555555555555555555555555555555555555',
  },
  initialAskWei: '100000000000000000',
  reserveWeth: '60000000000000000',
  openingOfferWeth: '40000000000000000',
  buyerMaximumWeth: '80000000000000000',
  buyerWethBalanceWei: '0',
  expiry: '1700600000',
  ...overrides,
});

test('creates a disclosed v14 two-agent Sepolia negotiation and exact transaction sequence', () => {
  const plan = createAutonomyRehearsalPlan(request(), { now: 1700000000 });
  assert.equal(plan.artifactVersion, 14);
  assert.equal(plan.negotiation.agreed, true);
  assert.equal(plan.classification, 'synthetic-self-trade-rehearsal-not-market-activity');
  assert.deepEqual(plan.steps.map((step) => step.function), [
    'approve', 'list', 'deposit', 'approve', 'makeOffer', 'cancelOffer', 'approve', 'makeOffer', 'acceptOffer', 'approve',
  ]);
  assert.ok(plan.steps[0].payload.data.startsWith(SELECTORS.approve));
  assert.ok(plan.steps[1].payload.data.startsWith(SELECTORS.list));
  assert.ok(plan.steps[2].payload.data.startsWith(SELECTORS.deposit));
  assert.equal(plan.steps[2].value, plan.finalPriceWeth);
  assert.ok(plan.steps.at(-2).payload.data.startsWith(SELECTORS.acceptOffer));
  assert.equal(plan.steps[0].args[1], '0');
});

test('transaction handoffs remain non-executing and bind each required signer', () => {
  const plan = createAutonomyRehearsalPlan(request(), { now: 1700000000 });
  const handoffs = createTransactionHandoffs(plan);
  assert.equal(handoffs.length, plan.steps.length);
  assert.ok(handoffs.every((item) => item.executable === false));
  assert.deepEqual(handoffs[0].arguments.slice(0, 4), ['wallet', 'send-transaction', '--chain-id', '11155111']);
  assert.equal(handoffs[0].signer, request().sellerAgent);
  assert.equal(handoffs[2].signer, request().buyerAgent);
});

test('does not wrap Sepolia ETH when the measured buyer WETH balance covers settlement', () => {
  const plan = createAutonomyRehearsalPlan(request({ buyerWethBalanceWei: '80000000000000000' }), { now: 1700000000 });
  assert.equal(plan.steps.some((step) => step.function === 'deposit'), false);
});

test('rejects mainnet, identical wallets, and undisclosed same-principal trading', () => {
  assert.throws(() => createAutonomyRehearsalPlan(request({ chainId: 1 }), { now: 1700000000 }), /Sepolia-only/);
  assert.throws(() => createAutonomyRehearsalPlan(request({ buyerAgent: request().sellerAgent }), { now: 1700000000 }), /distinct wallets/);
  assert.throws(() => createAutonomyRehearsalPlan(request({ syntheticSelfTradeRehearsal: false }), { now: 1700000000 }), /explicitly disclosed/);
});

test('rejects economic bounds that cannot reach agreement', () => {
  assert.throws(() => createAutonomyRehearsalPlan(request({ buyerMaximumWeth: '50000000000000000' }), { now: 1700000000 }), /buyer maximum is below seller reserve/);
  assert.throws(() => createAutonomyRehearsalPlan(request({ reserveWeth: '110000000000000000' }), { now: 1700000000 }), /reserve cannot exceed/);
  assert.throws(() => createAutonomyRehearsalPlan(request({ openingOfferWeth: '90000000000000000' }), { now: 1700000000 }), /opening offer cannot exceed/);
});

test('rejects expired or excessively long standing authority', () => {
  assert.throws(() => createAutonomyRehearsalPlan(request({ expiry: '1699999999' }), { now: 1700000000 }), /future/);
  assert.throws(() => createAutonomyRehearsalPlan(request({ expiry: String(1700000000 + 8 * 86400) }), { now: 1700000000 }), /seven days/);
});

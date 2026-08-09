import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import { createAutonomyRehearsalPlan, createTransactionHandoffs, SELECTORS } from '../src/marketplace.js';

const target = JSON.parse(await readFile(new URL('../config/sepolia.json', import.meta.url), 'utf8'));

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
    token: target.contracts.token,
    marketplace: target.contracts.marketplace,
    weth: target.contracts.weth,
  },
  initialAskWei: '100000000000000000',
  reserveWeth: '60000000000000000',
  openingOfferWeth: '40000000000000000',
  buyerMaximumWeth: '80000000000000000',
  buyerWethBalanceWei: '0',
  expiry: '1700600000',
  ...overrides,
});

const options = (overrides = {}) => ({ now: 1700000000, target, ...overrides });

test('creates a disclosed v16 two-agent Sepolia negotiation and exact transaction sequence', () => {
  const plan = createAutonomyRehearsalPlan(request(), options());
  assert.equal(plan.artifactVersion, 16);
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
  const plan = createAutonomyRehearsalPlan(request(), options());
  const handoffs = createTransactionHandoffs(plan);
  assert.equal(handoffs.length, plan.steps.length);
  assert.ok(handoffs.every((item) => item.executable === false));
  assert.deepEqual(handoffs[0].arguments.slice(0, 4), ['wallet', 'send-transaction', '--chain-id', '11155111']);
  assert.equal(handoffs[0].signer, request().sellerAgent);
  assert.equal(handoffs[2].signer, request().buyerAgent);
});

test('does not wrap Sepolia ETH when the measured buyer WETH balance covers settlement', () => {
  const plan = createAutonomyRehearsalPlan(request({ buyerWethBalanceWei: '80000000000000000' }), options());
  assert.equal(plan.steps.some((step) => step.function === 'deposit'), false);
});

test('rejects mainnet, identical wallets, and undisclosed same-principal trading', () => {
  assert.throws(() => createAutonomyRehearsalPlan(request({ chainId: 1 }), options()), /Sepolia-only|pinned target/);
  assert.throws(() => createAutonomyRehearsalPlan(request({ buyerAgent: request().sellerAgent }), options()), /distinct wallets/);
  assert.throws(() => createAutonomyRehearsalPlan(request({ syntheticSelfTradeRehearsal: false }), options()), /explicitly disclosed/);
});

test('rejects economic bounds that cannot reach agreement', () => {
  assert.throws(() => createAutonomyRehearsalPlan(request({ buyerMaximumWeth: '50000000000000000' }), options()), /buyer maximum is below seller reserve/);
  assert.throws(() => createAutonomyRehearsalPlan(request({ reserveWeth: '110000000000000000' }), options()), /reserve cannot exceed/);
  assert.throws(() => createAutonomyRehearsalPlan(request({ openingOfferWeth: '90000000000000000' }), options()), /opening offer cannot exceed/);
});

test('rejects expired or excessively long standing authority', () => {
  assert.throws(() => createAutonomyRehearsalPlan(request({ expiry: '1699999999' }), options()), /future/);
  assert.throws(() => createAutonomyRehearsalPlan(request({ expiry: String(1700000000 + 8 * 86400) }), options()), /seven days/);
});

test('rejects caller-supplied marketplace, token, or WETH targets that differ from the pinned target', () => {
  for (const key of ['marketplace', 'token', 'weth']) {
    const contracts = { ...request().contracts, [key]: '0x9999999999999999999999999999999999999999' };
    assert.throws(() => createAutonomyRehearsalPlan(request({ contracts }), options()), /pinned target/);
  }
  assert.throws(() => createAutonomyRehearsalPlan(request(), { now: 1700000000 }), /pinned target/);
});

test('rejects values that cannot fit one ABI uint256 word', () => {
  const overflow = (1n << 256n).toString();
  assert.throws(() => createAutonomyRehearsalPlan(request({ tokenId: overflow }), options()), /uint256/);
  assert.throws(() => createAutonomyRehearsalPlan(request({ initialAskWei: overflow }), options()), /uint256/);
});

const ADDRESS = /^0x[0-9a-fA-F]{40}$/;
const ZERO_ADDRESS = `0x${'0'.repeat(40)}`;

const SELECTORS = Object.freeze({
  approve: '0x095ea7b3',
  deposit: '0xd0e30db0',
  list: '0xca690ea2',
  cancelListing: '0x305a67a8',
  buy: '0xd96a094a',
  makeOffer: '0x4936f370',
  cancelOffer: '0xef706adf',
  acceptOffer: '0x918d407d',
});

function fail(message) {
  throw new Error(`Unsafe NFH marketplace request: ${message}`);
}

function address(value, label) {
  if (typeof value !== 'string' || !ADDRESS.test(value) || value.toLowerCase() === ZERO_ADDRESS) {
    fail(`${label} must be a nonzero EVM address.`);
  }
  return value.toLowerCase();
}

function uint(value, label) {
  const normalized = typeof value === 'number' && Number.isSafeInteger(value) ? String(value) : value;
  if (typeof normalized !== 'string' || !/^\d+$/.test(normalized)) fail(`${label} must be an unsigned decimal integer.`);
  return BigInt(normalized);
}

function word(value) {
  return value.toString(16).padStart(64, '0');
}

function addressWord(value) {
  return address(value, 'encoded address').slice(2).padStart(64, '0');
}

function calldata(selector, words = []) {
  return `${selector}${words.join('')}`;
}

function transaction({ signer, role, contract, functionName, args, value = 0n, intent }) {
  let data;
  if (functionName === 'approve') data = calldata(SELECTORS.approve, [addressWord(args[0]), word(BigInt(args[1]))]);
  else if (functionName === 'deposit') data = SELECTORS.deposit;
  else if (functionName === 'list') data = calldata(SELECTORS.list, args.map((item) => word(BigInt(item))));
  else if (functionName === 'cancelListing') data = calldata(SELECTORS.cancelListing, [word(BigInt(args[0]))]);
  else if (functionName === 'buy') data = calldata(SELECTORS.buy, [word(BigInt(args[0]))]);
  else if (functionName === 'makeOffer') data = calldata(SELECTORS.makeOffer, args.map((item) => word(BigInt(item))));
  else if (functionName === 'cancelOffer') data = calldata(SELECTORS.cancelOffer, [word(BigInt(args[0]))]);
  else if (functionName === 'acceptOffer') data = calldata(SELECTORS.acceptOffer, [word(BigInt(args[0])), addressWord(args[1])]);
  else fail(`unsupported function ${functionName}.`);
  return {
    signer,
    role,
    contract,
    function: functionName,
    args: args.map(String),
    value: value.toString(),
    payload: { to: contract, value: `0x${value.toString(16)}`, data },
    intent,
  };
}

function negotiate({ initialAsk, reserve, openingOffer, buyerMaximum, maxRounds = 12 }) {
  if (buyerMaximum < reserve) {
    return { agreed: false, reason: 'buyer maximum is below seller reserve', rounds: [] };
  }
  let ask = initialAsk;
  let offer = openingOffer;
  const rounds = [{ round: 0, sellerAskWeth: ask.toString(), buyerOfferWeth: offer.toString() }];
  if (offer >= ask) return { agreed: true, finalPriceWeth: ask.toString(), rounds };
  for (let round = 1; round <= maxRounds && offer < ask; round += 1) {
    const gap = ask - offer;
    const concession = gap / 3n > 0n ? gap / 3n : 1n;
    const nextAsk = ask - concession < reserve ? reserve : ask - concession;
    const nextOffer = offer + concession > buyerMaximum ? buyerMaximum : offer + concession;
    if (nextAsk === ask && nextOffer === offer) break;
    ask = nextAsk;
    offer = nextOffer;
    if (ask <= buyerMaximum) {
      offer = ask;
      rounds.push({ round, sellerAskWeth: ask.toString(), buyerOfferWeth: offer.toString(), accepted: true });
      break;
    }
    rounds.push({ round, sellerAskWeth: ask.toString(), buyerOfferWeth: offer.toString() });
  }
  if (offer < ask) return { agreed: false, reason: 'price bounds did not cross', rounds };
  return { agreed: true, finalPriceWeth: ask.toString(), rounds };
}

export function createAutonomyRehearsalPlan(input, { now = Math.floor(Date.now() / 1000) } = {}) {
  if (!input || typeof input !== 'object' || Array.isArray(input)) fail('input must be an object.');
  if (input.schema !== 'notforhumans-autonomy-rehearsal-request/1') fail('unsupported request schema.');
  const chainId = uint(input.chainId, 'chainId');
  if (chainId !== 11155111n) fail('v14 autonomy rehearsals are Sepolia-only.');
  const tokenId = uint(input.tokenId, 'tokenId');
  const seller = address(input.sellerAgent, 'sellerAgent');
  const buyer = address(input.buyerAgent, 'buyerAgent');
  if (seller === buyer) fail('sellerAgent and buyerAgent must be distinct wallets.');
  const marketplace = address(input.contracts?.marketplace, 'contracts.marketplace');
  const token = address(input.contracts?.token, 'contracts.token');
  const weth = address(input.contracts?.weth, 'contracts.weth');
  const sellerPrincipal = String(input.sellerPrincipal || '').trim();
  const buyerPrincipal = String(input.buyerPrincipal || '').trim();
  if (!sellerPrincipal || !buyerPrincipal) fail('sellerPrincipal and buyerPrincipal are required for anti-wash-trading classification.');
  const samePrincipal = sellerPrincipal === buyerPrincipal;
  if (samePrincipal && input.syntheticSelfTradeRehearsal !== true) {
    fail('same-principal agents may interact only in an explicitly disclosed synthetic Sepolia rehearsal.');
  }
  const initialAsk = uint(input.initialAskWei, 'initialAskWei');
  const reserve = uint(input.reserveWeth, 'reserveWeth');
  const openingOffer = uint(input.openingOfferWeth, 'openingOfferWeth');
  const buyerMaximum = uint(input.buyerMaximumWeth, 'buyerMaximumWeth');
  const buyerWethBalance = uint(input.buyerWethBalanceWei, 'buyerWethBalanceWei');
  const expiry = uint(input.expiry, 'expiry');
  if (initialAsk === 0n || reserve === 0n || openingOffer === 0n || buyerMaximum === 0n) fail('all prices must be nonzero.');
  if (reserve > initialAsk) fail('seller reserve cannot exceed initial ask.');
  if (openingOffer > buyerMaximum) fail('opening offer cannot exceed buyer maximum.');
  if (expiry <= BigInt(now)) fail('expiry must be in the future.');
  if (expiry > BigInt(now + 7 * 24 * 60 * 60)) fail('expiry cannot exceed seven days in the v14 rehearsal.');

  const negotiation = negotiate({ initialAsk, reserve, openingOffer, buyerMaximum });
  if (!negotiation.agreed) fail(`negotiation failed: ${negotiation.reason}.`);
  const finalPrice = BigInt(negotiation.finalPriceWeth);
  const steps = [
    transaction({ signer: seller, role: 'seller', contract: token, functionName: 'approve', args: [marketplace, tokenId], intent: `Approve only NFH #${tokenId} for the v14 marketplace` }),
    transaction({ signer: seller, role: 'seller', contract: marketplace, functionName: 'list', args: [tokenId, initialAsk, expiry], intent: `List NFH #${tokenId} for ${initialAsk} wei until ${expiry}` }),
  ];
  if (buyerWethBalance < finalPrice) {
    const shortfall = finalPrice - buyerWethBalance;
    steps.push(transaction({ signer: buyer, role: 'buyer', contract: weth, functionName: 'deposit', args: [], value: shortfall, intent: `Wrap exactly ${shortfall} Sepolia ETH to cover the measured WETH shortfall` }));
  }
  steps.push(
    transaction({ signer: buyer, role: 'buyer', contract: weth, functionName: 'approve', args: [marketplace, openingOffer], intent: `Approve exactly ${openingOffer} WETH units for the opening offer` }),
    transaction({ signer: buyer, role: 'buyer', contract: marketplace, functionName: 'makeOffer', args: [tokenId, openingOffer, expiry], intent: `Make opening WETH offer on NFH #${tokenId}` }),
  );
  if (finalPrice !== openingOffer) {
    steps.push(
      transaction({ signer: buyer, role: 'buyer', contract: marketplace, functionName: 'cancelOffer', args: [tokenId], intent: `Cancel the superseded opening offer on NFH #${tokenId}` }),
      transaction({ signer: buyer, role: 'buyer', contract: weth, functionName: 'approve', args: [marketplace, finalPrice], intent: `Approve exactly ${finalPrice} WETH units for the negotiated offer` }),
      transaction({ signer: buyer, role: 'buyer', contract: marketplace, functionName: 'makeOffer', args: [tokenId, finalPrice, expiry], intent: `Make negotiated WETH offer on NFH #${tokenId}` }),
    );
  }
  steps.push(
    transaction({ signer: seller, role: 'seller', contract: marketplace, functionName: 'acceptOffer', args: [tokenId, buyer], intent: `Accept the exact negotiated offer from ${buyer} for NFH #${tokenId}` }),
    transaction({ signer: buyer, role: 'buyer', contract: weth, functionName: 'approve', args: [marketplace, 0n], intent: 'Revoke any residual marketplace WETH allowance after settlement' }),
  );

  return {
    schema: 'notforhumans-autonomy-rehearsal-plan/1',
    artifactVersion: 14,
    protocolVersion: '5.2',
    environment: 'sepolia-rehearsal',
    classification: samePrincipal ? 'synthetic-self-trade-rehearsal-not-market-activity' : 'independent-principals-rehearsal',
    chainId: Number(chainId),
    tokenId: tokenId.toString(),
    contracts: { marketplace: input.contracts.marketplace, ['token']: input.contracts.token, weth: input.contracts.weth },
    agents: {
      seller: { address: input.sellerAgent, principal: sellerPrincipal },
      buyer: { address: input.buyerAgent, principal: buyerPrincipal },
    },
    controls: {
      guardModeRequired: true,
      maximumSettlementWorkflowsPerDay: 1,
      maximumRehearsalTransactions: steps.length,
      noLeverage: true,
      exactRoyaltyRequired: true,
      emergencyRevocationRequired: true,
      automaticExecutionAuthorized: false,
    },
    negotiation,
    finalPriceWeth: finalPrice.toString(),
    expiry: expiry.toString(),
    steps,
  };
}

export function createTransactionHandoffs(plan) {
  if (plan?.schema !== 'notforhumans-autonomy-rehearsal-plan/1' || !Array.isArray(plan.steps)) fail('validated v14 plan required.');
  return plan.steps.map((step, index) => ({
    sequence: index + 1,
    executable: false,
    reason: 'Exact raw transaction review is required before this handoff may be executed.',
    program: 'mm',
    arguments: [
      'wallet', 'send-transaction', '--chain-id', String(plan.chainId),
      '--payload', JSON.stringify(step.payload), '--wait', '--intent', step.intent,
    ],
    signer: step.signer,
    role: step.role,
    function: step.function,
    payload: step.payload,
    intent: step.intent,
  }));
}

export { SELECTORS };

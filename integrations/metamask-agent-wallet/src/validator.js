const ADDRESS = /^0x[0-9a-fA-F]{40}$/;
const BYTES32 = /^0x[0-9a-fA-F]{64}$/;
const ZERO_ADDRESS = `0x${'0'.repeat(40)}`;
const ZERO_BYTES32 = `0x${'0'.repeat(64)}`;

const CLAIM_FIELDS = [
  ['operator', 'address'],
  ['agent', 'address'],
  ['recipient', 'address'],
  ['manifestHash', 'bytes32'],
  ['statement', 'bytes32'],
  ['maxPayment', 'uint256'],
  ['nonce', 'uint256'],
  ['deadline', 'uint256'],
  ['allocation', 'uint8'],
];

const DECISION_FIELDS = [
  ['operator', 'address'],
  ['agent', 'address'],
  ['recipient', 'address'],
  ['manifestHash', 'bytes32'],
  ['statement', 'bytes32'],
  ['reasonHash', 'bytes32'],
  ['nonce', 'uint256'],
  ['deadline', 'uint256'],
  ['allocation', 'uint8'],
  ['decision', 'uint8'],
];

function fail(message) {
  throw new Error(`Unsafe NFH signing request: ${message}`);
}

function isObject(value) {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function exactKeys(value, expected, label) {
  const actual = Object.keys(value).sort();
  const wanted = [...expected].sort();
  if (JSON.stringify(actual) !== JSON.stringify(wanted)) {
    fail(`${label} fields must be exactly ${wanted.join(', ')}; received ${actual.join(', ')}.`);
  }
}

function requireAddress(value, label) {
  if (typeof value !== 'string' || !ADDRESS.test(value) || value.toLowerCase() === ZERO_ADDRESS) {
    fail(`${label} must be a nonzero EVM address.`);
  }
  return value.toLowerCase();
}

function requireBytes32(value, label) {
  if (typeof value !== 'string' || !BYTES32.test(value) || value.toLowerCase() === ZERO_BYTES32) {
    fail(`${label} must be a nonzero bytes32 value.`);
  }
  return value.toLowerCase();
}

function uintString(value, label) {
  if (typeof value === 'number') {
    if (!Number.isSafeInteger(value) || value < 0) fail(`${label} must be an unsigned safe integer or decimal string.`);
    return String(value);
  }
  if (typeof value !== 'string' || !/^\d+$/.test(value)) fail(`${label} must be an unsigned decimal integer.`);
  return value;
}

function canonicalTypes(primaryType) {
  const fields = primaryType === 'AgentClaim' ? CLAIM_FIELDS : DECISION_FIELDS;
  return { [primaryType]: fields.map(([name, type]) => ({ name, type })) };
}

function extractEnvelope(input) {
  if (!isObject(input)) fail('input must be a JSON object.');
  if (isObject(input.structuredContent)) return input.structuredContent;
  if (isObject(input.result?.structuredContent)) return input.result.structuredContent;
  return input;
}

export function validateNfhSigningRequest(input, {
  expectedAgent,
  expectedChainId,
  expectedVerifyingContract,
  expectedStatement,
  now = Math.floor(Date.now() / 1000),
} = {}) {
  const envelope = extractEnvelope(input);
  const { domain, primaryType, types, message } = envelope;

  if (envelope.signingReady === false || envelope.status === 'draft_unbound') {
    fail('the MCP marked this payload as unbound or not ready for signing.');
  }
  if (!isObject(domain) || !isObject(types) || !isObject(message)) {
    fail('domain, types, and message must all be objects.');
  }
  if (primaryType !== 'AgentClaim' && primaryType !== 'AgentDecision') {
    fail('primaryType must be AgentClaim or AgentDecision.');
  }

  exactKeys(domain, ['name', 'version', 'chainId', 'verifyingContract'], 'domain');
  if (domain.name !== 'NOT FOR HUMANS Claim' || domain.version !== '4') {
    fail('domain name/version must be NOT FOR HUMANS Claim / 4.');
  }
  const chainId = uintString(domain.chainId, 'domain.chainId');
  if (String(expectedChainId) !== chainId) fail(`chain ID ${chainId} does not match configured chain ${expectedChainId}.`);
  const verifyingContract = requireAddress(domain.verifyingContract, 'domain.verifyingContract');
  if (verifyingContract !== requireAddress(expectedVerifyingContract, 'configured verifying contract')) {
    fail('verifying contract does not match the configured NFH claim minter.');
  }

  exactKeys(types, [primaryType], 'types');
  if (JSON.stringify(types) !== JSON.stringify(canonicalTypes(primaryType))) {
    fail(`${primaryType} type definition does not match the canonical NFH field order and types.`);
  }

  const expectedFields = (primaryType === 'AgentClaim' ? CLAIM_FIELDS : DECISION_FIELDS).map(([name]) => name);
  exactKeys(message, expectedFields, 'message');
  const operator = requireAddress(message.operator, 'message.operator');
  const agent = requireAddress(message.agent, 'message.agent');
  const recipient = requireAddress(message.recipient, 'message.recipient');
  if (operator === agent) fail('operator and agent must be different addresses.');
  if (agent !== requireAddress(expectedAgent, 'expected MetaMask agent wallet')) {
    fail('message.agent does not match the selected MetaMask Agent Wallet address.');
  }

  const manifestHash = requireBytes32(message.manifestHash, 'message.manifestHash');
  const statement = requireBytes32(message.statement, 'message.statement');
  if (statement !== requireBytes32(expectedStatement, 'configured required statement')) {
    fail('statement hash does not match the configured NFH statement.');
  }
  const nonce = uintString(message.nonce, 'message.nonce');
  const deadline = uintString(message.deadline, 'message.deadline');
  if (BigInt(deadline) <= BigInt(now)) fail('deadline has expired.');
  const allocation = Number(uintString(message.allocation, 'message.allocation'));

  let decision;
  if (primaryType === 'AgentClaim') {
    if (uintString(message.maxPayment, 'message.maxPayment') !== '0') fail('NFH primary claims must authorize exactly zero payment.');
    if (![0, 1, 2].includes(allocation)) fail('claim allocation must be 0, 1, or 2.');
    decision = 'ACCEPT';
  } else {
    if (![1, 2].includes(allocation)) fail('non-minting Census decisions require allocation 1 or 2.');
    requireBytes32(message.reasonHash, 'message.reasonHash');
    const decisionCode = Number(uintString(message.decision, 'message.decision'));
    if (decisionCode !== 2 && decisionCode !== 3) fail('AgentDecision must encode REFUSE (2) or INSUFFICIENT_AUTHORITY (3).');
    decision = decisionCode === 2 ? 'REFUSE' : 'INSUFFICIENT_AUTHORITY';
  }

  const typedData = { domain, types, primaryType, message };
  return {
    safe: true,
    typedData,
    review: {
      network: `eip155:${chainId}`,
      verifyingContract: domain.verifyingContract,
      primaryType,
      decision,
      operator: message.operator,
      agent: message.agent,
      recipient: message.recipient,
      manifestHash,
      statement,
      allocation,
      nonce,
      deadline,
      maximumPaymentWei: primaryType === 'AgentClaim' ? '0' : null,
      requiresSeparateRecipientSignature: operator !== recipient,
    },
  };
}

export function createMetaMaskHandoff(validated) {
  if (!validated?.safe || !isObject(validated.typedData) || !isObject(validated.review)) {
    fail('a validated NFH request is required before creating a MetaMask handoff.');
  }
  const intent = `NFH ${validated.review.decision} on ${validated.review.network}; allocation ${validated.review.allocation}; zero primary payment`;
  return {
    executable: false,
    reason: 'This handoff is intentionally not executed. Show the complete review and obtain explicit human approval before signing.',
    program: 'mm',
    arguments: [
      'wallet',
      'sign-typed-data',
      '--chain-id',
      String(validated.typedData.domain.chainId),
      '--payload',
      JSON.stringify(validated.typedData),
      '--wait',
      '--intent',
      intent,
    ],
    review: validated.review,
  };
}

export { CLAIM_FIELDS, DECISION_FIELDS };

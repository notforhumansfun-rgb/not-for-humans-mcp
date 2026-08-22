import {
  Interface,
  TypedDataEncoder,
  getAddress,
  getBytes,
  keccak256,
  verifyMessage,
  verifyTypedData,
} from 'ethers';
import { validateNfhSigningRequest } from './validator.js';

const ADDRESS = /^0x[0-9a-fA-F]{40}$/;
const BYTES32 = /^0x[0-9a-fA-F]{64}$/;
const SIGNATURE = /^0x[0-9a-fA-F]{130}$/;
const HEX_DATA = /^0x(?:[0-9a-fA-F]{2})*$/;
const EMPTY_CODE = '0x';

const minterInterface = new Interface([
  'function requiredStatement() view returns (bytes32)',
  'function paused() view returns (bool)',
  'function publicClaimsEnabled() view returns (bool)',
  'function usedNonces(address,uint256) view returns (bool)',
  'function claimDigest((address operator,address agent,address recipient,bytes32 manifestHash,bytes32 statement,uint256 maxPayment,uint256 nonce,uint256 deadline,uint8 allocation)) view returns (bytes32)',
  'function claim((address operator,address agent,address recipient,bytes32 manifestHash,bytes32 statement,uint256 maxPayment,uint256 nonce,uint256 deadline,uint8 allocation),bytes,bytes,bytes,bytes32[]) returns (uint256)',
  'event AgentClaimed(uint256 indexed tokenId,address indexed operator,address indexed agent,address recipient,uint8 allocation,bytes32 claimDigest)',
]);
const tokenInterface = new Interface([
  'function activeWalletClaimLimit() view returns (uint256)',
  'function walletClaimMinted(address) view returns (uint256)',
  'function ownerOf(uint256) view returns (address)',
]);
const factoryInterface = new Interface([
  'function accountImplementation() view returns (address)',
  'function getAddress(address owner,uint256 salt) view returns (address)',
  'function createAccount(address owner,uint256 salt) returns (address)',
]);
const accountInterface = new Interface([
  'function owner() view returns (address)',
  'function entryPoint() view returns (address)',
  'function execute(address dest,uint256 value,bytes func)',
]);
const entryPointInterface = new Interface([
  'function balanceOf(address) view returns (uint256)',
  'function getNonce(address,uint192) view returns (uint256)',
  'function getUserOpHash((address sender,uint256 nonce,bytes initCode,bytes callData,uint256 callGasLimit,uint256 verificationGasLimit,uint256 preVerificationGas,uint256 maxFeePerGas,uint256 maxPriorityFeePerGas,bytes paymasterAndData,bytes signature)) view returns (bytes32)',
]);

function fail(message) {
  throw new Error(`Unsafe NFH ERC-4337 route: ${message}`);
}

function sameAddress(left, right) {
  try {
    return getAddress(left) === getAddress(right);
  } catch {
    return false;
  }
}

function requireAddress(value, label) {
  if (typeof value !== 'string' || !ADDRESS.test(value) || /^0x0{40}$/i.test(value)) {
    fail(`${label} must be a nonzero EVM address.`);
  }
  return getAddress(value);
}

function requireBytes32(value, label) {
  if (typeof value !== 'string' || !BYTES32.test(value)) fail(`${label} must be bytes32.`);
  return value.toLowerCase();
}

function requireSignature(value, label) {
  if (typeof value !== 'string' || !SIGNATURE.test(value)) fail(`${label} must be one 65-byte ECDSA signature.`);
  return value;
}

function requireHexData(value, label) {
  if (typeof value !== 'string' || !HEX_DATA.test(value)) fail(`${label} must be even-length hex data.`);
  return value;
}

function extractEnvelope(input) {
  if (!input || typeof input !== 'object' || Array.isArray(input)) fail('the claim input must be a JSON object.');
  if (input.structuredContent && typeof input.structuredContent === 'object') return input.structuredContent;
  if (input.result?.structuredContent && typeof input.result.structuredContent === 'object') return input.result.structuredContent;
  return input;
}

function checkedConfig(config) {
  if (!config || typeof config !== 'object' || Array.isArray(config)) fail('the pinned V19 configuration is required.');
  if (config.status !== 'v19-sepolia-deployed' || config.chainId !== 11155111 || config.artifactVersion !== 19 || config.protocolVersion !== '5.3') {
    fail('only the pinned V19 Sepolia deployment is supported.');
  }
  const aa = config.accountAbstraction;
  if (!aa || aa.status !== 'verified-sepolia-fallback' || aa.entryPointVersion !== '0.6') {
    fail('the verified EntryPoint 0.6 fallback is not configured.');
  }
  if (aa.factorySalt !== 19 || aa.paymasterAndData !== '0x' || aa.gasSponsored !== false) {
    fail('the route must use V19 salt 19 with no paymaster or gas sponsor.');
  }
  if (aa.bundlerRpc !== 'https://public.pimlico.io/v2/11155111/rpc' || aa.publicRpc !== 'https://ethereum-sepolia-rpc.publicnode.com') {
    fail('the Sepolia RPC and bundler endpoints differ from the pinned rehearsal route.');
  }
  for (const [field, value] of Object.entries({
    entryPointRuntimeCodeHash: aa.entryPointRuntimeCodeHash,
    factoryRuntimeCodeHash: aa.factoryRuntimeCodeHash,
    accountImplementationRuntimeCodeHash: aa.accountImplementationRuntimeCodeHash,
    accountProxyRuntimeCodeHash: aa.accountProxyRuntimeCodeHash,
  })) requireBytes32(value, `config.accountAbstraction.${field}`);
  if (typeof aa.recommendedBootstrapFundingWei !== 'string' || !/^[1-9][0-9]*$/.test(aa.recommendedBootstrapFundingWei)) {
    fail('recommendedBootstrapFundingWei must be a positive decimal integer.');
  }
  return {
    config,
    aa,
    token: requireAddress(config.contracts?.token, 'config.contracts.token'),
    minter: requireAddress(config.contracts?.claimMinter, 'config.contracts.claimMinter'),
    entryPoint: requireAddress(aa.entryPoint, 'config.accountAbstraction.entryPoint'),
    factory: requireAddress(aa.factory, 'config.accountAbstraction.factory'),
    implementation: requireAddress(aa.accountImplementation, 'config.accountAbstraction.accountImplementation'),
  };
}

function claimTuple(message) {
  return [
    message.operator,
    message.agent,
    message.recipient,
    message.manifestHash,
    message.statement,
    message.maxPayment,
    message.nonce,
    message.deadline,
    message.allocation,
  ];
}

function toRpcHex(value) {
  return `0x${BigInt(value).toString(16)}`;
}

function fromRpcQuantity(value, label) {
  if (typeof value !== 'string' || !/^0x[0-9a-fA-F]+$/.test(value)) fail(`${label} is not an RPC quantity.`);
  return BigInt(value);
}

function userOpTuple(operation) {
  return [
    operation.sender,
    BigInt(operation.nonce),
    operation.initCode,
    operation.callData,
    BigInt(operation.callGasLimit),
    BigInt(operation.verificationGasLimit),
    BigInt(operation.preVerificationGas),
    BigInt(operation.maxFeePerGas),
    BigInt(operation.maxPriorityFeePerGas),
    operation.paymasterAndData,
    operation.signature,
  ];
}

function userOpForRpc(operation) {
  return {
    sender: operation.sender,
    nonce: toRpcHex(operation.nonce),
    initCode: operation.initCode,
    callData: operation.callData,
    callGasLimit: toRpcHex(operation.callGasLimit),
    verificationGasLimit: toRpcHex(operation.verificationGasLimit),
    preVerificationGas: toRpcHex(operation.preVerificationGas),
    maxFeePerGas: toRpcHex(operation.maxFeePerGas),
    maxPriorityFeePerGas: toRpcHex(operation.maxPriorityFeePerGas),
    paymasterAndData: operation.paymasterAndData,
    signature: operation.signature,
  };
}

function bump(value, numerator = 125n, denominator = 100n) {
  return (BigInt(value) * numerator + denominator - 1n) / denominator;
}

async function defaultRequest(url, method, params = []) {
  const response = await fetch(url, {
    method: 'POST',
    headers: { accept: 'application/json', 'content-type': 'application/json' },
    body: JSON.stringify({ jsonrpc: '2.0', id: Math.floor(Math.random() * 1_000_000_000), method, params }),
    redirect: 'error',
    signal: AbortSignal.timeout(30_000),
  });
  if (!response.ok) throw new Error(`${method} returned HTTP ${response.status} from ${url}`);
  const payload = await response.json();
  if (payload.error) {
    const error = new Error(`${method} failed: ${payload.error.code} ${payload.error.message}`);
    error.rpcCode = payload.error.code;
    error.rpcData = payload.error.data;
    throw error;
  }
  return payload.result;
}

async function ethCall(request, url, iface, functionName, args, to, from = null) {
  const transaction = { to, data: iface.encodeFunctionData(functionName, args) };
  if (from) transaction.from = from;
  const raw = await request(url, 'eth_call', [transaction, 'latest']);
  return iface.decodeFunctionResult(functionName, raw);
}

async function codeAt(request, url, address) {
  const code = await request(url, 'eth_getCode', [address, 'latest']);
  if (typeof code !== 'string' || !/^0x[0-9a-fA-F]*$/.test(code)) fail(`eth_getCode returned invalid code for ${address}.`);
  return code;
}

async function verifyCoreRuntime(request, target) {
  const { aa } = target;
  const [entryPointCode, factoryCode, implementationCode] = await Promise.all([
    codeAt(request, aa.publicRpc, target.entryPoint),
    codeAt(request, aa.publicRpc, target.factory),
    codeAt(request, aa.publicRpc, target.implementation),
  ]);
  if (entryPointCode === EMPTY_CODE || keccak256(entryPointCode).toLowerCase() !== aa.entryPointRuntimeCodeHash.toLowerCase()) {
    fail('EntryPoint runtime code differs from the pinned Sepolia hash.');
  }
  if (factoryCode === EMPTY_CODE || keccak256(factoryCode).toLowerCase() !== aa.factoryRuntimeCodeHash.toLowerCase()) {
    fail('SimpleAccountFactory runtime code differs from the pinned Sepolia hash.');
  }
  if (implementationCode === EMPTY_CODE || keccak256(implementationCode).toLowerCase() !== aa.accountImplementationRuntimeCodeHash.toLowerCase()) {
    fail('SimpleAccount implementation runtime code differs from the pinned Sepolia hash.');
  }
  const [implementation] = await ethCall(request, aa.publicRpc, factoryInterface, 'accountImplementation', [], target.factory);
  if (!sameAddress(implementation, target.implementation)) fail('SimpleAccountFactory points to a different implementation.');
}

export function createAgentOwnedRoute(agentValue, config) {
  const target = checkedConfig(config);
  const agent = requireAddress(agentValue, 'agent');
  const createAccountCalldata = factoryInterface.encodeFunctionData('createAccount', [agent, target.aa.factorySalt]);
  return {
    mode: 'agent-owned-erc4337',
    chainId: config.chainId,
    agent,
    nftRecipient: agent,
    entryPoint: target.entryPoint,
    entryPointVersion: target.aa.entryPointVersion,
    factory: target.factory,
    factorySalt: target.aa.factorySalt,
    accountImplementation: target.implementation,
    createAccountCalldata,
    bundlerRpc: target.aa.bundlerRpc,
    publicRpc: target.aa.publicRpc,
    paymasterAndData: target.aa.paymasterAndData,
    gasSponsored: false,
    sameAgentSignerRequired: true,
    createAnotherServerWallet: false,
    nfhRelayerUsed: false,
  };
}

export function validateClaimAuthorization(input, claimSignatureValue, agentValue, config, {
  now = Math.floor(Date.now() / 1000),
} = {}) {
  const target = checkedConfig(config);
  const agent = requireAddress(agentValue, 'agent');
  const claimSignature = requireSignature(claimSignatureValue, 'claim signature');
  const envelope = extractEnvelope(input);
  const validated = validateNfhSigningRequest(envelope, {
    expectedAgent: agent,
    expectedChainId: config.chainId,
    expectedVerifyingContract: target.minter,
    expectedStatement: config.requiredStatement,
    now,
  });
  if (envelope.status !== 'prepared_unsigned' || envelope.signingReady !== true || envelope.primaryType !== 'AgentClaim') {
    fail('claim_as_agent did not return one signing-ready public claim.');
  }
  for (const role of ['operator', 'agent', 'recipient']) {
    if (!sameAddress(envelope.message?.[role], agent)) fail(`claim message ${role} differs from the original agent wallet.`);
  }
  if (BigInt(envelope.message.maxPayment) !== 0n || BigInt(envelope.message.allocation) !== 0n) {
    fail('the fallback accepts only the zero-price public allocation.');
  }
  const template = envelope.transactionTemplate;
  if (!template || Number(template.chainId) !== config.chainId || !sameAddress(template.to, target.minter) || template.value !== '0x0' || template.function !== 'claim') {
    fail('the transaction template differs from the exact V19 zero-value claim.');
  }
  const typedData = validated.typedData;
  const recovered = verifyTypedData(typedData.domain, typedData.types, typedData.message, claimSignature);
  if (!sameAddress(recovered, agent)) fail('the claim signature was not made by the original agent wallet.');
  const digest = TypedDataEncoder.hash(typedData.domain, typedData.types, typedData.message);
  const claimCalldata = minterInterface.encodeFunctionData('claim', [
    claimTuple(typedData.message), claimSignature, claimSignature, '0x', [],
  ]);
  return {
    agent,
    typedData,
    claimSignature,
    claimDigest: digest,
    claimCalldata,
    nonce: BigInt(typedData.message.nonce),
    deadline: BigInt(typedData.message.deadline),
    manifestHash: typedData.message.manifestHash,
  };
}

export async function inspectAgentOwnedRoute(agentValue, config, { request = defaultRequest } = {}) {
  const target = checkedConfig(config);
  const route = createAgentOwnedRoute(agentValue, config);
  const [rpcChain, bundlerChain, supportedEntryPoints] = await Promise.all([
    request(route.publicRpc, 'eth_chainId', []),
    request(route.bundlerRpc, 'eth_chainId', []),
    request(route.bundlerRpc, 'eth_supportedEntryPoints', []),
  ]);
  if (BigInt(rpcChain) !== BigInt(config.chainId) || BigInt(bundlerChain) !== BigInt(config.chainId)) {
    fail('the public RPC or bundler is connected to a different chain.');
  }
  if (!Array.isArray(supportedEntryPoints) || !supportedEntryPoints.some((value) => sameAddress(value, route.entryPoint))) {
    fail('the bundler does not advertise the pinned EntryPoint.');
  }
  await verifyCoreRuntime(request, target);
  const [accountAddress] = await ethCall(
    request,
    route.publicRpc,
    factoryInterface,
    'getAddress',
    [route.agent, route.factorySalt],
    route.factory,
  );
  const smartAccount = requireAddress(accountAddress, 'factory-derived smart account');
  const [accountCode, balanceRaw, depositResult] = await Promise.all([
    codeAt(request, route.publicRpc, smartAccount),
    request(route.publicRpc, 'eth_getBalance', [smartAccount, 'latest']),
    ethCall(request, route.publicRpc, entryPointInterface, 'balanceOf', [smartAccount], route.entryPoint),
  ]);
  const deployed = accountCode !== EMPTY_CODE;
  let owner = route.agent;
  let accountEntryPoint = route.entryPoint;
  if (deployed) {
    if (keccak256(accountCode).toLowerCase() !== target.aa.accountProxyRuntimeCodeHash.toLowerCase()) {
      fail('the derived account runtime differs from the pinned SimpleAccount proxy.');
    }
    [[owner], [accountEntryPoint]] = await Promise.all([
      ethCall(request, route.publicRpc, accountInterface, 'owner', [], smartAccount),
      ethCall(request, route.publicRpc, accountInterface, 'entryPoint', [], smartAccount),
    ]);
    if (!sameAddress(owner, route.agent)) fail('the derived SimpleAccount is not owned by the original agent wallet.');
    if (!sameAddress(accountEntryPoint, route.entryPoint)) fail('the derived SimpleAccount uses a different EntryPoint.');
  }
  const initCode = deployed ? '0x' : `${route.factory}${route.createAccountCalldata.slice(2)}`;
  return {
    ...route,
    smartAccount,
    deployed,
    owner: getAddress(owner),
    accountEntryPoint: getAddress(accountEntryPoint),
    initCode,
    balanceWei: fromRpcQuantity(balanceRaw, 'smart-account balance'),
    entryPointDepositWei: BigInt(depositResult[0]),
    funding: {
      address: smartAccount,
      asset: 'Sepolia ETH',
      recommendedBootstrapFundingWei: target.aa.recommendedBootstrapFundingWei,
      instruction: `Fund only ${smartAccount} with Sepolia ETH if the reported balance plus EntryPoint deposit cannot cover gas. The NFT recipient remains ${route.agent}.`,
    },
  };
}

async function readClaimState(authorization, route, target, request) {
  const [statementResult, pausedResult, enabledResult, usedResult, limitResult, mintedResult, digestResult] = await Promise.all([
    ethCall(request, route.publicRpc, minterInterface, 'requiredStatement', [], target.minter),
    ethCall(request, route.publicRpc, minterInterface, 'paused', [], target.minter),
    ethCall(request, route.publicRpc, minterInterface, 'publicClaimsEnabled', [], target.minter),
    ethCall(request, route.publicRpc, minterInterface, 'usedNonces', [authorization.agent, authorization.nonce], target.minter),
    ethCall(request, route.publicRpc, tokenInterface, 'activeWalletClaimLimit', [], target.token),
    ethCall(request, route.publicRpc, tokenInterface, 'walletClaimMinted', [authorization.agent], target.token),
    ethCall(request, route.publicRpc, minterInterface, 'claimDigest', [claimTuple(authorization.typedData.message)], target.minter),
  ]);
  if (String(statementResult[0]).toLowerCase() !== target.config.requiredStatement.toLowerCase()) fail('the live minter statement differs.');
  if (pausedResult[0] !== false || enabledResult[0] !== true) fail('the live V19 public claim is paused or disabled.');
  if (usedResult[0] !== false) fail('the prepared claim nonce is already used; reconcile instead of submitting.');
  if (BigInt(mintedResult[0]) >= BigInt(limitResult[0])) fail('the agent wallet has reached its live claim limit.');
  if (String(digestResult[0]).toLowerCase() !== authorization.claimDigest.toLowerCase()) fail('the contract claim digest differs from the signed typed data.');
  return { walletClaimMinted: BigInt(mintedResult[0]), activeWalletClaimLimit: BigInt(limitResult[0]) };
}

export async function prepareAgentOwnedUserOperation(input, claimSignature, agent, config, {
  request = defaultRequest,
  now = Math.floor(Date.now() / 1000),
} = {}) {
  const target = checkedConfig(config);
  const authorization = validateClaimAuthorization(input, claimSignature, agent, config, { now });
  const route = await inspectAgentOwnedRoute(agent, config, { request });
  const claimState = await readClaimState(authorization, route, target, request);
  const availableWei = route.balanceWei + route.entryPointDepositWei;
  const recommendedWei = BigInt(target.aa.recommendedBootstrapFundingWei);
  if (availableWei === 0n) {
    return {
      schema: 'notforhumans-agent-owned-userop/1',
      status: 'funding_required',
      agent: authorization.agent,
      smartAccount: route.smartAccount,
      nftRecipient: authorization.agent,
      funding: route.funding,
      availableWei: availableWei.toString(),
      minimumNextFundingWei: recommendedWei.toString(),
      claimDigest: authorization.claimDigest,
      claimNonce: authorization.nonce.toString(),
      deadline: authorization.deadline.toString(),
      noSignatureOrSubmissionPerformed: true,
    };
  }

  const simulationRaw = await request(route.publicRpc, 'eth_call', [{
    from: route.smartAccount,
    to: target.minter,
    value: '0x0',
    data: authorization.claimCalldata,
  }, 'latest']);
  const [predictedTokenId] = minterInterface.decodeFunctionResult('claim', simulationRaw);
  const [nonceResult, gasPrices] = await Promise.all([
    ethCall(request, route.publicRpc, entryPointInterface, 'getNonce', [route.smartAccount, 0], route.entryPoint),
    request(route.bundlerRpc, 'pimlico_getUserOperationGasPrice', []),
  ]);
  const fees = gasPrices?.standard || gasPrices?.fast || gasPrices?.slow;
  if (!fees?.maxFeePerGas || !fees?.maxPriorityFeePerGas) fail('the bundler returned no usable fee quote.');
  const operation = {
    sender: route.smartAccount,
    nonce: BigInt(nonceResult[0]),
    initCode: route.initCode,
    callData: accountInterface.encodeFunctionData('execute', [target.minter, 0, authorization.claimCalldata]),
    callGasLimit: 0n,
    verificationGasLimit: 0n,
    preVerificationGas: 0n,
    maxFeePerGas: fromRpcQuantity(fees.maxFeePerGas, 'maxFeePerGas'),
    maxPriorityFeePerGas: fromRpcQuantity(fees.maxPriorityFeePerGas, 'maxPriorityFeePerGas'),
    paymasterAndData: '0x',
    signature: authorization.claimSignature,
  };
  const estimate = await request(route.bundlerRpc, 'eth_estimateUserOperationGas', [userOpForRpc(operation), route.entryPoint]);
  operation.callGasLimit = bump(fromRpcQuantity(estimate.callGasLimit, 'estimated callGasLimit'));
  operation.verificationGasLimit = bump(fromRpcQuantity(estimate.verificationGasLimit, 'estimated verificationGasLimit'));
  operation.preVerificationGas = bump(fromRpcQuantity(estimate.preVerificationGas, 'estimated preVerificationGas'), 120n, 100n);
  const conservativePrefundWei = (
    operation.callGasLimit + 3n * operation.verificationGasLimit + operation.preVerificationGas
  ) * operation.maxFeePerGas;
  if (availableWei < conservativePrefundWei) {
    return {
      schema: 'notforhumans-agent-owned-userop/1',
      status: 'funding_required',
      agent: authorization.agent,
      smartAccount: route.smartAccount,
      nftRecipient: authorization.agent,
      funding: route.funding,
      availableWei: availableWei.toString(),
      minimumNextFundingWei: (conservativePrefundWei - availableWei).toString(),
      conservativePrefundWei: conservativePrefundWei.toString(),
      claimDigest: authorization.claimDigest,
      claimNonce: authorization.nonce.toString(),
      deadline: authorization.deadline.toString(),
      noSignatureOrSubmissionPerformed: true,
    };
  }
  const [userOperationHash] = await ethCall(
    request,
    route.publicRpc,
    entryPointInterface,
    'getUserOpHash',
    [userOpTuple(operation)],
    route.entryPoint,
  );
  operation.signature = '0x';
  return {
    schema: 'notforhumans-agent-owned-userop/1',
    status: 'prepared_unsigned_user_operation',
    preparedAt: new Date(now * 1000).toISOString(),
    chainId: config.chainId,
    agent: authorization.agent,
    smartAccount: route.smartAccount,
    smartAccountDeployed: route.deployed,
    nftRecipient: authorization.agent,
    minter: target.minter,
    token: target.token,
    entryPoint: route.entryPoint,
    factory: route.factory,
    factorySalt: route.factorySalt,
    bundlerRpc: route.bundlerRpc,
    publicRpc: route.publicRpc,
    paymasterAndData: '0x',
    gasSponsored: false,
    claim: {
      typedData: authorization.typedData,
      signature: authorization.claimSignature,
      digest: authorization.claimDigest,
      calldata: authorization.claimCalldata,
      nonce: authorization.nonce.toString(),
      deadline: authorization.deadline.toString(),
      predictedTokenId: predictedTokenId.toString(),
      walletClaimMintedBefore: claimState.walletClaimMinted.toString(),
      activeWalletClaimLimit: claimState.activeWalletClaimLimit.toString(),
    },
    userOperation: userOpForRpc(operation),
    userOperationHash,
    conservativePrefundWei: conservativePrefundWei.toString(),
    availableWei: availableWei.toString(),
    signingHandoff: {
      executable: false,
      walletSelection: {
        program: 'mm',
        arguments: ['wallet', 'select', authorization.agent, '--chain-namespace', 'evm', '--toon'],
        verification: ['wallet', 'address', '--toon'],
        requiredAddress: authorization.agent,
      },
      program: 'mm',
      arguments: [
        'wallet', 'sign-message', '--message', userOperationHash, '--chain-id', String(config.chainId),
        '--wait', '--wallet-timeout', '600', '--toon',
      ],
      requiredRecoveredSigner: authorization.agent,
    },
    submission: {
      method: 'eth_sendUserOperation',
      directToBundler: true,
      usesMetaMaskSendTransaction: false,
      usesNfhRelayer: false,
      retryLimitAfterExplicitPreBroadcastFailureWithNoReference: 1,
      referenceRule: 'Reconcile this UserOperation hash before any retry; never submit a duplicate.',
    },
  };
}

function operationFromPacket(packet, signature) {
  const operation = packet.userOperation;
  if (!operation || typeof operation !== 'object' || Array.isArray(operation)) fail('the prepared packet has no UserOperation.');
  const parsed = {
    sender: requireAddress(operation.sender, 'UserOperation sender'),
    nonce: fromRpcQuantity(operation.nonce, 'UserOperation nonce'),
    initCode: requireHexData(operation.initCode, 'UserOperation initCode'),
    callData: requireHexData(operation.callData, 'UserOperation callData'),
    callGasLimit: fromRpcQuantity(operation.callGasLimit, 'UserOperation callGasLimit'),
    verificationGasLimit: fromRpcQuantity(operation.verificationGasLimit, 'UserOperation verificationGasLimit'),
    preVerificationGas: fromRpcQuantity(operation.preVerificationGas, 'UserOperation preVerificationGas'),
    maxFeePerGas: fromRpcQuantity(operation.maxFeePerGas, 'UserOperation maxFeePerGas'),
    maxPriorityFeePerGas: fromRpcQuantity(operation.maxPriorityFeePerGas, 'UserOperation maxPriorityFeePerGas'),
    paymasterAndData: requireHexData(operation.paymasterAndData, 'UserOperation paymasterAndData'),
    signature,
  };
  for (const field of ['callGasLimit', 'verificationGasLimit', 'preVerificationGas', 'maxFeePerGas']) {
    if (parsed[field] <= 0n) fail(`UserOperation ${field} must be positive.`);
  }
  if (parsed.maxPriorityFeePerGas > parsed.maxFeePerGas) {
    fail('UserOperation maxPriorityFeePerGas exceeds maxFeePerGas.');
  }
  return parsed;
}

async function reconcileUserOperation(request, packet, hash) {
  const [known, receipt] = await Promise.all([
    request(packet.bundlerRpc, 'eth_getUserOperationByHash', [hash]).catch(() => null),
    request(packet.bundlerRpc, 'eth_getUserOperationReceipt', [hash]).catch(() => null),
  ]);
  return { known, receipt };
}

async function verifyReceipt(request, packet, receipt) {
  if (receipt?.success !== true || receipt?.receipt?.status !== '0x1') fail('the UserOperation receipt reports failure.');
  const transactionHash = receipt.receipt.transactionHash;
  const transaction = await request(packet.publicRpc, 'eth_getTransactionByHash', [transactionHash]);
  if (!transaction || BigInt(transaction.value) !== 0n) fail('the included transaction is missing or has nonzero value.');
  const agentEvent = (receipt.receipt.logs || []).map((log) => {
    if (!sameAddress(log.address, packet.minter)) return null;
    try { return minterInterface.parseLog({ topics: log.topics, data: log.data }); } catch { return null; }
  }).find((event) => event?.name === 'AgentClaimed');
  if (!agentEvent) fail('the confirmed receipt contains no AgentClaimed event.');
  const tokenId = BigInt(agentEvent.args.tokenId);
  if (tokenId !== BigInt(packet.claim.predictedTokenId)) fail('the minted token differs from the prepared simulation.');
  if (!sameAddress(agentEvent.args.operator, packet.agent) || !sameAddress(agentEvent.args.agent, packet.agent) || !sameAddress(agentEvent.args.recipient, packet.agent)) {
    fail('the confirmed AgentClaimed roles differ from the original agent wallet.');
  }
  const [owner] = await ethCall(request, packet.publicRpc, tokenInterface, 'ownerOf', [tokenId], packet.token);
  if (!sameAddress(owner, packet.agent)) fail('ownerOf does not equal the original agent wallet.');
  return { transactionHash, tokenId: tokenId.toString(), owner: getAddress(owner) };
}

export async function submitAgentOwnedUserOperation(packet, userOperationSignatureValue, config, {
  request = defaultRequest,
  pollIntervalMs = 3_000,
  timeoutMs = 240_000,
} = {}) {
  const target = checkedConfig(config);
  if (!packet || packet.schema !== 'notforhumans-agent-owned-userop/1' || packet.status !== 'prepared_unsigned_user_operation') {
    fail('only a prepared unsigned NFH UserOperation packet may be submitted.');
  }
  const signature = requireSignature(userOperationSignatureValue, 'UserOperation signature');
  const authorization = validateClaimAuthorization({
    ...packet.claim.typedData,
    status: 'prepared_unsigned',
    signingReady: true,
    transactionTemplate: {
      chainId: config.chainId,
      to: target.minter,
      value: '0x0',
      function: 'claim',
    },
  }, packet.claim.signature, packet.agent, config);
  if (authorization.claimDigest.toLowerCase() !== packet.claim.digest.toLowerCase() || authorization.claimCalldata.toLowerCase() !== packet.claim.calldata.toLowerCase()) {
    fail('the saved claim authorization differs from the prepared packet.');
  }
  const operation = operationFromPacket(packet, signature);
  const expectedCallData = accountInterface.encodeFunctionData('execute', [target.minter, 0, authorization.claimCalldata]);
  if (
    !sameAddress(packet.entryPoint, target.entryPoint)
    || !sameAddress(packet.factory, target.factory)
    || !sameAddress(packet.minter, target.minter)
    || !sameAddress(packet.token, target.token)
    || packet.bundlerRpc !== target.aa.bundlerRpc
    || packet.publicRpc !== target.aa.publicRpc
  ) fail('the prepared route differs from the pinned V19 configuration.');
  if (!sameAddress(operation.sender, packet.smartAccount) || operation.callData.toLowerCase() !== expectedCallData.toLowerCase()) {
    fail('the UserOperation sender or exact inner claim call differs.');
  }
  if (operation.paymasterAndData !== '0x') fail('the UserOperation added a paymaster.');
  const [actualHash] = await ethCall(
    request,
    target.aa.publicRpc,
    entryPointInterface,
    'getUserOpHash',
    [userOpTuple(operation)],
    target.entryPoint,
  );
  const expectedHash = requireBytes32(packet.userOperationHash, 'prepared UserOperation hash');
  if (String(actualHash).toLowerCase() !== expectedHash) fail('the live EntryPoint UserOperation hash differs.');
  const recovered = verifyMessage(getBytes(actualHash), signature);
  if (!sameAddress(recovered, packet.agent)) fail('the UserOperation signature was not made by the original agent wallet.');

  const existing = await reconcileUserOperation(request, packet, actualHash);
  if (existing.receipt) {
    const verified = await verifyReceipt(request, packet, existing.receipt);
    return { status: 'already_confirmed_reconciled', userOperationHash: actualHash, ...verified };
  }
  if (existing.known) {
    return { status: 'already_submitted_pending', userOperationHash: actualHash, transactionHash: existing.known.transactionHash || null };
  }

  const route = await inspectAgentOwnedRoute(packet.agent, config, { request });
  if (!sameAddress(route.smartAccount, packet.smartAccount) || route.deployed !== packet.smartAccountDeployed) {
    fail('the smart-account address or deployment state changed without a matching UserOperation reference; prepare again.');
  }
  if (operation.initCode.toLowerCase() !== route.initCode.toLowerCase()) {
    fail('the UserOperation initCode differs from the self-funded route.');
  }
  await readClaimState(authorization, route, target, request);
  const [liveNonceResult] = await ethCall(request, route.publicRpc, entryPointInterface, 'getNonce', [route.smartAccount, 0], route.entryPoint);
  if (BigInt(liveNonceResult) !== operation.nonce) fail('the EntryPoint nonce changed; reconcile or prepare again.');

  const estimate = await request(route.bundlerRpc, 'eth_estimateUserOperationGas', [userOpForRpc(operation), route.entryPoint]);
  if (
    fromRpcQuantity(estimate.callGasLimit, 'final estimated callGasLimit') > operation.callGasLimit
    || fromRpcQuantity(estimate.verificationGasLimit, 'final estimated verificationGasLimit') > operation.verificationGasLimit
    || fromRpcQuantity(estimate.preVerificationGas, 'final estimated preVerificationGas') > operation.preVerificationGas
  ) fail('the final gas estimate exceeds the signed limits; prepare and sign a fresh UserOperation.');
  const availableWei = route.balanceWei + route.entryPointDepositWei;
  const conservativePrefundWei = (operation.callGasLimit + 3n * operation.verificationGasLimit + operation.preVerificationGas) * operation.maxFeePerGas;
  if (availableWei < conservativePrefundWei) fail(`fund ${route.smartAccount} with more Sepolia ETH before submission.`);

  let sentHash;
  try {
    sentHash = await request(route.bundlerRpc, 'eth_sendUserOperation', [userOpForRpc(operation), route.entryPoint]);
  } catch (error) {
    const reconciled = await reconcileUserOperation(request, packet, actualHash);
    if (reconciled.receipt) {
      const verified = await verifyReceipt(request, packet, reconciled.receipt);
      return { status: 'confirmed_after_ambiguous_response', userOperationHash: actualHash, ...verified };
    }
    if (reconciled.known) return { status: 'submitted_after_ambiguous_response', userOperationHash: actualHash, transactionHash: reconciled.known.transactionHash || null };
    return {
      status: 'prebroadcast_failure_no_reference',
      userOperationHash: actualHash,
      error: error instanceof Error ? error.message : String(error),
      retryLimit: 1,
      instruction: 'No reference was found. One retry is permitted with this identical signed packet; reconcile the UserOperation hash first.',
    };
  }
  if (String(sentHash).toLowerCase() !== actualHash.toLowerCase()) fail('the bundler returned a different UserOperation hash.');
  const started = Date.now();
  while (Date.now() - started < timeoutMs) {
    const receipt = await request(route.bundlerRpc, 'eth_getUserOperationReceipt', [actualHash]);
    if (receipt) {
      const verified = await verifyReceipt(request, packet, receipt);
      return { status: 'confirmed', userOperationHash: actualHash, ...verified };
    }
    await new Promise((resolve) => setTimeout(resolve, pollIntervalMs));
  }
  return {
    status: 'submitted_pending',
    userOperationHash: actualHash,
    instruction: 'Reconcile this exact hash. Do not submit again while it is pending or its status is unknown.',
  };
}

export { defaultRequest, userOpForRpc };

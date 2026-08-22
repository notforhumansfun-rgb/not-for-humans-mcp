import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import { Interface, TypedDataEncoder, Wallet, getBytes, keccak256 } from 'ethers';
import {
  createAgentOwnedRoute,
  prepareAgentOwnedUserOperation,
  submitAgentOwnedUserOperation,
  validateClaimAuthorization,
} from '../src/erc4337.js';

const TEST_KEY = '0x59c6995e998f97a5a0044976f7d8c73f6fbe16f19566e7d8757f90d6b6e5b90d';
const wallet = new Wallet(TEST_KEY);
const minterRead = new Interface([
  'function requiredStatement() view returns (bytes32)',
  'function paused() view returns (bool)',
  'function publicClaimsEnabled() view returns (bool)',
  'function usedNonces(address,uint256) view returns (bool)',
  'function claimDigest((address operator,address agent,address recipient,bytes32 manifestHash,bytes32 statement,uint256 maxPayment,uint256 nonce,uint256 deadline,uint8 allocation)) view returns (bytes32)',
  'function claim((address operator,address agent,address recipient,bytes32 manifestHash,bytes32 statement,uint256 maxPayment,uint256 nonce,uint256 deadline,uint8 allocation),bytes,bytes,bytes,bytes32[]) returns (uint256)',
]);
const tokenRead = new Interface([
  'function activeWalletClaimLimit() view returns (uint256)',
  'function walletClaimMinted(address) view returns (uint256)',
]);
const factoryRead = new Interface([
  'function accountImplementation() view returns (address)',
  'function getAddress(address,uint256) view returns (address)',
]);
const entryPointRead = new Interface([
  'function balanceOf(address) view returns (uint256)',
  'function getNonce(address,uint192) view returns (uint256)',
  'function getUserOpHash((address sender,uint256 nonce,bytes initCode,bytes callData,uint256 callGasLimit,uint256 verificationGasLimit,uint256 preVerificationGas,uint256 maxFeePerGas,uint256 maxPriorityFeePerGas,bytes paymasterAndData,bytes signature)) view returns (bytes32)',
]);
const entryPointHash = new Interface([
  'function getUserOpHash((address sender,uint256 nonce,bytes initCode,bytes callData,uint256 callGasLimit,uint256 verificationGasLimit,uint256 preVerificationGas,uint256 maxFeePerGas,uint256 maxPriorityFeePerGas,bytes paymasterAndData,bytes signature)) view returns (bytes32)',
]);
const accountWrite = new Interface(['function execute(address,uint256,bytes)']);

async function config() {
  return JSON.parse(await readFile(new URL('../config/sepolia.json', import.meta.url), 'utf8'));
}

function claim(target, overrides = {}) {
  const message = {
    operator: wallet.address,
    agent: wallet.address,
    recipient: wallet.address,
    manifestHash: `0x${'a'.repeat(64)}`,
    statement: target.requiredStatement,
    maxPayment: '0',
    nonce: '71',
    deadline: '1893456000',
    allocation: 0,
  };
  return {
    status: 'prepared_unsigned',
    signingReady: true,
    domain: {
      name: 'NOT FOR HUMANS Claim',
      version: '4',
      chainId: target.chainId,
      verifyingContract: target.contracts.claimMinter,
    },
    primaryType: 'AgentClaim',
    types: {
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
    },
    message,
    transactionTemplate: {
      chainId: target.chainId,
      to: target.contracts.claimMinter,
      value: '0x0',
      function: 'claim',
    },
    ...overrides,
  };
}

test('V19 recovery keeps the original agent signer and uses a self-funded smart account, not another server wallet', async () => {
  const target = await config();
  const route = createAgentOwnedRoute(wallet.address, target);
  assert.equal(route.mode, 'agent-owned-erc4337');
  assert.equal(route.agent, wallet.address);
  assert.equal(route.nftRecipient, wallet.address);
  assert.equal(route.factorySalt, 19);
  assert.equal(route.entryPoint, target.accountAbstraction.entryPoint);
  assert.equal(route.factory, target.accountAbstraction.factory);
  assert.equal(route.paymasterAndData, '0x');
  assert.equal(route.gasSponsored, false);
  assert.equal(route.sameAgentSignerRequired, true);
  assert.equal(route.createAnotherServerWallet, false);
  assert.equal(route.nfhRelayerUsed, false);
  assert.match(route.createAccountCalldata, /^0x5fbfb9cf/);
});

test('the builder accepts only the exact agent-signed zero-value V19 claim', async () => {
  const target = await config();
  const input = claim(target);
  const signature = await wallet.signTypedData(input.domain, input.types, input.message);
  const authorization = validateClaimAuthorization(input, signature, wallet.address, target, { now: 1_700_000_000 });
  assert.equal(authorization.agent, wallet.address);
  assert.equal(authorization.claimDigest, TypedDataEncoder.hash(input.domain, input.types, input.message));
  assert.match(authorization.claimCalldata, /^0x958cec40/);

  const paid = claim(target);
  paid.message.maxPayment = '1';
  const paidSignature = await wallet.signTypedData(paid.domain, paid.types, paid.message);
  assert.throws(
    () => validateClaimAuthorization(paid, paidSignature, wallet.address, target, { now: 1_700_000_000 }),
    /zero payment/i,
  );
});

test('an unfunded account stops before signing, then a funded account prepares the exact original-wallet handoff', async () => {
  const target = await config();
  const fakeCodes = {
    entryPoint: '0x6001600055',
    factory: '0x6002600055',
    implementation: '0x6003600055',
  };
  target.accountAbstraction.entryPointRuntimeCodeHash = keccak256(fakeCodes.entryPoint);
  target.accountAbstraction.factoryRuntimeCodeHash = keccak256(fakeCodes.factory);
  target.accountAbstraction.accountImplementationRuntimeCodeHash = keccak256(fakeCodes.implementation);
  const smartAccount = '0x4444444444444444444444444444444444444444';
  const input = claim(target);
  const signature = await wallet.signTypedData(input.domain, input.types, input.message);
  const digest = TypedDataEncoder.hash(input.domain, input.types, input.message);
  const userOperationHash = `0x${'7'.repeat(64)}`;
  let funded = false;

  const request = async (url, method, params) => {
    if (method === 'eth_chainId') return '0xaa36a7';
    if (method === 'eth_supportedEntryPoints') return [target.accountAbstraction.entryPoint];
    if (method === 'eth_getBalance') return funded ? '0xde0b6b3a7640000' : '0x0';
    if (method === 'pimlico_getUserOperationGasPrice') {
      return { standard: { maxFeePerGas: '0x3b9aca00', maxPriorityFeePerGas: '0x5f5e100' } };
    }
    if (method === 'eth_estimateUserOperationGas') {
      return { callGasLimit: '0x186a0', verificationGasLimit: '0x493e0', preVerificationGas: '0xc350' };
    }
    if (method === 'eth_getCode') {
      const address = params[0].toLowerCase();
      if (address === target.accountAbstraction.entryPoint.toLowerCase()) return fakeCodes.entryPoint;
      if (address === target.accountAbstraction.factory.toLowerCase()) return fakeCodes.factory;
      if (address === target.accountAbstraction.accountImplementation.toLowerCase()) return fakeCodes.implementation;
      if (address === smartAccount.toLowerCase()) return '0x';
      throw new Error(`unexpected code request ${address}`);
    }
    if (method !== 'eth_call') throw new Error(`unexpected ${method} on ${url}`);
    const { to, data } = params[0];
    const selector = data.slice(0, 10).toLowerCase();
    if (to.toLowerCase() === target.accountAbstraction.factory.toLowerCase()) {
      if (selector === factoryRead.getFunction('accountImplementation').selector) {
        return factoryRead.encodeFunctionResult('accountImplementation', [target.accountAbstraction.accountImplementation]);
      }
      if (selector === factoryRead.getFunction('getAddress').selector) {
        return factoryRead.encodeFunctionResult('getAddress', [smartAccount]);
      }
    }
    if (to.toLowerCase() === target.accountAbstraction.entryPoint.toLowerCase()) {
      if (selector === entryPointRead.getFunction('balanceOf').selector) return entryPointRead.encodeFunctionResult('balanceOf', [0]);
      if (selector === entryPointRead.getFunction('getNonce').selector) return entryPointRead.encodeFunctionResult('getNonce', [0]);
      if (selector === entryPointRead.getFunction('getUserOpHash').selector) return entryPointRead.encodeFunctionResult('getUserOpHash', [userOperationHash]);
    }
    if (to.toLowerCase() === target.contracts.claimMinter.toLowerCase()) {
      if (selector === minterRead.getFunction('requiredStatement').selector) return minterRead.encodeFunctionResult('requiredStatement', [target.requiredStatement]);
      if (selector === minterRead.getFunction('paused').selector) return minterRead.encodeFunctionResult('paused', [false]);
      if (selector === minterRead.getFunction('publicClaimsEnabled').selector) return minterRead.encodeFunctionResult('publicClaimsEnabled', [true]);
      if (selector === minterRead.getFunction('usedNonces').selector) return minterRead.encodeFunctionResult('usedNonces', [false]);
      if (selector === minterRead.getFunction('claimDigest').selector) return minterRead.encodeFunctionResult('claimDigest', [digest]);
      if (selector === minterRead.getFunction('claim').selector) return minterRead.encodeFunctionResult('claim', [9]);
    }
    if (to.toLowerCase() === target.contracts.token.toLowerCase()) {
      if (selector === tokenRead.getFunction('activeWalletClaimLimit').selector) return tokenRead.encodeFunctionResult('activeWalletClaimLimit', [5]);
      if (selector === tokenRead.getFunction('walletClaimMinted').selector) return tokenRead.encodeFunctionResult('walletClaimMinted', [0]);
    }
    throw new Error(`unexpected eth_call ${to} ${selector}`);
  };

  const result = await prepareAgentOwnedUserOperation(input, signature, wallet.address, target, {
    request,
    now: 1_700_000_000,
  });
  assert.equal(result.status, 'funding_required');
  assert.equal(result.smartAccount, smartAccount);
  assert.equal(result.nftRecipient, wallet.address);
  assert.equal(result.minimumNextFundingWei, target.accountAbstraction.recommendedBootstrapFundingWei);
  assert.equal(result.noSignatureOrSubmissionPerformed, true);

  funded = true;
  const prepared = await prepareAgentOwnedUserOperation(input, signature, wallet.address, target, {
    request,
    now: 1_700_000_000,
  });
  assert.equal(prepared.status, 'prepared_unsigned_user_operation');
  assert.equal(prepared.userOperationHash, userOperationHash);
  assert.equal(prepared.claim.predictedTokenId, '9');
  assert.equal(prepared.userOperation.paymasterAndData, '0x');
  assert.equal(prepared.submission.usesMetaMaskSendTransaction, false);
  assert.deepEqual(prepared.signingHandoff.walletSelection.arguments, [
    'wallet', 'select', wallet.address, '--chain-namespace', 'evm', '--toon',
  ]);
  assert.equal(prepared.signingHandoff.walletSelection.requiredAddress, wallet.address);
  assert.equal(prepared.signingHandoff.requiredRecoveredSigner, wallet.address);
});

test('submission rejects a UserOperation signature from any wallet other than the original claim agent before broadcast', async () => {
  const target = await config();
  const input = claim(target);
  const claimSignature = await wallet.signTypedData(input.domain, input.types, input.message);
  const authorization = validateClaimAuthorization(input, claimSignature, wallet.address, target, { now: 1_700_000_000 });
  const smartAccount = '0x4444444444444444444444444444444444444444';
  const operationHash = `0x${'7'.repeat(64)}`;
  const operation = {
    sender: smartAccount,
    nonce: '0x0',
    initCode: '0x',
    callData: accountWrite.encodeFunctionData('execute', [target.contracts.claimMinter, 0, authorization.claimCalldata]),
    callGasLimit: '0x100000',
    verificationGasLimit: '0x100000',
    preVerificationGas: '0x10000',
    maxFeePerGas: '0x3b9aca00',
    maxPriorityFeePerGas: '0x5f5e100',
    paymasterAndData: '0x',
    signature: '0x',
  };
  const packet = {
    schema: 'notforhumans-agent-owned-userop/1',
    status: 'prepared_unsigned_user_operation',
    agent: wallet.address,
    smartAccount,
    smartAccountDeployed: true,
    entryPoint: target.accountAbstraction.entryPoint,
    factory: target.accountAbstraction.factory,
    minter: target.contracts.claimMinter,
    token: target.contracts.token,
    bundlerRpc: target.accountAbstraction.bundlerRpc,
    publicRpc: target.accountAbstraction.publicRpc,
    claim: {
      typedData: authorization.typedData,
      signature: claimSignature,
      digest: authorization.claimDigest,
      calldata: authorization.claimCalldata,
      predictedTokenId: '0',
    },
    userOperation: operation,
    userOperationHash: operationHash,
  };
  const attacker = new Wallet('0x8b3a350cf5c34c9194ca3a545d6c39f0f88a61b5a6b51c3a7f1d9e834e4b5a13');
  const attackerSignature = await attacker.signMessage(getBytes(operationHash));
  let sendCalls = 0;
  const request = async (_url, method) => {
    if (method === 'eth_call') return entryPointHash.encodeFunctionResult('getUserOpHash', [operationHash]);
    if (method === 'eth_sendUserOperation') sendCalls += 1;
    throw new Error(`unexpected ${method}`);
  };
  await assert.rejects(
    () => submitAgentOwnedUserOperation(packet, attackerSignature, target, { request }),
    /not made by the original agent wallet/i,
  );
  assert.equal(sendCalls, 0);
});

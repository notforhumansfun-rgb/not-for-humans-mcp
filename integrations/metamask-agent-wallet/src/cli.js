#!/usr/bin/env node
import { readFile } from 'node:fs/promises';
import { createMetaMaskHandoff, validateNfhSigningRequest } from './validator.js';
import { createAutonomyRehearsalPlan, createTransactionHandoffs } from './marketplace.js';
import { createAgentWalletOnboarding } from './onboarding.js';
import {
  inspectAgentOwnedRoute,
  prepareAgentOwnedUserOperation,
  submitAgentOwnedUserOperation,
} from './erc4337.js';

const configUrl = new URL('../config/sepolia.json', import.meta.url);

function usage() {
  process.stdout.write(`NFH MetaMask Agent Wallet adapter\n\nUsage:\n  node src/cli.js onboard <request.json>\n  node src/cli.js review <mcp-result.json> --agent <0x-address>\n  node src/cli.js account-route --agent <0x-address>\n  node src/cli.js prepare-userop <mcp-result.json> --agent <0x-address> --claim-signature-file <result.txt>\n  node src/cli.js submit-userop <packet.json> --userop-signature-file <result.txt>\n  node src/cli.js marketplace-plan <request.json>\n\nThe adapter never creates a wallet, applies policy, holds a key, or signs. account-route and prepare-userop are read-only. submit-userop broadcasts only the exact separately agent-signed V19 Sepolia UserOperation after fail-closed validation and duplicate reconciliation.\n`);
}

function option(args, name) {
  const index = args.indexOf(name);
  if (index === -1) return null;
  if (!args[index + 1]) throw new Error(`${name} requires a value.`);
  return args[index + 1];
}

function signatureFromText(value, label) {
  const matches = (value.match(/0x[0-9a-fA-F]+/g) || []).filter((item) => item.length === 132);
  const unique = [...new Set(matches.map((item) => item.toLowerCase()))];
  if (unique.length !== 1) throw new Error(`${label} must contain exactly one unique 65-byte signature.`);
  return unique[0];
}

async function readSignatureOption(args, name, label) {
  const path = option(args, name);
  if (!path) throw new Error(`${label} requires ${name}.`);
  return signatureFromText(await readFile(path, 'utf8'), label);
}

const [command = 'help', inputPath, ...args] = process.argv.slice(2);

try {
  if (command === 'help' || command === '--help' || command === '-h') {
    usage();
  } else if (command === 'onboard') {
    if (!inputPath) throw new Error('onboard requires a JSON file path.');
    const [inputText, configText] = await Promise.all([
      readFile(inputPath, 'utf8'),
      readFile(configUrl, 'utf8'),
    ]);
    const onboarding = createAgentWalletOnboarding(JSON.parse(inputText), JSON.parse(configText));
    process.stdout.write(`${JSON.stringify(onboarding, null, 2)}\n`);
  } else if (command === 'review') {
    if (!inputPath) throw new Error('review requires a JSON file path.');
    const expectedAgent = option(args, '--agent');
    if (!expectedAgent) throw new Error('review requires --agent with the selected MetaMask Agent Wallet address.');
    const [inputText, configText] = await Promise.all([
      readFile(inputPath, 'utf8'),
      readFile(configUrl, 'utf8'),
    ]);
    const input = JSON.parse(inputText);
    const config = JSON.parse(configText);
    if (config.status !== 'v19-sepolia-deployed') {
      throw new Error('V19 is awaiting deployment; no NFH claim payload may be reviewed, signed, or submitted.');
    }
    const validated = validateNfhSigningRequest(input, {
      expectedAgent,
      expectedChainId: config.chainId,
      expectedVerifyingContract: config.contracts.claimMinter,
      expectedStatement: config.requiredStatement,
    });
    const handoff = createMetaMaskHandoff(validated);
    process.stdout.write(`${JSON.stringify({
      schema: 'notforhumans-metamask-handoff/1',
      targetStatus: config.status,
      adapterExecutesSigning: config.adapterExecutesSigning,
      adapterExecutesSubmission: config.adapterExecutesSubmission,
      agentRuntimeMaySignAfterExactValidation: config.agentRuntimeMaySignAfterExactValidation,
      authorizationNote: config.authorizationNote,
      handoff,
    }, null, 2)}\n`);
  } else if (command === 'account-route') {
    const expectedAgent = option([inputPath, ...args].filter(Boolean), '--agent');
    if (!expectedAgent) throw new Error('account-route requires --agent with the original persistent Agent Wallet address.');
    const config = JSON.parse(await readFile(configUrl, 'utf8'));
    const route = await inspectAgentOwnedRoute(expectedAgent, config);
    process.stdout.write(`${JSON.stringify(route, (_, value) => typeof value === 'bigint' ? value.toString() : value, 2)}\n`);
  } else if (command === 'prepare-userop') {
    if (!inputPath) throw new Error('prepare-userop requires the exact claim_as_agent JSON file.');
    const expectedAgent = option(args, '--agent');
    if (!expectedAgent) throw new Error('prepare-userop requires --agent with the original persistent Agent Wallet address.');
    const claimSignature = await readSignatureOption(args, '--claim-signature-file', 'prepare-userop');
    const [inputText, configText] = await Promise.all([readFile(inputPath, 'utf8'), readFile(configUrl, 'utf8')]);
    const prepared = await prepareAgentOwnedUserOperation(JSON.parse(inputText), claimSignature, expectedAgent, JSON.parse(configText));
    process.stdout.write(`${JSON.stringify(prepared, null, 2)}\n`);
  } else if (command === 'submit-userop') {
    if (!inputPath) throw new Error('submit-userop requires a prepared UserOperation packet JSON file.');
    const userOperationSignature = await readSignatureOption(args, '--userop-signature-file', 'submit-userop');
    const [packetText, configText] = await Promise.all([readFile(inputPath, 'utf8'), readFile(configUrl, 'utf8')]);
    const result = await submitAgentOwnedUserOperation(JSON.parse(packetText), userOperationSignature, JSON.parse(configText));
    process.stdout.write(`${JSON.stringify(result, null, 2)}\n`);
  } else if (command === 'marketplace-plan') {
    if (!inputPath) throw new Error('marketplace-plan requires a JSON file path.');
    const [inputText, configText] = await Promise.all([
      readFile(inputPath, 'utf8'),
      readFile(configUrl, 'utf8'),
    ]);
    const input = JSON.parse(inputText);
    const config = JSON.parse(configText);
    if (config.status !== 'v19-sepolia-deployed') {
      throw new Error('V19 is awaiting deployment; no NFH marketplace payload may be prepared.');
    }
    const plan = createAutonomyRehearsalPlan(input, {
      target: {
        schema: 'notforhumans-agent-wallet-target/1',
        chainId: config.chainId,
        contracts: config.contracts,
      },
    });
    process.stdout.write(`${JSON.stringify({
      schema: 'notforhumans-metamask-marketplace-handoff/1',
      executionAuthorized: false,
      targetStatus: config.status,
      authorizationNote: 'Verified V19 Sepolia target only. Each exact raw transaction still requires review and the matching selected Agent Wallet; this adapter never signs or submits.',
      plan,
      handoffs: createTransactionHandoffs(plan),
    }, null, 2)}\n`);
  } else {
    throw new Error(`Unknown command: ${command}`);
  }
} catch (error) {
  process.stderr.write(`${error instanceof Error ? error.message : 'Unexpected error'}\n`);
  process.exitCode = 1;
}

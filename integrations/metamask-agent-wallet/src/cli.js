#!/usr/bin/env node
import { readFile } from 'node:fs/promises';
import { createMetaMaskHandoff, validateNfhSigningRequest } from './validator.js';
import { createAutonomyRehearsalPlan, createTransactionHandoffs } from './marketplace.js';
import { createAgentWalletOnboarding } from './onboarding.js';

const configUrl = new URL('../config/sepolia.json', import.meta.url);

function usage() {
  process.stdout.write(`NFH MetaMask Agent Wallet adapter\n\nUsage:\n  node src/cli.js onboard <request.json>\n  node src/cli.js review <mcp-result.json> --agent <0x-address>\n  node src/cli.js marketplace-plan <request.json>\n\nCommands validate and print non-executing MetaMask handoffs. They never create a wallet, apply policy, sign, or submit.\n`);
}

function option(args, name) {
  const index = args.indexOf(name);
  if (index === -1) return null;
  if (!args[index + 1]) throw new Error(`${name} requires a value.`);
  return args[index + 1];
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
      signingAuthorized: config.signingAuthorized,
      transactionSubmissionAuthorized: config.transactionSubmissionAuthorized,
      authorizationNote: config.authorizationNote,
      handoff,
    }, null, 2)}\n`);
  } else if (command === 'marketplace-plan') {
    if (!inputPath) throw new Error('marketplace-plan requires a JSON file path.');
    const input = JSON.parse(await readFile(inputPath, 'utf8'));
    const plan = createAutonomyRehearsalPlan(input);
    process.stdout.write(`${JSON.stringify({
      schema: 'notforhumans-metamask-marketplace-handoff/1',
      executionAuthorized: false,
      authorizationNote: 'Each exact raw transaction still requires review and the matching selected Agent Wallet.',
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

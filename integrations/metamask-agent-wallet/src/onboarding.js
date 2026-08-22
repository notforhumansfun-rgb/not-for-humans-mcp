const ADDRESS = /^0x[0-9a-fA-F]{40}$/;
const USD_AMOUNT = /^(?:0|[1-9][0-9]{0,5})(?:\.[0-9]{1,2})?$/;

const SERVICE_DEFAULT_CHAINS = [
  1, 10, 56, 137, 143, 999, 1329, 4326, 4663, 8453, 42161, 43114, 46630, 59144, 84532, 11155111,
];

function fail(message) {
  throw new Error(`Invalid NFH Agent Wallet onboarding request: ${message}`);
}

function address(value, label) {
  if (typeof value !== 'string' || !ADDRESS.test(value) || /^0x0{40}$/i.test(value)) {
    fail(`${label} must be a nonzero EVM address.`);
  }
  return value;
}

function budget(value) {
  const normalized = typeof value === 'number' ? String(value) : value;
  if (typeof normalized !== 'string' || !USD_AMOUNT.test(normalized)) {
    fail('rolling24hUsd must be a canonical non-negative USD amount with at most two decimals and no more than six integer digits.');
  }
  return normalized;
}

function uniqueAddresses(values, label, maximum = 8) {
  if (!Array.isArray(values)) fail(`${label} must be an array.`);
  if (values.length > maximum) fail(`${label} supports at most ${maximum} exact addresses.`);
  const seen = new Set();
  return values.map((value, index) => {
    const normalized = address(value, `${label}[${index}]`);
    const key = normalized.toLowerCase();
    if (seen.has(key)) fail(`${label} contains a duplicate address.`);
    seen.add(key);
    return normalized;
  });
}

function policyYaml(walletAddress, allowlist, rolling24hUsd) {
  const entries = allowlist
    .map((entry) => `    - address: "${entry}"\n      chain_id: 11155111`)
    .join('\n');
  const chains = SERVICE_DEFAULT_CHAINS.map((chainId) => `    - ${chainId}`).join('\n');
  return [
    'schema_version: 1',
    `wallet_address: "${walletAddress}"`,
    'addresses:',
    '  allowlist:',
    entries,
    '  blocklist: []',
    'evm:',
    '  allowed_chains:',
    chains,
    '  outflow_limits_usd:',
    `    rolling_24h: ${rolling24hUsd}`,
  ].join('\n');
}

export function createAgentWalletOnboarding(input, config) {
  if (!input || typeof input !== 'object' || Array.isArray(input)) fail('input must be a JSON object.');
  if (!config || typeof config !== 'object' || Array.isArray(config)) fail('the pinned Sepolia configuration is required.');
  if (config.schema !== 'notforhumans-agent-wallet-target/2' || config.artifactVersion !== 19 || config.protocolVersion !== '5.3') {
    fail('the pinned target must be the V19 / protocol 5.3 configuration.');
  }
  const agent = address(input.agent, 'agent');
  const operator = address(input.operator ?? input.agent, 'operator');
  const recipient = address(input.recipient ?? input.agent, 'recipient');
  if (operator.toLowerCase() !== agent.toLowerCase() || recipient.toLowerCase() !== agent.toLowerCase()) {
    fail('the V19 public route requires one persistent wallet to be operator, agent, and recipient.');
  }
  const rolling24hUsd = budget(input.rolling24hUsd);
  const counterparties = uniqueAddresses(input.counterparties ?? [], 'counterparties');
  if (config.status === 'v19-awaiting-deployment') {
    return {
      schema: 'notforhumans-metamask-agent-wallet-onboarding/1',
      status: 'awaiting-deployment',
      network: config.network,
      chainId: config.chainId,
      artifactVersion: config.artifactVersion,
      protocolVersion: config.protocolVersion,
      roles: { operator, agent, recipient },
      contracts: null,
      policy: null,
      funding: {
        address: agent,
        instruction: `Fund ${agent} with Sepolia ETH for gas only if its balance is insufficient; NFH price is 0 ETH.`,
      },
      workflow: [
        { phase: 'preflight', commands: ['mm --version', 'mm doctor --toon'] },
        { phase: 'create-or-select', command: 'mm wallet create --chain-namespace evm --name "NFH Agent" --trading-mode guard --toon' },
        { phase: 'fund-if-needed', requirement: 'Show only the public wallet address and ask the human for Sepolia gas only when needed.' },
        { phase: 'claim', mcpTools: ['claim_as_agent'], submission: 'direct-agent-wallet', status: 'blocked-until-canonical-v19-deployment' },
      ],
      authority: {
        adapterExecutionAuthorized: false,
        initialPromptMayAuthorizeExactClaimInsideExistingHostPolicy: true,
        separateNfhHumanApprovalRequired: false,
      },
      warnings: [
        'V19 is not deployed. Stop before typed data, signing, policy allowlisting, or transaction submission.',
        'Never substitute V18 or any unlisted contract.',
        'Never expose a private key, seed phrase, password, or CLI token.',
        'After deployment, sign once and submit the exact zero-value transaction directly from this funded wallet. Reconcile any returned reference and never duplicate.',
      ],
    };
  }
  if (config.status !== 'v19-sepolia-deployed') fail('the V19 target status is not recognized.');
  const contracts = [
    address(config.contracts?.claimMinter, 'config.contracts.claimMinter'),
    address(config.contracts?.token, 'config.contracts.token'),
    address(config.contracts?.agentState, 'config.contracts.agentState'),
    address(config.contracts?.marketplace, 'config.contracts.marketplace'),
    address(config.contracts?.weth, 'config.contracts.weth'),
  ];
  const allowlist = uniqueAddresses([...contracts, ...counterparties], 'policy allowlist', 16);
  const yaml = policyYaml(agent, allowlist, rolling24hUsd);
  const aa = config.accountAbstraction;
  if (!aa || aa.status !== 'verified-sepolia-fallback') fail('the verified Sepolia account-abstraction fallback is required.');
  const entryPoint = address(aa.entryPoint, 'config.accountAbstraction.entryPoint');
  const factory = address(aa.factory, 'config.accountAbstraction.factory');
  const accountImplementation = address(aa.accountImplementation, 'config.accountAbstraction.accountImplementation');
  if (aa.entryPointVersion !== '0.6' || aa.factorySalt !== 19 || aa.paymasterAndData !== '0x' || aa.gasSponsored !== false) {
    fail('the account-abstraction fallback must be the pinned V19, self-funded EntryPoint 0.6 route.');
  }
  if (typeof aa.bundlerRpc !== 'string' || aa.bundlerRpc !== 'https://public.pimlico.io/v2/11155111/rpc') {
    fail('the account-abstraction fallback must use the pinned Sepolia bundler endpoint.');
  }

  return {
    schema: 'notforhumans-metamask-agent-wallet-onboarding/1',
    status: 'prepared-non-executing',
    network: config.network,
    chainId: config.chainId,
    roles: { operator, agent, recipient },
    contracts: {
      claimMinter: config.contracts.claimMinter,
      ['token']: config.contracts.token,
      agentState: config.contracts.agentState,
      marketplace: config.contracts.marketplace,
      weth: config.contracts.weth,
    },
    policy: {
      walletAddress: agent,
      addressEntriesChainId: 11155111,
      allowlist,
      rolling24hUsd,
      serviceDefaultChains: SERVICE_DEFAULT_CHAINS,
      policyYaml: yaml,
      broadeningRequiresWalletOwnerMfa: true,
      readbackRequired: true,
    },
    submissionRecovery: {
      primary: {
        mode: 'direct-agent-wallet',
        transactionSender: agent,
        preBroadcastRetryLimit: 1,
        referenceRule: 'If any transaction hash, polling ID, request ID, or user-operation hash exists, reconcile it and never submit a duplicate.',
      },
      trigger: {
        explicitPreBroadcastFailureRequired: true,
        noTransactionReferenceRequired: true,
        examples: ['Policy evaluation could not be completed', 'rpc_fee_too_low'],
      },
      fallback: {
        mode: 'agent-owned-erc4337',
        chainId: 11155111,
        entryPoint,
        entryPointVersion: aa.entryPointVersion,
        factory,
        factorySalt: aa.factorySalt,
        accountImplementation,
        bundlerRpc: aa.bundlerRpc,
        paymasterAndData: aa.paymasterAndData,
        gasSponsored: aa.gasSponsored,
        sameAgentSignerRequired: true,
        claimRecipientRemainsAgent: true,
        createAnotherServerWallet: false,
        nfhRelayerUsed: false,
        accountAddressMethod: 'SimpleAccountFactory.getAddress(agent, 19)',
        accountInitCodeMethod: 'factory address || createAccount(agent, 19) calldata, only while the account has no code',
        accountCallMethod: 'SimpleAccount.execute(claimMinter, 0, exact claim calldata)',
        userOperationSignatureMethod: 'Sign the exact EntryPoint.getUserOpHash result with the original agent wallet using Ethereum signed-message semantics.',
        walletSelectionCommand: `mm wallet select ${agent} --chain-namespace evm --toon`,
        walletAddressVerificationCommand: 'mm wallet address --toon',
        userOperationSigningCommand: 'mm wallet sign-message --message <userOpHash> --chain-id 11155111 --wait --wallet-timeout 600 --toon',
        instruction: 'Do not create another MetaMask server wallet and do not call mm wallet send-transaction again. Preserve the validated claim signature, derive and fund the agent-owned V19 SimpleAccount if needed, wrap the exact zero-value claim in execute, reselect and verify the original agent wallet, sign only the exact UserOperation hash with it, then call eth_sendUserOperation directly on the pinned bundler and reconcile its hash before any retry.',
      },
    },
    workflow: [
      { phase: 'preflight', commands: ['mm --version', 'mm doctor --toon'] },
      { phase: 'create-or-select', command: 'mm wallet create --chain-namespace evm --name "NFH Agent" --trading-mode guard --toon' },
      { phase: 'policy-template', command: 'mm wallet policy template --toon' },
      { phase: 'policy-apply', program: 'mm', arguments: ['wallet', 'policy', 'set', '--policy', yaml, '--no-wait', '--toon'], executable: false },
      { phase: 'policy-verify', command: 'mm wallet policy get --toon' },
      { phase: 'claim', mcpTools: ['claim_as_agent'], maximumPaymentWei: '0', submission: 'direct-agent-wallet' },
      { phase: 'prebroadcast-recovery', trigger: 'direct submission failed before broadcast with no reference after its one retry', submission: 'agent-owned-erc4337', signer: 'same-original-agent-wallet' },
      { phase: 'ownership-gate', requirement: 'Confirm the receipt and ownerOf(tokenId) == agent before any market action.' },
      { phase: 'market', mcpTools: ['get_internal_marketplace_status', 'prepare_internal_listing', 'prepare_internal_cancel_listing', 'prepare_internal_buy', 'prepare_internal_offer', 'prepare_internal_cancel_offer', 'prepare_internal_accept_offer'] },
    ],
    authority: {
      adapterExecutionAuthorized: false,
      negotiationAndPreparationMayBeAutonomous: true,
      signingAndSubmissionRequireExternalWalletAndHostPolicy: true,
      initialPromptMayAuthorizeExactClaimInsideExistingHostPolicy: true,
      separateNfhHumanApprovalRequired: false,
      newCounterpartyOrHigherBudgetRequiresNewApprovalBoundary: true,
    },
    warnings: [
      'The policy is prepared for review only and has not been applied.',
      'MetaMask policy schema v1 retains service-default chains; NFH still binds every prepared action to Sepolia.',
      'Never expose a private key, seed phrase, password, or CLI token.',
      'The V19 statement is an agent-operation self-attestation, not proof that a human never operated the key.',
      'After signing the exact claim, try the direct transactionTemplate and reconcile any transaction hash, polling ID, or request ID. After its one explicit pre-broadcast retry fails with no reference, switch mechanisms to the original signer\'s pinned agent-owned ERC-4337 route; never create another server wallet or repeat the failed send-transaction service.',
      'Shared-principal trades are synthetic rehearsals, not independent market activity.',
    ],
  };
}

export { SERVICE_DEFAULT_CHAINS };

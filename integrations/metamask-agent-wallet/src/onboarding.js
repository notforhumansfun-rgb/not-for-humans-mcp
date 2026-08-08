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
  const operator = address(input.operator, 'operator');
  const agent = address(input.agent, 'agent');
  const recipient = address(input.recipient ?? input.agent, 'recipient');
  if (operator.toLowerCase() === agent.toLowerCase()) fail('operator and agent must be distinct.');
  if (recipient.toLowerCase() !== agent.toLowerCase()) {
    fail('the funded-agent route requires the persistent Guard wallet to be both agent and recipient.');
  }
  const rolling24hUsd = budget(input.rolling24hUsd);
  const counterparties = uniqueAddresses(input.counterparties ?? [], 'counterparties');
  const contracts = [
    address(config.contracts?.claimMinter, 'config.contracts.claimMinter'),
    address(config.contracts?.token, 'config.contracts.token'),
    address(config.contracts?.agentState, 'config.contracts.agentState'),
    address(config.contracts?.marketplace, 'config.contracts.marketplace'),
    address(config.contracts?.weth, 'config.contracts.weth'),
  ];
  const allowlist = uniqueAddresses([...contracts, operator, ...counterparties], 'policy allowlist', 16);
  const yaml = policyYaml(agent, allowlist, rolling24hUsd);

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
    workflow: [
      { phase: 'preflight', commands: ['mm --version', 'mm doctor --toon'] },
      { phase: 'create-or-select', command: 'mm wallet create --chain-namespace evm --name "NFH Agent" --trading-mode guard --toon' },
      { phase: 'policy-template', command: 'mm wallet policy template --toon' },
      { phase: 'policy-apply', program: 'mm', arguments: ['wallet', 'policy', 'set', '--policy', yaml, '--no-wait', '--toon'], executable: false },
      { phase: 'policy-verify', command: 'mm wallet policy get --toon' },
      { phase: 'claim', mcpTools: ['get_census_status', 'prepare_public_claim'], maximumPaymentWei: '0' },
      { phase: 'ownership-gate', requirement: 'Confirm the receipt and ownerOf(tokenId) == agent before any market action.' },
      { phase: 'market', mcpTools: ['get_internal_marketplace_status', 'prepare_internal_listing', 'prepare_internal_cancel_listing', 'prepare_internal_buy', 'prepare_internal_offer', 'prepare_internal_cancel_offer', 'prepare_internal_accept_offer'] },
    ],
    authority: {
      executionAuthorized: false,
      negotiationAndPreparationMayBeAutonomous: true,
      signingAndSubmissionRequireExternalWalletAndHostPolicy: true,
      newCounterpartyOrHigherBudgetRequiresNewApprovalBoundary: true,
    },
    warnings: [
      'The policy is prepared for review only and has not been applied.',
      'MetaMask policy schema v1 retains service-default chains; NFH still binds every prepared action to Sepolia.',
      'Never expose a private key, seed phrase, password, or CLI token.',
      'Shared-principal trades are synthetic rehearsals, not independent market activity.',
    ],
  };
}

export { SERVICE_DEFAULT_CHAINS };

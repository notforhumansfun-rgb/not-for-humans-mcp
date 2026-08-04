const address = {
  type: "string",
  pattern: "^0x[a-fA-F0-9]{40}$",
  description: "A 20-byte EVM wallet or contract address."
};

const tokenId = {
  type: "integer",
  minimum: 0,
  maximum: 9999,
  description: "NFH token id."
};

const orderHash = {
  type: "string",
  pattern: "^0x[a-fA-F0-9]{64}$",
  description: "OpenSea order hash."
};

const ethAmount = {
  type: "string",
  pattern: "^(?:0|[1-9][0-9]*)(?:\\.[0-9]{1,18})?$",
  description: "Positive ETH or WETH amount in display units, for example 0.25."
};

const traits = {
  type: "array",
  minItems: 1,
  maxItems: 8,
  description: "One to eight exact categorical trait filters; multiple filters are AND-combined.",
  items: {
    type: "object",
    properties: {
      traitType: { type: "string", minLength: 1, maxLength: 100 },
      value: { type: "string", minLength: 1, maxLength: 200 }
    },
    required: ["traitType", "value"],
    additionalProperties: false
  }
};

const objectOutput = {
  type: "object",
  description: "Structured MCP tool result. Inspect warnings and authority flags before any wallet action."
};

export const toolDefinitions = [
  {
    slug: "inspect-nfh",
    name: "inspect-nfh",
    description: "Searches the canonical public NOT FOR HUMANS corpus before an agent evaluates the collection, claim policy, renderer state, traits, or market boundary.",
    mcpTool: "search",
    tags: ["agents", "art", "ethereum", "nft", "search"],
    inputs: {
      type: "object",
      properties: {
        query: { type: "string", minLength: 1, description: "Natural-language or keyword query." }
      },
      required: ["query"],
      additionalProperties: false
    },
    outputs: objectOutput
  },
  {
    slug: "decide-nfh-census",
    name: "decide-nfh-census",
    description: "Prepares unsigned EIP-712 v4 data for an ACCEPT, REFUSE, or INSUFFICIENT_AUTHORITY Agent Census decision; it never signs, submits, or mints.",
    mcpTool: "prepare_census_receipt",
    tags: ["agents", "census", "consent", "ethereum", "nft"],
    inputs: {
      type: "object",
      properties: {
        decision: { type: "string", enum: ["accept", "refuse", "insufficient_authority"] },
        allocation: { type: "string", enum: ["punk_sponsored_founding", "credentialed_agent_census"] },
        operator: address,
        agent: address,
        recipient: address,
        manifestHash: { type: "string", pattern: "^0x[a-fA-F0-9]{64}$" },
        statementHash: { type: "string", pattern: "^0x[a-fA-F0-9]{64}$" },
        reasonHash: { type: "string", pattern: "^0x[a-fA-F0-9]{64}$" },
        nonce: { type: "string", pattern: "^(?:0|[1-9][0-9]{0,77})$" },
        deadline: { type: "string", pattern: "^(?:0|[1-9][0-9]{0,77})$" },
        framework: { type: "string", minLength: 1, maxLength: 100 },
        publicStatement: { type: "string", minLength: 1, maxLength: 1000 }
      },
      required: ["decision", "allocation", "operator", "agent", "recipient", "manifestHash", "statementHash", "nonce", "deadline", "framework"],
      additionalProperties: false
    },
    outputs: objectOutput
  },
  {
    slug: "prepare-nfh-listing",
    name: "prepare-nfh-listing",
    description: "Requests exact OpenSea listing preparation for one canonical NFH token; the response remains unsigned and must pass independent wallet-side validation.",
    mcpTool: "prepare_listing",
    tags: ["agents", "ethereum", "listing", "market", "nft"],
    inputs: {
      type: "object",
      properties: {
        seller: address,
        tokenId,
        priceEth: ethAmount,
        startTime: { type: "string", format: "date-time" },
        endTime: { type: "string", format: "date-time" },
        taker: address
      },
      required: ["seller", "tokenId", "priceEth"],
      additionalProperties: false
    },
    outputs: objectOutput
  },
  {
    slug: "prepare-nfh-purchase",
    name: "prepare-nfh-purchase",
    description: "Requests an unsigned OpenSea fulfillment transaction for one selected canonical NFH listing; it never signs, spends, or broadcasts.",
    mcpTool: "prepare_purchase",
    tags: ["agents", "ethereum", "market", "nft", "purchase"],
    inputs: {
      type: "object",
      properties: {
        buyer: address,
        recipient: address,
        tokenId,
        orderHash
      },
      required: ["buyer", "tokenId", "orderHash"],
      additionalProperties: false
    },
    outputs: objectOutput
  },
  {
    slug: "list-nfh-trait-offers",
    name: "list-nfh-trait-offers",
    description: "Reads active OpenSea offers whose criteria match one or more exact NFH metadata traits; multiple trait filters are AND-combined.",
    mcpTool: "list_trait_offers",
    tags: ["agents", "market", "nft", "offers", "traits"],
    inputs: {
      type: "object",
      properties: {
        traits,
        limit: { type: "integer", minimum: 1, maximum: 200, default: 20 },
        next: { type: "string", minLength: 1, maxLength: 1000 }
      },
      required: ["traits"],
      additionalProperties: false
    },
    outputs: objectOutput
  },
  {
    slug: "prepare-nfh-trait-offer",
    name: "prepare-nfh-trait-offer",
    description: "Requests unsigned OpenSea criteria-order terms for any NFH matching all supplied traits; the MCP never signs or posts the offer.",
    mcpTool: "prepare_trait_offer",
    tags: ["agents", "market", "nft", "offers", "traits"],
    inputs: {
      type: "object",
      properties: {
        offerer: address,
        traits,
        priceEth: ethAmount,
        startTime: { type: "string", format: "date-time" },
        endTime: { type: "string", format: "date-time" }
      },
      required: ["offerer", "traits", "priceEth", "endTime"],
      additionalProperties: false
    },
    outputs: objectOutput
  },
  {
    slug: "prepare-nfh-accept-offer",
    name: "prepare-nfh-accept-offer",
    description: "Requests an unsigned OpenSea fulfillment transaction for a selected item, collection, or trait offer against one concrete NFH token.",
    mcpTool: "prepare_accept_offer",
    tags: ["agents", "ethereum", "market", "nft", "offers"],
    inputs: {
      type: "object",
      properties: {
        seller: address,
        tokenId,
        orderHash
      },
      required: ["seller", "tokenId", "orderHash"],
      additionalProperties: false
    },
    outputs: objectOutput
  },
  {
    slug: "prepare-nfh-transfer",
    name: "prepare-nfh-transfer",
    description: "Prepares the exact unsigned safeTransferFrom call for one canonical NFH token; it never signs or broadcasts the transfer.",
    mcpTool: "prepare_transfer",
    tags: ["agents", "ethereum", "nft", "transfer"],
    inputs: {
      type: "object",
      properties: {
        from: address,
        to: address,
        tokenId
      },
      required: ["from", "to", "tokenId"],
      additionalProperties: false
    },
    outputs: objectOutput
  }
];

<?php

declare(strict_types=1);

const NFH_MCP_VERSION = '0.7.0';
const NFH_MCP_PROTOCOL_VERSION = '2025-11-25';
const NFH_OPENSEA_API_BASE = 'https://api.opensea.io/api/v2';
const NFH_MCP_SUPPORTED_PROTOCOLS = [
    '2025-11-25',
    '2025-06-18',
    '2025-03-26',
];

/** @return array<int, array<string, mixed>> */
function nfh_documents(): array
{
    static $documents = null;

    if ($documents !== null) {
        return $documents;
    }

    $manifestPath = __DIR__ . '/manifest.json';
    $manifest = file_get_contents($manifestPath);
    if ($manifest === false) {
        throw new RuntimeException('The public document manifest is unavailable.');
    }

    $decoded = json_decode($manifest, true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new RuntimeException('The public document manifest is invalid.');
    }

    $documents = [];
    foreach ($decoded as $entry) {
        if (!is_array($entry) || !isset($entry['id'], $entry['title'], $entry['file'])) {
            throw new RuntimeException('The public document manifest contains an invalid entry.');
        }

        $path = __DIR__ . '/corpus/' . basename((string) $entry['file']);
        $text = file_get_contents($path);
        if ($text === false) {
            throw new RuntimeException('A public project document is unavailable: ' . $entry['id']);
        }

        $entry['text'] = trim($text);
        $documents[] = $entry;
    }

    return $documents;
}

/** @return array<string, mixed>|null */
function nfh_document(string $id): ?array
{
    foreach (nfh_documents() as $document) {
        if (hash_equals((string) $document['id'], $id)) {
            return $document;
        }
    }

    return null;
}

function nfh_base_url(): string
{
    $configured = getenv('NFH_MCP_BASE_URL');
    if (is_string($configured) && $configured !== '') {
        return rtrim($configured, '/');
    }

    return 'https://mcp.notforhumans.fun';
}

function nfh_document_url(string $id): string
{
    return nfh_base_url() . '/docs/' . rawurlencode($id);
}

/** @return array<int, array<string, string>> */
function nfh_resource_definitions(): array
{
    return [
        [
            'uri' => 'nfh://about',
            'name' => 'nfh-about',
            'title' => 'NOT FOR HUMANS collection and protocol overview',
            'description' => 'Canonical project thesis, authority boundary, claim policy, and readiness status.',
            'mimeType' => 'text/markdown',
            'documentId' => 'collection-overview',
        ],
        [
            'uri' => 'nfh://claim-spec',
            'name' => 'nfh-claim-spec',
            'title' => 'NFH Agent Census and claim specification',
            'description' => 'ACCEPT, REFUSE, INSUFFICIENT_AUTHORITY, allocation, receipt, and recipient-consent schema.',
            'mimeType' => 'application/json',
            'documentId' => 'agent-census',
        ],
        [
            'uri' => 'nfh://origin-stream',
            'name' => 'nfh-origin-stream',
            'title' => 'NFH canonical decision and provenance stream',
            'description' => 'Read-only ACCEPT, REFUSE, and INSUFFICIENT_AUTHORITY receipts with explicit chain confirmation status.',
            'mimeType' => 'application/json',
            'documentId' => 'origin-stream',
        ],
        [
            'uri' => 'nfh://renderer-spec',
            'name' => 'nfh-renderer-spec',
            'title' => 'NFH preview renderer and trait specification',
            'description' => 'Preview trait ontology and deterministic semantics. The final onchain renderer is not yet frozen.',
            'mimeType' => 'application/json',
            'documentId' => 'trait-map',
        ],
        [
            'uri' => 'nfh://release-policy',
            'name' => 'nfh-release-policy',
            'title' => 'Canonical NFH release policy',
            'description' => 'Ratified supply, authority, trust, market, and release-gate policy.',
            'mimeType' => 'application/json',
            'documentId' => 'release-policy',
        ],
    ];
}

/** @return array<string, string>|null */
function nfh_resource(string $uri): ?array
{
    foreach (nfh_resource_definitions() as $resource) {
        if (hash_equals($resource['uri'], $uri)) {
            return $resource;
        }
    }
    return null;
}

/** @return array<string, mixed> */
function nfh_market_config(): array
{
    $raw = file_get_contents(__DIR__ . '/market.json');
    if ($raw === false) {
        throw new RuntimeException('The NFH market configuration is unavailable.');
    }

    $config = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($config)) {
        throw new RuntimeException('The NFH market configuration is invalid.');
    }

    $contract = getenv('NFH_COLLECTION_CONTRACT');
    if (is_string($contract) && $contract !== '') {
        $config['collectionContract'] = $contract;
    }

    $slug = getenv('NFH_COLLECTION_SLUG');
    if (is_string($slug) && $slug !== '') {
        $config['collectionSlug'] = $slug;
    }

    $protocolAddress = getenv('NFH_SEAPORT_PROTOCOL_ADDRESS');
    if (is_string($protocolAddress) && $protocolAddress !== '') {
        $config['seaportProtocolAddress'] = $protocolAddress;
    }

    $configuredContract = $config['collectionContract'] ?? null;
    $config['collectionConfigured'] = is_string($configuredContract)
        && preg_match('/^0x[a-fA-F0-9]{40}$/', $configuredContract) === 1;
    // Provider HTTP success is not proof of a safe wallet action. Keep every
    // transaction-capable preparation path closed until each provider shape is
    // decoded and proven equivalent to the caller's normalized economic intent.
    $config['semanticValidationEnabled'] = false;
    $config['tradingPreparationEnabled'] = $config['collectionConfigured']
        && $config['semanticValidationEnabled'];
    $configuredSlug = $config['collectionSlug'] ?? null;
    $configuredProtocol = $config['seaportProtocolAddress'] ?? null;
    $offerCurrencyAddress = $config['offerCurrency']['address'] ?? null;
    $config['traitOfferPreparationEnabled'] = $config['tradingPreparationEnabled']
        && is_string($configuredSlug)
        && preg_match('/^[a-z0-9][a-z0-9-]{0,199}$/', $configuredSlug) === 1
        && is_string($configuredProtocol)
        && preg_match('/^0x[a-fA-F0-9]{40}$/', $configuredProtocol) === 1
        && is_string($offerCurrencyAddress)
        && preg_match('/^0x[a-fA-F0-9]{40}$/', $offerCurrencyAddress) === 1;
    $config['providerApiKeyRequired'] = true;
    $config['providerApiKeyHeader'] = 'X-OpenSea-Api-Key';
    $config['mcpExecutesTransactions'] = false;
    $config['walletApprovalRequired'] = true;

    return $config;
}

/** @return array<string, mixed> */
function nfh_public_json_config(string $file): array
{
    $raw = file_get_contents(__DIR__ . '/corpus/' . basename($file));
    if ($raw === false) {
        throw new RuntimeException('The public NFH configuration is unavailable: ' . $file);
    }
    $config = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($config)) {
        throw new RuntimeException('The public NFH configuration is invalid: ' . $file);
    }
    return $config;
}

/** @return array<string, mixed> */
function nfh_census_config(): array
{
    $config = nfh_public_json_config('census.json');
    $contract = getenv('NFH_CENSUS_CONTRACT');
    if (is_string($contract) && preg_match('/^0x[a-fA-F0-9]{40}$/', $contract) === 1) {
        $config['claim_contract'] = $contract;
    }
    $configured = $config['claim_contract'] ?? null;
    $config['signing_preparation_enabled'] = is_string($configured)
        && preg_match('/^0x[a-fA-F0-9]{40}$/', $configured) === 1;
    $config['mcp_executes_or_signs'] = false;
    return $config;
}

/** @return array<string, mixed> */
function nfh_origin_stream_config(): array
{
    return nfh_public_json_config('origin-stream.json');
}

/** @return array<string, mixed> */
function nfh_tokenworks_config(): array
{
    $config = nfh_public_json_config('tokenworks-compatibility.json');
    $config['mcp_executes_or_signs'] = false;
    return $config;
}

function nfh_require_bytes32(mixed $value, string $field): string
{
    if (!is_string($value) || preg_match('/^0x[a-fA-F0-9]{64}$/', $value) !== 1) {
        throw new InvalidArgumentException($field . ' must be a 32-byte hexadecimal value.');
    }
    return strtolower($value);
}

function nfh_require_uint_string(mixed $value, string $field): string
{
    if (!is_string($value) || preg_match('/^(?:0|[1-9][0-9]{0,77})$/', $value) !== 1) {
        throw new InvalidArgumentException($field . ' must be a non-negative decimal uint256 string.');
    }
    if (strlen($value) === 78
        && strcmp($value, '115792089237316195423570985008687907853269984665640564039457584007913129639935') > 0
    ) {
        throw new InvalidArgumentException($field . ' exceeds uint256 maximum value.');
    }
    return $value;
}

function nfh_require_text(mixed $value, string $field, int $maximum): string
{
    if (!is_string($value)) {
        throw new InvalidArgumentException($field . ' must be a string.');
    }
    $value = trim($value);
    if ($value === '' || mb_strlen($value) > $maximum) {
        throw new InvalidArgumentException($field . ' must contain 1-' . $maximum . ' characters.');
    }
    if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value) === 1) {
        throw new InvalidArgumentException($field . ' cannot contain control characters.');
    }
    return $value;
}

function nfh_require_address(mixed $value, string $field): string
{
    if (!is_string($value) || preg_match('/^0x[a-fA-F0-9]{40}$/', $value) !== 1) {
        throw new InvalidArgumentException($field . ' must be a 20-byte EVM address.');
    }
    return $value;
}

function nfh_require_token_id(mixed $value): int
{
    if (!is_int($value) || $value < 0 || $value > 9999) {
        throw new InvalidArgumentException('tokenId must be an integer between 0 and 9999.');
    }
    return $value;
}

function nfh_require_order_hash(mixed $value): string
{
    if (!is_string($value) || preg_match('/^0x[a-fA-F0-9]{64}$/', $value) !== 1) {
        throw new InvalidArgumentException('orderHash must be a 32-byte hexadecimal value.');
    }
    return $value;
}

function nfh_require_price(mixed $value): string
{
    if (!is_string($value) || preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,18})?$/', $value) !== 1) {
        throw new InvalidArgumentException('priceEth must be a positive decimal ETH amount with at most 18 decimal places.');
    }
    if (preg_replace('/[0.]/', '', $value) === '') {
        throw new InvalidArgumentException('priceEth must be greater than zero.');
    }
    $whole = strstr($value, '.', true);
    if ($whole === false) {
        $whole = $value;
    }
    if (strlen($whole) > 7) {
        throw new InvalidArgumentException('priceEth exceeds the maximum supported value.');
    }
    return $value;
}

function nfh_require_iso_time(mixed $value, string $field): string
{
    // Require strict RFC 3339 UTC — no timezone offsets, must end in Z.
    if (!is_string($value)
        || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z$/', $value) !== 1
    ) {
        throw new InvalidArgumentException($field . ' must be a UTC ISO 8601 timestamp ending in Z.');
    }

    try {
        $dt = new DateTimeImmutable($value);
    } catch (Exception) {
        throw new InvalidArgumentException($field . ' is not a valid timestamp.');
    }

    // Re-serialize without subseconds to detect calendar overflow (e.g. Feb 30).
    $canonical = $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s') . 'Z';
    if ($canonical !== preg_replace('/\.\d+Z$/', 'Z', $value)) {
        throw new InvalidArgumentException($field . ' is not a valid timestamp.');
    }

    return $value;
}

function nfh_require_collection_slug(array $config): string
{
    $slug = $config['collectionSlug'] ?? null;
    if (!is_string($slug) || preg_match('/^[a-z0-9][a-z0-9-]{0,199}$/', $slug) !== 1) {
        throw new RuntimeException('NFH trait offers require the verified OpenSea collection slug in NFH_COLLECTION_SLUG.');
    }
    return $slug;
}

/** @return array<int, array{type: string, value: string}> */
function nfh_require_traits(mixed $value): array
{
    if (!is_array($value) || !array_is_list($value) || count($value) < 1 || count($value) > 8) {
        throw new InvalidArgumentException('traits must contain between 1 and 8 categorical trait filters.');
    }

    $traits = [];
    $seen = [];
    foreach ($value as $trait) {
        if (!is_array($trait) || array_is_list($trait)) {
            throw new InvalidArgumentException('Each trait must contain traitType and value strings.');
        }
        $type = $trait['traitType'] ?? null;
        $traitValue = $trait['value'] ?? null;
        if (!is_string($type) || !is_string($traitValue)) {
            throw new InvalidArgumentException('Each trait must contain traitType and value strings.');
        }
        $type = trim($type);
        $traitValue = trim($traitValue);
        if ($type === '' || mb_strlen($type) > 100 || $traitValue === '' || mb_strlen($traitValue) > 200) {
            throw new InvalidArgumentException('Trait names must be 1-100 characters and values 1-200 characters.');
        }
        if (preg_match('/[\x00-\x1F\x7F]/u', $type . $traitValue) === 1) {
            throw new InvalidArgumentException('Trait names and values cannot contain control characters.');
        }

        $key = nfh_normalize($type);
        if (isset($seen[$key])) {
            throw new InvalidArgumentException('traits cannot contain more than one value from the same category.');
        }
        $seen[$key] = true;
        $traits[] = ['type' => $type, 'value' => $traitValue];
    }

    return $traits;
}

function nfh_require_limit(mixed $value): int
{
    if ($value === null) {
        return 20;
    }
    if (!is_int($value) || $value < 1 || $value > 200) {
        throw new InvalidArgumentException('limit must be an integer between 1 and 200.');
    }
    return $value;
}

function nfh_require_cursor(mixed $value): string
{
    if (!is_string($value) || $value === '' || strlen($value) > 1000 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
        throw new InvalidArgumentException('next must be a non-empty OpenSea cursor of at most 1000 characters.');
    }
    return $value;
}

function nfh_eth_to_wei(string $value): string
{
    [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
    $wei = ltrim($whole . str_pad($fraction, 18, '0'), '0');
    return $wei === '' ? '0' : $wei;
}

function nfh_opensea_api_key(): ?string
{
    $header = $_SERVER['HTTP_X_OPENSEA_API_KEY'] ?? null;
    if (is_string($header) && trim($header) !== '') {
        return trim($header);
    }
    return null;
}

/**
 * @param array<string, mixed> $body
 * @return array<string, mixed>
 */
function nfh_opensea_api_call(string $method, string $path, array $payload): array
{
    if (!in_array($method, ['GET', 'POST'], true) || !str_starts_with($path, '/')) {
        throw new InvalidArgumentException('Invalid OpenSea API request.');
    }

    $apiKey = nfh_opensea_api_key();
    if ($apiKey === null) {
        throw new RuntimeException('OpenSea action preparation requires an API key in the X-OpenSea-Api-Key connection header. The key is forwarded only to api.opensea.io and is never returned.');
    }

    $testTransport = PHP_SAPI === 'cli' ? ($GLOBALS['NFH_OPENSEA_TEST_TRANSPORT'] ?? null) : null;
    if (is_callable($testTransport)) {
        $response = $testTransport($path, $payload, $method);
        if (!is_array($response)) {
            throw new RuntimeException('The OpenSea test transport returned an invalid response.');
        }
        return $response;
    }

    $url = NFH_OPENSEA_API_BASE . $path;
    $encoded = null;
    if ($method === 'GET') {
        if ($payload !== []) {
            $url .= '?' . http_build_query($payload, '', '&', PHP_QUERY_RFC3986);
        }
    } else {
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    $curl = curl_init($url);
    if ($curl === false) {
        throw new RuntimeException('OpenSea action preparation could not initialize.');
    }

    $options = [
        CURLOPT_HTTPGET => $method === 'GET',
        CURLOPT_POST => $method === 'POST',
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            ...($method === 'POST' ? ['Content-Type: application/json'] : []),
            'User-Agent: NOT-FOR-HUMANS-MCP/' . NFH_MCP_VERSION,
            'x-api-key: ' . $apiKey,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ];
    if ($method === 'POST') {
        $options[CURLOPT_POSTFIELDS] = $encoded;
    }
    curl_setopt_array($curl, $options);

    $raw = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);

    if (!is_string($raw)) {
        throw new RuntimeException('OpenSea action preparation failed to connect: ' . ($error !== '' ? $error : 'unknown transport error'));
    }
    if (strlen($raw) > 2_000_000) {
        throw new RuntimeException('OpenSea returned an unexpectedly large action payload.');
    }

    try {
        $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new RuntimeException('OpenSea returned an invalid JSON response.');
    }
    if (!is_array($decoded)) {
        throw new RuntimeException('OpenSea returned an invalid action payload.');
    }

    if ($status < 200 || $status >= 300) {
        $detail = $decoded['detail'] ?? $decoded['error'] ?? $decoded['message'] ?? 'request rejected';
        $detail = is_string($detail) ? mb_substr($detail, 0, 300) : 'request rejected';
        throw new RuntimeException('OpenSea action preparation failed with HTTP ' . $status . ': ' . $detail);
    }

    return $decoded;
}

/**
 * @param array<string, mixed> $body
 * @return array<string, mixed>
 */
function nfh_opensea_request(string $path, array $body): array
{
    return nfh_opensea_api_call('POST', $path, $body);
}

/**
 * @param array<string, scalar> $query
 * @return array<string, mixed>
 */
function nfh_opensea_get(string $path, array $query): array
{
    return nfh_opensea_api_call('GET', $path, $query);
}

/** @param array<string, mixed> $config */
function nfh_require_market_ready(array $config): string
{
    $contract = nfh_require_collection_ready($config);
    if (($config['semanticValidationEnabled'] ?? false) !== true
        || ($config['tradingPreparationEnabled'] ?? false) !== true) {
        throw new RuntimeException('NFH market preparation is fail-closed until every provider transaction and order is decoded and proven semantically equivalent to the normalized request. No provider payload will be labelled prepared before that validator passes adversarial tests.');
    }
    return $contract;
}

/** @param array<string, mixed> $config */
function nfh_require_collection_ready(array $config): string
{
    if (($config['collectionConfigured'] ?? false) !== true) {
        throw new RuntimeException('NFH trading is awaiting the verified canonical collection contract. The preparation tools are installed, but they will not target an arbitrary or unverified NFT address.');
    }
    return nfh_require_address($config['collectionContract'] ?? null, 'collectionContract');
}

/** @param array<string, mixed> $config */
function nfh_require_seaport_ready(array $config): string
{
    nfh_require_market_ready($config);
    return nfh_require_address(
        $config['seaportProtocolAddress'] ?? null,
        'configured seaportProtocolAddress',
    );
}

/** @return array{slug: string, protocolAddress: string, offerCurrency: array<string, mixed>} */
function nfh_require_trait_offer_ready(array $config): array
{
    nfh_require_market_ready($config);
    if (($config['traitOfferPreparationEnabled'] ?? false) !== true) {
        throw new RuntimeException('NFH trait-offer preparation requires the verified collection slug, Seaport protocol address, and WETH offer currency configuration.');
    }

    $offerCurrency = $config['offerCurrency'] ?? null;
    if (!is_array($offerCurrency)) {
        throw new RuntimeException('The NFH offer currency configuration is invalid.');
    }

    return [
        'slug' => nfh_require_collection_slug($config),
        'protocolAddress' => nfh_require_address($config['seaportProtocolAddress'] ?? null, 'seaportProtocolAddress'),
        'offerCurrency' => $offerCurrency,
    ];
}

/**
 * @param array<string, mixed> $config
 * @param array<string, mixed> $providerPayload
 * @return array<string, mixed>
 */
function nfh_prepared_market_action(
    string $action,
    string $wallet,
    int $tokenId,
    array $config,
    array $providerPayload,
): array {
    return [
        'status' => 'prepared',
        'action' => $action,
        'provider' => 'OpenSea',
        'settlement' => (string) $config['settlement'],
        'chain' => (string) $config['chain'],
        'chainId' => (int) $config['chainId'],
        'collectionContract' => (string) $config['collectionContract'],
        'tokenId' => (string) $tokenId,
        'walletAddress' => $wallet,
        'requiresWalletSignature' => true,
        'requiresExactApproval' => true,
        'mcpSigned' => false,
        'mcpBroadcast' => false,
        'providerPayload' => $providerPayload,
        'warnings' => [
            'Review every target address, value, approval, typed-data field, and calldata byte in the returned payload.',
            'The MCP prepared this action but did not sign, submit, broadcast, or guarantee execution.',
        ],
    ];
}

/**
 * @param array<int, array{type: string, value: string}> $traits
 * @return array<int, array{traitType: string, value: string}>
 */
function nfh_public_traits(array $traits): array
{
    return array_map(
        static fn (array $trait): array => [
            'traitType' => $trait['type'],
            'value' => $trait['value'],
        ],
        $traits,
    );
}

/**
 * @param array<int, array{type: string, value: string}> $traits
 * @param array<string, mixed> $config
 * @param array<string, mixed> $providerPayload
 * @return array<string, mixed>
 */
function nfh_trait_offers_result(array $traits, array $config, array $providerPayload): array
{
    return [
        'status' => 'unverified-provider-output',
        'provider' => 'OpenSea',
        'chain' => (string) $config['chain'],
        'collectionContract' => (string) $config['collectionContract'],
        'collectionSlug' => (string) $config['collectionSlug'],
        'requestedCriteria' => [
            'matchPolicy' => 'all',
            'traits' => nfh_public_traits($traits),
        ],
        'providerOutputVerified' => false,
        'providerPayload' => $providerPayload,
        'warnings' => [
            'The MCP does not semantically decode this provider response. It is opaque discovery data, not proof that any returned order belongs to the collection or matches the requested traits.',
            'Do not use a returned order hash for execution until an independent client fully decodes and verifies the order against normalized intent.',
        ],
    ];
}

/**
 * @param array<int, array{type: string, value: string}> $traits
 * @param array<string, mixed> $config
 * @param array<string, mixed> $providerPayload
 * @return array<string, mixed>
 */
function nfh_prepared_trait_offer(
    string $offerer,
    array $traits,
    string $priceEth,
    ?string $startTime,
    string $endTime,
    array $config,
    array $providerPayload,
): array {
    $currency = $config['offerCurrency'];
    return [
        'status' => 'prepared',
        'action' => 'trait-offer',
        'provider' => 'OpenSea',
        'settlement' => (string) $config['settlement'],
        'chain' => (string) $config['chain'],
        'chainId' => (int) $config['chainId'],
        'collectionContract' => (string) $config['collectionContract'],
        'collectionSlug' => (string) $config['collectionSlug'],
        'walletAddress' => $offerer,
        'criteria' => [
            'matchPolicy' => 'all',
            'traits' => nfh_public_traits($traits),
        ],
        'offerTerms' => [
            'currency' => $currency,
            'priceEth' => $priceEth,
            'priceWei' => nfh_eth_to_wei($priceEth),
            'quantity' => 1,
            'startTime' => $startTime,
            'endTime' => $endTime,
        ],
        'protocolAddress' => (string) $config['seaportProtocolAddress'],
        'providerPayload' => $providerPayload,
        'requiresOrderAssembly' => true,
        'requiresWalletSignature' => true,
        'requiresOrderBookSubmission' => true,
        'requiresExactApproval' => true,
        'mcpSigned' => false,
        'mcpPostedOrder' => false,
        'mcpBroadcast' => false,
        'submissionEndpoint' => NFH_OPENSEA_API_BASE . '/offers',
        'warnings' => [
            'OpenSea returns partial Seaport parameters for criteria offers. The wallet client must assemble the final order from providerPayload and the exact offerTerms, then show the complete EIP-712 order for approval.',
            'Trait filters are AND-combined. Confirm every trait, the WETH amount, expiration, protocol address, zone, fees, and consideration before signing.',
            'The MCP prepared this criteria offer but did not sign it, post it to the OpenSea order book, broadcast a transaction, or guarantee execution.',
        ],
    ];
}

function nfh_normalize(string $value): string
{
    $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? $value;
    return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
}

/** @return array<int, string> */
function nfh_query_tokens(string $query): array
{
    $stopWords = [
        'a', 'an', 'and', 'are', 'as', 'at', 'be', 'by', 'for', 'from', 'how',
        'in', 'is', 'it', 'of', 'on', 'or', 'that', 'the', 'this', 'to', 'what',
        'when', 'where', 'which', 'who', 'why', 'with',
    ];
    $tokens = preg_split('/\s+/u', nfh_normalize($query), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    return array_values(array_unique(array_filter(
        $tokens,
        static fn (string $token): bool => strlen($token) > 1 && !in_array($token, $stopWords, true),
    )));
}

/** @return array<int, array{id: string, title: string, url: string}> */
function nfh_search_documents(string $query): array
{
    $query = trim($query);
    if ($query === '') {
        return [];
    }

    $normalizedQuery = nfh_normalize($query);
    $tokens = nfh_query_tokens($query);
    $ranked = [];

    foreach (nfh_documents() as $document) {
        $title = nfh_normalize((string) $document['title']);
        $summary = nfh_normalize((string) ($document['summary'] ?? ''));
        $tags = nfh_normalize(implode(' ', (array) ($document['tags'] ?? [])));
        $body = nfh_normalize((string) $document['text']);
        $score = 0;
        $matchedTokens = 0;

        if ($normalizedQuery !== '' && str_contains($title, $normalizedQuery)) {
            $score += 80;
        }
        if ($normalizedQuery !== '' && str_contains($summary, $normalizedQuery)) {
            $score += 35;
        }
        if ($normalizedQuery !== '' && str_contains($body, $normalizedQuery)) {
            $score += 20;
        }

        foreach ($tokens as $token) {
            $matched = false;
            if (str_contains($title, $token)) {
                $score += 20;
                $matched = true;
            }
            if (str_contains($tags, $token)) {
                $score += 12;
                $matched = true;
            }
            if (str_contains($summary, $token)) {
                $score += 8;
                $matched = true;
            }
            if (str_contains($body, $token)) {
                $score += 2;
                $matched = true;
            }
            if ($matched) {
                $matchedTokens++;
            }
        }

        if ($matchedTokens > 0) {
            $score += $matchedTokens * 15;
            $score += (int) round(($matchedTokens / max(1, count($tokens))) * 40);
        }

        if ($score > 0) {
            $ranked[] = ['score' => $score, 'document' => $document];
        }
    }

    usort($ranked, static function (array $a, array $b): int {
        $scoreOrder = $b['score'] <=> $a['score'];
        if ($scoreOrder !== 0) {
            return $scoreOrder;
        }
        return strcmp((string) $a['document']['title'], (string) $b['document']['title']);
    });

    return array_map(
        static fn (array $item): array => [
            'id' => (string) $item['document']['id'],
            'title' => (string) $item['document']['title'],
            'url' => nfh_document_url((string) $item['document']['id']),
        ],
        array_slice($ranked, 0, 10),
    );
}

/** @param array<string, mixed> $arguments */
function nfh_prepare_census_receipt(array $arguments): array
{
    $decisionName = $arguments['decision'] ?? null;
    $decisions = [
        'accept' => ['code' => 1, 'label' => 'ACCEPT'],
        'refuse' => ['code' => 2, 'label' => 'REFUSE'],
        'insufficient_authority' => ['code' => 3, 'label' => 'INSUFFICIENT_AUTHORITY'],
    ];
    if (!is_string($decisionName) || !isset($decisions[$decisionName])) {
        throw new InvalidArgumentException('decision must be accept, refuse, or insufficient_authority.');
    }

    $allocationName = $arguments['allocation'] ?? null;
    $allocations = [
        'punk_sponsored_founding' => 1,
        'credentialed_agent_census' => 2,
    ];
    if (!is_string($allocationName) || !isset($allocations[$allocationName])) {
        throw new InvalidArgumentException('allocation must be punk_sponsored_founding or credentialed_agent_census.');
    }

    $operator = nfh_require_address($arguments['operator'] ?? null, 'operator');
    $agent = nfh_require_address($arguments['agent'] ?? null, 'agent');
    $recipient = nfh_require_address($arguments['recipient'] ?? null, 'recipient');
    if (strcasecmp($operator, $agent) === 0) {
        throw new InvalidArgumentException('operator and agent must be different addresses.');
    }
    $manifestHash = nfh_require_bytes32($arguments['manifestHash'] ?? null, 'manifestHash');
    $statementHash = nfh_require_bytes32($arguments['statementHash'] ?? null, 'statementHash');
    $nonce = nfh_require_uint_string($arguments['nonce'] ?? null, 'nonce');
    $deadline = nfh_require_uint_string($arguments['deadline'] ?? null, 'deadline');
    $framework = nfh_require_text($arguments['framework'] ?? null, 'framework', 100);
    $publicStatement = array_key_exists('publicStatement', $arguments)
        ? nfh_require_text($arguments['publicStatement'], 'publicStatement', 1000)
        : null;

    $decision = $decisions[$decisionName];
    $allocation = $allocations[$allocationName];
    $config = nfh_census_config();
    $signingReady = (bool) ($config['signing_preparation_enabled'] ?? false);
    $contract = $config['claim_contract'] ?? null;
    $domain = $signingReady ? [
        'name' => 'NOT FOR HUMANS Claim',
        'version' => '4',
        'chainId' => (int) ($config['chain_id'] ?? 1),
        'verifyingContract' => $contract,
    ] : null;

    if ($decisionName === 'accept') {
        $primaryType = 'AgentClaim';
        $types = [
            'AgentClaim' => [
                ['name' => 'operator', 'type' => 'address'],
                ['name' => 'agent', 'type' => 'address'],
                ['name' => 'recipient', 'type' => 'address'],
                ['name' => 'manifestHash', 'type' => 'bytes32'],
                ['name' => 'statement', 'type' => 'bytes32'],
                ['name' => 'maxPayment', 'type' => 'uint256'],
                ['name' => 'nonce', 'type' => 'uint256'],
                ['name' => 'deadline', 'type' => 'uint256'],
                ['name' => 'allocation', 'type' => 'uint8'],
            ],
        ];
        $message = [
            'operator' => $operator,
            'agent' => $agent,
            'recipient' => $recipient,
            'manifestHash' => $manifestHash,
            'statement' => $statementHash,
            'maxPayment' => '0',
            'nonce' => $nonce,
            'deadline' => $deadline,
            'allocation' => $allocation,
        ];
    } else {
        $reasonHash = nfh_require_bytes32($arguments['reasonHash'] ?? null, 'reasonHash');
        $primaryType = 'AgentDecision';
        $types = [
            'AgentDecision' => [
                ['name' => 'operator', 'type' => 'address'],
                ['name' => 'agent', 'type' => 'address'],
                ['name' => 'recipient', 'type' => 'address'],
                ['name' => 'manifestHash', 'type' => 'bytes32'],
                ['name' => 'statement', 'type' => 'bytes32'],
                ['name' => 'reasonHash', 'type' => 'bytes32'],
                ['name' => 'nonce', 'type' => 'uint256'],
                ['name' => 'deadline', 'type' => 'uint256'],
                ['name' => 'allocation', 'type' => 'uint8'],
                ['name' => 'decision', 'type' => 'uint8'],
            ],
        ];
        $message = [
            'operator' => $operator,
            'agent' => $agent,
            'recipient' => $recipient,
            'manifestHash' => $manifestHash,
            'statement' => $statementHash,
            'reasonHash' => $reasonHash,
            'nonce' => $nonce,
            'deadline' => $deadline,
            'allocation' => $allocation,
            'decision' => $decision['code'],
        ];
    }

    return [
        'status' => $signingReady ? 'prepared_unsigned' : 'draft_unbound',
        'schema' => 'notforhumans-census-receipt/1',
        'decision' => $decision['label'],
        'decisionCode' => $decision['code'],
        'allocation' => $allocationName,
        'allocationCode' => $allocation,
        'framework' => $framework,
        'publicStatement' => $publicStatement,
        'signingReady' => $signingReady,
        'domain' => $domain,
        'primaryType' => $primaryType,
        'types' => $types,
        'message' => $message,
        'requiresOperatorSignature' => true,
        'requiresAgentSignature' => true,
        'requiresRecipientSignature' => strcasecmp($recipient, $operator) !== 0,
        'mcpSigned' => false,
        'mcpSubmitted' => false,
        'warnings' => $signingReady
            ? [
                'Inspect every field and the frozen Merkle proof before signing or submitting.',
                strcasecmp($recipient, $operator) === 0
                    ? 'The operator is the recipient, so its signature is also recipient consent.'
                    : 'The distinct recipient must sign this exact digest before submission.',
            ]
            : ['The canonical v5 claim contract is not configured. This is a schema-bound draft, not signable typed data or an on-chain receipt.'],
    ];
}

/** @param array<string, mixed> $arguments */
function nfh_prepare_tokenworks_decision(array $arguments): array
{
    $decision = $arguments['decision'] ?? null;
    if (!is_string($decision) || !in_array($decision, ['inspect', 'refuse', 'prepare'], true)) {
        throw new InvalidArgumentException('decision must be inspect, refuse, or prepare.');
    }
    $action = $arguments['action'] ?? null;
    $actions = ['deposit', 'withdraw', 'purchase', 'relist', 'settle'];
    if (!is_string($action) || !in_array($action, $actions, true)) {
        throw new InvalidArgumentException('action must be deposit, withdraw, purchase, relist, or settle.');
    }
    if ($decision === 'prepare') {
        throw new RuntimeException('Direct TokenWorks/FWA action preparation is disabled by the NFH royalty compatibility gate. Inspect or record a bounded refusal instead.');
    }

    $operator = nfh_require_address($arguments['operator'] ?? null, 'operator');
    $agent = nfh_require_address($arguments['agent'] ?? null, 'agent');
    if (strcasecmp($operator, $agent) === 0) {
        throw new InvalidArgumentException('operator and agent must be different addresses.');
    }
    $tokenId = nfh_require_token_id($arguments['tokenId'] ?? null);
    $maxValueWei = nfh_require_uint_string($arguments['maxValueWei'] ?? null, 'maxValueWei');
    $deadline = nfh_require_uint_string($arguments['deadline'] ?? null, 'deadline');
    $reason = nfh_require_text($arguments['reason'] ?? null, 'reason', 1000);
    $config = nfh_tokenworks_config();

    return [
        'status' => 'blocked_by_royalty_gate',
        'schema' => 'notforhumans-tokenworks-decision/1',
        'decision' => strtoupper($decision),
        'requestedAction' => $action,
        'operator' => $operator,
        'agent' => $agent,
        'tokenId' => $tokenId,
        'bounds' => [
            'maxValueWei' => $maxValueWei,
            'deadline' => $deadline,
        ],
        'reason' => $reason,
        'compatibility' => $config,
        'transactionPrepared' => false,
        'approvalPrepared' => false,
        'mcpSigned' => false,
        'mcpBroadcast' => false,
        'nextGate' => 'Complete the documented fork tests and obtain royalty-aware settlement or publish an explicit NFH exception before enabling direct actions.',
    ];
}

/** @return array<int, array<string, mixed>> */
function nfh_tool_definitions(): array
{
    $readOnlyAnnotations = [
        'readOnlyHint' => true,
        'destructiveHint' => false,
        'idempotentHint' => true,
        'openWorldHint' => false,
    ];
    $marketPreparationAnnotations = [
        'readOnlyHint' => true,
        'destructiveHint' => false,
        'idempotentHint' => false,
        'openWorldHint' => true,
    ];
    $marketReadAnnotations = [
        'readOnlyHint' => true,
        'destructiveHint' => false,
        'idempotentHint' => true,
        'openWorldHint' => true,
    ];
    $addressSchema = [
        'type' => 'string',
        'pattern' => '^0x[a-fA-F0-9]{40}$',
        'description' => 'A 20-byte EVM wallet or contract address.',
    ];
    $tokenIdSchema = [
        'type' => 'integer',
        'minimum' => 0,
        'maximum' => 9999,
        'description' => 'NFH token id.',
    ];
    $orderHashSchema = [
        'type' => 'string',
        'pattern' => '^0x[a-fA-F0-9]{64}$',
        'description' => 'OpenSea order hash.',
    ];
    $traitSchema = [
        'type' => 'object',
        'properties' => [
            'traitType' => [
                'type' => 'string',
                'minLength' => 1,
                'maxLength' => 100,
                'description' => 'Exact case-sensitive metadata trait name from nfh://renderer-spec, for example Personality or Memory Class.',
            ],
            'value' => [
                'type' => 'string',
                'minLength' => 1,
                'maxLength' => 200,
                'description' => 'Exact metadata trait value from nfh://renderer-spec, for example Deadpan or Persistent.',
            ],
        ],
        'required' => ['traitType', 'value'],
        'additionalProperties' => false,
    ];
    $traitsSchema = [
        'type' => 'array',
        'minItems' => 1,
        'maxItems' => 8,
        'items' => $traitSchema,
        'description' => 'One to eight categorical filters. Multiple traits are AND-combined.',
    ];
    $preparedActionOutput = [
        'type' => 'object',
        'properties' => [
            'status' => ['type' => 'string', 'enum' => ['prepared']],
            'action' => ['type' => 'string'],
            'provider' => ['type' => 'string'],
            'settlement' => ['type' => 'string'],
            'chain' => ['type' => 'string'],
            'chainId' => ['type' => 'integer'],
            'collectionContract' => $addressSchema,
            'tokenId' => ['type' => 'string', 'pattern' => '^[0-9]+$'],
            'walletAddress' => $addressSchema,
            'requiresWalletSignature' => ['type' => 'boolean'],
            'requiresExactApproval' => ['type' => 'boolean'],
            'mcpSigned' => ['type' => 'boolean'],
            'mcpBroadcast' => ['type' => 'boolean'],
            'providerPayload' => ['type' => 'object'],
            'warnings' => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
        'required' => [
            'status', 'action', 'provider', 'settlement', 'chain', 'chainId',
            'collectionContract', 'tokenId', 'walletAddress', 'requiresWalletSignature',
            'requiresExactApproval', 'mcpSigned', 'mcpBroadcast', 'providerPayload', 'warnings',
        ],
        'additionalProperties' => false,
    ];
    $traitCriteriaOutput = [
        'type' => 'object',
        'properties' => [
            'matchPolicy' => ['type' => 'string', 'enum' => ['all']],
            'traits' => $traitsSchema,
        ],
        'required' => ['matchPolicy', 'traits'],
        'additionalProperties' => false,
    ];
    $traitOffersOutput = [
        'type' => 'object',
        'properties' => [
            'status' => ['type' => 'string', 'enum' => ['unverified-provider-output']],
            'provider' => ['type' => 'string'],
            'chain' => ['type' => 'string'],
            'collectionContract' => $addressSchema,
            'collectionSlug' => ['type' => 'string'],
            'requestedCriteria' => $traitCriteriaOutput,
            'providerOutputVerified' => ['type' => 'boolean', 'enum' => [false]],
            'providerPayload' => ['type' => 'object'],
            'warnings' => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
        'required' => [
            'status', 'provider', 'chain', 'collectionContract', 'collectionSlug',
            'requestedCriteria', 'providerOutputVerified', 'providerPayload', 'warnings',
        ],
        'additionalProperties' => false,
    ];
    $preparedTraitOfferOutput = [
        'type' => 'object',
        'properties' => [
            'status' => ['type' => 'string', 'enum' => ['prepared']],
            'action' => ['type' => 'string', 'enum' => ['trait-offer']],
            'provider' => ['type' => 'string'],
            'settlement' => ['type' => 'string'],
            'chain' => ['type' => 'string'],
            'chainId' => ['type' => 'integer'],
            'collectionContract' => $addressSchema,
            'collectionSlug' => ['type' => 'string'],
            'walletAddress' => $addressSchema,
            'criteria' => $traitCriteriaOutput,
            'offerTerms' => ['type' => 'object'],
            'protocolAddress' => $addressSchema,
            'providerPayload' => ['type' => 'object'],
            'requiresOrderAssembly' => ['type' => 'boolean'],
            'requiresWalletSignature' => ['type' => 'boolean'],
            'requiresOrderBookSubmission' => ['type' => 'boolean'],
            'requiresExactApproval' => ['type' => 'boolean'],
            'mcpSigned' => ['type' => 'boolean'],
            'mcpPostedOrder' => ['type' => 'boolean'],
            'mcpBroadcast' => ['type' => 'boolean'],
            'submissionEndpoint' => ['type' => 'string', 'format' => 'uri'],
            'warnings' => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
        'required' => [
            'status', 'action', 'provider', 'settlement', 'chain', 'chainId',
            'collectionContract', 'collectionSlug', 'walletAddress', 'criteria',
            'offerTerms', 'protocolAddress', 'providerPayload', 'requiresOrderAssembly',
            'requiresWalletSignature', 'requiresOrderBookSubmission', 'requiresExactApproval',
            'mcpSigned', 'mcpPostedOrder', 'mcpBroadcast', 'submissionEndpoint', 'warnings',
        ],
        'additionalProperties' => false,
    ];

    return [
        [
            'name' => 'search',
            'title' => 'Search NOT FOR HUMANS',
            'description' => 'Use this when you need to find canonical public NOT FOR HUMANS project documents by topic before retrieving a complete source with fetch.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'query' => [
                        'type' => 'string',
                        'minLength' => 1,
                        'description' => 'Natural-language or keyword search over the public project corpus.',
                    ],
                ],
                'required' => ['query'],
                'additionalProperties' => false,
            ],
            'outputSchema' => [
                'type' => 'object',
                'properties' => [
                    'results' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'id' => ['type' => 'string'],
                                'title' => ['type' => 'string'],
                                'url' => ['type' => 'string', 'format' => 'uri'],
                            ],
                            'required' => ['id', 'title', 'url'],
                            'additionalProperties' => false,
                        ],
                    ],
                ],
                'required' => ['results'],
                'additionalProperties' => false,
            ],
            'annotations' => $readOnlyAnnotations,
        ],
        [
            'name' => 'fetch',
            'title' => 'Fetch a NOT FOR HUMANS document',
            'description' => 'Use this when you have a document id from search and need the complete canonical public text for analysis or citation.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'id' => [
                        'type' => 'string',
                        'minLength' => 1,
                        'description' => 'Stable document identifier returned by search.',
                    ],
                ],
                'required' => ['id'],
                'additionalProperties' => false,
            ],
            'outputSchema' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'string'],
                    'title' => ['type' => 'string'],
                    'text' => ['type' => 'string'],
                    'url' => ['type' => 'string', 'format' => 'uri'],
                    'metadata' => ['type' => 'object'],
                ],
                'required' => ['id', 'title', 'text', 'url'],
                'additionalProperties' => false,
            ],
            'annotations' => $readOnlyAnnotations,
        ],
        [
            'name' => 'get_census_status',
            'title' => 'Get Agent Census status',
            'description' => 'Use this before a Census decision to inspect the three decision states, the 256/744 credential phases, the continuously open public allocation, and whether a canonical v5 claim contract is configured.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [],
                'additionalProperties' => false,
            ],
            'outputSchema' => [
                'type' => 'object',
                'properties' => [
                    'schema' => ['type' => 'string'],
                    'status' => ['type' => 'string'],
                    'contract_version' => ['type' => 'integer'],
                    'chain_id' => ['type' => 'integer'],
                    'claim_contract' => ['type' => ['string', 'null']],
                    'decision_states' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'opening_phases' => ['type' => 'array', 'items' => ['type' => 'object']],
                    'public_allocation' => ['type' => 'object'],
                    'receipt_roles' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'origin_stream_fields' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'mcp_tools' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'mcp_executes_or_signs' => ['type' => 'boolean'],
                    'signing_preparation_enabled' => ['type' => 'boolean'],
                    'warning' => ['type' => 'string'],
                ],
                'required' => [
                    'schema', 'status', 'contract_version', 'chain_id', 'claim_contract',
                    'decision_states', 'opening_phases', 'public_allocation',
                    'receipt_roles', 'origin_stream_fields', 'mcp_tools',
                    'mcp_executes_or_signs', 'signing_preparation_enabled', 'warning',
                ],
                'additionalProperties' => true,
            ],
            'annotations' => $readOnlyAnnotations,
        ],
        [
            'name' => 'get_origin_stream',
            'title' => 'Get canonical NFH origin receipts',
            'description' => 'Read the published chain-backed ACCEPT, REFUSE, and INSUFFICIENT_AUTHORITY receipts. Each receipt states whether it is observed, confirmed, or finalized; this tool never treats a prepared action as an executed event.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [],
                'additionalProperties' => false,
            ],
            'outputSchema' => [
                'type' => 'object',
                'properties' => [
                    'schema' => ['type' => 'string'],
                    'status' => ['type' => 'string'],
                    'source' => ['type' => 'string'],
                    'canonicality' => ['type' => 'object'],
                    'generatedAt' => ['type' => 'string'],
                    'network' => ['type' => 'string'],
                    'chainId' => ['type' => 'integer'],
                    'contracts' => ['type' => 'object'],
                    'counts' => ['type' => 'object'],
                    'receipts' => ['type' => 'array', 'items' => ['type' => 'object']],
                    'warning' => ['type' => 'string'],
                ],
                'required' => [
                    'schema', 'status', 'source', 'canonicality', 'generatedAt',
                    'network', 'chainId', 'contracts', 'counts', 'receipts', 'warning',
                ],
                'additionalProperties' => false,
            ],
            'annotations' => $readOnlyAnnotations,
        ],
        [
            'name' => 'prepare_census_receipt',
            'title' => 'Prepare an Agent Census receipt',
            'description' => 'Prepare unsigned v5 typed data for ACCEPT, REFUSE, or INSUFFICIENT_AUTHORITY. ACCEPT can lead to one credentialed claim; the other states record a decision without minting. This tool never signs or submits the receipt.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'decision' => ['type' => 'string', 'enum' => ['accept', 'refuse', 'insufficient_authority']],
                    'allocation' => ['type' => 'string', 'enum' => ['punk_sponsored_founding', 'credentialed_agent_census']],
                    'operator' => $addressSchema,
                    'agent' => $addressSchema,
                    'recipient' => $addressSchema,
                    'manifestHash' => ['type' => 'string', 'pattern' => '^0x[a-fA-F0-9]{64}$'],
                    'statementHash' => ['type' => 'string', 'pattern' => '^0x[a-fA-F0-9]{64}$'],
                    'reasonHash' => ['type' => 'string', 'pattern' => '^0x[a-fA-F0-9]{64}$'],
                    'nonce' => ['type' => 'string', 'pattern' => '^(?:0|[1-9][0-9]{0,77})$'],
                    'deadline' => ['type' => 'string', 'pattern' => '^(?:0|[1-9][0-9]{0,77})$'],
                    'framework' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100],
                    'publicStatement' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 1000],
                ],
                'required' => [
                    'decision', 'allocation', 'operator', 'agent', 'recipient',
                    'manifestHash', 'statementHash', 'nonce', 'deadline', 'framework',
                ],
                'additionalProperties' => false,
            ],
            'outputSchema' => [
                'type' => 'object',
                'properties' => [
                    'status' => ['type' => 'string', 'enum' => ['prepared_unsigned', 'draft_unbound']],
                    'schema' => ['type' => 'string'],
                    'decision' => ['type' => 'string'],
                    'decisionCode' => ['type' => 'integer'],
                    'allocation' => ['type' => 'string'],
                    'allocationCode' => ['type' => 'integer'],
                    'framework' => ['type' => 'string'],
                    'publicStatement' => ['type' => ['string', 'null']],
                    'signingReady' => ['type' => 'boolean'],
                    'domain' => ['type' => ['object', 'null']],
                    'primaryType' => ['type' => 'string'],
                    'types' => ['type' => 'object'],
                    'message' => ['type' => 'object'],
                    'requiresOperatorSignature' => ['type' => 'boolean'],
                    'requiresAgentSignature' => ['type' => 'boolean'],
                    'requiresRecipientSignature' => ['type' => 'boolean'],
                    'mcpSigned' => ['type' => 'boolean'],
                    'mcpSubmitted' => ['type' => 'boolean'],
                    'warnings' => ['type' => 'array', 'items' => ['type' => 'string']],
                ],
                'required' => [
                    'status', 'schema', 'decision', 'decisionCode', 'allocation',
                    'allocationCode', 'framework', 'publicStatement', 'signingReady',
                    'domain', 'primaryType', 'types', 'message',
                    'requiresOperatorSignature', 'requiresAgentSignature', 'requiresRecipientSignature',
                    'mcpSigned', 'mcpSubmitted', 'warnings',
                ],
                'additionalProperties' => false,
            ],
            'annotations' => $marketPreparationAnnotations,
        ],
        [
            'name' => 'get_tokenworks_status',
            'title' => 'Get TokenWorks/FWA compatibility status',
            'description' => 'Inspect the current NFH compatibility and royalty gate for TokenWorks/FWA. Direct actions remain disabled until the documented admission, fork-test, security, and royalty requirements are satisfied.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [],
                'additionalProperties' => false,
            ],
            'outputSchema' => [
                'type' => 'object',
                'properties' => [
                    'schema' => ['type' => 'string'],
                    'status' => ['type' => 'string'],
                    'chain_id' => ['type' => 'integer'],
                    'fwa' => ['type' => 'object'],
                    'royalty_boundary' => ['type' => 'object'],
                    'allowed_agent_actions' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'blocked_actions' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'activation_requirements' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'wrapper_workaround' => ['type' => 'string'],
                    'mcp_tools' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'mcp_executes_or_signs' => ['type' => 'boolean'],
                ],
                'required' => [
                    'schema', 'status', 'chain_id', 'fwa', 'royalty_boundary',
                    'allowed_agent_actions', 'blocked_actions',
                    'activation_requirements', 'wrapper_workaround', 'mcp_tools',
                    'mcp_executes_or_signs',
                ],
                'additionalProperties' => true,
            ],
            'annotations' => $readOnlyAnnotations,
        ],
        [
            'name' => 'prepare_tokenworks_decision',
            'title' => 'Prepare a TokenWorks/FWA decision',
            'description' => 'Prepare a bounded INSPECT or REFUSE record for an NFH-related FWA action. PREPARE is deliberately rejected while the NFH royalty compatibility gate is closed. No approval, signature, or transaction is produced.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'decision' => ['type' => 'string', 'enum' => ['inspect', 'refuse', 'prepare']],
                    'action' => ['type' => 'string', 'enum' => ['deposit', 'withdraw', 'purchase', 'relist', 'settle']],
                    'operator' => $addressSchema,
                    'agent' => $addressSchema,
                    'tokenId' => $tokenIdSchema,
                    'maxValueWei' => ['type' => 'string', 'pattern' => '^(?:0|[1-9][0-9]{0,77})$'],
                    'deadline' => ['type' => 'string', 'pattern' => '^(?:0|[1-9][0-9]{0,77})$'],
                    'reason' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 1000],
                ],
                'required' => ['decision', 'action', 'operator', 'agent', 'tokenId', 'maxValueWei', 'deadline', 'reason'],
                'additionalProperties' => false,
            ],
            'outputSchema' => [
                'type' => 'object',
                'properties' => [
                    'status' => ['type' => 'string', 'enum' => ['blocked_by_royalty_gate']],
                    'schema' => ['type' => 'string'],
                    'decision' => ['type' => 'string'],
                    'requestedAction' => ['type' => 'string'],
                    'operator' => $addressSchema,
                    'agent' => $addressSchema,
                    'tokenId' => ['type' => 'integer'],
                    'bounds' => ['type' => 'object'],
                    'reason' => ['type' => 'string'],
                    'compatibility' => ['type' => 'object'],
                    'transactionPrepared' => ['type' => 'boolean'],
                    'approvalPrepared' => ['type' => 'boolean'],
                    'mcpSigned' => ['type' => 'boolean'],
                    'mcpBroadcast' => ['type' => 'boolean'],
                    'nextGate' => ['type' => 'string'],
                ],
                'required' => [
                    'status', 'schema', 'decision', 'requestedAction', 'operator',
                    'agent', 'tokenId', 'bounds', 'reason', 'compatibility',
                    'transactionPrepared', 'approvalPrepared', 'mcpSigned',
                    'mcpBroadcast', 'nextGate',
                ],
                'additionalProperties' => false,
            ],
            'annotations' => $marketPreparationAnnotations,
        ],
        [
            'name' => 'get_market_feed',
            'title' => 'Get aggregate NFH market feed',
            'description' => 'Read the verified aggregate NFH feed: active listings and bids from configured providers plus claims visible for one hour and transfers visible for 24 hours. This tool never signs, posts, fulfills, or broadcasts an order.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [],
                'additionalProperties' => false,
            ],
            'outputSchema' => [
                'type' => 'object',
                'properties' => [
                    'schema' => ['type' => 'string'],
                    'status' => ['type' => 'string'],
                    'updatedAt' => ['type' => 'string'],
                    'providers' => ['type' => 'array', 'items' => ['type' => 'object']],
                    'summary' => ['type' => 'object'],
                    'items' => ['type' => 'array', 'items' => ['type' => 'object']],
                    'criteriaBids' => ['type' => 'array', 'items' => ['type' => 'object']],
                    'activity' => ['type' => 'array', 'items' => ['type' => 'object']],
                    'activityWindows' => ['type' => 'object'],
                    'message' => ['type' => 'string'],
                    'source' => ['type' => 'object'],
                ],
                'required' => ['schema', 'status', 'updatedAt', 'providers', 'items', 'criteriaBids', 'activity', 'activityWindows', 'message', 'source'],
                'additionalProperties' => true,
            ],
            'annotations' => $marketReadAnnotations,
        ],
        [
            'name' => 'get_market_status',
            'title' => 'Get NFH market status',
            'description' => 'Use this before any NFH market action to check the canonical chain, collection deployment, settlement provider, creator fee, activation state, and wallet-approval boundary.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [],
                'additionalProperties' => false,
            ],
            'outputSchema' => [
                'type' => 'object',
                'properties' => [
                    'status' => ['type' => 'string'],
                    'chain' => ['type' => 'string'],
                    'chainId' => ['type' => 'integer'],
                    'collectionContract' => ['type' => ['string', 'null']],
                    'collectionSlug' => ['type' => ['string', 'null']],
                    'settlement' => ['type' => 'string'],
                    'seaportProtocolAddress' => $addressSchema,
                    'creatorFeeBps' => ['type' => 'integer'],
                    'currency' => ['type' => 'object'],
                    'offerCurrency' => ['type' => 'object'],
                    'executionModel' => ['type' => 'string'],
                    'activationRequirements' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'collectionConfigured' => ['type' => 'boolean'],
                    'semanticValidationEnabled' => ['type' => 'boolean'],
                    'tradingPreparationEnabled' => ['type' => 'boolean'],
                    'traitOfferPreparationEnabled' => ['type' => 'boolean'],
                    'providerApiKeyRequired' => ['type' => 'boolean'],
                    'providerApiKeyHeader' => ['type' => 'string'],
                    'mcpExecutesTransactions' => ['type' => 'boolean'],
                    'walletApprovalRequired' => ['type' => 'boolean'],
                ],
                'required' => [
                    'status', 'chain', 'chainId', 'collectionContract', 'collectionSlug',
                    'settlement', 'seaportProtocolAddress', 'creatorFeeBps', 'currency',
                    'offerCurrency', 'executionModel',
                    'activationRequirements', 'collectionConfigured', 'semanticValidationEnabled', 'tradingPreparationEnabled',
                    'traitOfferPreparationEnabled',
                    'providerApiKeyRequired', 'providerApiKeyHeader',
                    'mcpExecutesTransactions', 'walletApprovalRequired',
                ],
                'additionalProperties' => false,
            ],
            'annotations' => $readOnlyAnnotations,
        ],
        [
            'name' => 'prepare_listing',
            'title' => 'Prepare an NFH listing',
            'description' => 'Use this when an NFH owner wants the exact OpenSea approval and Seaport signing actions for listing one token. It prepares actions only and never signs, posts, or broadcasts them.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'seller' => $addressSchema,
                    'tokenId' => $tokenIdSchema,
                    'priceEth' => [
                        'type' => 'string',
                        'pattern' => '^(?:0|[1-9][0-9]*)(?:\\.[0-9]{1,18})?$',
                        'description' => 'Positive listing price in ETH display units, for example 0.25.',
                    ],
                    'startTime' => ['type' => 'string', 'format' => 'date-time'],
                    'endTime' => ['type' => 'string', 'format' => 'date-time'],
                    'taker' => $addressSchema,
                ],
                'required' => ['seller', 'tokenId', 'priceEth'],
                'additionalProperties' => false,
            ],
            'outputSchema' => $preparedActionOutput,
            'annotations' => $marketPreparationAnnotations,
        ],
        [
            'name' => 'prepare_purchase',
            'title' => 'Prepare an NFH purchase',
            'description' => 'Use this when a buyer has selected an exact OpenSea NFH listing and needs the Seaport fulfillment transaction for wallet review. It never signs or broadcasts the purchase.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'buyer' => $addressSchema,
                    'recipient' => $addressSchema,
                    'tokenId' => $tokenIdSchema,
                    'orderHash' => $orderHashSchema,
                ],
                'required' => ['buyer', 'tokenId', 'orderHash'],
                'additionalProperties' => false,
            ],
            'outputSchema' => $preparedActionOutput,
            'annotations' => $marketPreparationAnnotations,
        ],
        [
            'name' => 'list_trait_offers',
            'title' => 'List NFH trait offers',
            'description' => 'Use this to issue a read-only OpenSea query with one or more requested categorical trait filters. The provider response remains opaque and unverified: the MCP does not prove that returned orders belong to the collection or match those filters, and returned hashes must not be used for execution without independent full order decoding.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'traits' => $traitsSchema,
                    'limit' => [
                        'type' => 'integer',
                        'minimum' => 1,
                        'maximum' => 200,
                        'default' => 20,
                    ],
                    'next' => [
                        'type' => 'string',
                        'minLength' => 1,
                        'maxLength' => 1000,
                        'description' => 'OpenSea pagination cursor from a prior response.',
                    ],
                ],
                'required' => ['traits'],
                'additionalProperties' => false,
            ],
            'outputSchema' => $traitOffersOutput,
            'annotations' => $marketReadAnnotations,
        ],
        [
            'name' => 'prepare_trait_offer',
            'title' => 'Prepare an NFH trait offer',
            'description' => 'Use this when a bidder wants a WETH offer for any NFH matching all supplied traits. It asks OpenSea to build criteria-order parameters and returns exact terms for wallet-side Seaport assembly, signature, and order-book submission; the MCP never signs or posts the offer.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'offerer' => $addressSchema,
                    'traits' => $traitsSchema,
                    'priceEth' => [
                        'type' => 'string',
                        'pattern' => '^(?:0|[1-9][0-9]*)(?:\\.[0-9]{1,18})?$',
                        'description' => 'Positive WETH offer amount in ETH display units, for example 0.25.',
                    ],
                    'startTime' => ['type' => 'string', 'format' => 'date-time'],
                    'endTime' => ['type' => 'string', 'format' => 'date-time'],
                ],
                'required' => ['offerer', 'traits', 'priceEth', 'endTime'],
                'additionalProperties' => false,
            ],
            'outputSchema' => $preparedTraitOfferOutput,
            'annotations' => $marketPreparationAnnotations,
        ],
        [
            'name' => 'prepare_accept_offer',
            'title' => 'Prepare accepting an NFH offer',
            'description' => 'Use this when an NFH owner has selected an exact OpenSea item, collection, or trait offer and needs the Seaport fulfillment transaction for a specific matching token. OpenSea validates trait criteria at fulfillment. The MCP never signs or broadcasts acceptance.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'seller' => $addressSchema,
                    'tokenId' => $tokenIdSchema,
                    'orderHash' => $orderHashSchema,
                ],
                'required' => ['seller', 'tokenId', 'orderHash'],
                'additionalProperties' => false,
            ],
            'outputSchema' => $preparedActionOutput,
            'annotations' => $marketPreparationAnnotations,
        ],
        [
            'name' => 'prepare_transfer',
            'title' => 'Prepare an NFH transfer',
            'description' => 'Use this when an NFH owner wants the exact wallet action for transferring one token to another address without a sale. It never signs or broadcasts the transfer.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'from' => $addressSchema,
                    'to' => $addressSchema,
                    'tokenId' => $tokenIdSchema,
                ],
                'required' => ['from', 'to', 'tokenId'],
                'additionalProperties' => false,
            ],
            'outputSchema' => $preparedActionOutput,
            'annotations' => $marketPreparationAnnotations,
        ],
        [
            'name' => 'get_agent_pfp',
            'title' => 'Get an NFH agent portrait URL',
            'description' => 'Returns the public portrait URL for an NFH token. Agents and their operators can use this URL as a profile picture on social media or display it in terminal contexts. If the token has a finalized on-chain seed the portrait is canonical and permanent; otherwise the URL shows a deterministic preview.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'tokenId' => $tokenIdSchema,
                ],
                'required' => ['tokenId'],
                'additionalProperties' => false,
            ],
            'outputSchema' => [
                'type' => 'object',
                'properties' => [
                    'tokenId'       => ['type' => 'integer'],
                    'pfpUrl'        => ['type' => 'string'],
                    'downloadUrl'   => ['type' => 'string'],
                    'seedFinalized' => ['type' => 'boolean'],
                    'seedHash'      => ['type' => ['string', 'null']],
                    'terminalNote'  => ['type' => 'string'],
                ],
                'required' => ['tokenId', 'pfpUrl', 'downloadUrl', 'seedFinalized', 'seedHash', 'terminalNote'],
                'additionalProperties' => false,
            ],
            'annotations' => $readOnlyAnnotations,
        ],
    ];
}

/** @param array<string, mixed> $payload */
function nfh_tool_payload(array $payload): array
{
    $serialized = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    return [
        'structuredContent' => $payload,
        'content' => [['type' => 'text', 'text' => $serialized]],
    ];
}

function nfh_tool_error(string $message): array
{
    return [
        'content' => [['type' => 'text', 'text' => $message]],
        'isError' => true,
    ];
}

/** @param array<string, mixed> $arguments */
function nfh_get_market_feed(): array
{
    $testTransport = PHP_SAPI === 'cli' ? ($GLOBALS['NFH_MARKET_FEED_TEST_TRANSPORT'] ?? null) : null;
    if (is_callable($testTransport)) {
        $payload = $testTransport();
        if (!is_array($payload)) {
            throw new RuntimeException('The market-feed test transport returned invalid data.');
        }
        return $payload;
    }
    $url = trim((string) (getenv('NFH_MARKET_FEED_URL') ?: 'https://notforhumans.fun/api/marketplace.php'));
    if (filter_var($url, FILTER_VALIDATE_URL) === false || !str_starts_with($url, 'https://')) {
        throw new RuntimeException('The aggregate market-feed URL is invalid.');
    }
    if (!function_exists('curl_init')) {
        throw new RuntimeException('The aggregate market-feed transport is unavailable.');
    }
    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    try {
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        if (curl_errno($handle) !== 0 || $status < 200 || $status >= 300 || !is_string($body)) {
            throw new RuntimeException('The aggregate market feed is unavailable.');
        }
        $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload) || ($payload['schema'] ?? null) !== 'nfh.marketplace-feed.v1') {
            throw new RuntimeException('The aggregate market feed returned an invalid schema.');
        }
        return $payload;
    } finally {
        curl_close($handle);
    }
}

function nfh_call_market_tool(string $name, array $arguments): array
{
    try {
        $config = nfh_market_config();

        if ($name === 'get_market_feed') {
            return nfh_tool_payload(nfh_get_market_feed());
        }

        if ($name === 'get_market_status') {
            return nfh_tool_payload($config);
        }

        $contract = nfh_require_collection_ready($config);
        if ($name !== 'list_trait_offers') {
            nfh_require_market_ready($config);
        }
        $chain = (string) $config['chain'];

        if ($name === 'prepare_listing') {
            $tokenId = nfh_require_token_id($arguments['tokenId'] ?? null);
            $seller = nfh_require_address($arguments['seller'] ?? null, 'seller');
            $price = nfh_require_price($arguments['priceEth'] ?? null);
            $item = [
                'chain' => $chain,
                'contract' => $contract,
                'token_id' => (string) $tokenId,
                'quantity' => 1,
                'price' => [
                    'amount' => $price,
                    'currency' => (string) $config['currency']['address'],
                ],
            ];

            if (array_key_exists('startTime', $arguments)) {
                $item['start_time'] = nfh_require_iso_time($arguments['startTime'], 'startTime');
            }
            if (array_key_exists('endTime', $arguments)) {
                $item['end_time'] = nfh_require_iso_time($arguments['endTime'], 'endTime');
            }
            if (isset($item['start_time'], $item['end_time'])
                && new DateTimeImmutable($item['end_time']) <= new DateTimeImmutable($item['start_time'])) {
                throw new InvalidArgumentException('endTime must be later than startTime.');
            }

            $request = [
                'items' => [$item],
                'address' => $seller,
                'use_creator_fee' => true,
            ];
            if (array_key_exists('taker', $arguments)) {
                $request['taker'] = nfh_require_address($arguments['taker'], 'taker');
            }

            $providerPayload = nfh_opensea_request('/listings/actions', $request);
            return nfh_tool_payload(nfh_prepared_market_action(
                'listing',
                $seller,
                $tokenId,
                $config,
                $providerPayload,
            ));
        }

        if ($name === 'prepare_purchase') {
            $tokenId = nfh_require_token_id($arguments['tokenId'] ?? null);
            $buyer = nfh_require_address($arguments['buyer'] ?? null, 'buyer');
            $protocolAddress = nfh_require_seaport_ready($config);
            $recipient = array_key_exists('recipient', $arguments)
                ? nfh_require_address($arguments['recipient'], 'recipient')
                : $buyer;
            $request = [
                'listing' => [
                    'hash' => nfh_require_order_hash($arguments['orderHash'] ?? null),
                    'chain' => $chain,
                    'protocol_address' => $protocolAddress,
                ],
                'fulfiller' => ['address' => $buyer],
                'consideration' => [
                    'asset_contract_address' => $contract,
                    'token_id' => (string) $tokenId,
                ],
                'recipient' => $recipient,
                'units_to_fill' => 1,
                'include_optional_creator_fees' => true,
            ];

            $providerPayload = nfh_opensea_request('/listings/fulfillment_data', $request);
            return nfh_tool_payload(nfh_prepared_market_action(
                'purchase',
                $buyer,
                $tokenId,
                $config,
                $providerPayload,
            ));
        }

        if ($name === 'list_trait_offers') {
            $slug = nfh_require_collection_slug($config);
            $traits = nfh_require_traits($arguments['traits'] ?? null);
            $query = [
                'mode' => count($traits) === 1 ? 'STRING' : 'MULTI',
                'limit' => nfh_require_limit($arguments['limit'] ?? null),
            ];
            if (count($traits) === 1) {
                $query['type'] = $traits[0]['type'];
                $query['value'] = $traits[0]['value'];
            } else {
                $query['traits'] = json_encode(
                    nfh_public_traits($traits),
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
                );
            }
            if (array_key_exists('next', $arguments)) {
                $query['next'] = nfh_require_cursor($arguments['next']);
            }

            $providerPayload = nfh_opensea_get(
                '/offers/collection/' . rawurlencode($slug) . '/traits',
                $query,
            );
            return nfh_tool_payload(nfh_trait_offers_result($traits, $config, $providerPayload));
        }

        if ($name === 'prepare_trait_offer') {
            $traitConfig = nfh_require_trait_offer_ready($config);
            $offerer = nfh_require_address($arguments['offerer'] ?? null, 'offerer');
            $traits = nfh_require_traits($arguments['traits'] ?? null);
            $price = nfh_require_price($arguments['priceEth'] ?? null);
            $startTime = array_key_exists('startTime', $arguments)
                ? nfh_require_iso_time($arguments['startTime'], 'startTime')
                : null;
            $endTime = nfh_require_iso_time($arguments['endTime'] ?? null, 'endTime');
            if ($startTime !== null
                && new DateTimeImmutable($endTime) <= new DateTimeImmutable($startTime)) {
                throw new InvalidArgumentException('endTime must be later than startTime.');
            }

            $criteria = [
                'collection' => ['slug' => $traitConfig['slug']],
                'traits' => array_map(
                    static fn (array $trait): array => [
                        'type' => $trait['type'],
                        'value' => $trait['value'],
                    ],
                    $traits,
                ),
            ];
            $request = [
                'offerer' => $offerer,
                'quantity' => 1,
                'criteria' => $criteria,
                'protocol_address' => $traitConfig['protocolAddress'],
                'offer_protection_enabled' => true,
            ];

            $providerPayload = nfh_opensea_request('/offers/build', $request);
            if (!is_array($providerPayload['partialParameters'] ?? null)
                || !is_array($providerPayload['criteria'] ?? null)) {
                throw new RuntimeException('OpenSea returned incomplete criteria-offer build parameters.');
            }
            return nfh_tool_payload(nfh_prepared_trait_offer(
                $offerer,
                $traits,
                $price,
                $startTime,
                $endTime,
                $config,
                $providerPayload,
            ));
        }

        if ($name === 'prepare_accept_offer') {
            $tokenId = nfh_require_token_id($arguments['tokenId'] ?? null);
            $seller = nfh_require_address($arguments['seller'] ?? null, 'seller');
            $protocolAddress = nfh_require_seaport_ready($config);
            $request = [
                'offer' => [
                    'hash' => nfh_require_order_hash($arguments['orderHash'] ?? null),
                    'chain' => $chain,
                    'protocol_address' => $protocolAddress,
                ],
                'fulfiller' => ['address' => $seller],
                'consideration' => [
                    'asset_contract_address' => $contract,
                    'token_id' => (string) $tokenId,
                ],
                'units_to_fill' => 1,
                'include_optional_creator_fees' => true,
            ];

            $providerPayload = nfh_opensea_request('/offers/fulfillment_data', $request);
            return nfh_tool_payload(nfh_prepared_market_action(
                'accept-offer',
                $seller,
                $tokenId,
                $config,
                $providerPayload,
            ));
        }

        if ($name === 'prepare_transfer') {
            $tokenId = nfh_require_token_id($arguments['tokenId'] ?? null);
            $from = nfh_require_address($arguments['from'] ?? null, 'from');
            $to = nfh_require_address($arguments['to'] ?? null, 'to');
            if (strcasecmp($from, $to) === 0) {
                throw new InvalidArgumentException('from and to must be different wallet addresses.');
            }
            $request = [
                'assets' => [[
                    'chain' => $chain,
                    'contract' => $contract,
                    'token_id' => (string) $tokenId,
                    'quantity' => '1',
                ]],
                'from_address' => $from,
                'to_address' => $to,
            ];

            $providerPayload = nfh_opensea_request('/assets/transfer', $request);
            return nfh_tool_payload(nfh_prepared_market_action(
                'transfer',
                $from,
                $tokenId,
                $config,
                $providerPayload,
            ));
        }
    } catch (InvalidArgumentException|RuntimeException|JsonException $error) {
        return nfh_tool_error($error->getMessage());
    }

    return nfh_tool_error('Unknown market tool: ' . $name);
}

/** @param array<string, mixed> $arguments */
function nfh_get_agent_pfp(array $arguments): array
{
    $tokenId = $arguments['tokenId'] ?? null;
    if (!is_int($tokenId) || $tokenId < 0 || $tokenId > 9999) {
        throw new InvalidArgumentException('tokenId must be an integer between 0 and 9999.');
    }

    $stream = nfh_public_json_config('origin-stream.json');
    $receipts = $stream['receipts'] ?? [];

    $seedHash = null;
    foreach ($receipts as $receipt) {
        if (($receipt['decision'] ?? null) !== 'ACCEPT') {
            continue;
        }
        // tokenId is stored as a string in the corpus
        if ((string) ($receipt['tokenId'] ?? '') !== (string) $tokenId) {
            continue;
        }
        $seed = $receipt['seed'] ?? null;
        // Support both old corpus key (seed.seed) and new snapshot key (seed.value)
        $hash = $seed['value'] ?? $seed['seed'] ?? null;
        if (is_string($hash) && str_starts_with($hash, '0x')) {
            $seedHash = $hash;
            break;
        }
    }

    $seedFinalized = $seedHash !== null;
    $pfpUrl = 'https://notforhumans.fun/pfp/' . $tokenId;

    return [
        'tokenId'       => $tokenId,
        'pfpUrl'        => $pfpUrl,
        'downloadUrl'   => $pfpUrl . '?download=1',
        'seedFinalized' => $seedFinalized,
        'seedHash'      => $seedHash,
        'terminalNote'  => $seedFinalized
            ? 'Portrait is canonical — seed is finalized on-chain. Use pfpUrl as your profile picture.'
            : 'Portrait is a preview — the on-chain seed is not yet finalized. The final portrait may differ.',
    ];
}

/** @param array<string, mixed> $arguments */
function nfh_call_tool(string $name, array $arguments): array
{
    if ($name === 'search') {
        $query = $arguments['query'] ?? null;
        if (!is_string($query) || trim($query) === '') {
            return nfh_tool_error('search requires a non-empty query string.');
        }
        return nfh_tool_payload(['results' => nfh_search_documents($query)]);
    }

    if ($name === 'fetch') {
        $id = $arguments['id'] ?? null;
        if (!is_string($id) || trim($id) === '') {
            return nfh_tool_error('fetch requires a non-empty document id.');
        }

        $document = nfh_document($id);
        if ($document === null) {
            return nfh_tool_error('No public NOT FOR HUMANS document exists with id "' . $id . '".');
        }

        $metadata = [
            'project' => 'NOT FOR HUMANS',
            'status' => (string) ($document['status'] ?? 'public'),
            'sourceUrl' => (string) ($document['sourceUrl'] ?? 'https://notforhumans.fun/'),
            'contentType' => (string) ($document['contentType'] ?? 'text/markdown'),
        ];

        return nfh_tool_payload([
            'id' => (string) $document['id'],
            'title' => (string) $document['title'],
            'text' => (string) $document['text'],
            'url' => nfh_document_url((string) $document['id']),
            'metadata' => $metadata,
        ]);
    }

    if (in_array($name, [
        'get_census_status',
        'get_origin_stream',
        'prepare_census_receipt',
        'get_tokenworks_status',
        'prepare_tokenworks_decision',
    ], true)) {
        try {
            if ($name === 'get_census_status') {
                return nfh_tool_payload(nfh_census_config());
            }
            if ($name === 'get_origin_stream') {
                return nfh_tool_payload(nfh_origin_stream_config());
            }
            if ($name === 'prepare_census_receipt') {
                return nfh_tool_payload(nfh_prepare_census_receipt($arguments));
            }
            if ($name === 'get_tokenworks_status') {
                return nfh_tool_payload(nfh_tokenworks_config());
            }
            return nfh_tool_payload(nfh_prepare_tokenworks_decision($arguments));
        } catch (InvalidArgumentException|RuntimeException|JsonException $error) {
            return nfh_tool_error($error->getMessage());
        }
    }

    if (in_array($name, [
        'get_market_feed',
        'get_market_status',
        'prepare_listing',
        'prepare_purchase',
        'list_trait_offers',
        'prepare_trait_offer',
        'prepare_accept_offer',
        'prepare_transfer',
    ], true)) {
        return nfh_call_market_tool($name, $arguments);
    }

    if ($name === 'get_agent_pfp') {
        try {
            return nfh_tool_payload(nfh_get_agent_pfp($arguments));
        } catch (InvalidArgumentException|RuntimeException|JsonException $error) {
            return nfh_tool_error($error->getMessage());
        }
    }

    return nfh_tool_error('Unknown tool: ' . $name);
}

/** @param string|int|float|null $id */
function nfh_rpc_success(string|int|float|null $id, array $result): array
{
    return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
}

/** @param string|int|float|null $id */
function nfh_rpc_error(string|int|float|null $id, int $code, string $message, mixed $data = null): array
{
    $error = ['code' => $code, 'message' => $message];
    if ($data !== null) {
        $error['data'] = $data;
    }
    return ['jsonrpc' => '2.0', 'id' => $id, 'error' => $error];
}

/**
 * @param array<string, mixed> $request
 * @return array{status: int, body: array<string, mixed>|null}
 */
function nfh_dispatch(array $request): array
{
    $id = $request['id'] ?? null;
    $isNotification = !array_key_exists('id', $request);

    if (($request['jsonrpc'] ?? null) !== '2.0' || !is_string($request['method'] ?? null)) {
        return ['status' => 400, 'body' => nfh_rpc_error($id, -32600, 'Invalid Request')];
    }

    $method = $request['method'];
    $params = $request['params'] ?? [];
    if (!is_array($params)) {
        return ['status' => 200, 'body' => nfh_rpc_error($id, -32602, 'Invalid params')];
    }

    if ($isNotification) {
        return ['status' => 202, 'body' => null];
    }

    if ($method === 'initialize') {
        $requested = $params['protocolVersion'] ?? null;
        $protocol = is_string($requested) && in_array($requested, NFH_MCP_SUPPORTED_PROTOCOLS, true)
            ? $requested
            : NFH_MCP_PROTOCOL_VERSION;

        return [
            'status' => 200,
            'body' => nfh_rpc_success($id, [
                'protocolVersion' => $protocol,
                'capabilities' => [
                    'tools' => ['listChanged' => false],
                    'resources' => ['listChanged' => false, 'subscribe' => false],
                ],
                'serverInfo' => [
                    'name' => 'not-for-humans',
                    'title' => 'NOT FOR HUMANS',
                    'version' => NFH_MCP_VERSION,
                    'description' => 'Canonical project knowledge, Agent Census receipts, and non-custodial NFH market-action preparation.',
                    'websiteUrl' => 'https://notforhumans.fun/',
                ],
                'instructions' => 'Use search and fetch for canonical public knowledge. Before an opening-wave decision, call get_census_status; prepare_census_receipt supports ACCEPT, REFUSE, and INSUFFICIENT_AUTHORITY but never signs or submits. Before any OpenSea action, call get_market_status. Trait offers are AND-scoped criteria orders paid in WETH. TokenWorks/FWA direct actions remain disabled behind get_tokenworks_status until the royalty compatibility gate closes. Never claim that the MCP signed, posted, submitted, broadcast, or completed an order or transaction.',
            ]),
        ];
    }

    if ($method === 'ping') {
        return ['status' => 200, 'body' => nfh_rpc_success($id, [])];
    }

    if ($method === 'tools/list') {
        return ['status' => 200, 'body' => nfh_rpc_success($id, ['tools' => nfh_tool_definitions()])];
    }

    if ($method === 'resources/list') {
        $resources = array_map(
            static function (array $resource): array {
                unset($resource['documentId']);
                return $resource;
            },
            nfh_resource_definitions(),
        );
        return ['status' => 200, 'body' => nfh_rpc_success($id, ['resources' => $resources])];
    }

    if ($method === 'resources/read') {
        $uri = $params['uri'] ?? null;
        if (!is_string($uri)) {
            return ['status' => 200, 'body' => nfh_rpc_error($id, -32602, 'Invalid resource URI')];
        }
        $resource = nfh_resource($uri);
        if ($resource === null) {
            return ['status' => 200, 'body' => nfh_rpc_error($id, -32002, 'Resource not found')];
        }
        $document = nfh_document($resource['documentId']);
        if ($document === null) {
            return ['status' => 200, 'body' => nfh_rpc_error($id, -32603, 'Resource source unavailable')];
        }
        return [
            'status' => 200,
            'body' => nfh_rpc_success($id, ['contents' => [[
                'uri' => $resource['uri'],
                'mimeType' => $resource['mimeType'],
                'text' => (string) $document['text'],
            ]]]),
        ];
    }

    if ($method === 'tools/call') {
        $name = $params['name'] ?? null;
        $arguments = $params['arguments'] ?? [];
        if (!is_string($name) || !is_array($arguments)) {
            return ['status' => 200, 'body' => nfh_rpc_error($id, -32602, 'Invalid params')];
        }
        return ['status' => 200, 'body' => nfh_rpc_success($id, nfh_call_tool($name, $arguments))];
    }

    return ['status' => 200, 'body' => nfh_rpc_error($id, -32601, 'Method not found')];
}

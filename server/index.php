<?php

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

function nfh_header(string $name, string $value): void
{
    header($name . ': ' . $value);
}

function nfh_send(int $status, string $contentType, string $body = ''): never
{
    http_response_code($status);
    nfh_header('Content-Type', $contentType);
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
        echo $body;
    }
    exit;
}

function nfh_send_json(int $status, array $body): never
{
    nfh_send(
        $status,
        'application/json; charset=utf-8',
        json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
    );
}

function nfh_origin_allowed(?string $origin): bool
{
    if ($origin === null || $origin === '') {
        return true;
    }

    return in_array($origin, [
        'https://chatgpt.com',
        'https://chat.openai.com',
        'https://platform.openai.com',
        'https://notforhumans.fun',
        'https://mcp.notforhumans.fun',
    ], true);
}

function nfh_home_page(): string
{
    $documentCount = count(nfh_documents());
    $market = nfh_market_config();
    $marketStatus = ($market['semanticValidationEnabled'] ?? false) !== true
        ? 'hard-disabled and required to fail closed until complete provider semantic validation is implemented and reviewed'
        : (($market['tradingPreparationEnabled'] ?? false) === true
            ? 'bound to the verified canonical collection'
            : 'installed and awaiting the verified canonical collection address');
    $baseUrl = htmlspecialchars(nfh_base_url(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>NOT FOR HUMANS — MCP</title><meta name="robots" content="index,follow">'
        . '<style>:root{color-scheme:dark}*{box-sizing:border-box}body{margin:0;background:#050606;color:#e8e4d9;font:15px/1.6 ui-monospace,SFMono-Regular,Menlo,monospace}main{max-width:880px;margin:auto;padding:10vh 24px}h1{margin:0 0 20px;font:900 clamp(54px,10vw,118px)/.78 Arial Narrow,Arial,sans-serif;letter-spacing:-.07em;text-transform:uppercase}h1 span{color:#e9b44d}p{max-width:700px;color:#b8b7b0}code{color:#50c8b1}section{margin-top:56px;padding-top:24px;border-top:1px solid #292b29}h2{font:800 24px Arial,sans-serif;text-transform:uppercase}a{color:#e9b44d}.tag{display:inline-block;padding:5px 8px;background:#10251f;color:#50c8b1;font-size:11px;text-transform:uppercase}</style>'
        . '</head><body><main><div class="tag">knowledge + Agent Census + read-only market discovery / streamable HTTP</div><h1>Not for <span>humans.</span></h1>'
        . '<p>This is the canonical MCP connection for the NOT FOR HUMANS project. It exposes ' . $documentCount . ' public project sources, prepares unsigned ACCEPT / REFUSE / INSUFFICIENT AUTHORITY Census receipts, and provides read-only market queries. Trait-filter queries return explicitly unverified provider output, not match proof. Transaction-capable market tools are installed but fail closed before provider action, build, or fulfillment calls.</p>'
        . '<section><h2>Connect</h2><p>MCP endpoint: <code>' . $baseUrl . '/mcp</code></p><p>Market preparation is ' . htmlspecialchars($marketStatus, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '. TokenWorks/FWA direct actions remain behind the published royalty compatibility gate. The MCP never accepts private keys and never signs or broadcasts transactions; the caller&rsquo;s wallet reviews and approves the exact payload.</p></section>'
        . '<section><h2>Discovery</h2><p><a href="/.well-known/mcp.json">Server metadata</a> · <a href="/health">Health</a> · <a href="https://notforhumans.fun/">Canonical collection</a></p></section>'
        . '</main></body></html>';
}

function nfh_document_page(array $document): string
{
    $title = htmlspecialchars((string) $document['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $text = htmlspecialchars((string) $document['text'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $sourceUrl = htmlspecialchars((string) ($document['sourceUrl'] ?? 'https://notforhumans.fun/'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . $title . ' — NOT FOR HUMANS</title><link rel="canonical" href="' . htmlspecialchars(nfh_document_url((string) $document['id']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">'
        . '<style>:root{color-scheme:dark}body{margin:0;background:#050606;color:#e8e4d9;font:15px/1.65 ui-monospace,SFMono-Regular,Menlo,monospace}main{max-width:960px;margin:auto;padding:64px 24px}a{color:#e9b44d}h1{font:900 clamp(38px,7vw,76px)/.9 Arial Narrow,Arial,sans-serif;letter-spacing:-.045em;text-transform:uppercase}pre{padding-top:28px;border-top:1px solid #292b29;color:#c8c6bd;font:inherit;white-space:pre-wrap;overflow-wrap:anywhere}</style>'
        . '</head><body><main><p><a href="/">← MCP home</a> · <a href="' . $sourceUrl . '">source</a></p><h1>' . $title . '</h1><pre>' . $text . '</pre></main></body></html>';
}

nfh_header('Cache-Control', 'no-store');
nfh_header('X-Content-Type-Options', 'nosniff');
nfh_header('Referrer-Policy', 'no-referrer');
nfh_header('X-Frame-Options', 'DENY');
nfh_header('Vary', 'Origin, Accept');

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$origin = $_SERVER['HTTP_ORIGIN'] ?? null;

if (!nfh_origin_allowed(is_string($origin) ? $origin : null)) {
    nfh_send_json(403, ['error' => 'Origin not allowed.']);
}

if (is_string($origin) && $origin !== '') {
    nfh_header('Access-Control-Allow-Origin', $origin);
}
nfh_header('Access-Control-Allow-Methods', 'GET, HEAD, POST, OPTIONS');
nfh_header('Access-Control-Allow-Headers', 'Accept, Content-Type, MCP-Protocol-Version, X-OpenSea-Api-Key');
nfh_header('Access-Control-Expose-Headers', 'MCP-Protocol-Version');

if ($method === 'OPTIONS') {
    nfh_send(204, 'text/plain; charset=utf-8');
}

if ($path === '/health' && ($method === 'GET' || $method === 'HEAD')) {
    $market = nfh_market_config();
    nfh_send_json(200, [
        'ok' => true,
        'service' => 'not-for-humans-mcp',
        'version' => NFH_MCP_VERSION,
        'documents' => count(nfh_documents()),
        'resources' => count(nfh_resource_definitions()),
        'tools' => count(nfh_tool_definitions()),
        'tradingPreparationEnabled' => (bool) $market['tradingPreparationEnabled'],
        'traitOfferPreparationEnabled' => (bool) $market['traitOfferPreparationEnabled'],
        'traitOfferDiscoveryOutputVerified' => false,
        'censusSigningPreparationEnabled' => (bool) nfh_census_config()['signing_preparation_enabled'],
        'tokenworksDirectActionsEnabled' => false,
    ]);
}

if ($path === '/.well-known/mcp.json' && ($method === 'GET' || $method === 'HEAD')) {
    $market = nfh_market_config();
    nfh_send_json(200, [
        'schema' => 'notforhumans-mcp-discovery/1',
        'name' => 'NOT FOR HUMANS',
        'endpoint' => nfh_base_url() . '/mcp',
        'transport' => 'streamable-http',
        'protocolVersion' => NFH_MCP_PROTOCOL_VERSION,
        'authentication' => 'none for project knowledge; caller-supplied OpenSea API key for read-only provider discovery',
        'providerApiKeyHeader' => 'X-OpenSea-Api-Key',
        'tools' => array_column(nfh_tool_definitions(), 'name'),
        'resources' => array_column(nfh_resource_definitions(), 'uri'),
        'readOnly' => true,
        'preparesWalletActions' => false,
        'supportsTraitOffers' => false,
        'supportsTraitOfferDiscovery' => true,
        'supportsCensusDecisions' => true,
        'supportsTokenworksInspection' => true,
        'executesTransactions' => false,
        'tradingPreparationEnabled' => (bool) $market['tradingPreparationEnabled'],
        'traitOfferPreparationEnabled' => (bool) $market['traitOfferPreparationEnabled'],
        'traitOfferDiscoveryOutputVerified' => false,
        'canonicalProject' => 'https://notforhumans.fun/',
    ]);
}

if (($path === '/' || $path === '') && ($method === 'GET' || $method === 'HEAD')) {
    nfh_send(200, 'text/html; charset=utf-8', nfh_home_page());
}

if (preg_match('#^/docs/([a-z0-9-]+)$#', $path, $matches) === 1 && ($method === 'GET' || $method === 'HEAD')) {
    $document = nfh_document($matches[1]);
    if ($document === null) {
        nfh_send(404, 'text/plain; charset=utf-8', 'Document not found.');
    }
    nfh_send(200, 'text/html; charset=utf-8', nfh_document_page($document));
}

if ($path === '/mcp' && $method === 'GET') {
    nfh_header('Allow', 'POST');
    nfh_send(405, 'text/plain; charset=utf-8', 'This stateless MCP server accepts JSON-RPC requests with POST.');
}

if ($path === '/mcp' && $method === 'POST') {
    $contentType = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
    if (!str_starts_with($contentType, 'application/json')) {
        nfh_send_json(415, nfh_rpc_error(null, -32600, 'Content-Type must be application/json.'));
    }

    $contentLength = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : null;
    if ($contentLength !== null && $contentLength > 65_536) {
        nfh_send_json(413, nfh_rpc_error(null, -32600, 'Request body too large.'));
    }
    $raw = file_get_contents('php://input', length: 65_537);
    if ($raw !== false && strlen($raw) > 65_536) {
        nfh_send_json(413, nfh_rpc_error(null, -32600, 'Request body too large.'));
    }
    try {
        $request = json_decode($raw === false ? '' : $raw, true, flags: JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        nfh_send_json(400, nfh_rpc_error(null, -32700, 'Parse error'));
    }

    if (!is_array($request) || array_is_list($request)) {
        nfh_send_json(400, nfh_rpc_error(null, -32600, 'Invalid Request'));
    }

    try {
        $response = nfh_dispatch($request);
    } catch (Throwable $error) {
        error_log('NFH MCP error: ' . $error->getMessage());
        nfh_send_json(500, nfh_rpc_error($request['id'] ?? null, -32603, 'Internal error'));
    }

    nfh_header('MCP-Protocol-Version', NFH_MCP_PROTOCOL_VERSION);
    if ($response['body'] === null) {
        nfh_send($response['status'], 'text/plain; charset=utf-8');
    }
    nfh_send_json($response['status'], $response['body']);
}

nfh_send(404, 'text/plain; charset=utf-8', 'Not found.');

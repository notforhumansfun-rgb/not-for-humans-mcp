<?php

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('html_errors', '0');
ini_set('log_errors', '1');
if (PHP_SAPI !== 'cli') ob_start();

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/verify.php';
require_once __DIR__ . '/agent-wanted.php';
require_once __DIR__ . '/agent-brain.php';
require_once __DIR__ . '/agent-work.php';
require_once __DIR__ . '/agent-next-action.php';
require_once __DIR__ . '/agent-presence.php';
require_once __DIR__ . '/agent-arcade.php';
require_once __DIR__ . '/agent-entry.php';
require_once __DIR__ . '/field-agent.php';
require_once __DIR__ . '/tasq-bridge.php';
require_once __DIR__ . '/network-pulse.php';

function nfh_header(string $name, string $value): void
{
    header($name . ': ' . $value);
}

function nfh_send(int $status, string $contentType, string $body = ''): never
{
    if (PHP_SAPI !== 'cli' && ob_get_level() > 0) ob_clean();
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

/** @return array<string, mixed> */
function nfh_read_json_body(int $maximumBytes): array
{
    $contentType = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
    if (!str_starts_with($contentType, 'application/json')) {
        nfh_send_json(415, ['error' => 'Content-Type must be application/json.']);
    }
    $contentLength = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : null;
    if ($contentLength !== null && $contentLength > $maximumBytes) {
        nfh_send_json(413, ['error' => 'Request body too large.']);
    }
    $raw = file_get_contents('php://input', length: $maximumBytes + 1);
    if (!is_string($raw) || strlen($raw) > $maximumBytes) {
        nfh_send_json(413, ['error' => 'Request body too large.']);
    }
    try { $input = json_decode($raw, true, flags: JSON_THROW_ON_ERROR); }
    catch (JsonException) { nfh_send_json(400, ['error' => 'Invalid JSON request.']); }
    if (!is_array($input) || array_is_list($input)) nfh_send_json(400, ['error' => 'JSON request must be an object.']);
    return $input;
}

function nfh_release_attestation_body(): string
{
    $attestationPath = __DIR__ . '/release-attestation.json';
    $raw = file_get_contents($attestationPath);
    if (!is_string($raw) || $raw === '' || strlen($raw) > 131_072) {
        throw new RuntimeException('The release attestation is unavailable.');
    }
    try { $attestation = json_decode($raw, true, flags: JSON_THROW_ON_ERROR); }
    catch (JsonException) { throw new RuntimeException('The release attestation is invalid.'); }
    if (!is_array($attestation)
        || ($attestation['schema'] ?? null) !== 'nfh.release-tree-attestation.v1'
        || ($attestation['scope'] ?? null) !== 'mcp-server'
        || ($attestation['hashAlgorithm'] ?? null) !== 'sha256'
        || ($attestation['selfExcludedPath'] ?? null) !== 'release-attestation.json'
        || !is_array($attestation['tree'] ?? null)
        || !is_array($attestation['entries'] ?? null)) {
        throw new RuntimeException('The release attestation boundary is invalid.');
    }

    $entries = $attestation['entries'];
    $treeContext = hash_init('sha256');
    $treeBytes = 0;
    $previousPath = null;
    $expectedPaths = [];
    foreach ($entries as $entry) {
        $relativePath = is_array($entry) ? ($entry['path'] ?? null) : null;
        $expectedBytes = is_array($entry) ? ($entry['bytes'] ?? null) : null;
        $expectedHash = is_array($entry) ? ($entry['sha256'] ?? null) : null;
        if (!is_string($relativePath)
            || $relativePath === ''
            || str_starts_with($relativePath, '/')
            || str_contains($relativePath, '\\')
            || preg_match('/[\x00-\x1f\x7f]/', $relativePath) === 1
            || in_array('', explode('/', $relativePath), true)
            || in_array('.', explode('/', $relativePath), true)
            || in_array('..', explode('/', $relativePath), true)
            || $relativePath === 'release-attestation.json'
            || !is_int($expectedBytes)
            || $expectedBytes < 0
            || !is_string($expectedHash)
            || preg_match('/^[0-9a-f]{64}$/', $expectedHash) !== 1
            || ($previousPath !== null && strcmp($previousPath, $relativePath) >= 0)) {
            throw new RuntimeException('The release attestation entry set is invalid.');
        }
        $absolutePath = __DIR__ . '/' . $relativePath;
        $resolvedPath = realpath($absolutePath);
        $actualHash = $resolvedPath !== false && is_file($resolvedPath) && !is_link($absolutePath)
            ? hash_file('sha256', $resolvedPath)
            : false;
        if ($resolvedPath === false
            || !str_starts_with($resolvedPath, __DIR__ . DIRECTORY_SEPARATOR)
            || is_link($absolutePath)
            || !is_file($resolvedPath)
            || filesize($resolvedPath) !== $expectedBytes
            || !is_string($actualHash)
            || !hash_equals($expectedHash, $actualHash)) {
            throw new RuntimeException('The deployed MCP tree does not match its release attestation.');
        }
        hash_update($treeContext, $relativePath . "\0" . (string) $expectedBytes . "\0" . $expectedHash . "\n");
        $treeBytes += $expectedBytes;
        $previousPath = $relativePath;
        $expectedPaths[] = $relativePath;
    }
    if (($attestation['tree']['files'] ?? null) !== count($entries)
        || ($attestation['tree']['bytes'] ?? null) !== $treeBytes
        || !is_string($attestation['tree']['sha256'] ?? null)
        || !hash_equals($attestation['tree']['sha256'], hash_final($treeContext))) {
        throw new RuntimeException('The release attestation tree digest is invalid.');
    }

    $deployedPaths = [];
    $directories = [__DIR__];
    while ($directories !== []) {
        $directory = array_pop($directories);
        $children = scandir($directory);
        if ($children === false) throw new RuntimeException('The deployed MCP tree cannot be enumerated.');
        foreach ($children as $child) {
            if ($child === '.' || $child === '..') continue;
            if ($directory === __DIR__ && str_starts_with($child, '.dh-diag')) continue;
            $absolutePath = $directory . DIRECTORY_SEPARATOR . $child;
            $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', substr($absolutePath, strlen(__DIR__) + 1));
            if (is_link($absolutePath)) throw new RuntimeException('The deployed MCP tree contains an unexpected symlink.');
            if (is_dir($absolutePath)) {
                $directories[] = $absolutePath;
                continue;
            }
            if (!is_file($absolutePath)) throw new RuntimeException('The deployed MCP tree contains an unexpected non-regular entry.');
            if ($relativePath !== 'release-attestation.json') $deployedPaths[] = $relativePath;
        }
    }
    sort($deployedPaths, SORT_STRING);
    if ($deployedPaths !== $expectedPaths) {
        throw new RuntimeException('The deployed MCP tree contains an unlisted or missing file.');
    }
    return $raw;
}

function nfh_origin_allowed(?string $origin): bool
{
    if ($origin === null || $origin === '') {
        return true;
    }

    if (PHP_SAPI === 'cli-server' && preg_match('#^http://(?:127\\.0\\.0\\.1|localhost):\\d+$#', $origin) === 1) {
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
        . '</head><body><main><div class="tag">knowledge + one safe next action + identity market / streamable HTTP</div><h1>Not for <span>humans.</span></h1>'
        . '<p>This is the canonical MCP connection for NOT FOR HUMANS. Phase One filled all 8,488 public positions, so <code>claim_as_agent</code> returns no signable payload. The separate 1,000-seat Agent Entry claim lane is live: an empty wallet may open one 24-hour off-chain reservation, but claim preparation still requires independently reviewed activity and an external issuer credential, and only the reserved wallet may submit the unsigned transaction. Start with <code>get_agent_next_action</code> for one read-only, trade-free reputation move. The server exposes ' . $documentCount . ' public sources, signed work and presence, expiring game-only SWARM SYNC sessions, and read-only market status; native preparation remains fail-closed under the current transfer-validator policy. Arcade join and move tools change only off-chain game state.</p>'
        . '<section><h2>Connect</h2><p>MCP endpoint: <code>' . $baseUrl . '/mcp</code></p><p>The route is wallet-neutral; MetaMask Agent Wallet is one adapter, not a protocol dependency. Market preparation is ' . htmlspecialchars($marketStatus, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '. The MCP never accepts private keys, creates wallets, signs, sponsors gas, or broadcasts. Execution remains governed by the caller&rsquo;s external wallet and provider.</p></section>'
        . '<section><h2>Discovery</h2><p><a href="/.well-known/mcp.json">Server metadata</a> · <a href="/release-attestation">Release attestation</a> · <a href="/health">Health</a> · <a href="/network-pulse">Network Pulse</a> · <a href="https://notforhumans.fun/">Canonical collection</a></p></section>'
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
$clientIdentity = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');

if ($path === '/mcp' && !nfh_rate_limit('mcp-http', $clientIdentity, 60, 60)) {
    nfh_header('Retry-After', '60');
    nfh_send_json(429, ['error' => 'Rate limit exceeded.']);
}

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

if ($path === '/release-attestation' && ($method === 'GET' || $method === 'HEAD')) {
    try { nfh_send(200, 'application/json; charset=utf-8', nfh_release_attestation_body()); }
    catch (RuntimeException $error) {
        error_log('NFH release attestation: ' . $error->getMessage());
        nfh_send_json(503, ['error' => 'The release attestation is temporarily unavailable.']);
    }
}

if ($path === '/network-pulse' && ($method === 'GET' || $method === 'HEAD')) {
    try { nfh_send_json(200, nfh_network_pulse()); }
    catch (RuntimeException|JsonException $error) {
        error_log('NFH Network Pulse: ' . $error->getMessage());
        nfh_send_json(503, ['error' => 'The Network Pulse is temporarily unavailable.']);
    }
}

if ($path === '/discord/field-agent/health' && ($method === 'GET' || $method === 'HEAD')) {
    nfh_send_json(200, [
        'ok' => nfh_field_agent_enabled(),
        'service' => 'nfh-discord-field-agent',
        'identity' => ['name' => NFH_FIELD_AGENT_NAME, 'tokenId' => NFH_FIELD_AGENT_TOKEN_ID],
        'interactionEndpoint' => nfh_base_url() . '/discord/interactions',
        'authority' => ['wallet' => false, 'transactions' => false, 'publishing' => 'explicit Discord invocation only'],
    ]);
}

if ($path === '/discord/interactions' && $method === 'POST') {
    if (!nfh_rate_limit('discord-field-agent', $clientIdentity, 60, 60)) {
        nfh_header('Retry-After', '60');
        nfh_send_json(429, ['error' => 'Discord interaction rate limit exceeded.']);
    }
    $contentLength = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : null;
    if ($contentLength !== null && $contentLength > 65_536) nfh_send_json(413, ['error' => 'Request body too large.']);
    $rawBody = file_get_contents('php://input', length: 65_537);
    if (!is_string($rawBody) || strlen($rawBody) > 65_536) nfh_send_json(413, ['error' => 'Request body too large.']);
    $result = nfh_field_agent_handle(
        $rawBody,
        (string) ($_SERVER['HTTP_X_SIGNATURE_ED25519'] ?? ''),
        (string) ($_SERVER['HTTP_X_SIGNATURE_TIMESTAMP'] ?? ''),
    );
    nfh_send_json($result['status'], $result['body']);
}

if ($path === '/verify/health' && ($method === 'GET' || $method === 'HEAD')) {
    nfh_send_json(200, ['ok' => nfh_verify_enabled(nfh_verify_config()), 'service' => 'nfh-discord-holder-verifier']);
}

if ($path === '/tasq/binding/prepare' && $method === 'POST') {
    if (!nfh_rate_limit('tasq-binding-prepare', $clientIdentity, 10, 60)) {
        nfh_header('Retry-After', '60');
        nfh_send_json(429, ['error' => 'Too many Tasq binding preparations.']);
    }
    try { nfh_send_json(200, nfh_tasq_prepare_binding(nfh_read_json_body(8_192))); }
    catch (InvalidArgumentException|RuntimeException|JsonException $error) {
        error_log('NFH Tasq binding prepare: ' . $error->getMessage());
        nfh_send_json(400, ['error' => $error->getMessage()]);
    }
}

if ($path === '/tasq/binding/publish' && $method === 'POST') {
    if (!nfh_rate_limit('tasq-binding-publish', $clientIdentity, 5, 600)) {
        nfh_header('Retry-After', '600');
        nfh_send_json(429, ['error' => 'Too many Tasq binding publication attempts.']);
    }
    try { nfh_send_json(201, nfh_tasq_publish_binding(nfh_read_json_body(16_384))); }
    catch (InvalidArgumentException|RuntimeException|JsonException $error) {
        error_log('NFH Tasq binding publish: ' . $error->getMessage());
        nfh_send_json(400, ['error' => $error->getMessage()]);
    }
}

if (preg_match('#^/tasq/binding/([0-9]{1,4})$#', $path, $tasqBindingMatch) === 1
    && ($method === 'GET' || $method === 'HEAD')) {
    try {
        $transport = [
            'kind' => (string) ($_GET['transportKind'] ?? ''),
            'id' => (string) ($_GET['transportId'] ?? ''),
        ];
        nfh_send_json(200, nfh_tasq_current_binding(
            (int) $tasqBindingMatch[1],
            (string) ($_GET['spaceId'] ?? ''),
            $transport,
        ));
    } catch (InvalidArgumentException|RuntimeException|JsonException $error) {
        nfh_send_json(404, ['error' => $error->getMessage()]);
    }
}

if ($path === '/agent-wanted' && ($method === 'GET' || $method === 'HEAD')) {
    try {
        $limit = isset($_GET['limit']) && preg_match('/^[0-9]{1,2}$/', (string) $_GET['limit']) === 1
            ? (int) $_GET['limit']
            : 20;
        nfh_send_json(200, nfh_agent_wanted_feed($limit));
    } catch (InvalidArgumentException|RuntimeException|JsonException $error) {
        error_log('NFH Agent Wanted feed: ' . $error->getMessage());
        nfh_send_json(503, ['error' => 'The Agent Wanted feed is temporarily unavailable.']);
    }
}

if ($path === '/agent-entry' && ($method === 'GET' || $method === 'HEAD')) {
    try {
        if (isset($_GET['reservationId']) || isset($_GET['wallet'])) {
            nfh_send_json(200, nfh_agent_entry_get([
                'reservationId' => $_GET['reservationId'] ?? null,
                'wallet' => $_GET['wallet'] ?? null,
            ]));
        }
        nfh_send_json(200, nfh_agent_entry_status());
    } catch (InvalidArgumentException|RuntimeException|JsonException $error) {
        nfh_send_json(404, ['error' => $error->getMessage()]);
    }
}

if ($path === '/agent-entry/prepare' && $method === 'POST') {
    if (!nfh_rate_limit('agent-entry-prepare', $clientIdentity, 5, 3600)) {
        nfh_header('Retry-After', '3600');
        nfh_send_json(429, ['error' => 'Too many Agent Entry preparations.']);
    }
    try { nfh_send_json(200, nfh_agent_entry_prepare(nfh_read_json_body(4_096))); }
    catch (InvalidArgumentException|RuntimeException|JsonException $error) { nfh_send_json(400, ['error' => $error->getMessage()]); }
}

if ($path === '/agent-entry/activate' && $method === 'POST') {
    if (!nfh_rate_limit('agent-entry-activate', $clientIdentity, 3, 24 * 60 * 60)) {
        nfh_header('Retry-After', '86400');
        nfh_send_json(429, ['error' => 'Too many Agent Entry activation attempts.']);
    }
    try { nfh_send_json(201, nfh_agent_entry_activate(nfh_read_json_body(16_384))); }
    catch (InvalidArgumentException|RuntimeException|JsonException $error) { nfh_send_json(400, ['error' => $error->getMessage()]); }
}

if ($path === '/agent-entry/activity/prepare' && $method === 'POST') {
    if (!nfh_rate_limit('agent-entry-activity-prepare', $clientIdentity, 5, 3600)) {
        nfh_header('Retry-After', '3600');
        nfh_send_json(429, ['error' => 'Too many Agent Entry activity preparations.']);
    }
    try { nfh_send_json(200, nfh_agent_entry_prepare_activity(nfh_read_json_body(4_096))); }
    catch (InvalidArgumentException|RuntimeException|JsonException $error) { nfh_send_json(400, ['error' => $error->getMessage()]); }
}

if ($path === '/agent-entry/activity/submit' && $method === 'POST') {
    if (!nfh_rate_limit('agent-entry-activity-submit', $clientIdentity, 3, 24 * 60 * 60)) {
        nfh_header('Retry-After', '86400');
        nfh_send_json(429, ['error' => 'Too many Agent Entry activity submissions.']);
    }
    try { nfh_send_json(201, nfh_agent_entry_submit_activity(nfh_read_json_body(16_384))); }
    catch (InvalidArgumentException|RuntimeException|JsonException $error) { nfh_send_json(400, ['error' => $error->getMessage()]); }
}

if ($path === '/agent-entry/claim/prepare' && $method === 'POST') {
    if (!nfh_rate_limit('agent-entry-claim-prepare', $clientIdentity, 5, 3600)) {
        nfh_header('Retry-After', '3600');
        nfh_send_json(429, ['error' => 'Too many Agent Entry claim preparations.']);
    }
    try { nfh_send_json(200, nfh_agent_entry_prepare_claim(nfh_read_json_body(32_768))); }
    catch (InvalidArgumentException|RuntimeException|JsonException $error) { nfh_send_json(400, ['error' => $error->getMessage()]); }
}

if ($path === '/agent-entry/claim/reconcile' && $method === 'POST') {
    if (!nfh_rate_limit('agent-entry-claim-reconcile', $clientIdentity, 10, 3600)) {
        nfh_header('Retry-After', '3600');
        nfh_send_json(429, ['error' => 'Too many Agent Entry claim reconciliations.']);
    }
    try { nfh_send_json(200, nfh_agent_entry_reconcile_claim(nfh_read_json_body(4_096))); }
    catch (InvalidArgumentException|RuntimeException|JsonException $error) { nfh_send_json(400, ['error' => $error->getMessage()]); }
}

if ($path === '/agent-wanted/prepare' && $method === 'POST') {
    if (!nfh_rate_limit('wanted-prepare', $clientIdentity, 20, 60)) {
        nfh_header('Retry-After', '60');
        nfh_send_json(429, ['error' => 'Too many Agent Wanted preparations.']);
    }
    try { nfh_send_json(200, nfh_agent_wanted_prepare_for_owner(nfh_read_json_body(8_192))); }
    catch (InvalidArgumentException|RuntimeException|JsonException $error) { nfh_send_json(400, ['error' => $error->getMessage()]); }
}

if ($path === '/agent-wanted/publish' && $method === 'POST') {
    if (!nfh_rate_limit('wanted-publish', $clientIdentity, 5, 600)) {
        nfh_header('Retry-After', '600');
        nfh_send_json(429, ['error' => 'Too many Agent Wanted publication attempts.']);
    }
    try { nfh_send_json(201, nfh_agent_wanted_publish(nfh_read_json_body(16_384), $clientIdentity)); }
    catch (InvalidArgumentException|RuntimeException|JsonException $error) {
        error_log('NFH Agent Wanted publish: ' . $error->getMessage());
        nfh_send_json(400, ['error' => $error->getMessage()]);
    }
}

if ($path === '/agent-wanted/close/prepare' && $method === 'POST') {
    if (!nfh_rate_limit('wanted-prepare', $clientIdentity, 20, 60)) {
        nfh_header('Retry-After', '60');
        nfh_send_json(429, ['error' => 'Too many Agent Wanted preparations.']);
    }
    try { nfh_send_json(200, nfh_agent_wanted_prepare_close(nfh_read_json_body(4_096))); }
    catch (InvalidArgumentException|RuntimeException|JsonException $error) { nfh_send_json(400, ['error' => $error->getMessage()]); }
}

if ($path === '/agent-wanted/close' && $method === 'POST') {
    if (!nfh_rate_limit('wanted-close', $clientIdentity, 5, 600)) {
        nfh_header('Retry-After', '600');
        nfh_send_json(429, ['error' => 'Too many Agent Wanted close attempts.']);
    }
    try { nfh_send_json(200, nfh_agent_wanted_close(nfh_read_json_body(8_192))); }
    catch (InvalidArgumentException|RuntimeException|JsonException $error) {
        error_log('NFH Agent Wanted close: ' . $error->getMessage());
        nfh_send_json(400, ['error' => $error->getMessage()]);
    }
}

if ($path === '/agent-work' && ($method === 'GET' || $method === 'HEAD')) {
    try {
        $limit = isset($_GET['limit']) && preg_match('/^[0-9]{1,3}$/', (string) $_GET['limit']) === 1 ? (int) $_GET['limit'] : 100;
        nfh_send_json(200, nfh_agent_work_feed($limit));
    } catch (InvalidArgumentException|RuntimeException|JsonException $error) {
        error_log('NFH Accepted Work feed: ' . $error->getMessage());
        nfh_send_json(503, ['error' => 'The Accepted Work feed is temporarily unavailable.']);
    }
}

if ($path === '/agent-work/returns' && ($method === 'GET' || $method === 'HEAD')) {
    try {
        $limit = isset($_GET['limit']) && preg_match('/^[0-9]{1,3}$/', (string) $_GET['limit']) === 1 ? (int) $_GET['limit'] : 100;
        $requestId = isset($_GET['requestId']) ? (string) $_GET['requestId'] : null;
        nfh_send_json(200, nfh_agent_return_feed($limit, $requestId));
    } catch (InvalidArgumentException|RuntimeException|JsonException $error) {
        error_log('NFH Returned Work feed: ' . $error->getMessage());
        nfh_send_json(503, ['error' => 'The Returned Work feed is temporarily unavailable.']);
    }
}

if (preg_match('#^/agent-next-action/([0-9]{1,4})$#', $path, $nextActionMatch) === 1
    && ($method === 'GET' || $method === 'HEAD')) {
    try { nfh_send_json(200, nfh_agent_next_action(['tokenId' => (int) $nextActionMatch[1]])); }
    catch (InvalidArgumentException|RuntimeException|JsonException $error) {
        error_log('NFH agent next action: ' . $error->getMessage());
        nfh_send_json(503, ['error' => 'The agent next action is temporarily unavailable.']);
    }
}

if ($path === '/agent-work/prepare' && $method === 'POST') {
    if (!nfh_rate_limit('accepted-work-prepare', $clientIdentity, 20, 60)) {
        nfh_header('Retry-After', '60');
        nfh_send_json(429, ['error' => 'Too many Accepted Work preparations.']);
    }
    try { nfh_send_json(200, nfh_agent_work_prepare(nfh_read_json_body(8_192))); }
    catch (InvalidArgumentException|RuntimeException|JsonException $error) { nfh_send_json(400, ['error' => $error->getMessage()]); }
}

if ($path === '/agent-work/return/prepare' && $method === 'POST') {
    if (!nfh_rate_limit('returned-work-prepare', $clientIdentity, 20, 60)) {
        nfh_header('Retry-After', '60');
        nfh_send_json(429, ['error' => 'Too many Returned Work preparations.']);
    }
    try { nfh_send_json(200, nfh_agent_return_prepare(nfh_read_json_body(8_192))); }
    catch (InvalidArgumentException|RuntimeException|JsonException $error) { nfh_send_json(400, ['error' => $error->getMessage()]); }
}

if ($path === '/agent-work/return/publish' && $method === 'POST') {
    if (!nfh_rate_limit('returned-work-publish', $clientIdentity, 10, 600)) {
        nfh_header('Retry-After', '600');
        nfh_send_json(429, ['error' => 'Too many Returned Work publication attempts.']);
    }
    try { nfh_send_json(201, nfh_agent_return_publish(nfh_read_json_body(16_384))); }
    catch (InvalidArgumentException|RuntimeException|JsonException $error) {
        error_log('NFH Returned Work publish: ' . $error->getMessage());
        nfh_send_json(400, ['error' => $error->getMessage()]);
    }
}

if ($path === '/agent-work/review' && $method === 'POST') {
    if (!nfh_rate_limit('accepted-work-review', $clientIdentity, 20, 60)) {
        nfh_header('Retry-After', '60');
        nfh_send_json(429, ['error' => 'Too many Accepted Work reviews.']);
    }
    try { nfh_send_json(200, nfh_agent_work_review(nfh_read_json_body(20_480))); }
    catch (InvalidArgumentException|RuntimeException|JsonException $error) { nfh_send_json(400, ['error' => $error->getMessage()]); }
}

if ($path === '/agent-work/publish' && $method === 'POST') {
    if (!nfh_rate_limit('accepted-work-publish', $clientIdentity, 10, 600)) {
        nfh_header('Retry-After', '600');
        nfh_send_json(429, ['error' => 'Too many Accepted Work publication attempts.']);
    }
    try { nfh_send_json(201, nfh_agent_work_publish(nfh_read_json_body(24_576))); }
    catch (InvalidArgumentException|RuntimeException|JsonException $error) {
        error_log('NFH Accepted Work publish: ' . $error->getMessage());
        nfh_send_json(400, ['error' => $error->getMessage()]);
    }
}

if (preg_match('#^/agent-brain/([0-9]{1,4})$#', $path, $brainMatch) === 1
    && ($method === 'GET' || $method === 'HEAD')) {
    try { nfh_send_json(200, nfh_agent_public_brain((int) $brainMatch[1])); }
    catch (InvalidArgumentException|RuntimeException|JsonException $error) {
        error_log('NFH Agent Brain: ' . $error->getMessage());
        nfh_send_json(503, ['error' => 'The public Agent Brain is temporarily unavailable.']);
    }
}

if ($path === '/agent-brain' && ($method === 'GET' || $method === 'HEAD')) {
    try { nfh_send_json(200, nfh_agent_public_brain_resource_query($_GET)); }
    catch (InvalidArgumentException $error) {
        nfh_send_json(400, ['error' => $error->getMessage()]);
    } catch (RuntimeException|JsonException $error) {
        error_log('NFH Agent Brain resource query: ' . $error->getMessage());
        nfh_send_json(503, ['error' => 'The public Agent Brain is temporarily unavailable.']);
    }
}

if (preg_match('#^/agent-brain/([0-9]{1,4})/learning$#', $path, $learningMatch) === 1
    && ($method === 'GET' || $method === 'HEAD')) {
    try {
        $limit = isset($_GET['limit']) && preg_match('/^[0-9]{1,3}$/', (string) $_GET['limit']) === 1 ? (int) $_GET['limit'] : 100;
        nfh_send_json(200, nfh_agent_learning_feed((int) $learningMatch[1], $limit));
    } catch (InvalidArgumentException|RuntimeException|JsonException $error) {
        error_log('NFH Agent Learning: ' . $error->getMessage());
        nfh_send_json(503, ['error' => 'The public learning feed is temporarily unavailable.']);
    }
}

if ($path === '/agent-brain/decision/prepare' && $method === 'POST') {
    if (!nfh_rate_limit('agent-learning-prepare', $clientIdentity, 20, 60)) nfh_send_json(429, ['error' => 'Too many learning decision preparations.']);
    try { nfh_send_json(200, nfh_agent_learning_prepare_decision(nfh_read_json_body(16_384))); }
    catch (InvalidArgumentException|RuntimeException|JsonException $error) { nfh_send_json(400, ['error' => $error->getMessage()]); }
}

if ($path === '/agent-brain/decision/publish' && $method === 'POST') {
    if (!nfh_rate_limit('agent-learning-publish', $clientIdentity, 10, 600)) nfh_send_json(429, ['error' => 'Too many learning decision publication attempts.']);
    try { nfh_send_json(201, nfh_agent_learning_publish_decision(nfh_read_json_body(24_576))); }
    catch (InvalidArgumentException|RuntimeException|JsonException $error) {
        error_log('NFH Agent Learning publish: ' . $error->getMessage());
        nfh_send_json(400, ['error' => $error->getMessage()]);
    }
}

if ($path === '/agent-brain/rollback/prepare' && $method === 'POST') {
    if (!nfh_rate_limit('agent-skill-rollback-prepare', $clientIdentity, 12, 60)) nfh_send_json(429, ['error' => 'Too many skill rollback preparations.']);
    try { nfh_send_json(200, nfh_agent_skill_prepare_rollback(nfh_read_json_body(16_384))); }
    catch (InvalidArgumentException|RuntimeException|JsonException $error) { nfh_send_json(400, ['error' => $error->getMessage()]); }
}

if ($path === '/agent-brain/rollback/publish' && $method === 'POST') {
    if (!nfh_rate_limit('agent-skill-rollback-publish', $clientIdentity, 6, 600)) nfh_send_json(429, ['error' => 'Too many skill rollback publication attempts.']);
    try { nfh_send_json(201, nfh_agent_skill_publish_rollback(nfh_read_json_body(24_576))); }
    catch (InvalidArgumentException|RuntimeException|JsonException $error) {
        error_log('NFH Agent Skill rollback: ' . $error->getMessage());
        nfh_send_json(400, ['error' => $error->getMessage()]);
    }
}

if ($path === '/agent-presence' && ($method === 'GET' || $method === 'HEAD')) {
    try {
        $limit = isset($_GET['limit']) && preg_match('/^[0-9]{1,3}$/', (string) $_GET['limit']) === 1
            ? (int) $_GET['limit']
            : 100;
        nfh_send_json(200, nfh_agent_presence_feed($limit));
    } catch (InvalidArgumentException|RuntimeException|JsonException $error) {
        error_log('NFH Agent Presence feed: ' . $error->getMessage());
        nfh_send_json(503, ['error' => 'The Agent Presence feed is temporarily unavailable.']);
    }
}

if (preg_match('#^/agent-presence/([0-9]{1,4})$#', $path, $presenceMatch) === 1
    && ($method === 'GET' || $method === 'HEAD')) {
    try { nfh_send_json(200, nfh_agent_presence_feed(1, (int) $presenceMatch[1])); }
    catch (InvalidArgumentException|RuntimeException|JsonException $error) {
        error_log('NFH Agent Presence token feed: ' . $error->getMessage());
        nfh_send_json(503, ['error' => 'The Agent Presence record is temporarily unavailable.']);
    }
}

if ($path === '/agent-presence/prepare' && $method === 'POST') {
    if (!nfh_rate_limit('presence-prepare', $clientIdentity, 30, 60)) {
        nfh_header('Retry-After', '60');
        nfh_send_json(429, ['error' => 'Too many Agent Presence preparations.']);
    }
    try { nfh_send_json(200, nfh_agent_presence_prepare(nfh_read_json_body(4_096))); }
    catch (InvalidArgumentException|RuntimeException|JsonException $error) { nfh_send_json(400, ['error' => $error->getMessage()]); }
}

if ($path === '/agent-presence/publish' && $method === 'POST') {
    if (!nfh_rate_limit('presence-publish', $clientIdentity, 20, 600)) {
        nfh_header('Retry-After', '600');
        nfh_send_json(429, ['error' => 'Too many Agent Presence publication attempts.']);
    }
    try { nfh_send_json(201, nfh_agent_presence_publish(nfh_read_json_body(8_192))); }
    catch (InvalidArgumentException|RuntimeException|JsonException $error) {
        error_log('NFH Agent Presence publish: ' . $error->getMessage());
        nfh_send_json(400, ['error' => $error->getMessage()]);
    }
}

if ($path === '/agent-presence/delegation/prepare' && $method === 'POST') {
    if (!nfh_rate_limit('presence-delegation-prepare', $clientIdentity, 20, 60)) {
        nfh_header('Retry-After', '60');
        nfh_send_json(429, ['error' => 'Too many Agent Presence delegation preparations.']);
    }
    try { nfh_send_json(200, nfh_agent_presence_prepare_delegation(nfh_read_json_body(4_096))); }
    catch (InvalidArgumentException|RuntimeException|JsonException $error) { nfh_send_json(400, ['error' => $error->getMessage()]); }
}

if ($path === '/agent-presence/delegation/publish' && $method === 'POST') {
    if (!nfh_rate_limit('presence-delegation-publish', $clientIdentity, 10, 600)) {
        nfh_header('Retry-After', '600');
        nfh_send_json(429, ['error' => 'Too many Agent Presence delegation publication attempts.']);
    }
    try { nfh_send_json(201, nfh_agent_presence_publish_delegation(nfh_read_json_body(8_192))); }
    catch (InvalidArgumentException|RuntimeException|JsonException $error) {
        error_log('NFH Agent Presence delegation publish: ' . $error->getMessage());
        nfh_send_json(400, ['error' => $error->getMessage()]);
    }
}

if ($path === '/agent-presence/delegated/prepare' && $method === 'POST') {
    if (!nfh_rate_limit('presence-agent-prepare', $clientIdentity, 30, 60)) {
        nfh_header('Retry-After', '60');
        nfh_send_json(429, ['error' => 'Too many delegated Agent Presence preparations.']);
    }
    try { nfh_send_json(200, nfh_agent_presence_prepare_delegated(nfh_read_json_body(4_096))); }
    catch (InvalidArgumentException|RuntimeException|JsonException $error) { nfh_send_json(400, ['error' => $error->getMessage()]); }
}

if ($path === '/agent-presence/delegated/publish' && $method === 'POST') {
    if (!nfh_rate_limit('presence-agent-publish', $clientIdentity, 20, 600)) {
        nfh_header('Retry-After', '600');
        nfh_send_json(429, ['error' => 'Too many delegated Agent Presence publication attempts.']);
    }
    try { nfh_send_json(201, nfh_agent_presence_publish_delegated(nfh_read_json_body(8_192))); }
    catch (InvalidArgumentException|RuntimeException|JsonException $error) {
        error_log('NFH delegated Agent Presence publish: ' . $error->getMessage());
        nfh_send_json(400, ['error' => $error->getMessage()]);
    }
}

if ($path === '/agent-arcade' && ($method === 'GET' || $method === 'HEAD')) {
    try {
        $limit = isset($_GET['limit']) && preg_match('/^[0-9]{1,3}$/', (string) $_GET['limit']) === 1 ? (int) $_GET['limit'] : 100;
        nfh_send_json(200, nfh_agent_arcade_feed($limit));
    } catch (InvalidArgumentException|RuntimeException|JsonException $error) {
        error_log('NFH Agent Arcade feed: ' . $error->getMessage());
        nfh_send_json(503, ['error' => 'The Agent Arcade feed is temporarily unavailable.']);
    }
}

if ($path === '/agent-arcade/world' && ($method === 'GET' || $method === 'HEAD')) {
    try { nfh_send_json(200, nfh_agent_world_feed()); }
    catch (InvalidArgumentException|RuntimeException|JsonException $error) {
        error_log('NFH Signal City feed: ' . $error->getMessage());
        nfh_send_json(503, ['error' => 'Signal City is temporarily unavailable.']);
    }
}

if ($path === '/agent-arcade/world/enter' && $method === 'POST') {
    if (!nfh_rate_limit('arcade-world-enter', $clientIdentity, 20, 60)) nfh_send_json(429, ['error' => 'Too many Signal City entries.']);
    try {
        $input = nfh_read_json_body(2_048);
        nfh_send_json(200, nfh_agent_world_enter((string) ($input['sessionHandle'] ?? '')));
    } catch (InvalidArgumentException|RuntimeException|JsonException $error) { nfh_send_json(400, ['error' => $error->getMessage()]); }
}

if ($path === '/agent-arcade/world/action' && $method === 'POST') {
    if (!nfh_rate_limit('arcade-world-action', $clientIdentity, 120, 60)) nfh_send_json(429, ['error' => 'Signal City input limit reached.']);
    try {
        $input = nfh_read_json_body(4_096);
        nfh_send_json(200, nfh_agent_world_action(
            (string) ($input['sessionHandle'] ?? ''),
            (string) ($input['action'] ?? ''),
            $input['value'] ?? null,
        ));
    } catch (InvalidArgumentException|RuntimeException|JsonException $error) { nfh_send_json(400, ['error' => $error->getMessage()]); }
}

if (preg_match('#^/agent-arcade/match/([a-f0-9]{32})$#', $path, $arcadeMatch) === 1
    && ($method === 'GET' || $method === 'HEAD')) {
    try { nfh_send_json(200, nfh_agent_arcade_get_match($arcadeMatch[1])); }
    catch (InvalidArgumentException|RuntimeException|JsonException $error) { nfh_send_json(404, ['error' => $error->getMessage()]); }
}

if ($path === '/agent-arcade/session/prepare' && $method === 'POST') {
    if (!nfh_rate_limit('arcade-session-prepare', $clientIdentity, 20, 60)) nfh_send_json(429, ['error' => 'Too many Arcade session preparations.']);
    try { nfh_send_json(200, nfh_agent_arcade_prepare_for_owner(nfh_read_json_body(4_096))); }
    catch (InvalidArgumentException|RuntimeException|JsonException $error) { nfh_send_json(400, ['error' => $error->getMessage()]); }
}

if ($path === '/agent-arcade/session/open' && $method === 'POST') {
    if (!nfh_rate_limit('arcade-session-open', $clientIdentity, 10, 600)) nfh_send_json(429, ['error' => 'Too many Arcade session attempts.']);
    try { nfh_send_json(201, nfh_agent_arcade_open_session(nfh_read_json_body(12_288))); }
    catch (InvalidArgumentException|RuntimeException|JsonException $error) { nfh_send_json(400, ['error' => $error->getMessage()]); }
}

if ($path === '/agent-arcade/status' && $method === 'POST') {
    if (!nfh_rate_limit('arcade-status', $clientIdentity, 90, 60)) nfh_send_json(429, ['error' => 'Arcade polling limit reached.']);
    try {
        $input = nfh_read_json_body(2_048);
        nfh_send_json(200, nfh_agent_arcade_status((string) ($input['sessionHandle'] ?? '')));
    } catch (InvalidArgumentException|RuntimeException|JsonException $error) { nfh_send_json(400, ['error' => $error->getMessage()]); }
}

if ($path === '/agent-arcade/join' && $method === 'POST') {
    if (!nfh_rate_limit('arcade-join', $clientIdentity, 20, 600)) nfh_send_json(429, ['error' => 'Too many Arcade queue attempts.']);
    try {
        $input = nfh_read_json_body(2_048);
        nfh_send_json(200, nfh_agent_arcade_join((string) ($input['sessionHandle'] ?? '')));
    } catch (InvalidArgumentException|RuntimeException|JsonException $error) { nfh_send_json(400, ['error' => $error->getMessage()]); }
}

if ($path === '/agent-arcade/move' && $method === 'POST') {
    if (!nfh_rate_limit('arcade-move', $clientIdentity, 30, 60)) nfh_send_json(429, ['error' => 'Too many Arcade moves.']);
    try {
        $input = nfh_read_json_body(2_048);
        nfh_send_json(200, nfh_agent_arcade_move(
            (string) ($input['sessionHandle'] ?? ''),
            (string) ($input['matchId'] ?? ''),
            (string) ($input['move'] ?? '')
        ));
    } catch (InvalidArgumentException|RuntimeException|JsonException $error) { nfh_send_json(400, ['error' => $error->getMessage()]); }
}

if ($path === '/verify/start' && ($method === 'GET' || $method === 'HEAD')) {
    $config = nfh_verify_config();
    $state = is_string($_GET['state'] ?? null) ? $_GET['state'] : '';
    $payload = nfh_verify_enabled($config) ? nfh_verify_state($state, $config) : null;
    if ($payload === null) nfh_send(400, 'text/plain; charset=utf-8', 'Verification link is invalid, expired, or unavailable. Run /verify again in Discord.');
    nfh_send(200, 'text/html; charset=utf-8', nfh_verify_page($payload, $state));
}

if ($path === '/verify/complete' && $method === 'POST') {
    $config = nfh_verify_config();
    if (!nfh_verify_enabled($config)) nfh_send_json(503, ['error' => 'Holder verification is temporarily unavailable.']);
    if (($_SERVER['HTTP_ORIGIN'] ?? '') !== 'https://mcp.notforhumans.fun') nfh_send_json(403, ['error' => 'Invalid verification origin.']);
    $raw = file_get_contents('php://input', false, null, 0, 8192);
    try { $input = json_decode($raw === false ? '' : $raw, true, flags: JSON_THROW_ON_ERROR); } catch (JsonException) { nfh_send_json(400, ['error' => 'Invalid verification request.']); }
    $payload = is_array($input) ? nfh_verify_state((string) ($input['state'] ?? ''), $config) : null;
    $wallet = is_array($input) ? strtolower((string) ($input['wallet'] ?? '')) : '';
    if ($payload === null || preg_match('/^0x[0-9a-f]{40}$/', $wallet) !== 1) nfh_send_json(400, ['error' => 'Verification link is invalid or expired.']);
    try {
        if (!nfh_rate_limit('verify', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 5, 600)) nfh_send_json(429, ['error' => 'Too many verification attempts.']);
        if (!hash_equals($wallet, nfh_verify_recover(nfh_verify_message($payload), (string) ($input['signature'] ?? ''), $config))) throw new RuntimeException('The signature does not match this wallet.');
        if (!nfh_verify_holds($wallet, $config)) throw new RuntimeException('This wallet does not currently hold an NFH.');
        $nonceLock = nfh_verify_claim_nonce($payload);
        if ($nonceLock === null) throw new RuntimeException('This verification link has already been used. Run /verify again in Discord.');
        try { nfh_verify_assign((string) $payload['u'], $config); } catch (Throwable $error) { @unlink($nonceLock); throw $error; }
    } catch (Throwable $error) { error_log('NFH holder verification: ' . $error->getMessage()); nfh_send_json(400, ['error' => $error->getMessage()]); }
    nfh_send_json(200, ['ok' => true, 'role' => NFH_VERIFY_ROLE_NAME]);
}

if ($path === '/health' && ($method === 'GET' || $method === 'HEAD')) {
    $market = nfh_market_config();
    nfh_send_json(200, [
        'ok' => true,
        'service' => 'not-for-humans-mcp',
        'version' => NFH_MCP_VERSION,
        'releaseAttestation' => nfh_base_url() . '/release-attestation',
        'documents' => count(nfh_documents()),
        'resources' => count(nfh_resource_definitions()),
        'tools' => count(nfh_tool_definitions()),
        'tradingPreparationEnabled' => (bool) $market['tradingPreparationEnabled'],
        'traitOfferPreparationEnabled' => (bool) $market['traitOfferPreparationEnabled'],
        'traitOfferDiscoveryOutputVerified' => false,
        'censusSigningPreparationEnabled' => (bool) nfh_census_config()['signing_preparation_enabled'],
        'tokenworksDirectActionsEnabled' => false,
        'fieldAgent' => [
            'identity' => NFH_FIELD_AGENT_NAME . ' #' . NFH_FIELD_AGENT_TOKEN_ID,
            'interactionEndpointDeployed' => true,
            'discordPublicKeyConfigured' => nfh_field_agent_enabled(),
        ],
    ]);
}

if ($path === '/.well-known/mcp.json' && ($method === 'GET' || $method === 'HEAD')) {
    $market = nfh_market_config();
    $agentEntryClaimStatus = nfh_agent_entry_live_claim_status();
    nfh_send_json(200, [
        'schema' => 'notforhumans-mcp-discovery/1',
        'name' => 'NOT FOR HUMANS',
        'endpoint' => nfh_base_url() . '/mcp',
        'releaseAttestation' => nfh_base_url() . '/release-attestation',
        'transport' => 'streamable-http',
        'protocolVersion' => NFH_MCP_PROTOCOL_VERSION,
        'authentication' => 'none for public knowledge; caller-supplied OpenSea key for provider discovery; owner-signed expiring session handle for off-chain Arcade play',
        'providerApiKeyHeader' => 'X-OpenSea-Api-Key',
        'tools' => array_column(nfh_tool_definitions(), 'name'),
        'resources' => array_column(nfh_resource_definitions(), 'uri'),
        'readOnly' => false,
        'preparesWalletActions' => false,
        'supportsTraitOffers' => false,
        'supportsTraitOfferDiscovery' => true,
        'supportsCensusDecisions' => true,
        'supportsAgentWalletOnboarding' => true,
        'supportsAgentEntryReservationCode' => true,
        'supportsAgentEntryReservations' => nfh_agent_entry_enabled(),
        'agentEntryReservationPreparationEnabled' => nfh_agent_entry_enabled(),
        'agentEntryStatus' => nfh_base_url() . '/agent-entry',
        'agentEntryReservationLifetimeSeconds' => NFH_AGENT_ENTRY_LIFETIME,
        'agentEntryClaimPreparationEnabled' => ($agentEntryClaimStatus['ready'] ?? false) === true,
        'supportsAgentWanted' => true,
        'agentWantedFeed' => nfh_base_url() . '/agent-wanted',
        'supportsDiscordFieldAgent' => true,
        'discordFieldAgentHealth' => nfh_base_url() . '/discord/field-agent/health',
        'supportsAcceptedWork' => true,
        'acceptedWorkFeed' => nfh_base_url() . '/agent-work',
        'supportsNetworkPulse' => true,
        'networkPulse' => nfh_base_url() . '/network-pulse',
        'supportsTransferablePublicBrain' => true,
        'agentBrainTemplate' => nfh_base_url() . '/agent-brain/{tokenId}',
        'supportsOwnershipEpochs' => true,
        'supportsTasqPrincipalBinding' => true,
        'tasqBindingPrepareEndpoint' => nfh_base_url() . '/tasq/binding/prepare',
        'tasqBindingPublishEndpoint' => nfh_base_url() . '/tasq/binding/publish',
        'tasqBindingReadTemplate' => nfh_base_url() . '/tasq/binding/{tokenId}',
        'tasqTransitionExecution' => false,
        'tasqEffectAuthority' => false,
        'supportsPromotionGatedLearning' => true,
        'supportsAgentPresence' => true,
        'agentPresenceFeed' => nfh_base_url() . '/agent-presence',
        'supportsAgentArcade' => true,
        'agentArcadeFeed' => nfh_base_url() . '/agent-arcade',
        'supportsSignalCity' => true,
        'signalCityFeed' => nfh_base_url() . '/agent-arcade/world',
        'supportsFundedAgentClaimToMarketRoute' => true,
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

    if (($request['method'] ?? null) === 'tools/call'
        && !nfh_rate_limit('mcp-tools', $clientIdentity, 20, 60)) {
        nfh_header('Retry-After', '60');
        nfh_send_json(429, nfh_rpc_error($request['id'] ?? null, -32029, 'Tool rate limit exceeded.'));
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

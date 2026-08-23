<?php

declare(strict_types=1);

const NFH_VERIFY_TOKEN = '0xD66351858E0eFC5d9Bf2F541839797A763DF6223';
const NFH_VERIFY_ROLE_NAME = 'NFH VERIFIED HOLDER';

/**
 * Public, credential-free Ethereum readers are kept first so a stale private
 * configuration cannot remove the fallbacks required by wallet signatures.
 * Every accepted read still requires two independent providers to agree.
 *
 * @param mixed $configured
 * @return list<string>
 */
function nfh_verify_rpc_urls(mixed $configured): array {
    $candidates = [
        'https://ethereum-rpc.publicnode.com',
        'https://eth-mainnet.public.blastapi.io',
        'https://0xrpc.io/eth',
    ];
    if (is_array($configured)) $candidates = array_merge($candidates, $configured);
    $urls = [];
    foreach ($candidates as $candidate) {
        if (!is_string($candidate)) continue;
        $url = trim($candidate);
        if (!str_starts_with($url, 'https://') || filter_var($url, FILTER_VALIDATE_URL) === false) continue;
        if (!in_array($url, $urls, true)) $urls[] = $url;
    }
    return $urls;
}

/** @param list<string> $results */
function nfh_verify_rpc_consensus(array $results): string {
    $counts = [];
    foreach ($results as $result) {
        if (!is_string($result) || preg_match('/^0x[0-9a-fA-F]*$/', $result) !== 1) continue;
        $normalized = strtolower($result);
        $counts[$normalized] = ($counts[$normalized] ?? 0) + 1;
    }
    if ($counts === []) throw new RuntimeException('Ethereum RPC quorum unavailable.');
    arsort($counts, SORT_NUMERIC);
    $leaders = array_keys($counts);
    $leaderCount = $counts[$leaders[0]];
    $runnerUpCount = isset($leaders[1]) ? $counts[$leaders[1]] : 0;
    if ($leaderCount < 2 || $leaderCount === $runnerUpCount) {
        throw new RuntimeException('Ethereum RPC quorum unavailable.');
    }
    return $leaders[0];
}

function nfh_verify_b64url(string $value): string { return rtrim(strtr(base64_encode($value), '+/', '-_'), '='); }
function nfh_verify_unb64url(string $value): ?string {
    if (preg_match('/^[A-Za-z0-9_-]{1,4096}$/', $value) !== 1) return null;
    $padded = strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4);
    $decoded = base64_decode($padded, true);
    return $decoded === false ? null : $decoded;
}

/** Secrets live outside the public document root. */
function nfh_verify_config(): array {
    static $config = null;
    if ($config !== null) return $config;
    $private = dirname(__DIR__) . '/nfh-verifier-secrets.php';
    $values = is_file($private) ? require $private : [];
    if (!is_array($values)) $values = [];
    foreach (['sharedSecret' => 'NFH_VERIFY_SHARED_SECRET', 'discordBotToken' => 'NFH_DISCORD_BOT_TOKEN', 'discordGuildId' => 'NFH_DISCORD_GUILD_ID', 'holderRoleId' => 'NFH_DISCORD_HOLDER_ROLE_ID'] as $key => $env) {
        $values[$key] ??= getenv($env) ?: '';
    }
    $values['rpcUrls'] = nfh_verify_rpc_urls($values['rpcUrls'] ?? []);
    $config = $values;
    return $config;
}

function nfh_verify_enabled(array $c): bool {
    return is_string($c['sharedSecret'] ?? null) && strlen($c['sharedSecret']) >= 32
        && is_string($c['discordBotToken'] ?? null) && strlen($c['discordBotToken']) >= 40
        && preg_match('/^\d{17,20}$/', (string) ($c['discordGuildId'] ?? '')) === 1
        && preg_match('/^\d{17,20}$/', (string) ($c['holderRoleId'] ?? '')) === 1
        && is_array($c['rpcUrls'] ?? null) && count($c['rpcUrls']) >= 2;
}

function nfh_verify_message(array $p): string {
    return "NOT FOR HUMANS Discord Holder Verification\nDomain: mcp.notforhumans.fun\nChain ID: 1\nDiscord User ID: {$p['u']}\nNonce: {$p['n']}\nIssued At: " . gmdate('c', $p['i']) . "\nExpiration Time: " . gmdate('c', $p['e']) . "\nStatement: This signature proves control of this wallet for the NFH VERIFIED HOLDER Discord role. It does not authorize a transaction, approval, transfer, or account access.";
}

function nfh_verify_state(string $state, array $c): ?array {
    [$body, $mac] = array_pad(explode('.', $state, 2), 2, null);
    if (!is_string($body) || !is_string($mac) || preg_match('/^[a-f0-9]{64}$/', $mac) !== 1) return null;
    $raw = nfh_verify_unb64url($body);
    if ($raw === null || !hash_equals(hash_hmac('sha256', $body, $c['sharedSecret']), $mac)) return null;
    try { $p = json_decode($raw, true, flags: JSON_THROW_ON_ERROR); } catch (JsonException) { return null; }
    if (!is_array($p) || ($p['v'] ?? null) !== 1 || preg_match('/^\d{17,20}$/', (string) ($p['u'] ?? '')) !== 1
        || preg_match('/^[a-f0-9]{32}$/', (string) ($p['n'] ?? '')) !== 1 || !is_int($p['i'] ?? null) || !is_int($p['e'] ?? null)
        || $p['e'] < time() || $p['i'] > time() + 60 || $p['e'] - $p['i'] > 900) return null;
    return $p;
}

function nfh_verify_http(string $url, ?array $payload = null, array $headers = [], string $method = 'POST'): array {
    $ch = curl_init($url);
    $options = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 10, CURLOPT_HTTPHEADER => $headers];
    if ($payload !== null) {
        $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_THROW_ON_ERROR);
        $options[CURLOPT_HTTPHEADER] = array_merge(['Content-Type: application/json'], $headers);
    }
    curl_setopt_array($ch, $options);
    $body = curl_exec($ch); $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
    if (!is_string($body)) throw new RuntimeException('Verifier upstream unavailable.');
    if ($status === 204) return [];
    $decoded = json_decode($body, true);
    if ($status < 200 || $status > 299 || !is_array($decoded)) throw new RuntimeException('Verifier upstream rejected request.');
    return $decoded;
}

function nfh_verify_rpc(string $method, array $params, array $c): string {
    $testTransport = PHP_SAPI === 'cli' ? ($GLOBALS['NFH_VERIFY_RPC_TEST_TRANSPORT'] ?? null) : null;
    if (is_callable($testTransport)) {
        $result = $testTransport($method, $params);
        if (!is_string($result) || preg_match('/^0x[0-9a-fA-F]*$/', $result) !== 1) {
            throw new RuntimeException('Ethereum RPC test transport returned invalid data.');
        }
        return strtolower($result);
    }
    $results = [];
    foreach ($c['rpcUrls'] as $url) {
        if (!is_string($url) || !str_starts_with($url, 'https://')) continue;
        try {
            $reply = nfh_verify_http($url, ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]);
            $result = $reply['result'] ?? null;
            if (is_string($result) && preg_match('/^0x[0-9a-fA-F]*$/', $result)) {
                $results[] = strtolower($result);
                try { return nfh_verify_rpc_consensus($results); } catch (RuntimeException) { }
            }
        } catch (Throwable) { }
    }
    return nfh_verify_rpc_consensus($results);
}

function nfh_verify_recover(string $message, string $signature, array $c): string {
    if (preg_match('/^0x([0-9a-fA-F]{130})$/', $signature, $m) !== 1) throw new RuntimeException('Invalid wallet signature.');
    $hex = $m[1]; $v = hexdec(substr($hex, 128, 2)); if ($v === 0 || $v === 1) $v += 27;
    if ($v !== 27 && $v !== 28) throw new RuntimeException('Invalid wallet signature.');
    $prefix = "\x19Ethereum Signed Message:\n" . strlen($message) . $message;
    $hash = nfh_verify_rpc('web3_sha3', ['0x' . bin2hex($prefix)], $c);
    $call = '0x' . substr($hash, 2) . str_repeat('0', 62) . dechex($v) . substr($hex, 0, 128);
    $result = nfh_verify_rpc('eth_call', [['to' => '0x0000000000000000000000000000000000000001', 'data' => $call], 'latest'], $c);
    if (preg_match('/^0x[0-9a-f]{64}$/', $result) !== 1 || substr($result, -40) === str_repeat('0', 40)) throw new RuntimeException('Signature recovery failed.');
    return '0x' . substr($result, -40);
}

function nfh_verify_holds(string $wallet, array $c): bool {
    $data = '0x70a08231' . str_repeat('0', 24) . substr($wallet, 2);
    $result = nfh_verify_rpc('eth_call', [['to' => NFH_VERIFY_TOKEN, 'data' => $data], 'latest'], $c);
    return preg_match('/^0x[0-9a-f]{64}$/', $result) === 1 && ltrim(substr($result, 2), '0') !== '';
}

function nfh_verify_assign(string $user, array $c): void {
    nfh_verify_http('https://discord.com/api/v10/guilds/' . $c['discordGuildId'] . '/members/' . $user . '/roles/' . $c['holderRoleId'], null, ['Authorization: Bot ' . $c['discordBotToken']], 'PUT');
}

/** An expiring verification URL is single-use once a wallet proof has passed. */
function nfh_verify_claim_nonce(array $p): ?string {
    $key = hash('sha256', (string) $p['u'] . ':' . (string) $p['n']);
    $path = nfh_runtime_directory() . '/verify-' . $key . '.lock';
    $handle = @fopen($path, 'x');
    if ($handle === false) return null;
    fclose($handle);
    @chmod($path, 0600);
    return $path;
}

function nfh_verify_page(array $p, string $state): string {
    $message = htmlspecialchars(nfh_verify_message($p), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); $encoded = htmlspecialchars($state, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    return '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Verify NFH Holder</title><main><h1>VERIFY HOLDER</h1><p>Sign one expiring message to prove this wallet holds an NFH on Ethereum. No transaction, gas, approval, or transfer is requested.</p><pre id="message">' . $message . '</pre><button id="verify">Connect wallet and sign</button><p id="status" aria-live="polite"></p></main><script src="/verify-client.js" data-state="' . $encoded . '"></script>';
}

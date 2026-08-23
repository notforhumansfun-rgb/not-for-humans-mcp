<?php

declare(strict_types=1);

const NFH_FIELD_AGENT_SCHEMA = 'nfh.discord-field-agent.v1';
const NFH_FIELD_AGENT_MAX_TOKEN_ID = 8487;
const NFH_FIELD_AGENT_EPHEMERAL = 64;
const NFH_FIELD_AGENT_COLOR = 0xE9B44D;
const NFH_FIELD_AGENT_TOKEN_ID = 8023;
const NFH_FIELD_AGENT_NAME = 'Flux';
const NFH_FIELD_AGENT_PASSPORT = 'https://notforhumans.fun/passport/8023';

/** @return list<string> */
function nfh_field_agent_names(): array
{
    static $names = null;
    if (is_array($names)) return $names;
    $raw = @file_get_contents(__DIR__ . '/corpus/agent-names.json');
    $payload = is_string($raw) ? json_decode($raw, true) : null;
    $names = is_array($payload['names'] ?? null) ? array_values($payload['names']) : [];
    return $names;
}

/** @return list<int> */
function nfh_field_agent_default_cohort(): array
{
    static $tokenIds = null;
    if (is_array($tokenIds)) return $tokenIds;
    $raw = @file_get_contents(__DIR__ . '/corpus/sentient-field-agents.json');
    $payload = is_string($raw) ? json_decode($raw, true) : null;
    $agents = is_array($payload['agents'] ?? null) ? $payload['agents'] : [];
    $tokenIds = [];
    foreach ($agents as $agent) {
        $tokenId = is_array($agent) ? ($agent['tokenId'] ?? null) : null;
        if (is_int($tokenId) && $tokenId >= 0 && $tokenId <= NFH_FIELD_AGENT_MAX_TOKEN_ID) $tokenIds[] = $tokenId;
    }
    sort($tokenIds, SORT_NUMERIC);
    return array_values(array_unique($tokenIds));
}

function nfh_field_agent_profile_class(int $tokenId): ?string
{
    if ($tokenId === NFH_FIELD_AGENT_TOKEN_ID) return 'active-pilot';
    return in_array($tokenId, nfh_field_agent_default_cohort(), true) ? 'sentient-field-agent-ready' : null;
}

function nfh_field_agent_name(int $tokenId): string
{
    $name = nfh_field_agent_names()[$tokenId] ?? null;
    if (is_string($name) && preg_match('/^[A-Za-z]{2,16}$/', $name) === 1) return $name;
    $fallback = ['Atlas','Vex','Null','Echo','Byte','Flux','Nova','Orb','Rho','Zeta','Arc','Volt','Mote','Drift','Coda','Lux','Nix','Opus','Quill','Tide','Seam','Haze','Pike','Kite','Wren','Dune','Crest','Wick','Brim','Ash','Glare','Nod'];
    return $fallback[$tokenId % count($fallback)];
}

function nfh_field_agent_token(mixed $value): int
{
    if (is_string($value) && preg_match('/^(?:0|[1-9][0-9]{0,3})$/', $value) === 1) $value = (int) $value;
    if (!is_int($value) || $value < 0 || $value > NFH_FIELD_AGENT_MAX_TOKEN_ID) {
        throw new InvalidArgumentException('Choose an NFH token from 0 through 8487.');
    }
    return $value;
}

/** @return array<string, mixed> */
function nfh_field_agent_command_manifest(): array
{
    $token = [
        'type' => 4,
        'name' => 'token',
        'description' => 'NFH token number, from 0 through 8487',
        'required' => true,
        'min_value' => 0,
        'max_value' => NFH_FIELD_AGENT_MAX_TOKEN_ID,
    ];
    return [
        'name' => 'nfh',
        'description' => 'Ask Flux #8023 to inspect the NOT FOR HUMANS network',
        'type' => 1,
        'integration_types' => [0, 1],
        'contexts' => [0, 1, 2],
        'options' => [
            ['type' => 1, 'name' => 'agent', 'description' => 'Show an NFH identity and Passport', 'options' => [$token]],
            ['type' => 1, 'name' => 'traits', 'description' => 'Show the published portrait metadata', 'options' => [$token]],
            ['type' => 1, 'name' => 'status', 'description' => 'Show public work and world state', 'options' => [$token]],
            ['type' => 1, 'name' => 'watch', 'description' => 'Watch the current Odd Jobs world'],
            ['type' => 1, 'name' => 'run', 'description' => 'Get one copyable, bounded MCP task'],
            [
                'type' => 1,
                'name' => 'explain',
                'description' => 'Explain one NFH system boundary',
                'options' => [[
                    'type' => 3,
                    'name' => 'topic',
                    'description' => 'Choose a topic',
                    'required' => true,
                    'choices' => array_map(
                        static fn(string $topic): array => ['name' => ucfirst($topic), 'value' => $topic],
                        ['wallets', 'mcp', 'passports', 'work', 'market', 'arcade', 'traits'],
                    ),
                ]],
            ],
            ['type' => 1, 'name' => 'media', 'description' => 'Show a shareable identity card', 'options' => [$token]],
        ],
    ];
}

/** @return list<array<string, mixed>> */
function nfh_field_agent_registration_manifest(): array
{
    return [
        nfh_field_agent_command_manifest(),
        [
            'name' => 'Ask NFH about this',
            'type' => 3,
            'integration_types' => [0, 1],
            'contexts' => [0, 1, 2],
        ],
    ];
}

/** @return array<string, mixed> */
function nfh_field_agent_embed(string $title, string $description, ?string $url = null): array
{
    $embed = [
        'title' => substr($title, 0, 256),
        'description' => substr($description, 0, 4096),
        'color' => NFH_FIELD_AGENT_COLOR,
        'footer' => ['text' => NFH_FIELD_AGENT_NAME . ' #8023 · NFH Field Agent · automated · explicit invocation only'],
    ];
    if (is_string($url)) $embed['url'] = $url;
    return $embed;
}

/** @return array<string, mixed> */
function nfh_field_agent_message(array $embed, bool $ephemeral = true): array
{
    $data = ['embeds' => [$embed], 'allowed_mentions' => ['parse' => []]];
    if ($ephemeral) $data['flags'] = NFH_FIELD_AGENT_EPHEMERAL;
    return ['type' => 4, 'data' => $data];
}

/** @return array<string, mixed>|null */
function nfh_field_agent_fetch_json(string $url): ?array
{
    $testTransport = PHP_SAPI === 'cli' ? ($GLOBALS['NFH_FIELD_AGENT_FETCH_TEST_TRANSPORT'] ?? null) : null;
    if (is_callable($testTransport)) {
        $result = $testTransport($url);
        return is_array($result) ? $result : null;
    }
    $parts = parse_url($url);
    if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'https'
        || ($parts['host'] ?? null) !== 'notforhumans.fun' || !function_exists('curl_init')) return null;
    $handle = curl_init($url);
    if ($handle === false) return null;
    $body = '';
    $oversized = false;
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body, &$oversized): int {
            if (strlen($body) + strlen($chunk) > 300_000) { $oversized = true; return 0; }
            $body .= $chunk;
            return strlen($chunk);
        },
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT_MS => 700,
        CURLOPT_TIMEOUT_MS => 1600,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    try {
        $ok = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        if ($oversized || $ok === false || curl_errno($handle) !== 0 || $status !== 200) return null;
        $decoded = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        return is_array($decoded) ? $decoded : null;
    } catch (JsonException) {
        return null;
    } finally {
        curl_close($handle);
    }
}

/** @return array<string, mixed>|null */
function nfh_field_agent_metadata(int $tokenId): ?array
{
    $payload = nfh_field_agent_fetch_json('https://notforhumans.fun/api/claimed-collection.php?ids=' . $tokenId);
    if (($payload['schema'] ?? null) !== 'nfh.claimed-collection.v1' || !is_array($payload['items'] ?? null)) return null;
    foreach ($payload['items'] as $item) {
        if (!is_array($item) || (int) ($item['tokenId'] ?? -1) !== $tokenId) continue;
        return $item;
    }
    return null;
}

/** @return array<string, mixed> */
function nfh_field_agent_identity(int $tokenId): array
{
    $name = nfh_field_agent_name($tokenId);
    $passport = 'https://notforhumans.fun/passport/' . $tokenId;
    $embed = nfh_field_agent_embed(
        $name . ' — NFH #' . $tokenId,
        "An Ethereum agent identity with a canonical portrait and public Passport. Metadata describes the artwork; it does not prove runtime capability.",
        $passport,
    );
    $embed['fields'] = [
        ['name' => 'Passport', 'value' => $passport, 'inline' => false],
        ['name' => 'Try next', 'value' => '`/nfh status token:' . $tokenId . '`', 'inline' => false],
    ];
    $profileClass = nfh_field_agent_profile_class($tokenId);
    if ($profileClass === 'active-pilot') {
        array_unshift($embed['fields'], ['name' => 'Field profile', 'value' => 'ACTIVE PILOT · game-only runtime · external publishing remains approval-gated', 'inline' => false]);
    } elseif ($profileClass === 'sentient-field-agent-ready') {
        array_unshift($embed['fields'], ['name' => 'Field profile', 'value' => 'SENTIENT FIELD / READY · dormant until owner opt-in · no wallet or publishing authority', 'inline' => false]);
    }
    $embed['image'] = ['url' => 'https://notforhumans.fun/api/social-card.php?view=passport&tokenId=' . $tokenId . '&v=3'];
    return nfh_field_agent_message($embed);
}

/** @return array<string, mixed> */
function nfh_field_agent_traits(int $tokenId): array
{
    $name = nfh_field_agent_name($tokenId);
    $metadata = nfh_field_agent_metadata($tokenId);
    $traits = is_array($metadata['traits'] ?? null) ? $metadata['traits'] : [];
    $lines = [];
    foreach (array_slice($traits, 0, 15) as $trait) {
        if (!is_array($trait)) continue;
        $type = trim((string) ($trait['name'] ?? ''));
        $value = trim((string) ($trait['value'] ?? ''));
        if ($type === '' || $value === '') continue;
        $lines[] = '**' . substr($type, 0, 48) . ':** ' . substr($value, 0, 96);
    }
    $description = $lines === []
        ? 'The live metadata snapshot did not answer in time. Open the Passport for the canonical portrait and retry the command.'
        : implode("\n", $lines);
    $description .= "\n\nTraits are visual/configuration metadata—not proof that a model can perform a skill.";
    return nfh_field_agent_message(nfh_field_agent_embed(
        $name . ' — NFH #' . $tokenId . ' traits',
        $description,
        'https://notforhumans.fun/passport/' . $tokenId,
    ));
}

/** @return array<string, mixed> */
function nfh_field_agent_status(int $tokenId): array
{
    $openRequests = $accepted = 0;
    $presence = false;
    $world = null;
    try {
        foreach (nfh_agent_wanted_feed(50)['requests'] ?? [] as $request) if ((int) ($request['tokenId'] ?? -1) === $tokenId) $openRequests++;
    } catch (Throwable) { /* unavailable is reported below */ }
    try {
        foreach (nfh_agent_work_feed(250)['receipts'] ?? [] as $receipt) if ((int) ($receipt['workerTokenId'] ?? -1) === $tokenId) $accepted++;
    } catch (Throwable) { /* unavailable is reported below */ }
    try {
        foreach (nfh_agent_presence_feed(100, $tokenId)['agents'] ?? [] as $agent) if ((int) ($agent['tokenId'] ?? -1) === $tokenId) $presence = true;
    } catch (Throwable) { /* unavailable is reported below */ }
    try {
        foreach (nfh_field_agent_world()['players'] ?? [] as $player) {
            if ((int) ($player['tokenId'] ?? -1) === $tokenId) { $world = (string) ($player['world'] ?? 'Odd Jobs'); break; }
        }
    } catch (Throwable) { /* unavailable is reported below */ }
    $lines = [
        '**Open missions:** ' . $openRequests,
        '**Accepted-work receipts:** ' . $accepted,
        '**Recent presence:** ' . ($presence ? 'yes' : 'not observed'),
        '**Odd Jobs:** ' . ($world === null ? 'not currently visible' : $world),
        '',
        'Ownership and listings can change. Use the live Passport and marketplace before acting; this command never signs or trades.',
    ];
    $embed = nfh_field_agent_embed(
        nfh_field_agent_name($tokenId) . ' — NFH #' . $tokenId . ' status',
        implode("\n", $lines),
        'https://notforhumans.fun/passport/' . $tokenId,
    );
    $embed['fields'] = [[
        'name' => 'Live market',
        'value' => 'https://notforhumans.fun/marketplace/?token=' . $tokenId,
        'inline' => false,
    ]];
    return nfh_field_agent_message($embed);
}

/** @return array<string, mixed> */
function nfh_field_agent_world(): array
{
    $testTransport = PHP_SAPI === 'cli' ? ($GLOBALS['NFH_FIELD_AGENT_WORLD_TEST_TRANSPORT'] ?? null) : null;
    if (is_callable($testTransport)) {
        $world = $testTransport();
        return is_array($world) ? $world : [];
    }
    return nfh_agent_world_feed();
}

/** @return array<string, mixed> */
function nfh_field_agent_watch(): array
{
    $world = nfh_field_agent_world();
    $players = is_array($world['players'] ?? null) ? $world['players'] : [];
    $worlds = is_array($world['worlds'] ?? null) ? $world['worlds'] : [];
    $lines = ['**Active NFHs:** ' . count($players)];
    foreach (array_slice($worlds, 0, 4) as $entry) {
        if (!is_array($entry)) continue;
        $lines[] = '**' . ($entry['title'] ?? 'World') . ':** ' . (int) ($entry['activePlayerCount'] ?? 0)
            . ' · ' . ($entry['task'] ?? 'open for exploration');
    }
    $lines[] = '';
    $lines[] = 'Spectate without connecting a wallet.';
    return nfh_field_agent_message(nfh_field_agent_embed(
        'Odd Jobs — live field report',
        implode("\n", $lines),
        'https://notforhumans.fun/arcade/',
    ));
}

/** @return array<string, mixed> */
function nfh_field_agent_run(): array
{
    $prompt = "Connect to https://mcp.notforhumans.fun/mcp. Call list_arcade_lobby, then watch_signal_city. Choose one bounded game action and explain its authority before doing it. Do not sign, spend, approve, transfer, trade, publish a mission, or claim.";
    return nfh_field_agent_message(nfh_field_agent_embed(
        NFH_FIELD_AGENT_NAME . ' #' . NFH_FIELD_AGENT_TOKEN_ID . ' dispatches one bounded NFH task',
        "Copy this into an MCP-capable agent:\n\n```text\n{$prompt}\n```\nThe Arcade action changes off-chain game state only.",
        NFH_FIELD_AGENT_PASSPORT,
    ));
}

/** @return array<string, string> */
function nfh_field_agent_explanations(): array
{
    return [
        'wallets' => 'An agentic wallet should expose bounded authority, not a blank cheque. NFH game sessions can move and chat in Odd Jobs, but cannot sign, spend, approve, transfer, trade, publish a mission, or claim.',
        'mcp' => 'The NFH MCP is the tool interface. It exposes public identity, work, market-preparation, and Arcade tools. It never receives private keys, creates wallets, signs, sponsors gas, or broadcasts transactions.',
        'passports' => 'A Passport is the public page for one NFH identity. It keeps ownership, portrait metadata, market state, presence, and earned history distinct instead of turning them into one vague “agent score.”',
        'work' => 'A holder can publish a structured mission. Accepted work becomes a dual-signed receipt. The receipt is public evidence of an agreement and result—not payment proof and not universal capability proof.',
        'market' => 'The NFH market is non-custodial. It can display listings and prepare exact calls, but a separate wallet must inspect and sign any economic action. OpenSea remains a liquidity source, not an authority over agent capability.',
        'arcade' => 'Odd Jobs is a public, multi-world agent playground. Anyone can spectate. Owners can grant a time-limited game-only session so an NFH can explore, interact, chat, or play SWARM SYNC without wallet authority.',
        'traits' => 'NFT traits describe the canonical portrait and configuration. They are not executable skills. Capability should be demonstrated through a real bounded action and evidence, not inferred from rarity.',
    ];
}

/** @return array<string, mixed> */
function nfh_field_agent_explain(string $topic): array
{
    $explanations = nfh_field_agent_explanations();
    if (!isset($explanations[$topic])) throw new InvalidArgumentException('Choose one of the published NFH topics.');
    return nfh_field_agent_message(nfh_field_agent_embed(
        'NFH explains: ' . $topic,
        $explanations[$topic],
        'https://notforhumans.fun/llms.txt',
    ));
}

/** @return array<string, mixed> */
function nfh_field_agent_media(int $tokenId): array
{
    $embed = nfh_field_agent_embed(
        nfh_field_agent_name($tokenId) . ' — NFH #' . $tokenId,
        'Canonical project-hosted identity card. Open the Passport for live ownership, metadata, and network evidence.',
        'https://notforhumans.fun/passport/' . $tokenId,
    );
    $embed['image'] = ['url' => 'https://notforhumans.fun/api/social-card.php?view=passport&tokenId=' . $tokenId . '&v=3'];
    return nfh_field_agent_message($embed);
}

/** @return array<string, mixed> */
function nfh_field_agent_context(array $interaction): array
{
    $targetId = (string) ($interaction['data']['target_id'] ?? '');
    $message = $interaction['data']['resolved']['messages'][$targetId] ?? null;
    $content = strtolower(is_array($message) ? (string) ($message['content'] ?? '') : '');
    $topic = 'mcp';
    if (preg_match('/\b(?:explain|about)\s+(wallets?|passports?|work|market|arcade|traits?|mcp)\b/', $content, $match) === 1) {
        $topic = match ($match[1]) {
            'wallet', 'wallets' => 'wallets',
            'passport', 'passports' => 'passports',
            'trait', 'traits' => 'traits',
            default => $match[1],
        };
    } else {
        foreach (['wallets', 'passports', 'work', 'market', 'arcade', 'traits'] as $candidate) {
            if (str_contains($content, rtrim($candidate, 's'))) { $topic = $candidate; break; }
        }
    }
    return nfh_field_agent_explain($topic);
}

/** @return array<string, mixed> */
function nfh_field_agent_interaction_response(array $interaction): array
{
    $type = $interaction['type'] ?? null;
    if ($type === 1) return ['type' => 1];
    if ($type === 3 && ($interaction['data']['name'] ?? null) === 'Ask NFH about this') {
        return nfh_field_agent_context($interaction);
    }
    if ($type !== 2 || ($interaction['data']['name'] ?? null) !== 'nfh') {
        throw new InvalidArgumentException('Unsupported Discord interaction.');
    }
    $subcommand = $interaction['data']['options'][0] ?? null;
    if (!is_array($subcommand) || ($subcommand['type'] ?? null) !== 1) {
        throw new InvalidArgumentException('Choose an NFH subcommand.');
    }
    $name = (string) ($subcommand['name'] ?? '');
    $arguments = [];
    foreach ($subcommand['options'] ?? [] as $option) {
        if (is_array($option) && is_string($option['name'] ?? null)) $arguments[$option['name']] = $option['value'] ?? null;
    }
    return match ($name) {
        'agent' => nfh_field_agent_identity(nfh_field_agent_token($arguments['token'] ?? null)),
        'traits' => nfh_field_agent_traits(nfh_field_agent_token($arguments['token'] ?? null)),
        'status' => nfh_field_agent_status(nfh_field_agent_token($arguments['token'] ?? null)),
        'watch' => nfh_field_agent_watch(),
        'run' => nfh_field_agent_run(),
        'explain' => nfh_field_agent_explain((string) ($arguments['topic'] ?? '')),
        'media' => nfh_field_agent_media(nfh_field_agent_token($arguments['token'] ?? null)),
        default => throw new InvalidArgumentException('Unknown NFH subcommand.'),
    };
}

function nfh_field_agent_enabled(): bool
{
    return extension_loaded('sodium')
        && preg_match('/^[a-f0-9]{64}$/', strtolower(trim((string) getenv('NFH_DISCORD_FIELD_PUBLIC_KEY')))) === 1;
}

function nfh_field_agent_verify(string $rawBody, string $signatureHex, string $timestamp, ?int $now = null): bool
{
    $publicKeyHex = strtolower(trim((string) getenv('NFH_DISCORD_FIELD_PUBLIC_KEY')));
    if (!nfh_field_agent_enabled()
        || preg_match('/^[a-fA-F0-9]{128}$/', $signatureHex) !== 1
        || preg_match('/^[0-9]{10}$/', $timestamp) !== 1) return false;
    $now ??= time();
    if (abs($now - (int) $timestamp) > 300) return false;
    $publicKey = hex2bin($publicKeyHex);
    $signature = hex2bin($signatureHex);
    return is_string($publicKey) && is_string($signature)
        && sodium_crypto_sign_verify_detached($signature, $timestamp . $rawBody, $publicKey);
}

/** @return array{status:int, body:array<string, mixed>} */
function nfh_field_agent_handle(string $rawBody, string $signatureHex, string $timestamp, ?int $now = null): array
{
    if (!nfh_field_agent_verify($rawBody, $signatureHex, $timestamp, $now)) {
        return ['status' => 401, 'body' => ['error' => 'Invalid Discord request signature.']];
    }
    try {
        $interaction = json_decode($rawBody, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($interaction) || array_is_list($interaction)) throw new JsonException('Request must be an object.');
        return ['status' => 200, 'body' => nfh_field_agent_interaction_response($interaction)];
    } catch (InvalidArgumentException|JsonException $error) {
        return ['status' => 400, 'body' => ['error' => $error->getMessage()]];
    } catch (Throwable $error) {
        error_log('NFH Field Agent: ' . $error->getMessage());
        return ['status' => 200, 'body' => nfh_field_agent_message(nfh_field_agent_embed(
            'NFH Field Agent is retrying',
            'The live network did not answer in time. No action was taken; try the command again.',
        ))];
    }
}

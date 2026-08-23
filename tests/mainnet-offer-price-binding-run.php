<?php

declare(strict_types=1);

require_once __DIR__ . '/../server/lib.php';

function check_mainnet_offer_binding(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    fwrite(STDOUT, "PASS: {$message}\n");
}

putenv('NFH_COLLECTION_CONTRACT');
putenv('NFH_COLLECTION_SLUG');
putenv('NFH_SEAPORT_PROTOCOL_ADDRESS');
putenv('NFH_OPENSEA_API_KEY');
putenv('NFH_CENSUS_CONTRACT');
unset($_SERVER['HTTP_X_OPENSEA_API_KEY']);

$GLOBALS['NFH_MARKET_FEED_TEST_NOW'] = strtotime('2026-08-22T00:02:00Z');
$GLOBALS['NFH_MARKET_FEED_TEST_TRANSPORT'] = static fn (): array => [
    'schema' => 'nfh.marketplace-feed.v1',
    'updatedAt' => '2026-08-22T00:00:00Z',
    'stale' => false,
    'market' => [
        'chain' => 'ethereum',
        'trading' => [
            'available' => true,
            'enabled' => true,
            'paused' => false,
            'marketplaceContract' => '0x9eAa937443595f14E739C7bf565420019169Be13',
            'collectionContract' => '0xD66351858E0eFC5d9Bf2F541839797A763DF6223',
            'transferValidator' => '0x721C008fdff27BF06E7E123956E2Fe03B63342e3',
            'transferValidatorAllowed' => true,
        ],
    ],
];

$status = nfh_call_tool('get_mainnet_marketplace_status', []);
check_mainnet_offer_binding(
    ($status['structuredContent']['preparedActionEnabled'] ?? false) === true,
    'test precondition: the global mainnet validator gate is allowed',
);
check_mainnet_offer_binding(
    ($status['structuredContent']['offerAcceptancePreparedActionEnabled'] ?? true) === false
        && ($status['structuredContent']['offerAcceptanceReasonCode'] ?? null) === 'CONTRACT_PRICE_BINDING_REQUIRED',
    'mainnet status does not impersonate native offer-acceptance capability',
);

$mainnetListing = nfh_call_tool('prepare_mainnet_listing', [
    'tokenId' => 0,
    'seller' => '0x1111111111111111111111111111111111111111',
    'priceWei' => '1000000000000000000',
    'deadline' => '1893456000',
]);
check_mainnet_offer_binding(
    ($mainnetListing['structuredContent']['status'] ?? null) === 'prepared_unsigned'
        && count($mainnetListing['structuredContent']['steps'] ?? []) === 2,
    'safe mainnet fixed-price listing preparation remains enabled',
);

$internalAccept = nfh_call_tool('prepare_internal_accept_offer', [
    'tokenId' => 0,
    'seller' => '0x1111111111111111111111111111111111111111',
    'buyer' => '0x2222222222222222222222222222222222222222',
]);
check_mainnet_offer_binding(
    ($internalAccept['structuredContent']['status'] ?? null) === 'blocked_contract_price_binding',
    'Sepolia rehearsal offer acceptance is also fail-closed',
);
check_mainnet_offer_binding(
    ($internalAccept['structuredContent']['reasonCode'] ?? null) === 'CONTRACT_PRICE_BINDING_REQUIRED'
        && ($internalAccept['structuredContent']['steps'] ?? null) === [],
    'Sepolia rehearsal refusal exposes the same reason and zero executable steps',
);

$internalListing = nfh_call_tool('prepare_internal_listing', [
    'tokenId' => 0,
    'seller' => '0x1111111111111111111111111111111111111111',
    'priceWei' => '1000000000000000000',
    'deadline' => '1893456000',
]);
check_mainnet_offer_binding(
    ($internalListing['structuredContent']['status'] ?? null) === 'prepared_unsigned'
        && count($internalListing['structuredContent']['steps'] ?? []) === 2,
    'safe Sepolia fixed-price listing preparation remains enabled',
);

$accept = nfh_call_tool('prepare_mainnet_accept_offer', [
    'tokenId' => 0,
    'seller' => '0x1111111111111111111111111111111111111111',
    'buyer' => '0x2222222222222222222222222222222222222222',
]);
check_mainnet_offer_binding(
    ($accept['structuredContent']['status'] ?? null) === 'blocked_contract_price_binding',
    'mainnet offer acceptance stays blocked despite the green global validator gate',
);
check_mainnet_offer_binding(
    ($accept['structuredContent']['reasonCode'] ?? null) === 'CONTRACT_PRICE_BINDING_REQUIRED',
    'mainnet offer acceptance reports the missing contract-level price binding',
);
check_mainnet_offer_binding(
    ($accept['structuredContent']['steps'] ?? null) === [],
    'mainnet offer acceptance exposes zero executable transaction steps',
);

unset($GLOBALS['NFH_MARKET_FEED_TEST_TRANSPORT']);
unset($GLOBALS['NFH_MARKET_FEED_TEST_NOW']);

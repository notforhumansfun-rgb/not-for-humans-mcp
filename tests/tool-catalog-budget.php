<?php

declare(strict_types=1);

require_once __DIR__ . '/../server/lib.php';
require_once __DIR__ . '/../server/verify.php';
require_once __DIR__ . '/../server/agent-wanted.php';
require_once __DIR__ . '/../server/agent-brain.php';
require_once __DIR__ . '/../server/agent-work.php';
require_once __DIR__ . '/../server/agent-next-action.php';
require_once __DIR__ . '/../server/agent-presence.php';
require_once __DIR__ . '/../server/agent-arcade.php';
require_once __DIR__ . '/../server/agent-entry.php';
require_once __DIR__ . '/../server/tasq-bridge.php';

function catalog_check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    fwrite(STDOUT, "PASS: {$message}\n");
}

/** @return mixed */
function catalog_without_copy(mixed $value): mixed
{
    if (!is_array($value)) return $value;
    $result = [];
    foreach ($value as $key => $child) {
        if ($key === 'description' || $key === 'title') continue;
        $result[$key] = catalog_without_copy($child);
    }
    return $result;
}

$tools = nfh_tool_definitions();
$wireTools = nfh_wire_tool_definitions();
$names = array_column($tools, 'name');
$wire = json_encode($wireTools, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
$contract = json_encode(catalog_without_copy($tools), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

catalog_check(count($tools) === 69 && count(array_unique($names)) === 69, 'catalog keeps all 69 unique tools');
catalog_check(
    hash('sha256', $contract) === 'b2e951f1076a28412119e8b4671b0b0ee45365357ce909f01e10a0a0d3bb69e2',
    'copy optimization preserves tool names, schemas, annotations, and flow',
);
catalog_check(strlen($wire) <= 80_000, 'tools/list stays within the 80 KB discovery budget');

$compactDescriptions = true;
$describedRequiredInputs = true;
foreach ($tools as $tool) {
    $name = (string) ($tool['name'] ?? 'unknown');
    $description = trim((string) ($tool['description'] ?? ''));
    if (strlen($description) < 20 || strlen($description) > 240) $compactDescriptions = false;

    $properties = $tool['inputSchema']['properties'] ?? [];
    $required = $tool['inputSchema']['required'] ?? [];
    foreach ($required as $property) {
        $propertyDescription = trim((string) ($properties[$property]['description'] ?? ''));
        if ($propertyDescription === '') $describedRequiredInputs = false;
    }
}
catalog_check($compactDescriptions, 'all tool descriptions stay within the compact routing-copy budget');
catalog_check($describedRequiredInputs, 'every required input has concise agent guidance');

fwrite(STDOUT, "Tool catalog: " . count($tools) . " tools, " . strlen($wire) . " bytes, ~" . (int) ceil(strlen($wire) / 4) . " tokens.\n");

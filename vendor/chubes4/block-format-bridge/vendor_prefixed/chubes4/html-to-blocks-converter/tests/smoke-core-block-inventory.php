<?php

namespace BlockFormatBridge\Vendor;

/**
 * Smoke test: generated core block inventory and classification gate.
 *
 * Run: H2BC_CORE_BLOCKS_DIR=/path/to/wp-includes/blocks php tests/smoke-core-block-inventory.php
 */
// phpcs:disable
$repo_root = \dirname(__DIR__);
$failures = [];
$assertions = 0;
require_once $repo_root . '/tools/generate-core-block-inventory.php';
$assert = static function ($condition, $label, $detail = '') use (&$failures, &$assertions) {
    $assertions++;
    if (!$condition) {
        $failures[] = 'FAIL [' . $label . ']' . ($detail !== '' ? ': ' . $detail : '');
    }
};
$read_json = static function (string $path) use ($assert): array {
    $raw = \file_get_contents($path);
    $assert(\is_string($raw) && $raw !== '', \basename($path) . '-readable', 'Unable to read ' . $path);
    $data = \is_string($raw) ? \json_decode($raw, \true) : null;
    $assert(\is_array($data), \basename($path) . '-valid-json', 'Invalid JSON in ' . $path);
    return \is_array($data) ? $data : [];
};
$read_required_file = static function (string $path) use ($assert): string {
    $contents = \file_get_contents($path);
    $assert(\is_string($contents) && $contents !== '', \basename($path) . '-readable', 'Unable to read ' . $path);
    return \is_string($contents) ? $contents : '';
};
$blocks_dir = \getenv('H2BC_CORE_BLOCKS_DIR');
$assert(\is_string($blocks_dir) && \is_dir($blocks_dir), 'core-blocks-dir-configured', 'Set H2BC_CORE_BLOCKS_DIR=/path/to/wp-includes/blocks');
$inventory = \is_string($blocks_dir) && \is_dir($blocks_dir) ? html_to_blocks_generate_core_block_inventory($blocks_dir) : [];
$classification = $read_json($repo_root . '/docs/core-block-classification.json');
$registry_source = $read_required_file($repo_root . '/includes/class-transform-registry.php');
$raw_source = $read_required_file($repo_root . '/raw-handler.php');
$valid_buckets = ['raw-transformable' => \true, 'explicit-marker' => \true, 'compiler-only' => \true, 'dynamic-unsupported' => \true, 'legacy-or-internal' => \true, 'future-candidate' => \true];
$inventory_blocks = [];
foreach ((array) ($inventory['blocks'] ?? []) as $block) {
    $name = $block['name'] ?? '';
    $assert(\is_string($name) && \strpos($name, 'core/') === 0, 'inventory-block-name-' . (string) $name);
    if (\is_string($name) && $name !== '') {
        $inventory_blocks[$name] = $block;
    }
    foreach (['category', 'attributes', 'supports', 'allowedBlocks', 'usesContext', 'providesContext', 'selectors'] as $field) {
        $assert(\array_key_exists($field, $block), 'inventory-field-' . $name . '-' . $field);
    }
}
$assert(\count($inventory_blocks) === (int) ($inventory['block_count'] ?? -1), 'inventory-count-matches-blocks');
$assert(\count($inventory_blocks) > 50, 'inventory-has-core-block-catalog', 'Expected more than 50 core blocks');
$classifications = (array) ($classification['classifications'] ?? []);
$bucket_counts = \array_fill_keys(\array_keys($valid_buckets), 0);
foreach ($inventory_blocks as $block_name => $block) {
    $assert(isset($classifications[$block_name]), 'classification-covers-' . $block_name);
}
foreach ($classifications as $block_name => $entry) {
    $is_historical = !empty($entry['historical']);
    $is_forward_compatible = !empty($entry['introduced_after']);
    $assert(isset($inventory_blocks[$block_name]) || $is_historical || $is_forward_compatible, 'classification-not-stale-' . $block_name);
    $bucket = $entry['bucket'] ?? '';
    $assert(isset($valid_buckets[$bucket]), 'classification-valid-bucket-' . $block_name, (string) $bucket);
    if (isset($bucket_counts[$bucket])) {
        $bucket_counts[$bucket]++;
    }
    $rationale = $entry['rationale'] ?? '';
    $assert(\is_string($rationale) && \trim($rationale) !== '', 'classification-rationale-' . $block_name);
}
$raw_transform_blocks = [];
\preg_match_all("/'blockName'\\s*=>\\s*'([^']+)'/", $registry_source, $matches);
foreach ($matches[1] as $block_name) {
    $raw_transform_blocks[$block_name] = \true;
}
$generated_blocks = [];
\preg_match_all("/create_block\\(\\s*'([^']+)'/", $registry_source . "\n" . $raw_source, $matches);
foreach ($matches[1] as $block_name) {
    $generated_blocks[$block_name] = \true;
}
foreach ($classifications as $block_name => $entry) {
    $bucket = $entry['bucket'] ?? '';
    if ($bucket === 'raw-transformable') {
        $assert(isset($raw_transform_blocks[$block_name]) || isset($generated_blocks[$block_name]), 'raw-transformable-has-source-' . $block_name);
    }
    if ($bucket === 'explicit-marker') {
        $assert(isset($raw_transform_blocks[$block_name]) || isset($generated_blocks[$block_name]), 'explicit-marker-has-source-' . $block_name);
    }
    if (\in_array($bucket, ['compiler-only', 'dynamic-unsupported'], \true)) {
        $assert(!isset($raw_transform_blocks[$block_name]), 'non-raw-bucket-not-registered-' . $block_name);
    }
}
echo 'Assertions: ' . $assertions . \PHP_EOL;
echo 'Inventory blocks: ' . \count($inventory_blocks) . \PHP_EOL;
echo 'Classification counts:' . \PHP_EOL;
foreach ($bucket_counts as $bucket => $count) {
    echo '  - ' . $bucket . ': ' . $count . \PHP_EOL;
}
if (empty($failures)) {
    echo 'ALL PASS' . \PHP_EOL;
    exit(0);
}
echo 'FAILURES (' . \count($failures) . '):' . \PHP_EOL;
foreach ($failures as $failure) {
    echo '  - ' . $failure . \PHP_EOL;
}
exit(1);

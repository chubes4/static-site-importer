<?php

namespace BlockFormatBridge\Vendor;

/**
 * Smoke test: core block coverage docs stay aligned with inventory/classification.
 *
 * Run: H2BC_CORE_BLOCKS_DIR=/path/to/wp-includes/blocks php tests/core-block-coverage-docs-smoke.php
 */
// phpcs:disable
$repo_root = \dirname(__DIR__);
$failures = [];
$assertions = 0;
$assert = static function ($condition, $label, $detail = '') use (&$failures, &$assertions) {
    $assertions++;
    if (!$condition) {
        $failures[] = 'FAIL [' . $label . ']' . ('' !== $detail ? ': ' . $detail : '');
    }
};
$read_file = static function (string $path) use ($assert): string {
    global $wp_filesystem;
    $contents = $wp_filesystem->get_contents($path);
    $assert(\is_string($contents) && '' !== $contents, \basename($path) . '-readable', 'Unable to read ' . $path);
    return \is_string($contents) ? $contents : '';
};
$read_json = static function (string $path) use ($read_file, $assert): array {
    $raw = $read_file($path);
    $data = \json_decode($raw, \true);
    $assert(\is_array($data), \basename($path) . '-valid-json', 'Invalid JSON in ' . $path);
    return \is_array($data) ? $data : [];
};
$blocks_dir = \getenv('H2BC_CORE_BLOCKS_DIR');
$has_blocks_dir = \is_string($blocks_dir) && \is_dir($blocks_dir);
$inventory = [];
if ($has_blocks_dir) {
    $command = \escapeshellarg(\PHP_BINARY) . ' ' . \escapeshellarg($repo_root . '/tools/generate-core-block-inventory.php') . ' ' . \escapeshellarg($blocks_dir);
    $output = [];
    $exit = 0;
    \exec($command, $output, $exit);
    $assert(0 === $exit, 'core-block-inventory-generator-runs', \implode("\n", $output));
    $generated_inventory = \json_decode(\implode("\n", $output), \true);
    $assert(\is_array($generated_inventory), 'core-block-inventory-generator-json');
    $inventory = \is_array($generated_inventory) ? $generated_inventory : [];
}
$classification = $read_json($repo_root . '/docs/core-block-classification.json');
$coverage_doc = $read_file($repo_root . '/docs/core-block-coverage.md');
$registry_source = $read_file($repo_root . '/includes/class-transform-registry.php');
$raw_source = $read_file($repo_root . '/raw-handler.php');
$inventory_blocks = [];
foreach ((array) ($inventory['blocks'] ?? []) as $block) {
    $name = $block['name'] ?? '';
    if (\is_string($name) && '' !== $name) {
        $inventory_blocks[$name] = \true;
    }
}
if ($has_blocks_dir) {
    $assert(\count($inventory_blocks) > 50, 'inventory-has-core-block-catalog', 'Expected more than 50 core blocks');
}
$classifications = (array) ($classification['classifications'] ?? []);
$doc_rows = [];
$doc_patterns = [];
foreach (\preg_split("/\r?\n/", $coverage_doc) ? \preg_split("/\r?\n/", $coverage_doc) : [] as $line) {
    $trimmed = \trim($line);
    if (\strpos($trimmed, '|') !== 0 || \strpos($trimmed, '---') !== \false) {
        continue;
    }
    $cells = \array_map('trim', \explode('|', \trim($trimmed, '|')));
    if (\count($cells) < 3) {
        continue;
    }
    $cells = \array_pad($cells, 5, '');
    $row = ['block' => $cells[0], 'status' => \trim((string) $cells[1], '` '), 'signal' => $cells[2], 'test' => $cells[3], 'notes' => $cells[4], 'line' => $trimmed];
    if (\preg_match_all('/`(core\/[a-z0-9-]+\*?)`/', $row['line'], $matches)) {
        foreach ($matches[1] as $pattern) {
            $doc_patterns[] = ['pattern' => $pattern, 'row' => $row];
        }
    }
    $doc_rows[] = $row;
}
$matches_pattern = static function (string $block_name, string $pattern): bool {
    if (\substr($pattern, -1) === '*') {
        return \strpos($block_name, \substr($pattern, 0, -1)) === 0;
    }
    return $block_name === $pattern;
};
$rows_for_block = static function (string $block_name) use ($doc_patterns, $matches_pattern): array {
    $rows = [];
    foreach ($doc_patterns as $entry) {
        if ($matches_pattern($block_name, $entry['pattern'])) {
            $rows[] = $entry['row'];
        }
    }
    return $rows;
};
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
$bucket_counts = [];
foreach ($classifications as $block_name => $entry) {
    $bucket = (string) ($entry['bucket'] ?? '');
    $bucket_counts[$bucket] = ($bucket_counts[$bucket] ?? 0) + 1;
    $rows = $rows_for_block($block_name);
    $assert(!empty($rows), 'coverage-doc-covers-' . $block_name, $bucket);
    if (\in_array($bucket, ['raw-transformable', 'explicit-marker'], \true)) {
        $has_source = isset($raw_transform_blocks[$block_name]) || isset($generated_blocks[$block_name]);
        $assert($has_source, 'transform-source-for-' . $block_name, $bucket);
    }
    if (\in_array($bucket, ['compiler-only', 'dynamic-unsupported'], \true)) {
        $has_rationale = \false;
        foreach ($rows as $row) {
            $text = \strtolower(wp_strip_all_tags($row['signal'] . ' ' . $row['notes']));
            if (\preg_match('/\b(require|requires|depend|depends|intent|context|state|runtime|metadata|query|taxonomy|route|identity|permission)\b/', $text)) {
                $has_rationale = \true;
                break;
            }
        }
        $assert($has_rationale, 'coverage-rationale-for-' . $block_name, $bucket);
    }
    if ('future-candidate' === $bucket) {
        $has_source_signal_note = \false;
        foreach ($rows as $row) {
            $text = \strtolower(wp_strip_all_tags($row['signal'] . ' ' . $row['notes']));
            if (\strpos($text, 'source-signal') !== \false || \strpos($text, 'source signal') !== \false || \strpos($text, 'explicit') !== \false || \strpos($text, 'stable') !== \false) {
                $has_source_signal_note = \true;
                break;
            }
        }
        $assert($has_source_signal_note, 'future-candidate-source-signal-' . $block_name);
    }
}
if ($has_blocks_dir) {
    foreach ($inventory_blocks as $block_name => $_) {
        $assert(!empty($rows_for_block($block_name)), 'inventory-block-documented-' . $block_name);
    }
}
foreach ($doc_patterns as $entry) {
    $status = $entry['row']['status'];
    if (!\in_array($status, ['supported', 'explicit-marker supported'], \true)) {
        continue;
    }
    $matched_blocks = \array_filter(\array_keys($classifications), static function (string $block_name) use ($entry, $matches_pattern): bool {
        return $matches_pattern($block_name, $entry['pattern']);
    });
    $assert(!empty($matched_blocks), 'supported-doc-pattern-matches-classification-' . $entry['pattern']);
    foreach ($matched_blocks as $block_name) {
        $has_source = isset($raw_transform_blocks[$block_name]) || isset($generated_blocks[$block_name]);
        $assert($has_source, 'supported-doc-has-transform-source-' . $block_name, $entry['pattern']);
    }
}
echo 'Assertions: ' . $assertions . \PHP_EOL;
echo 'Inventory blocks: ' . \count($inventory_blocks) . \PHP_EOL;
echo 'Coverage rows: ' . \count($doc_rows) . \PHP_EOL;
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

<?php

namespace BlockFormatBridge\Vendor;

/**
 * Smoke test: core block raw-transform coverage matrix.
 *
 * This keeps the docs and executable transform surface aligned. It does not
 * exercise every attribute path; the family-specific smokes own that. This file
 * is the high-level map: supported block families, observed fallback buckets,
 * future candidates, and context-required blocks that h2bc must not infer from
 * raw HTML alone.
 *
 * Run: php tests/smoke-core-block-transform-matrix.php
 */
// phpcs:disable
$repo_root = \dirname(__DIR__);
$failures = [];
$assertions = 0;
$assert = static function ($condition, $label, $detail = '') use (&$failures, &$assertions) {
    $assertions++;
    if (!$condition) {
        $failures[] = 'FAIL [' . $label . ']' . ($detail !== '' ? ': ' . $detail : '');
    }
};
$assert_contains = static function (string $haystack, string $needle, string $label) use ($assert) {
    $assert(\strpos($haystack, $needle) !== \false, $label, 'Missing ' . $needle);
};
$read_required_file = static function (string $path) use ($assert): string {
    $contents = \file_get_contents($path);
    $assert(\is_string($contents) && $contents !== '', \basename($path) . '-readable', 'Unable to read ' . $path);
    return \is_string($contents) ? $contents : '';
};
$registry_source = $read_required_file($repo_root . '/includes/class-transform-registry.php');
$raw_source = $read_required_file($repo_root . '/raw-handler.php');
$coverage_doc = $read_required_file($repo_root . '/docs/core-block-coverage.md');
$site_editor_doc = $read_required_file($repo_root . '/docs/site-editor-boundary.md');
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
$supported_matrix = ['core/heading' => 'raw-transform', 'core/paragraph' => 'raw-transform', 'core/list' => 'raw-transform', 'core/list-item' => 'generated-inner-block', 'core/quote' => 'raw-transform', 'core/image' => 'raw-transform', 'core/code' => 'raw-transform', 'core/preformatted' => 'raw-transform', 'core/separator' => 'raw-transform', 'core/table' => 'raw-transform', 'core/shortcode' => 'raw-handler-special-case', 'core/group' => 'raw-transform', 'core/columns' => 'raw-transform', 'core/column' => 'raw-transform-and-generated-inner-block', 'core/cover' => 'raw-transform', 'core/spacer' => 'raw-transform', 'core/buttons' => 'raw-transform', 'core/button' => 'generated-inner-block', 'core/details' => 'raw-transform', 'core/pullquote' => 'raw-transform', 'core/verse' => 'raw-transform', 'core/video' => 'raw-transform', 'core/audio' => 'raw-transform', 'core/gallery' => 'raw-transform', 'core/media-text' => 'raw-transform', 'core/file' => 'raw-transform', 'core/embed' => 'raw-transform'];
foreach ($supported_matrix as $block_name => $coverage_kind) {
    $assert_contains($coverage_doc, '`' . $block_name . '`', 'doc-names-supported-' . $block_name);
    if (\strpos($coverage_kind, 'raw-transform') !== \false) {
        $assert(isset($raw_transform_blocks[$block_name]), 'registry-declares-' . $block_name);
    }
    if (\strpos($coverage_kind, 'generated-inner-block') !== \false) {
        $assert(isset($generated_blocks[$block_name]), 'source-generates-' . $block_name);
    }
    if ($coverage_kind === 'raw-handler-special-case') {
        $assert(isset($generated_blocks[$block_name]), 'raw-handler-generates-' . $block_name);
    }
}
$observed_fallbacks = ['core/html', 'Unknown `<iframe>` providers', 'Arbitrary wrappers', 'Ordinary links', 'Navigation markup'];
foreach ($observed_fallbacks as $fallback_label) {
    $assert_contains($coverage_doc, $fallback_label, 'doc-names-fallback-' . $fallback_label);
}
$context_required_blocks = ['core/site-title', 'core/site-logo', 'core/site-tagline', 'core/post-title', 'core/post-content', 'core/post-excerpt', 'core/post-featured-image', 'core/query', 'core/post-template', 'core/comments', 'core/loginout', 'core/search', 'core/calendar', 'core/archives', 'core/categories', 'core/latest-posts', 'core/latest-comments', 'core/rss', 'core/tag-cloud'];
foreach ($context_required_blocks as $block_name) {
    $assert(!isset($raw_transform_blocks[$block_name]), 'no-raw-transform-for-context-required-' . $block_name);
}
foreach (['core/site-title', 'core/post-title', 'core/query', 'core/comments'] as $doc_example) {
    $assert_contains($coverage_doc, '`' . $doc_example, 'coverage-doc-names-context-required-' . $doc_example);
    $assert_contains($site_editor_doc, '`' . $doc_example, 'site-editor-doc-names-context-required-' . $doc_example);
}
foreach (['core/pattern', 'core/template-part'] as $doc_example) {
    $assert_contains($coverage_doc, '`' . $doc_example, 'coverage-doc-names-explicit-marker-' . $doc_example);
    $assert_contains($site_editor_doc, '`' . $doc_example, 'site-editor-doc-names-explicit-marker-' . $doc_example);
}
$assert_contains($coverage_doc, 'Native `core/navigation` blocks', 'coverage-doc-names-navigation-boundary');
$assert_contains($site_editor_doc, 'Native `core/navigation', 'site-editor-doc-names-navigation-boundary');
$assert_contains($coverage_doc, 'Theme And Context Block Classification', 'coverage-doc-classifies-theme-context-blocks');
$assert_contains($site_editor_doc, 'Theme Block Classification', 'site-editor-doc-classifies-theme-blocks');
$future_candidates = ['Additional embed providers', 'Additional static layout patterns', 'Static social links'];
foreach ($future_candidates as $future_candidate) {
    $assert_contains($coverage_doc, $future_candidate, 'doc-names-future-candidate-' . $future_candidate);
}
foreach (['supported', 'fallback-observed', 'context-required', 'explicit-marker supported', 'compiler-only', 'unsupported', 'future candidate'] as $status) {
    $assert_contains($coverage_doc, '`' . $status . '`', 'doc-defines-status-' . $status);
}
echo 'Assertions: ' . $assertions . \PHP_EOL;
if (empty($failures)) {
    echo 'ALL PASS' . \PHP_EOL;
    exit(0);
}
echo 'FAILURES (' . \count($failures) . '):' . \PHP_EOL;
foreach ($failures as $failure) {
    echo '  - ' . $failure . \PHP_EOL;
}
exit(1);

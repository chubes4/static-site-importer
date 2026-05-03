<?php

namespace BlockFormatBridge\Vendor;

/**
 * Smoke test: safe inline SVG icons expose sanitized placeholder metadata.
 *
 * Run: php tests/smoke-inline-svg-icon-classification.php
 */
// phpcs:disable
if (!\defined('ABSPATH')) {
    \define('ABSPATH', __DIR__);
}
if (!\class_exists('WP_HTML_Processor', \false)) {
    $wp_html_api_candidates = \array_filter([\getenv('WP_HTML_API_PATH') ?: '', '/wordpress/wp-includes/html-api', '/Users/chubes/Studio/intelligence-chubes4/wp-includes/html-api']);
    $wp_html_api_path = '';
    foreach ($wp_html_api_candidates as $candidate) {
        if (\is_file(\rtrim($candidate, '/') . '/class-wp-html-processor.php')) {
            $wp_html_api_path = \rtrim($candidate, '/');
            break;
        }
    }
    if ($wp_html_api_path === '') {
        \fwrite(\STDERR, "FAIL: WP_HTML_Processor is unavailable. Set WP_HTML_API_PATH to wp-includes/html-api.\n");
        exit(1);
    }
    foreach (['class-wp-html-attribute-token.php', 'class-wp-html-span.php', 'class-wp-html-text-replacement.php', 'class-wp-html-decoder.php', 'class-wp-html-doctype-info.php', 'class-wp-html-unsupported-exception.php', 'class-wp-html-token.php', 'class-wp-html-tag-processor.php', 'class-wp-html-stack-event.php', 'class-wp-html-open-elements.php', 'class-wp-html-active-formatting-elements.php', 'class-wp-html-processor-state.php', 'class-wp-html-processor.php'] as $file) {
        require_once $wp_html_api_path . '/' . $file;
    }
}
if (!\class_exists('WP_Block_Type_Registry', \false)) {
    class WP_Block_Type_Registry
    {
        public static function get_instance()
        {
            return new self();
        }
        public function is_registered($name)
        {
            return \in_array($name, ['core/html', 'core/paragraph'], \true);
        }
        public function get_registered($name)
        {
            return (object) ['attributes' => []];
        }
    }
    \class_alias('BlockFormatBridge\Vendor\WP_Block_Type_Registry', 'WP_Block_Type_Registry', \false);
}
foreach (['esc_attr', 'esc_html', 'esc_url'] as $function_name) {
    if (!\function_exists($function_name)) {
        eval('function ' . $function_name . '( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, "UTF-8" ); }');
    }
}
if (!\function_exists('BlockFormatBridge\Vendor\wp_strip_all_tags')) {
    function wp_strip_all_tags($text)
    {
        return \strip_tags($text);
    }
}
if (!\function_exists('BlockFormatBridge\Vendor\get_shortcode_regex')) {
    function get_shortcode_regex()
    {
        return '(?!)';
    }
}
$fallback_events = [];
if (!\function_exists('do_action')) {
    function do_action($hook_name, ...$args)
    {
        global $fallback_events;
        if ($hook_name === 'html_to_blocks_unsupported_html_fallback') {
            $fallback_events[] = $args;
        }
    }
}
$repo_root = \dirname(__DIR__);
require_once $repo_root . '/includes/class-block-factory.php';
require_once $repo_root . '/includes/class-attribute-parser.php';
require_once $repo_root . '/includes/class-html-element.php';
require_once $repo_root . '/includes/class-svg-icon-classifier.php';
require_once $repo_root . '/includes/class-transform-registry.php';
require_once $repo_root . '/raw-handler.php';
$failures = [];
$assertions = 0;
$assert = static function ($condition, $label, $detail = '') use (&$failures, &$assertions) {
    $assertions++;
    if (!$condition) {
        $failures[] = 'FAIL [' . $label . ']' . ($detail !== '' ? ': ' . $detail : '');
    }
};
$collect_blocks = static function (array $blocks, string $name) use (&$collect_blocks): array {
    $matches = [];
    foreach ($blocks as $block) {
        if (($block['blockName'] ?? '') === $name) {
            $matches[] = $block;
        }
        if (!empty($block['innerBlocks']) && \is_array($block['innerBlocks'])) {
            $matches = \array_merge($matches, $collect_blocks($block['innerBlocks'], $name));
        }
    }
    return $matches;
};
$safe_svg = '<svg class="icon icon-arrow" viewBox="0 0 24 24" width="24" height="24" role="img" aria-label="Arrow"><title>Arrow</title><path fill="#fff" d="M4 12h14"/><polyline points="14 6 20 12 14 18" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" fill="none"/></svg>';
$safe = HTML_To_Blocks_SVG_Icon_Classifier::classify($safe_svg);
$assert(!empty($safe['is_safe']), 'classifier-accepts-safe-svg', $safe['reason'] ?? '');
$assert(\str_contains($safe['svg'], '<svg'), 'classifier-returns-sanitized-svg', $safe['svg'] ?? '');
$assert(!\str_contains($safe['svg'], 'xmlns'), 'classifier-strips-nonessential-attrs', $safe['svg'] ?? '');
$assert(($safe['metadata']['kind'] ?? '') === 'inline-svg-icon', 'classifier-returns-kind-metadata');
$assert(($safe['metadata']['viewBox'] ?? '') === '0 0 24 24', 'classifier-returns-viewbox-metadata');
$safe_blocks = html_to_blocks_raw_handler(['HTML' => $safe_svg]);
$icons = $collect_blocks($safe_blocks, 'html-to-blocks/svg-icon');
$fallbacks = $collect_blocks($safe_blocks, 'core/html');
$assert(\count($icons) === 1, 'safe-svg-emits-placeholder-block');
$assert(\count($fallbacks) === 0, 'safe-svg-emits-no-core-html');
$assert(\count($fallback_events) === 0, 'safe-svg-emits-no-fallback-diagnostic');
$assert(($icons[0]['attrs']['metadata']['kind'] ?? '') === 'inline-svg-icon', 'placeholder-exposes-metadata');
$assert(\str_contains($icons[0]['attrs']['svg'] ?? '', '<polyline'), 'placeholder-exposes-sanitized-payload');
$unsafe_svg = '<svg viewBox="0 0 24 24"><script>alert(1)</script><path onclick="alert(1)" d="M0 0h24v24H0z"/></svg>';
$unsafe = HTML_To_Blocks_SVG_Icon_Classifier::classify($unsafe_svg);
$unsafe_blocks = html_to_blocks_raw_handler(['HTML' => $unsafe_svg]);
$unsafe_icons = $collect_blocks($unsafe_blocks, 'html-to-blocks/svg-icon');
$unsafe_html = $collect_blocks($unsafe_blocks, 'core/html');
$assert(empty($unsafe['is_safe']), 'classifier-rejects-active-svg', $unsafe['reason'] ?? '');
$assert(\count($unsafe_icons) === 0, 'unsafe-svg-emits-no-placeholder');
$assert(\count($unsafe_html) === 1, 'unsafe-svg-stays-on-fallback-path');
$assert(\count($fallback_events) === 1, 'unsafe-svg-emits-fallback-diagnostic');
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

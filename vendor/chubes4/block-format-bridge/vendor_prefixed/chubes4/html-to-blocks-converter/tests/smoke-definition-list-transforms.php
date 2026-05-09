<?php

namespace BlockFormatBridge\Vendor;

/**
 * Smoke test: simple definition lists become native list blocks.
 *
 * Run: php tests/smoke-definition-list-transforms.php
 */
// phpcs:disable
if (!\defined('ABSPATH')) {
    \define('ABSPATH', __DIR__);
}
if (!\class_exists('WP_HTML_Processor', \false)) {
    $wp_html_api_candidates = \array_filter([\getenv('WP_HTML_API_PATH') ? \getenv('WP_HTML_API_PATH') : '', '/wordpress/wp-includes/html-api', '/Users/chubes/Studio/intelligence-chubes4/wp-includes/html-api']);
    $wp_html_api_path = '';
    foreach ($wp_html_api_candidates as $candidate) {
        if (\is_file(\rtrim($candidate, '/') . '/class-wp-html-processor.php')) {
            $wp_html_api_path = \rtrim($candidate, '/');
            break;
        }
    }
    if ('' === $wp_html_api_path) {
        \fwrite(\STDERR, "FAIL: WP_HTML_Processor is unavailable. Set WP_HTML_API_PATH to wp-includes/html-api.\n");
        exit(1);
    }
    $core_root = \dirname($wp_html_api_path);
    if (\is_file($core_root . '/class-wp-token-map.php')) {
        require_once $core_root . '/class-wp-token-map.php';
    }
    if (\is_file($wp_html_api_path . '/html5-named-character-references.php')) {
        require_once $wp_html_api_path . '/html5-named-character-references.php';
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
            return \in_array($name, ['core/html', 'core/list', 'core/list-item'], \true);
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
        return \trim(\strip_tags((string) $text));
    }
}
if (!\function_exists('BlockFormatBridge\Vendor\get_shortcode_regex')) {
    function get_shortcode_regex()
    {
        return '(?!)';
    }
}
if (!\function_exists('do_action')) {
    function do_action($hook_name, ...$args)
    {
    }
}
$repo_root = \dirname(__DIR__);
require_once $repo_root . '/includes/class-block-factory.php';
require_once $repo_root . '/includes/class-attribute-parser.php';
require_once $repo_root . '/includes/class-html-element.php';
require_once $repo_root . '/includes/class-transform-registry.php';
require_once $repo_root . '/raw-handler.php';
$failures = [];
$assertions = 0;
$assert = static function ($condition, $label, $detail = '') use (&$failures, &$assertions) {
    $assertions++;
    if (!$condition) {
        $failures[] = 'FAIL [' . $label . ']' . ('' !== $detail ? ': ' . $detail : '');
    }
};
$flatten_block_names = static function (array $blocks) use (&$flatten_block_names): array {
    $names = [];
    foreach ($blocks as $block) {
        $names[] = $block['blockName'] ?? '';
        $names = \array_merge($names, $flatten_block_names($block['innerBlocks'] ?? []));
    }
    return $names;
};
$html = <<<'HTML'
<dl><div><dt>Best seller</dt><dd>Brownie Depth Set</dd></div><div><dt>Use case</dt><dd>Glossy tops · dense crumb · deep cocoa</dd></div></dl>
HTML;
$blocks = html_to_blocks_raw_handler(['HTML' => $html]);
$names = $flatten_block_names($blocks);
$assert(\count($blocks) === 1, 'definition-list-produces-single-block');
$assert(($blocks[0]['blockName'] ?? '') === 'core/list', 'definition-list-becomes-list');
$assert(($blocks[0]['attrs']['ordered'] ?? null) === \false, 'definition-list-is-unordered');
$assert(\count($blocks[0]['innerBlocks'] ?? []) === 2, 'definition-list-keeps-pair-count');
$assert(!\in_array('core/html', $names, \true), 'definition-list-has-no-core-html', \implode(', ', $names));
$assert(($blocks[0]['innerBlocks'][0]['attrs']['content'] ?? '') === 'Best seller: Brownie Depth Set', 'definition-list-first-pair-content');
$assert(($blocks[0]['innerBlocks'][1]['attrs']['content'] ?? '') === 'Use case: Glossy tops · dense crumb · deep cocoa', 'definition-list-second-pair-content');
$direct_blocks = html_to_blocks_raw_handler(['HTML' => '<dl><dt>Origin</dt><dd>Charleston</dd></dl>']);
$assert(($direct_blocks[0]['blockName'] ?? '') === 'core/list', 'direct-definition-list-becomes-list');
$assert(($direct_blocks[0]['innerBlocks'][0]['attrs']['content'] ?? '') === 'Origin: Charleston', 'direct-definition-list-content');
$wrapper_stat_blocks = html_to_blocks_raw_handler(['HTML' => '<dl class="hero-stats" aria-label="Store highlights"><div><dt>5</dt><dd>workflow categories</dd></div><div><dt>18+</dt><dd>bench-ready tools</dd></div><div><dt>0</dt><dd>guesswork mornings</dd></div></dl>']);
$assert(\count($wrapper_stat_blocks) === 1, 'wrapped-stat-definition-list-produces-single-block');
$assert(($wrapper_stat_blocks[0]['blockName'] ?? '') === 'core/list', 'wrapped-stat-definition-list-becomes-list');
$assert(($wrapper_stat_blocks[0]['attrs']['className'] ?? '') === 'hero-stats', 'wrapped-stat-definition-list-preserves-class');
$assert(\count($wrapper_stat_blocks[0]['innerBlocks'] ?? []) === 3, 'wrapped-stat-definition-list-keeps-pair-count');
$assert(($wrapper_stat_blocks[0]['innerBlocks'][0]['attrs']['content'] ?? '') === '5: workflow categories', 'wrapped-stat-definition-list-first-content');
$assert(($wrapper_stat_blocks[0]['innerBlocks'][1]['attrs']['content'] ?? '') === '18+: bench-ready tools', 'wrapped-stat-definition-list-second-content');
$assert(($wrapper_stat_blocks[0]['innerBlocks'][2]['attrs']['content'] ?? '') === '0: guesswork mornings', 'wrapped-stat-definition-list-third-content');
$complex_blocks = html_to_blocks_raw_handler(['HTML' => '<dl><div><dt>Term</dt><dd>Description</dd><p>Extra</p></div></dl>']);
$complex_names = $flatten_block_names($complex_blocks);
$assert(\in_array('core/html', $complex_names, \true), 'complex-definition-list-still-falls-back', \implode(', ', $complex_names));
if ($failures) {
    \fwrite(\STDERR, \implode("\n", $failures) . "\n");
    exit(1);
}
\fwrite(\STDOUT, "PASS: {$assertions} definition list assertions\n");

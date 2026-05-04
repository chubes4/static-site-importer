<?php

namespace BlockFormatBridge\Vendor;

/**
 * Smoke test: unsupported HTML fallback observability hook.
 *
 * Run: php tests/smoke-unsupported-html-fallback-hook.php
 */
// phpcs:disable
if (!\defined('ABSPATH')) {
    \define('ABSPATH', __DIR__);
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
            return 'core/html' === $name;
        }
        public function get_registered($name)
        {
            return (object) ['attributes' => ['content' => ['type' => 'string']]];
        }
    }
    \class_alias('BlockFormatBridge\Vendor\WP_Block_Type_Registry', 'WP_Block_Type_Registry', \false);
}
$html_to_blocks_smoke_actions = [];
if (!\function_exists('do_action')) {
    function do_action($hook_name, ...$args)
    {
        global $html_to_blocks_smoke_actions;
        $html_to_blocks_smoke_actions[] = [$hook_name, $args];
    }
}
function html_to_blocks_smoke_action_count(): int
{
    global $html_to_blocks_smoke_actions;
    return \count($html_to_blocks_smoke_actions);
}
function html_to_blocks_smoke_first_action(): ?array
{
    global $html_to_blocks_smoke_actions;
    $action = $html_to_blocks_smoke_actions[0] ?? null;
    return \is_array($action) ? $action : null;
}
require_once \dirname(__DIR__) . '/includes/class-block-factory.php';
require_once \dirname(__DIR__) . '/raw-handler.php';
if (!\function_exists('BlockFormatBridge\Vendor\html_to_blocks_create_unsupported_html_fallback_block')) {
    \fwrite(\STDERR, "FAIL: fallback block helper was not loaded.\n");
    exit(1);
}
$failures = [];
$assertions = 0;
$assert = static function ($condition, $label, $detail = '') use (&$failures, &$assertions) {
    $assertions++;
    if (!$condition) {
        $failures[] = 'FAIL [' . $label . ']' . ('' !== $detail ? ': ' . $detail : '');
    }
};
$read_required_file = static function (string $path) use ($assert): string {
    global $wp_filesystem;
    $contents = $wp_filesystem->get_contents($path);
    $assert(\is_string($contents) && '' !== $contents, \basename($path) . '-readable', 'Unable to read ' . $path);
    return \is_string($contents) ? $contents : '';
};
$fallback_html = '<iframe src="https://example.com/widget"></iframe>';
$context = ['reason' => 'no_transform', 'tag_name' => 'IFRAME', 'occurrence' => 0];
$block = html_to_blocks_create_unsupported_html_fallback_block($fallback_html, $context);
$assert('core/html' === $block['blockName'], 'fallback-block-name');
$assert(($block['attrs']['content'] ?? '') === $fallback_html, 'fallback-preserves-html');
$assert(html_to_blocks_smoke_action_count() === 1, 'fallback-emits-one-action');
$action = html_to_blocks_smoke_first_action();
$assert($action && 'html_to_blocks_unsupported_html_fallback' === $action[0], 'fallback-action-name');
$assert(($action[1][0] ?? '') === $fallback_html, 'fallback-action-html-arg');
$assert(($action[1][1]['reason'] ?? '') === 'no_transform', 'fallback-action-reason');
$assert(($action[1][1]['tag_name'] ?? '') === 'IFRAME', 'fallback-action-tag-name');
$assert(($action[1][2]['blockName'] ?? '') === 'core/html', 'fallback-action-block-arg');
$raw_handler_source = $read_required_file(\dirname(__DIR__) . '/raw-handler.php');
$assert(\substr_count($raw_handler_source, 'html_to_blocks_create_unsupported_html_fallback_block(') >= 3, 'raw-handler-routes-fallbacks-through-helper');
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

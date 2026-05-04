<?php

/**
 * Smoke test for namespace-safe REST filter callback registration.
 *
 * h2bc runs both as a standalone plugin and as a dependency bundled by plugins
 * such as Block Format Bridge. Bundled copies can be php-scoped into a vendor
 * namespace, while WordPress still stores hook callbacks as strings and invokes
 * them later. This smoke guards the pattern that builds those callback strings
 * from __NAMESPACE__ instead of assuming root-global function names.
 *
 * The test simulates php-scoper by injecting a synthetic namespace into
 * includes/hooks.php, then rewrites the WordPress hook APIs to local stubs so we
 * can inspect the callbacks h2bc registers without booting WordPress.
 *
 * Run with: php tests/smoke-scoped-rest-callback.php
 *
 * @package HTML_To_Blocks_Converter\Tests
 */
namespace BlockFormatBridge\Vendor\VendorScoped;

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
$registered_filters = array();
function add_filter(string $hook_name, $callback, int $priority = 10, int $accepted_args = 1): bool
{
    global $registered_filters;
    $registered_filters[] = array($hook_name, $callback, $priority, $accepted_args);
    return \true;
}
function has_filter(string $hook_name, $callback = \false)
{
    global $registered_filters;
    foreach ($registered_filters as $filter) {
        if ($filter[0] === $hook_name && $filter[1] === $callback) {
            return $filter[2];
        }
    }
    return \false;
}
function add_action(string $hook_name, $callback, int $priority = 10, int $accepted_args = 1): bool
{
    return add_filter($hook_name, $callback, $priority, $accepted_args);
}
function has_action(string $hook_name, $callback = \false)
{
    return has_filter($hook_name, $callback);
}
function get_post_types(): array
{
    return array('post' => 'post', 'page' => 'page');
}
function apply_filters(string $hook_name, $value)
{
    unset($hook_name);
    return $value;
}
function wp_unslash($value)
{
    return $value;
}
function wp_slash($value)
{
    return $value;
}
function html_to_blocks_is_supported_type(): bool
{
    return \true;
}
function html_to_blocks_convert_content(string $content): string
{
    return $content;
}
function html_to_blocks_smoke_registered_callbacks(): array
{
    global $registered_filters;
    return array_column($registered_filters, 1);
}
// Load the production hook file as if php-scoper had placed it in this namespace.
$source = $wp_filesystem->get_contents(dirname(__DIR__) . '/includes/hooks.php');
if (!is_string($source) || '' === $source) {
    fwrite(\STDERR, "FAIL: unable to read includes/hooks.php.\n");
    exit(1);
}
$test_namespace = __NAMESPACE__;
$source = preg_replace('/^<\?php\s*(?:namespace\s+[^;]+;\s*)?/', "<?php\nnamespace {$test_namespace};\n", $source, 1);
if (!is_string($source)) {
    fwrite(\STDERR, "FAIL: unable to rewrite includes/hooks.php for scoped smoke.\n");
    exit(1);
}
// Keep WordPress hook calls inside the synthetic namespace so the stubs above
// capture the exact callback strings that would be handed to WordPress.
$source = str_replace(array('\add_filter(', '\has_filter(', '\add_action(', '\has_action(', '\get_post_types(', '\apply_filters('), array('add_filter(', 'has_filter(', 'add_action(', 'has_action(', 'get_post_types(', 'apply_filters('), $source);
$tmp = tempnam(sys_get_temp_dir(), 'h2bc-scoped-hooks-');
$wp_filesystem->put_contents($tmp, $source);
require $tmp;
wp_delete_file($tmp);
$register_rest_filters = __NAMESPACE__ . '\html_to_blocks_register_rest_filters';
// @phpstan-ignore-next-line Dynamic require loads this scoped callback at runtime.
if (!is_callable($register_rest_filters)) {
    fwrite(\STDERR, "FAIL: scoped html_to_blocks_register_rest_filters() was not loaded.\n");
    exit(1);
}
// @phpstan-ignore-next-line Dynamic require loads this scoped callback at runtime.
call_user_func($register_rest_filters);
$callbacks = html_to_blocks_smoke_registered_callbacks();
if (!in_array(__NAMESPACE__ . '\html_to_blocks_convert_rest_response', $callbacks, \true)) {
    fwrite(\STDERR, "FAIL: scoped REST callback was not registered.\n");
    exit(1);
}
if (in_array(null, $callbacks, \true) || in_array('', $callbacks, \true) || in_array(array(), $callbacks, \true)) {
    fwrite(\STDERR, "FAIL: empty REST callback was registered.\n");
    exit(1);
}
echo "PASS: scoped REST callback registration\n";

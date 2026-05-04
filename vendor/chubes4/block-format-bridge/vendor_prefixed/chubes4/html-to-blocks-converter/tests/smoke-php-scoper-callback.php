<?php

/**
 * Smoke test: raw handler callback safety.
 *
 * Catches the regression class where the raw handler callback is passed in a
 * shape that cannot be resolved by call_user_func() or function_exists().
 *
 * Strategy:
 *   1. Static source-content assertions — every callback construction site
 *      that names html_to_blocks_raw_handler uses the literal global function
 *      name expected by this package source.
 *   2. Dynamic equivalence — assert the literal callback resolves via
 *      call_user_func() and function_exists().
 *
 * Run: php tests/smoke-php-scoper-callback.php
 *
 * Exits 0 on pass, 1 on failure. No WordPress required.
 */
// phpcs:disable
namespace BlockFormatBridge\Vendor\HTMLToBlocksConverterSmoke\Synthetic\Vendor;

/**
 * Synthetic stand-in for the real raw handler, declared inside a faux
 * scoped namespace. Mimics what php-scoper produces when h2bc is
 * vendored under BlockFormatBridge\Vendor\.
 */
function html_to_blocks_raw_handler($args)
{
    return array('called_in_namespace' => __NAMESPACE__, 'args' => $args);
}
function current_namespace(): string
{
    return __NAMESPACE__;
}
/**
 * Builds the recursive-handler callable used by raw-handler.php.
 */
function build_callback()
{
    return 'BlockFormatBridge\Vendor\HTMLToBlocksConverterSmoke\Synthetic\Vendor\html_to_blocks_raw_handler';
}
namespace BlockFormatBridge\Vendor;

$failures = array();
$assertions = 0;
$smoke_assert = function ($condition, $label, $detail = '') use (&$failures, &$assertions) {
    $assertions++;
    if (!$condition) {
        $failures[] = "FAIL [{$label}]" . ('' !== $detail ? ": {$detail}" : '');
    }
};
$read_required_file = static function (string $path) use ($smoke_assert): string {
    global $wp_filesystem;
    $contents = $wp_filesystem->get_contents($path);
    $smoke_assert(\is_string($contents) && '' !== $contents, \basename($path) . '-readable', "Unable to read {$path}");
    return \is_string($contents) ? $contents : '';
};
// -----------------------------------------------------------------------
// 1. Static source assertions
// -----------------------------------------------------------------------
$repo_root = \dirname(__DIR__);
$raw_handler_source = $read_required_file($repo_root . '/raw-handler.php');
$library_source = $read_required_file($repo_root . '/library.php');
// The callback must be built once, not passed as an inline callback literal.
$smoke_assert(\strpos($raw_handler_source, "call_user_func( \$transform_fn, \$element, 'BlockFormatBridge\Vendor\html_to_blocks_raw_handler' )") === \false, 'raw-handler-no-string-literal-callback', 'raw-handler.php passes the raw handler callback inline instead of through the wrapper closure');
// Source is global, so the raw handler callback is the literal global function name.
$smoke_assert(\strpos($raw_handler_source, "\$raw_handler_fn       = 'BlockFormatBridge\Vendor\html_to_blocks_raw_handler';") !== \false, 'raw-handler-uses-global-callback', 'raw-handler.php must build the recursive handler callback from the global function name');
// library.php must use the same literal for its function_exists guard.
$smoke_assert(\strpos($library_source, "\$html_to_blocks_raw_handler_callback = 'BlockFormatBridge\Vendor\html_to_blocks_raw_handler';") !== \false, 'library-uses-global-function-exists-callback', 'library.php must guard the require_once via the global function name');
$smoke_assert(\strpos($library_source, 'function_exists( $html_to_blocks_raw_handler_callback )') !== \false, 'library-uses-variable-function-exists', 'library.php must use the computed callback for function_exists');
// -----------------------------------------------------------------------
// 2. Dynamic equivalence inside a synthetic namespace
// -----------------------------------------------------------------------
$scoped_callable = \BlockFormatBridge\Vendor\HTMLToBlocksConverterSmoke\Synthetic\Vendor\build_callback();
$smoke_assert('HTMLToBlocksConverterSmoke\Synthetic\Vendor\html_to_blocks_raw_handler' === $scoped_callable, 'scoped-callable-string-shape', "got: {$scoped_callable}");
$smoke_assert(\is_callable($scoped_callable), 'scoped-callable-resolves', "php cannot resolve {$scoped_callable} to a function");
$smoke_assert(\function_exists($scoped_callable), 'scoped-function-exists', "function_exists() rejects {$scoped_callable}");
$invoke_result = \call_user_func($scoped_callable, array('HTML' => '<p>x</p>'));
$smoke_assert(\is_array($invoke_result) && isset($invoke_result['called_in_namespace']) && 'HTMLToBlocksConverterSmoke\Synthetic\Vendor' === $invoke_result['called_in_namespace'], 'scoped-callable-invokes-namespaced-function', 'invocation did not land in the synthetic namespace');
// And in the unscoped path: the same expression evaluated in the global
// namespace (where __NAMESPACE__ === '') must collapse to the bare name.
function html_to_blocks_raw_handler_global_smoke($args)
{
    return array('called_in_namespace' => '', 'args' => $args);
}
function html_to_blocks_smoke_current_namespace(): string
{
    // @phpstan-ignore-next-line The smoke intentionally exercises the global-namespace branch.
    return __NAMESPACE__;
}
$global_namespace = html_to_blocks_smoke_current_namespace();
$unscoped_callable = '' !== $global_namespace ? $global_namespace . '\html_to_blocks_raw_handler_global_smoke' : 'html_to_blocks_raw_handler_global_smoke';
$smoke_assert('html_to_blocks_raw_handler_global_smoke' === $unscoped_callable, 'unscoped-callable-string-shape', "got: {$unscoped_callable}");
$smoke_assert(\is_callable($unscoped_callable), 'unscoped-callable-resolves', "global function not resolvable: {$unscoped_callable}");
// -----------------------------------------------------------------------
// Report
// -----------------------------------------------------------------------
echo "Assertions: {$assertions}" . \PHP_EOL;
if (empty($failures)) {
    echo 'ALL PASS' . \PHP_EOL;
    exit(0);
}
echo 'FAILURES (' . \count($failures) . '):' . \PHP_EOL;
foreach ($failures as $f) {
    echo "  - {$f}" . \PHP_EOL;
}
exit(1);

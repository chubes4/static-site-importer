<?php

/**
 * Smoke test: php-scoper callback safety.
 *
 * Catches the regression class where string-literal function-name callbacks
 * do not survive php-scoper's namespace rewriting, leading to fatals like
 * "Call to undefined function html_to_blocks_raw_handler()" when the library
 * is consumed via a vendor_prefixed/ build (e.g. Block Format Bridge).
 *
 * Strategy:
 *   1. Static source-content assertions — every callback construction site
 *      that names html_to_blocks_raw_handler MUST gate the resolution on
 *      __NAMESPACE__ so the same source compiles correctly in both the
 *      unscoped global namespace and a scoped namespace.
 *   2. Dynamic equivalence — declare functions inside a synthetic namespace
 *      (mimicking what php-scoper does) and assert the __NAMESPACE__-derived
 *      callable resolves to that namespaced function via call_user_func.
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
 * Builds the recursive-handler callable using the same
 * __NAMESPACE__-aware expression that lives in raw-handler.php and
 * library.php. Must yield a string that resolves correctly inside this
 * namespace.
 */
function build_callback()
{
    $namespace = current_namespace();
    return $namespace !== '' ? $namespace . '\html_to_blocks_raw_handler' : 'html_to_blocks_raw_handler';
}
namespace BlockFormatBridge\Vendor;

$failures = array();
$assertions = 0;
$smoke_assert = function ($condition, $label, $detail = '') use (&$failures, &$assertions) {
    $assertions++;
    if (!$condition) {
        $failures[] = "FAIL [{$label}]" . ($detail !== '' ? ": {$detail}" : '');
    }
};
$read_required_file = static function (string $path) use ($smoke_assert): string {
    $contents = \file_get_contents($path);
    $smoke_assert(\is_string($contents) && $contents !== '', \basename($path) . '-readable', "Unable to read {$path}");
    return \is_string($contents) ? $contents : '';
};
// -----------------------------------------------------------------------
// 1. Static source assertions
// -----------------------------------------------------------------------
$repo_root = \dirname(__DIR__);
$raw_handler_source = $read_required_file($repo_root . '/raw-handler.php');
$library_source = $read_required_file($repo_root . '/library.php');
// The bare string literal must not appear as a callback argument to
// call_user_func. Docblocks/comments are fine; we only fail on the exact
// dangerous shape.
$smoke_assert(\strpos($raw_handler_source, "call_user_func( \$transform_fn, \$element, 'html_to_blocks_raw_handler' )") === \false, 'raw-handler-no-string-literal-callback', 'raw-handler.php still passes the bare string "html_to_blocks_raw_handler" into call_user_func; will fatal under php-scoper');
// And it must use __NAMESPACE__ to build the callable.
$smoke_assert(\preg_match('/__NAMESPACE__\s*\?\s*__NAMESPACE__\s*\.\s*[\'"]\\\\\\\\html_to_blocks_raw_handler[\'"]\s*:\s*[\'"]html_to_blocks_raw_handler[\'"]/', $raw_handler_source) === 1, 'raw-handler-uses-namespace-aware-callback', 'raw-handler.php must build the recursive handler callback via __NAMESPACE__');
// library.php must use the same pattern for its function_exists guard.
$smoke_assert(\strpos($library_source, "function_exists( 'html_to_blocks_raw_handler' )") === \false, 'library-no-string-literal-function-exists', 'library.php still calls function_exists with bare string; will mis-guard under scoping');
$smoke_assert(\preg_match('/__NAMESPACE__\s*\?\s*__NAMESPACE__\s*\.\s*[\'"]\\\\\\\\html_to_blocks_raw_handler[\'"]\s*:\s*[\'"]html_to_blocks_raw_handler[\'"]/', $library_source) === 1, 'library-uses-namespace-aware-function-exists', 'library.php must guard the require_once via a __NAMESPACE__-derived callable');
// -----------------------------------------------------------------------
// 2. Dynamic equivalence inside a synthetic namespace
// -----------------------------------------------------------------------
$scoped_callable = \BlockFormatBridge\Vendor\HTMLToBlocksConverterSmoke\Synthetic\Vendor\build_callback();
$smoke_assert($scoped_callable === 'HTMLToBlocksConverterSmoke\Synthetic\Vendor\html_to_blocks_raw_handler', 'scoped-callable-string-shape', "got: {$scoped_callable}");
$smoke_assert(\is_callable($scoped_callable), 'scoped-callable-resolves', "php cannot resolve {$scoped_callable} to a function");
$smoke_assert(\function_exists($scoped_callable), 'scoped-function-exists', "function_exists() rejects {$scoped_callable}");
$invoke_result = \call_user_func($scoped_callable, array('HTML' => '<p>x</p>'));
$smoke_assert(\is_array($invoke_result) && isset($invoke_result['called_in_namespace']) && $invoke_result['called_in_namespace'] === 'HTMLToBlocksConverterSmoke\Synthetic\Vendor', 'scoped-callable-invokes-namespaced-function', 'invocation did not land in the synthetic namespace');
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
$unscoped_callable = $global_namespace !== '' ? $global_namespace . '\html_to_blocks_raw_handler_global_smoke' : 'html_to_blocks_raw_handler_global_smoke';
$smoke_assert($unscoped_callable === 'html_to_blocks_raw_handler_global_smoke', 'unscoped-callable-string-shape', "got: {$unscoped_callable}");
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

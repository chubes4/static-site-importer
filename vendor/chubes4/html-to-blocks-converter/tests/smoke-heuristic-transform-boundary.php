<?php
/**
 * Smoke test: heuristic transform boundary remains generic.
 *
 * Run: php tests/smoke-heuristic-transform-boundary.php
 */

// phpcs:disable

$repo_root  = dirname( __DIR__ );
$failures   = [];
$assertions = 0;

$assert = static function ( $condition, $label, $detail = '' ) use ( &$failures, &$assertions ) {
	$assertions++;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
	}
};

$read_required_file = static function ( string $path ) use ( $assert ): string {
	$contents = file_get_contents( $path );
	$assert( is_string( $contents ) && '' !== $contents, basename( $path ) . '-readable', 'Unable to read ' . $path );

	return is_string( $contents ) ? $contents : '';
};

$registry_source = $read_required_file( $repo_root . '/includes/class-transform-registry.php' );
$boundary_doc    = $read_required_file( $repo_root . '/docs/site-editor-boundary.md' );
$normalized_doc  = preg_replace( '/\s+/', ' ', $boundary_doc );

foreach ( [ 'Navigation, WooCommerce identity, query/post/site-title/template intent stay compiler-only.', 'Exact brand, site, product, or fixture names belong in tests and docs, not production transform rules.' ] as $required_rule ) {
	$assert( strpos( $normalized_doc, $required_rule ) !== false, 'boundary-doc-rule-' . substr( md5( $required_rule ), 0, 8 ) );
}

$forbidden_production_literals = [
	'Ember',
	'Rye',
	'Loom',
	'Larder',
	'Extra Chill',
	'extrachill',
	'Sourdough',
];

foreach ( $forbidden_production_literals as $literal ) {
	$assert( stripos( $registry_source, $literal ) === false, 'registry-omits-site-literal-' . strtolower( preg_replace( '/[^a-z0-9]+/i', '-', $literal ) ) );
}

$assert( strpos( $registry_source, 'has_explicit_commerce_context' ) !== false, 'commerce-heuristics-remain-context-gated' );
$assert( strpos( $registry_source, 'core/navigation' ) === false, 'registry-does-not-emit-navigation-blocks' );
$assert( strpos( $registry_source, 'core/site-title' ) === false, 'registry-does-not-emit-site-title-blocks' );
$assert( strpos( $registry_source, 'core/query' ) === false, 'registry-does-not-emit-query-blocks' );

echo 'Assertions: ' . $assertions . PHP_EOL;
if ( empty( $failures ) ) {
	echo 'ALL PASS' . PHP_EOL;
	exit( 0 );
}

echo 'FAILURES (' . count( $failures ) . '):' . PHP_EOL;
foreach ( $failures as $failure ) {
	echo '  - ' . $failure . PHP_EOL;
}
exit( 1 );

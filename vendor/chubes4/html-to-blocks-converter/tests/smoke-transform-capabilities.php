<?php
/**
 * Smoke test: public transform capability inventory.
 *
 * Run: php tests/smoke-transform-capabilities.php
 */

// phpcs:disable

define( 'ABSPATH', __DIR__ );

require_once dirname( __DIR__ ) . '/includes/class-block-factory.php';
require_once dirname( __DIR__ ) . '/includes/transform-families/class-site-editor-marker-transforms.php';
require_once dirname( __DIR__ ) . '/includes/class-transform-registry.php';
require_once dirname( __DIR__ ) . '/includes/capabilities.php';

$failures   = [];
$assertions = 0;

$assert = static function ( $condition, $label, $detail = '' ) use ( &$failures, &$assertions ) {
	$assertions++;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
	}
};

$capabilities = html_to_blocks_get_capabilities();
$blocks       = array_fill_keys( $capabilities['transforms']['supported_core_blocks'] ?? [], true );
$families     = $capabilities['transforms']['families'] ?? [];
$family_slugs = [];

foreach ( $families as $family ) {
	$family_slugs[ $family['slug'] ?? '' ] = true;
	$assert( ! empty( $family['transform_count'] ), 'family-has-transform-count-' . ( $family['slug'] ?? 'unknown' ) );
}

$assert( isset( $capabilities['version'] ) && is_string( $capabilities['version'] ), 'capabilities-include-version' );
$assert( 'html_to_blocks_raw_handler' === ( $capabilities['raw_handler']['function'] ?? null ), 'capabilities-name-raw-handler' );
$assert( isset( $family_slugs['site-editor-markers'] ), 'capabilities-include-site-editor-family' );
$assert( isset( $family_slugs['layout'] ), 'capabilities-include-layout-family' );
$assert( isset( $family_slugs['paragraph-text'] ), 'capabilities-include-paragraph-family' );

foreach ( [ 'core/paragraph', 'core/heading', 'core/list', 'core/image', 'core/group', 'core/pattern', 'core/template-part' ] as $block_name ) {
	$assert( isset( $blocks[ $block_name ] ), 'capabilities-include-' . $block_name );
}

$marker_attributes = $capabilities['transforms']['explicit_markers'] ?? [];
$assert( in_array( 'data-h2bc-pattern', $marker_attributes['pattern'] ?? [], true ), 'capabilities-include-generic-pattern-marker' );
$assert( in_array( 'data-bfb-pattern', $marker_attributes['pattern'] ?? [], true ), 'capabilities-include-bfb-pattern-alias' );
$assert( in_array( 'data-h2bc-template-part', $marker_attributes['template_part'] ?? [], true ), 'capabilities-include-generic-template-part-marker' );
$assert( in_array( 'data-bfb-template-part', $marker_attributes['template_part'] ?? [], true ), 'capabilities-include-bfb-template-part-alias' );
$assert( 'html_to_blocks_unsupported_html_fallback' === ( $capabilities['hooks']['unsupported_html_fallback'] ?? null ), 'capabilities-include-fallback-hook' );

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

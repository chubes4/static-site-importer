<?php
/**
 * Smoke test: safe inline SVG icons materialize as theme assets; unsafe SVG is reported.
 *
 * Run inside a WordPress site with BFB active:
 * wp eval-file tests/smoke-inline-svg-icons.php
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$plugin_root = dirname( __DIR__ );

if ( ! defined( 'STATIC_SITE_IMPORTER_PATH' ) && is_readable( $plugin_root . '/static-site-importer.php' ) ) {
	require_once $plugin_root . '/static-site-importer.php';
}
if ( ! class_exists( 'Static_Site_Importer_Document', false ) ) {
	require_once $plugin_root . '/includes/class-static-site-importer-document.php';
}
if ( ! class_exists( 'Static_Site_Importer_Theme_Generator', false ) ) {
	require_once $plugin_root . '/includes/class-static-site-importer-theme-generator.php';
}

$assertions = 0;
$failures   = array();

$assert = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
	++$assertions;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
	}
};

$read = static function ( string $path ): string {
	$contents = file_get_contents( $path );
	return false === $contents ? '' : $contents;
};

$pattern_blocks = static function ( string $pattern_file ): string {
	$parts = explode( '?>', $pattern_file, 2 );
	return trim( 2 === count( $parts ) ? $parts[1] : $pattern_file );
};

$write_fixture = static function ( string $filename, string $html ): string {
	$dir = trailingslashit( get_temp_dir() ) . 'static-site-importer-svg-' . wp_generate_uuid4();
	wp_mkdir_p( $dir );
	$path = trailingslashit( $dir ) . $filename;
	file_put_contents( $path, $html );
	return $path;
};

$safe_path = $write_fixture(
	'safe-svg-icons.html',
	'<!doctype html><html><head><title>Safe SVG Icons</title></head><body><main><section class="icons"><h1>Icons</h1><svg class="icon icon-bolt" viewBox="0 0 24 24" width="24" height="24" role="img" aria-label="Bolt"><title>Bolt</title><path d="M13 2 3 14h8l-1 8 11-13h-8z" fill="currentColor"/></svg></section></main></body></html>'
);

$safe_result = Static_Site_Importer_Theme_Generator::import_theme(
	$safe_path,
	array(
		'name'      => 'Safe SVG Icons',
		'slug'      => 'safe-svg-icons-smoke',
		'overwrite' => true,
		'activate'  => false,
	)
);

$assert( ! is_wp_error( $safe_result ), 'safe-import-succeeds', is_wp_error( $safe_result ) ? $safe_result->get_error_message() : '' );
if ( ! is_wp_error( $safe_result ) ) {
	$theme_dir = $safe_result['theme_dir'];
	$pattern   = $pattern_blocks( $read( $theme_dir . '/patterns/page-safe-svg-icons.php' ) );
	$report    = json_decode( $read( $safe_result['report_path'] ), true );
	$asset     = $report['assets']['svg_icons'][0] ?? array();

	$assert( str_contains( $pattern, '<!-- wp:image ' ), 'safe-svg-renders-core-image' );
	$assert( ! str_contains( $pattern, '<!-- wp:html' ), 'safe-svg-does-not-render-core-html' );
	$assert( str_contains( $pattern, '/assets/icons/' ), 'safe-svg-references-theme-asset' );
	$assert( 0 === ( $report['quality']['fallback_count'] ?? null ), 'safe-svg-has-zero-fallbacks' );
	$assert( 0 === ( $report['quality']['unsafe_svg_count'] ?? null ), 'safe-svg-has-zero-unsafe-svg-count' );
	$assert( 'core/image' === ( $asset['block'] ?? '' ), 'safe-svg-report-records-native-block' );
	$assert( isset( $asset['path'] ) && is_readable( $theme_dir . '/' . $asset['path'] ), 'safe-svg-asset-written' );
}

$unsafe_path = $write_fixture(
	'unsafe-svg-icons.html',
	'<!doctype html><html><head><title>Unsafe SVG Icons</title></head><body><main><section><h1>Unsafe</h1><svg viewBox="0 0 24 24"><script>alert(1)</script><path d="M0 0h24v24H0z"/></svg></section></main></body></html>'
);

$unsafe_result = Static_Site_Importer_Theme_Generator::import_theme(
	$unsafe_path,
	array(
		'name'      => 'Unsafe SVG Icons',
		'slug'      => 'unsafe-svg-icons-smoke',
		'overwrite' => true,
		'activate'  => false,
	)
);

$assert( ! is_wp_error( $unsafe_result ), 'unsafe-import-succeeds', is_wp_error( $unsafe_result ) ? $unsafe_result->get_error_message() : '' );
if ( ! is_wp_error( $unsafe_result ) ) {
	$report      = json_decode( $read( $unsafe_result['report_path'] ), true );
	$diagnostics = array_filter(
		$report['diagnostics'] ?? array(),
		static fn ( array $diagnostic ): bool => 'unsafe_inline_svg' === ( $diagnostic['type'] ?? '' )
	);

	$assert( 1 === ( $report['quality']['unsafe_svg_count'] ?? null ), 'unsafe-svg-counts-as-quality-failure' );
	$assert( in_array( 'unsafe_inline_svg', $report['quality']['failure_reasons'] ?? array(), true ), 'unsafe-svg-adds-failure-reason' );
	$assert( ! empty( $diagnostics ), 'unsafe-svg-emits-diagnostic' );
}

if ( ! empty( $failures ) ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo 'OK: inline SVG icon smoke passed (' . $assertions . " assertions)\n";

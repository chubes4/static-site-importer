<?php
/**
 * Smoke test: simple extracted header/footer chrome fragments use native blocks.
 *
 * Run inside a WordPress site with BFB active:
 * wp eval-file tests/smoke-extracted-chrome-fragments.php
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
	global $wp_filesystem;
	$contents = $wp_filesystem->get_contents( $path );
	return false === $contents ? '' : $contents;
};

$dir = trailingslashit( get_temp_dir() ) . 'static-site-importer-chrome-' . wp_generate_uuid4();
wp_mkdir_p( $dir );

$fixture = trailingslashit( $dir ) . 'index.html';
file_put_contents(
	$fixture,
	'<!doctype html><html><head><title>Event Conference</title></head><body>' .
	'<header class="site-header"><div class="header-shell"><a class="nav-logo" href="/"><div class="logo-mark">EC</div><div class="logo-text">EventConf</div></a><nav class="main-nav"><a href="/schedule/">Schedule</a><a href="/speakers/">Speakers</a></nav></div><div class="hero-meta"><div class="meta-label">When</div><div class="meta-value">June 12</div></div></header>' .
	'<main><section class="hero"><h1>Event Conference</h1><p>Future-facing talks.</p></section></main>' .
	'<footer class="site-footer"><div class="footer-shell"><div class="footer-row"><div class="footer-brand">EventConf 2026</div><ul class="links"><li><a href="/privacy/">Privacy</a></li></ul></div></div></footer>' .
	'</body></html>'
);

$result = Static_Site_Importer_Theme_Generator::import_theme(
	$fixture,
	array(
		'name'      => 'Event Conference Chrome',
		'slug'      => 'event-conference-chrome-smoke',
		'overwrite' => true,
		'activate'  => false,
	)
);

$assert( ! is_wp_error( $result ), 'import-succeeds', is_wp_error( $result ) ? $result->get_error_message() : '' );

if ( ! is_wp_error( $result ) ) {
	$theme_dir = $result['theme_dir'];
	$header    = $read( $theme_dir . '/parts/header.html' );
	$footer    = $read( $theme_dir . '/parts/footer.html' );
	$report    = json_decode( $read( $result['report_path'] ), true );
	$chrome    = $header . $footer;
	$documents = array();
	foreach ( $report['generated_theme']['block_documents'] ?? array() as $document ) {
		if ( is_array( $document ) && isset( $document['path'] ) ) {
			$documents[ $document['path'] ] = $document;
		}
	}

	$assert( str_contains( $header, '<!-- wp:navigation ' ), 'header-navigation-uses-native-block' );
	$assert( str_contains( $header, '<!-- wp:paragraph --><p><a class="nav-logo"' ), 'logo-anchor-uses-native-paragraph' );
	$assert( str_contains( $header, '<!-- wp:paragraph {"className":"meta-label"}' ), 'meta-label-uses-native-paragraph' );
	$assert( str_contains( $header, '<!-- wp:paragraph {"className":"meta-value"}' ), 'meta-value-uses-native-paragraph' );
	$assert( str_contains( $footer, '<!-- wp:paragraph {"className":"footer-brand"}' ), 'footer-brand-uses-native-paragraph' );
	$assert( ! str_contains( $chrome, '<!-- wp:freeform' ), 'chrome-has-no-freeform-blocks' );
	$assert( ! str_contains( $chrome, '<!-- wp:html' ), 'chrome-has-no-core-html-blocks' );
	$assert( 0 === ( $documents['parts/header.html']['freeform_block_count'] ?? null ), 'report-header-has-zero-freeform' );
	$assert( 0 === ( $documents['parts/header.html']['core_html_block_count'] ?? null ), 'report-header-has-zero-core-html' );
	$assert( 0 === ( $documents['parts/footer.html']['freeform_block_count'] ?? null ), 'report-footer-has-zero-freeform' );
	$assert( 0 === ( $documents['parts/footer.html']['core_html_block_count'] ?? null ), 'report-footer-has-zero-core-html' );
}

if ( ! empty( $failures ) ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo 'OK: extracted chrome fragments smoke passed (' . $assertions . " assertions)\n";

<?php
/**
 * Smoke test: simple branded inline chrome text converts to native paragraph blocks.
 *
 * Locks in coverage for the GitHub issue #132 examples that were emitting
 * `wp:freeform` instead of editable native blocks:
 *
 * - `<div class="nav-logo">Field Notes Live '26</div>`
 * - `<div class="footer-logo">Field Notes <em>Live</em></div>`
 * - `<div class="footer-copy">© 2026 Field Notes Live. All rights reserved.</div>`
 *
 * Each must become a native `wp:paragraph` block that preserves the source
 * class name and (for the footer logo) inline `<em>` emphasis.
 *
 * Run inside a WordPress site with BFB active:
 * wp eval-file tests/smoke-branded-inline-chrome.php
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

$dir = trailingslashit( get_temp_dir() ) . 'static-site-importer-branded-chrome-' . wp_generate_uuid4();
wp_mkdir_p( $dir );

$fixture = trailingslashit( $dir ) . 'index.html';
file_put_contents(
	$fixture,
	'<!doctype html><html><head><title>Field Notes Live</title></head><body>' .
	'<nav class="site-nav">' .
		'<div class="nav-logo">Field Notes Live \'26</div>' .
		'<ul class="nav-links"><li><a href="#theme">Theme</a></li><li><a href="#speakers">Speakers</a></li></ul>' .
	'</nav>' .
	'<main><section class="hero"><h1>Field Notes Live</h1><p>Bend, Oregon.</p></section></main>' .
	'<footer class="site-footer">' .
		'<div class="footer-logo">Field Notes <em>Live</em></div>' .
		'<p class="footer-tagline">Bend, Oregon &mdash; September 18&ndash;19, 2026</p>' .
		'<div class="footer-copy">&copy; 2026 Field Notes Live. All rights reserved.</div>' .
	'</footer>' .
	'</body></html>'
);

$result = Static_Site_Importer_Theme_Generator::import_theme(
	$fixture,
	array(
		'name'      => 'Field Notes Live Branded Chrome',
		'slug'      => 'field-notes-live-branded-chrome-smoke',
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

	// Issue #132 example 1: <div class="nav-logo">Field Notes Live '26</div>.
	$assert(
		str_contains( $header, '<!-- wp:paragraph {"className":"nav-logo"} --><p class="nav-logo">Field Notes Live \'26</p>' ),
		'nav-logo-uses-native-paragraph-with-class'
	);

	// Issue #132 example 2: <div class="footer-logo">Field Notes <em>Live</em></div>.
	$assert(
		str_contains( $footer, '<!-- wp:paragraph {"className":"footer-logo"} --><p class="footer-logo">Field Notes <em>Live</em></p>' ),
		'footer-logo-uses-native-paragraph-with-inline-em'
	);

	// Issue #132 example 3: <div class="footer-copy">&copy; 2026 ...</div>.
	$assert(
		str_contains( $footer, '<!-- wp:paragraph {"className":"footer-copy"}' ) && str_contains( $footer, '<p class="footer-copy">' ),
		'footer-copy-uses-native-paragraph-with-class'
	);
	$assert(
		( false !== strpos( $footer, "\xC2\xA9 2026 Field Notes Live" ) ) || str_contains( $footer, '&copy; 2026 Field Notes Live' ),
		'footer-copy-preserves-text-content'
	);

	$assert( ! str_contains( $chrome, '<!-- wp:freeform' ), 'branded-chrome-has-no-freeform-blocks' );
	$assert( ! str_contains( $chrome, '<!-- wp:html' ), 'branded-chrome-has-no-core-html-blocks' );
	$assert( 0 === ( $documents['parts/header.html']['freeform_block_count'] ?? null ), 'report-header-zero-freeform' );
	$assert( 0 === ( $documents['parts/footer.html']['freeform_block_count'] ?? null ), 'report-footer-zero-freeform' );
	$assert( 0 === ( $documents['parts/header.html']['core_html_block_count'] ?? null ), 'report-header-zero-core-html' );
	$assert( 0 === ( $documents['parts/footer.html']['core_html_block_count'] ?? null ), 'report-footer-zero-core-html' );
}

if ( ! empty( $failures ) ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo 'OK: branded inline chrome smoke passed (' . $assertions . " assertions)\n";

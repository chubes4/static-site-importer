<?php
/**
 * Smoke test: generated editor styles show JS-revealed animation content.
 *
 * Run inside a WordPress site with BFB active:
 * wp eval-file tests/smoke-editor-reveal-animation-support.php
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$plugin_root = dirname( __DIR__ );
if ( ! class_exists( 'Static_Site_Importer_Document', false ) ) {
	require_once $plugin_root . '/includes/class-static-site-importer-document.php';
}
if ( ! class_exists( 'Static_Site_Importer_Woo_Product_Seeder', false ) ) {
	require_once $plugin_root . '/includes/class-static-site-importer-woo-product-seeder.php';
}
if ( ! class_exists( 'Static_Site_Importer_Theme_Generator', false ) ) {
	require_once $plugin_root . '/includes/class-static-site-importer-theme-generator.php';
}

$assertions = 0;
$failures   = array();

$assert = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
	$assertions++;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
	}
};

$read = static function ( string $path ): string {
	$contents = file_get_contents( $path );
	return false === $contents ? '' : $contents;
};

$fixture = trailingslashit( get_temp_dir() ) . 'static-site-importer-editor-reveal-animation.html';
file_put_contents(
	$fixture,
	'<!doctype html><html><head><title>Editor Reveal Animation</title><style>' .
	'.hero-eyebrow { opacity: 0; transform: translateY(12px); }' .
	'.hero-title, .hero-tagline { opacity: 0 !important; transform: translateY(20px); }' .
	'@media (min-width: 800px) { .hero-meta { opacity: 0; transform: translateX(-16px); } }' .
	'.visually-hidden { opacity: 0; }' .
	'.spinner { opacity: 0; transform: none; }' .
	'</style></head><body><main><section class="hero"><p class="hero-eyebrow">Field Notes Live</p>' .
	'<h1 class="hero-title">Conference content should be editable</h1><p class="hero-tagline">Frontend JavaScript reveals this copy.</p>' .
	'<p class="hero-meta">May 2026</p><span class="visually-hidden">Screen reader label</span><span class="spinner">Loading</span>' .
	'</section></main><script>document.querySelectorAll(\'.hero-eyebrow,.hero-title,.hero-tagline,.hero-meta\').forEach(function(el){el.style.opacity=\'1\';el.style.transform=\'translateY(0)\';});</script></body></html>'
);

$result = Static_Site_Importer_Theme_Generator::import_theme(
	$fixture,
	array(
		'name'      => 'Editor Reveal Animation',
		'slug'      => 'editor-reveal-animation',
		'overwrite' => true,
		'activate'  => false,
	)
);

$assert( ! is_wp_error( $result ), 'import-succeeds', is_wp_error( $result ) ? $result->get_error_message() : '' );

if ( ! is_wp_error( $result ) ) {
	$style        = $read( $result['theme_dir'] . '/style.css' );
	$editor_style = $read( $result['theme_dir'] . '/assets/css/editor-style.css' );

	$assert( str_contains( $style, '.hero-eyebrow { opacity: 0; transform: translateY(12px);' ), 'frontend-source-opacity-transform-preserved' );
	$assert( ! str_contains( $style, 'Static Site Importer: show JS-revealed animation content in editor canvases.' ), 'frontend-style-does-not-include-editor-reveal-bridge' );
	$assert( str_contains( $editor_style, 'Static Site Importer: show JS-revealed animation content in editor canvases.' ), 'editor-reveal-bridge-comment-present' );
	$assert( str_contains( $editor_style, '.editor-styles-wrapper .hero-eyebrow' ), 'editor-reveals-eyebrow' );
	$assert( str_contains( $editor_style, '.editor-styles-wrapper .hero-title' ), 'editor-reveals-title' );
	$assert( str_contains( $editor_style, '.editor-styles-wrapper .hero-tagline' ), 'editor-reveals-tagline' );
	$assert( str_contains( $editor_style, '.editor-styles-wrapper .hero-meta' ), 'editor-reveals-media-query-target' );
	$assert( str_contains( $editor_style, 'opacity: 1 !important; transform: none !important;' ), 'editor-neutralizes-hidden-transform-start' );
	$assert( ! str_contains( $editor_style, '.editor-styles-wrapper .visually-hidden' ), 'opacity-only-hidden-class-not-neutralized' );
	$assert( ! str_contains( $editor_style, '.editor-styles-wrapper .spinner' ), 'transform-none-hidden-class-not-neutralized' );
}

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo 'OK: editor reveal animation smoke passed (' . $assertions . " assertions)\n";

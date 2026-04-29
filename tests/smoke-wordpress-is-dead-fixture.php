<?php
/**
 * Smoke test: the WordPress-is-dead static site fixture imports as non-empty theme files.
 *
 * Run inside a WordPress site with BFB active:
 * wp eval-file tests/smoke-wordpress-is-dead-fixture.php
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

$fixture = is_readable( '/Users/chubes/Developer/wordpress-is-dead/index.html' )
	? '/Users/chubes/Developer/wordpress-is-dead/index.html'
	: $plugin_root . '/tests/fixtures/wordpress-is-dead/index.html';
$result  = Static_Site_Importer_Theme_Generator::import_theme(
	$fixture,
	array(
		'name'      => 'WordPress Is Dead Fixture',
		'slug'      => 'wordpress-is-dead-fixture',
		'overwrite' => true,
		'activate'  => false,
	)
);

$assert( ! is_wp_error( $result ), 'import-succeeds', is_wp_error( $result ) ? $result->get_error_message() : '' );

if ( ! is_wp_error( $result ) ) {
	$theme_dir  = $result['theme_dir'];
	$front_page = file_get_contents( $theme_dir . '/templates/front-page.html' );
	$page       = file_get_contents( $theme_dir . '/templates/page.html' );
	$header     = file_get_contents( $theme_dir . '/parts/header.html' );
	$footer     = file_get_contents( $theme_dir . '/parts/footer.html' );
	$style      = file_get_contents( $theme_dir . '/style.css' );
	$functions  = file_get_contents( $theme_dir . '/functions.php' );

	$assert( is_string( $front_page ) && str_contains( $front_page, 'wp:post-content' ), 'front-page-renders-imported-page-content' );
	$assert( is_string( $page ) && str_contains( $page, 'wp:post-content' ), 'page-template-renders-imported-page-content' );
	$assert( is_string( $header ) && str_contains( $header, 'WordPress' ) && str_contains( $header, 'Is' ) && str_contains( $header, 'Dead' ), 'header-preserves-site-brand' );
	$assert( is_string( $header ) && ! str_contains( $header, 'href="manifesto.html"' ), 'header-rewrites-internal-links' );
	$assert( is_string( $header ) && 1 === substr_count( $header, 'Manifesto' ), 'header-does-not-duplicate-navigation' );
	$assert( is_string( $footer ) && str_contains( $footer, 'Prompt Liberation Front' ), 'footer-preserves-footer-copy' );
	$assert( is_string( $style ) && str_contains( $style, '--accent' ), 'style-preserves-inline-css' );
	$assert( is_string( $functions ) && str_contains( $functions, 'wp_enqueue_style' ), 'theme-enqueues-stylesheet' );
	$assert( is_string( $front_page ) && ! str_contains( $front_page, '<!-- wp:html /-->' ), 'front-page-has-no-empty-html-fallbacks' );
	$assert( isset( $result['pages']['index.html'], $result['pages']['manifesto.html'], $result['pages']['comparison.html'], $result['pages']['eulogy.html'], $result['pages']['proof.html'] ), 'imports-five-html-pages' );

	$manifesto = isset( $result['pages']['manifesto.html'] ) ? get_post( $result['pages']['manifesto.html'] ) : null;
	$proof     = isset( $result['pages']['proof.html'] ) ? get_post( $result['pages']['proof.html'] ) : null;
	$assert( $manifesto instanceof WP_Post && str_contains( $manifesto->post_content, 'The CMS was a workaround' ), 'manifesto-page-preserves-body' );
	$assert( $proof instanceof WP_Post && str_contains( $proof->post_content, 'The whole site' ), 'proof-page-preserves-body' );
	$assert( $proof instanceof WP_Post && ! str_contains( $proof->post_content, 'href="index.html"' ), 'page-content-rewrites-internal-links' );
}

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo 'OK: wordpress-is-dead fixture smoke passed (' . $assertions . " assertions)\n";

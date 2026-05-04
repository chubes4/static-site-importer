<?php
/**
 * Smoke test: a body hero header after leading site nav stays in page content.
 *
 * Run inside a WordPress site with BFB active:
 * wp eval-file tests/smoke-multi-header-with-hero.php
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

$fixture = $plugin_root . '/tests/fixtures/multi-header-with-hero/index.html';
$assert( is_readable( $fixture ), 'fixture-file-exists' );

$result = Static_Site_Importer_Theme_Generator::import_theme(
	$fixture,
	array(
		'name'        => 'Multi Header With Hero',
		'slug'        => 'multi-header-with-hero',
		'overwrite'   => true,
		'activate'    => false,
		'keep_source' => true,
	)
);

$assert( ! is_wp_error( $result ), 'import-succeeds', is_wp_error( $result ) ? $result->get_error_message() : '' );

if ( ! is_wp_error( $result ) ) {
	$theme_dir = $result['theme_dir'];
	$pattern   = $read( $theme_dir . '/patterns/page-home.php' );
	$header    = $read( $theme_dir . '/parts/header.html' );

	$assert( str_contains( $pattern, 'hero-tag' ), 'home-pattern-preserves-hero-tag-class' );
	$assert( str_contains( $pattern, 'Radical Speed Month 2025' ), 'home-pattern-preserves-hero-tag-text' );
	$assert( str_contains( $pattern, 'Static<br>into<br><em>WordPress.</em>' ), 'home-pattern-preserves-hero-heading' );
	$assert( 14 === substr_count( $pattern, 'className":"ticker-item"' ), 'home-pattern-preserves-14-ticker-items', 'count=' . substr_count( $pattern, 'className":"ticker-item"' ) );
	$assert( 4 === substr_count( $pattern, 'className":"hero-stat"' ), 'home-pattern-preserves-4-stat-tiles', 'count=' . substr_count( $pattern, 'className":"hero-stat"' ) );
	$assert( str_contains( $header, 'nav-logo' ), 'header-part-preserves-site-nav-logo' );
	$assert( str_contains( $header, 'Static Site Importer' ), 'header-part-preserves-site-nav-text' );
	$assert( ! str_contains( $header, 'hero-tag' ), 'header-part-excludes-hero-tag' );
	$assert( ! str_contains( $header, 'Command' ), 'header-part-excludes-hero-stat-copy' );
	$assert( ! str_contains( $header, 'ticker-item' ), 'header-part-excludes-ticker-items' );
}

if ( ! empty( $failures ) ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo 'OK: multi-header hero smoke passed (' . $assertions . " assertions)\n";

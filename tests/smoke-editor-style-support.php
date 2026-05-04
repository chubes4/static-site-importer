<?php
/**
 * Smoke test: generated themes load imported styles in the editor/Site Editor.
 *
 * Run inside a WordPress site with BFB active:
 * wp eval-file tests/smoke-editor-style-support.php
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

$read = static function ( string $path ): string {
	$contents = file_get_contents( $path );
	return false === $contents ? '' : $contents;
};

$copy_fixture = static function ( string $source_dir, string $target_dir ) use ( &$copy_fixture ): void {
	if ( ! is_dir( $target_dir ) ) {
		wp_mkdir_p( $target_dir );
	}

	foreach ( scandir( $source_dir ) ?: array() as $entry ) {
		if ( '.' === $entry || '..' === $entry ) {
			continue;
		}

		$source = $source_dir . '/' . $entry;
		$target = $target_dir . '/' . $entry;
		if ( is_dir( $source ) ) {
			$copy_fixture( $source, $target );
			continue;
		}

		copy( $source, $target );
	}
};

$source_fixture = $plugin_root . '/tests/fixtures/wordpress-is-dead';
$fixture_copy   = trailingslashit( get_temp_dir() ) . 'static-site-importer-editor-style-fixture';
$copy_fixture( $source_fixture, $fixture_copy );

$result = Static_Site_Importer_Theme_Generator::import_theme(
	$fixture_copy . '/index.html',
	array(
		'name'      => 'Editor Style Support Fixture',
		'slug'      => 'editor-style-support-fixture',
		'overwrite' => true,
		'activate'  => false,
	)
);

$assert( ! is_wp_error( $result ), 'import-succeeds', is_wp_error( $result ) ? $result->get_error_message() : '' );

if ( ! is_wp_error( $result ) ) {
	$functions    = $read( $result['theme_dir'] . '/functions.php' );
	$style        = $read( $result['theme_dir'] . '/style.css' );
	$editor_style = $read( $result['theme_dir'] . '/assets/css/editor-style.css' );

	$assert( str_contains( $functions, "add_theme_support( 'editor-styles' )" ), 'functions-adds-editor-styles-support' );
	$assert( str_contains( $functions, "add_editor_style( 'assets/css/editor-style.css' )" ), 'functions-registers-dedicated-editor-style-css' );
	$assert( str_contains( $functions, "add_action( 'enqueue_block_editor_assets'" ), 'functions-adds-block-editor-assets-fallback' );
	$assert( str_contains( $functions, 'wp_enqueue_style' ) && str_contains( $functions, '-editor-style' ), 'functions-enqueues-editor-style-fallback' );
	$assert( str_contains( $functions, "get_template_directory_uri() . '/assets/css/editor-style.css'" ), 'functions-enqueues-dedicated-editor-style-css' );
	$assert( str_contains( $functions, 'wp_enqueue_scripts' ), 'functions-keeps-frontend-style-enqueue' );
	$assert( str_contains( $style, '--accent' ), 'frontend-style-keeps-source-css' );
	$assert( ! str_contains( $style, '.editor-styles-wrapper' ), 'frontend-style-excludes-editor-wrapper-rules' );
	$assert( str_contains( $editor_style, '--accent' ), 'editor-style-keeps-source-css' );
	$assert( ! str_contains( $editor_style, 'body.admin-bar' ), 'editor-style-excludes-frontend-admin-bar-rules' );
}

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo 'OK: editor style support smoke passed (' . $assertions . " assertions)\n";

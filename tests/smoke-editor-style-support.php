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

$result = Static_Site_Importer_Theme_Generator::import_theme(
	$plugin_root . '/tests/fixtures/wordpress-is-dead/index.html',
	array(
		'name'      => 'Editor Style Support Fixture',
		'slug'      => 'editor-style-support-fixture',
		'overwrite' => true,
		'activate'  => false,
	)
);

$assert( ! is_wp_error( $result ), 'import-succeeds', is_wp_error( $result ) ? $result->get_error_message() : '' );

if ( ! is_wp_error( $result ) ) {
	$functions = $read( $result['theme_dir'] . '/functions.php' );

	$assert( str_contains( $functions, "add_theme_support( 'editor-styles' )" ), 'functions-adds-editor-styles-support' );
	$assert( str_contains( $functions, "add_editor_style( 'style.css' )" ), 'functions-registers-editor-style-css' );
	$assert( str_contains( $functions, "add_action( 'enqueue_block_editor_assets'" ), 'functions-adds-block-editor-assets-fallback' );
	$assert( str_contains( $functions, 'wp_enqueue_style' ) && str_contains( $functions, '-editor-style' ), 'functions-enqueues-editor-style-fallback' );
	$assert( str_contains( $functions, 'get_stylesheet_uri()' ), 'functions-reuses-generated-style-css' );
	$assert( str_contains( $functions, 'wp_enqueue_scripts' ), 'functions-keeps-frontend-style-enqueue' );
}

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo 'OK: editor style support smoke passed (' . $assertions . " assertions)\n";

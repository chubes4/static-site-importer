<?php
/**
 * Smoke test: admin UI exposes Import HTML from Appearance -> Themes.
 *
 * Run from the repository root:
 * php tests/smoke-admin-import-html-entry.php
 *
 * @package StaticSiteImporter
 */

$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-static-site-importer-admin.php' );
if ( false === $source ) {
	fwrite( STDERR, "FAIL [admin-source-readable]\n" );
	exit( 1 );
}

$assertions = 0;
$failures   = array();

$assert = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
	$assertions++;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
	}
};

$assert( str_contains( $source, "add_action( 'admin_head-themes.php'" ), 'themes-screen-hook-registered' );
$assert( str_contains( $source, 'render_themes_screen_button' ), 'themes-screen-button-method-exists' );
$assert( str_contains( $source, 'add_submenu_page(' ), 'hidden-import-page-registered' );
$assert( str_contains( $source, 'register_import_page' ), 'hidden-import-page-method-exists' );
$assert( ! str_contains( $source, 'add_theme_page(' ), 'appearance-submenu-not-registered' );
$assert( str_contains( $source, "admin_url( 'admin.php?page=static-site-importer' )" ), 'button-targets-hidden-import-page' );
$assert( str_contains( $source, '.page-title-action[href*="theme-install.php"]' ), 'button-anchors-to-add-theme-action' );
$assert( str_contains( $source, 'static-site-importer-import-html-action' ), 'button-has-plugin-specific-class' );
$assert( ! str_contains( $source, 'Import Static Site' ), 'old-import-static-site-label-removed' );
$assert( str_contains( $source, 'Import HTML' ), 'import-html-label-present' );
$assert( str_contains( $source, 'HTML ZIP' ), 'zip-field-label-renamed' );

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo 'OK: admin Import HTML entry smoke passed (' . $assertions . " assertions)\n";

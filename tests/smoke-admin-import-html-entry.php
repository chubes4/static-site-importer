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
$assert( str_contains( $source, 'name="static_site_pasted_html"' ), 'paste-html-textarea-present' );
$assert( str_contains( $source, 'write_pasted_html' ), 'paste-html-write-helper-present' );
$assert( str_contains( $source, 'name="static_site_html"' ), 'single-html-upload-field-present' );
$assert( str_contains( $source, 'accept=".html,.htm"' ), 'single-html-upload-accepts-html' );
$assert( str_contains( $source, 'name="static_site_zip"' ), 'zip-upload-field-present' );
$assert( ! str_contains( $source, 'name="static_site_zip" accept=".zip" required' ), 'zip-upload-not-required' );
$assert( str_contains( $source, "has_uploaded_file( 'static_site_html' )" ), 'html-upload-preferred-before-zip' );
$assert( str_contains( $source, "has_uploaded_file( 'static_site_zip' )" ), 'zip-upload-fallback-present' );
$assert( strpos( $source, "has_uploaded_file( 'static_site_html' )" ) < strpos( $source, "has_uploaded_file( 'static_site_zip' )" ), 'html-upload-precedes-zip-fallback' );
$assert( str_contains( $source, "in_array( $" . "ext, array( 'html', 'htm' ), true )" ), 'html-extension-validation-present' );
$assert( str_contains( $source, "'index.html'" ), 'admin-intake-stores-index-html' );
$assert( str_contains( $source, 'prepare_uploaded_zip_file' ), 'zip-import-helper-present' );
$assert( str_contains( $source, 'find_index_html' ), 'zip-index-discovery-preserved' );
$assert( str_contains( $source, 'Paste HTML content, upload a single HTML file, or upload a ZIP containing index.html.' ), 'empty-intake-validation-message-present' );
$assert( str_contains( $source, 'multi-page static site or bundled HTML export' ), 'zip-copy-explains-bundle-contract' );
$assert( str_contains( $source, 'validate_zip_archive' ), 'zip-archive-validation-method-exists' );
$assert( str_contains( $source, "class_exists( 'ZipArchive' )" ), 'ziparchive-inspection-is-conditional' );
$assert( str_contains( $source, 'is_unsafe_archive_path' ), 'unsafe-archive-path-check-exists' );
$assert( str_contains( $source, 'is_server_side_file' ), 'server-side-file-check-exists' );
$assert( str_contains( $source, 'path_is_under' ), 'selected-index-path-boundary-check-exists' );
$assert( str_contains( $source, '$root_candidates' ), 'root-index-candidates-win-before-nested' );
$assert( str_contains( $source, 'str_contains( $normalized, "\0" )' ), 'nul-byte-archive-path-check-exists' );
$assert( str_contains( $source, 'in_array( \'..\', explode( \'/\', $normalized ), true )' ), 'path-traversal-archive-entry-check-exists' );
$assert( str_contains( $source, "preg_match( '/^[A-Za-z]:" ), 'windows-absolute-archive-path-check-exists' );
$assert( str_contains( $source, "'php', 'phtml', 'phar'" ), 'server-side-extension-denylist-exists' );
$assert( str_contains( $source, 'Root-level index.html wins' ), 'index-precedence-documented-in-source' );
$assert( str_contains( $source, 'multiple nested index.html files' ), 'ambiguous-index-error-present' );
$assert( str_contains( $source, 'needs an index.html entry point' ), 'missing-index-error-friendly' );

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo 'OK: admin Import HTML entry smoke passed (' . $assertions . " assertions)\n";

<?php
/**
 * Smoke test: mixed HTML/Markdown source-site fixture imports with report diagnostics.
 *
 * Run inside a WordPress site with BFB active:
 * wp eval-file tests/smoke-mixed-source-fixture.php
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
	$assertions++;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
	}
};

$read = static function ( string $path ): string {
	$contents = file_get_contents( $path );
	return false === $contents ? '' : $contents;
};

$fixture_dir = $plugin_root . '/tests/fixtures/mixed-source-site';
$fixture     = $fixture_dir . '/index.html';

$result = Static_Site_Importer_Theme_Generator::import_theme(
	$fixture,
	array(
		'name'        => 'Mixed Source Fixture',
		'slug'        => 'mixed-source-fixture',
		'overwrite'   => true,
		'activate'    => false,
		'keep_source' => true,
	)
);

$assert( ! is_wp_error( $result ), 'import-succeeds', is_wp_error( $result ) ? $result->get_error_message() : '' );

if ( ! is_wp_error( $result ) ) {
	$report       = json_decode( $read( $result['report_path'] ), true );
	$story_id     = $result['pages']['content/story.md'] ?? 0;
	$notes_id     = $result['pages']['content/notes.markdown'] ?? 0;
	$story        = $story_id ? get_post( $story_id ) : null;
	$notes        = $notes_id ? get_post( $notes_id ) : null;
	$header       = $read( $result['theme_dir'] . '/parts/header.html' );
	$story_pattern = $read( $result['theme_dir'] . '/patterns/page-content-story.php' );

	$assert( is_array( $report ), 'report-is-json' );
	$assert( 1 === (int) ( $report['source_documents']['counts_by_format']['html'] ?? 0 ), 'report-counts-html-entry' );
	$assert( 2 === (int) ( $report['source_documents']['counts_by_format']['markdown'] ?? 0 ), 'report-counts-markdown-documents' );
	$assert( 1 === (int) ( $report['source_documents']['skipped_mdx_count'] ?? 0 ), 'report-counts-skipped-mdx' );
	$assert( 0 === (int) ( $report['source_documents']['unresolved_link_count'] ?? -1 ), 'report-has-no-unresolved-links' );
	$assert( isset( $report['conversion_fragments']['main:content/story.md'] ), 'report-includes-markdown-conversion-fragment' );
	$assert( $story instanceof WP_Post, 'story-page-created' );
	$assert( $notes instanceof WP_Post, 'notes-page-created' );
	$assert( $story instanceof WP_Post && str_contains( $story->post_content, 'Markdown Story' ), 'story-page-contains-markdown-heading' );
	$assert( $notes instanceof WP_Post && str_contains( $notes->post_content, 'Field Notes' ), 'notes-page-contains-markdown-heading' );
	$assert( ! str_contains( $header, '.md' ), 'header-links-rewritten-away-from-markdown-sources' );
	$assert( str_contains( $story_pattern, 'Markdown Story' ), 'markdown-pattern-written' );
}

if ( $failures ) {
	foreach ( $failures as $failure ) {
		WP_CLI::warning( $failure );
	}
	WP_CLI::error( sprintf( 'Mixed source fixture smoke failed with %d failure(s) across %d assertion(s).', count( $failures ), $assertions ) );
}

WP_CLI::success( sprintf( 'Mixed source fixture smoke passed (%d assertions).', $assertions ) );

<?php
/**
 * Smoke test: an Astro-like HTML shell imports Markdown content collections.
 *
 * Run inside a WordPress site with BFB active:
 * wp eval-file tests/smoke-astro-markdown-content-collections.php
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
if ( ! class_exists( 'Static_Site_Importer_Source_Page', false ) ) {
	require_once $plugin_root . '/includes/class-static-site-importer-source-page.php';
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

$fixture_dir = $plugin_root . '/tests/fixtures/astro-content-collection';
$fixture     = $fixture_dir . '/index.html';

foreach ( array( 'index.html', 'styles.css', 'src/content/posts/first-post.md', 'src/content/pages/about.markdown' ) as $file ) {
	$assert( is_readable( $fixture_dir . '/' . $file ), 'fixture-file-exists-' . $file );
}

$result = Static_Site_Importer_Theme_Generator::import_theme(
	$fixture,
	array(
		'name'        => 'Astro Content Collection',
		'slug'        => 'astro-content-collection',
		'overwrite'   => true,
		'activate'    => false,
		'keep_source' => true,
	)
);

$assert( ! is_wp_error( $result ), 'import-succeeds', is_wp_error( $result ) ? $result->get_error_message() : '' );

if ( ! is_wp_error( $result ) ) {
	$theme_dir = $result['theme_dir'];
	$header    = $read( $theme_dir . '/parts/header.html' );
	$style     = $read( $theme_dir . '/style.css' );
	$report    = json_decode( $read( $result['report_path'] ?? '' ), true );
	$pages     = $result['pages'] ?? array();

	$assert( isset( $pages['index.html'] ), 'html-shell-page-imported' );
	$assert( isset( $pages['src/content/posts/first-post.md'] ), 'markdown-md-page-imported' );
	$assert( isset( $pages['src/content/pages/about.markdown'] ), 'markdown-long-extension-page-imported' );
	$assert( str_contains( $header, 'Astro Shell' ), 'html-shell-supplies-header-chrome' );
	$assert( str_contains( $style, '.markdown-card' ), 'html-shell-linked-css-preserved' );
	$assert( ! str_contains( $header, '.md' ) && ! str_contains( $header, '.markdown' ) && ! str_contains( $header, 'index.html' ), 'header-source-links-rewritten' );

	$markdown_post = get_post( $pages['src/content/posts/first-post.md'] ?? 0 );
	$about_page    = get_post( $pages['src/content/pages/about.markdown'] ?? 0 );
	$assert( $markdown_post instanceof WP_Post, 'markdown-post-created' );
	$assert( $about_page instanceof WP_Post, 'markdown-page-created' );
	if ( $markdown_post instanceof WP_Post ) {
		$assert( str_contains( $markdown_post->post_content, 'First Markdown Post' ), 'markdown-heading-converted-to-block-content' );
		$assert( str_contains( $markdown_post->post_content, 'markdown-card' ), 'markdown-html-island-converted-to-block-content' );
		$assert( ! str_contains( $markdown_post->post_content, 'collection: posts' ), 'frontmatter-not-imported-as-content' );
		$assert( ! str_contains( $markdown_post->post_content, '.markdown' ), 'markdown-content-link-rewritten' );
	}
	if ( $about_page instanceof WP_Post ) {
		$assert( str_contains( $about_page->post_content, 'About Markdown Page' ), 'markdown-extension-page-content-imported' );
		$assert( ! str_contains( $about_page->post_content, '../posts/first-post.md' ), 'relative-markdown-link-rewritten-by-basename' );
	}

	$semantic_targets = $report['semantic_fidelity']['comparison_targets'] ?? array();
	$markdown_targets = array_values(
		array_filter(
			is_array( $semantic_targets ) ? $semantic_targets : array(),
			static fn ( $target ): bool => is_array( $target ) && 'markdown' === ( $target['source_type'] ?? '' )
		)
	);
	$assert( count( $markdown_targets ) >= 2, 'report-records-markdown-source-targets' );
}

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo 'OK: Astro Markdown content collection smoke passed (' . $assertions . " assertions)\n";

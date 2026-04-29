<?php
/**
 * Smoke test: the WordPress-is-dead static site fixture imports with portable fidelity checks.
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

$assertions        = 0;
$failures          = array();
$expected_failures = array();

$assert = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
	$assertions++;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
	}
};

$expect_failure = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$expected_failures ): void {
	$assertions++;
	if ( ! $condition ) {
		$expected_failures[] = 'EXPECTED-FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
	}
};

$read = static function ( string $path ): string {
	$contents = file_get_contents( $path );
	return false === $contents ? '' : $contents;
};

$selector_count = static function ( string $content, string $selector ): int {
	$classes = array_filter( explode( '.', ltrim( $selector, '.' ) ) );
	preg_match_all( '/class="([^"]*)"|"className":"([^"]*)"/', $content, $matches, PREG_SET_ORDER );

	$count = 0;
	foreach ( $matches as $match ) {
		$attribute = $match[1] ?: $match[2];
		$tokens    = preg_split( '/\s+/', trim( $attribute ) ) ?: array();
		if ( empty( array_diff( $classes, $tokens ) ) ) {
			$count++;
		}
	}

	return $count;
};

$contains_selector = static fn ( string $content, string $selector ): bool => $selector_count( $content, $selector ) > 0;

$fixture_dir = $plugin_root . '/tests/fixtures/wordpress-is-dead';
$fixture     = $fixture_dir . '/index.html';
$files       = array( 'index.html', 'manifesto.html', 'comparison.html', 'eulogy.html', 'proof.html', 'styles.css' );

foreach ( $files as $file ) {
	$assert( is_readable( $fixture_dir . '/' . $file ), 'fixture-file-exists-' . $file );
}

$assert( ! str_contains( $read( __FILE__ ), 'Developer' . '/wordpress-is-dead' ), 'smoke-has-no-local-fixture-fallback' );

$result = Static_Site_Importer_Theme_Generator::import_theme(
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
	$front_page = $read( $theme_dir . '/templates/front-page.html' );
	$page       = $read( $theme_dir . '/templates/page.html' );
	$header     = $read( $theme_dir . '/parts/header.html' );
	$footer     = $read( $theme_dir . '/parts/footer.html' );
	$style      = $read( $theme_dir . '/style.css' );
	$functions  = $read( $theme_dir . '/functions.php' );

	$assert( str_contains( $front_page, 'wp:post-content' ), 'front-page-renders-imported-page-content' );
	$assert( str_contains( $page, 'wp:post-content' ), 'page-template-renders-imported-page-content' );
	$assert( ! str_contains( $front_page, 'layout":{"type":"constrained"' ), 'front-page-template-is-neutral' );
	$assert( ! str_contains( $page, 'layout":{"type":"constrained"' ), 'page-template-is-neutral' );
	$assert( str_contains( $header, 'WordPress' ) && str_contains( $header, 'Is' ) && str_contains( $header, 'Dead' ), 'header-preserves-site-brand' );
	$assert( ! preg_match( '/href=("|\')[^"\']+\.html(?:[#?][^"\']*)?\1/i', $header ), 'header-has-no-stale-html-links' );
	$assert( ! $contains_selector( $header, '.active' ), 'shared-header-has-no-static-active-nav-class' );
	$assert( 1 === substr_count( $header, 'Manifesto' ), 'header-does-not-duplicate-navigation' );
	$assert( str_contains( $footer, 'Prompt Liberation Front' ), 'footer-preserves-footer-copy' );
	$assert( str_contains( $style, '--accent' ) && str_contains( $style, '.compare' ) && str_contains( $style, '.manifesto-list' ), 'style-preserves-source-css' );
	$assert( str_contains( $functions, 'wp_enqueue_style' ), 'theme-enqueues-stylesheet' );
	$assert( ! str_contains( $front_page, '<!-- wp:html /-->' ), 'front-page-has-no-empty-html-fallbacks' );
	$assert( isset( $result['pages']['index.html'], $result['pages']['manifesto.html'], $result['pages']['comparison.html'], $result['pages']['eulogy.html'], $result['pages']['proof.html'] ), 'imports-five-html-pages' );

	$pages = array();
	foreach ( array( 'index.html', 'manifesto.html', 'comparison.html', 'eulogy.html', 'proof.html' ) as $filename ) {
		$post = isset( $result['pages'][ $filename ] ) ? get_post( $result['pages'][ $filename ] ) : null;
		$assert( $post instanceof WP_Post, 'page-post-exists-' . $filename );
		if ( $post instanceof WP_Post ) {
			$pages[ $filename ] = array(
				'stored'   => $post->post_content,
				'rendered' => do_blocks( $post->post_content ),
			);
		}
	}

	$assert( isset( $pages['proof.html'] ) && ! str_contains( $pages['proof.html']['stored'], 'href="index.html"' ), 'page-content-rewrites-internal-links' );

	$selector_expectations = array(
		'index.html'      => array( '.hero', '.block', '.block.alt', '.container', '.lede', '.pull' ),
		'manifesto.html'  => array( '.hero', '.block', '.container', '.lede', '.manifesto-list', '.pull' ),
		'comparison.html' => array( '.hero', '.block', '.container', '.lede', '.compare', '.col-wp', '.col-claude' ),
		'eulogy.html'     => array( '.hero', '.block', '.container', '.lede', '.eulogy-frame', '.dates', '.pull' ),
		'proof.html'      => array( '.hero', '.block', '.block.alt', '.container', '.lede', '.prompt', '.pull' ),
	);

	foreach ( $selector_expectations as $filename => $selectors ) {
		foreach ( array_unique( $selectors ) as $selector ) {
			$stored   = $pages[ $filename ]['stored'] ?? '';
			$rendered = $pages[ $filename ]['rendered'] ?? '';
			$expect_failure( $contains_selector( $stored, $selector ), 'stored-preserves-selector-' . $filename . '-' . $selector, 'blocked by h2bc/BFB wrapper-class fidelity gaps' );
			$expect_failure( $contains_selector( $rendered, $selector ), 'rendered-preserves-selector-' . $filename . '-' . $selector, 'blocked by h2bc/BFB wrapper-class fidelity gaps' );
		}
	}

	$comparison = $pages['comparison.html']['stored'] ?? '';
	$eulogy     = $pages['eulogy.html']['stored'] ?? '';
	$manifesto  = $pages['manifesto.html']['stored'] ?? '';

	$expect_failure( 1 === $selector_count( $comparison, '.compare' ), 'comparison-compare-wrapper-not-duplicated', 'count=' . $selector_count( $comparison, '.compare' ) );
	$expect_failure( 1 === $selector_count( $comparison, '.col-wp' ), 'comparison-col-wp-not-duplicated', 'count=' . $selector_count( $comparison, '.col-wp' ) );
	$expect_failure( 1 === $selector_count( $comparison, '.col-claude' ), 'comparison-col-claude-not-duplicated', 'count=' . $selector_count( $comparison, '.col-claude' ) );
	$expect_failure( 1 === substr_count( $comparison, 'WordPress <span class="tag">' ), 'comparison-wordpress-heading-not-duplicated', 'count=' . substr_count( $comparison, 'WordPress <span class="tag">' ) );

	$expect_failure( 1 === $selector_count( $eulogy, '.eulogy-frame' ), 'eulogy-frame-not-duplicated', 'count=' . $selector_count( $eulogy, '.eulogy-frame' ) );
	$expect_failure( 1 === $selector_count( $eulogy, '.dates' ), 'eulogy-dates-not-duplicated', 'count=' . $selector_count( $eulogy, '.dates' ) );
	$expect_failure( 1 === substr_count( $eulogy, 'It is rare that a piece of software earns the right to be eulogized.' ), 'eulogy-key-paragraph-not-duplicated', 'count=' . substr_count( $eulogy, 'It is rare that a piece of software earns the right to be eulogized.' ) );

	$expect_failure( 1 === $selector_count( $manifesto, '.manifesto-list' ), 'manifesto-list-not-duplicated', 'count=' . $selector_count( $manifesto, '.manifesto-list' ) );
	$expect_failure( 1 === substr_count( $manifesto, 'The CMS was a workaround for not being able to write HTML.' ), 'manifesto-key-heading-not-duplicated', 'count=' . substr_count( $manifesto, 'The CMS was a workaround for not being able to write HTML.' ) );
}

if ( $expected_failures ) {
	fwrite( STDERR, implode( "\n", $expected_failures ) . "\n" );
}

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo 'OK: wordpress-is-dead fixture smoke passed (' . $assertions . ' assertions';
if ( $expected_failures ) {
	echo ', ' . count( $expected_failures ) . ' expected upstream fidelity failures';
}
echo ")\n";

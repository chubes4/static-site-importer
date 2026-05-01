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

if ( ! defined( 'STATIC_SITE_IMPORTER_PATH' ) && is_readable( $plugin_root . '/static-site-importer.php' ) ) {
	require_once $plugin_root . '/static-site-importer.php';
}
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

$pattern_blocks = static function ( string $pattern_file ): string {
	$parts = explode( '?>', $pattern_file, 2 );
	return trim( 2 === count( $parts ) ? $parts[1] : $pattern_file );
};

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
	$theme_json = json_decode( $read( $theme_dir . '/theme.json' ), true );
	$report     = json_decode( $read( $result['report_path'] ?? '' ), true );
	$palette    = array();
	foreach ( $theme_json['settings']['color']['palette'] ?? array() as $color ) {
		if ( isset( $color['slug'] ) ) {
			$palette[ $color['slug'] ] = $color;
		}
	}
	$home_tmpl  = $read( $theme_dir . '/templates/page-home.html' );
	$proof_tmpl = $read( $theme_dir . '/templates/page-proof.html' );
	$home_pat   = $read( $theme_dir . '/patterns/page-home.php' );
	$proof_pat  = $read( $theme_dir . '/patterns/page-proof.php' );
	$header_nav = get_page_by_path( 'wordpress-is-dead-fixture-header-navigation', OBJECT, 'wp_navigation' );
	$footer_nav = get_page_by_path( 'wordpress-is-dead-fixture-footer-navigation', OBJECT, 'wp_navigation' );

	$assert( str_contains( $front_page, 'wp:pattern' ) && str_contains( $front_page, 'wordpress-is-dead-fixture/page-home' ), 'front-page-renders-imported-page-pattern' );
	$assert( is_array( $report ), 'import-report-is-valid-json' );
	$assert( isset( $report['quality']['fallback_count'] ), 'import-report-includes-fallback-count' );
	$assert( isset( $report['conversion_fragments']['main:index.html'] ), 'import-report-groups-fragments-by-source' );
	$assert( isset( $result['quality']['pass'] ), 'import-result-includes-quality-summary' );
	$assert( str_contains( $page, 'wp:post-content' ), 'page-template-renders-imported-page-content' );
	$assert( str_contains( $home_tmpl, 'wp:pattern' ) && str_contains( $home_tmpl, 'wordpress-is-dead-fixture/page-home' ), 'home-page-template-renders-home-pattern' );
	$assert( str_contains( $proof_tmpl, 'wp:pattern' ) && str_contains( $proof_tmpl, 'wordpress-is-dead-fixture/page-proof' ), 'proof-page-template-renders-proof-pattern' );
	$assert( str_contains( $home_pat, 'Slug: wordpress-is-dead-fixture/page-home' ), 'home-pattern-has-theme-slug' );
	$assert( str_contains( $proof_pat, 'Slug: wordpress-is-dead-fixture/page-proof' ), 'proof-pattern-has-theme-slug' );
	$assert( ! str_contains( $front_page, 'layout":{"type":"constrained"' ), 'front-page-template-is-neutral' );
	$assert( ! str_contains( $page, 'layout":{"type":"constrained"' ), 'page-template-is-neutral' );
	$assert( str_contains( $header, 'WordPress' ) && str_contains( $header, 'Is' ) && str_contains( $header, 'Dead' ), 'header-preserves-site-brand' );
	$assert( ! preg_match( '/href=("|\')[^"\']+\.html(?:[#?][^"\']*)?\1/i', $header ), 'header-has-no-stale-html-links' );
	$assert( ! $contains_selector( $header, '.active' ), 'shared-header-has-no-static-active-nav-class' );
	$assert( str_contains( $header, '<!-- wp:navigation ' ), 'header-uses-native-navigation-block' );
	$assert( str_contains( $header, '"ref":' ), 'header-navigation-references-persistent-entity' );
	$assert( ! str_contains( $header, '<!-- wp:navigation-link ' ), 'header-template-part-does-not-inline-navigation-links' );
	$assert( $header_nav instanceof WP_Post, 'header-navigation-post-exists' );
	if ( $header_nav instanceof WP_Post ) {
		$assert( str_contains( $header, '"ref":' . $header_nav->ID ), 'header-navigation-ref-points-to-post' );
		$assert( 1 === substr_count( $header_nav->post_content, '"label":"Manifesto"' ), 'header-navigation-post-does-not-duplicate-label' );
		$assert( str_contains( $header_nav->post_content, '<!-- wp:navigation-link ' ), 'header-navigation-post-stores-navigation-link-blocks' );
	}
	$assert( str_contains( $footer, 'Prompt Liberation Front' ), 'footer-preserves-footer-copy' );
	$assert( str_contains( $footer, '<!-- wp:navigation ' ), 'footer-uses-native-navigation-block' );
	$assert( str_contains( $footer, '"ref":' ), 'footer-navigation-references-persistent-entity' );
	$assert( ! str_contains( $footer, '<!-- wp:navigation-link ' ), 'footer-template-part-does-not-inline-navigation-links' );
	$assert( $footer_nav instanceof WP_Post, 'footer-navigation-post-exists' );
	if ( $footer_nav instanceof WP_Post ) {
		$assert( str_contains( $footer, '"ref":' . $footer_nav->ID ), 'footer-navigation-ref-points-to-post' );
		$assert( str_contains( $footer_nav->post_content, '<!-- wp:navigation-link ' ), 'footer-navigation-post-stores-navigation-link-blocks' );
	}
	$assert( ! preg_match( '/href=("|\')[^"\']+\.html(?:[#?][^"\']*)?\1/i', $footer ), 'footer-has-no-stale-html-links' );

	$second_result = Static_Site_Importer_Theme_Generator::import_theme(
		$fixture,
		array(
			'name'      => 'WordPress Is Dead Fixture',
			'slug'      => 'wordpress-is-dead-fixture',
			'overwrite' => true,
			'activate'  => false,
		)
	);
	$assert( ! is_wp_error( $second_result ), 'second-import-succeeds', is_wp_error( $second_result ) ? $second_result->get_error_message() : '' );
	$header_nav_after = get_page_by_path( 'wordpress-is-dead-fixture-header-navigation', OBJECT, 'wp_navigation' );
	$footer_nav_after = get_page_by_path( 'wordpress-is-dead-fixture-footer-navigation', OBJECT, 'wp_navigation' );
	if ( $header_nav instanceof WP_Post && $header_nav_after instanceof WP_Post ) {
		$assert( $header_nav->ID === $header_nav_after->ID, 'second-import-reuses-header-navigation-post' );
	}
	if ( $footer_nav instanceof WP_Post && $footer_nav_after instanceof WP_Post ) {
		$assert( $footer_nav->ID === $footer_nav_after->ID, 'second-import-reuses-footer-navigation-post' );
	}
	$assert( str_contains( $style, '--accent' ) && str_contains( $style, '.compare' ) && str_contains( $style, '.manifesto-list' ), 'style-preserves-source-css' );
	$assert( str_contains( $style, '.wp-block-button.btn > .wp-block-button__link' ), 'style-bridges-source-button-classes-to-core-button-links' );
	$assert( str_contains( $style, '.wp-block-button.btn.primary > .wp-block-button__link' ), 'style-bridges-source-primary-button-to-core-button-link' );
	$assert( str_contains( $style, '.wp-block-button.btn.ghost > .wp-block-button__link' ), 'style-bridges-source-ghost-button-to-core-button-link' );
	$assert( str_contains( $functions, 'wp_enqueue_style' ), 'theme-enqueues-stylesheet' );
	$assert( is_array( $theme_json ), 'theme-json-is-valid-json' );
	$assert( isset( $palette['bg'] ) && '#0a0a0a' === $palette['bg']['color'], 'theme-json-exposes-bg-palette-token' );
	$assert( isset( $palette['fg'] ) && '#f4f4f0' === $palette['fg']['color'], 'theme-json-exposes-fg-palette-token' );
	$assert( isset( $palette['muted'] ) && '#8a8a82' === $palette['muted']['color'], 'theme-json-exposes-muted-palette-token' );
	$assert( isset( $palette['accent'] ) && '#ff3b1f' === $palette['accent']['color'], 'theme-json-exposes-accent-palette-token' );
	$assert( ! isset( $palette['max'] ), 'theme-json-ignores-non-color-custom-properties' );
	$assert( 'var(--wp--preset--color--bg)' === ( $theme_json['styles']['color']['background'] ?? '' ), 'theme-json-sets-background-default-from-bg' );
	$assert( 'var(--wp--preset--color--fg)' === ( $theme_json['styles']['color']['text'] ?? '' ), 'theme-json-sets-text-default-from-fg' );
	$assert( ! str_contains( $front_page, '<!-- wp:html /-->' ), 'front-page-has-no-empty-html-fallbacks' );
	$assert( isset( $result['pages']['index.html'], $result['pages']['manifesto.html'], $result['pages']['comparison.html'], $result['pages']['eulogy.html'], $result['pages']['proof.html'] ), 'imports-five-html-pages' );

	$pages = array();
	foreach ( array( 'index.html', 'manifesto.html', 'comparison.html', 'eulogy.html', 'proof.html' ) as $filename ) {
		$post = isset( $result['pages'][ $filename ] ) ? get_post( $result['pages'][ $filename ] ) : null;
		$assert( $post instanceof WP_Post, 'page-post-exists-' . $filename );
		if ( $post instanceof WP_Post ) {
			$slug         = 'index.html' === $filename ? 'home' : preg_replace( '/\.html?$/i', '', $filename );
			$pattern_body = $pattern_blocks( $read( $theme_dir . '/patterns/page-' . $slug . '.php' ) );
			$assert( str_contains( $post->post_content, 'Imported page layout lives in this page' ), 'page-post-content-is-shell-' . $filename );
			$assert( ! $contains_selector( $post->post_content, '.hero' ), 'page-post-content-does-not-duplicate-layout-' . $filename );
			$pages[ $filename ] = array(
				'stored'   => $pattern_body,
				'rendered' => do_blocks( $pattern_body ),
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
			$assert( $contains_selector( $stored, $selector ), 'stored-preserves-selector-' . $filename . '-' . $selector );
			if ( 'proof.html' === $filename && '.prompt' === $selector ) {
				$expect_failure( $contains_selector( $rendered, $selector ), 'rendered-preserves-selector-' . $filename . '-' . $selector, 'blocked by h2bc/BFB preformatted wrapper-class fidelity gap' );
				continue;
			}

			$assert( $contains_selector( $rendered, $selector ), 'rendered-preserves-selector-' . $filename . '-' . $selector );
		}
	}

	$comparison = $pages['comparison.html']['stored'] ?? '';
	$eulogy     = $pages['eulogy.html']['stored'] ?? '';
	$manifesto  = $pages['manifesto.html']['stored'] ?? '';

	$assert( 1 === $selector_count( $comparison, '.compare' ), 'comparison-compare-wrapper-not-duplicated', 'count=' . $selector_count( $comparison, '.compare' ) );
	$assert( 1 === $selector_count( $comparison, '.col-wp' ), 'comparison-col-wp-not-duplicated', 'count=' . $selector_count( $comparison, '.col-wp' ) );
	$assert( 1 === $selector_count( $comparison, '.col-claude' ), 'comparison-col-claude-not-duplicated', 'count=' . $selector_count( $comparison, '.col-claude' ) );
	$assert( 1 === substr_count( $comparison, 'WordPress <span class="tag">' ), 'comparison-wordpress-heading-not-duplicated', 'count=' . substr_count( $comparison, 'WordPress <span class="tag">' ) );

	$assert( 1 === $selector_count( $eulogy, '.eulogy-frame' ), 'eulogy-frame-not-duplicated', 'count=' . $selector_count( $eulogy, '.eulogy-frame' ) );
	$assert( 1 === $selector_count( $eulogy, '.dates' ), 'eulogy-dates-not-duplicated', 'count=' . $selector_count( $eulogy, '.dates' ) );
	$assert( 1 === substr_count( $eulogy, 'It is rare that a piece of software earns the right to be eulogized.' ), 'eulogy-key-paragraph-not-duplicated', 'count=' . substr_count( $eulogy, 'It is rare that a piece of software earns the right to be eulogized.' ) );

	$expect_failure( 1 === $selector_count( $manifesto, '.manifesto-list' ), 'manifesto-list-not-duplicated', 'count=' . $selector_count( $manifesto, '.manifesto-list' ) );
	$assert( 1 === substr_count( $manifesto, 'The CMS was a workaround for not being able to write HTML.' ), 'manifesto-key-heading-not-duplicated', 'count=' . substr_count( $manifesto, 'The CMS was a workaround for not being able to write HTML.' ) );
}

$theme_part_fixture = trailingslashit( get_temp_dir() ) . 'static-site-importer-theme-parts.html';
$wrote_fixture      = file_put_contents(
	$theme_part_fixture,
	'<!doctype html><html><head><title>Theme Parts</title></head><body>' .
	'<nav class="nav"><div class="nav-logo"><span class="mark">*</span>Studio Code</div><a href="#get-started" class="nav-pill"><span>Get Early Access</span></a></nav>' .
	'<main><section><h1>Theme part smoke</h1><p>Body copy.</p></section></main>' .
	'<footer class="footer"><div class="footer-brand">Studio Code by Automattic</div><div class="footer-copy">Copyright 2026 Automattic Inc.</div></footer>' .
	'</body></html>'
);
$assert( false !== $wrote_fixture, 'theme-part-fixture-written' );

if ( false !== $wrote_fixture ) {
	$theme_part_result = Static_Site_Importer_Theme_Generator::import_theme(
		$theme_part_fixture,
		array(
			'name'      => 'Theme Part Fixture',
			'slug'      => 'theme-part-fixture',
			'overwrite' => true,
			'activate'  => false,
		)
	);
	$assert( ! is_wp_error( $theme_part_result ), 'theme-part-import-succeeds', is_wp_error( $theme_part_result ) ? $theme_part_result->get_error_message() : '' );
	if ( ! is_wp_error( $theme_part_result ) ) {
		$theme_part_header = $read( $theme_part_result['theme_dir'] . '/parts/header.html' );
		$theme_part_footer = $read( $theme_part_result['theme_dir'] . '/parts/footer.html' );
		$assert( str_contains( $theme_part_header, 'Studio Code' ), 'nav-only-header-preserves-brand-text' );
		$assert( str_contains( $theme_part_header, 'Get Early Access' ), 'nav-only-header-preserves-cta-text' );
		$assert( ! str_starts_with( trim( $theme_part_header ), '<!-- wp:html -->' ), 'nav-only-header-does-not-become-whole-html-island' );
		$assert( str_contains( $theme_part_footer, 'Studio Code by Automattic' ), 'simple-footer-preserves-brand-text' );
		$assert( str_contains( $theme_part_footer, 'Copyright 2026 Automattic Inc.' ), 'simple-footer-preserves-copy-text' );
		$assert( ! str_contains( $theme_part_footer, '<!-- wp:group {"className":"footer-brand"} /-->' ), 'simple-footer-does-not-emit-empty-brand-group' );
	}
}

$quality_fixture = trailingslashit( get_temp_dir() ) . 'static-site-importer-quality.html';
$wrote_quality   = file_put_contents(
	$quality_fixture,
	'<!doctype html><html><head><title>Quality</title></head><body><main><section><h1>Quality smoke</h1><iframe src="https://example.com/widget"></iframe></section></main></body></html>'
);
$assert( false !== $wrote_quality, 'quality-fixture-written' );

if ( false !== $wrote_quality ) {
	$quality_result = Static_Site_Importer_Theme_Generator::import_theme(
		$quality_fixture,
		array(
			'name'            => 'Quality Fixture',
			'slug'            => 'quality-fixture',
			'overwrite'       => true,
			'activate'        => false,
			'max_fallbacks'   => 0,
		)
	);
	$assert( ! is_wp_error( $quality_result ), 'quality-import-writes-theme-for-inspection', is_wp_error( $quality_result ) ? $quality_result->get_error_message() : '' );
	if ( ! is_wp_error( $quality_result ) ) {
		$quality_report = json_decode( $read( $quality_result['report_path'] ), true );
		$assert( 1 === ( $quality_result['quality']['fallback_count'] ?? 0 ), 'quality-result-counts-fallbacks' );
		$assert( false === ( $quality_result['quality']['pass'] ?? true ), 'quality-result-fails-when-fallbacks-exist' );
		$assert( true === ( $quality_result['quality']['fail_import'] ?? false ), 'quality-gate-fails-when-max-fallbacks-exceeded' );
		$assert( is_array( $quality_report ) && 'unsupported_html_fallback' === ( $quality_report['diagnostics'][0]['type'] ?? '' ), 'quality-report-records-fallback-diagnostic' );
	}
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

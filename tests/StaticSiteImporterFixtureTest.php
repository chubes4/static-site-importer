<?php
/**
 * End-to-end fixture tests for static site import.
 *
 * @package StaticSiteImporter
 */

/**
 * Tests the generated block-theme output for the bundled fixture.
 */
class StaticSiteImporterFixtureTest extends WP_UnitTestCase {

	/**
	 * Imports the full fixture as a block theme and verifies the generated site shape.
	 */
	public function test_wordpress_is_dead_fixture_imports_as_block_theme(): void {
		$plugin_root = dirname( __DIR__ );
		$fixture_dir = $plugin_root . '/tests/fixtures/wordpress-is-dead';
		$fixture     = $fixture_dir . '/index.html';

		foreach ( array( 'index.html', 'manifesto.html', 'comparison.html', 'eulogy.html', 'proof.html', 'styles.css' ) as $file ) {
			$this->assertFileExists( $fixture_dir . '/' . $file, 'Fixture file missing: ' . $file );
		}

		$result = Static_Site_Importer_Theme_Generator::import_theme(
			$fixture,
			array(
				'name'      => 'WordPress Is Dead Fixture',
				'slug'      => 'wordpress-is-dead-fixture',
				'overwrite' => true,
				'activate'  => false,
				'report'    => trailingslashit( get_temp_dir() ) . 'static-site-importer-fixture-report.json',
			)
		);

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );

		$theme_dir  = $result['theme_dir'];
		$front_page = $this->read_file( $theme_dir . '/templates/front-page.html' );
		$page       = $this->read_file( $theme_dir . '/templates/page.html' );
		$header     = $this->read_file( $theme_dir . '/parts/header.html' );
		$footer     = $this->read_file( $theme_dir . '/parts/footer.html' );
		$style      = $this->read_file( $theme_dir . '/style.css' );
		$functions  = $this->read_file( $theme_dir . '/functions.php' );
		$theme_json = json_decode( $this->read_file( $theme_dir . '/theme.json' ), true );
		$home_tmpl  = $this->read_file( $theme_dir . '/templates/page-home.html' );
		$proof_tmpl = $this->read_file( $theme_dir . '/templates/page-proof.html' );
		$home_pat   = $this->read_file( $theme_dir . '/patterns/page-home.php' );
		$proof_pat  = $this->read_file( $theme_dir . '/patterns/page-proof.php' );

		$this->assertStringContainsString( 'wordpress-is-dead-fixture/page-home', $front_page );
		$this->assertStringContainsString( '<!-- wp:post-content', $page );
		$this->assertStringContainsString( 'wordpress-is-dead-fixture/page-home', $home_tmpl );
		$this->assertStringContainsString( 'wordpress-is-dead-fixture/page-proof', $proof_tmpl );
		$this->assertStringContainsString( 'Slug: wordpress-is-dead-fixture/page-home', $home_pat );
		$this->assertStringContainsString( 'Slug: wordpress-is-dead-fixture/page-proof', $proof_pat );
		$this->assertStringNotContainsString( '<!-- wp:html /-->', $front_page );

		$this->assertStringContainsString( 'WordPress', $header );
		$this->assertStringNotContainsString( 'href="index.html"', $header );
		$this->assertStringContainsString( '<!-- wp:navigation ', $header );
		$this->assertStringContainsString( '"ref":', $header );
		$this->assertStringNotContainsString( '<!-- wp:navigation-link ', $header );

		$header_nav = get_page_by_path( 'wordpress-is-dead-fixture-header-navigation', OBJECT, 'wp_navigation' );
		$footer_nav = get_page_by_path( 'wordpress-is-dead-fixture-footer-navigation', OBJECT, 'wp_navigation' );
		$this->assertInstanceOf( WP_Post::class, $header_nav );
		$this->assertInstanceOf( WP_Post::class, $footer_nav );
		$this->assertStringContainsString( '"ref":' . $header_nav->ID, $header );
		$this->assertStringContainsString( '<!-- wp:navigation-link ', $header_nav->post_content );
		$this->assertSame( 1, substr_count( $header_nav->post_content, '"label":"Manifesto"' ) );
		$this->assertStringContainsString( '"ref":' . $footer_nav->ID, $footer );
		$this->assertStringContainsString( '<!-- wp:navigation-link ', $footer_nav->post_content );

		$this->assertStringContainsString( '--accent', $style );
		$this->assertStringContainsString( '.wp-block-button.btn > .wp-block-button__link', $style );
		$this->assertStringContainsString( "add_theme_support( 'editor-styles' )", $functions );
		$this->assertStringContainsString( "add_editor_style( 'style.css' )", $functions );
		$this->assertStringContainsString( "add_action( 'enqueue_block_editor_assets'", $functions );
		$this->assertIsArray( $theme_json );

		$palette = array();
		foreach ( $theme_json['settings']['color']['palette'] ?? array() as $color ) {
			if ( isset( $color['slug'] ) ) {
				$palette[ $color['slug'] ] = $color;
			}
		}
		$this->assertSame( '#0a0a0a', $palette['bg']['color'] ?? '' );
		$this->assertSame( '#f4f4f0', $palette['fg']['color'] ?? '' );
		$this->assertSame( '#ff3b1f', $palette['accent']['color'] ?? '' );
		$this->assertArrayNotHasKey( 'max', $palette );

		$this->assertArrayHasKey( 'index.html', $result['pages'] );
		$this->assertArrayHasKey( 'manifesto.html', $result['pages'] );
		$this->assertArrayHasKey( 'comparison.html', $result['pages'] );
		$this->assertArrayHasKey( 'eulogy.html', $result['pages'] );
		$this->assertArrayHasKey( 'proof.html', $result['pages'] );
		$this->assertNotEmpty( $result['report_path'] );
		$this->assertFileExists( $result['report_path'] );
		$this->assertSame( trailingslashit( get_temp_dir() ) . 'static-site-importer-fixture-report.json', $result['external_report_path'] );
		$this->assertFileExists( $result['external_report_path'] );

		$report          = json_decode( $this->read_file( $result['report_path'] ), true );
		$external_report = json_decode( $this->read_file( $result['external_report_path'] ), true );
		$this->assertIsArray( $report );
		$this->assertSame( $report, $external_report );
		$this->assertSame( 1, $report['version'] ?? 0 );
		$this->assertArrayHasKey( 'quality', $report );
		$this->assertArrayHasKey( 'conversion_fragments', $report );
		$this->assertArrayHasKey( 'generated_theme', $report );
		$this->assertArrayHasKey( 'diagnostics', $report );
		$this->assertSame( 0, $report['quality']['invalid_block_count'] ?? null );
		$this->assertSame( 0, $report['quality']['invalid_block_document_count'] ?? null );
		$this->assertNotEmpty( $report['generated_theme']['block_documents'] ?? array() );

		$pages = array();
		foreach ( array( 'index.html', 'manifesto.html', 'comparison.html', 'eulogy.html', 'proof.html' ) as $filename ) {
			$post = get_post( $result['pages'][ $filename ] ?? 0 );
			$this->assertInstanceOf( WP_Post::class, $post, 'Missing imported page post for ' . $filename );
			$slug         = 'index.html' === $filename ? 'home' : preg_replace( '/\.html?$/i', '', $filename );
			$pattern_body = $this->pattern_blocks( $this->read_file( $theme_dir . '/patterns/page-' . $slug . '.php' ) );
			$this->assertStringContainsString( 'Imported page layout lives in this page', $post->post_content );
			$this->assertFalse( $this->contains_selector( $post->post_content, '.hero' ) );
			$this->assertNotEmpty( parse_blocks( $pattern_body ), 'Pattern block parse failed for ' . $filename );
			$pages[ $filename ] = array(
				'stored'   => $pattern_body,
				'rendered' => do_blocks( $pattern_body ),
			);
		}

		$this->assertStringNotContainsString( 'href="index.html"', $pages['proof.html']['stored'] );
		$this->assert_selector_matrix( $pages );
		$this->assertSame( 1, $this->selector_count( $pages['comparison.html']['stored'], '.compare' ) );
		$this->assertSame( 1, $this->selector_count( $pages['comparison.html']['stored'], '.col-wp' ) );
		$this->assertSame( 1, $this->selector_count( $pages['comparison.html']['stored'], '.col-claude' ) );
		$this->assertSame( 1, $this->selector_count( $pages['eulogy.html']['stored'], '.eulogy-frame' ) );
		$this->assertSame( 1, $this->selector_count( $pages['eulogy.html']['stored'], '.dates' ) );

		$second_result = Static_Site_Importer_Theme_Generator::import_theme(
			$fixture,
			array(
				'name'      => 'WordPress Is Dead Fixture',
				'slug'      => 'wordpress-is-dead-fixture',
				'overwrite' => true,
				'activate'  => false,
			)
		);
		$this->assertNotWPError( $second_result );
		$this->assertSame( $header_nav->ID, get_page_by_path( 'wordpress-is-dead-fixture-header-navigation', OBJECT, 'wp_navigation' )->ID );
		$this->assertSame( $footer_nav->ID, get_page_by_path( 'wordpress-is-dead-fixture-footer-navigation', OBJECT, 'wp_navigation' )->ID );
	}

	/**
	 * Leading page navigation before a hero/header belongs in the shared header part.
	 */
	public function test_leading_nav_before_header_is_preserved_in_header_part(): void {
		$html_path = $this->write_temp_fixture(
			'leading-nav-header.html',
			'<!doctype html><html><head><title>Leading Nav Header</title></head><body>' .
			'<nav><div class="nav-logo">Studio Code</div><div class="nav-badge">Early Access</div><a href="#get-started" class="nav-cta">Get Started</a></nav>' .
			'<header class="hero"><h1>Launch with Studio</h1><p>Hero copy.</p></header>' .
			'<main><section id="get-started"><h2>Get started</h2><p>Body copy.</p></section></main>' .
			'</body></html>'
		);

		$result = Static_Site_Importer_Theme_Generator::import_theme(
			$html_path,
			array(
				'name'      => 'Leading Nav Header',
				'slug'      => 'leading-nav-header',
				'overwrite' => true,
				'activate'  => false,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );

		$theme_dir = $result['theme_dir'];
		$header    = $this->read_file( $theme_dir . '/parts/header.html' );

		$this->assertStringContainsString( 'Studio Code', $header );
		$this->assertStringContainsString( 'Early Access', $header );
		$this->assertStringContainsString( 'Get Started', $header );
		$this->assertStringContainsString( 'Launch with Studio', $header );
	}

	/**
	 * A top-level nav can contain brand chrome plus a nested menu list.
	 */
	public function test_branded_top_level_nav_preserves_brand_and_converts_only_menu_list(): void {
		$html_path = $this->write_temp_fixture(
			'branded-nav-header.html',
			'<!doctype html><html><head><title>Branded Nav Header</title></head><body>' .
			'<nav class="nav-shell">' .
			'<a href="#" class="nav-brand"><div class="nav-logo">SC</div><span class="nav-name">Studio Code</span><span class="nav-badge">New</span></a>' .
			'<ul class="nav-links"><li><a href="#benefits">Benefits</a></li><li><a href="#workflow">How it works</a></li><li><a href="#use-cases">Use cases</a></li><li><a href="#cta" class="nav-cta">Get started</a></li></ul>' .
			'</nav>' .
			'<main><section id="benefits"><h1>Benefits</h1><p>Body copy.</p></section></main>' .
			'</body></html>'
		);

		$result = Static_Site_Importer_Theme_Generator::import_theme(
			$html_path,
			array(
				'name'      => 'Branded Nav Header',
				'slug'      => 'branded-nav-header',
				'overwrite' => true,
				'activate'  => false,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );

		$theme_dir = $result['theme_dir'];
		$header    = $this->read_file( $theme_dir . '/parts/header.html' );
		$nav_post  = get_page_by_path( 'branded-nav-header-header-navigation', OBJECT, 'wp_navigation' );

		$this->assertStringContainsString( 'nav-brand', $header );
		$this->assertStringContainsString( 'nav-logo', $header );
		$this->assertStringContainsString( 'Studio Code', $header );
		$this->assertStringContainsString( 'New', $header );
		$this->assertStringContainsString( '<!-- wp:navigation ', $header );
		$this->assertStringNotContainsString( '"tagName":"nav"', $header );
		$this->assertStringNotContainsString( '<!-- wp:navigation-link ', $header );
		$this->assertInstanceOf( WP_Post::class, $nav_post );
		$this->assertStringContainsString( '"label":"Benefits"', $nav_post->post_content );
		$this->assertStringContainsString( '"label":"How it works"', $nav_post->post_content );
		$this->assertStringContainsString( '"label":"Use cases"', $nav_post->post_content );
		$this->assertStringContainsString( '"label":"Get started"', $nav_post->post_content );
		$this->assertStringNotContainsString( 'Studio Code', $nav_post->post_content );
		$this->assertStringNotContainsString( 'SC', $nav_post->post_content );
	}

	/**
	 * Pure top-level nav fragments still become a reusable navigation entity.
	 */
	public function test_pure_top_level_nav_still_converts_to_navigation_entity(): void {
		$html_path = $this->write_temp_fixture(
			'pure-nav-header.html',
			'<!doctype html><html><head><title>Pure Nav Header</title></head><body>' .
			'<nav class="top-nav"><a href="#intro">Intro</a><a href="#pricing"><span>Pricing</span></a></nav>' .
			'<main><section id="intro"><h1>Intro</h1><p>Body copy.</p></section></main>' .
			'</body></html>'
		);

		$result = Static_Site_Importer_Theme_Generator::import_theme(
			$html_path,
			array(
				'name'      => 'Pure Nav Header',
				'slug'      => 'pure-nav-header',
				'overwrite' => true,
				'activate'  => false,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );

		$theme_dir = $result['theme_dir'];
		$header    = $this->read_file( $theme_dir . '/parts/header.html' );
		$nav_post  = get_page_by_path( 'pure-nav-header-header-navigation', OBJECT, 'wp_navigation' );

		$this->assertStringContainsString( '<!-- wp:navigation ', $header );
		$this->assertStringContainsString( '"className":"top-nav"', $header );
		$this->assertStringNotContainsString( '<!-- wp:navigation-link ', $header );
		$this->assertStringNotContainsString( '"tagName":"nav"', $header );
		$this->assertInstanceOf( WP_Post::class, $nav_post );
		$this->assertStringContainsString( '"label":"Intro"', $nav_post->post_content );
		$this->assertStringContainsString( '"label":"Pricing"', $nav_post->post_content );
	}

	/**
	 * Nested section headers are page content, not shared site chrome.
	 */
	public function test_nested_section_header_stays_in_page_content(): void {
		$html_path = $this->write_temp_fixture(
			'nested-section-header.html',
			'<!doctype html><html><head><title>Nested Section Header</title></head><body>' .
			'<main><section class="proof"><div class="proof-inner"><header class="proof-header">' .
			'<p class="section-label">Why It Actually Works</p>' .
			'<h2 class="proof-heading reveal">Six reasons the assumptions are wrong.</h2>' .
			'</header><p class="proof-copy">The section body stays with the section.</p></div></section></main>' .
			'</body></html>'
		);

		$result = Static_Site_Importer_Theme_Generator::import_theme(
			$html_path,
			array(
				'name'      => 'Nested Section Header',
				'slug'      => 'nested-section-header',
				'overwrite' => true,
				'activate'  => false,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );

		$theme_dir = $result['theme_dir'];
		$header    = $this->read_file( $theme_dir . '/parts/header.html' );
		$pattern   = $this->pattern_blocks( $this->read_file( $theme_dir . '/patterns/page-nested-section-header.php' ) );

		$this->assertStringNotContainsString( 'proof-header', $header );
		$this->assertStringNotContainsString( 'Six reasons the assumptions are wrong.', $header );
		$this->assertStringContainsString( 'proof-header', $pattern );
		$this->assertStringContainsString( 'Why It Actually Works', $pattern );
		$this->assertStringContainsString( 'Six reasons the assumptions are wrong.', $pattern );
	}

	/**
	 * Footer-like content inside a page section is content, not an empty shared footer part.
	 */
	public function test_nested_footer_bar_does_not_emit_empty_footer_part(): void {
		$html_path = $this->write_temp_fixture(
			'nested-footer-bar.html',
			'<!doctype html><html><head><title>Nested Footer Bar</title></head><body>' .
			'<nav class="top-nav"><a href="/">Home</a></nav>' .
			'<main><section class="cta"><h1>Ship the site</h1><p>CTA body.</p><div class="footer-bar"><span>Fine print stays with the CTA.</span></div></section></main>' .
			'</body></html>'
		);

		$result = Static_Site_Importer_Theme_Generator::import_theme(
			$html_path,
			array(
				'name'      => 'Nested Footer Bar',
				'slug'      => 'nested-footer-bar',
				'overwrite' => true,
				'activate'  => false,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );

		$theme_dir = $result['theme_dir'];
		$pattern   = $this->pattern_blocks( $this->read_file( $theme_dir . '/patterns/page-nested-footer-bar.php' ) );
		$page      = $this->read_file( $theme_dir . '/templates/page.html' );
		$template  = $this->read_file( $theme_dir . '/templates/page-nested-footer-bar.html' );
		$report    = json_decode( $this->read_file( $result['report_path'] ), true );

		$this->assertFileDoesNotExist( $theme_dir . '/parts/footer.html' );
		$this->assertStringNotContainsString( '"slug":"footer"', $page );
		$this->assertStringNotContainsString( '"slug":"footer"', $template );
		$this->assertStringContainsString( 'footer-bar', $pattern );
		$this->assertStringContainsString( 'Fine print stays with the CTA.', $pattern );
		$this->assertNotContains( 'parts/footer.html', $report['visual_fidelity']['comparison_targets'][0]['comparison_hooks']['generated_files'] ?? array() );
	}

	/**
	 * Source button styles are moved to the inner link without restyling the core/button wrapper.
	 */
	public function test_source_button_class_styles_do_not_double_apply_to_core_button_wrapper(): void {
		$html_path = $this->write_temp_fixture(
			'button-wrapper-style.html',
			'<!doctype html><html><head><title>Button Wrapper Style</title><style>' .
			'.nav-cta { background: var(--accent); color: #fff; padding: 10px 24px; border-radius: 100px; transition: transform 0.08s ease; }' .
			'.nav-cta:hover { transform: translateY(-1px); }' .
			'a.nav-cta:focus-visible { outline: 2px solid var(--accent); }' .
			'.btn-primary { background: var(--acid); color: var(--ink); padding: 0.9rem 2.25rem; border-radius: 8px; display: inline-flex; }' .
			'.btn-ghost { color: var(--paper-dim); border-bottom: 1px solid var(--slate-border); padding-bottom: 2px; }' .
			'.btn-outline { border: 1px solid var(--slate-border); color: var(--paper-dim); padding: 0.85rem 1.75rem; border-radius: 8px; }' .
			'</style></head><body>' .
			'<main><h1>Button Wrapper Style</h1><p><a href="#try" class="btn nav-cta">Request Access</a></p>' .
			'<p><a href="#primary" class="btn-primary">Start Building</a> <a href="#ghost" class="btn-ghost">Watch Demo</a> <a href="#outline" class="btn-outline">Read Docs</a></p></main>' .
			'</body></html>'
		);

		$result = Static_Site_Importer_Theme_Generator::import_theme(
			$html_path,
			array(
				'name'      => 'Button Wrapper Style',
				'slug'      => 'button-wrapper-style',
				'overwrite' => true,
				'activate'  => false,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );

		$theme_dir = $result['theme_dir'];
		$pattern   = $this->pattern_blocks( $this->read_file( $theme_dir . '/patterns/page-button-wrapper-style.php' ) );
		$style     = $this->read_file( $theme_dir . '/style.css' );

		$this->assertStringContainsString( '<div class="wp-block-button btn nav-cta">', $pattern );
		$this->assertStringContainsString( '<div class="wp-block-button btn-primary">', $pattern );
		$this->assertStringContainsString( '<div class="wp-block-button btn-ghost">', $pattern );
		$this->assertStringContainsString( '<div class="wp-block-button btn-outline">', $pattern );
		$this->assertStringContainsString( '.nav-cta:not(.wp-block-button)', $style );
		$this->assertStringContainsString( '.btn-primary:not(.wp-block-button)', $style );
		$this->assertStringContainsString( '.btn-ghost:not(.wp-block-button)', $style );
		$this->assertStringContainsString( '.btn-outline:not(.wp-block-button)', $style );
		$this->assertStringNotContainsString( '.nav-cta { background: var(--accent); color: #fff; padding: 10px 24px;', $style );
		$this->assertStringContainsString( '.wp-block-button.nav-cta > .wp-block-button__link:where(.wp-element-button)', $style );
		$this->assertStringContainsString( '.wp-block-button.btn-primary > .wp-block-button__link:where(.wp-element-button)', $style );
		$this->assertStringContainsString( '.wp-block-button.btn-ghost > .wp-block-button__link:where(.wp-element-button)', $style );
		$this->assertStringContainsString( '.wp-block-button.btn-outline > .wp-block-button__link:where(.wp-element-button)', $style );
		$this->assertStringContainsString( 'background: transparent; border: 0; border-radius: 0; box-shadow: none; color: inherit; display: inline;', $style );
		$this->assertStringContainsString( 'max-width: none; min-width: 0; padding: 0; text-decoration: inherit; width: auto;', $style );
		$this->assertStringContainsString( '.wp-block-button.nav-cta > .wp-block-button__link { background: var(--accent); color: #fff; padding: 10px 24px;', $style );
		$this->assertStringContainsString( '.wp-block-button.nav-cta > .wp-block-button__link:hover { transform: translateY(-1px); }', $style );
		$this->assertStringContainsString( '.wp-block-button.btn-primary > .wp-block-button__link { background: var(--acid); color: var(--ink); padding: 0.9rem 2.25rem;', $style );
		$this->assertStringContainsString( '.wp-block-button.btn-ghost > .wp-block-button__link { color: var(--paper-dim); border-bottom: 1px solid var(--slate-border); padding-bottom: 2px;', $style );
		$this->assertStringContainsString( '.wp-block-button.btn-outline > .wp-block-button__link { border: 1px solid var(--slate-border); color: var(--paper-dim); padding: 0.85rem 1.75rem;', $style );
		$this->assertStringContainsString( 'a.nav-cta:focus-visible', $style );
	}

	/**
	 * Absolute decorative children inside imported sections keep their source stack in the Site Editor.
	 */
	public function test_absolute_decorative_overlay_stacks_in_site_editor(): void {
		$html_path = $this->write_temp_fixture(
			'absolute-hero-overlay.html',
			'<!doctype html><html><head><title>Absolute Hero Overlay</title><style>' .
			'.hero { min-height: 100vh; display: flex; flex-direction: column; justify-content: flex-end; position: relative; overflow: hidden; }' .
			'.hero-rip { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; font-size: clamp(220px, 42vw, 580px); color: rgba(255, 61, 0, 0.035); pointer-events: none; user-select: none; }' .
			'.hero .container { position: relative; z-index: 1; }' .
			'</style></head><body><main><section class="hero"><div class="hero-rip" aria-hidden="true">RIP</div><div class="container"><h1>WordPress is dead.</h1><p>Hero copy stays above the decorative overlay.</p></div></section></main></body></html>'
		);

		$result = Static_Site_Importer_Theme_Generator::import_theme(
			$html_path,
			array(
				'name'      => 'Absolute Hero Overlay',
				'slug'      => 'absolute-hero-overlay',
				'overwrite' => true,
				'activate'  => false,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );

		$theme_dir = $result['theme_dir'];
		$pattern   = $this->pattern_blocks( $this->read_file( $theme_dir . '/patterns/page-absolute-hero-overlay.php' ) );
		$style     = $this->read_file( $theme_dir . '/style.css' );
		$report    = json_decode( $this->read_file( $result['report_path'] ), true );

		$this->assertStringContainsString( '<!-- wp:group {"className":"hero","tagName":"section"}', $pattern );
		$this->assertStringContainsString( 'hero-rip', $pattern );
		$this->assertStringContainsString( '<!-- wp:group {"className":"container"}', $pattern );
		$this->assertStringContainsString( '.hero-rip { position: absolute;', $style );
		$this->assertStringContainsString( '.hero .container { position: relative; z-index: 1;', $style );
		$this->assertStringContainsString( 'Static Site Importer: let Site Editor wrappers preserve imported absolute overlay stacking.', $style );
		$this->assertStringContainsString( '.editor-styles-wrapper .block-editor-block-list__layout > .wp-block:has(> .hero-rip) { display: contents; }', $style );
		$this->assertSame( 0, $report['quality']['fallback_count'] ?? null );
		$this->assertSame( 0, $report['quality']['core_html_block_count'] ?? null );
		$this->assertSame( 0, $report['quality']['invalid_block_count'] ?? null );
	}

	/**
	 * Empty CSS-only visual layers stay on the frontend without noisy Site Editor group placeholders.
	 */
	public function test_empty_css_only_background_layers_hide_site_editor_placeholders(): void {
		$html_path = $this->write_temp_fixture(
			'empty-css-background-layers.html',
			'<!doctype html><html><head><title>Empty CSS Background Layers</title><style>' .
			'#hero { position: relative; overflow: hidden; min-height: 80vh; }' .
			'.hero-bg { position: absolute; inset: 0; pointer-events: none; }' .
			'.hero-bg-grid { position: absolute; inset: 0; background-image: linear-gradient(rgba(255,255,255,.1) 1px, transparent 1px); background-size: 32px 32px; }' .
			'.hero-bg-glow { position: absolute; inset: 10% auto auto 20%; width: 40vw; height: 40vw; background: radial-gradient(circle, rgba(255,61,0,.4), transparent 70%); }' .
			'.hero-bg-glow2 { position: absolute; right: 5%; bottom: 10%; width: 30vw; height: 30vw; background: radial-gradient(circle, rgba(0,200,255,.35), transparent 70%); }' .
			'.hero-inner { position: relative; z-index: 1; }' .
			'</style></head><body><main><section id="hero"><div class="hero-bg"><div class="hero-bg-grid"></div><div class="hero-bg-glow"></div><div class="hero-bg-glow2"></div></div><div class="hero-inner"><h1>Launch cleanly</h1><p>Content remains editable above decorative layers.</p></div></section></main></body></html>'
		);

		$result = Static_Site_Importer_Theme_Generator::import_theme(
			$html_path,
			array(
				'name'      => 'Empty CSS Background Layers',
				'slug'      => 'empty-css-background-layers',
				'overwrite' => true,
				'activate'  => false,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );

		$theme_dir = $result['theme_dir'];
		$pattern   = $this->pattern_blocks( $this->read_file( $theme_dir . '/patterns/page-empty-css-background-layers.php' ) );
		$style     = $this->read_file( $theme_dir . '/style.css' );
		$report    = json_decode( $this->read_file( $result['report_path'] ), true );

		foreach ( array( 'hero-bg-grid', 'hero-bg-glow', 'hero-bg-glow2' ) as $class_name ) {
			$this->assertStringContainsString( $class_name, $pattern );
			$this->assertStringContainsString( 'wp-block-group ' . $class_name . ' static-site-importer-decorative-layer', $pattern );
			$this->assertStringContainsString( '.editor-styles-wrapper .block-editor-block-list__layout > .wp-block:has(> .' . $class_name . ')', $style );
		}

		$this->assertStringContainsString( 'Static Site Importer: hide empty decorative layer group controls in the Site Editor.', $style );
		$this->assertStringContainsString( '.editor-styles-wrapper .wp-block-group.static-site-importer-decorative-layer .block-editor-block-variation-picker', $style );
		$this->assertStringContainsString( '.editor-styles-wrapper .wp-block-group.static-site-importer-decorative-layer .components-placeholder', $style );
		$this->assertStringContainsString( '.editor-styles-wrapper .wp-block-group.static-site-importer-decorative-layer .block-list-appender', $style );
		$this->assertStringContainsString( '.editor-styles-wrapper .wp-block-group.static-site-importer-decorative-layer .block-editor-button-block-appender', $style );
		$this->assertSame( 0, $report['quality']['fallback_count'] ?? null );
		$this->assertSame( 0, $report['quality']['core_html_block_count'] ?? null );
		$this->assertSame( 0, $report['quality']['invalid_block_count'] ?? null );
	}

	/**
	 * Normal empty groups without decorative CSS are not marked for hidden editor controls.
	 */
	public function test_normal_empty_groups_remain_unmarked_for_editor_controls(): void {
		$reflection = new ReflectionClass( Static_Site_Importer_Theme_Generator::class );

		$classes_property = $reflection->getProperty( 'decorative_empty_group_classes' );
		$classes_property->setAccessible( true );
		$classes_property->setValue( null, array( 'hero-bg-grid' => true ) );

		$mark = $reflection->getMethod( 'mark_empty_decorative_group_blocks' );
		$mark->setAccessible( true );

		$markup = '<!-- wp:group {"className":"normal-empty"} --><div class="wp-block-group normal-empty"></div><!-- /wp:group -->';
		$result = $mark->invoke( null, $markup );

		$this->assertSame( $markup, $result );
		$this->assertStringNotContainsString( 'static-site-importer-decorative-layer', $result );
	}

	/**
	 * Imported fixed/sticky top chrome is offset only when the WordPress admin bar is present.
	 */
	public function test_fixed_top_chrome_gets_admin_bar_offsets(): void {
		$html_path = $this->write_temp_fixture(
			'fixed-top-chrome.html',
			'<!doctype html><html><head><title>Fixed Top Chrome</title><style>' .
			'header.site-header { position: fixed; top: 0; left: 0; right: 0; z-index: 10; }' .
			'.top-nav { position: sticky; top: 12px; z-index: 9; }' .
			'.modal-header { position: fixed; top: 0; }' .
			'.site-footer { position: fixed; top: 0; }' .
			'</style></head><body>' .
			'<header class="site-header"><p>Site title</p><nav class="top-nav"><a href="#content">Content</a></nav></header>' .
			'<main id="content"><h1>Fixed Top Chrome</h1><p>Body copy.</p></main>' .
			'</body></html>'
		);

		$result = Static_Site_Importer_Theme_Generator::import_theme(
			$html_path,
			array(
				'name'      => 'Fixed Top Chrome',
				'slug'      => 'fixed-top-chrome',
				'overwrite' => true,
				'activate'  => false,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );

		$style = $this->read_file( $result['theme_dir'] . '/style.css' );

		$this->assertStringContainsString( 'Static Site Importer: offset imported fixed/sticky top chrome below the WordPress admin bar.', $style );
		$this->assertStringContainsString( 'body.admin-bar header.site-header { top: 32px; }', $style );
		$this->assertStringContainsString( '@media screen and (max-width: 782px) { body.admin-bar header.site-header { top: 46px; } }', $style );
		$this->assertStringContainsString( 'body.admin-bar .top-nav { top: calc(12px + 32px); }', $style );
		$this->assertStringContainsString( '@media screen and (max-width: 782px) { body.admin-bar .top-nav { top: calc(12px + 46px); } }', $style );
		$this->assertStringNotContainsString( 'body.admin-bar .modal-header', $style );
		$this->assertStringNotContainsString( 'body.admin-bar .site-footer', $style );
	}

	/**
	 * Static headers are not offset just because they look like top chrome.
	 */
	public function test_static_header_does_not_get_admin_bar_offsets(): void {
		$html_path = $this->write_temp_fixture(
			'static-header.html',
			'<!doctype html><html><head><title>Static Header</title><style>' .
			'header.site-header { position: relative; top: 0; }' .
			'.top-nav { display: flex; gap: 1rem; }' .
			'</style></head><body>' .
			'<header class="site-header"><nav class="top-nav"><a href="#content">Content</a></nav></header>' .
			'<main id="content"><h1>Static Header</h1><p>Body copy.</p></main>' .
			'</body></html>'
		);

		$result = Static_Site_Importer_Theme_Generator::import_theme(
			$html_path,
			array(
				'name'      => 'Static Header',
				'slug'      => 'static-header',
				'overwrite' => true,
				'activate'  => false,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );

		$style = $this->read_file( $result['theme_dir'] . '/style.css' );

		$this->assertStringContainsString( 'header.site-header { position: relative; top: 0;', $style );
		$this->assertStringNotContainsString( 'Static Site Importer: offset imported fixed/sticky top chrome below the WordPress admin bar.', $style );
		$this->assertStringNotContainsString( 'body.admin-bar header.site-header', $style );
		$this->assertStringNotContainsString( 'body.admin-bar .top-nav', $style );
	}

	/**
	 * Safe inline SVG icons are materialized as theme assets and native image blocks.
	 */
	public function test_safe_inline_svg_icons_materialize_as_theme_assets(): void {
		$html_path = $this->write_temp_fixture(
			'safe-svg-icons.html',
			'<!doctype html><html><head><title>Safe SVG Icons</title></head><body><main><section class="icons"><h1>Icons</h1><svg class="icon icon-bolt" viewBox="0 0 24 24" width="24" height="24" role="img" aria-label="Bolt"><title>Bolt</title><path d="M13 2 3 14h8l-1 8 11-13h-8z" fill="currentColor"/></svg></section></main></body></html>'
		);

		$result = Static_Site_Importer_Theme_Generator::import_theme(
			$html_path,
			array(
				'name'      => 'Safe SVG Icons',
				'slug'      => 'safe-svg-icons',
				'overwrite' => true,
				'activate'  => false,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );

		$theme_dir = $result['theme_dir'];
		$pattern   = $this->pattern_blocks( $this->read_file( $theme_dir . '/patterns/page-safe-svg-icons.php' ) );
		$report    = json_decode( $this->read_file( $result['report_path'] ), true );

		$this->assertStringContainsString( '<!-- wp:image ', $pattern );
		$this->assertStringNotContainsString( '<!-- wp:html', $pattern );
		$this->assertStringContainsString( '/assets/icons/', $pattern );
		$this->assertSame( 0, $report['quality']['fallback_count'] ?? null );
		$this->assertSame( 0, $report['quality']['unsafe_svg_count'] ?? null );
		$this->assertNotEmpty( $report['assets']['svg_icons'] ?? array() );

		$asset = $report['assets']['svg_icons'][0] ?? array();
		$this->assertSame( 'core/image', $asset['block'] ?? '' );
		$this->assertFileExists( $theme_dir . '/' . ( $asset['path'] ?? '' ) );
	}

	/**
	 * Unsafe inline SVG remains visible in the import report instead of being accepted silently.
	 */
	public function test_unsafe_inline_svg_is_reported(): void {
		$html_path = $this->write_temp_fixture(
			'unsafe-svg-icons.html',
			'<!doctype html><html><head><title>Unsafe SVG Icons</title></head><body><main><section><h1>Unsafe</h1><svg viewBox="0 0 24 24"><script>alert(1)</script><path d="M0 0h24v24H0z"/></svg></section></main></body></html>'
		);

		$result = Static_Site_Importer_Theme_Generator::import_theme(
			$html_path,
			array(
				'name'      => 'Unsafe SVG Icons',
				'slug'      => 'unsafe-svg-icons',
				'overwrite' => true,
				'activate'  => false,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );

		$report = json_decode( $this->read_file( $result['report_path'] ), true );
		$this->assertSame( 1, $report['quality']['unsafe_svg_count'] ?? null );
		$this->assertContains( 'unsafe_inline_svg', $report['quality']['failure_reasons'] ?? array() );
		$this->assertNotEmpty(
			array_filter(
				$report['diagnostics'] ?? array(),
				static fn ( array $diagnostic ): bool => 'unsafe_inline_svg' === ( $diagnostic['type'] ?? '' )
			)
		);
	}

	/**
	 * Source cleanup is the default after a clean import.
	 */
	public function test_source_directory_is_deleted_after_clean_import(): void {
		$html_path  = $this->write_temp_fixture(
			'index.html',
			'<!doctype html><html><head><title>Clean Import</title></head><body><main><h1>Clean Import</h1><p>Body copy.</p></main></body></html>'
		);
		$source_dir = dirname( $html_path );

		$result = Static_Site_Importer_Theme_Generator::import_theme(
			$html_path,
			array(
				'name'          => 'Clean Import Cleanup',
				'slug'          => 'clean-import-cleanup',
				'overwrite'     => true,
				'activate'      => false,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertTrue( $result['quality']['pass'] ?? false );
		$this->assertTrue( $result['source_deleted'] ?? false );
		$this->assertSame( '', $result['source_cleanup_error'] ?? null );
		$this->assertFalse( file_exists( $source_dir ), 'Clean import source directory should be deleted.' );
	}

	/**
	 * Developers can keep source artifacts for debugging and iteration.
	 */
	public function test_keep_source_preserves_source_directory_after_clean_import(): void {
		$html_path  = $this->write_temp_fixture(
			'index.html',
			'<!doctype html><html><head><title>Kept Import</title></head><body><main><h1>Kept Import</h1><p>Body copy.</p></main></body></html>'
		);
		$source_dir = dirname( $html_path );

		$result = Static_Site_Importer_Theme_Generator::import_theme(
			$html_path,
			array(
				'name'        => 'Kept Import Source',
				'slug'        => 'kept-import-source',
				'overwrite'   => true,
				'activate'    => false,
				'keep_source' => true,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertTrue( $result['quality']['pass'] ?? false );
		$this->assertFalse( $result['source_deleted'] ?? true );
		$this->assertSame( '', $result['source_cleanup_error'] ?? null );
		$this->assertTrue( file_exists( $source_dir ), 'Clean import source directory should be preserved when requested.' );
	}

	/**
	 * Broken imports always keep source artifacts.
	 */
	public function test_source_directory_is_preserved_when_quality_fails(): void {
		$html_path  = $this->write_temp_fixture(
			'index.html',
			'<!doctype html><html><head><title>Broken Import</title></head><body><main><h1>Broken Import</h1><svg viewBox="0 0 24 24"><script>alert(1)</script><path d="M0 0h24v24H0z"/></svg></main></body></html>'
		);
		$source_dir = dirname( $html_path );

		$result = Static_Site_Importer_Theme_Generator::import_theme(
			$html_path,
			array(
				'name'          => 'Broken Import Cleanup',
				'slug'          => 'broken-import-cleanup',
				'overwrite'     => true,
				'activate'      => false,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertFalse( $result['quality']['pass'] ?? true );
		$this->assertFalse( $result['source_deleted'] ?? true );
		$this->assertSame( 'import quality checks reported issues', $result['source_cleanup_error'] ?? '' );
		$this->assertTrue( file_exists( $source_dir ), 'Failed-quality import source directory should be preserved.' );
	}

	/**
	 * Server-visible malformed block documents are counted in generated-theme quality.
	 */
	public function test_generated_theme_quality_reports_malformed_block_documents(): void {
		$reflection = new ReflectionClass( Static_Site_Importer_Theme_Generator::class );

		$new_report = $reflection->getMethod( 'new_conversion_report' );
		$new_report->setAccessible( true );

		$report_property = $reflection->getProperty( 'conversion_report' );
		$report_property->setAccessible( true );
		$report_property->setValue( null, $new_report->invoke( null, '/tmp/source/index.html' ) );

		$analyze = $reflection->getMethod( 'analyze_generated_theme_block_documents' );
		$analyze->setAccessible( true );
		$analyze->invoke(
			null,
			array(
				'/tmp/generated/templates/front-page.html' => '<!-- wp:paragraph --><p>Missing closer</p>',
			),
			'/tmp/generated'
		);

		$finalize = $reflection->getMethod( 'finalize_quality_report' );
		$finalize->setAccessible( true );
		$quality = $finalize->invoke( null, array() );
		$report  = $report_property->getValue();

		$this->assertSame( 1, $quality['invalid_block_count'] ?? null );
		$this->assertSame( 1, $quality['invalid_block_document_count'] ?? null );
		$this->assertContains( 'invalid_block', $quality['failure_reasons'] ?? array() );
		$this->assertSame( 'invalid_block_document', $report['diagnostics'][0]['type'] ?? '' );
	}

	/**
	 * Reads a generated file.
	 */
	private function read_file( string $path ): string {
		$contents = file_get_contents( $path );
		$this->assertNotFalse( $contents, 'Unable to read ' . $path );

		return (string) $contents;
	}

	/**
	 * Writes a temporary single-file static site fixture.
	 */
	private function write_temp_fixture( string $filename, string $html ): string {
		$dir = trailingslashit( get_temp_dir() ) . 'static-site-importer-fixtures-' . wp_generate_uuid4();
		$this->assertTrue( wp_mkdir_p( $dir ) );
		$path = trailingslashit( $dir ) . $filename;
		$this->assertNotFalse( file_put_contents( $path, $html ) );

		return $path;
	}

	/**
	 * Extracts block content from a pattern file.
	 */
	private function pattern_blocks( string $pattern_file ): string {
		$parts = explode( '?>', $pattern_file, 2 );

		return trim( 2 === count( $parts ) ? $parts[1] : $pattern_file );
	}

	/**
	 * Counts blocks/elements containing every selector class token.
	 */
	private function selector_count( string $content, string $selector ): int {
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
	}

	/**
	 * Whether content contains every selector class token.
	 */
	private function contains_selector( string $content, string $selector ): bool {
		return $this->selector_count( $content, $selector ) > 0;
	}

	/**
	 * Verifies source classes survive storage and render paths.
	 *
	 * @param array<string,array{stored:string,rendered:string}> $pages Page content by fixture filename.
	 */
	private function assert_selector_matrix( array $pages ): void {
		$selector_expectations = array(
			'index.html'      => array( '.hero', '.block', '.block.alt', '.container', '.lede', '.pull' ),
			'manifesto.html'  => array( '.hero', '.block', '.container', '.lede', '.manifesto-list', '.pull' ),
			'comparison.html' => array( '.hero', '.block', '.container', '.lede', '.compare', '.col-wp', '.col-claude' ),
			'eulogy.html'     => array( '.hero', '.block', '.container', '.lede', '.eulogy-frame', '.dates', '.pull' ),
			'proof.html'      => array( '.hero', '.block', '.block.alt', '.container', '.lede', '.prompt', '.pull' ),
		);

		foreach ( $selector_expectations as $filename => $selectors ) {
			foreach ( array_unique( $selectors ) as $selector ) {
				$this->assertTrue( $this->contains_selector( $pages[ $filename ]['stored'] ?? '', $selector ), 'Stored content lost selector ' . $filename . ' ' . $selector );
				$this->assertTrue( $this->contains_selector( $pages[ $filename ]['rendered'] ?? '', $selector ), 'Rendered content lost selector ' . $filename . ' ' . $selector );
			}
		}
	}
}

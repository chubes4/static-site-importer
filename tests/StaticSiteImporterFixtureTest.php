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
		$this->assertArrayHasKey( 'diagnostics', $report );

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
	 * Reads a generated file.
	 */
	private function read_file( string $path ): string {
		$contents = file_get_contents( $path );
		$this->assertNotFalse( $contents, 'Unable to read ' . $path );

		return (string) $contents;
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

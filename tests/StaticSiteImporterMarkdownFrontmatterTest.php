<?php
/**
 * Markdown frontmatter import tests.
 *
 * @package StaticSiteImporter
 */

/**
 * Tests Markdown source frontmatter handling.
 */
class StaticSiteImporterMarkdownFrontmatterTest extends WP_UnitTestCase {

	/**
	 * Markdown imports strip frontmatter, map safe metadata, and keep BFB input body-only.
	 */
	public function test_markdown_frontmatter_maps_metadata_and_strips_body(): void {
		$fixture_dir = $this->write_markdown_fixture(
			'valid-frontmatter',
			'index.md',
			"---\ntitle: Frontmatter Landing\nslug: frontmatter-landing\nstatus: draft\n---\n\n# Visible Heading\n\nBody copy."
		);

		$result = Static_Site_Importer_Theme_Generator::import_theme(
			$fixture_dir . '/index.md',
			array(
				'slug'        => 'markdown-frontmatter-valid',
				'overwrite'   => true,
				'activate'    => false,
				'keep_source' => true,
			)
		);

		$this->assertNotWPError( $result );
		$post = get_post( $result['pages']['index.md'] ?? 0 );
		$this->assertInstanceOf( WP_Post::class, $post );
		$this->assertSame( 'Frontmatter Landing', $post->post_title );
		$this->assertSame( 'frontmatter-landing', $post->post_name );
		$this->assertSame( 'draft', $post->post_status );
		$this->assertStringContainsString( 'Visible Heading', $post->post_content );
		$this->assertStringContainsString( 'Body copy.', $post->post_content );
		$this->assertStringNotContainsString( 'title: Frontmatter Landing', $post->post_content );
		$this->assertStringNotContainsString( '---', $post->post_content );
	}

	/**
	 * Markdown without frontmatter imports normally and derives page data from content/path.
	 */
	public function test_markdown_without_frontmatter_imports_normally(): void {
		$fixture_dir = $this->write_markdown_fixture(
			'no-frontmatter',
			'about.md',
			"# About The Import\n\nPlain markdown body."
		);

		$result = Static_Site_Importer_Theme_Generator::import_theme(
			$fixture_dir . '/about.md',
			array(
				'slug'        => 'markdown-frontmatter-none',
				'overwrite'   => true,
				'activate'    => false,
				'keep_source' => true,
			)
		);

		$this->assertNotWPError( $result );
		$post = get_post( $result['pages']['about.md'] ?? 0 );
		$this->assertInstanceOf( WP_Post::class, $post );
		$this->assertSame( 'About The Import', $post->post_title );
		$this->assertSame( 'about', $post->post_name );
		$this->assertSame( 'publish', $post->post_status );
		$this->assertStringContainsString( 'Plain markdown body.', $post->post_content );
	}

	/**
	 * Malformed Markdown frontmatter reports an actionable importer error.
	 */
	public function test_malformed_markdown_frontmatter_reports_error(): void {
		$fixture_dir = $this->write_markdown_fixture(
			'malformed-frontmatter',
			'index.md',
			"---\ntitle Missing Colon\n---\n\n# Body"
		);

		$result = Static_Site_Importer_Theme_Generator::import_theme(
			$fixture_dir . '/index.md',
			array(
				'slug'      => 'markdown-frontmatter-malformed',
				'overwrite' => true,
			)
		);

		$this->assertWPError( $result );
		$this->assertStringContainsString( 'Malformed frontmatter', $result->get_error_message() );
		$this->assertStringContainsString( 'expected "key: value"', $result->get_error_message() );
	}

	/**
	 * Writes a temporary Markdown fixture and returns its directory.
	 */
	private function write_markdown_fixture( string $slug, string $filename, string $markdown ): string {
		$dir = trailingslashit( get_temp_dir() ) . 'static-site-importer-' . $slug . '-' . wp_generate_uuid4();
		$this->assertTrue( wp_mkdir_p( $dir ) );
		$this->assertNotFalse( file_put_contents( trailingslashit( $dir ) . $filename, $markdown ) );

		return $dir;
	}
}

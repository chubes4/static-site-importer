<?php
/**
 * Focused tests for preserving source ID selector contracts in extracted chrome.
 *
 * @package StaticSiteImporter
 */

/**
 * Tests source IDs targeted by CSS survive theme chrome extraction.
 */
class StaticSiteImporterHeroIdChromeTest extends WP_UnitTestCase {

	/**
	 * Source IDs targeted by CSS survive extracted theme chrome as native anchors.
	 */
	public function test_header_id_selector_survives_theme_chrome_extraction(): void {
		$plugin_root = dirname( __DIR__ );
		$fixture     = $plugin_root . '/tests/fixtures/hero-id-chrome/index.html';

		$result = Static_Site_Importer_Theme_Generator::import_theme(
			$fixture,
			array(
				'name'      => 'Hero ID Chrome',
				'slug'      => 'hero-id-chrome',
				'overwrite' => true,
				'activate'  => false,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );

		$theme_dir = $result['theme_dir'];
		$header    = $this->read_file( $theme_dir . '/parts/header.html' );
		$style     = $this->read_file( $theme_dir . '/style.css' );

		$this->assertStringContainsString( 'header#hero', $style );
		$this->assertStringContainsString( '"anchor":"hero"', $header );
		$this->assertStringContainsString( '<header id="hero" class="wp-block-group hero-shell">', $header );
		$this->assertStringContainsString( '<!-- wp:navigation ', $header );
		$this->assertStringNotContainsString( '<!-- wp:html -->', $header );
	}

	/**
	 * Reads a generated file.
	 */
	private function read_file( string $path ): string {
		$contents = file_get_contents( $path );
		$this->assertNotFalse( $contents, 'Unable to read ' . $path );

		return (string) $contents;
	}
}

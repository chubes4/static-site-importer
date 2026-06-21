<?php
/**
 * Smoke test: SSI transformer adapter maps Blocks Engine php-transformer output.
 *
 * Run from the repository root:
 * php tests/smoke-transformer-adapter.php
 *
 * @package StaticSiteImporter
 */

namespace {
	function blocks_engine_php_transformer_compile_artifact( array $artifact, array $options = array() ): array {
		$GLOBALS['ssi_transformer_adapter_artifact_compiler_calls'][] = array( $artifact, $options );

		return array(
						'schema'            => 'blocks-engine/php-transformer/result/v1',
						'status'            => 'success',
						'components'        => array(),
						'block_types'       => array(
							array( 'name' => 'core/paragraph' ),
						),
						'source_reports'    => array(
							'artifact'      => array(
								'schema'          => 'blocks-engine/php-transformer/site-artifact/v1',
								'original_schema' => 'block-artifact-compiler/website-artifact/v1',
								'entry_path'      => 'website/index.html',
								'entrypoints'     => array( 'website/index.html' ),
								'file_count'      => 3,
								'accepted_count'  => 3,
								'rejected_count'  => 0,
								'bytes'           => 100,
								'files_by_kind'   => array( 'html' => 2, 'asset' => 1 ),
								'files_by_role'   => array( 'document' => 2, 'stylesheet' => 1 ),
								'files_by_mime'   => array( 'text/html' => 2, 'text/css' => 1 ),
								'source_hash'     => 'abc123',
							),
							'compiled_site' => array(
								'schema'      => 'blocks-engine/php-transformer/compiled-site/v1',
								'source_hash' => 'abc123',
								'entry_path'  => 'website/index.html',
								'pages'       => array(
									array(
										'source_path'  => 'website/index.html',
										'entrypoint'   => true,
										'slug'         => 'index',
										'title'        => 'Home Page',
										'block_markup' => '<!-- wp:paragraph --><p>Home</p><!-- /wp:paragraph -->',
									),
									array(
										'source_path' => 'website/menu.html',
										'entrypoint'  => false,
										'slug'        => 'menu',
										'title'       => 'Menu Page',
									),
									array(
										'source_path' => 'content/about.md',
										'entrypoint'  => false,
										'slug'        => 'about',
										'title'       => 'About',
									),
									array(
										'source_path'    => 'products/rye-loaf.md',
										'entrypoint'     => false,
										'post_type'      => 'product',
										'slug'           => 'rye-loaf',
										'title'          => 'Rye Loaf',
										'regular_price'  => '12',
										'categories'     => array( 'Bread' ),
									),
								),
								'assets'      => array(
									array( 'path' => 'assets/stale-compiled-site.css', 'role' => 'stylesheet' ),
								),
								'routes'      => array(
									array( 'source_path' => 'website/index.html', 'permalink' => '/legacy-home/' ),
								),
								'navigation'  => array(
									'items' => array(
										array( 'label' => 'Legacy Menu', 'url' => '/legacy-menu/' ),
									),
								),
								'template_parts' => array(
									array( 'path' => 'parts/legacy-header.html', 'area' => 'header' ),
								),
								'visual_repair' => array(
									'css' => array(
										array( 'target' => 'front', 'content' => '.legacy-repair{display:block}' ),
									),
								),
								'theme'       => array(
									'stylesheets' => array( 'assets/site.css' ),
								),
							),
							'materialization_plan' => array(
								'schema'      => 'blocks-engine/php-transformer/materialization-plan/v1',
								'source_schema' => 'blocks-engine/php-transformer/compiled-site/v1',
								'source_hash' => 'abc123',
								'entry_path'  => 'website/index.html',
								'pages'       => array(
									array(
										'source_path'  => 'website/index.html',
										'entrypoint'   => true,
										'post_type'    => 'page',
										'slug'         => 'home-canonical',
										'title'        => 'Home Canonical',
										'route_key'    => 'front-page',
										'permalink'    => '/',
										'block_markup' => '<!-- wp:paragraph --><p>Home</p><!-- /wp:paragraph -->',
									),
									array(
										'source_path' => 'website/menu.html',
										'entrypoint'  => false,
										'post_type'   => 'page',
										'slug'        => 'menu',
										'title'       => 'Menu Page',
										'route_key'   => 'menu',
										'permalink'   => '/menu/',
									),
									array(
										'source_path' => 'content/about.md',
										'entrypoint'  => false,
										'post_type'   => 'page',
										'slug'        => 'about-canonical',
										'title'       => 'About',
										'route_key'   => 'about',
										'permalink'   => '/about/',
									),
									array(
										'source_path'    => 'products/rye-loaf.md',
										'entrypoint'     => false,
										'post_type'      => 'product',
										'slug'           => 'rye-loaf-canonical',
										'title'          => 'Rye Loaf',
										'route_key'      => 'product/rye-loaf',
										'permalink'      => '/products/rye-loaf/',
										'regular_price'  => '12',
										'categories'     => array( 'Bread' ),
									),
								),
								'routes'      => array(
									array( 'source_path' => 'website/index.html', 'route_key' => 'front-page', 'permalink' => '/' ),
									array( 'source_path' => 'website/menu.html', 'route_key' => 'menu', 'permalink' => '/menu/' ),
									array( 'source_path' => 'products/rye-loaf.md', 'route_key' => 'product/rye-loaf', 'permalink' => '/products/rye-loaf/' ),
								),
								'navigation'  => array(
									'items' => array(
										array( 'label' => 'Home', 'url' => '/' ),
										array( 'label' => 'Menu', 'url' => '/menu/' ),
										array( 'label' => 'Shop', 'url' => '/products/rye-loaf/' ),
									),
								),
								'assets'      => array(
									array(
										'path'    => 'assets/native-site.css',
										'role'    => 'stylesheet',
										'kind'    => 'css',
										'content' => 'body { color: black; }',
									),
								),
								'template_parts' => array(
									array(
										'path'         => 'parts/header.html',
										'area'         => 'header',
										'block_markup' => '<!-- wp:navigation /-->',
									),
									array(
										'path'         => 'parts/footer.html',
										'area'         => 'footer',
										'block_markup' => '<!-- wp:paragraph --><p>Open daily.</p><!-- /wp:paragraph -->',
									),
								),
								'visual_repair' => array(
									'css' => array(
										array( 'target' => 'front', 'content' => '.native-repair{display:grid}' ),
									),
								),
								'theme'       => array(
									'stylesheets' => array( 'assets/site.css' ),
								),
							),
						),
						'blocks'            => array(
							array( 'blockName' => 'core/paragraph', 'innerBlocks' => array() ),
						),
						'serialized_blocks' => '<!-- wp:paragraph --><p>Legacy top-level Home</p><!-- /wp:paragraph -->',
						'conversion_report' => array(
							'status'            => 'success',
							'serialized_blocks' => '<!-- wp:paragraph --><p>Native report Home</p><!-- /wp:paragraph -->',
							'diagnostics'       => array(
								array(
									'code'    => 'native_report_diagnostic',
									'message' => 'Native conversion report diagnostic.',
								),
							),
							'fallbacks'         => array(
								array(
									'source' => 'native-conversion-report',
									'count'  => 0,
								),
							),
						),
						'documents'         => array(
							array(
								'source_path'  => 'content/about.md',
								'slug'         => 'about',
								'title'        => 'About',
								'block_markup' => '<!-- wp:paragraph --><p>About</p><!-- /wp:paragraph -->',
							),
						),
						'assets'            => array(
							array( 'path' => 'assets/legacy-site.css', 'role' => 'stylesheet' ),
						),
						'diagnostics'       => array(
							array(
								'code'    => 'legacy_top_level_diagnostic',
								'message' => 'Legacy top-level diagnostic.',
							),
						),
						'fallbacks'         => array(
							array(
								'source' => 'legacy-top-level',
								'count'  => 1,
							),
						),
						'legacy_mapping'    => array(
							'block-artifact-compiler/result/v1' => array(
								'wordpress_artifacts.site.pages.0.slug' => 'legacy.slug',
								'wordpress_artifacts.documents.0.source_path' => 'legacy.documents.0.source_path',
							),
						),
						'legacy'            => array(
							'slug'      => 'legacy-home',
							'documents' => array(
								array( 'source_path' => 'legacy/about.html' ),
							),
						),
						'provenance'        => array(
							array( 'source_hash' => 'abc123' ),
						),
		);
	}

	function blocks_engine_php_transformer_convert_format( string $content, string $from, string $to, array $options = array() ): array {
		$GLOBALS['ssi_transformer_adapter_format_bridge_calls'][] = array( $content, $from, $to, $options );
		return array(
			'schema'    => 'blocks-engine/php-transformer/result/v1',
			'status'    => 'success',
			'documents' => array(
				array(
					'format'  => 'html',
					'content' => '<p>FormatBridge rendered</p>',
				),
			),
		);
	}

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', dirname( __DIR__ ) . '/' );
	}

	$GLOBALS['ssi_transformer_adapter_format_bridge_calls'] = array();
	$GLOBALS['ssi_transformer_adapter_artifact_compiler_calls'] = array();

	if ( ! class_exists( 'WP_Error' ) ) {
		class WP_Error {
			private string $code;
			private string $message;

			public function __construct( string $code, string $message ) {
				$this->code    = $code;
				$this->message = $message;
			}

			public function get_error_code(): string {
				return $this->code;
			}

			public function get_error_message(): string {
				return $this->message;
			}
		}
	}

	if ( ! function_exists( 'is_wp_error' ) ) {
		function is_wp_error( mixed $thing ): bool {
			return $thing instanceof WP_Error;
		}
	}

	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-transformer-adapter.php';

	$failures   = array();
	$assertions = 0;
	$assert     = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
		++$assertions;
		if ( ! $condition ) {
			$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
		}
	};

	$adapter = new Static_Site_Importer_Transformer_Adapter();
	$html    = $adapter->blocks_to_html( '<!-- wp:paragraph --><p>Edited</p><!-- /wp:paragraph -->', array( 'source' => 'smoke' ) );
	$assert( '<p>FormatBridge rendered</p>' === $html, 'format-bridge-result-is-used' );
	$assert( 1 === count( $GLOBALS['ssi_transformer_adapter_format_bridge_calls'] ), 'format-bridge-called' );
	$assert( 'blocks' === ( $GLOBALS['ssi_transformer_adapter_format_bridge_calls'][0][1] ?? '' ), 'format-bridge-from-format' );
	$assert( 'html' === ( $GLOBALS['ssi_transformer_adapter_format_bridge_calls'][0][2] ?? '' ), 'format-bridge-to-format' );
	$assert( 'smoke' === ( $GLOBALS['ssi_transformer_adapter_format_bridge_calls'][0][3]['source'] ?? '' ), 'format-bridge-options-forwarded' );

	$compiled  = $adapter->compile_website_artifact( array( 'schema' => 'block-artifact-compiler/website-artifact/v1' ), array( 'include_bfb_report' => true ) );
	$artifacts = $compiled['wordpress_artifacts'] ?? array();
	$site      = $artifacts['site'] ?? array();
	$pages     = $site['pages'] ?? array();
	$documents = $artifacts['documents'] ?? array();
	$products  = $compiled['products_manifest'] ?? array();
	$routes    = $site['routes'] ?? array();
	$navigation_items = $site['navigation']['items'] ?? array();
	$template_parts   = $artifacts['template_parts'] ?? array();
	$visual_repair    = $artifacts['visual_repair'] ?? array();
	$assert( ! is_wp_error( $compiled ), 'native-compile-succeeds' );
	$assert( 1 === count( $GLOBALS['ssi_transformer_adapter_artifact_compiler_calls'] ), 'plugin-artifact-helper-called' );
	$assert( true === ( $GLOBALS['ssi_transformer_adapter_artifact_compiler_calls'][0][1]['include_conversion_report'] ?? false ), 'compile-options-forwarded-as-native-report-request' );
	$assert( ! array_key_exists( 'include_bfb_report', $GLOBALS['ssi_transformer_adapter_artifact_compiler_calls'][0][1] ?? array() ), 'legacy-bfb-option-is-isolated' );
	$assert( 'block-artifact-compiler/result/v1' === ( $compiled['schema'] ?? '' ), 'native-result-mapped-to-bac-envelope' );
	$assert( 'success' === ( $compiled['bfb_report']['status'] ?? '' ), 'legacy-bfb-report-shape-preserved' );
	$assert( '<!-- wp:paragraph --><p>Native report Home</p><!-- /wp:paragraph -->' === ( $compiled['bfb_report']['serialized_blocks'] ?? '' ), 'legacy-bfb-report-uses-native-report-serialized-blocks' );
	$assert( 'native_report_diagnostic' === ( $compiled['bfb_report']['diagnostics'][0]['code'] ?? '' ), 'legacy-bfb-report-uses-native-report-diagnostics' );
	$assert( 'native-conversion-report' === ( $compiled['bfb_report']['fallbacks'][0]['source'] ?? '' ), 'legacy-bfb-report-uses-native-report-fallbacks' );
	$assert( 'legacy_top_level_diagnostic' !== ( $compiled['bfb_report']['diagnostics'][0]['code'] ?? '' ), 'legacy-bfb-report-ignores-top-level-diagnostics' );
	$assert( 'legacy-top-level' !== ( $compiled['bfb_report']['fallbacks'][0]['source'] ?? '' ), 'legacy-bfb-report-ignores-top-level-fallbacks' );
	$assert( 'website/index.html' === ( $compiled['input']['entry_path'] ?? '' ), 'native-artifact-report-preserved-as-input' );
	$assert( 'blocks-engine/php-transformer/materialization-plan/v1' === ( $site['schema'] ?? '' ), 'native-materialization-plan-contract-is-used' );
	$assert( 4 === count( $pages ), 'native-keeps-compiled-site-pages-without-adapter-filtering' );
	$assert( 'website/index.html' === ( $pages[0]['source_path'] ?? '' ), 'native-entry-source-path' );
	$assert( 'home-canonical' === ( $pages[0]['slug'] ?? '' ), 'native-entry-slug-from-materialization-plan' );
	$assert( 'legacy-home' !== ( $pages[0]['slug'] ?? '' ), 'legacy-mapping-does-not-override-native-materialization-plan' );
	$assert( true === ( $pages[0]['entrypoint'] ?? false ), 'native-entrypoint' );
	$assert( 'front-page' === ( $pages[0]['route_key'] ?? '' ), 'native-entry-route-key-from-materialization-plan' );
	$assert( '/' === ( $pages[0]['permalink'] ?? '' ), 'native-entry-permalink-from-materialization-plan' );
	$assert( 'about-canonical' === ( $pages[2]['slug'] ?? '' ), 'native-route-slug-from-materialization-plan' );
	$assert( 3 === count( $routes ), 'native-routes-are-exposed-to-importer-report' );
	$assert( '/menu/' === ( $routes[1]['permalink'] ?? '' ), 'native-route-permalink-is-used' );
	$assert( '/legacy-home/' !== ( $routes[0]['permalink'] ?? '' ), 'compiled-site-route-does-not-override-materialization-plan-route' );
	$assert( 3 === count( $navigation_items ), 'native-navigation-items-are-exposed-to-importer-report' );
	$assert( 'Shop' === ( $navigation_items[2]['label'] ?? '' ), 'native-navigation-product-link-is-preserved' );
	$assert( 'Legacy Menu' !== ( $navigation_items[0]['label'] ?? '' ), 'compiled-site-navigation-does-not-override-materialization-plan-navigation' );
	$assert( 1 === count( $documents ), 'native-documents-preserve-transformer-documents-without-compiled-site-synthesis' );
	$assert( 'content/about.md' === ( $documents[0]['source_path'] ?? '' ), 'native-document-from-transformer-documents' );
	$assert( 'legacy/about.html' !== ( $documents[0]['source_path'] ?? '' ), 'legacy-mapping-does-not-override-native-documents' );
	$assert( 'assets/native-site.css' === ( $artifacts['files'][0]['path'] ?? '' ), 'native-materialization-plan-assets-drive-artifact-files' );
	$assert( 'body { color: black; }' === ( $artifacts['files'][0]['content'] ?? '' ), 'native-materialization-plan-asset-payload-is-preserved' );
	$assert( 'assets/legacy-site.css' !== ( $artifacts['files'][0]['path'] ?? '' ), 'legacy-assets-do-not-override-native-materialization-plan-assets' );
	$assert( 'assets/stale-compiled-site.css' !== ( $artifacts['files'][0]['path'] ?? '' ), 'compiled-site-assets-do-not-override-native-materialization-plan-assets' );
	$assert( 2 === count( $template_parts ), 'native-template-parts-are-exposed-to-importer-report' );
	$assert( 'parts/header.html' === ( $template_parts[0]['path'] ?? '' ), 'native-template-part-path-is-used' );
	$assert( 'parts/legacy-header.html' !== ( $template_parts[0]['path'] ?? '' ), 'compiled-site-template-part-does-not-override-materialization-plan-template-part' );
	$assert( '.native-repair{display:grid}' === ( $visual_repair['css'][0]['content'] ?? '' ), 'native-visual-repair-css-is-exposed-to-importer-report' );
	$assert( '.legacy-repair{display:block}' !== ( $visual_repair['css'][0]['content'] ?? '' ), 'compiled-site-visual-repair-does-not-override-materialization-plan-visual-repair' );
	$assert( 'rye-loaf-canonical' === ( $products[0]['slug'] ?? '' ), 'native-product-slug-mapped-from-generic-report' );
	$assert( '12.00' === ( $products[0]['regular_price'] ?? '' ), 'native-product-price-normalized-from-generic-report' );
	$assert( array( 'Bread' ) === ( $products[0]['categories'] ?? array() ), 'native-product-categories-mapped-from-generic-report' );

	if ( $failures ) {
		fwrite( STDERR, implode( "\n", $failures ) . "\n" );
		exit( 1 );
	}

	echo 'OK: transformer adapter smoke passed (' . $assertions . " assertions)\n";
}

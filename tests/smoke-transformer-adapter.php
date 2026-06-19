<?php
/**
 * Smoke test: SSI transformer adapter owns php-transformer/BAC migration shims.
 *
 * Run from the repository root:
 * php tests/smoke-transformer-adapter.php
 *
 * @package StaticSiteImporter
 */

namespace Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler {
	class ArtifactCompiler {
		public function compile( array $artifact ): object {
			$GLOBALS['ssi_transformer_adapter_artifact_compiler_calls'][] = $artifact;

			return new class() {
				public function toArray(): array {
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
								),
								'assets'      => array(
									array( 'path' => 'assets/site.css', 'role' => 'stylesheet' ),
								),
								'theme'       => array(
									'stylesheets' => array( 'assets/site.css' ),
								),
							),
						),
						'blocks'            => array(
							array( 'blockName' => 'core/paragraph', 'innerBlocks' => array() ),
						),
						'serialized_blocks' => '<!-- wp:paragraph --><p>Home</p><!-- /wp:paragraph -->',
						'documents'         => array(
							array(
								'source_path'  => 'content/about.md',
								'slug'         => 'about',
								'title'        => 'About',
								'block_markup' => '<!-- wp:paragraph --><p>About</p><!-- /wp:paragraph -->',
							),
						),
						'assets'            => array(
							array( 'path' => 'assets/site.css', 'role' => 'stylesheet' ),
						),
						'diagnostics'       => array(),
						'fallbacks'         => array(),
						'provenance'        => array(
							array( 'source_hash' => 'abc123' ),
						),
					);
				}
			};
		}
	}
}

namespace Automattic\BlocksEngine\PhpTransformer\FormatBridge {
	class FormatBridge {
		public function convert( string $content, string $from, string $to, array $options = array() ): string {
			$GLOBALS['ssi_transformer_adapter_format_bridge_calls'][] = array( $content, $from, $to, $options );
			return '<p>FormatBridge rendered</p>';
		}
	}
}

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', dirname( __DIR__ ) . '/' );
	}

	$GLOBALS['ssi_transformer_adapter_format_bridge_calls'] = array();
	$GLOBALS['ssi_transformer_adapter_bfb_calls']           = array();
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

	if ( ! function_exists( 'bfb_convert' ) ) {
		function bfb_convert( string $content, string $from, string $to ): string {
			$GLOBALS['ssi_transformer_adapter_bfb_calls'][] = array( $content, $from, $to );
			return '<p>BFB rendered</p>';
		}
	}

	if ( ! function_exists( 'bac_compile_website_artifact' ) ) {
		function bac_compile_website_artifact( array $artifact, array $options = array() ): array {
			return array(
				'schema'              => 'block-artifact-compiler/result/v1',
				'status'              => 'success',
				'input'               => array(
					'entry_path' => 'website/index.html',
					'options'    => $options,
				),
				'wordpress_artifacts' => array(
					'documents' => array(
						array(
							'source_path'  => 'website/index.html',
							'title'        => 'Home Page',
							'block_markup' => '<!-- wp:paragraph --><p>Home</p><!-- /wp:paragraph -->',
						),
						array(
							'source_path'  => 'website/menu.html',
							'title'        => 'Menu Page',
							'block_markup' => '<!-- wp:paragraph --><p>Menu</p><!-- /wp:paragraph -->',
						),
					),
				),
				'diagnostics'         => array(),
			);
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
	$assert( array() === $GLOBALS['ssi_transformer_adapter_bfb_calls'], 'bfb-not-called-when-format-bridge-exists' );
	$assert( 'blocks' === ( $GLOBALS['ssi_transformer_adapter_format_bridge_calls'][0][1] ?? '' ), 'format-bridge-from-format' );
	$assert( 'html' === ( $GLOBALS['ssi_transformer_adapter_format_bridge_calls'][0][2] ?? '' ), 'format-bridge-to-format' );
	$assert( 'smoke' === ( $GLOBALS['ssi_transformer_adapter_format_bridge_calls'][0][3]['source'] ?? '' ), 'format-bridge-options-forwarded' );

	$compiled  = $adapter->compile_website_artifact( array( 'schema' => 'block-artifact-compiler/website-artifact/v1' ), array( 'include_bfb_report' => true ) );
	$artifacts = $compiled['wordpress_artifacts'] ?? array();
	$site      = $artifacts['site'] ?? array();
	$pages     = $site['pages'] ?? array();
	$documents = $artifacts['documents'] ?? array();
	$assert( ! is_wp_error( $compiled ), 'native-compile-succeeds' );
	$assert( 1 === count( $GLOBALS['ssi_transformer_adapter_artifact_compiler_calls'] ), 'native-artifact-compiler-called' );
	$assert( 'block-artifact-compiler/result/v1' === ( $compiled['schema'] ?? '' ), 'native-result-mapped-to-bac-envelope' );
	$assert( 'block-artifact-compiler/compiled-site/v1' === ( $site['schema'] ?? '' ), 'native-compiled-site-mapped-to-ssi-contract' );
	$assert( 'blocks-engine/php-transformer/compiled-site/v1' === ( $site['source'] ?? '' ), 'native-compiled-site-source-recorded' );
	$assert( 2 === count( $pages ), 'native-keeps-materializable-compiled-site-pages' );
	$assert( 'website/index.html' === ( $pages[0]['source_path'] ?? '' ), 'native-entry-source-path' );
	$assert( 'index' === ( $pages[0]['slug'] ?? '' ), 'native-entry-slug' );
	$assert( 'page' === ( $pages[0]['post_type'] ?? '' ), 'native-entry-post-type-owned-by-ssi' );
	$assert( true === ( $pages[0]['entrypoint'] ?? false ), 'native-entrypoint' );
	$assert( 'about' === ( $pages[1]['slug'] ?? '' ), 'native-route-slug-from-source-document-compiled-site' );
	$assert( 2 === count( $documents ), 'native-documents-include-compiled-page-markup-and-source-documents' );
	$assert( 'website/index.html' === ( $documents[0]['source_path'] ?? '' ), 'native-document-from-compiled-site-page' );
	$assert( 'content/about.md' === ( $documents[1]['source_path'] ?? '' ), 'native-document-from-transformer-documents' );
	$assert( 'assets/site.css' === ( $artifacts['files'][0]['path'] ?? '' ), 'native-assets-report-preserved' );

	if ( $failures ) {
		fwrite( STDERR, implode( "\n", $failures ) . "\n" );
		exit( 1 );
	}

	echo 'OK: transformer adapter smoke passed (' . $assertions . " assertions)\n";
}

<?php
/**
 * Smoke test: SSI transformer adapter owns php-transformer/BAC migration shims.
 *
 * Run from the repository root:
 * php tests/smoke-transformer-adapter.php
 *
 * @package StaticSiteImporter
 */

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

	$compiled = $adapter->compile_website_artifact( array( 'schema' => 'block-artifact-compiler/website-artifact/v1' ), array( 'include_bfb_report' => true ) );
	$site     = $compiled['wordpress_artifacts']['site'] ?? array();
	$pages    = $site['pages'] ?? array();
	$assert( ! is_wp_error( $compiled ), 'legacy-compile-succeeds' );
	$assert( 'block-artifact-compiler/compiled-site/v1' === ( $site['schema'] ?? '' ), 'fallback-adds-compiled-site-contract' );
	$assert( 2 === count( $pages ), 'fallback-preserves-document-count' );
	$assert( 'website/index.html' === ( $pages[0]['source_path'] ?? '' ), 'fallback-entry-source-path' );
	$assert( 'home' === ( $pages[0]['slug'] ?? '' ), 'fallback-entry-slug' );
	$assert( true === ( $pages[0]['entrypoint'] ?? false ), 'fallback-entrypoint' );
	$assert( 'menu' === ( $pages[1]['slug'] ?? '' ), 'fallback-route-slug-from-source-path' );
	$assert( 'static_site_importer_legacy_compiled_site_fallback' === ( $compiled['diagnostics'][0]['code'] ?? '' ), 'fallback-diagnostic-recorded' );

	if ( $failures ) {
		fwrite( STDERR, implode( "\n", $failures ) . "\n" );
		exit( 1 );
	}

	echo 'OK: transformer adapter smoke passed (' . $assertions . " assertions)\n";
}

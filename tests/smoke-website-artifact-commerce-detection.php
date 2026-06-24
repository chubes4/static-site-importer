<?php
/**
 * Smoke coverage for commerce inference from raw website artifact HTML.
 *
 * Run from the repository root:
 * php tests/smoke-website-artifact-commerce-detection.php
 *
 * @package StaticSiteImporter
 */

namespace {
	function blocks_engine_php_transformer_compile_artifact( array $artifact, array $options = array() ): array {
		return array(
			'schema'         => 'blocks-engine/php-transformer/result/v1',
			'status'         => 'success',
			'source_reports' => array(
				'artifact'              => array(
					'schema'     => 'blocks-engine/php-transformer/site-artifact/v1',
					'entry_path' => 'website/index.html',
				),
				'materialization_plan' => array(
					'schema' => 'blocks-engine/php-transformer/materialization-plan/v1',
					'pages'  => array(
						array(
							'source_path'  => 'website/index.html',
							'post_type'    => 'page',
							'slug'         => 'home',
							'title'        => 'Storefront',
							'block_markup' => '<!-- wp:paragraph --><p>Storefront</p><!-- /wp:paragraph -->',
						),
					),
				),
			),
		);
	}

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', dirname( __DIR__ ) . '/' );
	}

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

	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-entity-materializer-registry.php';
	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-transformer-adapter.php';

	$failures   = array();
	$assertions = 0;
	$assert     = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
		++$assertions;
		if ( ! $condition ) {
			$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
		}
	};

	$artifact = array(
		'schema'     => 'blocks-engine/php-transformer/site-artifact/v1',
		'entrypoint' => 'website/index.html',
		'files'      => array(
			array(
				'path'      => 'website/index.html',
				'kind'      => 'html',
				'mime_type' => 'text/html',
				'content'   => '<!doctype html><html><head><script type="application/ld+json">{"@context":"https://schema.org","@type":"Product","name":"Signal Hoodie","url":"/shop/signal-hoodie","offers":{"@type":"Offer","price":"64"}}</script></head><body><article class="product-card"><h2>Field Mug</h2><p class="price">$18.50</p></article></body></html>',
			),
		),
	);

	$compiled = ( new Static_Site_Importer_Transformer_Adapter() )->compile_website_artifact( $artifact );
	$products = is_array( $compiled ) ? ( $compiled['products_manifest'] ?? array() ) : array();

	$assert( ! is_wp_error( $compiled ), 'compile-succeeds', is_wp_error( $compiled ) ? $compiled->get_error_message() : '' );
	$assert( 2 === count( $products ), 'detects-json-ld-and-product-card-products' );
	$assert( 'signal-hoodie' === ( $products[0]['slug'] ?? '' ), 'json-ld-product-slug-from-url' );
	$assert( 'Signal Hoodie' === ( $products[0]['name'] ?? '' ), 'json-ld-product-name' );
	$assert( '64.00' === ( $products[0]['regular_price'] ?? '' ), 'json-ld-product-price-normalized' );
	$assert( 'field-mug' === ( $products[1]['slug'] ?? '' ), 'product-card-slug-from-heading' );
	$assert( '18.50' === ( $products[1]['regular_price'] ?? '' ), 'product-card-price-normalized' );

	$validation = Static_Site_Importer_Entity_Materializer_Registry::validate_manifest(
		Static_Site_Importer_Entity_Materializer_Registry::product_adapter(),
		array(
			'schema_version' => 1,
			'products'       => $products,
		)
	);
	$assert( array() === ( $validation['errors'] ?? array() ), 'validated-products-are-seedable' );
	$assert( 2 === count( $validation['products'] ?? array() ), 'validator-preserves-detected-product-count' );

	if ( $failures ) {
		fwrite( STDERR, implode( "\n", $failures ) . "\n" );
		exit( 1 );
	}

	echo 'OK: website artifact commerce detection smoke passed (' . $assertions . " assertions)\n";
}

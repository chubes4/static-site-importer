<?php
/**
 * Smoke coverage for materialized post_content block validation reporting.
 *
 * Run from the repository root:
 * php tests/smoke-materialized-block-content-validation.php
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook_name, $value ) {
		unset( $hook_name );
		return $value;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value, int $flags = 0, int $depth = 512 ) {
		return json_encode( $value, $flags, $depth );
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( string $value ): string {
		return rtrim( $value, '/\\' ) . '/';
	}
}

if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( string $value ): string {
		$value = strtolower( trim( $value ) );
		$value = preg_replace( '/[^a-z0-9_-]+/', '-', $value );

		return trim( (string) $value, '-' );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( string $value ): string {
		$value = strtolower( $value );

		return preg_replace( '/[^a-z0-9_-]/', '', $value ) ?: '';
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( string $value ): string {
		return htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}

if ( ! function_exists( 'get_post_type_object' ) ) {
	function get_post_type_object( string $post_type ): ?object {
		return 'page' === $post_type ? (object) array( 'name' => 'page' ) : null;
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( private string $code, private string $message ) {}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}
	}
}

$wp_root = getenv( 'STATIC_SITE_IMPORTER_WP_ROOT' ) ?: '/Users/chubes/Studio/intelligence-chubes4';
$parser  = rtrim( $wp_root, '/\\' ) . '/wp-includes/class-wp-block-parser.php';
$blocks  = rtrim( $wp_root, '/\\' ) . '/wp-includes/blocks.php';
if ( ! is_readable( $parser ) || ! is_readable( $blocks ) ) {
	fwrite( STDERR, "SKIP: WordPress parser/serializer files are unavailable. Set STATIC_SITE_IMPORTER_WP_ROOT.\n" );
	exit( 0 );
}

require_once $parser;
require_once $blocks;

require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-document.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-source-page.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-product-handoff-contract.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-report-diagnostics.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-page-materializer.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-theme-generator.php';

$failures   = array();
$assertions = 0;
$assert     = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
	++$assertions;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
	}
};

$page = Static_Site_Importer_Source_Page::from_wordpress_document_artifact(
	array(
		'source_path'  => 'index.html',
		'slug'         => 'home',
		'post_type'    => 'page',
		'title'        => 'Home',
		'block_markup' => '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->',
	)
);
if ( $page instanceof WP_Error ) {
	fwrite( STDERR, $page->get_error_message() . "\n" );
	exit( 1 );
}

$reflection      = new ReflectionClass( Static_Site_Importer_Theme_Generator::class );
$report_property = $reflection->getProperty( 'conversion_report' );
$report_property->setValue( null, Static_Site_Importer_Report_Diagnostics::new_conversion_report( '/tmp/source/index.html' ) );

$analyze = $reflection->getMethod( 'analyze_imported_page_content_documents' );
$analyze->invoke(
	null,
	array( 'index.html' => $page ),
	array( 'index.html' => '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->' )
);

$report       = $report_property->getValue();
$materialized = $report['materialized_content']['block_documents'][0] ?? array();
$generated    = $report['generated_theme']['block_documents'][0] ?? array();

$assert( 'posts/page-home.post_content' === ( $materialized['path'] ?? '' ), 'materialized-path' );
$assert( 'materialized_post_content' === ( $materialized['target'] ?? '' ), 'materialized-target' );
$assert( 'index.html' === ( $materialized['source_path'] ?? '' ), 'source-path' );
$assert( 'home' === ( $materialized['slug'] ?? '' ), 'slug' );
$assert( 'page' === ( $materialized['post_type'] ?? '' ), 'post-type' );
$assert( true === ( $materialized['validation_available'] ?? false ), 'validation-available' );
$assert( 'wordpress_parse_blocks_serialize_blocks' === ( $materialized['validation_method'] ?? '' ), 'validation-method' );
$assert( 1 === ( $materialized['block_count'] ?? 0 ), 'block-count' );
$assert( 0 === ( $materialized['invalid_block_count'] ?? -1 ), 'valid-content-has-no-invalid-blocks' );
$assert( $materialized === $generated, 'legacy-generated-theme-bucket-preserved' );

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo 'OK: materialized block content validation smoke passed (' . $assertions . " assertions)\n";

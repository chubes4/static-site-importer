<?php
/**
 * Smoke test: BAC visual repair artifacts drive generated theme CSS.
 *
 * Run from the repository root:
 * php tests/smoke-bac-visual-repair-css.php
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private string $code;

		public function __construct( string $code ) {
			$this->code = $code;
		}

		public function get_error_code(): string {
			return $this->code;
		}
	}
}

require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-stylesheet-materializer.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-theme-generator.php';

$failures   = array();
$assertions = 0;

$assert = static function ( bool $condition, string $label, string $detail = '' ) use ( &$failures, &$assertions ): void {
	++$assertions;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
	}
};

$artifacts = array(
	'visual_repair' => array(
		'schema' => 'block-artifact-compiler/visual-repair-artifacts/v1',
		'css'    => '.compiled-site-repair { display: block; }',
		'styles' => array(
			array(
				'schema'  => 'block-artifact-compiler/visual-repair-css/v1',
				'target'  => 'frontend',
				'path'    => 'assets/css/visual-repair.css',
				'content' => "/* Block Artifact Compiler: visual repair artifacts. */\n.wp-block-group.hero-shell { gap: 0; }",
			),
			array(
				'schema'  => 'block-artifact-compiler/visual-repair-css/v1',
				'target'  => 'editor',
				'path'    => 'assets/css/visual-repair-editor.css',
				'content' => "/* Block Artifact Compiler: editor visual repair artifacts. */\n.editor-styles-wrapper .glow-orb { opacity: 1 !important; }",
			),
			array(
				'target'  => 'frontend',
				'content' => "/* Block Artifact Compiler: visual repair artifacts. */\n.wp-block-group.hero-shell { gap: 0; }",
			),
			array(
				'target'  => 'unknown',
				'content' => '.should-not-appear { color: red; }',
			),
		),
	),
);

$collector = new ReflectionMethod( Static_Site_Importer_Theme_Generator::class, 'visual_repair_styles_from_artifacts' );
$styles    = $collector->invoke( null, $artifacts );

$writes     = Static_Site_Importer_Stylesheet_Materializer::stylesheet_writes( '/tmp/bac-visual-repair-smoke', 'BAC Visual Repair Smoke', '.hero-shell{display:grid}', $styles );
$style_css  = (string) ( $writes['/tmp/bac-visual-repair-smoke/style.css'] ?? '' );
$editor_css = (string) ( $writes['/tmp/bac-visual-repair-smoke/assets/css/editor-style.css'] ?? '' );

$assert( 2 === count( $styles['frontend'] ?? array() ), 'collector-dedupes-frontend-repair-css' );
$assert( 2 === count( $styles['editor'] ?? array() ), 'collector-reads-editor-repair-css' );
$assert( str_contains( $style_css, 'Block Artifact Compiler: visual repair artifacts.' ), 'style-includes-bac-frontend-repair-comment', $style_css );
$assert( str_contains( $style_css, '.wp-block-group.hero-shell { gap: 0; }' ), 'style-includes-bac-frontend-repair-rule', $style_css );
$assert( str_contains( $style_css, '.compiled-site-repair { display: block; }' ), 'style-includes-compiled-site-repair-css', $style_css );
$assert( ! str_contains( $style_css, 'editor visual repair artifacts' ), 'style-excludes-editor-repair-css', $style_css );
$assert( str_contains( $editor_css, 'Block Artifact Compiler: editor visual repair artifacts.' ), 'editor-includes-bac-editor-repair-comment', $editor_css );
$assert( str_contains( $editor_css, '.editor-styles-wrapper .glow-orb { opacity: 1 !important; }' ), 'editor-includes-bac-editor-repair-rule', $editor_css );
$assert( str_contains( $editor_css, '.compiled-site-repair { display: block; }' ), 'editor-includes-compiled-site-repair-css', $editor_css );
$assert( ! str_contains( $editor_css, '.should-not-appear' ), 'unknown-target-repair-css-is-ignored', $editor_css );

$documents      = new ReflectionMethod( Static_Site_Importer_Theme_Generator::class, 'documents_from_compiled_site_pages' );
$missing_source = $documents->invoke(
	null,
	array(
		array(
			'slug' => 'home',
		),
	),
	array()
);
$assert( $missing_source instanceof WP_Error, 'compiled-site-page-source-is-required' );
$assert( 'static_site_importer_compiled_site_page_missing_source' === ( $missing_source instanceof WP_Error ? $missing_source->get_error_code() : '' ), 'compiled-site-page-source-error-code' );

if ( ! empty( $failures ) ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo 'OK: BAC visual repair CSS smoke passed (' . $assertions . " assertions)\n";

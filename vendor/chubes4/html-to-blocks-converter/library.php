<?php
/**
 * Library entry point for html-to-blocks-converter.
 *
 * Composer consumers autoload this file (`autoload.files`) to make the
 * zero-configuration HTML → blocks automation available without requiring
 * the standalone plugin to be separately activated. The raw conversion API
 * is loaded alongside the automatic write/read hooks.
 *
 * Multiple bundled copies can coexist: each registers its version and
 * initializer, then `HTML_To_Blocks_Versions` initializes the highest
 * registered version on `plugins_loaded:1`.
 *
 * @package HTML_To_Blocks_Converter
 */

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

$html_to_blocks_library_path    = __DIR__;
$html_to_blocks_library_version = '0.7.2';

if ( ! defined( 'HTML_TO_BLOCKS_CONVERTER_VERSION' ) ) {
	define( 'HTML_TO_BLOCKS_CONVERTER_VERSION', $html_to_blocks_library_version );
}

if ( ! class_exists( 'HTML_To_Blocks_Versions', false ) ) {
	require_once $html_to_blocks_library_path . '/includes/class-html-to-blocks-versions.php';
}

$html_to_blocks_initializer = static function () use ( $html_to_blocks_library_path ): void {
	if ( ! class_exists( 'HTML_To_Blocks_HTML_Element', false ) ) {
		require_once $html_to_blocks_library_path . '/includes/class-html-element.php';
	}
	if ( ! class_exists( 'HTML_To_Blocks_Block_Factory', false ) ) {
		require_once $html_to_blocks_library_path . '/includes/class-block-factory.php';
	}
	if ( ! class_exists( 'HTML_To_Blocks_Attribute_Parser', false ) ) {
		require_once $html_to_blocks_library_path . '/includes/class-attribute-parser.php';
	}
	if ( ! class_exists( 'HTML_To_Blocks_SVG_Icon_Classifier', false ) ) {
		require_once $html_to_blocks_library_path . '/includes/class-svg-icon-classifier.php';
	}
	if ( ! function_exists( 'html_to_blocks_classify_inline_svg_icon' ) ) {
		require_once $html_to_blocks_library_path . '/includes/svg-icon-functions.php';
	}
	if ( ! class_exists( 'HTML_To_Blocks_Site_Editor_Marker_Transforms', false ) ) {
		require_once $html_to_blocks_library_path . '/includes/transform-families/class-site-editor-marker-transforms.php';
	}
	if ( ! class_exists( 'HTML_To_Blocks_Transform_Registry', false ) ) {
		require_once $html_to_blocks_library_path . '/includes/class-transform-registry.php';
	}
	if ( ! function_exists( 'html_to_blocks_get_capabilities' ) ) {
		require_once $html_to_blocks_library_path . '/includes/capabilities.php';
	}
	$html_to_blocks_raw_handler_callback = 'html_to_blocks_raw_handler';
	if ( ! function_exists( $html_to_blocks_raw_handler_callback ) ) {
		require_once $html_to_blocks_library_path . '/raw-handler.php';
	}
	require_once $html_to_blocks_library_path . '/includes/hooks.php';
};

$html_to_blocks_register = static function () use ( $html_to_blocks_library_version, $html_to_blocks_initializer ): void {
	HTML_To_Blocks_Versions::instance()->register( $html_to_blocks_library_version, $html_to_blocks_initializer );
};

HTML_To_Blocks_Versions::register_hooks();

if ( did_action( 'plugins_loaded' ) && ! doing_action( 'plugins_loaded' ) ) {
	$html_to_blocks_register();
	HTML_To_Blocks_Versions::initialize_latest_version();
} else {
	add_action( 'plugins_loaded', $html_to_blocks_register, 0 );
}

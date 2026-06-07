<?php
/**
 * Public capability inventory for html-to-blocks-converter consumers.
 *
 * @package HTML_To_Blocks_Converter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'html_to_blocks_get_capabilities' ) ) {
	/**
	 * Gets h2bc public capabilities without requiring consumers to parse source.
	 *
	 * @return array<string,mixed> Capability inventory.
	 */
	function html_to_blocks_get_capabilities(): array {
		$transform_inventory = class_exists( 'HTML_To_Blocks_Transform_Registry', false )
			? HTML_To_Blocks_Transform_Registry::get_transform_inventory()
			: array(
				'families'              => array(),
				'supported_core_blocks' => array(),
				'explicit_markers'      => array(),
			);

		return array(
			'version'            => defined( 'HTML_TO_BLOCKS_CONVERTER_VERSION' ) ? HTML_TO_BLOCKS_CONVERTER_VERSION : '0.7.2',
			'raw_handler'        => array(
				'function'  => 'html_to_blocks_raw_handler',
				'available' => function_exists( 'html_to_blocks_raw_handler' ),
			),
			'transforms'         => $transform_inventory,
			'hooks'              => array(
				'unsupported_html_fallback' => 'html_to_blocks_unsupported_html_fallback',
				'convert_metrics'           => 'html_to_blocks_convert_metrics',
			),
			'fallback_blocks'    => array( 'core/html' ),
			'boundary_contracts' => array(
				'raw_fragment_to_block_array'  => true,
				'explicit_site_editor_markers' => true,
				'context_required_blocks'      => array(
					'core/navigation',
					'core/site-title',
					'core/post-title',
					'core/query',
					'woocommerce/*',
				),
			),
		);
	}
}

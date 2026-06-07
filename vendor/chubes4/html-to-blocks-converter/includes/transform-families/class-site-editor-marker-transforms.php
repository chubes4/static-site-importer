<?php
/**
 * Explicit Site Editor marker transforms.
 *
 * These transforms are intentionally marker-only. They do not infer pattern or
 * template-part intent from tag names, class names, layout, or visual shape.
 *
 * @package HTML_To_Blocks_Converter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HTML_To_Blocks_Site_Editor_Marker_Transforms {

	/**
	 * Gets transform family metadata for capability inventory consumers.
	 *
	 * @return array<string,mixed> Family metadata.
	 */
	public static function get_family_metadata(): array {
		return array(
			'slug'              => 'site-editor-markers',
			'label'             => 'Explicit Site Editor markers',
			'blocks'            => array( 'core/pattern', 'core/template-part' ),
			'explicit_markers'  => true,
			'marker_attributes' => self::get_marker_attributes(),
			'criteria'          => 'Requires explicit marker attributes; never inferred from visual similarity.',
		);
	}

	/**
	 * Gets explicit marker attributes accepted by this shared contract.
	 *
	 * `data-h2bc-*` is the generic h2bc-owned marker contract. The `data-bfb-*`
	 * aliases are a documented compatibility contract for Block Format Bridge and
	 * remain explicit markers rather than inferred Site Editor semantics.
	 *
	 * @return array<string,string[]> Marker attributes keyed by marker type.
	 */
	public static function get_marker_attributes(): array {
		$attributes = array(
			'pattern'       => array( 'data-h2bc-pattern', 'data-bfb-pattern' ),
			'template_part' => array( 'data-h2bc-template-part', 'data-bfb-template-part' ),
		);

		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filters explicit Site Editor marker attributes.
			 *
			 * Consumers can add aliases while preserving the marker-only boundary.
			 * Attribute values are still validated by h2bc before conversion.
			 *
			 * @param array<string,string[]> $attributes Marker attributes.
			 */
			$attributes = self::normalize_marker_attributes(
				call_user_func( 'apply_filters', 'html_to_blocks_site_editor_marker_attributes', $attributes )
			);
		}

		return $attributes;
	}

	/**
	 * Gets explicit marker raw transforms.
	 *
	 * @return array<int,array<string,mixed>> Transform definitions.
	 */
	public static function get_transforms(): array {
		return array(
			array(
				'blockName' => 'core/pattern',
				'priority'  => 1,
				'isMatch'   => function ( $element ) {
					return self::get_pattern_marker_slug( $element ) !== '';
				},
				'transform' => function ( $element ) {
					return HTML_To_Blocks_Block_Factory::create_block(
						'core/pattern',
						array( 'slug' => self::get_pattern_marker_slug( $element ) )
					);
				},
			),
			array(
				'blockName' => 'core/template-part',
				'priority'  => 1,
				'isMatch'   => function ( $element ) {
					return self::get_template_part_marker_slug( $element ) !== '';
				},
				'transform' => function ( $element ) {
					$slug       = self::get_template_part_marker_slug( $element );
					$attributes = array( 'slug' => $slug );

					if ( in_array( $slug, array( 'header', 'footer', 'sidebar' ), true ) ) {
						$attributes['area'] = $slug;
					}

					return HTML_To_Blocks_Block_Factory::create_block( 'core/template-part', $attributes );
				},
			),
		);
	}

	/**
	 * Gets a valid explicit pattern marker slug.
	 *
	 * @param HTML_To_Blocks_HTML_Element $element Element to inspect.
	 * @return string Pattern slug or empty string.
	 */
	private static function get_pattern_marker_slug( $element ): string {
		$slug = self::get_first_marker_attribute_value( $element, 'pattern' );
		return preg_match( '/^[a-z0-9_.-]+\/[a-z0-9_.\/-]+$/i', $slug ) === 1 ? $slug : '';
	}

	/**
	 * Gets a valid explicit template-part marker slug.
	 *
	 * @param HTML_To_Blocks_HTML_Element $element Element to inspect.
	 * @return string Template-part slug or empty string.
	 */
	private static function get_template_part_marker_slug( $element ): string {
		$slug = self::get_first_marker_attribute_value( $element, 'template_part' );
		return preg_match( '/^[a-z0-9_.-]+$/i', $slug ) === 1 ? $slug : '';
	}

	/**
	 * Gets the first non-empty marker value from allowed attributes.
	 *
	 * @param HTML_To_Blocks_HTML_Element $element Element to inspect.
	 * @param string                      $type    Marker type.
	 * @return string Marker value or empty string.
	 */
	private static function get_first_marker_attribute_value( $element, string $type ): string {
		$attributes = self::get_marker_attributes();
		foreach ( $attributes[ $type ] ?? array() as $attribute_name ) {
			if ( '' === $attribute_name || ! $element->has_attribute( $attribute_name ) ) {
				continue;
			}

			$value = trim( (string) $element->get_attribute( $attribute_name ) );
			if ( '' !== $value ) {
				return $value;
			}
		}

		return '';
	}

	/**
	 * Normalizes marker attribute filter output.
	 *
	 * @param array<string,mixed> $attributes Marker attributes.
	 * @return array<string,string[]> Normalized marker attributes.
	 */
	private static function normalize_marker_attributes( array $attributes ): array {
		$normalized = array(
			'pattern'       => array(),
			'template_part' => array(),
		);

		foreach ( $normalized as $type => $_ ) {
			foreach ( (array) ( $attributes[ $type ] ?? array() ) as $attribute_name ) {
				if ( is_string( $attribute_name ) && preg_match( '/^data-[a-z0-9_.:-]+$/i', $attribute_name ) === 1 ) {
					$normalized[ $type ][] = strtolower( $attribute_name );
				}
			}

			$normalized[ $type ] = array_values( array_unique( $normalized[ $type ] ) );
		}

		return $normalized;
	}
}

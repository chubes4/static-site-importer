<?php
/**
 * Public API.
 *
 * These functions form the public Phase 1 surface:
 *
 *   bfb_convert( $content, $from, $to, $options ) — universal conversion
 *   bfb_to_blocks( $content, $from, $options )    — block-array conversion
 *   bfb_normalize( $content, $format )      — declared-format validation
 *   bfb_capabilities()                      — conversion substrate report
 *   bfb_get_adapter( $slug )                — registry lookup
 *
 * `bfb_convert()` routes through the block pivot via the adapter registry.
 * `bfb_normalize()` validates already-declared content through the dedicated
 * normalization helpers loaded from includes/normalization.php.
 *
 * @package BlockFormatBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'bfb_capabilities' ) ) {
	/**
	 * Return a machine-readable report of the active conversion substrate.
	 *
	 * @return array<string, mixed>
	 */
	function bfb_capabilities(): array {
		$formats = array(
			'blocks' => array(
				'slug'        => 'blocks',
				'label'       => 'Serialized WordPress blocks',
				'adapter'     => null,
				'to_blocks'   => true,
				'from_blocks' => true,
				'pivot'       => true,
			),
		);

		foreach ( BFB_Adapter_Registry::slugs() as $slug ) {
			$adapter = bfb_get_adapter( $slug );
			if ( ! $adapter ) {
				continue;
			}

			$formats[ $slug ] = array(
				'slug'        => $slug,
				'label'       => $slug,
				'adapter'     => get_class( $adapter ),
				'to_blocks'   => true,
				'from_blocks' => true,
				'pivot'       => false,
			);
		}

		$h2bc = bfb_h2bc_capabilities();

		return array(
			'bridge'         => array(
				'version' => defined( 'BFB_VERSION' ) ? BFB_VERSION : null,
				'path'    => defined( 'BFB_PATH' ) ? BFB_PATH : null,
			),
			'formats'        => $formats,
			'conversions'    => array(
				'html_to_blocks' => array(
					'available' => (bool) $h2bc['available'],
					'provider'  => 'html-to-blocks-converter',
				),
			),
			'h2bc'           => $h2bc,
			'block_coverage' => array(
				'source'             => 'not_available',
				'requires'           => 'h2bc#56',
				'supported_blocks'   => array(),
				'unsupported_blocks' => array(),
				'classifications'    => array(),
			),
			'hooks'          => array(
				'filters' => array(
					'bfb_register_format_adapter',
					'bfb_default_format',
					'bfb_skip_insert_conversion',
					'bfb_rest_supported_post_types',
					'bfb_markdown_input',
					'bfb_markdown_output',
					'bfb_html_to_blocks_args',
					'bfb_html_to_markdown_options',
				),
				'actions' => array(
					'bfb_loaded',
					'bfb_adapters_registered',
					'bfb_diagnostic',
					'bfb_insert_conversion_measured',
					'bfb_html_to_markdown_converter',
				),
			),
			'abilities'      => array(
				'block-format-bridge/get-capabilities',
				'block-format-bridge/convert',
				'block-format-bridge/normalize',
			),
		);
	}
}

if ( ! function_exists( 'bfb_h2bc_capabilities' ) ) {
	/**
	 * Return availability metadata for the bundled or standalone h2bc substrate.
	 *
	 * @return array<string, mixed>
	 */
	function bfb_h2bc_capabilities(): array {
		$handler = null;
		if ( function_exists( '\BlockFormatBridge\Vendor\html_to_blocks_raw_handler' ) ) {
			$handler = '\BlockFormatBridge\Vendor\html_to_blocks_raw_handler';
		} elseif ( function_exists( 'html_to_blocks_raw_handler' ) ) {
			$handler = 'html_to_blocks_raw_handler';
		}

		$path = null;
		if ( defined( 'HTML_TO_BLOCKS_CONVERTER_PATH' ) ) {
			$path = HTML_TO_BLOCKS_CONVERTER_PATH;
		} elseif ( $handler ) {
			$reflection = new ReflectionFunction( $handler );
			$file       = $reflection->getFileName();
			$path       = is_string( $file ) ? dirname( $file ) . '/' : null;
		}

		$version = null;
		if ( $path ) {
			$library = trailingslashit( $path ) . 'library.php';
			if ( is_readable( $library ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local package metadata read.
				$source = file_get_contents( $library );
				if ( is_string( $source ) && preg_match( "/html_to_blocks_library_version\s*=\s*'([^']+)'/", $source, $match ) ) {
					$version = $match[1];
				}
			}
		}

		return array(
			'available'   => null !== $handler,
			'version'     => $version,
			'path'        => $path,
			'raw_handler' => $handler,
			'inventory'   => array(
				'source'   => 'not_available',
				'requires' => 'h2bc#56',
			),
		);
	}
}

if ( ! function_exists( 'bfb_get_adapter' ) ) {
	/**
	 * Resolve an adapter by slug.
	 *
	 * @param string $slug Adapter slug (e.g. 'html', 'markdown').
	 * @return BFB_Format_Adapter|null
	 */
	function bfb_get_adapter( string $slug ): ?BFB_Format_Adapter {
		return BFB_Adapter_Registry::get( $slug );
	}
}

if ( ! function_exists( 'bfb_to_blocks' ) ) {
	/**
	 * Convert content into parse_blocks()-compatible block arrays.
	 *
	 * This is the compiler-facing helper for callers that need the block-array
	 * pivot directly instead of serialized block markup.
	 *
	 * @param string               $content Source content.
	 * @param string               $from    Source format slug.
	 * @param array<string, mixed> $options Per-call conversion options.
	 * @return array<int, array<string, mixed>> Block array. Empty array on unsupported source.
	 */
	function bfb_to_blocks( string $content, string $from, array $options = array() ): array {
		if ( 'blocks' === $from ) {
			return parse_blocks( $content );
		}

		$from_adapter = bfb_get_adapter( $from );
		if ( ! $from_adapter ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Matches existing public conversion failure diagnostics.
			error_log( sprintf( '[Block Format Bridge] No adapter registered for source format "%s".', $from ) );
			return array();
		}

		return $from_adapter->to_blocks( $content, $options );
	}
}

if ( ! function_exists( 'bfb_convert' ) ) {
	/**
	 * Convert content from one format to another.
	 *
	 * Routing always passes through the block pivot:
	 *
	 *   $blocks = bfb_to_blocks( $content, $from, $options );
	 *   return    $to_adapter->from_blocks( $blocks, $options );
	 *
	 * Special cases:
	 *   - $from === $to                → returns $content unchanged
	 *   - $from === 'blocks'           → skips the to_blocks() hop and
	 *                                    treats $content as serialized
	 *                                    block markup, parsing it first
	 *   - $to === 'blocks'             → returns serialized block markup
	 *
	 * @param string               $content Source content.
	 * @param string               $from    Source format slug.
	 * @param string               $to      Target format slug.
	 * @param array<string, mixed> $options Per-call conversion options.
	 * @return string Converted content. Empty string on failure.
	 */
	function bfb_convert( string $content, string $from, string $to, array $options = array() ): string {
		if ( $from === $to ) {
			return $content;
		}

		$blocks = bfb_to_blocks( $content, $from, $options );
		if ( array() === $blocks && 'blocks' !== $from ) {
			return '';
		}

		// Render the intermediate into the target format.
		if ( 'blocks' === $to ) {
			return serialize_blocks( $blocks );
		}

		$to_adapter = bfb_get_adapter( $to );
		if ( ! $to_adapter ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Matches existing public conversion failure diagnostics.
			error_log( sprintf( '[Block Format Bridge] No adapter registered for target format "%s".', $to ) );
			return '';
		}

		return $to_adapter->from_blocks( $blocks, $options );
	}
}

if ( ! function_exists( 'bfb_render_post' ) ) {
	/**
	 * Render a post's `post_content` in the requested format.
	 *
	 * Reads the raw `post_content` and routes it through `bfb_convert`
	 * with `'blocks'` as the source format (so dynamic blocks render
	 * via their server-side callbacks).
	 *
	 * Returns an empty string when the post is missing, the post type
	 * does not support content, or the requested format is unknown.
	 *
	 * @param int|WP_Post          $post    Post ID or WP_Post.
	 * @param string               $format  Target format slug (e.g. 'html', 'markdown').
	 * @param array<string, mixed> $options Per-call conversion options.
	 * @return string Rendered content. Empty string on failure.
	 */
	function bfb_render_post( $post, string $format, array $options = array() ): string {
		$post_obj = get_post( $post );
		if ( ! $post_obj ) {
			return '';
		}

		$content = (string) $post_obj->post_content;
		if ( '' === $content ) {
			return '';
		}

		// post_content is always serialised block markup (or raw HTML on
		// pre-Gutenberg installs); route through the 'blocks' source so
		// dynamic blocks resolve.
		return bfb_convert( $content, 'blocks', $format, $options );
	}
}

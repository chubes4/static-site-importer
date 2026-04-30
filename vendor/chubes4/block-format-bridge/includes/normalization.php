<?php
/**
 * Content normalization and validation helpers.
 *
 * @package BlockFormatBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'bfb_normalize' ) ) {
	/**
	 * Normalize and validate content for a declared format.
	 *
	 * This is the explicit same-format safety boundary. `bfb_convert()`
	 * remains a conversion API; callers that need to verify declared input
	 * before storage should call this function first.
	 *
	 * Supported options:
	 *   - mode: 'strict' (default) or 'lenient'. The initial contract is
	 *     strict-first; lenient currently accepts the same recoverable cases
	 *     as strict and reserves room for future diagnostics.
	 *
	 * @param string $content Source content.
	 * @param string $format  Declared format slug.
	 * @param array  $options Normalization options.
	 * @return string|WP_Error Normalized content, or WP_Error on malformed input.
	 */
	function bfb_normalize( string $content, string $format, array $options = array() ) {
		$mode = isset( $options['mode'] ) ? (string) $options['mode'] : 'strict';
		if ( ! in_array( $mode, array( 'strict', 'lenient' ), true ) ) {
			return new WP_Error(
				'bfb_invalid_normalize_mode',
				sprintf( 'Unsupported BFB normalization mode "%s".', $mode ),
				array( 'mode' => $mode )
			);
		}

		switch ( $format ) {
			case 'blocks':
				return bfb_normalize_blocks( $content );
			case 'markdown':
				return bfb_normalize_markdown( $content );
			case 'html':
				return bfb_normalize_html( $content );
			default:
				if ( ! bfb_get_adapter( $format ) ) {
					return new WP_Error(
						'bfb_unknown_format',
						sprintf( 'No BFB adapter is registered for format "%s".', $format ),
						array( 'format' => $format )
					);
				}

				return $content;
		}
	}
}

if ( ! function_exists( 'bfb_normalize_blocks' ) ) {
	/**
	 * Validate serialized block markup without falling back to freeform.
	 *
	 * @param string $content Declared block content.
	 * @return string|WP_Error
	 */
	function bfb_normalize_blocks( string $content ) {
		if ( '' === trim( $content ) ) {
			return '';
		}

		$tokens = bfb_extract_block_tokens( $content );
		if ( $tokens instanceof WP_Error ) {
			return $tokens;
		}
		/** @var array<int, array{raw: string, offset: int, type: string, name: string}> $tokens */

		if ( empty( $tokens ) ) {
			return new WP_Error(
				'bfb_blocks_missing_comments',
				'Declared blocks content does not contain serialized block comments.',
				array( 'format' => 'blocks' )
			);
		}

		$stack  = array();
		$cursor = 0;
		foreach ( $tokens as $token ) {
			$between = substr( $content, $cursor, $token['offset'] - $cursor );
			if ( empty( $stack ) && '' !== trim( $between ) ) {
				return new WP_Error(
					'bfb_blocks_mixed_content',
					'Declared blocks content contains raw content outside serialized block comments.',
					array(
						'format'  => 'blocks',
						'excerpt' => bfb_excerpt( $between ),
					)
				);
			}

			if ( 'open' === $token['type'] ) {
				$stack[] = $token['name'];
			} elseif ( 'close' === $token['type'] ) {
				$expected = array_pop( $stack );
				if ( $expected !== $token['name'] ) {
					return new WP_Error(
						'bfb_blocks_mismatched_comment',
						'Mismatched serialized block closing comment.',
						array(
							'expected' => $expected,
							'actual'   => $token['name'],
						)
					);
				}
			}

			$cursor = $token['offset'] + strlen( $token['raw'] );
		}

		$trailing = substr( $content, $cursor );
		if ( empty( $stack ) && '' !== trim( $trailing ) ) {
			return new WP_Error(
				'bfb_blocks_mixed_content',
				'Declared blocks content contains raw content outside serialized block comments.',
				array(
					'format'  => 'blocks',
					'excerpt' => bfb_excerpt( $trailing ),
				)
			);
		}

		if ( ! empty( $stack ) ) {
			return new WP_Error(
				'bfb_blocks_unclosed_comment',
				'Serialized block markup contains an unclosed block comment.',
				array( 'open_blocks' => $stack )
			);
		}

		return $content;
	}
}

if ( ! function_exists( 'bfb_normalize_markdown' ) ) {
	/**
	 * Validate markdown content for mixed serialized blocks.
	 *
	 * @param string $content Declared markdown content.
	 * @return string|WP_Error
	 */
	function bfb_normalize_markdown( string $content ) {
		if ( bfb_contains_block_comment( $content ) ) {
			return new WP_Error(
				'bfb_markdown_contains_blocks',
				'Declared markdown content contains serialized block comments.',
				array( 'format' => 'markdown' )
			);
		}

		return str_replace( array( "\r\n", "\r" ), "\n", $content );
	}
}

if ( ! function_exists( 'bfb_normalize_html' ) ) {
	/**
	 * Validate HTML content for mixed markdown or serialized blocks.
	 *
	 * @param string $content Declared HTML content.
	 * @return string|WP_Error
	 */
	function bfb_normalize_html( string $content ) {
		if ( bfb_contains_block_comment( $content ) ) {
			return new WP_Error(
				'bfb_html_contains_blocks',
				'Declared HTML content contains serialized block comments.',
				array( 'format' => 'html' )
			);
		}

		if ( bfb_html_contains_markdown_markers( $content ) ) {
			return new WP_Error(
				'bfb_html_contains_markdown',
				'Declared HTML content contains markdown markers.',
				array( 'format' => 'html' )
			);
		}

		return $content;
	}
}

if ( ! function_exists( 'bfb_extract_block_tokens' ) ) {
	/**
	 * Extract and validate serialized block comment tokens.
	 *
	 * @param string $content Declared block content.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	function bfb_extract_block_tokens( string $content ) {
		if ( ! preg_match_all( '/<!--\s*(\/?wp:[^>]*)-->/', $content, $matches, PREG_OFFSET_CAPTURE ) ) {
			return array();
		}

		$tokens = array();
		foreach ( $matches[0] as $index => $match ) {
			$raw   = $match[0];
			$inner = trim( $matches[1][ $index ][0] );

			if ( preg_match( '/^wp:([a-z][a-z0-9-]*(?:\/[a-z][a-z0-9-]*)?)(?:\s+\{.*\})?\s*(\/)?$/s', $inner, $open ) ) {
				$tokens[] = array(
					'raw'    => $raw,
					'offset' => $match[1],
					'type'   => isset( $open[2] ) ? 'self' : 'open',
					'name'   => $open[1],
				);
				continue;
			}

			if ( preg_match( '/^\/wp:([a-z][a-z0-9-]*(?:\/[a-z][a-z0-9-]*)?)$/', $inner, $close ) ) {
				$tokens[] = array(
					'raw'    => $raw,
					'offset' => $match[1],
					'type'   => 'close',
					'name'   => $close[1],
				);
				continue;
			}

			return new WP_Error(
				'bfb_blocks_malformed_comment',
				'Malformed serialized block comment.',
				array(
					'format'  => 'blocks',
					'comment' => $raw,
				)
			);
		}

		return $tokens;
	}
}

if ( ! function_exists( 'bfb_contains_block_comment' ) ) {
	/**
	 * Check for serialized block comments.
	 *
	 * @param string $content Content to scan.
	 * @return bool
	 */
	function bfb_contains_block_comment( string $content ): bool {
		return (bool) preg_match( '/<!--\s*\/?wp:/', $content );
	}
}

if ( ! function_exists( 'bfb_html_contains_markdown_markers' ) ) {
	/**
	 * Detect common markdown markers in content declared as HTML.
	 *
	 * @param string $content Content to scan.
	 * @return bool
	 */
	function bfb_html_contains_markdown_markers( string $content ): bool {
		return (bool) preg_match( '/(^|\n)\s*(```|#{1,6}\s+|[-*+]\s+\S|>\s+\S)/', $content );
	}
}

if ( ! function_exists( 'bfb_excerpt' ) ) {
	/**
	 * Return a compact diagnostic excerpt.
	 *
	 * @param string $content Content to excerpt.
	 * @return string
	 */
	function bfb_excerpt( string $content ): string {
		$excerpt = trim( preg_replace( '/\s+/', ' ', $content ) ?? $content );
		return substr( $excerpt, 0, 120 );
	}
}

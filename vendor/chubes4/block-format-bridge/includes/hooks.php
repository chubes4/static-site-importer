<?php
/**
 * WordPress hook integration.
 *
 * Phase 1 plumbing:
 *
 *   - `_bfb_format` per-call hint on $postarr is honoured by
 *     wp_insert_post_data.
 *   - `bfb_default_format` filter declares which format a CPT writes
 *     in by default. Defaults to 'html'.
 *   - Runs at priority 5 — BEFORE html-to-blocks-converter (which
 *     fires at priority 10) — so non-HTML formats are normalised to
 *     block markup first, then html-to-blocks-converter sees nothing
 *     to do (its `<!-- wp:` short-circuit triggers).
 *
 * @package BlockFormatBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolve the format hint for a given post insert.
 *
 * Resolution order:
 *   1. `_bfb_format` key on $postarr (per-call override)
 *   2. `bfb_default_format` filter result for the post type
 *   3. 'html' (default — no conversion needed)
 *
 * @param array $data    Sanitized post data destined for wp_posts.
 * @param array $postarr Original post-insert array.
 * @return string Format slug.
 */
function bfb_resolve_format_for_insert( array $data, array $postarr ): string {
	if ( ! empty( $postarr['_bfb_format'] ) && is_string( $postarr['_bfb_format'] ) ) {
		return $postarr['_bfb_format'];
	}

	$post_type = isset( $data['post_type'] ) ? (string) $data['post_type'] : 'post';
	$content   = isset( $data['post_content'] ) ? (string) $data['post_content'] : '';

	/**
	 * Filters the default format a post type writes in.
	 *
	 * Return one of the registered adapter slugs ('html', 'markdown',
	 * or any third-party registered slug). Return 'html' (or skip the
	 * filter) for no-op conversion.
	 *
	 * @since 0.1.0
	 *
	 * @param string $format    Default format slug. 'html' to opt out.
	 * @param string $post_type Post type being inserted.
	 * @param string $content   Raw post content (already wp_unslash'd? NO — still slashed).
	 */
	$format = (string) apply_filters( 'bfb_default_format', 'html', $post_type, $content );

	return '' !== $format ? $format : 'html';
}

/**
 * Convert post_content from a non-HTML source format to serialised
 * block markup before WordPress writes it.
 *
 * Skips when:
 *   - post_content is empty
 *   - the resolved format is 'html' (existing html-to-blocks-converter
 *     handles HTML→blocks at priority 10)
 *   - post_content is already block markup (`<!-- wp:`)
 *   - no adapter is registered for the resolved format
 *   - a `bfb_skip_insert_conversion` filter callback returns true
 *     (consumer veto for storage layers that own their own
 *     post_content shape — see the filter docblock below)
 *
 * @param array $data    Sanitized post data.
 * @param array $postarr Original post-insert array.
 * @return array Modified post data.
 */
function bfb_convert_on_insert( array $data, array $postarr ): array {
	if ( empty( $data['post_content'] ) || ! is_string( $data['post_content'] ) ) {
		return $data;
	}

	$format = bfb_resolve_format_for_insert( $data, $postarr );
	if ( 'html' === $format ) {
		// HTML is the no-op format; let html-to-blocks-converter handle it
		// at its own priority.
		return $data;
	}

	/**
	 * Filters whether the bridge should skip its insert-time conversion
	 * for this `wp_insert_post_data` call.
	 *
	 * Storage layers that own the canonical shape of `post_content`
	 * (e.g. a markdown-on-disk store that wants `post_content` to stay
	 * as raw markdown) hook this filter to opt out of the
	 * markdown-/format-→blocks normalisation that happens by default.
	 *
	 * Returning `true` short-circuits the conversion; `post_content` is
	 * passed through to WordPress untouched. The bridge stays unaware of
	 * any specific consumer — the filter is the seam consumers hook.
	 *
	 * Use cases:
	 *   - markdown-database-integration in any mode: post_content is
	 *     authoritative markdown; conversion to blocks would force a
	 *     lossy blocks→markdown round-trip on the disk-write side.
	 *   - any future "post_content holds raw <X>" storage layer.
	 *
	 * @since 0.4.0
	 *
	 * @param bool   $skip    Whether to skip insert-time conversion.
	 *                        Default false.
	 * @param array  $data    Sanitized post data destined for wp_posts.
	 * @param array  $postarr Original `wp_insert_post()` array.
	 * @param string $format  Resolved source format slug
	 *                        (e.g. 'markdown'). Never 'html'.
	 */
	$skip = (bool) apply_filters( 'bfb_skip_insert_conversion', false, $data, $postarr, $format );
	if ( $skip ) {
		return $data;
	}

	$content = wp_unslash( $data['post_content'] );
	if ( ! is_string( $content ) ) {
		return $data;
	}

	$post_type = isset( $data['post_type'] ) ? (string) $data['post_type'] : '';

	// Already block markup — leave it alone.
	if ( false !== strpos( $content, '<!-- wp:' ) ) {
		return $data;
	}

	$adapter = bfb_get_adapter( $format );
	if ( ! $adapter ) {
		do_action(
			'bfb_diagnostic',
			'insert_adapter_unavailable',
			'No adapter is registered for insert conversion; skipping.',
			array(
				'format'    => $format,
				'post_type' => $post_type,
			)
		);
		return $data;
	}

	$started_at = microtime( true );
	$serialized = bfb_convert( $content, $format, 'blocks' );
	if ( '' === $serialized ) {
		return $data;
	}

	/**
	 * Fires after BFB successfully converts insert content to blocks.
	 *
	 * This action is intentionally observation-only: listeners can measure
	 * conversion cost without affecting the data written to WordPress.
	 *
	 * @since 0.4.0
	 *
	 * @param array $measurement Conversion timing and size metadata.
	 */
	do_action(
		'bfb_insert_conversion_measured',
		array(
			'format'       => $format,
			'from'         => $format,
			'to'           => 'blocks',
			'duration_ms'  => ( microtime( true ) - $started_at ) * 1000,
			'input_bytes'  => strlen( $content ),
			'output_bytes' => strlen( $serialized ),
			'post_type'    => $post_type,
		)
	);

	$data['post_content'] = wp_slash( $serialized );
	return $data;
}
add_filter( 'wp_insert_post_data', 'bfb_convert_on_insert', 5, 2 );

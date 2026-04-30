<?php
/**
 * REST integration — read-as-format query param.
 *
 * Adds a `?content_format=<slug>` query parameter to every REST-enabled
 * post type. When set, the response gains a sibling `content.formatted`
 * field rendered by `bfb_render_post()`. The existing `content.rendered`
 * and `content.raw` fields are left untouched so existing consumers keep
 * working unchanged.
 *
 * Full HTTP content negotiation (Accept header, q-values, .md URL
 * suffix, 406 Not Acceptable) is intentionally out of scope here — that
 * is the job of `roots/post-content-to-markdown` when active. The
 * bridge surface is the simpler, programmatic query-param form.
 *
 * @package BlockFormatBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the `content_format` REST query argument and the
 * `rest_prepare_*` filters that honour it.
 *
 * Runs at priority 20 to mirror html-to-blocks-converter's pattern of
 * waiting until after every other plugin has registered its CPTs at
 * the default `init` priority.
 */
function bfb_register_rest_filters() {
	$default_types = array_keys( get_post_types( array( 'show_in_rest' => true ) ) );

	/**
	 * Filters the post types that opt into the `content_format` REST
	 * query argument.
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $post_types REST-enabled post type slugs.
	 */
	$supported = (array) apply_filters( 'bfb_rest_supported_post_types', $default_types );

	foreach ( $supported as $post_type ) {
		add_filter( "rest_{$post_type}_collection_params", 'bfb_rest_add_collection_param' );
		add_filter( "rest_prepare_{$post_type}", 'bfb_rest_prepare_response', 20, 3 );
	}
}
add_action( 'init', 'bfb_register_rest_filters', 20 );

/**
 * Add `content_format` to the REST collection schema so clients can
 * discover the parameter and pass it through validation.
 *
 * @param array $params Existing query params.
 * @return array
 */
function bfb_rest_add_collection_param( $params ) {
	$params['content_format'] = array(
		'description' => __( 'Render post content in the requested format and expose it as `content.formatted` on each post.', 'block-format-bridge' ),
		'type'        => 'string',
	);

	return $params;
}

/**
 * Inject `content.formatted` into a REST response when the request
 * includes a `content_format` query param.
 *
 * @param WP_REST_Response $response The response object.
 * @param WP_Post          $post     The post object.
 * @param WP_REST_Request  $request  The request object.
 * @return WP_REST_Response
 */
function bfb_rest_prepare_response( $response, $post, $request ) {
	$format = $request->get_param( 'content_format' );
	if ( ! is_string( $format ) || '' === $format ) {
		return $response;
	}

	// HTML is a no-op — `content.rendered` already holds rendered HTML.
	if ( 'html' === $format ) {
		return $response;
	}

	$adapter = function_exists( 'bfb_get_adapter' ) ? bfb_get_adapter( $format ) : null;
	if ( ! $adapter ) {
		return $response;
	}

	$rendered = bfb_render_post( $post, $format );
	if ( '' === $rendered ) {
		return $response;
	}

	$data = $response->get_data();
	if ( ! is_array( $data ) ) {
		return $response;
	}

	if ( ! isset( $data['content'] ) || ! is_array( $data['content'] ) ) {
		$data['content'] = array();
	}

	$data['content']['formatted'] = $rendered;
	$data['content']['format']    = $format;

	$response->set_data( $data );
	return $response;
}

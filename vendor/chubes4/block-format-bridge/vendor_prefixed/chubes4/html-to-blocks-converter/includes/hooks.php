<?php

namespace BlockFormatBridge\Vendor;

/**
 * Automatic hook integration.
 *
 * Loaded by the winning library version in both standalone-plugin and
 * Composer-package mode. Guards keep older standalone plugin copies and
 * bundled package copies from double-registering callbacks.
 *
 * Callback strings passed to WordPress hook APIs are built from __NAMESPACE__
 * so scoped package copies register callable names that still exist later.
 *
 * @package HTML_To_Blocks_Converter
 */
if (!\defined('ABSPATH')) {
    exit;
}
if (!\function_exists('BlockFormatBridge\Vendor\html_to_blocks_callable_name')) {
    /**
     * Build a callback name that survives PHP scoping.
     *
     * @param string $function_name Function name without namespace.
     * @return string Callable function name.
     */
    function html_to_blocks_callable_name(string $function_name): string
    {
        // PHP-Scoper rewrites __NAMESPACE__ in bundled copies; source stays global.
        // @phpstan-ignore-next-line PHPStan only sees the unscoped source tree.
        $namespace = __NAMESPACE__;
        // @phpstan-ignore-next-line PHPStan only sees the unscoped source tree.
        if ('' === $namespace) {
            return $function_name;
        }
        // @phpstan-ignore-next-line PHPStan only sees the unscoped source tree.
        return $namespace . '\\' . $function_name;
    }
}
// ---------------------------------------------------------------------------
// Write path: convert HTML → blocks when a post is inserted/updated.
// ---------------------------------------------------------------------------
$html_to_blocks_insert_callback = html_to_blocks_callable_name('html_to_blocks_convert_on_insert');
/**
 * Converts raw HTML to Gutenberg blocks during post insertion.
 *
 * @param array $data    An array of slashed, sanitized, and processed post data.
 * @param array $postarr An array of sanitized (and slashed) but otherwise unmodified post data.
 * @return array Modified post data with HTML converted to blocks.
 */
if (!\function_exists($html_to_blocks_insert_callback)) {
    function html_to_blocks_convert_on_insert($data, $postarr)
    {
        unset($postarr);
        if (empty($data['post_content'])) {
            return $data;
        }
        $content = wp_unslash($data['post_content']);
        if (\strpos($content, '<!-- wp:') !== \false) {
            return $data;
        }
        if (!html_to_blocks_is_supported_type($data['post_type'])) {
            return $data;
        }
        $serialized = html_to_blocks_convert_content($content);
        if (null !== $serialized) {
            $data['post_content'] = wp_slash($serialized);
        }
        return $data;
    }
}
if (\function_exists('add_filter') && (!\function_exists('has_filter') || \false === \has_filter('wp_insert_post_data', $html_to_blocks_insert_callback))) {
    // @phpstan-ignore-next-line WordPress accepts callable strings resolved at runtime.
    \add_filter('wp_insert_post_data', $html_to_blocks_insert_callback, 10, 2);
}
// ---------------------------------------------------------------------------
// Read path: convert HTML → blocks in REST API responses for the editor.
// ---------------------------------------------------------------------------
$html_to_blocks_register_rest_callback = html_to_blocks_callable_name('html_to_blocks_register_rest_filters');
$html_to_blocks_convert_rest_callback = html_to_blocks_callable_name('html_to_blocks_convert_rest_response');
/**
 * Register REST API response filters for all supported post types.
 *
 * The block editor fetches posts via the REST API and reads content.raw.
 * If content.raw is HTML (no block delimiters), we convert it to blocks
 * so the editor works natively.
 *
 * Runs at priority 10 — after any upstream filters (e.g. markdown → HTML
 * at priority 5) have already converted to HTML.
 *
 * The REST callback is computed from __NAMESPACE__ because WordPress stores the
 * callback string and invokes it later.
 */
if (!\function_exists($html_to_blocks_register_rest_callback)) {
    function html_to_blocks_register_rest_filters()
    {
        $convert_rest_callback = html_to_blocks_callable_name('html_to_blocks_convert_rest_response');
        $default_types = \array_keys(get_post_types(array('show_in_rest' => \true, 'public' => \true)));
        $supported_types = apply_filters('html_to_blocks_supported_post_types', $default_types);
        foreach ($supported_types as $post_type) {
            if (!\function_exists('has_filter') || \false === \has_filter("rest_prepare_{$post_type}", $convert_rest_callback)) {
                // @phpstan-ignore-next-line WordPress accepts callable strings resolved at runtime.
                \add_filter("rest_prepare_{$post_type}", $convert_rest_callback, 10, 3);
            }
        }
    }
}
// Priority 20: run after plugins have registered custom post types at the
// default init priority (10), so get_post_types() sees the full REST surface.
if (\function_exists('add_action') && (!\function_exists('has_action') || \false === \has_action('init', $html_to_blocks_register_rest_callback))) {
    // @phpstan-ignore-next-line WordPress accepts callable strings resolved at runtime.
    \add_action('init', $html_to_blocks_register_rest_callback, 20);
}
/**
 * Convert HTML to blocks in REST API responses.
 *
 * Only converts content.raw when the request has edit context (i.e. the
 * block editor is loading the post). Frontend REST requests are untouched.
 *
 * @param WP_REST_Response $response The response object.
 * @param WP_Post          $post     The post object.
 * @param WP_REST_Request  $request  The request object.
 * @return WP_REST_Response Modified response.
 */
if (!\function_exists($html_to_blocks_convert_rest_callback)) {
    function html_to_blocks_convert_rest_response($response, $post, $request)
    {
        // Only convert for edit context (block editor).
        if ($request->get_param('context') !== 'edit') {
            return $response;
        }
        $data = $response->get_data();
        if (empty($data['content']['raw'])) {
            return $response;
        }
        $raw = $data['content']['raw'];
        // Already block markup — nothing to do.
        if (\strpos($raw, '<!-- wp:') !== \false) {
            return $response;
        }
        $serialized = html_to_blocks_convert_content($raw);
        if (null !== $serialized) {
            $data['content']['raw'] = $serialized;
            $response->set_data($data);
        }
        return $response;
    }
}
// ---------------------------------------------------------------------------
// Shared helpers.
// ---------------------------------------------------------------------------
/**
 * Check if a post type is supported for conversion.
 *
 * @param string $post_type The post type slug.
 * @return bool
 */
$html_to_blocks_is_supported_type_callback = html_to_blocks_callable_name('html_to_blocks_is_supported_type');
if (!\function_exists($html_to_blocks_is_supported_type_callback)) {
    function html_to_blocks_is_supported_type(string $post_type): bool
    {
        $default_types = \array_keys(get_post_types(array('show_in_rest' => \true, 'public' => \true)));
        $supported_types = apply_filters('html_to_blocks_supported_post_types', $default_types);
        return \in_array($post_type, $supported_types, \true);
    }
}
/**
 * Convert HTML content to serialized block markup.
 *
 * Returns null if conversion fails or would lose significant content.
 *
 * @param string $html The HTML content.
 * @return string|null Serialized block markup, or null on failure.
 */
$html_to_blocks_convert_content_callback = html_to_blocks_callable_name('html_to_blocks_convert_content');
if (!\function_exists($html_to_blocks_convert_content_callback)) {
    function html_to_blocks_convert_content(string $html): ?string
    {
        $blocks = html_to_blocks_raw_handler(array('HTML' => $html));
        if (empty($blocks)) {
            return null;
        }
        $serialized = serialize_blocks($blocks);
        $serialized_text_length = \strlen(wp_strip_all_tags($serialized));
        $original_text_length = \strlen(wp_strip_all_tags($html));
        // Safety: abort if we'd lose significant content.
        if ($original_text_length > 50 && $serialized_text_length < $original_text_length * 0.3) {
            \do_action('html_to_blocks_conversion_aborted_content_loss', $original_text_length, $serialized_text_length);
            return null;
        }
        return $serialized;
    }
}

<?php
/**
 * Smoke coverage for scoped h2bc WordPress hook registration.
 *
 * @package BlockFormatBridge
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/../' );

$GLOBALS['bfb_smoke_filters'] = array();

function bfb_smoke_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

function add_filter( string $hook_name, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
	$GLOBALS['bfb_smoke_filters'][ $hook_name ][ $priority ][] = array(
		'callback'      => $callback,
		'accepted_args' => $accepted_args,
	);

	return true;
}

function add_action( string $hook_name, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
	return add_filter( $hook_name, $callback, $priority, $accepted_args );
}

function has_filter( string $hook_name, $callback = false ) {
	if ( ! isset( $GLOBALS['bfb_smoke_filters'][ $hook_name ] ) ) {
		return false;
	}

	if ( false === $callback ) {
		return true;
	}

	foreach ( $GLOBALS['bfb_smoke_filters'][ $hook_name ] as $priority => $entries ) {
		foreach ( $entries as $entry ) {
			if ( $entry['callback'] === $callback ) {
				return $priority;
			}
		}
	}

	return false;
}

function has_action( string $hook_name, $callback = false ) {
	return has_filter( $hook_name, $callback );
}

$hooks_file    = __DIR__ . '/../vendor_prefixed/chubes4/html-to-blocks-converter/includes/hooks.php';
$versions_file = __DIR__ . '/../vendor_prefixed/chubes4/html-to-blocks-converter/includes/class-html-to-blocks-versions.php';
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Static smoke reads local bundled source.
$hooks_source_raw = file_get_contents( $hooks_file );

if ( ! is_string( $hooks_source_raw ) ) {
	bfb_smoke_assert( false, 'Scoped h2bc hooks.php should be readable.' );
}
$hooks_source = is_string( $hooks_source_raw ) ? $hooks_source_raw : '';

foreach ( array( 'add_filter', 'has_filter', 'add_action', 'has_action' ) as $function_name ) {
	bfb_smoke_assert(
		! str_contains( $hooks_source, "BlockFormatBridge\\Vendor\\{$function_name}" ),
		"Scoped h2bc hooks.php should not prefix WordPress {$function_name} guards."
	);
}

$raw_handler_source = file_get_contents( __DIR__ . '/../vendor_prefixed/chubes4/html-to-blocks-converter/raw-handler.php' );
bfb_smoke_assert( is_string( $raw_handler_source ), 'Scoped h2bc raw-handler.php should be readable.' );
bfb_smoke_assert(
	str_contains( (string) $raw_handler_source, "'BlockFormatBridge\\Vendor\\html_to_blocks_raw_handler'" ),
	'Scoped h2bc recursive raw-handler callback should target the prefixed function.'
);

require_once $versions_file;
\BlockFormatBridge\Vendor\HTML_To_Blocks_Versions::register_hooks();

$plugins_loaded_callback = array( \BlockFormatBridge\Vendor\HTML_To_Blocks_Versions::class, 'initialize_latest_version' );
bfb_smoke_assert(
	1 === has_action( 'plugins_loaded', $plugins_loaded_callback ),
	'Scoped h2bc version registry should register on global plugins_loaded.'
);

require_once $hooks_file;

bfb_smoke_assert(
	10 === has_filter( 'wp_insert_post_data', 'BlockFormatBridge\\Vendor\\html_to_blocks_convert_on_insert' ),
	'Scoped h2bc should register wp_insert_post_data on the global hook API.'
);

bfb_smoke_assert(
	20 === has_action( 'init', 'BlockFormatBridge\\Vendor\\html_to_blocks_register_rest_filters' ),
	'Scoped h2bc should register init on the global hook API.'
);

echo "PASS: scoped h2bc hooks register globally\n";

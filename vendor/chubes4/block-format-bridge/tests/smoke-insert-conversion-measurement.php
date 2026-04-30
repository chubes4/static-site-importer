<?php
/**
 * Smoke coverage for insert conversion timing instrumentation.
 *
 * @package BlockFormatBridge
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/../' );

$GLOBALS['bfb_smoke_filters']           = array();
$GLOBALS['bfb_smoke_conversion_output'] = '<!-- wp:paragraph --><p>Converted</p><!-- /wp:paragraph -->';

function bfb_smoke_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

function bfb_smoke_reset_hooks(): void {
	$GLOBALS['bfb_smoke_filters'] = array();
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

function apply_filters( string $hook_name, $value, ...$args ) {
	if ( empty( $GLOBALS['bfb_smoke_filters'][ $hook_name ] ) ) {
		return $value;
	}

	ksort( $GLOBALS['bfb_smoke_filters'][ $hook_name ] );
	foreach ( $GLOBALS['bfb_smoke_filters'][ $hook_name ] as $entries ) {
		foreach ( $entries as $entry ) {
			$accepted = max( 1, (int) $entry['accepted_args'] );
			$params   = array_slice( array_merge( array( $value ), $args ), 0, $accepted );
			$value    = call_user_func_array( $entry['callback'], $params );
		}
	}

	return $value;
}

function do_action( string $hook_name, ...$args ): void {
	if ( empty( $GLOBALS['bfb_smoke_filters'][ $hook_name ] ) ) {
		return;
	}

	ksort( $GLOBALS['bfb_smoke_filters'][ $hook_name ] );
	foreach ( $GLOBALS['bfb_smoke_filters'][ $hook_name ] as $entries ) {
		foreach ( $entries as $entry ) {
			$accepted = (int) $entry['accepted_args'];
			$params   = array_slice( $args, 0, $accepted );
			call_user_func_array( $entry['callback'], $params );
		}
	}
}

function wp_unslash( $value ) {
	return $value;
}

function wp_slash( $value ) {
	return $value;
}

function __return_true(): bool {
	return true;
}

function bfb_get_adapter( string $format ) {
	return 'markdown' === $format ? new stdClass() : null;
}

function bfb_convert( string $content, string $from, string $to, array $options = array() ): string {
	unset( $options );
	return (string) $GLOBALS['bfb_smoke_conversion_output'];
}

require_once __DIR__ . '/../includes/hooks.php';

$input  = "# Heading\n\nParagraph.";
$events = array();
add_action(
	'bfb_insert_conversion_measured',
	static function ( array $measurement ) use ( &$events ): void {
		$events[] = $measurement;
	},
	10,
	1
);

$result = bfb_convert_on_insert(
	array(
		'post_content' => $input,
		'post_type'    => 'wiki',
	),
	array( '_bfb_format' => 'markdown' )
);

bfb_smoke_assert( $GLOBALS['bfb_smoke_conversion_output'] === $result['post_content'], 'Successful conversion should update post_content.' );
bfb_smoke_assert( 1 === count( $events ), 'Measurement action should fire once after successful conversion.' );

$event = $events[0];
bfb_smoke_assert( 'markdown' === $event['format'], 'Measurement should include the resolved format.' );
bfb_smoke_assert( 'markdown' === $event['from'], 'Measurement should include the source format.' );
bfb_smoke_assert( 'blocks' === $event['to'], 'Measurement should include the target format.' );
bfb_smoke_assert( 'wiki' === $event['post_type'], 'Measurement should include the post type.' );
bfb_smoke_assert( strlen( $input ) === $event['input_bytes'], 'Measurement should include input byte length.' );
bfb_smoke_assert( strlen( $GLOBALS['bfb_smoke_conversion_output'] ) === $event['output_bytes'], 'Measurement should include output byte length.' );
bfb_smoke_assert( is_float( $event['duration_ms'] ) || is_int( $event['duration_ms'] ), 'Measurement should include numeric duration.' );
bfb_smoke_assert( $event['duration_ms'] >= 0, 'Measurement duration should be non-negative.' );

bfb_smoke_reset_hooks();
$events = array();
add_filter( 'bfb_skip_insert_conversion', '__return_true', 10, 4 );
add_action(
	'bfb_insert_conversion_measured',
	static function ( array $measurement ) use ( &$events ): void {
		$events[] = $measurement;
	},
	10,
	1
);

$result = bfb_convert_on_insert(
	array(
		'post_content' => $input,
		'post_type'    => 'wiki',
	),
	array( '_bfb_format' => 'markdown' )
);

bfb_smoke_assert( $input === $result['post_content'], 'Skipped conversion should leave post_content untouched.' );
bfb_smoke_assert( array() === $events, 'Measurement action should not fire when conversion is skipped.' );

bfb_smoke_reset_hooks();
$events                                  = array();
$GLOBALS['bfb_smoke_conversion_output'] = '';
add_action(
	'bfb_insert_conversion_measured',
	static function ( array $measurement ) use ( &$events ): void {
		$events[] = $measurement;
	},
	10,
	1
);

$result = bfb_convert_on_insert(
	array(
		'post_content' => $input,
		'post_type'    => 'wiki',
	),
	array( '_bfb_format' => 'markdown' )
);

bfb_smoke_assert( $input === $result['post_content'], 'Empty conversion output should leave post_content untouched.' );
bfb_smoke_assert( array() === $events, 'Measurement action should not fire when conversion produces no output.' );

echo "PASS: insert conversion measurement hook\n";

<?php
/**
 * Smoke coverage for duplicate semantic-version registry behavior.
 *
 * @package BlockFormatBridge
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/../' );

$GLOBALS['bfb_duplicate_loaded_versions'] = array();

function bfb_duplicate_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

function do_action( string $hook_name, ...$args ): void {
	if ( 'bfb_loaded' === $hook_name ) {
		$GLOBALS['bfb_duplicate_loaded_versions'][] = $args[0] ?? null;
	}
}

function esc_html( string $text ): string {
	return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
}

require_once __DIR__ . '/../includes/class-bfb-versions.php';

function bfb_duplicate_reset_registry(): BFB_Versions {
	$registry = BFB_Versions::instance();

	foreach ( array( 'versions', 'initialized', 'registration_order' ) as $property_name ) {
		$property = new ReflectionProperty( $registry, $property_name );

		if ( 'versions' === $property_name ) {
			$property->setValue( $registry, array() );
		} elseif ( 'initialized' === $property_name ) {
			$property->setValue( $registry, false );
		} else {
			$property->setValue( $registry, 0 );
		}
	}

	$GLOBALS['bfb_duplicate_loaded_versions'] = array();

	return $registry;
}

$registry      = bfb_duplicate_reset_registry();
$api_surface   = array();
$initialized   = array();
$old_source    = __DIR__ . '/fixtures/old-bfb-copy';
$new_source    = __DIR__ . '/fixtures/new-bfb-copy';
$old_api_loader = static function () use ( &$api_surface, &$initialized ): void {
	$api_surface   = array( 'bfb_convert' );
	$initialized[] = 'old';
};
$new_api_loader = static function () use ( &$api_surface, &$initialized ): void {
	$api_surface   = array( 'bfb_convert', 'bfb_normalize' );
	$initialized[] = 'new';
};

$registry->register( '0.3.0', $old_api_loader, $old_source );
$registry->register( '0.4.0', $new_api_loader, $new_source );
$registry->initialize_latest();

bfb_duplicate_assert( array( 'new' ) === $initialized, 'Different semantic versions should still initialize the highest version.' );
bfb_duplicate_assert( array( '0.4.0' ) === $GLOBALS['bfb_duplicate_loaded_versions'], 'bfb_loaded should receive the highest version.' );

$registry      = bfb_duplicate_reset_registry();
$api_surface   = array();
$initialized   = array();
$warnings      = array();
set_error_handler(
	static function ( int $errno, string $errstr ) use ( &$warnings ): bool {
		if ( E_USER_WARNING === $errno && str_contains( $errstr, 'Block Format Bridge version 0.3.0 was registered by multiple sources' ) ) {
			$warnings[] = $errstr;
			return true;
		}

		return false;
	}
);

$registry->register( '0.3.0', $old_api_loader, $old_source );
$registry->register( '0.3.0', $new_api_loader, $new_source );
restore_error_handler();

$versions = new ReflectionProperty( $registry, 'versions' );
$registered_versions = $versions->getValue( $registry );

bfb_duplicate_assert( 1 === count( $warnings ), 'Duplicate semantic versions from different sources should emit one warning.' );
bfb_duplicate_assert( str_contains( $warnings[0], $old_source ), 'Duplicate warning should include the first source.' );
bfb_duplicate_assert( str_contains( $warnings[0], $new_source ), 'Duplicate warning should include the later source.' );
bfb_duplicate_assert( array( '0.3.0', '0.3.0' ) === array_column( $registered_versions, 'version' ), 'Duplicate versions should both remain registered.' );

$registry->initialize_latest();

bfb_duplicate_assert( array( 'new' ) === $initialized, 'Same-version duplicate ties should deterministically initialize the later registration.' );
bfb_duplicate_assert( array( 'bfb_convert', 'bfb_normalize' ) === $api_surface, 'Same-version duplicate ties should load the later registration API surface.' );
bfb_duplicate_assert( array( '0.3.0' ) === $GLOBALS['bfb_duplicate_loaded_versions'], 'bfb_loaded should still fire once for duplicate-version ties.' );

echo "PASS: duplicate BFB version registry behavior\n";

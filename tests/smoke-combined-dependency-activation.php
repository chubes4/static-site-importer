<?php
/**
 * Smoke coverage for SSI loading alongside standalone BFB/H2BC plugins.
 *
 * Simulates a WordPress request where standalone html-to-blocks-converter and
 * block-format-bridge load before Static Site Importer's Composer autoload.
 *
 * @package StaticSiteImporter
 */

declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__ ) . '/' );

$GLOBALS['ssi_smoke_filters']        = array();
$GLOBALS['ssi_smoke_actions_done']   = array();
$GLOBALS['ssi_smoke_actions_active'] = array();
$GLOBALS['ssi_smoke_bfb_loaded']     = array();
$GLOBALS['ssi_smoke_h2bc_loaded']    = array();

function ssi_smoke_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

function add_filter( string $hook_name, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
	$GLOBALS['ssi_smoke_filters'][ $hook_name ][ $priority ][] = array(
		'callback'      => $callback,
		'accepted_args' => $accepted_args,
	);

	return true;
}

function add_action( string $hook_name, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
	return add_filter( $hook_name, $callback, $priority, $accepted_args );
}

function has_filter( string $hook_name, $callback = false ) {
	if ( ! isset( $GLOBALS['ssi_smoke_filters'][ $hook_name ] ) ) {
		return false;
	}

	if ( false === $callback ) {
		return true;
	}

	foreach ( $GLOBALS['ssi_smoke_filters'][ $hook_name ] as $priority => $entries ) {
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

function do_action( string $hook_name, ...$args ): void {
	$GLOBALS['ssi_smoke_actions_done'][ $hook_name ] = ( $GLOBALS['ssi_smoke_actions_done'][ $hook_name ] ?? 0 ) + 1;
	$GLOBALS['ssi_smoke_actions_active'][ $hook_name ] = ( $GLOBALS['ssi_smoke_actions_active'][ $hook_name ] ?? 0 ) + 1;

	try {
		$priorities = array_keys( $GLOBALS['ssi_smoke_filters'][ $hook_name ] ?? array() );
		sort( $priorities, SORT_NUMERIC );

		foreach ( $priorities as $priority ) {
			foreach ( $GLOBALS['ssi_smoke_filters'][ $hook_name ][ $priority ] as $entry ) {
				$callback = $entry['callback'];
				if ( is_callable( $callback ) ) {
					call_user_func_array( $callback, array_slice( $args, 0, $entry['accepted_args'] ) );
				}
			}
		}
	} finally {
		--$GLOBALS['ssi_smoke_actions_active'][ $hook_name ];
	}
}

function did_action( string $hook_name ): int {
	return (int) ( $GLOBALS['ssi_smoke_actions_done'][ $hook_name ] ?? 0 );
}

function doing_action( ?string $hook_name = null ): bool {
	if ( null === $hook_name ) {
		return ! empty( array_filter( $GLOBALS['ssi_smoke_actions_active'] ) );
	}

	return ! empty( $GLOBALS['ssi_smoke_actions_active'][ $hook_name ] );
}

function apply_filters( string $hook_name, $value, ...$args ) {
	foreach ( $GLOBALS['ssi_smoke_filters'][ $hook_name ] ?? array() as $entries ) {
		foreach ( $entries as $entry ) {
			$callback = $entry['callback'];
			if ( is_callable( $callback ) ) {
				$value = call_user_func_array( $callback, array_merge( array( $value ), array_slice( $args, 0, max( 0, $entry['accepted_args'] - 1 ) ) ) );
			}
		}
	}

	return $value;
}

function plugin_dir_path( string $file ): string {
	return trailingslashit( dirname( $file ) );
}

function plugin_dir_url( string $file ): string {
	return 'https://example.test/wp-content/plugins/' . basename( dirname( $file ) ) . '/';
}

function trailingslashit( string $path ): string {
	return rtrim( $path, '/\\' ) . '/';
}

function esc_html( string $text ): string {
	return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
}

function __( string $text, string $domain = 'default' ): string {
	unset( $domain );
	return $text;
}

function ssi_smoke_remove_path( string $path ): void {
	if ( ! file_exists( $path ) ) {
		return;
	}

	if ( is_file( $path ) || is_link( $path ) ) {
		unlink( $path );
		return;
	}

	$entries = scandir( $path );
	if ( ! is_array( $entries ) ) {
		return;
	}

	foreach ( $entries as $entry ) {
		if ( '.' === $entry || '..' === $entry ) {
			continue;
		}

		ssi_smoke_remove_path( $path . '/' . $entry );
	}

	rmdir( $path );
}

function ssi_smoke_copy_path( string $source, string $destination ): void {
	if ( is_dir( $source ) ) {
		if ( ! is_dir( $destination ) ) {
			mkdir( $destination, 0777, true );
		}

		$entries = scandir( $source );
		ssi_smoke_assert( is_array( $entries ), "Should read {$source}." );

		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry || '.git' === $entry ) {
				continue;
			}

			ssi_smoke_copy_path( $source . '/' . $entry, $destination . '/' . $entry );
		}

		return;
	}

	ssi_smoke_assert( copy( $source, $destination ), "Should copy {$source}." );
}

function ssi_smoke_hook_count( string $hook_name, $callback ): int {
	$count = 0;

	foreach ( $GLOBALS['ssi_smoke_filters'][ $hook_name ] ?? array() as $entries ) {
		foreach ( $entries as $entry ) {
			if ( $entry['callback'] === $callback ) {
				++$count;
			}
		}
	}

	return $count;
}

add_action(
	'bfb_loaded',
	static function ( string $version ): void {
		$GLOBALS['ssi_smoke_bfb_loaded'][] = $version;
	},
	10,
	1
);
add_action(
	'html_to_blocks_loaded',
	static function ( string $version ): void {
		$GLOBALS['ssi_smoke_h2bc_loaded'][] = $version;
	},
	10,
	1
);

$root                 = dirname( __DIR__ );
$temp_root            = sys_get_temp_dir() . '/ssi-combined-deps-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );
$standalone_bfb_path  = $temp_root . '/block-format-bridge';
$standalone_h2bc_path = $temp_root . '/html-to-blocks-converter';
$ssi_bfb_path         = $root . '/vendor/chubes4/block-format-bridge';
$ssi_h2bc_path        = $root . '/vendor/chubes4/html-to-blocks-converter';
$duplicate_warnings   = array();

try {
	ssi_smoke_copy_path( $ssi_h2bc_path, $standalone_h2bc_path );
	ssi_smoke_copy_path( $ssi_bfb_path, $standalone_bfb_path );
	mkdir( $standalone_bfb_path . '/vendor', 0777, true );
	file_put_contents(
		$standalone_bfb_path . '/vendor/autoload.php',
		"<?php\nrequire_once " . var_export( $standalone_h2bc_path . '/library.php', true ) . ";\n"
	);

	set_error_handler(
		static function ( int $errno, string $errstr ) use ( &$duplicate_warnings ): bool {
			if ( E_USER_WARNING === $errno && str_contains( $errstr, 'Block Format Bridge version' ) && str_contains( $errstr, 'registered by multiple sources' ) ) {
				$duplicate_warnings[] = $errstr;
				return true;
			}

			return false;
		}
	);

	// Standalone plugins load first, then SSI's Composer autoload loads its copies.
	require $standalone_h2bc_path . '/library.php';
	require $standalone_bfb_path . '/library.php';
	require $root . '/static-site-importer.php';
	ssi_smoke_assert( class_exists( 'BFB_Versions', false ), 'BFB version registry should load.' );
	ssi_smoke_assert( class_exists( 'HTML_To_Blocks_Versions', false ), 'H2BC version registry should load.' );
	ssi_smoke_assert( 1 === ssi_smoke_hook_count( 'plugins_loaded', array( 'BFB_Versions', 'initialize_latest_version' ) ), 'BFB initializer hook should register once.' );
	ssi_smoke_assert( 1 === ssi_smoke_hook_count( 'plugins_loaded', array( 'HTML_To_Blocks_Versions', 'initialize_latest_version' ) ), 'H2BC initializer hook should register once.' );

	do_action( 'plugins_loaded' );
	restore_error_handler();

	ssi_smoke_assert( 1 === count( $duplicate_warnings ), 'Duplicate BFB same-version registration should warn once.' );

	$ssi_bfb_real  = realpath( $ssi_bfb_path );
	$ssi_h2bc_real = realpath( $ssi_h2bc_path );
	ssi_smoke_assert( is_string( $ssi_bfb_real ), 'SSI BFB path should resolve.' );
	ssi_smoke_assert( is_string( $ssi_h2bc_real ), 'SSI H2BC path should resolve.' );

	ssi_smoke_assert( defined( 'BFB_PATH' ) && trailingslashit( (string) $ssi_bfb_real ) === BFB_PATH, 'SSI Composer BFB copy should win the same-version tie.' );
	ssi_smoke_assert( function_exists( 'bfb_convert' ), 'BFB API should load after combined activation.' );
	ssi_smoke_assert( function_exists( 'bfb_normalize' ), 'BFB normalize API should load after combined activation.' );
	ssi_smoke_assert( function_exists( 'html_to_blocks_raw_handler' ), 'H2BC raw handler should load after combined activation.' );

	$normalize_ref = new ReflectionFunction( 'bfb_normalize' );
	$h2bc_ref      = new ReflectionFunction( 'html_to_blocks_raw_handler' );
	ssi_smoke_assert( $ssi_bfb_real . '/includes/normalization.php' === $normalize_ref->getFileName(), 'bfb_normalize() should come from SSI Composer BFB.' );
	ssi_smoke_assert( $ssi_h2bc_real . '/raw-handler.php' === $h2bc_ref->getFileName(), 'html_to_blocks_raw_handler() should come from SSI Composer H2BC.' );
	ssi_smoke_assert( array( '0.8.1' ) === $GLOBALS['ssi_smoke_bfb_loaded'], 'bfb_loaded should fire once.' );
	ssi_smoke_assert( array( '0.7.2' ) === $GLOBALS['ssi_smoke_h2bc_loaded'], 'html_to_blocks_loaded should fire once.' );
	ssi_smoke_assert( 1 === ssi_smoke_hook_count( 'wp_insert_post_data', 'bfb_convert_on_insert' ), 'BFB insert hook should register once.' );
	ssi_smoke_assert( 1 === ssi_smoke_hook_count( 'wp_insert_post_data', 'html_to_blocks_convert_on_insert' ), 'H2BC insert hook should register once.' );
} finally {
	restore_error_handler();
	ssi_smoke_remove_path( $temp_root );
}

echo "OK: combined dependency activation smoke passed\n";

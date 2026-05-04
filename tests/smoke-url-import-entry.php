<?php
/**
 * Smoke test: URL intake exposes CLI/admin hooks and SSRF rejection basics.
 *
 * Run from the repository root:
 * php tests/smoke-url-import-entry.php
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private string $code;
		private string $message;

		public function __construct( string $code, string $message ) {
			$this->code    = $code;
			$this->message = $message;
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ): bool {
		return $thing instanceof WP_Error;
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( string $url ) {
		return parse_url( $url );
	}
}

$root    = dirname( __DIR__ );
$fetcher = $wp_filesystem->get_contents( $root . '/includes/class-static-site-importer-url-fetcher.php' );
$cli     = $wp_filesystem->get_contents( $root . '/includes/class-static-site-importer-cli-command.php' );
$admin   = $wp_filesystem->get_contents( $root . '/includes/class-static-site-importer-admin.php' );

if ( false === $fetcher || false === $cli || false === $admin ) {
	fwrite( STDERR, "FAIL [source-readable]\n" );
	exit( 1 );
}

require_once $root . '/includes/class-static-site-importer-url-fetcher.php';

$assertions = 0;
$failures   = array();

$assert = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
	$assertions++;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
	}
};

$assert_error = static function ( string $url, string $code ) use ( $assert ): void {
	$result = Static_Site_Importer_URL_Fetcher::validate_url( $url );
	$assert( is_wp_error( $result ), 'url-rejected-' . $code, $url );
	if ( is_wp_error( $result ) ) {
		$assert( $code === $result->get_error_code(), 'url-rejected-code-' . $code, $result->get_error_code() );
	}
};

$assert( str_contains( $fetcher, "array( 'http', 'https' )" ), 'http-https-only' );
$assert( str_contains( $fetcher, 'FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE' ), 'private-reserved-ip-filter-present' );
$assert( str_contains( $fetcher, 'stream_socket_client' ), 'connects-to-validated-ip' );
$assert( str_contains( $fetcher, 'Cookie' ) === false, 'no-cookie-forwarding' );
$assert( str_contains( $fetcher, 'Authorization' ) === false, 'no-authorization-forwarding' );
$assert( str_contains( $fetcher, 'MAX_REDIRECTS' ), 'redirect-limit-present' );
$assert( str_contains( $fetcher, 'DEFAULT_MAX_BYTES' ), 'max-response-size-present' );
$assert( str_contains( $fetcher, 'text/html' ), 'html-content-type-required' );
$assert( str_contains( $cli, '[--url=<url>]' ), 'cli-import-theme-url-option-present' );
$assert( str_contains( $cli, 'public function import_url' ), 'cli-import-url-subcommand-present' );
$assert( str_contains( $admin, 'name="static_site_url"' ), 'admin-url-field-present' );
$assert( str_contains( $admin, "'source_metadata' => $" . "entry['metadata']" ), 'admin-passes-source-metadata' );

$assert_error( 'ftp://example.com/', 'static_site_importer_url_scheme' );
$assert_error( 'https://user:pass@example.com/', 'static_site_importer_url_credentials' );
$assert_error( 'http://localhost/', 'static_site_importer_url_host' );
$assert_error( 'http://127.0.0.1/', 'static_site_importer_url_private_ip' );
$assert_error( 'http://169.254.169.254/', 'static_site_importer_url_private_ip' );
$assert_error( 'http://10.0.0.1/', 'static_site_importer_url_private_ip' );
$assert_error( 'http://[::1]/', 'static_site_importer_url_private_ip' );

$public = Static_Site_Importer_URL_Fetcher::validate_url( 'http://93.184.216.34/' );
$assert( ! is_wp_error( $public ), 'public-url-validates', is_wp_error( $public ) ? $public->get_error_message() : '' );
if ( ! is_wp_error( $public ) ) {
	$assert( 'http' === $public['scheme'], 'public-url-scheme' );
	$assert( '93.184.216.34' === $public['host'], 'public-url-host' );
	$assert( '/' === $public['path'], 'public-url-path' );
	$assert( ! empty( $public['ips'] ), 'public-url-resolves' );
}

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo 'OK: URL import entry smoke passed (' . $assertions . " assertions)\n";

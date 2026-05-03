<?php
/**
 * Static smoke coverage for the WP-CLI wrapper contract.
 *
 * @package BlockFormatBridge
 */

declare(strict_types=1);

function bfb_cli_smoke_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

$cli_source = file_get_contents( __DIR__ . '/../includes/cli.php' );
$library    = file_get_contents( __DIR__ . '/../library.php' );

bfb_cli_smoke_assert( is_string( $cli_source ), 'CLI source should be readable.' );
bfb_cli_smoke_assert( is_string( $library ), 'Library source should be readable.' );

$cli_source = (string) $cli_source;
$library    = (string) $library;

bfb_cli_smoke_assert( strpos( $library, "includes/cli.php" ) !== false, 'library.php should load the CLI integration.' );
bfb_cli_smoke_assert( strpos( $library, "includes/abilities.php" ) !== false, 'library.php should load the Abilities API integration.' );
bfb_cli_smoke_assert( strpos( $cli_source, "WP_CLI::add_command( 'bfb', 'BFB_CLI_Command' )" ) !== false, 'CLI should register the bfb command namespace.' );
bfb_cli_smoke_assert( strpos( $cli_source, 'public function capabilities' ) !== false, 'CLI should expose a capabilities subcommand.' );
bfb_cli_smoke_assert( strpos( $cli_source, 'bfb_capabilities()' ) !== false, 'Capabilities CLI should wrap the PHP report helper.' );
bfb_cli_smoke_assert( strpos( $cli_source, "'json' === \$format" ) !== false, 'Capabilities CLI should support --format=json.' );
bfb_cli_smoke_assert( strpos( $cli_source, 'public function convert' ) !== false, 'CLI should expose a convert subcommand.' );
bfb_cli_smoke_assert( strpos( $cli_source, 'public function analyze' ) !== false, 'CLI should expose an analyze subcommand.' );
bfb_cli_smoke_assert( strpos( $cli_source, 'bfb_conversion_report( $content, $from )' ) !== false, 'Analyze CLI should wrap the conversion report helper.' );
bfb_cli_smoke_assert( strpos( $cli_source, 'Status: %s' ) !== false, 'Analyze summary should surface structured conversion status.' );
bfb_cli_smoke_assert( strpos( $cli_source, 'Diagnostic: %s (%s) - %s' ) !== false, 'Analyze summary should surface structured diagnostics.' );
bfb_cli_smoke_assert( strpos( $cli_source, 'Agent guidance: %s' ) !== false, 'Analyze summary should surface agent-safe guidance.' );
bfb_cli_smoke_assert( strpos( $cli_source, "file_get_contents( 'php://stdin' )" ) !== false, 'CLI should read STDIN when --input is omitted.' );
bfb_cli_smoke_assert( strpos( $cli_source, 'file_get_contents( $path )' ) !== false, 'CLI should read file input when --input is present.' );
bfb_cli_smoke_assert( strpos( $cli_source, 'file_put_contents( $path, $content )' ) !== false, 'CLI should write file output when --output is present.' );
bfb_cli_smoke_assert( strpos( $cli_source, 'WP_CLI::line( $content )' ) !== false, 'CLI should write STDOUT when --output is omitted.' );
bfb_cli_smoke_assert( strpos( $cli_source, 'bfb_convert( $content, $from, $to )' ) !== false, 'Content output should wrap bfb_convert().' );
bfb_cli_smoke_assert( strpos( $cli_source, 'bfb_to_blocks( $content, $from )' ) !== false, 'JSON block output should wrap bfb_to_blocks().' );
bfb_cli_smoke_assert( strpos( $cli_source, '--as=json is only supported with --to=blocks.' ) !== false, 'Structured output should be explicit instead of overloading content output.' );
bfb_cli_smoke_assert( substr_count( $cli_source, 'bfb_get_adapter(' ) >= 2, 'CLI should fail fast for unsupported source and target formats.' );

echo "PASS: CLI command wrapper contract\n";

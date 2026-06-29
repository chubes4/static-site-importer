<?php
/**
 * Smoke coverage for the import-website-artifact success envelope carrying the
 * static-site-importer/import-diagnostics/v1 contract and firing the completion hook.
 *
 * Run from the repository root:
 * php tests/smoke-ability-import-success-diagnostics.php
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! function_exists( '__' ) ) {
	function __( string $text ): string {
		return $text;
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( string $key ): string {
		return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ) );
	}
}

if ( ! function_exists( 'doing_action' ) ) {
	function doing_action( string $hook_name ): bool {
		unset( $hook_name );
		return false;
	}
}

if ( ! function_exists( 'did_action' ) ) {
	function did_action( string $hook_name ): int {
		unset( $hook_name );
		return 0;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook_name, string $callback ): void {
		unset( $hook_name, $callback );
	}
}

$GLOBALS['ssi_smoke_fired_hooks'] = array();
if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $hook_name, ...$args ): void {
		$GLOBALS['ssi_smoke_fired_hooks'][] = array(
			'hook' => $hook_name,
			'args' => $args,
		);
	}
}

require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-diagnostic-contract.php';
require_once dirname( __DIR__ ) . '/includes/abilities.php';

$failures   = array();
$assertions = 0;
$assert     = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
	++$assertions;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
	}
};

// A representative success result shaped like
// Static_Site_Importer_Theme_Generator::import_website_artifact() returns.
$result = array(
	'theme_slug'               => 'acme-co',
	'theme_name'               => 'Acme Co',
	'quality'                  => array(
		'fallback_count'                        => 2,
		'core_html_block_count'                 => 1,
		'runtime_dependency_parity_issue_count' => 1,
	),
	'import_report_summary'    => array(
		'status' => 'completed',
	),
	'import_validation_result' => array(
		'schema'      => 'blocks-engine/import-validation-result/v1',
		'status'      => 'reported',
		'diagnostics' => array(
			array(
				'type'        => 'core_html_block',
				'reason_code' => 'unconverted_markup',
				'severity'    => 'warning',
				'source_path' => 'website/index.html',
				'selector'    => 'div.hero',
				'message'     => 'Fell back to a core/html block.',
			),
		),
	),
);

$input    = array( 'slug' => 'acme-co' );
$envelope = static_site_importer_ability_import_success( $result, $input );

$assert( true === ( $envelope['success'] ?? false ), 'envelope-success-true' );
$assert( $result === ( $envelope['result'] ?? null ), 'envelope-preserves-raw-result' );
$assert( is_array( $envelope['fixture_diagnostics'] ?? null ), 'envelope-has-fixture-diagnostics' );

$contract = $envelope['fixture_diagnostics'];
$assert(
	Static_Site_Importer_Diagnostic_Contract::IMPORT_DIAGNOSTICS_SCHEMA === ( $contract['schema'] ?? '' ),
	'contract-schema-v1',
	(string) ( $contract['schema'] ?? '' )
);
$assert( true === ( $contract['success'] ?? false ), 'contract-success-true' );
$assert( 'acme-co' === ( $contract['fixture']['slug'] ?? '' ), 'contract-carries-slug' );
$assert( 2 === ( $contract['quality_counts']['fallback_count'] ?? -1 ), 'contract-quality-counts-from-result' );
$assert( 1 === ( $contract['quality_counts']['runtime_dependency_parity_issue_count'] ?? -1 ), 'contract-runtime-quality-count' );
$assert( ! empty( $contract['diagnostics'] ), 'contract-has-diagnostic-rows' );
$assert(
	is_array( $envelope['diagnostics'] ?? null ) && count( $envelope['diagnostics'] ) === count( $contract['diagnostics'] ),
	'envelope-diagnostics-match-contract'
);

$fired = array_values(
	array_filter(
		$GLOBALS['ssi_smoke_fired_hooks'],
		static fn ( array $entry ): bool => 'static_site_importer_import_completed' === $entry['hook']
	)
);
$assert( 1 === count( $fired ), 'completion-hook-fired-once', (string) count( $fired ) );
$assert( ( $fired[0]['args'][0] ?? null ) === $contract, 'hook-arg-contract' );
$assert( ( $fired[0]['args'][1] ?? null ) === $result, 'hook-arg-result' );
$assert( ( $fired[0]['args'][2] ?? null ) === $input, 'hook-arg-input' );

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo 'OK: ability import success diagnostics smoke passed (' . $assertions . " assertions)\n";

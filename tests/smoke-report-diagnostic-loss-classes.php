<?php
/**
 * Smoke coverage for loss classes in import reports, validation results, and finding packets.
 *
 * Run from the repository root:
 * php tests/smoke-report-diagnostic-loss-classes.php
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.keyFound
		$key = strtolower( (string) $key );

		return preg_replace( '/[^a-z0-9_\-]/', '', $key );
	}
}

require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-product-handoff-contract.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-diagnostic-loss-classes.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-artifact-diagnostics-adapter.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-report-diagnostics.php';

$failures   = array();
$assertions = 0;
$assert     = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
	++$assertions;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
	}
};

$report                  = Static_Site_Importer_Report_Diagnostics::new_conversion_report( 'website/index.html' );
$report['diagnostics'][] = array(
	'type'        => 'core_html_block',
	'source_path' => 'templates/front-page.html',
	'block_name'  => 'core/html',
);
$report['diagnostics'][] = array(
	'type'        => 'interaction_candidate',
	'source_path' => 'website/index.html',
	'selector'    => '.map iframe',
);
$report['diagnostics'][] = array(
	'type'        => 'svg_materialization_failure',
	'source_path' => 'assets/icon.svg',
);
$report['quality']['core_html_block_count']             = 1;
$report['quality']['interaction_candidate_count']       = 1;
$report['quality']['svg_materialization_failure_count'] = 1;

Static_Site_Importer_Report_Diagnostics::finalize_report( $report, array() );

$assert( 'editable_approximation' === ( $report['diagnostics'][0]['loss_class'] ?? '' ), 'report-diagnostic-editable-loss-class' );
$assert( 'preserved_runtime_island' === ( $report['diagnostics'][1]['loss_class'] ?? '' ), 'report-diagnostic-runtime-loss-class' );
$assert( 'importer_materialization_bug' === ( $report['diagnostics'][2]['loss_class'] ?? '' ), 'report-diagnostic-importer-loss-class' );
$assert( 1 === ( $report['compact_summary']['loss_class_summary']['editable_approximation'] ?? 0 ), 'compact-summary-editable-count' );
$assert( 1 === ( $report['import_validation_result']['diagnostic_summary']['loss_class']['preserved_runtime_island'] ?? 0 ), 'validation-summary-runtime-count' );
$assert( 'editable_approximation' === ( $report['import_validation_result']['diagnostics'][0]['loss_class'] ?? '' ), 'validation-diagnostic-loss-class' );
$assert( 'editable_approximation' === ( $report['finding_packets']['packets'][0]['loss_class'] ?? '' ), 'finding-packet-loss-class' );
$assert( 'editable_approximation' === ( $report['finding_packets']['packets'][0]['routing']['loss_class'] ?? '' ), 'finding-packet-routing-loss-class' );

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo 'OK: report diagnostic loss classes smoke passed (' . $assertions . " assertions)\n";

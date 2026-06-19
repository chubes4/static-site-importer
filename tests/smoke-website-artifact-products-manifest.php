<?php
/**
 * Smoke coverage for explicit products_manifest materialization.
 *
 * This intentionally avoids WP_UnitTestCase so it can run in lightweight agent
 * shells while guarding the explicit commerce handoff contract.
 */

$root   = dirname( __DIR__ );
$source = file_get_contents( $root . '/includes/class-static-site-importer-theme-generator.php' );

if ( false === $source ) {
	fwrite( STDERR, "Could not read theme generator source.\n" );
	exit( 1 );
}

$assert = static function ( bool $condition, string $message ) use ( $source ): void {
	if ( $condition ) {
		return;
	}

	fwrite( STDERR, "FAIL: {$message}\n" );
	exit( 1 );
};

$assert(
	! str_contains( $source, 'products_manifest_from_website_artifact' ),
	'website artifact import does not scan raw files for products.json'
);

$assert(
	! str_contains( $source, 'website_artifact_file_text_content' ),
	'website artifact import does not decode raw artifact file content for commerce manifests'
);

$assert(
	str_contains( $source, 'record_products_manifest_from_import_args( $args, $compiled )' ),
	'compiled artifact import records explicit products manifest before commerce context'
);

$assert(
	str_contains( $source, "isset( \$args['products_manifest'] )" ),
	'products manifest recording reads only explicit import args'
);

$assert(
	str_contains( $source, "\$source = 'import_args.products_manifest'" ),
	'products manifest report records explicit arg source'
);

$assert(
	str_contains( $source, 'record_commerce_context_summary( $args )' ),
	'compiled artifact import builds commerce context from explicit products manifest'
);

$assert(
	str_contains( $source, 'self::materialize_required_plugins( $args );' ),
	'direct compiled artifact import materializes plugins before product seeding'
);

$assert(
	str_contains( $source, 'validate_products_manifest(' ),
	'explicit products manifest is still validated before seeding/reporting'
);

$adapter_source = file_get_contents( $root . '/includes/class-static-site-importer-transformer-adapter.php' );
$assert(
	false !== $adapter_source && str_contains( $adapter_source, 'products_manifest_from_transformer_reports' ),
	'transformer adapter maps generic product reports in SSI'
);

$assert(
	false !== $adapter_source && str_contains( $adapter_source, "\$compiled['products_manifest']" ),
	'adapter exposes mapped product reports through compiled products_manifest'
);

fwrite( STDOUT, "website artifact products manifest smoke ok\n" );

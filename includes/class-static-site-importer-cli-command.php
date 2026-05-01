<?php
/**
 * WP-CLI command.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static site importer CLI command.
 */
class Static_Site_Importer_CLI_Command {

	/**
	 * Import an HTML file as a block theme.
	 *
	 * @subcommand import-theme
	 *
	 * ## OPTIONS
	 *
	 * <html-file>
	 * : Path to index.html.
	 *
	 * [--slug=<slug>]
	 * : Theme slug.
	 *
	 * [--name=<name>]
	 * : Theme name.
	 *
	 * [--activate]
	 * : Activate the generated theme.
	 *
	 * [--overwrite]
	 * : Overwrite an existing theme directory.
	 *
	 * [--fail-on-quality]
	 * : Exit non-zero when conversion quality checks report fallbacks or content loss.
	 *
	 * [--max-fallbacks=<count>]
	 * : Exit non-zero when unsupported HTML fallback count exceeds this threshold.
	 *
	 * @param array<int, string>   $args       Positional args.
	 * @param array<string, mixed> $assoc_args Associative args.
	 * @return void
	 */
	public function import_theme( array $args, array $assoc_args ): void {
		$html_file = $args[0] ?? '';
		if ( '' === $html_file ) {
			WP_CLI::error( 'Missing <html-file> argument.' );
			return;
		}

		$result = Static_Site_Importer_Theme_Generator::import_theme(
			$html_file,
			array(
				'slug'            => isset( $assoc_args['slug'] ) ? (string) $assoc_args['slug'] : '',
				'name'            => isset( $assoc_args['name'] ) ? (string) $assoc_args['name'] : '',
				'activate'        => isset( $assoc_args['activate'] ),
				'overwrite'       => isset( $assoc_args['overwrite'] ),
				'fail_on_quality' => isset( $assoc_args['fail-on-quality'] ),
				'max_fallbacks'   => isset( $assoc_args['max-fallbacks'] ) ? (int) $assoc_args['max-fallbacks'] : null,
			)
		);

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
			return;
		}

		WP_CLI::success( sprintf( 'Imported static site as block theme "%s" (%s).', $result['theme_name'], $result['theme_slug'] ) );
		WP_CLI::line( sprintf( 'Theme directory: %s', $result['theme_dir'] ) );
		WP_CLI::line( sprintf( 'Import report: %s', $result['report_path'] ) );
		WP_CLI::line( sprintf( 'Conversion quality: %s (%d unsupported HTML fallbacks, %d content-loss aborts).', $result['quality']['pass'] ? 'pass' : 'needs review', $result['quality']['fallback_count'], $result['quality']['content_loss_count'] ) );

		if ( ! $result['quality']['pass'] ) {
			WP_CLI::warning( 'Conversion quality checks reported issues. Inspect import-report.json for source fragments and diagnostics.' );
		}

		if ( ! empty( $result['quality']['fail_import'] ) ) {
			WP_CLI::error( 'Conversion quality gate failed. Theme files were written for inspection.' );
			return;
		}
	}
}

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
	 * [<html-file>]
	 * : Path to index.html. Optional when --url is provided.
	 *
	 * [--url=<url>]
	 * : Fetch one public http/https HTML URL and import it as index.html. Redirects are validated with SSRF protections before connecting.
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
	 * [--keep-source]
	 * : Preserve the source static-site directory after a successful clean import for debugging or development. Sources are always preserved when import quality checks report issues.
	 *
	 * [--fail-on-quality]
	 * : Exit non-zero when conversion quality checks report fallbacks, invalid blocks, or content loss.
	 *
	 * [--max-fallbacks=<count>]
	 * : Exit non-zero when unsupported HTML fallback count exceeds this threshold.
	 *
	 * [--report=<path>]
	 * : Copy the generated import report JSON to an external archive path.
	 *
	 * [--format=<format>]
	 * : Output format. Use json for machine-readable command output.
	 *
	 * @param array<int, string>   $args       Positional args.
	 * @param array<string, mixed> $assoc_args Associative args.
	 * @return void
	 */
	public function import_theme( array $args, array $assoc_args ): void {
		$html_file       = $args[0] ?? '';
		$source_metadata = array();
		if ( isset( $assoc_args['url'] ) && '' !== trim( (string) $assoc_args['url'] ) ) {
			if ( '' !== $html_file ) {
				WP_CLI::error( 'Use either <html-file> or --url, not both.' );
				return;
			}

			$fetch = Static_Site_Importer_URL_Fetcher::fetch_to_work_dir(
				(string) $assoc_args['url'],
				trailingslashit( wp_upload_dir()['basedir'] ) . 'static-site-importer/' . wp_generate_uuid4()
			);
			if ( is_wp_error( $fetch ) ) {
				WP_CLI::error( $fetch->get_error_message() );
				return;
			}

			$html_file       = $fetch['html_path'];
			$source_metadata = $fetch['metadata'];
		}

		if ( '' === $html_file ) {
			WP_CLI::error( 'Missing <html-file> argument or --url option.' );
			return;
		}

		$result = Static_Site_Importer_Theme_Generator::import_theme(
			$html_file,
			array(
				'slug'            => isset( $assoc_args['slug'] ) ? (string) $assoc_args['slug'] : '',
				'name'            => isset( $assoc_args['name'] ) ? (string) $assoc_args['name'] : '',
				'activate'        => isset( $assoc_args['activate'] ),
				'overwrite'       => isset( $assoc_args['overwrite'] ),
				'keep_source'     => isset( $assoc_args['keep-source'] ),
				'fail_on_quality' => isset( $assoc_args['fail-on-quality'] ),
				'max_fallbacks'   => isset( $assoc_args['max-fallbacks'] ) ? (int) $assoc_args['max-fallbacks'] : null,
				'report'          => isset( $assoc_args['report'] ) ? (string) $assoc_args['report'] : '',
				'source_metadata' => $source_metadata,
			)
		);

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
			return;
		}

		if ( isset( $assoc_args['format'] ) && 'json' === (string) $assoc_args['format'] ) {
			WP_CLI::line( (string) wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		WP_CLI::success( sprintf( 'Imported static site as block theme "%s" (%s).', $result['theme_name'], $result['theme_slug'] ) );
		WP_CLI::line( sprintf( 'Theme directory: %s', $result['theme_dir'] ) );
		WP_CLI::line( sprintf( 'Import report: %s', $result['report_path'] ) );
		if ( ! empty( $result['external_report_path'] ) ) {
			WP_CLI::line( sprintf( 'External import report: %s', $result['external_report_path'] ) );
		}
		if ( ! empty( $result['source_cleanup_error'] ) ) {
			WP_CLI::warning( sprintf( 'Source cleanup skipped: %s', $result['source_cleanup_error'] ) );
		}
		if ( ! empty( $source_metadata['final_url'] ) ) {
			WP_CLI::line( sprintf( 'Fetched URL: %s', $source_metadata['final_url'] ) );
		}
		WP_CLI::line( sprintf( 'Conversion quality: %s (%d unsupported HTML fallbacks, %d invalid blocks, %d content-loss aborts).', $result['quality']['pass'] ? 'pass' : 'needs review', $result['quality']['fallback_count'], $result['quality']['invalid_block_count'], $result['quality']['content_loss_count'] ) );

		if ( ! $result['quality']['pass'] ) {
			WP_CLI::warning( 'Conversion quality checks reported issues. Inspect import-report.json for source fragments and diagnostics.' );
		}

		if ( ! empty( $result['quality']['fail_import'] ) ) {
			WP_CLI::error( 'Conversion quality gate failed. Theme files were written for inspection.' );
			return;
		}
	}

	/**
	 * Import one public URL as a block theme.
	 *
	 * ## OPTIONS
	 *
	 * <url>
	 * : Public http/https URL to fetch.
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
	 * [--keep-source]
	 * : Preserve the fetched source directory after a successful clean import.
	 *
	 * [--fail-on-quality]
	 * : Exit non-zero when conversion quality checks report fallbacks, invalid blocks, or content loss.
	 *
	 * [--max-fallbacks=<count>]
	 * : Exit non-zero when unsupported HTML fallback count exceeds this threshold.
	 *
	 * [--report=<path>]
	 * : Copy the generated import report JSON to an external archive path.
	 *
	 * [--format=<format>]
	 * : Output format. Use json for machine-readable command output.
	 *
	 * @param array<int, string>   $args       Positional args.
	 * @param array<string, mixed> $assoc_args Associative args.
	 * @return void
	 */
	public function import_url( array $args, array $assoc_args ): void {
		$url = $args[0] ?? '';
		if ( '' === trim( $url ) ) {
			WP_CLI::error( 'Missing <url> argument.' );
			return;
		}

		$assoc_args['url'] = $url;
		$this->import_theme( array(), $assoc_args );
	}
}

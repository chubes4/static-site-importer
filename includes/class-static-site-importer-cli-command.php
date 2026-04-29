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
	 * @param array<int, string>   $args       Positional args.
	 * @param array<string, mixed> $assoc_args Associative args.
	 * @return void
	 */
	public function import_theme( array $args, array $assoc_args ): void {
		$html_file = $args[0] ?? '';
		if ( '' === $html_file ) {
			WP_CLI::error( 'Missing <html-file> argument.' );
		}

		$result = Static_Site_Importer_Theme_Generator::import_theme(
			$html_file,
			array(
				'slug'      => isset( $assoc_args['slug'] ) ? (string) $assoc_args['slug'] : '',
				'name'      => isset( $assoc_args['name'] ) ? (string) $assoc_args['name'] : '',
				'activate'  => isset( $assoc_args['activate'] ),
				'overwrite' => isset( $assoc_args['overwrite'] ),
			)
		);

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success( sprintf( 'Imported static site as block theme "%s" (%s).', $result['theme_name'], $result['theme_slug'] ) );
		WP_CLI::line( sprintf( 'Theme directory: %s', $result['theme_dir'] ) );
	}
}

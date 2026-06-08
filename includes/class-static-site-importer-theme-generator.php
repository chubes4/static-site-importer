<?php
/**
 * Block theme generator.
 *
 * @package StaticSiteImporter
 */

// phpcs:disable Generic.Formatting.MultipleStatementAlignment -- The generator keeps localized assignment alignment; PHPCBF exhausts memory on this large file.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates a block theme from a static HTML document.
 */
class Static_Site_Importer_Theme_Generator {

	/**
	 * Scoped conversion quality report for the active import.
	 *
	 * @var array<string, mixed>
	 */
	private static array $conversion_report = array();

	/**
	 * Generated theme directory for import-scoped asset writes.
	 *
	 * @var string
	 */
	private static string $active_theme_dir = '';

	/**
	 * Generated theme URI for import-scoped asset references.
	 *
	 * @var string
	 */
	private static string $active_theme_uri = '';

	/**
	 * Caller-supplied asset map keyed by source-relative path.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private static array $active_asset_map = array();

	/**
	 * BAC file artifacts materialized into the generated theme, keyed by source path.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private static array $active_artifact_materialized_assets = array();

	/**
	 * Active local asset materialization policy.
	 *
	 * @var string
	 */
	private static string $active_asset_materialization_policy = 'copy_to_theme';

	/**
	 * Resolved local asset metadata keyed by original and rewritten references.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private static array $active_asset_metadata = array();

	/**
	 * Local asset report rows already recorded for this import.
	 *
	 * @var array<string, true>
	 */
	private static array $recorded_local_asset_keys = array();

	/**
	 * Classes observed on generated core/button wrappers during this import.
	 *
	 * @var array<string, true>
	 */
	private static array $button_wrapper_classes = array();

	/**
	 * Import a website artifact bundle as a block theme.
	 *
	 * @param array<string,mixed> $artifact Website artifact bundle.
	 * @param array<string,mixed> $args     Import args.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function import_website_artifact( array $artifact, array $args = array() ) {
		if ( ! function_exists( 'bac_compile_website_artifact' ) || ! function_exists( 'bac_summarize_result' ) ) {
			return new WP_Error( 'static_site_importer_missing_bac', 'Block Artifact Compiler is required to import a website artifact.' );
		}

		$compiler_options = isset( $args['compiler_options'] ) && is_array( $args['compiler_options'] ) ? $args['compiler_options'] : array();
		$compiled         = bac_compile_website_artifact( $artifact, array_merge( array( 'include_bfb_report' => true ), $compiler_options ) );
		$document_pages   = self::bac_document_pages( $compiled );
		if ( is_wp_error( $document_pages ) ) {
			return $document_pages;
		}

		if ( 'failed' === (string) ( $compiled['status'] ?? '' ) || empty( $document_pages ) ) {
			return new WP_Error( 'static_site_importer_artifact_compile_failed', 'Website artifact compilation did not produce BAC compiled-site document pages.', $compiled );
		}

		return self::import_compiled_website_artifact( $compiled, $args );
	}

	/**
	 * Materialize a compiled website artifact directly into WordPress theme artifacts.
	 *
	 * @param array<string,mixed> $compiled Compiler result envelope.
	 * @param array<string,mixed> $args     Import args.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function import_compiled_website_artifact( array $compiled, array $args = array() ) {
		$artifacts    = isset( $compiled['wordpress_artifacts'] ) && is_array( $compiled['wordpress_artifacts'] ) ? $compiled['wordpress_artifacts'] : array();
		$theme_name   = isset( $args['name'] ) && '' !== trim( (string) $args['name'] ) ? sanitize_text_field( (string) $args['name'] ) : 'Imported Website Artifact';
		$theme_slug   = isset( $args['slug'] ) && '' !== trim( (string) $args['slug'] ) ? sanitize_title( (string) $args['slug'] ) : sanitize_title( $theme_name );
		if ( '' === $theme_slug ) {
			$theme_slug = 'imported-website-artifact';
		}

		$theme_root = get_theme_root();
		$theme_dir  = trailingslashit( $theme_root ) . $theme_slug;
		if ( file_exists( $theme_dir ) && empty( $args['overwrite'] ) ) {
			return new WP_Error( 'static_site_importer_theme_exists', sprintf( 'Theme already exists: %s', $theme_slug ) );
		}

		$result = Static_Site_Importer_Theme_Materializer::ensure_dirs( $theme_dir );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$source_metadata           = isset( $args['source_metadata'] ) && is_array( $args['source_metadata'] ) ? $args['source_metadata'] : array();
		$source_metadata['source'] = 'website_artifact';
		$html_path                 = (string) ( $compiled['provenance']['source'] ?? ( $compiled['input']['entry_path'] ?? 'website_artifact' ) );
		self::$conversion_report   = self::new_conversion_report( $html_path, $source_metadata );
		self::record_website_artifact_compiler_result( $compiled );

		self::$active_theme_dir         = $theme_dir;
		self::$active_theme_uri         = trailingslashit( get_theme_root_uri( $theme_slug ) ) . $theme_slug;
		$asset_policy = self::normalize_asset_materialization_policy( $args['asset_materialization_policy'] ?? '' );
		if ( is_wp_error( $asset_policy ) ) {
			return $asset_policy;
		}

		self::$active_asset_map         = self::normalize_asset_map( isset( $args['asset_map'] ) && is_array( $args['asset_map'] ) ? $args['asset_map'] : array() );
		self::$active_artifact_materialized_assets = array();
		self::$active_asset_materialization_policy = $asset_policy;
		self::$active_asset_metadata    = array();
		self::$recorded_local_asset_keys    = array();
		self::$conversion_report['assets']['policy']         = 'theme';
		self::$conversion_report['asset_map']['supplied']    = ! empty( self::$active_asset_map );
		self::$conversion_report['asset_map']['entry_count'] = count( self::$active_asset_map );
		self::$conversion_report['assets']['local_policy']   = self::$active_asset_materialization_policy;
		self::$button_wrapper_classes   = array();

		$document_pages = self::bac_document_pages( $compiled );
		if ( is_wp_error( $document_pages ) ) {
			return $document_pages;
		}

		$page_ids = self::create_page_shells( $document_pages );
		if ( is_wp_error( $page_ids ) ) {
			return $page_ids;
		}

		$permalinks     = self::page_permalinks( $page_ids );
		$route_map      = self::source_route_map( $document_pages, $permalinks );
		$page_artifacts = self::page_artifacts( $document_pages, $route_map, $theme_slug );

		$materialized = self::materialize_website_artifact_files_to_theme( $theme_dir, $artifacts );
		if ( is_wp_error( $materialized ) ) {
			return $materialized;
		}
		self::record_website_artifact_document_metadata( $artifacts );

		$template_part_writes = self::template_part_artifact_writes( $theme_dir, $artifacts );
		if ( is_wp_error( $template_part_writes ) ) {
			return $template_part_writes;
		}
		$has_footer_part      = isset( $template_part_writes[ $theme_dir . '/parts/footer.html' ] );

		$writes = array(
			$theme_dir . '/style.css'                   => self::style_css( $theme_name, $materialized['css'], array_keys( self::$button_wrapper_classes ) ),
			$theme_dir . '/assets/css/editor-style.css' => self::editor_style_css( $materialized['css'], array_keys( self::$button_wrapper_classes ) ),
			$theme_dir . '/functions.php'               => self::functions_php( $theme_slug ),
			$theme_dir . '/theme.json'                  => self::theme_json( $theme_name, $materialized['css'] ),
			$theme_dir . '/templates/front-page.html'   => self::content_template( '', $has_footer_part ),
			$theme_dir . '/templates/page.html'         => self::content_template( '', $has_footer_part ),
			$theme_dir . '/templates/index.html'        => self::content_template( '', $has_footer_part ),
		);
		$writes = array_merge( $writes, $template_part_writes );
		$result         = self::write_page_contents( $document_pages, $page_ids, $page_artifacts['contents'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		self::analyze_imported_page_content_documents( $document_pages, $page_artifacts['contents'] );

		self::record_bac_source_documents_summary( $artifacts['documents'] ?? array(), $document_pages, $page_ids, $permalinks );
		foreach ( array_keys( $page_artifacts['patterns'] ) as $filename ) {
			$page = $document_pages[ $filename ] ?? null;
			$slug = $page instanceof Static_Site_Importer_Source_Page ? self::page_slug( $filename, $page ) : self::page_slug( $filename );
			if ( '' === $slug ) {
				continue;
			}

			$writes[ $theme_dir . '/templates/page-' . $slug . '.html' ] = self::content_template( '', $has_footer_part );
		}

		if ( '' !== trim( $materialized['js'] ) ) {
			$writes[ $theme_dir . '/assets/site.js' ] = $materialized['js'];
		}

		self::analyze_generated_theme_block_documents( $writes, $theme_dir );
		self::$conversion_report['theme_slug'] = $theme_slug;
		self::record_product_seeding_report( $args );
		self::record_commerce_dependency_check( $args );
		$quality     = self::finalize_quality_report( $args );
		$summary     = self::import_report_summary( self::$conversion_report, $quality );
		self::$conversion_report['compact_summary'] = $summary;
		$report_json = wp_json_encode( self::$conversion_report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		if ( false === $report_json ) {
			return new WP_Error( 'static_site_importer_report_encode_failed', 'Failed to encode import report JSON.' );
		}

		$writes[ $theme_dir . '/import-report.json' ] = $report_json . "\n";
		foreach ( $writes as $path => $content ) {
			$result = Static_Site_Importer_Theme_Materializer::write_file( $path, $content );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		$external_report_path = '';
		if ( isset( $args['report'] ) && '' !== trim( (string) $args['report'] ) ) {
			$external_report_path = (string) $args['report'];
			$result               = Static_Site_Importer_Theme_Materializer::write_external_report( $external_report_path, $report_json . "\n" );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		if ( ! empty( $args['activate'] ) ) {
			$front_page_id = self::front_page_id( $page_ids );
			if ( 0 === $front_page_id && ! empty( $page_ids ) ) {
				$front_page_id = (int) reset( $page_ids );
			}

			switch_theme( $theme_slug );
			if ( 0 !== $front_page_id ) {
				update_option( 'show_on_front', 'page' );
				update_option( 'page_on_front', $front_page_id );
			}
		}

		return array(
			'theme_slug'            => $theme_slug,
			'theme_name'            => $theme_name,
			'theme_dir'             => $theme_dir,
			'report_path'           => $theme_dir . '/import-report.json',
			'external_report_path'  => $external_report_path,
			'import_report_summary' => $summary,
			'pages'                 => $page_ids,
			'quality'               => $quality,
			'source_documents'      => self::$conversion_report['source_documents'],
		);
	}

	/**
	 * Export an imported or active block theme as a website artifact.
	 *
	 * @param array $args Export args.
	 * @return array{website_artifact:array<string,mixed>}|WP_Error
	 */
	public static function export_theme( array $args = array() ) {
		if ( ! function_exists( 'bfb_convert' ) ) {
			return new WP_Error( 'static_site_importer_missing_bfb', 'Block Format Bridge is required to export a website artifact.' );
		}

		$theme_slug = isset( $args['theme_slug'] ) && '' !== trim( (string) $args['theme_slug'] ) ? sanitize_title( (string) $args['theme_slug'] ) : self::active_theme_slug();
		if ( '' === $theme_slug ) {
			return new WP_Error( 'static_site_importer_missing_theme_slug', 'A theme_slug input is required when no active theme can be detected.' );
		}

		$theme_dir = self::export_theme_dir( $theme_slug );
		if ( '' === $theme_dir || ! is_dir( $theme_dir ) ) {
			return new WP_Error( 'static_site_importer_theme_not_found', sprintf( 'Theme directory not found for %s.', $theme_slug ) );
		}

		$entrypoint      = self::export_artifact_path( isset( $args['entrypoint'] ) ? (string) $args['entrypoint'] : 'website/index.html', 'website/index.html' );
		$root            = self::export_artifact_root( isset( $args['root'] ) ? (string) $args['root'] : '', $entrypoint );
		$include_pages   = $args['include_pages'] ?? true;
		$source_metadata = isset( $args['source_metadata'] ) && is_array( $args['source_metadata'] ) ? $args['source_metadata'] : array();
		$diagnostics     = array();
		$files           = array();

		$stylesheet = self::export_theme_stylesheet_file( $theme_dir, $root );
		if ( null !== $stylesheet ) {
			$files[] = $stylesheet;
		}

		$pages = self::export_pages( $include_pages );
		if ( empty( $pages ) ) {
			$diagnostics[] = array(
				'level'   => 'warning',
				'code'    => 'static_site_importer_export_no_pages',
				'message' => 'No published pages were available to export; generated an entrypoint from theme templates only.',
			);
			$files[] = self::export_file_entry(
				$entrypoint,
				self::export_html_document( '', self::export_theme_chrome_html( $theme_dir, 'front-page' ), $theme_slug, null !== $stylesheet ),
				'document',
				'entrypoint'
			);
		} else {
			$front_page_id = self::export_front_page_id();
			$first         = true;
			foreach ( $pages as $page ) {
				$page_id   = isset( $page->ID ) ? (int) $page->ID : 0;
				$is_front  = $first || ( $front_page_id > 0 && $page_id === $front_page_id );
				$path      = $is_front ? $entrypoint : self::export_page_artifact_path( $page, $root );
				$template  = $is_front ? 'front-page' : 'page';
				$page_html = bfb_convert( isset( $page->post_content ) ? (string) $page->post_content : '', 'blocks', 'html' );

				$files[] = self::export_file_entry(
					$path,
					self::export_html_document( $page_html, self::export_theme_chrome_html( $theme_dir, $template ), self::export_page_title( $page, $theme_slug ), null !== $stylesheet ),
					'document',
					$is_front ? 'entrypoint' : 'page',
					array(
						'post_id'   => $page_id,
						'post_name' => isset( $page->post_name ) ? (string) $page->post_name : '',
					)
				);

				$first = false;
			}
		}

		$files = array_merge( $files, self::export_theme_asset_files( $theme_dir, $root, $diagnostics ) );

		$import_report = self::read_theme_import_report( $theme_dir );
		if ( ! empty( $import_report ) ) {
			$files[] = self::export_file_entry(
				$root . '/import-report.json',
				self::json_encode_pretty( $import_report ),
				'metadata',
				'report',
				array(
					'source' => array(
						'type' => 'static-site-importer-import-report',
					),
				)
			);

			$source_documents = isset( $import_report['source_documents'] ) && is_array( $import_report['source_documents'] ) ? $import_report['source_documents'] : array();
			if ( ! empty( $source_documents ) ) {
				$files[] = self::export_file_entry(
					$root . '/source-documents.json',
					self::json_encode_pretty( $source_documents ),
					'metadata',
					'source-document',
					array(
						'source' => array(
							'type' => 'static-site-importer-source-documents',
						),
					)
				);
			}
		}

		$report = array(
			'status'          => 'completed',
			'theme_slug'      => $theme_slug,
			'theme_dir'       => $theme_dir,
			'root'            => $root,
			'entrypoint'      => $entrypoint,
			'file_count'      => count( $files ),
			'page_count'      => count( $pages ),
			'source_metadata' => $source_metadata,
			'diagnostics'     => $diagnostics,
		);
		if ( ! empty( $import_report ) ) {
			$report['import_report'] = $import_report;
		}

		$website_artifact = self::export_website_artifact( $theme_slug, $root, $entrypoint, $files, $report, $source_metadata );

		return array(
			'website_artifact' => $website_artifact,
		);
	}

	/**
	 * Resolve the active theme slug.
	 *
	 * @return string
	 */
	private static function active_theme_slug(): string {
		if ( function_exists( 'get_stylesheet' ) ) {
			return sanitize_title( (string) get_stylesheet() );
		}

		return '';
	}

	/**
	 * Resolve a theme directory for export.
	 *
	 * @param string $theme_slug Theme slug.
	 * @return string
	 */
	private static function export_theme_dir( string $theme_slug ): string {
		if ( function_exists( 'wp_get_theme' ) ) {
			$theme = wp_get_theme( $theme_slug );
			if ( is_object( $theme ) && method_exists( $theme, 'exists' ) && $theme->exists() && method_exists( $theme, 'get_stylesheet_directory' ) ) {
				return (string) $theme->get_stylesheet_directory();
			}
		}

		if ( function_exists( 'get_theme_root' ) ) {
			return trailingslashit( get_theme_root( $theme_slug ) ) . $theme_slug;
		}

		return '';
	}

	/**
	 * Get published pages selected by include_pages.
	 *
	 * @param mixed $include_pages Include pages argument.
	 * @return array<int,object>
	 */
	private static function export_pages( $include_pages ): array {
		if ( false === $include_pages || ! function_exists( 'get_posts' ) ) {
			$page = self::export_front_page();
			return null === $page ? array() : array( $page );
		}

		$pages = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'menu_order title',
				'order'          => 'ASC',
			)
		);
		if ( ! is_array( $pages ) ) {
			return array();
		}

		if ( ! is_array( $include_pages ) || empty( $include_pages ) ) {
			return self::order_front_page_first( array_values( $pages ) );
		}

		$allowed = array_fill_keys( array_map( 'strval', $include_pages ), true );
		return self::order_front_page_first( array_values(
			array_filter(
				$pages,
				static function ( $page ) use ( $allowed ): bool {
					$page_id   = isset( $page->ID ) ? (string) $page->ID : '';
					$page_slug = isset( $page->post_name ) ? (string) $page->post_name : '';
					return isset( $allowed[ $page_id ] ) || isset( $allowed[ $page_slug ] );
				}
			)
		) );
	}

	/**
	 * Order exported pages so the configured front page becomes the entrypoint.
	 *
	 * @param array<int,object> $pages Pages.
	 * @return array<int,object>
	 */
	private static function order_front_page_first( array $pages ): array {
		$front_page_id = self::export_front_page_id();
		if ( $front_page_id <= 0 ) {
			return $pages;
		}

		usort(
			$pages,
			static function ( object $left, object $right ) use ( $front_page_id ): int {
				$left_is_front  = isset( $left->ID ) && (int) $left->ID === $front_page_id;
				$right_is_front = isset( $right->ID ) && (int) $right->ID === $front_page_id;
				if ( $left_is_front === $right_is_front ) {
					return 0;
				}

				return $left_is_front ? -1 : 1;
			}
		);

		return $pages;
	}

	/**
	 * Get the configured front page post.
	 *
	 * @return object|null
	 */
	private static function export_front_page(): ?object {
		$front_page_id = self::export_front_page_id();
		if ( $front_page_id > 0 && function_exists( 'get_post' ) ) {
			$page = get_post( $front_page_id );
			if ( is_object( $page ) ) {
				return $page;
			}
		}

		if ( ! function_exists( 'get_posts' ) ) {
			return null;
		}

		$pages = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'orderby'        => 'menu_order title',
				'order'          => 'ASC',
			)
		);

		return is_array( $pages ) && isset( $pages[0] ) && is_object( $pages[0] ) ? $pages[0] : null;
	}

	/**
	 * Get the configured front page ID.
	 *
	 * @return int
	 */
	private static function export_front_page_id(): int {
		if ( ! function_exists( 'get_option' ) || 'page' !== get_option( 'show_on_front' ) ) {
			return 0;
		}

		return (int) get_option( 'page_on_front' );
	}

	/**
	 * Convert template parts around exported page content.
	 *
	 * @param string $theme_dir Theme directory.
	 * @param string $template  Template slug.
	 * @return array{before:string,after:string}
	 */
	private static function export_theme_chrome_html( string $theme_dir, string $template ): array {
		$before = self::convert_theme_block_file_to_html( $theme_dir . '/parts/header.html' );
		$after  = self::convert_theme_block_file_to_html( $theme_dir . '/parts/footer.html' );

		$template_html = self::read_file_if_readable( $theme_dir . '/templates/' . $template . '.html' );
		if ( '' === $template_html && 'front-page' !== $template ) {
			$template_html = self::read_file_if_readable( $theme_dir . '/templates/index.html' );
		}

		if ( '' !== $template_html && function_exists( 'bfb_convert' ) ) {
			$converted_template = bfb_convert( $template_html, 'blocks', 'html' );
			if ( '' !== trim( $converted_template ) && '' === trim( $before . $after ) ) {
				$before = $converted_template;
			}
		}

		return array(
			'before' => $before,
			'after'  => $after,
		);
	}

	/**
	 * Convert a block markup file to HTML.
	 *
	 * @param string $path File path.
	 * @return string
	 */
	private static function convert_theme_block_file_to_html( string $path ): string {
		$content = self::read_file_if_readable( $path );
		return '' === $content || ! function_exists( 'bfb_convert' ) ? '' : bfb_convert( $content, 'blocks', 'html' );
	}

	/**
	 * Read a file when available.
	 *
	 * @param string $path File path.
	 * @return string
	 */
	private static function read_file_if_readable( string $path ): string {
		if ( ! is_readable( $path ) ) {
			return '';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads local generated theme artifacts for export.
		$content = file_get_contents( $path );
		return false === $content ? '' : (string) $content;
	}

	/**
	 * Build a full static HTML document.
	 *
	 * @param string                    $page_html       Converted page body HTML.
	 * @param array{before:string,after:string} $chrome          Converted theme chrome.
	 * @param string                    $title           Document title.
	 * @param bool                      $include_styles  Whether to link exported CSS.
	 * @return string
	 */
	private static function export_html_document( string $page_html, array $chrome, string $title, bool $include_styles ): string {
		$head = '<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
		if ( $include_styles ) {
			// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- This method emits standalone static HTML, not a WordPress-rendered page.
			$head .= '<link rel="stylesheet" href="style.css">';
		}

		return '<!doctype html>' . "\n"
			. '<html><head>' . $head . '<title>' . esc_html( $title ) . '</title></head><body>' . "\n"
			. trim( (string) ( $chrome['before'] ?? '' ) . "\n" . $page_html . "\n" . ( $chrome['after'] ?? '' ) ) . "\n"
			. '</body></html>' . "\n";
	}

	/**
	 * Build an artifact file entry.
	 *
	 * @param string              $path        Artifact path.
	 * @param string              $content     File content.
	 * @param string              $kind        File kind.
	 * @param string              $role        File role.
	 * @param array<string,mixed> $diagnostics Optional diagnostics/metadata.
	 * @return array<string,mixed>
	 */
	private static function export_file_entry( string $path, string $content, string $kind, string $role, array $diagnostics = array() ): array {
		$encoding = self::is_binary_content( $content ) ? 'base64' : 'utf8';
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary artifact files are explicitly represented as base64 for transport.
		$body = 'base64' === $encoding ? base64_encode( $content ) : $content;
		$entry = array(
			'path'      => $path,
			'content'   => $body,
			'kind'      => $kind,
			'role'      => $role,
			'mime_type' => self::export_mime_type( $path ),
			'encoding'  => $encoding,
			'bytes'     => strlen( $content ),
			'sha256'    => hash( 'sha256', $content ),
		);
		if ( ! empty( $diagnostics ) ) {
			$entry = array_merge( $entry, $diagnostics );
		}

		return $entry;
	}

	/**
	 * Build the BAC-compatible website artifact envelope.
	 *
	 * @param string              $theme_slug      Theme slug.
	 * @param string              $root            Artifact root.
	 * @param string              $entrypoint      Entrypoint path.
	 * @param array<int,array<string,mixed>> $files Exported files.
	 * @param array<string,mixed> $report          Export report.
	 * @param array<string,mixed> $source_metadata Source metadata.
	 * @return array<string,mixed>
	 */
	private static function export_website_artifact( string $theme_slug, string $root, string $entrypoint, array $files, array $report, array $source_metadata ): array {
		$generated_at = self::export_generated_at();
		$id           = 'website-artifact-' . $theme_slug . '-' . substr( hash( 'sha256', self::json_encode_pretty( array( $entrypoint, $files ) ) ), 0, 12 );

		return array(
			'schema'        => 'block-artifact-compiler/website-artifact/v1',
			'artifact_type' => 'website',
			'version'       => 1,
			'id'            => $id,
			'generated_at'  => $generated_at,
			'theme_slug'    => $theme_slug,
			'root'          => $root,
			'entrypoint'    => $entrypoint,
			'files'         => $files,
			'report'        => $report,
			'reports'       => self::export_report_refs( $files ),
			'import'        => array(
				'status'      => empty( $report['diagnostics'] ) ? 'passed' : 'warning',
				'theme_slug'  => $theme_slug,
				'source_path' => $entrypoint,
				'warnings'    => self::export_diagnostic_messages( $report['diagnostics'] ?? array(), 'warning' ),
				'errors'      => self::export_diagnostic_messages( $report['diagnostics'] ?? array(), 'error' ),
			),
			'validation'    => array(
				'status'     => self::export_validation_status( $report['diagnostics'] ?? array() ),
				'checked_at' => $generated_at,
				'checks'     => array(
					array(
						'name'    => 'entrypoint-present',
						'status'  => self::export_has_file( $files, $entrypoint ) ? 'passed' : 'failed',
						'message' => 'The website artifact entrypoint is present in the exported file set.',
					),
				),
			),
			'provenance'    => array(
				'producer'          => 'static-site-importer',
				'source_metadata'   => $source_metadata,
				'materialized_from' => array(
					'type'       => 'wordpress-block-theme',
					'theme_slug' => $theme_slug,
				),
			),
		);
	}

	/**
	 * Export the theme stylesheet when present.
	 *
	 * @param string $theme_dir Theme directory.
	 * @return array<string,mixed>|null
	 */
	private static function export_theme_stylesheet_file( string $theme_dir, string $root ): ?array {
		$content = self::read_file_if_readable( $theme_dir . '/style.css' );
		if ( '' === $content ) {
			return null;
		}

		return self::export_file_entry( $root . '/style.css', $content, 'asset', 'stylesheet' );
	}

	/**
	 * Export browser assets that can be replayed with the website artifact.
	 *
	 * @param string                    $theme_dir   Theme directory.
	 * @param string                    $root        Artifact root.
	 * @param array<int,array<string,mixed>> $diagnostics Export diagnostics.
	 * @return array<int,array<string,mixed>>
	 */
	private static function export_theme_asset_files( string $theme_dir, string $root, array &$diagnostics ): array {
		$assets_dir = $theme_dir . '/assets';
		if ( ! is_dir( $assets_dir ) ) {
			return array();
		}

		$files    = array();
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $assets_dir, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $item ) {
			if ( ! $item instanceof SplFileInfo || ! $item->isFile() || ! $item->isReadable() ) {
				continue;
			}

			$relative = ltrim( str_replace( '\\', '/', substr( $item->getPathname(), strlen( $assets_dir ) ) ), '/' );
			$path     = self::export_artifact_path( $root . '/assets/' . $relative, '' );
			if ( '' === $path || ! self::export_is_supported_asset_path( $path ) ) {
				$diagnostics[] = array(
					'level'   => 'warning',
					'code'    => 'static_site_importer_export_asset_skipped',
					'message' => 'A theme asset was skipped because its path or type is not supported for static export.',
					'path'    => $relative,
				);
				continue;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads local generated theme artifacts for export.
			$content = file_get_contents( $item->getPathname() );
			if ( false === $content ) {
				continue;
			}

			$files[] = self::export_file_entry( $path, (string) $content, self::export_kind_from_path( $path ), self::export_role_from_path( $path ) );
		}

		usort(
			$files,
			static function ( array $left, array $right ): int {
				return strcmp( (string) ( $left['path'] ?? '' ), (string) ( $right['path'] ?? '' ) );
			}
		);

		return $files;
	}

	/**
	 * Normalize an exported artifact path.
	 *
	 * @param string $path     Requested path.
	 * @param string $fallback Fallback path.
	 * @return string
	 */
	private static function export_artifact_path( string $path, string $fallback ): string {
		$path = self::normalize_route_path( $path );
		if ( '' === $path || str_ends_with( $path, '/' ) ) {
			return $fallback;
		}

		return $path;
	}

	/**
	 * Resolve the artifact root from input or entrypoint.
	 *
	 * @param string $root       Requested root.
	 * @param string $entrypoint Entrypoint path.
	 * @return string
	 */
	private static function export_artifact_root( string $root, string $entrypoint ): string {
		$root = self::normalize_route_path( $root );
		if ( '' !== $root && ! str_contains( $root, '/' ) ) {
			return $root;
		}

		$parts = explode( '/', $entrypoint );
		return '' !== ( $parts[0] ?? '' ) ? $parts[0] : 'website';
	}

	/**
	 * Build a page artifact path.
	 *
	 * @param object $page Page object.
	 * @return string
	 */
	private static function export_page_artifact_path( object $page, string $root ): string {
		$slug = isset( $page->post_name ) && '' !== trim( (string) $page->post_name ) ? sanitize_title( (string) $page->post_name ) : 'page-' . ( isset( $page->ID ) ? (int) $page->ID : uniqid() );
		return self::export_artifact_path( $root . '/' . $slug . '/index.html', $root . '/page/index.html' );
	}

	/**
	 * Resolve a page title for export.
	 *
	 * @param object $page       Page object.
	 * @param string $theme_slug Fallback theme slug.
	 * @return string
	 */
	private static function export_page_title( object $page, string $theme_slug ): string {
		if ( isset( $page->post_title ) && '' !== trim( (string) $page->post_title ) ) {
			return (string) $page->post_title;
		}

		return $theme_slug;
	}

	/**
	 * Resolve a static export MIME type from path.
	 *
	 * @param string $path Artifact path.
	 * @return string
	 */
	private static function export_mime_type( string $path ): string {
		return match ( strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) ) {
			'html', 'htm' => 'text/html',
			'css'         => 'text/css',
			'js', 'mjs'    => 'text/javascript',
			'json'        => 'application/json',
			'svg'         => 'image/svg+xml',
			'png'         => 'image/png',
			'jpg', 'jpeg'  => 'image/jpeg',
			'gif'         => 'image/gif',
			'webp'        => 'image/webp',
			'avif'        => 'image/avif',
			'woff'        => 'font/woff',
			'woff2'       => 'font/woff2',
			default       => 'application/octet-stream',
		};
	}

	/**
	 * Infer an exported file kind from path.
	 *
	 * @param string $path Artifact path.
	 * @return string
	 */
	private static function export_kind_from_path( string $path ): string {
		return match ( strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) ) {
			'html', 'htm' => 'document',
			'css'         => 'asset',
			'js', 'mjs'    => 'asset',
			'json'        => 'metadata',
			default       => 'asset',
		};
	}

	/**
	 * Infer a static artifact file role from path.
	 *
	 * @param string $path Artifact path.
	 * @return string
	 */
	private static function export_role_from_path( string $path ): string {
		return match ( strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) ) {
			'css'        => 'stylesheet',
			'js', 'mjs'   => 'script',
			'json'       => 'metadata',
			default      => 'asset',
		};
	}

	/**
	 * Check whether an asset path is supported for static export.
	 *
	 * @param string $path Artifact path.
	 * @return bool
	 */
	private static function export_is_supported_asset_path( string $path ): bool {
		return in_array( strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ), array( 'css', 'js', 'mjs', 'json', 'svg', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'woff', 'woff2' ), true );
	}

	/**
	 * Detect binary content that should be inlined as base64.
	 *
	 * @param string $content File content.
	 * @return bool
	 */
	private static function is_binary_content( string $content ): bool {
		return str_contains( $content, "\0" ) || ! preg_match( '//u', $content );
	}

	/**
	 * JSON encode with stable options and a PHP fallback for smoke tests.
	 *
	 * @param mixed $data Data to encode.
	 * @return string
	 */
	private static function json_encode_pretty( mixed $data ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Smoke tests load this class without WordPress helpers.
		$encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) : json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		return is_string( $encoded ) ? $encoded . "\n" : "{}\n";
	}

	/**
	 * Return the export timestamp.
	 *
	 * @return string
	 */
	private static function export_generated_at(): string {
		return gmdate( 'Y-m-d\TH:i:s\Z' );
	}

	/**
	 * Build report file references from exported files.
	 *
	 * @param array<int,array<string,mixed>> $files Exported files.
	 * @return array<int,array<string,string>>
	 */
	private static function export_report_refs( array $files ): array {
		$refs = array();
		foreach ( $files as $file ) {
			$role = (string) ( $file['role'] ?? '' );
			if ( in_array( $role, array( 'report', 'source-document' ), true ) ) {
				$refs[] = array(
					'role' => $role,
					'path' => (string) ( $file['path'] ?? '' ),
				);
			}
		}

		return $refs;
	}

	/**
	 * Extract diagnostic messages by level/severity.
	 *
	 * @param mixed  $diagnostics Diagnostics.
	 * @param string $level       Level to collect.
	 * @return array<int,string>
	 */
	private static function export_diagnostic_messages( mixed $diagnostics, string $level ): array {
		if ( ! is_array( $diagnostics ) ) {
			return array();
		}

		$messages = array();
		foreach ( $diagnostics as $diagnostic ) {
			if ( ! is_array( $diagnostic ) ) {
				continue;
			}

			$diagnostic_level = (string) ( $diagnostic['level'] ?? ( $diagnostic['severity'] ?? '' ) );
			if ( $level === $diagnostic_level ) {
				$messages[] = (string) ( $diagnostic['message'] ?? ( $diagnostic['code'] ?? '' ) );
			}
		}

		return array_values( array_filter( $messages ) );
	}

	/**
	 * Resolve validation status from diagnostics.
	 *
	 * @param mixed $diagnostics Diagnostics.
	 * @return string
	 */
	private static function export_validation_status( mixed $diagnostics ): string {
		if ( ! is_array( $diagnostics ) ) {
			return 'passed';
		}

		foreach ( $diagnostics as $diagnostic ) {
			if ( is_array( $diagnostic ) && 'error' === (string) ( $diagnostic['level'] ?? ( $diagnostic['severity'] ?? '' ) ) ) {
				return 'failed';
			}
		}

		return empty( $diagnostics ) ? 'passed' : 'warning';
	}

	/**
	 * Check whether a file path exists in the export set.
	 *
	 * @param array<int,array<string,mixed>> $files Exported files.
	 * @param string                        $path  Artifact path.
	 * @return bool
	 */
	private static function export_has_file( array $files, string $path ): bool {
		foreach ( $files as $file ) {
			if ( (string) ( $file['path'] ?? '' ) === $path ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Read the import report bundled with an SSI-generated theme.
	 *
	 * @param string $theme_dir Theme directory.
	 * @return array<string,mixed>
	 */
	private static function read_theme_import_report( string $theme_dir ): array {
		$report = self::read_file_if_readable( $theme_dir . '/import-report.json' );
		if ( '' === $report ) {
			return array();
		}

		$decoded = json_decode( $report, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Normalize BAC WordPress document artifacts into source pages.
	 *
	 * SSI consumes BAC's compiled-site page contract when available, then attaches
	 * the referenced `wordpress_artifacts.documents[]` block markup for WordPress
	 * materialization.
	 *
	 * @param array<string,mixed> $compiled Compiler result envelope.
	 * @return array<string, Static_Site_Importer_Source_Page>|WP_Error
	 */
	private static function bac_document_pages( array $compiled ) {
		$artifacts = isset( $compiled['wordpress_artifacts'] ) && is_array( $compiled['wordpress_artifacts'] ) ? $compiled['wordpress_artifacts'] : array();
		$documents = isset( $artifacts['documents'] ) && is_array( $artifacts['documents'] ) ? $artifacts['documents'] : array();
		$site      = isset( $artifacts['site'] ) && is_array( $artifacts['site'] ) ? $artifacts['site'] : array();
		if ( ! empty( $documents ) ) {
			if ( 'block-artifact-compiler/compiled-site/v1' !== (string) ( $site['schema'] ?? '' ) || ! isset( $site['pages'] ) || ! is_array( $site['pages'] ) ) {
				return new WP_Error( 'static_site_importer_bac_compiled_site_missing', 'BAC document artifacts require the block-artifact-compiler/compiled-site/v1 site contract.' );
			}

			$documents = self::bac_documents_from_compiled_site_pages( $site['pages'], $documents );
			if ( is_wp_error( $documents ) ) {
				return $documents;
			}
		}

		$pages     = array();

		foreach ( $documents as $document ) {
			if ( ! is_array( $document ) ) {
				continue;
			}

			$page = Static_Site_Importer_Source_Page::from_wordpress_document_artifact( $document );
			if ( is_wp_error( $page ) ) {
				return $page;
			}

			$pages[ $page->source_key() ] = $page;
		}

		return $pages;
	}

	/**
	 * Attach BAC document markup to compiled-site page contracts.
	 *
	 * @param array<int,array<string,mixed>> $site_pages Compiled-site page rows.
	 * @param array<int,mixed>               $documents  BAC document artifacts.
	 * @return array<int,array<string,mixed>>|WP_Error Document artifacts ordered by compiled-site pages.
	 */
	private static function bac_documents_from_compiled_site_pages( array $site_pages, array $documents ) {
		$documents_by_source = array();
		foreach ( $documents as $document ) {
			if ( ! is_array( $document ) ) {
				continue;
			}

			$source_path = isset( $document['source_path'] ) && is_scalar( $document['source_path'] ) ? (string) $document['source_path'] : '';
			if ( '' !== $source_path ) {
				$documents_by_source[ $source_path ] = $document;
			}
		}

		$compiled_documents = array();
		foreach ( $site_pages as $page ) {
			if ( ! is_array( $page ) ) {
				continue;
			}

			$source_path = isset( $page['source_path'] ) && is_scalar( $page['source_path'] ) ? (string) $page['source_path'] : '';
			if ( '' === $source_path ) {
				return new WP_Error( 'static_site_importer_compiled_site_page_missing_source', 'BAC compiled-site page is missing source_path.' );
			}
			if ( ! isset( $documents_by_source[ $source_path ] ) ) {
				return new WP_Error( 'static_site_importer_compiled_site_page_missing_document', sprintf( 'BAC compiled-site page does not reference a document artifact: %s', $source_path ) );
			}

			$document = array_merge( $documents_by_source[ $source_path ], array_filter(
				array(
					'source_path' => $source_path,
					'slug'        => isset( $page['slug'] ) && is_scalar( $page['slug'] ) ? (string) $page['slug'] : '',
					'route_key'   => isset( $page['route_key'] ) && is_scalar( $page['route_key'] ) ? (string) $page['route_key'] : '',
					'post_type'   => isset( $page['post_type'] ) && is_scalar( $page['post_type'] ) ? (string) $page['post_type'] : '',
					'title'       => isset( $page['title'] ) && is_scalar( $page['title'] ) ? (string) $page['title'] : '',
				),
				static fn ( string $value ): bool => '' !== $value
			) );

			if ( ! empty( $page['entrypoint'] ) ) {
				$document['entrypoint'] = '1';
			}

			$compiled_documents[] = $document;
		}

		return $compiled_documents;
	}

	/**
	 * Normalize BAC template part artifacts into generated theme writes.
	 *
	 * @param string              $theme_dir Theme directory.
	 * @param array<string,mixed> $artifacts WordPress artifacts from BAC.
	 * @return array<string,string>|WP_Error Absolute write paths keyed to serialized block markup.
	 */
	private static function template_part_artifact_writes( string $theme_dir, array $artifacts ) {
		$result = Static_Site_Importer_Theme_Materializer::template_part_artifact_writes( $theme_dir, $artifacts );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		foreach ( $result['reports'] as $report ) {
			self::$conversion_report['generated_theme']['template_parts'][] = $report;
		}

		return $result['writes'];
	}

	/**
	 * Check whether a source filename is the site index.
	 *
	 * @param string $filename Source filename.
	 * @return bool
	 */
	private static function is_index_source_filename( string $filename ): bool {
		return in_array( strtolower( basename( $filename ) ), array( 'index.html' ), true );
	}

	/**
	 * Check whether a source filename is the root site index.
	 *
	 * @param string $filename Source filename.
	 * @return bool
	 */
	private static function is_root_index_source_filename( string $filename ): bool {
		return in_array( strtolower( trim( self::normalize_route_path( $filename ), '/' ) ), array( 'index.html' ), true );
	}

	/**
	 * Get imported front page ID for HTML index sources.
	 *
	 * @param array<string,int> $page_ids Page IDs keyed by source filename.
	 * @return int
	 */
	private static function front_page_id( array $page_ids ): int {
		foreach ( $page_ids as $filename => $page_id ) {
			if ( self::is_index_source_filename( $filename ) ) {
				return (int) $page_id;
			}
		}

		return 0;
	}

	/**
	 * Create page shells so links can be rewritten before content conversion.
	 *
	 * @param array<string, Static_Site_Importer_Source_Page> $pages Pages.
	 * @return array<string,int>|WP_Error
	 */
	private static function create_page_shells( array $pages ) {
		$page_ids = array();
		foreach ( $pages as $filename => $page ) {
			$title  = self::page_title( $filename, $page );
			$slug   = self::page_slug( $filename, $page );
			$status = self::page_status( $page );
			$type   = self::page_post_type( $page );

			$existing = get_page_by_path( $slug, OBJECT, $type );
			$postarr  = array(
				'post_title'   => $title,
				'post_name'    => $slug,
				'post_status'  => $status,
				'post_type'    => $type,
				'post_content' => '',
			);

			if ( $existing instanceof WP_Post ) {
				$postarr['ID'] = $existing->ID;
			}

			$page_id = wp_insert_post( $postarr, true );
			if ( is_wp_error( $page_id ) ) {
				return $page_id;
			}

			$page_ids[ $filename ] = (int) $page_id;
		}

		return $page_ids;
	}

	/**
	 * Build page-specific template and pattern artifacts.
	 *
	 * @param array<string, Static_Site_Importer_Source_Page> $pages      Pages.
	 * @param array<string,string>                          $route_map Route map.
	 * @param string                                                                    $theme_slug Theme slug.
	 * @return array{patterns:array<string,string>,files:array<string,string>,contents:array<string,string>}
	 */
	private static function page_artifacts( array $pages, array $route_map, string $theme_slug ): array {
		$patterns = array();
		$files    = array();
		$contents = array();

		foreach ( $pages as $filename => $page ) {
			$slug         = self::page_slug( $filename, $page );
			$pattern_slug = sanitize_key( $theme_slug ) . '/page-' . $slug;
			$content      = self::source_page_content_blocks( $page, $route_map );
			self::record_button_wrapper_classes_from_blocks( $content );

			$patterns[ $filename ] = $pattern_slug;
			$files[ $filename ]    = self::pattern_file( self::page_title( $filename, $page ), $pattern_slug, $content );
			$contents[ $filename ] = $content;
		}

		return array(
			'patterns' => $patterns,
			'files'    => $files,
			'contents' => $contents,
		);
	}

	/**
	 * Store imported page bodies on their corresponding WordPress pages.
	 *
	 * @param array<string, Static_Site_Importer_Source_Page> $pages    Pages.
	 * @param array<string,int>                                                         $page_ids Page IDs keyed by filename.
	 * @param array<string,string>                                                      $contents Converted block markup keyed by filename.
	 * @return true|WP_Error
	 */
	private static function write_page_contents( array $pages, array $page_ids, array $contents ) {
		foreach ( array_keys( $pages ) as $filename ) {
			$page_id = $page_ids[ $filename ] ?? 0;

			$result = wp_update_post(
				array(
					'ID'           => $page_id,
					'post_content' => wp_slash( trim( $contents[ $filename ] ?? '' ) ),
				),
				true
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return true;
	}

	/**
	 * Analyze canonical imported page post content for conversion quality.
	 *
	 * @param array<string, Static_Site_Importer_Source_Page> $pages    Pages.
	 * @param array<string,string>                           $contents Converted block markup keyed by filename.
	 * @return void
	 */
	private static function analyze_imported_page_content_documents( array $pages, array $contents ): void {
		foreach ( $pages as $filename => $page ) {
			$slug    = self::page_slug( $filename, $page );
			$source  = '' !== $slug ? 'posts/page-' . $slug . '.post_content' : 'posts/' . sanitize_title( $filename ) . '.post_content';
			$content = $contents[ $filename ] ?? '';
			if ( '' === trim( $content ) ) {
				continue;
			}

			$analysis = self::analyze_generated_block_document( $source, $content );
			self::$conversion_report['generated_theme']['block_documents'][] = $analysis;
		}
	}

	/**
	 * Build permalink map keyed by source filename.
	 *
	 * @param array<string,int> $page_ids Page IDs keyed by filename.
	 * @return array<string,string>
	 */
	private static function page_permalinks( array $page_ids ): array {
		$permalinks = array();
		foreach ( $page_ids as $filename => $page_id ) {
			$permalink = get_permalink( $page_id );
			if ( false !== $permalink ) {
				$permalinks[ $filename ] = $permalink;
				$basename                = basename( $filename );
				if ( ! isset( $permalinks[ $basename ] ) ) {
					$permalinks[ $basename ] = $permalink;
				}
			}
		}

		return $permalinks;
	}

	/**
	 * Build a route map for source document links.
	 *
	 * @param array<string, Static_Site_Importer_Source_Page> $pages      Pages.
	 * @param array<string,string>                          $permalinks Permalinks keyed by source path.
	 * @return array<string,string>
	 */
	private static function source_route_map( array $pages, array $permalinks ): array {
		$candidates = array();
		foreach ( $pages as $source_path => $page ) {
			$permalink = $permalinks[ $source_path ] ?? '';
			if ( '' === $permalink ) {
				continue;
			}

			foreach ( self::source_route_keys( $page->source_key() ) as $key ) {
				$candidates[ $key ][ $permalink ] = true;
			}
		}

		$route_map = array();
		foreach ( $candidates as $key => $matches ) {
			if ( 1 === count( $matches ) ) {
				$route_map[ $key ] = (string) array_key_first( $matches );
			}
		}

		return $route_map;
	}

	/**
	 * Build deterministic route keys for a discovered source path.
	 *
	 * @param string $relative_path Source-relative path.
	 * @return array<int,string>
	 */
	private static function source_route_keys( string $relative_path ): array {
		$relative_path = self::normalize_route_path( $relative_path );
		if ( '' === $relative_path ) {
			return array();
		}

		$extensionless = preg_replace( '/\.(?:html?)$/i', '', $relative_path );
		$extensionless = '' === trim( (string) $extensionless ) ? $relative_path : (string) $extensionless;
		$extensions    = array( 'html', 'htm' );
		$keys          = array( $relative_path, '/' . $relative_path, './' . $relative_path );

		foreach ( $extensions as $extension ) {
			$keys[] = $extensionless . '.' . $extension;
			$keys[] = '/' . $extensionless . '.' . $extension;
		}

		if ( preg_match( '#(^|/)index$#i', $extensionless ) ) {
			$clean  = preg_replace( '#(^|/)index$#i', '$1', $extensionless );
			$clean  = trim( (string) $clean, '/' );
			$keys[] = '' === $clean ? '/' : $clean;
			$keys[] = '' === $clean ? '/' : '/' . $clean . '/';
			if ( '' !== $clean ) {
				$keys[] = $clean . '/';
			}
		} else {
			$keys[] = $extensionless;
			$keys[] = '/' . $extensionless;
			$keys[] = $extensionless . '/';
			$keys[] = '/' . $extensionless . '/';
		}

		return array_values( array_unique( array_filter( $keys, static fn ( string $key ): bool => '' !== trim( $key ) ) ) );
	}

	/**
	 * Rewrite local document links to imported WordPress page permalinks.
	 *
	 * @param string               $html        HTML fragment.
	 * @param array<string,string> $route_map   Route map.
	 * @param string               $source_path Source-relative source path.
	 * @param string               $source      Diagnostic source label.
	 * @return string
	 */
	private static function rewrite_internal_links( string $html, array $route_map, string $source_path, string $source ): string {
		if ( '' === trim( $html ) || empty( $route_map ) ) {
			return $html;
		}

		return preg_replace_callback(
			'/\bhref=("|\')([^"\']+)(\1)/i',
			static function ( array $matches ) use ( $route_map, $source_path, $source ): string {
				$href        = html_entity_decode( $matches[2], ENT_QUOTES );
				$replacement = self::resolve_route_href( $href, $source_path, $route_map );
				if ( null === $replacement ) {
					self::record_unresolved_internal_link( $source, $source_path, $href );
					return $matches[0];
				}

				return 'href=' . $matches[1] . esc_url( $replacement ) . $matches[3];
			},
			$html
		) ?? $html;
	}

	/**
	 * Rewrite local media URLs inside already-converted block markup.
	 *
	 * BAC document artifacts already contain block comments. DOM-based rewriting would
	 * discard those comments, so use focused attribute rewrites that preserve markup.
	 *
	 * @param string $markup      Block markup.
	 * @param string $source_path Source-relative source path.
	 * @param string $source      Diagnostic source label.
	 * @return string
	 */
	private static function rewrite_block_markup_local_asset_references( string $markup, string $source_path, string $source ): string {
		if ( '' === trim( $markup ) || ! preg_match( '/\b(?:src|poster|srcset)\s*=/i', $markup ) ) {
			return $markup;
		}

		$markup = preg_replace_callback(
			'/\b(src|poster)\s*=\s*("|\')([^"\']*)(\2)/i',
			static function ( array $matches ) use ( $source_path, $source ): string {
				$url = self::normalize_block_markup_local_asset_url( html_entity_decode( (string) $matches[3], ENT_QUOTES ) );
				if ( '' === trim( $url ) || ! self::is_local_url( $url ) ) {
					return $matches[0];
				}

				$asset = self::resolve_local_asset_reference( $url, $source_path, $source );
				if ( null === $asset ) {
					return $matches[0];
				}

				return $matches[1] . '=' . $matches[2] . esc_url_raw( (string) $asset['url'] ) . $matches[4];
			},
			$markup
		) ?? $markup;

		return preg_replace_callback(
			'/\bsrcset\s*=\s*("|\')([^"\']*)(\1)/i',
			static function ( array $matches ) use ( $source_path, $source ): string {
				$srcset    = html_entity_decode( (string) $matches[2], ENT_QUOTES );
				$rewritten = self::rewrite_srcset_asset_references( $srcset, $source_path, $source );
				if ( $rewritten === $srcset ) {
					return $matches[0];
				}

				return 'srcset=' . $matches[1] . esc_attr( $rewritten ) . $matches[3];
			},
			$markup
		) ?? $markup;
	}

	/**
	 * Normalize source-local paths that were serialized as URL-shaped block attrs.
	 *
	 * @param string $url Block attribute URL.
	 * @return string URL or source-local path.
	 */
	private static function normalize_block_markup_local_asset_url( string $url ): string {
		$url = trim( $url );
		if ( self::is_local_url( $url ) ) {
			return $url;
		}

		$parts  = wp_parse_url( $url );
		$scheme = isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : '';
		$host   = isset( $parts['host'] ) ? strtolower( (string) $parts['host'] ) : '';
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || '' === $host || str_contains( $host, '.' ) || ! in_array( $host, array( 'assets', 'asset', 'img', 'imgs', 'image', 'images', 'media', 'static' ), true ) ) {
			return $url;
		}

		$path = isset( $parts['path'] ) ? ltrim( (string) $parts['path'], '/' ) : '';
		if ( '' === $path ) {
			return $url;
		}

		return $host . '/' . $path;
	}

	/**
	 * Ensure image block className attrs are reflected on the serialized figure.
	 *
	 * @param string $markup Serialized block markup.
	 * @return string Repaired markup.
	 */
	private static function repair_image_block_class_markup( string $markup ): string {
		if ( '' === trim( $markup ) || ! str_contains( $markup, '<!-- wp:image' ) || ! str_contains( $markup, '"className"' ) ) {
			return $markup;
		}

		return preg_replace_callback(
			'/<!--\s+wp:image\s+(\{.*?\})\s+-->\s*<figure\b([^>]*)class=("|\')([^"\']*)(\3)/s',
			static function ( array $matches ): string {
				$attrs = json_decode( html_entity_decode( (string) $matches[1], ENT_QUOTES ), true );
				if ( ! is_array( $attrs ) || empty( $attrs['className'] ) || ! is_string( $attrs['className'] ) ) {
					return $matches[0];
				}

				$class = (string) $matches[4];
				foreach ( preg_split( '/\s+/', trim( $attrs['className'] ) ) ?: array() as $token ) {
					$token = sanitize_html_class( $token );
					if ( '' !== $token ) {
						$class = self::append_class_token( $class, $token );
					}
				}

				return '<!-- wp:image ' . $matches[1] . ' --><figure' . $matches[2] . 'class=' . $matches[3] . esc_attr( $class ) . $matches[5];
			},
			$markup
		) ?? $markup;
	}

	/**
	 * Rewrite one srcset attribute value through local asset materialization.
	 *
	 * @param string $srcset      Source srcset attribute.
	 * @param string $source_path Source-relative source path.
	 * @param string $source      Diagnostic source label.
	 * @return string
	 */
	private static function rewrite_srcset_asset_references( string $srcset, string $source_path, string $source ): string {
		$candidates = array();
		$changed    = false;
		foreach ( explode( ',', $srcset ) as $candidate ) {
			$candidate = trim( $candidate );
			if ( '' === $candidate ) {
				continue;
			}

			$parts      = preg_split( '/\s+/', $candidate, 2 );
			$url        = $parts[0] ?? '';
			$descriptor = $parts[1] ?? '';
			$asset      = self::resolve_local_asset_reference( $url, $source_path, $source );
			if ( null !== $asset ) {
				$url     = $asset['url'];
				$changed = true;
			}

			$candidates[] = trim( $url . ( '' === $descriptor ? '' : ' ' . $descriptor ) );
		}

		return $changed ? implode( ', ', $candidates ) : $srcset;
	}

	/**
	 * Normalize caller-supplied asset map entries by source-relative key.
	 *
	 * @param array<string, mixed> $asset_map Raw asset map.
	 * @return array<string, array<string, mixed>>
	 */
	private static function normalize_asset_map( array $asset_map ): array {
		$normalized = array();
		foreach ( $asset_map as $key => $entry ) {
			if ( ! is_string( $key ) || ! is_array( $entry ) ) {
				continue;
			}

			$path = self::normalize_asset_map_key( $key );
			if ( '' === $path ) {
				continue;
			}

			$normalized[ $path ] = $entry;
		}

		return $normalized;
	}

	/**
	 * Normalize and validate the local asset materialization policy.
	 *
	 * @param mixed $policy Raw policy value.
	 * @return string|WP_Error
	 */
	private static function normalize_asset_materialization_policy( $policy ) {
		$policy = is_string( $policy ) ? sanitize_key( $policy ) : '';
		if ( '' === $policy ) {
			return 'copy_to_theme';
		}

		if ( in_array( $policy, array( 'copy_to_theme', 'use_map' ), true ) ) {
			return $policy;
		}

		return new WP_Error( 'static_site_importer_invalid_asset_materialization_policy', 'Asset materialization policy must be one of: copy_to_theme, use_map.' );
	}

	/**
	 * Resolve a local source URL through the active asset map and record the lookup.
	 *
	 * @param string $url         Source URL or path.
	 * @param string $source_path Source-relative document path.
	 * @param string $source      Diagnostic source label.
	 * @return array{key:string,entry:array<string,mixed>}|null
	 */
	private static function resolve_asset_map_reference( string $url, string $source_path, string $source ): ?array {
		if ( empty( self::$active_asset_map ) || ! self::is_local_url( $url ) ) {
			return null;
		}

		$key = self::asset_map_lookup_key( $url, $source_path );
		if ( '' === $key ) {
			return null;
		}

		if ( isset( self::$active_asset_map[ $key ] ) ) {
			self::record_asset_map_lookup( 'resolved', $source, $source_path, $url, $key, self::$active_asset_map[ $key ] );
			return array(
				'key'   => $key,
				'entry' => self::$active_asset_map[ $key ],
			);
		}

		self::record_asset_map_lookup( 'unresolved', $source, $source_path, $url, $key );
		return null;
	}

	/**
	 * Convert a source URL/path to an asset-map key relative to the source root.
	 *
	 * @param string $url         Source URL or path.
	 * @param string $source_path Source-relative document path.
	 * @return string
	 */
	private static function asset_map_lookup_key( string $url, string $source_path ): string {
		$path = wp_parse_url( html_entity_decode( $url, ENT_QUOTES ), PHP_URL_PATH );
		if ( ! is_string( $path ) || '' === trim( $path ) ) {
			return '';
		}

		$path = rawurldecode( $path );
		if ( str_starts_with( $path, '/' ) ) {
			return self::normalize_asset_map_key( $path );
		}

		$base = trim( dirname( self::normalize_route_path( $source_path ) ), './' );
		return self::normalize_asset_map_key( ( '.' === $base || '' === $base ? '' : $base . '/' ) . $path );
	}

	/**
	 * Normalize an asset-map key without allowing traversal outside the source root.
	 *
	 * @param string $path Asset path.
	 * @return string
	 */
	private static function normalize_asset_map_key( string $path ): string {
		$path     = str_replace( '\\', '/', html_entity_decode( $path, ENT_QUOTES ) );
		$path     = ltrim( $path, '/' );
		$segments = array();

		foreach ( explode( '/', $path ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}

			if ( '..' === $segment ) {
				if ( empty( $segments ) ) {
					return '';
				}

				array_pop( $segments );
				continue;
			}

			$segments[] = $segment;
		}

		return implode( '/', $segments );
	}

	/**
	 * Record asset map lookup diagnostics in the import report.
	 *
	 * @param string              $status      Lookup status.
	 * @param string              $source      Diagnostic source label.
	 * @param string              $source_path Source-relative document path.
	 * @param string              $url         Original URL.
	 * @param string              $key         Asset map key.
	 * @param array<string,mixed> $entry       Optional resolved map entry.
	 * @return void
	 */
	private static function record_asset_map_lookup( string $status, string $source, string $source_path, string $url, string $key, array $entry = array() ): void {
		if ( ! isset( self::$conversion_report['asset_map'] ) || ! is_array( self::$conversion_report['asset_map'] ) ) {
			self::$conversion_report['asset_map'] = array(
				'supplied'         => ! empty( self::$active_asset_map ),
				'entry_count'      => count( self::$active_asset_map ),
				'resolved_count'   => 0,
				'unresolved_count' => 0,
				'resolved'         => array(),
				'unresolved'       => array(),
			);
		}

		$row = array(
			'source'      => $source,
			'source_path' => $source_path,
			'url'         => $url,
			'key'         => $key,
		);

		if ( 'resolved' === $status ) {
			foreach ( array( 'url', 'attachment_id', 'mime_type', 'width', 'height', 'alt' ) as $field ) {
				if ( isset( $entry[ $field ] ) ) {
					$row[ $field ] = $entry[ $field ];
				}
			}

			self::$conversion_report['asset_map']['resolved'][] = $row;
			++self::$conversion_report['asset_map']['resolved_count'];
			return;
		}

		self::$conversion_report['asset_map']['unresolved'][] = $row;
		++self::$conversion_report['asset_map']['unresolved_count'];
		self::$conversion_report['diagnostics'][] = array(
			'type'        => 'asset_map_unresolved',
			'source'      => $source,
			'source_path' => $source_path,
			'url'         => $url,
			'key'         => $key,
			'message'     => 'Local image reference had no matching asset_map entry; leaving the source reference unchanged.',
		);
	}

	/**
	 * Resolve a source-local asset URL from the explicit map or BAC file artifacts.
	 *
	 * @param string $url         Source URL or path.
	 * @param string $source_path Source-relative document path.
	 * @param string $source      Diagnostic source label.
	 * @return array<string,mixed>|null
	 */
	private static function resolve_local_asset_reference( string $url, string $source_path, string $source ): ?array {
		if ( ! self::is_local_url( $url ) ) {
			return null;
		}

		$key = self::asset_map_lookup_key( $url, $source_path );
		if ( '' === $key ) {
			self::record_local_asset_diagnostic( 'local_asset_unsafe_path', $source, $source_path, $url, '', 'Local asset reference resolves outside the static source root; leaving it unchanged.' );
			return null;
		}

		if ( 'use_map' === self::$active_asset_materialization_policy ) {
			$mapped = self::resolve_asset_map_reference( $url, $source_path, $source );
			if ( null === $mapped ) {
				return null;
			}

			$entry = $mapped['entry'];
			if ( ! isset( $entry['url'] ) || '' === trim( (string) $entry['url'] ) ) {
				self::record_local_asset_diagnostic( 'asset_map_missing_url', $source, $source_path, $url, $key, 'Asset map entry did not provide a URL; leaving the source reference unchanged.' );
				return null;
			}

			$entry['url'] = esc_url_raw( (string) $entry['url'] );
			self::record_local_asset_outcome( 'mapped', $source, $source_path, $url, $key, $entry );
			self::remember_asset_metadata( $url, $key, $entry );
			return $entry;
		}

		if ( isset( self::$active_artifact_materialized_assets[ $key ] ) ) {
			$asset = self::$active_artifact_materialized_assets[ $key ];
			self::record_local_asset_outcome( 'copied', $source, $source_path, $url, $key, $asset );
			self::remember_asset_metadata( $url, $key, $asset );
			return $asset;
		}

		self::record_local_asset_diagnostic( 'local_asset_not_materialized', $source, $source_path, $url, $key, 'Local asset reference was not present in BAC file artifacts or the supplied asset_map; leaving it unchanged.' );
		return null;
	}

	/**
	 * Store metadata under all keys serialized BAC block markup may reference.
	 *
	 * @param string              $url   Original source reference.
	 * @param string              $key   Source-relative key.
	 * @param array<string,mixed> $asset Asset metadata.
	 * @return void
	 */
	private static function remember_asset_metadata( string $url, string $key, array $asset ): void {
		foreach ( array( $url, html_entity_decode( $url, ENT_QUOTES ), $key, '/' . ltrim( $key, '/' ), $asset['url'] ?? '' ) as $metadata_key ) {
			$metadata_key = trim( (string) $metadata_key );
			if ( '' !== $metadata_key ) {
				self::$active_asset_metadata[ $metadata_key ] = $asset;
			}
		}
	}

	/**
	 * Record a local asset policy outcome row in the import report.
	 *
	 * @param string              $source      Diagnostic source label.
	 * @param string              $source_path Source-relative source path.
	 * @param string              $url         Original URL.
	 * @param string              $key         Source-relative key.
	 * @param array<string,mixed> $asset       Asset metadata.
	 * @return void
	 */
	private static function record_local_asset_outcome( string $outcome, string $source, string $source_path, string $url, string $key, array $asset ): void {
		if ( ! isset( self::$conversion_report['assets']['local'] ) || ! is_array( self::$conversion_report['assets']['local'] ) ) {
			self::$conversion_report['assets']['local'] = array();
		}

		$report_key = self::$active_asset_materialization_policy . ':' . (string) ( $asset['policy'] ?? 'theme' ) . ':' . $key;
		if ( isset( self::$recorded_local_asset_keys[ $report_key ] ) ) {
			return;
		}
		self::$recorded_local_asset_keys[ $report_key ] = true;

		$row = array(
			'source'                 => $source,
			'source_path'            => $source_path,
			'url'                    => $url,
			'key'                    => $key,
			'policy'                 => $asset['policy'] ?? 'theme',
			'materialization_policy' => self::$active_asset_materialization_policy,
			'outcome'                => $outcome,
			'rewritten_url'          => $asset['url'] ?? '',
			'theme_path'             => $asset['theme_path'] ?? '',
			'final_url'              => $asset['url'] ?? '',
			'mime_type'              => $asset['mime_type'] ?? '',
		);

		foreach ( array( 'id', 'attachment_id', 'width', 'height', 'alt' ) as $field ) {
			if ( isset( $asset[ $field ] ) ) {
				$row[ $field ] = $asset[ $field ];
			}
		}

		self::$conversion_report['assets']['local'][] = $row;
	}

	/**
	 * Record an actionable local asset diagnostic.
	 *
	 * @param string $type        Diagnostic type.
	 * @param string $source      Diagnostic source label.
	 * @param string $source_path Source-relative source path.
	 * @param string $url         Original URL.
	 * @param string $key         Source-relative key.
	 * @param string $message     Diagnostic message.
	 * @return void
	 */
	private static function record_local_asset_diagnostic( string $type, string $source, string $source_path, string $url, string $key, string $message ): void {
		self::$conversion_report['diagnostics'][] = array(
			'type'                   => $type,
			'category'               => 'unresolved_asset',
			'suggested_repair_class' => 'materialize_or_rewrite_asset',
			'source'                 => $source,
			'source_path'            => $source_path,
			'url'                    => $url,
			'key'                    => $key,
			'message'                => $message,
		);
	}

	/**
	 * Prepare one source page body to blocks after shared link rewriting.
	 *
	 * @param Static_Site_Importer_Source_Page $page      Source page.
	 * @param array<string,string>             $route_map Route map.
	 * @return string
	 */
	private static function source_page_content_blocks( Static_Site_Importer_Source_Page $page, array $route_map ): string {
		$source_path = $page->source_key();
		$source      = 'main:' . $source_path;
		$body        = self::route_external_script_tags_from_page_body( $page->body(), $source_path, $source );
		$body        = self::rewrite_internal_links( $body, $route_map, $source_path, $source );
		if ( 'blocks' !== $page->body_format() ) {
			self::$conversion_report['diagnostics'][] = array(
				'type'        => 'unsupported_document_artifact_format',
				'source'      => $source,
				'source_path' => $source_path,
				'format'      => $page->body_format(),
				'message'     => 'Website artifact imports require BAC document artifacts with serialized block markup.',
			);
			return '';
		}

		$body = self::rewrite_block_markup_local_asset_references( $body, $source_path, $source );
		$body = self::repair_image_block_class_markup( $body );
		return trim( $body );
	}

	/**
	 * Route generated external script references out of editable page content.
	 *
	 * @param string $body        Page body or block markup.
	 * @param string $source_path Source-relative document path.
	 * @param string $source      Diagnostic source label.
	 * @return string Body with external script elements removed.
	 */
	private static function route_external_script_tags_from_page_body( string $body, string $source_path, string $source ): string {
		if ( '' === trim( $body ) || stripos( $body, '<script' ) === false ) {
			return $body;
		}

		$routed = preg_replace_callback(
			'/<script\b([^>]*)>\s*<\/script>/is',
			static function ( array $matches ) use ( $source_path, $source ): string {
				$attributes = self::parse_simple_html_attributes( $matches[1] ?? '' );
				$src        = isset( $attributes['src'] ) ? trim( (string) $attributes['src'] ) : '';
				if ( '' === $src ) {
					return $matches[0];
				}

				self::record_page_body_script_asset( $src, $source_path, $source, $attributes );
				return '';
			},
			$body
		) ?? $body;

		return self::remove_empty_core_html_blocks( $routed );
	}

	/**
	 * Remove empty raw-HTML block wrappers left after routing script tags.
	 *
	 * @param string $body Block markup.
	 * @return string Block markup without empty core/html wrappers.
	 */
	private static function remove_empty_core_html_blocks( string $body ): string {
		return preg_replace( '/<!--\s+wp:html\s+-->\s*<!--\s+\/wp:html\s+-->/i', '', $body ) ?? $body;
	}

	/**
	 * Parse a conservative HTML attribute string.
	 *
	 * @param string $attribute_string Raw tag attribute string.
	 * @return array<string,string|bool>
	 */
	private static function parse_simple_html_attributes( string $attribute_string ): array {
		$attributes = array();
		if ( preg_match_all( '/([a-zA-Z_:][-a-zA-Z0-9_:.]*)\s*(?:=\s*("([^"]*)"|\'([^\']*)\'|([^\s"\'>]+)))?/', $attribute_string, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$name  = strtolower( $match[1] );
				$value = true;
				if ( isset( $match[3] ) && '' !== $match[3] ) {
					$value = $match[3];
				} elseif ( isset( $match[4] ) && '' !== $match[4] ) {
					$value = $match[4];
				} elseif ( isset( $match[5] ) && '' !== $match[5] ) {
					$value = $match[5];
				}

				$attributes[ $name ] = is_string( $value ) ? html_entity_decode( $value, ENT_QUOTES, 'UTF-8' ) : $value;
			}
		}

		return $attributes;
	}

	/**
	 * Record a script reference routed out of page block content.
	 *
	 * @param string                    $src         Script source.
	 * @param string                    $source_path Source-relative document path.
	 * @param string                    $source      Diagnostic source label.
	 * @param array<string,string|bool> $attributes  Parsed script attributes.
	 */
	private static function record_page_body_script_asset( string $src, string $source_path, string $source, array $attributes ): void {
		if ( ! isset( self::$conversion_report['assets']['scripts'] ) || ! is_array( self::$conversion_report['assets']['scripts'] ) ) {
			self::$conversion_report['assets']['scripts'] = array();
		}

		$asset = self::resolve_local_asset_reference( $src, $source_path, $source );
		$row   = array(
			'source'      => $source,
			'source_path' => $source_path,
			'src'         => esc_url_raw( $src ),
			'placement'   => 'page_body',
			'routed_to'   => 'theme_asset_metadata',
			'attributes'  => array_intersect_key( $attributes, array_fill_keys( array( 'type', 'defer', 'async', 'crossorigin', 'integrity' ), true ) ),
		);

		if ( null !== $asset ) {
			$row['asset'] = $asset;
		}

		self::$conversion_report['assets']['scripts'][] = $row;
	}

	/**
	 * Build a WordPress page title from a source document.
	 *
	 * @param string                           $filename Source filename.
	 * @param Static_Site_Importer_Source_Page $page     Source page.
	 * @return string
	 */
	private static function page_title( string $filename, Static_Site_Importer_Source_Page $page ): string {
		$title = $page->metadata_value( 'title' );
		if ( '' !== trim( $title ) ) {
			return sanitize_text_field( $title );
		}

		if ( self::is_root_index_source_filename( $filename ) ) {
			return 'Home';
		}

		$title = preg_replace( '/\s+[—-]\s+.+$/u', '', $page->document()->title() );
		if ( '' !== trim( (string) $title ) ) {
			return trim( (string) $title );
		}

		return ucwords( str_replace( '-', ' ', self::page_slug( $filename, $page ) ) );
	}

	/**
	 * Build a WordPress page slug from a source path.
	 *
	 * @param string                                $filename Source filename.
	 * @param Static_Site_Importer_Source_Page|null $page     Source page.
	 * @return string
	 */
	private static function page_slug( string $filename, ?Static_Site_Importer_Source_Page $page = null ): string {
		if ( $page instanceof Static_Site_Importer_Source_Page && 'bac_document' === $page->type() && self::is_index_source_filename( $filename ) && filter_var( $page->metadata_value( 'entrypoint' ), FILTER_VALIDATE_BOOLEAN ) ) {
			return 'home';
		}

		if ( $page instanceof Static_Site_Importer_Source_Page && '' !== trim( $page->metadata_value( 'slug' ) ) ) {
			$slug = sanitize_title( $page->metadata_value( 'slug' ) );
			if ( '' !== $slug ) {
				return $slug;
			}
		}

		$extensionless = preg_replace( '/\.(?:html?)$/i', '', self::normalize_route_path( $filename ) );
		$extensionless = trim( (string) $extensionless, '/' );

		if ( self::is_root_index_source_filename( $filename ) ) {
			return 'home';
		}

		if ( str_ends_with( $extensionless, '/index' ) ) {
			$extensionless = substr( $extensionless, 0, -6 );
		}

		return sanitize_title( str_replace( '/', '-', $extensionless ) );
	}

	/**
	 * Build a safe WordPress page status from source metadata.
	 *
	 * @param Static_Site_Importer_Source_Page $page Source page.
	 * @return string
	 */
	private static function page_status( Static_Site_Importer_Source_Page $page ): string {
		$status = sanitize_key( $page->metadata_value( 'status' ) );

		return in_array( $status, array( 'publish', 'draft', 'pending', 'private' ), true ) ? $status : 'publish';
	}

	/**
	 * Build a safe WordPress post type from source metadata.
	 *
	 * @param Static_Site_Importer_Source_Page $page Source page.
	 * @return string
	 */
	private static function page_post_type( Static_Site_Importer_Source_Page $page ): string {
		$post_type = sanitize_key( $page->metadata_value( 'post_type' ) );
		if ( '' === $post_type ) {
			return 'page';
		}

		$post_type_object = get_post_type_object( $post_type );
		return $post_type_object instanceof WP_Post_Type ? $post_type : 'page';
	}

	/**
	 * Resolve a local link href through the source route map.
	 *
	 * @param string               $href        Link href.
	 * @param string               $source_path Source-relative source path.
	 * @param array<string,string> $route_map   Route map.
	 * @return string|null
	 */
	private static function resolve_route_href( string $href, string $source_path, array $route_map ): ?string {
		if ( ! self::is_local_url( $href ) || str_starts_with( $href, '#' ) ) {
			return null;
		}

		$parts      = wp_parse_url( $href );
		$path       = isset( $parts['path'] ) ? (string) $parts['path'] : '';
		$query      = isset( $parts['query'] ) ? (string) $parts['query'] : '';
		$fragment   = isset( $parts['fragment'] ) ? (string) $parts['fragment'] : '';
		$lookup_key = str_starts_with( $path, '/' ) ? self::normalize_route_path( $path ) : self::normalize_route_path( dirname( $source_path ) . '/' . $path );

		$keys = array( $lookup_key, '/' . $lookup_key );
		if ( str_ends_with( $path, '/' ) ) {
			$keys[] = trailingslashit( $lookup_key );
			$keys[] = '/' . trailingslashit( $lookup_key );
		}

		foreach ( array_values( array_unique( $keys ) ) as $key ) {
			if ( isset( $route_map[ $key ] ) ) {
				$replacement = $route_map[ $key ];
				if ( '' !== $query ) {
					$replacement = add_query_arg( array(), $replacement ) . '?' . $query;
				}
				if ( '' !== $fragment ) {
					$replacement .= '#' . $fragment;
				}

				return $replacement;
			}
		}

		return null;
	}

	/**
	 * Normalize a route-like path without resolving outside the source root.
	 *
	 * @param string $path Route path.
	 * @return string
	 */
	private static function normalize_route_path( string $path ): string {
		$path_without_query = strtok( $path, '?' );
		$path               = str_replace( '\\', '/', false === $path_without_query ? $path : $path_without_query );
		$path               = ltrim( $path, '/' );
		$segments           = array();
		foreach ( explode( '/', $path ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}
			if ( '..' === $segment ) {
				array_pop( $segments );
				continue;
			}

			$segments[] = $segment;
		}

		return implode( '/', $segments );
	}

	/**
	 * Check whether a URL is local to the imported source tree.
	 *
	 * @param string $url URL or path.
	 * @return bool
	 */
	private static function is_local_url( string $url ): bool {
		$url = trim( $url );
		if ( '' === $url || str_starts_with( $url, '#' ) ) {
			return false;
		}

		$lower = strtolower( $url );
		if ( preg_match( '#^[a-z][a-z0-9+.-]*:#i', $lower ) || str_starts_with( $lower, '//' ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Record the bundle-level compiler result used by import_website_artifact().
	 *
	 * @param array<string,mixed> $compiled Compiler result envelope.
	 * @return void
	 */
	private static function record_website_artifact_compiler_result( array $compiled ): void {
		$artifacts = isset( $compiled['wordpress_artifacts'] ) && is_array( $compiled['wordpress_artifacts'] ) ? $compiled['wordpress_artifacts'] : array();
		$site      = isset( $artifacts['site'] ) && is_array( $artifacts['site'] ) ? $artifacts['site'] : array();
		self::$conversion_report['block_artifact_compiler']['available']        = true;
		self::$conversion_report['block_artifact_compiler']['website_artifact'] = array(
			'summary'     => bac_summarize_result( $compiled ),
			'provenance'  => isset( $compiled['provenance'] ) && is_array( $compiled['provenance'] ) ? $compiled['provenance'] : array(),
			'input'       => isset( $compiled['input'] ) && is_array( $compiled['input'] ) ? $compiled['input'] : array(),
			'diagnostics' => isset( $compiled['diagnostics'] ) && is_array( $compiled['diagnostics'] ) ? $compiled['diagnostics'] : array(),
		);

		if ( 'block-artifact-compiler/compiled-site/v1' === (string) ( $site['schema'] ?? '' ) ) {
			self::$conversion_report['block_artifact_compiler']['compiled_site'] = self::compiled_site_report_payload( $site );
		}
	}

	/**
	 * Preserve the BAC compiled-site contract in the SSI import report.
	 *
	 * @param array<string,mixed> $site BAC compiled-site artifact.
	 * @return array<string,mixed>
	 */
	private static function compiled_site_report_payload( array $site ): array {
		$pages          = isset( $site['pages'] ) && is_array( $site['pages'] ) ? $site['pages'] : array();
		$shared_regions = isset( $site['shared_regions'] ) && is_array( $site['shared_regions'] ) ? $site['shared_regions'] : array();
		$theme_assets   = isset( $site['theme_assets'] ) && is_array( $site['theme_assets'] ) ? $site['theme_assets'] : array();

		return array(
			'schema'              => (string) ( $site['schema'] ?? '' ),
			'page_count'          => count( $pages ),
			'pages'               => self::compiled_site_pages_report_payload( $pages ),
			'shared_region_count' => count( $shared_regions ),
			'shared_regions'      => $shared_regions,
			'theme_assets'        => array(
				'styles'  => isset( $theme_assets['styles'] ) && is_array( $theme_assets['styles'] ) ? $theme_assets['styles'] : array(),
				'scripts' => isset( $theme_assets['scripts'] ) && is_array( $theme_assets['scripts'] ) ? $theme_assets['scripts'] : array(),
			),
			'provenance'          => isset( $site['provenance'] ) && is_array( $site['provenance'] ) ? $site['provenance'] : array(),
		);
	}

	/**
	 * Normalize compiled-site page rows for reports.
	 *
	 * @param array<int,mixed> $pages Compiled-site page rows.
	 * @return array<int,array<string,mixed>>
	 */
	private static function compiled_site_pages_report_payload( array $pages ): array {
		$normalized = array();
		foreach ( $pages as $page ) {
			if ( ! is_array( $page ) ) {
				continue;
			}

			$normalized[] = array(
				'source_path' => isset( $page['source_path'] ) && is_scalar( $page['source_path'] ) ? (string) $page['source_path'] : '',
				'route_key'   => isset( $page['route_key'] ) && is_scalar( $page['route_key'] ) ? (string) $page['route_key'] : '',
				'slug'        => isset( $page['slug'] ) && is_scalar( $page['slug'] ) ? (string) $page['slug'] : '',
				'post_type'   => isset( $page['post_type'] ) && is_scalar( $page['post_type'] ) ? (string) $page['post_type'] : '',
				'title'       => isset( $page['title'] ) && is_scalar( $page['title'] ) ? (string) $page['title'] : '',
				'entrypoint'  => ! empty( $page['entrypoint'] ),
				'artifact'    => isset( $page['artifact'] ) && is_scalar( $page['artifact'] ) ? (string) $page['artifact'] : '',
			);
		}

		return $normalized;
	}

	/**
	 * Record compiler-routed full-document metadata and head asset references.
	 *
	 * @param array<string,mixed> $artifacts WordPress artifacts from BAC.
	 * @return void
	 */
	private static function record_website_artifact_document_metadata( array $artifacts ): void {
		$metadata = isset( $artifacts['document_metadata'] ) && is_array( $artifacts['document_metadata'] ) ? $artifacts['document_metadata'] : array();
		if ( 'block-artifact-compiler/document-metadata/v1' !== (string) ( $metadata['schema'] ?? '' ) ) {
			return;
		}

		$normalized = array(
			'schema'      => 'static-site-importer/document-metadata/v1',
			'source'      => 'block-artifact-compiler/document_metadata',
			'source_path' => isset( $metadata['source_path'] ) && is_scalar( $metadata['source_path'] ) ? (string) $metadata['source_path'] : '',
			'title'       => isset( $metadata['title'] ) && is_scalar( $metadata['title'] ) ? sanitize_text_field( (string) $metadata['title'] ) : '',
			'meta'        => self::normalize_document_metadata_rows( $metadata['meta'] ?? array(), array( 'charset', 'name', 'property', 'http_equiv', 'content' ) ),
			'links'       => self::normalize_document_metadata_rows( $metadata['links'] ?? array(), array( 'rel', 'href', 'as', 'type', 'media', 'crossorigin', 'integrity' ) ),
			'styles'      => self::normalize_hashed_head_assets( $metadata['styles'] ?? array() ),
			'scripts'     => self::normalize_document_scripts( $metadata['scripts'] ?? array() ),
		);

		self::$conversion_report['generated_theme']['document_metadata'] = $normalized;
		self::$conversion_report['diagnostics'][] = array(
			'type'        => 'document_metadata_routed',
			'source'      => $normalized['source_path'],
			'severity'    => 'info',
			'stage'       => 'website_artifact_materialization',
			'message'     => 'Full-document head metadata/assets were routed through the generated_theme.document_metadata contract instead of generated page block content.',
			'meta_count'  => count( $normalized['meta'] ),
			'link_count'  => count( $normalized['links'] ),
			'style_count' => count( $normalized['styles'] ),
			'script_count' => count( $normalized['scripts'] ),
		);
	}

	/**
	 * Normalize scalar rows from the document metadata contract.
	 *
	 * @param mixed             $rows    Raw rows.
	 * @param array<int,string> $allowed Allowed keys.
	 * @return array<int,array<string,string>>
	 */
	private static function normalize_document_metadata_rows( mixed $rows, array $allowed ): array {
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$item = array();
			foreach ( $allowed as $key ) {
				if ( isset( $row[ $key ] ) && is_scalar( $row[ $key ] ) && '' !== trim( (string) $row[ $key ] ) ) {
					$item[ $key ] = sanitize_text_field( (string) $row[ $key ] );
				}
			}

			if ( ! empty( $item ) ) {
				$normalized[] = $item;
			}
		}

		return $normalized;
	}

	/**
	 * Normalize hashed inline head asset summaries.
	 *
	 * @param mixed $rows Raw rows.
	 * @return array<int,array<string,mixed>>
	 */
	private static function normalize_hashed_head_assets( mixed $rows ): array {
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$hash = isset( $row['hash'] ) && is_scalar( $row['hash'] ) ? preg_replace( '/[^a-f0-9]/i', '', (string) $row['hash'] ) : '';
			$bytes = isset( $row['bytes'] ) ? max( 0, (int) $row['bytes'] ) : 0;
			if ( '' === $hash && 0 === $bytes ) {
				continue;
			}

			$normalized[] = array(
				'bytes' => $bytes,
				'hash'  => $hash,
			);
		}

		return $normalized;
	}

	/**
	 * Normalize script references from the document metadata contract.
	 *
	 * @param mixed $rows Raw rows.
	 * @return array<int,array<string,mixed>>
	 */
	private static function normalize_document_scripts( mixed $rows ): array {
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$item = array();
			foreach ( array( 'src', 'type', 'crossorigin', 'integrity' ) as $key ) {
				if ( isset( $row[ $key ] ) && is_scalar( $row[ $key ] ) && '' !== trim( (string) $row[ $key ] ) ) {
					$item[ $key ] = sanitize_text_field( (string) $row[ $key ] );
				}
			}

			foreach ( array( 'defer', 'async' ) as $key ) {
				if ( isset( $row[ $key ] ) ) {
					$item[ $key ] = (bool) $row[ $key ];
				}
			}

			if ( isset( $row['inline'] ) ) {
				$inline = self::normalize_hashed_head_assets( array( $row['inline'] ) );
				if ( ! empty( $inline ) ) {
					$item['inline'] = $inline[0];
				}
			}

			if ( ! empty( $item ) ) {
				$normalized[] = $item;
			}
		}

		return $normalized;
	}

	/**
	 * Write compiler-emitted files that can be consumed without re-importing HTML.
	 *
	 * @param string              $theme_dir Theme directory.
	 * @param array<string,mixed> $artifacts WordPress artifacts from BAC.
	 * @return array{css:string,js:string}|WP_Error
	 */
	private static function materialize_website_artifact_files_to_theme( string $theme_dir, array $artifacts ) {
		$result = Static_Site_Importer_Theme_Materializer::materialize_website_artifact_files( $theme_dir, self::$active_theme_uri, $artifacts );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		foreach ( $result['diagnostics'] as $diagnostic ) {
			self::$conversion_report['diagnostics'][] = $diagnostic;
		}
		self::$active_artifact_materialized_assets = array_merge( self::$active_artifact_materialized_assets, $result['assets'] );

		return array(
			'css' => $result['css'],
			'js'  => $result['js'],
		);
	}

	/**
	 * Append a class token if it is not already present.
	 *
	 * @param string $classes Existing classes.
	 * @param string $class_name_to_append Class to append.
	 * @return string Updated classes.
	 */
	private static function append_class_token( string $classes, string $class_name_to_append ): string {
		$tokens = preg_split( '/\s+/', trim( $classes ) );
		$tokens = false === $tokens ? array() : $tokens;
		if ( ! in_array( $class_name_to_append, $tokens, true ) ) {
			$tokens[] = $class_name_to_append;
		}

		return trim( implode( ' ', array_filter( $tokens ) ) );
	}	/**
	 * Initialize a conversion report.
	 *
	 * @param string $html_path Imported entry file.
	 * @return array<string, mixed>
	 */
	private static function new_conversion_report( string $html_path, array $source_metadata = array() ): array {
		return array(
			'version'                 => 1,
			'entry_file'              => $html_path,
			'source'                  => array_merge(
				array(
					'type' => empty( $source_metadata ) ? 'file' : (string) ( $source_metadata['source_type'] ?? 'file' ),
				),
				$source_metadata
			),
			'quality'                 => array(
				'pass'                               => true,
				'core_html_block_count'              => 0,
				'freeform_block_count'               => 0,
				'invalid_block_count'                => 0,
				'invalid_block_document_count'       => 0,
				'commerce_dependency_failures'       => 0,
				'failure_reasons'                    => array(),
			),
			'source_documents'        => array(
				'total_count'           => 0,
				'unresolved_links'      => array(),
				'unresolved_link_count' => 0,
			),
			'commerce_context'        => array(
				'supplied'       => false,
				'source'         => 'none',
				'product_count'  => 0,
				'selector_hints' => array(),
				'diagnostics'    => array(),
			),
			'assets'                  => array(
				'policy'       => 'theme',
				'local_policy' => 'copy_to_theme',
				'local'        => array(),
			),
			'asset_map'               => array(
				'supplied'         => false,
				'entry_count'      => 0,
				'resolved_count'   => 0,
				'unresolved_count' => 0,
				'resolved'         => array(),
				'unresolved'       => array(),
			),
			'block_artifact_compiler' => array(
				'available'      => true,
				'fragment_count' => 0,
				'fragments'      => array(),
			),
			'generated_theme'         => array(
				'document_metadata' => array(),
				'template_parts'    => array(),
				'block_documents' => array(),
				'freeform_blocks' => array(),
			),
			'visual_fidelity'         => array(
				'status'             => 'requires_external_render_check',
				'gate_owner'         => 'benchmark_harness',
				'comparison_targets' => array(),
				'notes'              => array(
					'Static Site Importer records source and generated DOM probes, render URLs, and theme artifacts for visual comparison; screenshot capture and computed-style/layout thresholds belong to the benchmark harness.',
				),
			),
			'semantic_fidelity'       => array(
				'status'             => 'requires_external_render_check',
				'gate_owner'         => 'benchmark_harness',
				'comparison_targets' => array(),
				'notes'              => array(
					'Static Site Importer records source/generated semantic comparison targets; browser DOM extraction and semantic fingerprint comparison belong to the benchmark harness.',
				),
			),
			'diagnostics'             => array(),
			'notes'                   => array(
				'Block Artifact Compiler owns the website-artifact to WordPress-artifact envelope; Static Site Importer materializes the result and records compiler summaries.',
				'Block Format Bridge owns HTML-to-block transform fidelity; Static Site Importer records converter diagnostics and quality gates the generated theme.',
				'Generated-theme block validation uses WordPress server-side block parsing and serialization checks; editor-runtime validation remains the exact Gutenberg authority.',
				'Visual fidelity requires browser rendering; use visual_fidelity.comparison_targets to compare source static HTML against the generated WordPress URL.',
				'Semantic fidelity requires browser DOM extraction; use semantic_fidelity.comparison_targets to compare source static HTML against the generated WordPress URL.',
			),
		);
	}	/**
	 * Record BAC-owned source document materialization details.
	 *
	 * @param array<int,mixed>                                   $documents  BAC document artifacts.
	 * @param array<string, Static_Site_Importer_Source_Page> $pages      Imported pages/posts.
	 * @param array<string,int>                                $page_ids   Imported post IDs keyed by source path.
	 * @param array<string,string>                             $permalinks Imported permalinks keyed by source path.
	 * @return void
	 */
	private static function record_bac_source_documents_summary( array $documents, array $pages, array $page_ids, array $permalinks ): void {
		$records = array();
		foreach ( $documents as $document ) {
			if ( ! is_array( $document ) ) {
				continue;
			}

			$source_path = self::normalize_route_path( isset( $document['source_path'] ) ? (string) $document['source_path'] : (string) ( $document['path'] ?? '' ) );
			if ( '' === $source_path ) {
				$source_path = self::normalize_route_path( isset( $document['slug'] ) ? (string) $document['slug'] : '' );
			}
			if ( '' === $source_path || ! isset( $pages[ $source_path ] ) ) {
				continue;
			}

			$page        = $pages[ $source_path ];
			$post_id     = (int) ( $page_ids[ $source_path ] ?? 0 );
			$post_type   = self::page_post_type( $page );
			$diagnostics = isset( $document['diagnostics'] ) && is_array( $document['diagnostics'] ) ? array_values( $document['diagnostics'] ) : array();

			$record = array(
				'source_path'  => $source_path,
				'post_id'      => $post_id,
				'post_type'    => $post_type,
				'slug'         => self::page_slug( $source_path, $page ),
				'title'        => self::page_title( $source_path, $page ),
				'status'       => self::page_status( $page ),
				'permalink'    => $permalinks[ $source_path ] ?? '',
				'diagnostics'  => $diagnostics,
				'materialized' => $post_id > 0,
			);

			$records[] = $record;
			foreach ( $diagnostics as $diagnostic ) {
				if ( is_array( $diagnostic ) ) {
					$diagnostic['source']                     = isset( $diagnostic['source'] ) && '' !== trim( (string) $diagnostic['source'] ) ? (string) $diagnostic['source'] : $source_path;
					self::$conversion_report['diagnostics'][] = $diagnostic;
				}
			}
		}

		self::$conversion_report['source_documents'] = array_merge(
			self::$conversion_report['source_documents'],
			array(
				'source'             => 'block_artifact_compiler',
				'total_count'        => count( $records ),
				'bac_documents'      => $records,
				'bac_document_count' => count( $records ),
			)
		);
	}

	/**
	 * Record a local source link or asset that could not be resolved/materialized.
	 *
	 * @param string $source      Diagnostic source label.
	 * @param string $source_path Source-relative source path.
	 * @param string $href        Original href or source URL.
	 * @param string $type        Diagnostic type.
	 * @return void
	 */
	private static function record_unresolved_internal_link( string $source, string $source_path, string $href, string $type = 'unresolved_internal_link' ): void {
		if ( ! self::is_local_url( $href ) ) {
			return;
		}

		self::$conversion_report['diagnostics'][] = array(
			'type'        => $type,
			'source'      => $source,
			'source_path' => $source_path,
			'href'        => $href,
		);
	}

	/**
	 * Record WooCommerce product seeding results for an already-validated manifest.
	 *
	 * @param array<string, mixed> $args Import args.
	 * @return void
	 */
	private static function record_product_seeding_report( array $args ): void {
		$manifest = isset( $args['products_manifest'] ) && is_array( $args['products_manifest'] ) ? $args['products_manifest'] : null;
		if ( null === $manifest ) {
			$report_manifest = self::$conversion_report['commerce']['products_manifest'] ?? array();
			if ( is_array( $report_manifest ) && true === ( $report_manifest['valid'] ?? false ) ) {
				$manifest = isset( $report_manifest['products'] ) && is_array( $report_manifest['products'] ) ? $report_manifest['products'] : array();
			}
		}

		if ( null === $manifest ) {
			self::$conversion_report['product_seeding']           = Static_Site_Importer_Woo_Product_Seeder::new_report();
			self::$conversion_report['product_seeding']['reason'] = 'no_validated_manifest';
			return;
		}

		self::$conversion_report['product_seeding'] = Static_Site_Importer_Woo_Product_Seeder::seed( $manifest );
	}

	/**
	 * Record the WooCommerce dependency check for commerce-bearing imports.
	 *
	 * Commerce intent is detected when the artifact import has a validated
	 * products manifest or compiler-supplied commerce context carrying at least
	 * one product. When intent is present, WooCommerce must be active or the
	 * caller must explicitly waive the requirement via allow_missing_woocommerce.
	 * Without intent, no commerce.dependencies shape is recorded.
	 *
	 * @param array<string, mixed> $args Import args.
	 * @return void
	 */
	private static function record_commerce_dependency_check( array $args ): void {
		$intent = self::commerce_dependency_intent();
		if ( ! $intent['present'] ) {
			return;
		}

		$woocommerce_active = Static_Site_Importer_Woo_Product_Seeder::woocommerce_available();
		$waived             = ! empty( $args['allow_missing_woocommerce'] );

		$dependencies = array(
			'woocommerce' => array(
				'required'      => true,
				'active'        => $woocommerce_active,
				'sources'       => $intent['sources'],
				'product_count' => $intent['product_count'],
				'waived'        => $waived,
				'missing_apis'  => $woocommerce_active ? array() : array( 'WC_Product_Simple', 'product_post_type', 'product_cat_taxonomy' ),
			),
		);

		if ( ! isset( self::$conversion_report['commerce'] ) || ! is_array( self::$conversion_report['commerce'] ) ) {
			self::$conversion_report['commerce'] = array();
		}
		self::$conversion_report['commerce']['dependencies'] = $dependencies;

		if ( $woocommerce_active ) {
			self::$conversion_report['diagnostics'][] = array(
				'code'          => 'woocommerce_present',
				'severity'      => 'info',
				'source'        => 'commerce.dependencies.woocommerce',
				'message'       => 'WooCommerce is active; commerce-bearing import will seed products.',
				'product_count' => $intent['product_count'],
				'sources'       => $intent['sources'],
			);
			return;
		}

		if ( $waived ) {
			self::$conversion_report['diagnostics'][] = array(
				'code'          => 'woocommerce_waived',
				'severity'      => 'warning',
				'source'        => 'commerce.dependencies.woocommerce',
				'message'       => 'Commerce-bearing import proceeded without WooCommerce because allow_missing_woocommerce was set; products were not seeded.',
				'product_count' => $intent['product_count'],
				'sources'       => $intent['sources'],
			);
			return;
		}

		++self::$conversion_report['quality']['commerce_dependency_failures'];
		self::$conversion_report['diagnostics'][] = array(
			'code'          => 'woocommerce_missing',
			'severity'      => 'error',
			'source'        => 'commerce.dependencies.woocommerce',
			'message'       => 'WooCommerce is required for this import. The source declared products but WooCommerce is not active. Install and activate WooCommerce, or pass allow_missing_woocommerce to import the theme without seeding products.',
			'product_count' => $intent['product_count'],
			'sources'       => $intent['sources'],
		);

		if ( isset( self::$conversion_report['product_seeding'] ) && is_array( self::$conversion_report['product_seeding'] ) ) {
			self::$conversion_report['product_seeding']['reason'] = 'woocommerce_required_but_missing';
		}
	}

	/**
	 * Detect commerce intent for the active import.
	 *
	 * @return array{present:bool,sources:array<int,string>,product_count:int}
	 */
	private static function commerce_dependency_intent(): array {
		$sources       = array();
		$product_count = 0;

		$manifest = self::$conversion_report['commerce']['products_manifest'] ?? array();
		if ( is_array( $manifest ) && true === ( $manifest['valid'] ?? false ) ) {
			$manifest_count = (int) ( $manifest['product_count'] ?? 0 );
			if ( $manifest_count > 0 ) {
				$sources[]     = 'products_manifest';
				$product_count = $manifest_count;
			}
		}

		$context = self::$conversion_report['commerce_context'] ?? array();
		if ( is_array( $context ) && true === ( $context['supplied'] ?? false ) ) {
			$context_count = (int) ( $context['product_count'] ?? 0 );
			if ( $context_count > 0 ) {
				$source = (string) ( $context['source'] ?? 'commerce_context' );
				if ( '' === $source ) {
					$source = 'commerce_context';
				}
				if ( ! in_array( $source, $sources, true ) ) {
					$sources[] = $source;
				}
				if ( $context_count > $product_count ) {
					$product_count = $context_count;
				}
			}
		}

		return array(
			'present'       => ! empty( $sources ),
			'sources'       => $sources,
			'product_count' => $product_count,
		);
	}

	/**
	 * Analyze generated theme block documents before writing the import report.
	 *
	 * @param array<string,string> $writes    Generated files keyed by absolute path.
	 * @param string               $theme_dir Generated theme directory.
	 * @return void
	 */
	private static function analyze_generated_theme_block_documents( array $writes, string $theme_dir ): void {
		foreach ( $writes as $path => $content ) {
			$relative_path = ltrim( str_replace( trailingslashit( $theme_dir ), '', $path ), '/' );
			if ( ! self::is_generated_block_document_path( $relative_path ) ) {
				continue;
			}

			$block_markup = self::generated_block_document_markup( $relative_path, $content );
			$analysis     = self::analyze_generated_block_document( $relative_path, $block_markup );
			self::$conversion_report['generated_theme']['block_documents'][] = $analysis;
		}
	}

	/**
	 * Determine whether a generated file should contain block markup.
	 *
	 * @param string $relative_path Theme-relative path.
	 * @return bool
	 */
	private static function is_generated_block_document_path( string $relative_path ): bool {
		return str_starts_with( $relative_path, 'templates/' ) || str_starts_with( $relative_path, 'parts/' ) || str_starts_with( $relative_path, 'patterns/' );
	}

	/**
	 * Extract block markup from a generated block document.
	 *
	 * @param string $relative_path Theme-relative path.
	 * @param string $content       Generated file content.
	 * @return string
	 */
	private static function generated_block_document_markup( string $relative_path, string $content ): string {
		if ( str_starts_with( $relative_path, 'patterns/' ) ) {
			$parts = explode( '?>', $content, 2 );
			return trim( 2 === count( $parts ) ? $parts[1] : $content );
		}

		return trim( $content );
	}

	/**
	 * Analyze one generated block document for server-visible quality issues.
	 *
	 * @param string $relative_path Theme-relative path.
	 * @param string $block_markup  Block markup.
	 * @return array<string,mixed>
	 */
	private static function analyze_generated_block_document( string $relative_path, string $block_markup ): array {
		$blocks          = parse_blocks( $block_markup );
		$block_count     = 0;
		$core_html_count = 0;
		$freeform_count  = 0;
		$invalid_count   = 0;

		/** @var array<int, array<string, mixed>> $analyzed_blocks */
		$analyzed_blocks = $blocks;
		self::analyze_generated_block_list( $analyzed_blocks, $block_count, $core_html_count, $freeform_count, $invalid_count, $relative_path );

		$serialized             = serialize_blocks( $blocks );
		$serialization_mismatch = self::normalize_block_document_for_report( $block_markup ) !== self::normalize_block_document_for_report( $serialized );
		if ( $serialization_mismatch ) {
			++$invalid_count;
		}

		self::$conversion_report['quality']['core_html_block_count'] += $core_html_count;
		self::$conversion_report['quality']['freeform_block_count']  += $freeform_count;
		self::$conversion_report['quality']['invalid_block_count']   += $invalid_count;
		if ( $invalid_count > 0 ) {
			++self::$conversion_report['quality']['invalid_block_document_count'];
			self::$conversion_report['diagnostics'][] = array(
				'type'                   => 'invalid_block_document',
				'source'                 => $relative_path,
				'block_count'            => $block_count,
				'core_html_block_count'  => $core_html_count,
				'freeform_block_count'   => $freeform_count,
				'invalid_block_count'    => $invalid_count,
				'serialization_mismatch' => $serialization_mismatch,
				'original_excerpt'       => self::diagnostic_excerpt( $block_markup ),
				'serialized_excerpt'     => self::diagnostic_excerpt( $serialized ),
			);
		}

		return array(
			'path'                   => $relative_path,
			'block_count'            => $block_count,
			'core_html_block_count'  => $core_html_count,
			'freeform_block_count'   => $freeform_count,
			'invalid_block_count'    => $invalid_count,
			'serialization_mismatch' => $serialization_mismatch,
		);
	}

	/**
	 * Walk parsed blocks for generated-theme quality metrics.
	 *
	 * @param array<int,array<string,mixed>> $blocks          Parsed blocks.
	 * @param int                           $block_count     Total named block count.
	 * @param int                           $core_html_count HTML block count.
	 * @param int                           $freeform_count  Freeform block count.
	 * @param int                           $invalid_count   Invalid block count.
	 * @param string                        $source          Theme-relative source document path.
	 * @param array<int,int>                $path            Parsed block path.
	 * @return void
	 */
	private static function analyze_generated_block_list( array $blocks, int &$block_count, int &$core_html_count, int &$freeform_count, int &$invalid_count, string $source = '', array $path = array() ): void {
		foreach ( $blocks as $index => $block ) {
			$name = isset( $block['blockName'] ) ? $block['blockName'] : null;
			if ( is_string( $name ) && '' !== $name ) {
				++$block_count;
				if ( 'core/html' === $name ) {
					++$core_html_count;
					self::record_generated_core_html_block( $source, array_merge( $path, array( $index ) ), $block );
				}
				if ( 'core/freeform' === $name ) {
					++$freeform_count;
					self::record_generated_freeform_block( $source, array_merge( $path, array( $index ) ), $block, false );
				}
			} elseif ( '' !== trim( isset( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ? $block['innerHTML'] : '' ) ) {
				++$freeform_count;
				++$invalid_count;
				self::record_generated_freeform_block( $source, array_merge( $path, array( $index ) ), $block, true );
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				self::analyze_generated_block_list( $block['innerBlocks'], $block_count, $core_html_count, $freeform_count, $invalid_count, $source, array_merge( $path, array( $index ) ) );
			}
		}
	}

	/**
	 * Record an actionable generated core/html block diagnostic.
	 *
	 * @param string              $source Theme-relative source document path.
	 * @param array<int,int>      $path   Parsed block path.
	 * @param array<string,mixed> $block  Parsed block.
	 * @return void
	 */
	private static function record_generated_core_html_block( string $source, array $path, array $block ): void {
		$html = '';
		if ( isset( $block['attrs']['content'] ) && is_string( $block['attrs']['content'] ) ) {
			$html = $block['attrs']['content'];
		} elseif ( isset( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ) {
			$html = $block['innerHTML'];
		}

		self::$conversion_report['diagnostics'][] = self::fallback_diagnostic_entry(
			'core_html_block',
			$source,
			$html,
			array(
				'reason' => 'generated_document_contains_core_html',
				'stage'  => 'generated_theme_block_analysis',
				'path'   => implode( '.', $path ),
			),
			$block
		);
	}

	/**
	 * Record an actionable generated freeform block diagnostic.
	 *
	 * @param string              $source     Theme-relative source document path.
	 * @param array<int,int>      $path       Parsed block path.
	 * @param array<string,mixed> $block      Parsed block.
	 * @param bool                $malformed  Whether the block parser exposed raw HTML without a block name.
	 * @return void
	 */
	private static function record_generated_freeform_block( string $source, array $path, array $block, bool $malformed ): void {
		$html = '';
		if ( isset( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ) {
			$html = $block['innerHTML'];
		}

		$emitted = '';
		if ( ! $malformed && function_exists( 'serialize_blocks' ) ) {
			// @phpstan-ignore-next-line argument.type -- Parsed block shape comes from WordPress parse_blocks().
			$emitted = serialize_blocks( array( $block ) );
		}
		if ( '' === trim( $emitted ) ) {
			$emitted = $html;
		}

		$entry                          = self::fallback_diagnostic_entry(
			'freeform_block',
			$source,
			$html,
			array(
				'reason' => $malformed ? 'generated_document_contains_malformed_freeform_html' : 'generated_document_contains_core_freeform',
				'stage'  => 'generated_theme_block_analysis',
				'path'   => implode( '.', $path ),
			),
			$block
		);
		$entry['emitted_block_preview'] = self::diagnostic_excerpt( $emitted );
		$entry['malformed']             = $malformed;

		self::$conversion_report['diagnostics'][]                        = $entry;
		self::$conversion_report['generated_theme']['freeform_blocks'][] = $entry;
	}

	/**
	 * Normalize generated markup enough to avoid formatting-only report noise.
	 *
	 * @param string $markup Block document markup.
	 * @return string
	 */
	private static function normalize_block_document_for_report( string $markup ): string {
		$markup = str_replace( array( "\r\n", "\r" ), "\n", trim( $markup ) );
		$markup = preg_replace( '/>\s+</', '><', $markup );
		$markup = preg_replace( '/\s+/', ' ', is_string( $markup ) ? $markup : '' );

		return is_string( $markup ) ? trim( $markup ) : '';
	}

	/**
	 * Record generated core/button wrapper classes from serialized block markup.
	 *
	 * @param string $blocks Serialized block markup.
	 * @return void
	 */
	private static function record_button_wrapper_classes_from_blocks( string $blocks ): void {
		if ( '' === trim( $blocks ) || ! function_exists( 'parse_blocks' ) ) {
			return;
		}

		/** @var array<int, array<string, mixed>> $parsed_blocks */
		$parsed_blocks = parse_blocks( $blocks );
		self::record_button_wrapper_classes_from_parsed_blocks( $parsed_blocks );
	}

	/**
	 * Record generated core/button wrapper classes from parsed blocks.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed block list.
	 * @return void
	 */
	private static function record_button_wrapper_classes_from_parsed_blocks( array $blocks ): void {
		foreach ( $blocks as $block ) {
			if ( 'core/button' === ( $block['blockName'] ?? null ) && ! empty( $block['attrs']['className'] ) && is_string( $block['attrs']['className'] ) ) {
				self::record_button_wrapper_classes( $block['attrs']['className'] );
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				self::record_button_wrapper_classes_from_parsed_blocks( $block['innerBlocks'] );
			}
		}
	}

	/**
	 * Record individual class tokens present on generated core/button wrappers.
	 *
	 * @param string $class_name Space-separated class attribute.
	 * @return void
	 */
	private static function record_button_wrapper_classes( string $class_name ): void {
		$classes = preg_split( '/\s+/', trim( $class_name ) );
		if ( ! is_array( $classes ) ) {
			return;
		}

		foreach ( $classes as $class ) {
			if ( preg_match( '/^[A-Za-z_-][A-Za-z0-9_-]*$/', $class ) ) {
				self::$button_wrapper_classes[ $class ] = true;
			}
		}
	}	/**
	 * Build a normalized fallback/core-html diagnostic entry.
	 *
	 * @param string              $type         Diagnostic type.
	 * @param string              $source       Source fragment or generated document path.
	 * @param string              $element_html Source HTML fragment.
	 * @param array<string,mixed> $context      Diagnostic context.
	 * @param array<string,mixed> $block        Generated or parsed block.
	 * @return array<string,mixed>
	 */
	private static function fallback_diagnostic_entry( string $type, string $source, string $element_html, array $context, array $block ): array {
		$selector = isset( $context['selector'] ) && is_scalar( $context['selector'] ) ? trim( (string) $context['selector'] ) : '';
		if ( '' === $selector ) {
			$selector = self::diagnostic_selector_from_html( $element_html );
		}

		$emitted = '';
		if ( function_exists( 'serialize_blocks' ) ) {
			// @phpstan-ignore-next-line argument.type -- Parsed block shape comes from WordPress parse_blocks().
			$emitted = serialize_blocks( array( $block ) );
		}
		if ( '' === trim( $emitted ) || preg_match( '/^<!--\s+wp:[^>]+\/-->$/', trim( $emitted ) ) ) {
			$emitted = isset( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ? $block['innerHTML'] : $element_html;
		}

		$entry = array(
			'type'                  => $type,
			'source'                => $source,
			'selector'              => '' !== $selector ? $selector : null,
			'excerpt'               => self::diagnostic_excerpt( wp_strip_all_tags( $element_html ) ),
			'source_html_preview'   => self::diagnostic_excerpt( $element_html ),
			'emitted_block_preview' => self::diagnostic_excerpt( $emitted ),
			'reason'                => isset( $context['reason'] ) ? (string) $context['reason'] : 'unknown',
			'tag_name'              => isset( $context['tag_name'] ) ? (string) $context['tag_name'] : self::diagnostic_tag_name_from_html( $element_html ),
			'block_name'            => isset( $block['blockName'] ) ? (string) $block['blockName'] : null,
			'converter'             => 'html-to-blocks-converter',
			'stage'                 => isset( $context['stage'] ) ? (string) $context['stage'] : 'html_to_blocks',
			'html_length'           => strlen( $element_html ),
			'html_excerpt'          => self::diagnostic_excerpt( $element_html ),
		);

		if ( isset( $context['occurrence'] ) ) {
			$entry['occurrence'] = (int) $context['occurrence'];
		}

		if ( isset( $context['path'] ) && is_scalar( $context['path'] ) ) {
			$entry['block_path'] = (string) $context['path'];
		}

		return $entry;
	}

	/**
	 * Finalize quality summary and gate status.
	 *
	 * @param array $args Import args.
	 * @return array<string, mixed>
	 */
	private static function finalize_quality_report( array $args ): array {
		self::normalize_import_diagnostics();

		$quality = self::$conversion_report['quality'];
		$reasons = array();
		if ( $quality['core_html_block_count'] > 0 ) {
			$reasons[] = 'core_html_block';
		}
		if ( $quality['freeform_block_count'] > 0 ) {
			$reasons[] = 'freeform_block';
		}
		if ( $quality['invalid_block_count'] > 0 ) {
			$reasons[] = 'invalid_block';
		}
		if ( ( $quality['commerce_dependency_failures'] ?? 0 ) > 0 ) {
			$reasons[] = 'woocommerce_missing';
		}

		$quality['pass']            = empty( $reasons );
		$quality['failure_reasons'] = $reasons;
		$quality['fail_import']     = false;
		if ( ! empty( $args['fail_on_quality'] ) && ! $quality['pass'] ) {
			$quality['fail_import'] = true;
		}
		// Commerce dependency failures are always import-failing regardless of --fail-on-quality.
		// Silently producing a no-products WordPress site for a commerce import is qualitatively
		// worse than a layout fallback, so the gate trips even without an explicit failure flag.
		if ( in_array( 'woocommerce_missing', $reasons, true ) ) {
			$quality['fail_import'] = true;
		}

		$quality['diagnostic_refs']         = self::quality_diagnostic_refs( self::$conversion_report['diagnostics'] ?? array() );
		self::$conversion_report['quality'] = $quality;
		self::normalize_source_document_diagnostic_refs();
		self::$conversion_report['artifact_diagnostics'] = Static_Site_Importer_WP_Codebox_Artifact_Diagnostics_Normalizer::build(
			array( 'diagnostics' => self::$conversion_report['diagnostics'] ?? array() ),
			array(
				'source'          => 'static-site-importer',
				'stage'           => 'import',
				'observationType' => 'static-site-importer/import-report',
				'refs'            => array(
					array(
						'path' => 'import-report.json',
						'kind' => 'static-site-importer/import-report',
					),
				),
			)
		);
		return $quality;
	}

	/**
	 * Normalize import diagnostics into a stable, machine-actionable shape.
	 *
	 * Existing human-readable fields remain alongside the machine fields.
	 * The added fields give repair loops generic, path-addressed records without
	 * coupling the report to a specific downstream consumer.
	 *
	 * @return void
	 */
	private static function normalize_import_diagnostics(): void {
		if ( empty( self::$conversion_report['diagnostics'] ) || ! is_array( self::$conversion_report['diagnostics'] ) ) {
			return;
		}

		$normalized = array();
		foreach ( array_values( self::$conversion_report['diagnostics'] ) as $index => $diagnostic ) {
			if ( ! is_array( $diagnostic ) ) {
				continue;
			}

			$type        = isset( $diagnostic['type'] ) && is_scalar( $diagnostic['type'] ) ? (string) $diagnostic['type'] : 'import_diagnostic';
			$source      = isset( $diagnostic['source'] ) && is_scalar( $diagnostic['source'] ) ? (string) $diagnostic['source'] : '';
			$source_path = isset( $diagnostic['source_path'] ) && is_scalar( $diagnostic['source_path'] ) ? (string) $diagnostic['source_path'] : self::diagnostic_source_path( $source );
			$reason_code = self::diagnostic_reason_code( $type, $diagnostic );

			$machine = array(
				'id'                     => self::diagnostic_id( $index, $type, $source_path, $reason_code ),
				'severity'               => self::diagnostic_severity( $type ),
				'category'               => self::diagnostic_category( $type ),
				'reason_code'            => $reason_code,
				'suggested_repair_class' => self::diagnostic_repair_class( $type ),
				'source_path'            => $source_path,
			);

			$selector = isset( $diagnostic['selector'] ) && is_scalar( $diagnostic['selector'] ) ? trim( (string) $diagnostic['selector'] ) : '';
			if ( '' !== $selector ) {
				$machine['selector'] = $selector;
			}

			if ( isset( $diagnostic['line'] ) && is_numeric( $diagnostic['line'] ) ) {
				$machine['line'] = (int) $diagnostic['line'];
			}

			if ( isset( $diagnostic['line_range'] ) ) {
				$machine['line_range'] = $diagnostic['line_range'];
			}

			$context = self::diagnostic_context( $diagnostic );
			if ( ! empty( $context ) ) {
				$machine['context'] = $context;
			}

			$normalized[] = array_merge( $machine, $diagnostic );
		}

		self::$conversion_report['diagnostics'] = $normalized;
	}

	/**
	 * Build quality counter references into normalized diagnostics.
	 *
	 * @param array<int,array<string,mixed>> $diagnostics Normalized diagnostics.
	 * @return array<string,array<int,string>> Diagnostic IDs keyed by quality count.
	 */
	private static function quality_diagnostic_refs( array $diagnostics ): array {
		$types_by_count = array(
			'core_html_block_count'              => array( 'core_html_block' ),
			'freeform_block_count'               => array( 'freeform_block' ),
			'invalid_block_count'                => array( 'invalid_block_document' ),
			'commerce_dependency_failures'       => array( 'commerce_dependency_failure' ),
		);

		$refs = array();
		foreach ( $types_by_count as $count_key => $types ) {
			$refs[ $count_key ] = array_values(
				array_filter(
					array_map(
						static function ( array $diagnostic ) use ( $types ): string {
							return in_array( $diagnostic['type'] ?? '', $types, true ) && isset( $diagnostic['id'] ) ? (string) $diagnostic['id'] : '';
						},
						$diagnostics
					)
				)
			);
		}

		return $refs;
	}

	/**
	 * Link source-document counts back to concrete diagnostics.
	 *
	 * @return void
	 */
	private static function normalize_source_document_diagnostic_refs(): void {
		$diagnostics = isset( self::$conversion_report['diagnostics'] ) && is_array( self::$conversion_report['diagnostics'] ) ? self::$conversion_report['diagnostics'] : array();
		$refs        = array(
			'unresolved_link_count' => array(),
		);

		foreach ( $diagnostics as $diagnostic ) {
			if ( ! is_array( $diagnostic ) || empty( $diagnostic['id'] ) ) {
				continue;
			}

			$type = (string) ( $diagnostic['type'] ?? '' );
			if ( 'unresolved_internal_link' === $type ) {
				$refs['unresolved_link_count'][] = (string) $diagnostic['id'];
			}
		}

		self::$conversion_report['source_documents']['diagnostic_refs'] = $refs;
	}

	/**
	 * Build a stable diagnostic ID.
	 *
	 * @param int    $index       Zero-based diagnostic position.
	 * @param string $type        Diagnostic type.
	 * @param string $source_path Source-relative path.
	 * @param string $reason_code Reason code.
	 * @return string Diagnostic ID.
	 */
	private static function diagnostic_id( int $index, string $type, string $source_path, string $reason_code ): string {
		$slug = sanitize_key( $type . '-' . $reason_code . '-' . $source_path );
		if ( '' === $slug ) {
			$slug = 'import-diagnostic';
		}

		return sprintf( 'diag-%03d-%s', $index + 1, substr( $slug, 0, 80 ) );
	}

	/**
	 * Infer a source-relative path from a diagnostic source label.
	 *
	 * @param string $source Diagnostic source label.
	 * @return string Source-relative path or generated artifact path.
	 */
	private static function diagnostic_source_path( string $source ): string {
		if ( str_contains( $source, ':' ) ) {
			return (string) substr( $source, strpos( $source, ':' ) + 1 );
		}

		return $source;
	}

	/**
	 * Normalize a diagnostic reason code.
	 *
	 * @param string              $type       Diagnostic type.
	 * @param array<string,mixed> $diagnostic Diagnostic record.
	 * @return string Reason code.
	 */
	private static function diagnostic_reason_code( string $type, array $diagnostic ): string {
		foreach ( array( 'reason_code', 'reason', 'error_code' ) as $key ) {
			if ( isset( $diagnostic[ $key ] ) && is_scalar( $diagnostic[ $key ] ) && '' !== trim( (string) $diagnostic[ $key ] ) ) {
				return sanitize_key( (string) $diagnostic[ $key ] );
			}
		}

		return sanitize_key( $type );
	}

	/**
	 * Classify diagnostic severity for repair prioritization.
	 *
	 * @param string $type Diagnostic type.
	 * @return string Severity.
	 */
	private static function diagnostic_severity( string $type ): string {
		if ( in_array( $type, array( 'invalid_block_document', 'commerce_dependency_failure' ), true ) ) {
			return 'error';
		}

		return 'warning';
	}

	/**
	 * Classify diagnostics by generic repair category.
	 *
	 * @param string $type Diagnostic type.
	 * @return string Category.
	 */
	private static function diagnostic_category( string $type ): string {
		$categories = array(
			'local_asset_not_materialized'         => 'unresolved_asset',
			'unresolved_internal_link'             => 'broken_internal_link',
			'unsupported_html_fallback'            => 'unsupported_element',
			'core_html_block'                      => 'fallback_block',
			'freeform_block'                       => 'fallback_block',
			'invalid_block_document'               => 'conversion_quality',
			'commerce_dependency_failure'          => 'conversion_quality',
			'commerce_product_inference_unmatched' => 'conversion_quality',
		);

		return $categories[ $type ] ?? 'import_quality';
	}

	/**
	 * Suggest a generic repair class for a diagnostic type.
	 *
	 * @param string $type Diagnostic type.
	 * @return string Suggested repair class.
	 */
	private static function diagnostic_repair_class( string $type ): string {
		$classes = array(
			'local_asset_not_materialized'         => 'materialize_or_rewrite_asset',
			'unresolved_internal_link'             => 'rewrite_or_create_internal_target',
			'unsupported_html_fallback'            => 'replace_unsupported_html',
			'core_html_block'                      => 'replace_fallback_block',
			'freeform_block'                       => 'replace_fallback_block',
			'invalid_block_document'               => 'repair_generated_block_markup',
			'commerce_dependency_failure'          => 'install_or_configure_dependency',
			'commerce_product_inference_unmatched' => 'provide_structured_product_data',
		);

		return $classes[ $type ] ?? 'inspect_import_diagnostic';
	}

	/**
	 * Extract concise diagnostic context for repair prompts.
	 *
	 * @param array<string,mixed> $diagnostic Diagnostic record.
	 * @return array<string,mixed> Context fields.
	 */
	private static function diagnostic_context( array $diagnostic ): array {
		$context = array();
		foreach ( array( 'href', 'tag', 'tag_name', 'block_name', 'block_path', 'excerpt', 'html_excerpt', 'source_html_preview', 'error_message' ) as $key ) {
			if ( array_key_exists( $key, $diagnostic ) && null !== $diagnostic[ $key ] && '' !== $diagnostic[ $key ] ) {
				$context[ $key ] = $diagnostic[ $key ];
			}
		}

		return $context;
	}

	/**
	 * Build the compact report summary consumed by validation harnesses.
	 *
	 * @param array<string, mixed> $report  Full conversion report.
	 * @param array<string, mixed> $quality Finalized quality summary.
	 * @return array<string, mixed>
	 */
	private static function import_report_summary( array $report, array $quality ): array {
		$diagnostics      = isset( $report['diagnostics'] ) && is_array( $report['diagnostics'] ) ? $report['diagnostics'] : array();
		$source_documents = isset( $report['source_documents'] ) && is_array( $report['source_documents'] ) ? $report['source_documents'] : array();

		return array(
			'schema'                       => 'static-site-importer/import-metrics/v1',
			'version'                      => 1,
			'report_version'               => (int) ( $report['version'] ?? 0 ),
			'status'                       => ! empty( $quality['fail_import'] ) ? 'failed' : 'completed',
			'theme_slug'                   => isset( $report['theme_slug'] ) ? (string) $report['theme_slug'] : '',
			'entry_file'                   => isset( $report['entry_file'] ) ? (string) $report['entry_file'] : '',
			'compiler'                     => self::compact_import_report_compiler_summary( $report ),
			'quality_pass'                 => ! empty( $quality['pass'] ),
			'fail_import'                  => ! empty( $quality['fail_import'] ),
			'failure_reasons'              => isset( $quality['failure_reasons'] ) && is_array( $quality['failure_reasons'] ) ? array_values( $quality['failure_reasons'] ) : array(),
			'core_html_block_count'        => (int) ( $quality['core_html_block_count'] ?? 0 ),
			'freeform_block_count'         => (int) ( $quality['freeform_block_count'] ?? 0 ),
			'invalid_block_count'          => (int) ( $quality['invalid_block_count'] ?? 0 ),
			'invalid_block_document_count' => (int) ( $quality['invalid_block_document_count'] ?? 0 ),
			'source_document_count'        => (int) ( $source_documents['total_count'] ?? 0 ),
			'unresolved_link_count'        => (int) ( $source_documents['unresolved_link_count'] ?? 0 ),
			'diagnostic_count'             => count( $diagnostics ),
			'diagnostic_summary'           => self::compact_import_report_diagnostic_summary( $diagnostics ),
			'warning_summaries'            => self::compact_import_report_diagnostic_summaries_by_severity( $diagnostics, 'warning' ),
			'error_summaries'              => self::compact_import_report_diagnostic_summaries_by_severity( $diagnostics, 'error' ),
			'diagnostics'                  => self::compact_import_report_diagnostics( $diagnostics ),
		);
	}

	/**
	 * Summarize compiler evidence for compact import metrics.
	 *
	 * @param array<string,mixed> $report Full conversion report.
	 * @return array<string,mixed>
	 */
	private static function compact_import_report_compiler_summary( array $report ): array {
		$compiler = isset( $report['block_artifact_compiler'] ) && is_array( $report['block_artifact_compiler'] ) ? $report['block_artifact_compiler'] : array();
		$summary  = array(
			'available'      => ! empty( $compiler['available'] ),
			'fragment_count' => (int) ( $compiler['fragment_count'] ?? 0 ),
		);

		$website_artifact = isset( $compiler['website_artifact'] ) && is_array( $compiler['website_artifact'] ) ? $compiler['website_artifact'] : array();
		$compiler_summary = isset( $website_artifact['summary'] ) && is_array( $website_artifact['summary'] ) ? $website_artifact['summary'] : array();
		if ( empty( $compiler_summary ) && ! empty( $compiler['fragments'][0]['summary'] ) && is_array( $compiler['fragments'][0]['summary'] ) ) {
			$compiler_summary = $compiler['fragments'][0]['summary'];
		}

		foreach ( array( 'schema', 'status', 'source' ) as $field ) {
			if ( isset( $compiler_summary[ $field ] ) && is_scalar( $compiler_summary[ $field ] ) ) {
				$summary[ $field ] = (string) $compiler_summary[ $field ];
			}
		}

		if ( isset( $compiler_summary['diagnostic_count'] ) ) {
			$summary['diagnostic_count'] = (int) $compiler_summary['diagnostic_count'];
		}

		return $summary;
	}

	/**
	 * Summarize compact diagnostics by severity.
	 *
	 * @param array<int,array<string,mixed>> $diagnostics Normalized diagnostics.
	 * @return array<string,int>
	 */
	private static function compact_import_report_diagnostic_summary( array $diagnostics ): array {
		$summary = array(
			'total'   => 0,
			'error'   => 0,
			'warning' => 0,
			'notice'  => 0,
			'info'    => 0,
		);

		foreach ( $diagnostics as $diagnostic ) {
			if ( ! is_array( $diagnostic ) ) {
				continue;
			}

			++$summary['total'];
			$severity = isset( $diagnostic['severity'] ) && is_scalar( $diagnostic['severity'] ) ? (string) $diagnostic['severity'] : 'warning';
			if ( ! array_key_exists( $severity, $summary ) ) {
				$severity = 'warning';
			}

			++$summary[ $severity ];
		}

		return $summary;
	}

	/**
	 * Build concise diagnostic summaries for a severity bucket.
	 *
	 * @param array<int,array<string,mixed>> $diagnostics Normalized diagnostics.
	 * @param string                         $severity    Severity to include.
	 * @return array<int,array<string,string>>
	 */
	private static function compact_import_report_diagnostic_summaries_by_severity( array $diagnostics, string $severity ): array {
		$summaries = array();
		foreach ( $diagnostics as $diagnostic ) {
			$diagnostic_severity = isset( $diagnostic['severity'] ) && is_scalar( $diagnostic['severity'] ) ? (string) $diagnostic['severity'] : 'warning';
			if ( $severity !== $diagnostic_severity ) {
				continue;
			}

			$summaries[] = array(
				'id'      => isset( $diagnostic['id'] ) && is_scalar( $diagnostic['id'] ) ? (string) $diagnostic['id'] : '',
				'type'    => isset( $diagnostic['type'] ) && is_scalar( $diagnostic['type'] ) ? (string) $diagnostic['type'] : 'static_site_importer_diagnostic',
				'source'  => self::diagnostic_summary_source( $diagnostic ),
				'message' => self::diagnostic_summary_message( $diagnostic ),
			);

			if ( count( $summaries ) >= 10 ) {
				break;
			}
		}

		return $summaries;
	}

	/**
	 * Resolve a concise diagnostic source label.
	 *
	 * @param array<string,mixed> $diagnostic Normalized diagnostic.
	 * @return string
	 */
	private static function diagnostic_summary_source( array $diagnostic ): string {
		foreach ( array( 'source_path', 'source' ) as $field ) {
			if ( isset( $diagnostic[ $field ] ) && is_scalar( $diagnostic[ $field ] ) ) {
				return (string) $diagnostic[ $field ];
			}
		}

		return '';
	}

	/**
	 * Resolve a concise diagnostic message.
	 *
	 * @param array<string,mixed> $diagnostic Normalized diagnostic.
	 * @return string
	 */
	private static function diagnostic_summary_message( array $diagnostic ): string {
		foreach ( array( 'message', 'reason', 'excerpt', 'error_message', 'html_excerpt' ) as $field ) {
			if ( isset( $diagnostic[ $field ] ) && is_scalar( $diagnostic[ $field ] ) && '' !== trim( (string) $diagnostic[ $field ] ) ) {
				return self::diagnostic_excerpt( (string) $diagnostic[ $field ] );
			}
		}

		return isset( $diagnostic['type'] ) && is_scalar( $diagnostic['type'] ) ? (string) $diagnostic['type'] : 'static_site_importer_diagnostic';
	}

	/**
	 * Keep summary diagnostics compact while preserving repair-agent evidence.
	 *
	 * @param array<int,array<string,mixed>> $diagnostics Normalized diagnostics.
	 * @return array<int,array<string,mixed>> Compact diagnostics for validation harnesses.
	 */
	private static function compact_import_report_diagnostics( array $diagnostics ): array {
		$fields = array(
			'id',
			'type',
			'severity',
			'category',
			'reason_code',
			'suggested_repair_class',
			'source_path',
			'source',
			'selector',
			'excerpt',
			'source_html_preview',
			'emitted_block_preview',
			'block_name',
			'block_path',
			'converter',
			'stage',
			'reason',
			'message',
			'tag_name',
			'html_excerpt',
			'context',
		);

		$compact = array();
		foreach ( array_slice( $diagnostics, 0, 50 ) as $diagnostic ) {
			$row = array();
			foreach ( $fields as $field ) {
				if ( ! array_key_exists( $field, $diagnostic ) || null === $diagnostic[ $field ] || '' === $diagnostic[ $field ] || array() === $diagnostic[ $field ] ) {
					continue;
				}

				$row[ $field ] = $diagnostic[ $field ];
			}

			if ( ! empty( $row ) ) {
				$compact[] = $row;
			}
		}

		return $compact;
	}

	/**
	 * Infer a compact CSS-like selector from an HTML preview.
	 *
	 * @param string $html Source HTML.
	 * @return string
	 */
	private static function diagnostic_selector_from_html( string $html ): string {
		if ( ! preg_match( '/<\s*([a-z0-9:-]+)\b([^>]*)>/i', $html, $match ) ) {
			return '';
		}

		$selector = strtolower( (string) $match[1] );
		$attrs    = (string) $match[2];
		if ( preg_match( '/\sid\s*=\s*(["\'])(.*?)\1/i', $attrs, $id_match ) ) {
			$id = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $id_match[2] );
			if ( is_string( $id ) && '' !== $id ) {
				$selector .= '#' . $id;
			}
		}

		if ( preg_match( '/\sclass\s*=\s*(["\'])(.*?)\1/i', $attrs, $class_match ) ) {
			$classes = preg_split( '/\s+/', trim( (string) $class_match[2] ) );
			if ( is_array( $classes ) ) {
				foreach ( array_slice( $classes, 0, 3 ) as $class ) {
					$class = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $class );
					if ( is_string( $class ) && '' !== $class ) {
						$selector .= '.' . $class;
					}
				}
			}
		}

		return $selector;
	}

	/**
	 * Infer the first element tag name from an HTML preview.
	 *
	 * @param string $html Source HTML.
	 * @return string|null
	 */
	private static function diagnostic_tag_name_from_html( string $html ): ?string {
		if ( ! preg_match( '/<\s*([a-z0-9:-]+)\b/i', $html, $match ) ) {
			return null;
		}

		return strtoupper( (string) $match[1] );
	}

	/**
	 * Build a compact diagnostic excerpt.
	 *
	 * @param string $html Source HTML.
	 * @return string
	 */
	private static function diagnostic_excerpt( string $html ): string {
		$excerpt = preg_replace( '/\s+/', ' ', trim( $html ) );
		$excerpt = is_string( $excerpt ) ? $excerpt : trim( $html );
		return substr( $excerpt, 0, 300 );
	}

	/**
	 * Build style.css.
	 *
	 * @param string $theme_name Theme name.
	 * @param string $css        Source CSS.
	 * @return string
	 */
	private static function style_css( string $theme_name, string $css, array $button_classes = array() ): string {
		$button_bridge              = self::button_style_bridge_css( $css, $button_classes );
		$admin_bar_bridge           = self::admin_bar_top_chrome_css( $css );
		$source_nav_selector_bridge = self::source_nav_selector_bridge_css( $css );
		$source_display_bridge      = self::source_display_selector_bridge_css( $css );
		$image_block_bridge         = self::source_image_block_selector_bridge_css( $css );
		$form_control_bridge        = self::source_form_control_selector_bridge_css( $css );
		$layout_gap_bridge          = self::imported_group_layout_gap_bridge_css();
		$css                        = self::scope_source_button_css( $css, $button_classes );

		return "/*\nTheme Name: " . $theme_name . "\nAuthor: Static Site Importer\nDescription: Materialized from a compiled website artifact.\nVersion: 0.1.0\nRequires at least: 6.6\n*/\n\n" . $css . "\n" . $button_bridge . $admin_bar_bridge . $source_nav_selector_bridge . $source_display_bridge . $image_block_bridge . $layout_gap_bridge . $form_control_bridge;
	}

	/**
	 * Build editor-style.css.
	 *
	 * @param string $css Source CSS.
	 * @return string
	 */
	private static function editor_style_css( string $css, array $button_classes = array() ): string {
		$button_bridge              = self::button_style_bridge_css( $css, $button_classes );
		$editor_bridge              = self::editor_absolute_overlay_css( $css );
		$editor_reveal_bridge       = self::editor_reveal_animation_css( $css );
		$source_nav_selector_bridge = self::source_nav_selector_bridge_css( $css );
		$source_display_bridge      = self::source_display_selector_bridge_css( $css );
		$image_block_bridge         = self::source_image_block_selector_bridge_css( $css );
		$form_control_bridge        = self::source_form_control_selector_bridge_css( $css );
		$layout_gap_bridge          = self::imported_group_layout_gap_bridge_css();
		$css                        = self::scope_source_button_css( $css, $button_classes );

		return "/*\nStatic Site Importer editor styles.\nGenerated separately from frontend style.css so editor wrapper repairs do not leak to public rendering.\n*/\n\n" . $css . "\n" . $button_bridge . $source_nav_selector_bridge . $source_display_bridge . $image_block_bridge . $layout_gap_bridge . $form_control_bridge . $editor_bridge . $editor_reveal_bridge;
	}

	/**
	 * Build layout-gap reset rules for imported source groups.
	 *
	 * Static HTML spacing is preserved by the imported source CSS. WordPress adds
	 * block layout gap/margins to generated group wrappers, which can stretch
	 * stacked hero and section groups beyond the source document height.
	 *
	 * @return string Additional CSS rules.
	 */
	private static function imported_group_layout_gap_bridge_css(): string {
		return "\n/* Static Site Importer: preserve source-authored spacing inside converted source wrappers. */\n.wp-block-post-content.is-layout-flow > *,\n.wp-block-group.is-layout-flow > *,\n.wp-block-group.is-vertical > * { margin-block-start: 0; margin-block-end: 0; }\n.wp-block-group.is-layout-flex,\n.wp-block-group.is-vertical { gap: 0; }\n";
	}

	/**
	 * Build selector parity rules for source classes converted to group blocks.
	 *
	 * WordPress layout classes can override source-authored rules on converted group
	 * wrappers. Re-emitting simple class rules with the block wrapper class keeps
	 * authored layouts intact, including media-query overrides, without hard-coded
	 * selectors.
	 *
	 * @param string $css Source CSS.
	 * @return string Additional CSS rules.
	 */
	private static function source_display_selector_bridge_css( string $css ): string {
		$css = preg_replace( '/\/\*.*?\*\//s', '', $css ) ?? $css;
		if ( '' === trim( $css ) ) {
			return '';
		}

		$rules = self::source_display_selector_bridge_rules_from_css( $css );
		if ( empty( $rules ) ) {
			return '';
		}

		return "\n/* Static Site Importer: preserve source class rules on converted group wrappers. */\n" . implode( "\n", array_unique( $rules ) ) . "\n";
	}

	/**
	 * Build source selector bridge rules from one CSS scope.
	 *
	 * @param string $css CSS to inspect.
	 * @return array<int,string> CSS rules.
	 */
	private static function source_display_selector_bridge_rules_from_css( string $css ): array {
		$rules  = array();
		$length = strlen( $css );
		$offset = 0;

		while ( $offset < $length && preg_match( '/\G\s*([^{}]+)\{/', $css, $match, 0, $offset ) ) {
			$prelude    = trim( $match[1] );
			$body_start = $offset + strlen( $match[0] );
			$depth      = 1;
			$position   = $body_start;
			while ( $position < $length && $depth > 0 ) {
				$char = $css[ $position ];
				if ( '{' === $char ) {
					++$depth;
				} elseif ( '}' === $char ) {
					--$depth;
				}

				++$position;
			}

			$body   = substr( $css, $body_start, max( 0, $position - $body_start - 1 ) );
			$offset = $position;

			if ( str_starts_with( $prelude, '@' ) ) {
				$nested = self::source_display_selector_bridge_rules_from_css( $body );
				if ( ! empty( $nested ) ) {
					$rules[] = $prelude . ' { ' . implode( ' ', $nested ) . ' }';
				}

				continue;
			}

			$selectors = array();
			foreach ( explode( ',', $prelude ) as $selector ) {
				$rewritten = self::source_display_selector_bridge_selector( trim( $selector ) );
				if ( null !== $rewritten ) {
					$selectors[] = $rewritten;
				}
			}

			if ( empty( $selectors ) ) {
				continue;
			}

			$rules[] = implode( ', ', array_unique( $selectors ) ) . ' {' . trim( $body ) . '}';
		}

		return $rules;
	}

	/**
	 * Rewrite one source selector for converted group display parity.
	 *
	 * @param string $selector Source selector.
	 * @return string|null Rewritten selector, or null when not applicable.
	 */
	private static function source_display_selector_bridge_selector( string $selector ): ?string {
		if ( '' === $selector || str_contains( $selector, '.wp-block-' ) || str_contains( $selector, '#' ) || str_contains( $selector, ':' ) || str_contains( $selector, '[' ) || str_contains( $selector, ' ' ) || str_contains( $selector, '>' ) || str_contains( $selector, '+' ) || str_contains( $selector, '~' ) ) {
			return null;
		}

		if ( ! preg_match( '/^(?:[a-z][a-z0-9_-]*)?((?:\.[a-z0-9_-]+)+)$/i', $selector, $match ) ) {
			return null;
		}

		return '.wp-block-group' . $match[1];
	}

	/**
	 * Build selector parity rules for source image grids converted to image blocks.
	 *
	 * @param string $css Source CSS.
	 * @return string Additional CSS rules.
	 */
	private static function source_image_block_selector_bridge_css( string $css ): string {
		$css = preg_replace( '/\/\*.*?\*\//s', '', $css ) ?? $css;
		if ( '' === trim( $css ) || ! str_contains( strtolower( $css ), 'img' ) ) {
			return '';
		}

		$rules = self::source_image_block_selector_bridge_rules_from_css( $css );
		if ( empty( $rules ) ) {
			return '';
		}

		return "\n/* Static Site Importer: preserve source image-grid selectors on native image blocks. */\n" . implode( "\n", array_unique( $rules ) ) . "\n";
	}

	/**
	 * Build image block selector bridge rules from one CSS scope.
	 *
	 * @param string $css CSS to inspect.
	 * @return array<int,string> CSS rules.
	 */
	private static function source_image_block_selector_bridge_rules_from_css( string $css ): array {
		$rules  = array();
		$length = strlen( $css );
		$offset = 0;

		while ( $offset < $length && preg_match( '/\G\s*([^{}]+)\{/', $css, $match, 0, $offset ) ) {
			$prelude    = trim( $match[1] );
			$body_start = $offset + strlen( $match[0] );
			$depth      = 1;
			$position   = $body_start;
			while ( $position < $length && $depth > 0 ) {
				$char = $css[ $position ];
				if ( '{' === $char ) {
					++$depth;
				} elseif ( '}' === $char ) {
					--$depth;
				}

				++$position;
			}

			$body   = substr( $css, $body_start, max( 0, $position - $body_start - 1 ) );
			$offset = $position;

			if ( str_starts_with( $prelude, '@' ) ) {
				$nested = self::source_image_block_selector_bridge_rules_from_css( $body );
				if ( ! empty( $nested ) ) {
					$rules[] = $prelude . ' { ' . implode( ' ', $nested ) . ' }';
				}

				continue;
			}

			$selectors = array();
			$resets    = array();
			foreach ( explode( ',', $prelude ) as $selector ) {
				$rewritten = self::source_image_block_selector_bridge_selector( trim( $selector ) );
				if ( empty( $rewritten ) ) {
					continue;
				}

				$selectors = array_merge( $selectors, $rewritten['selectors'] );
				$resets[]  = $rewritten['reset'];
			}

			if ( empty( $selectors ) ) {
				continue;
			}

			foreach ( array_unique( $resets ) as $reset ) {
				$rules[] = $reset . ' {margin:0}';
			}

			$rules[] = implode( ', ', array_unique( $selectors ) ) . ' {' . trim( $body ) . '}';
		}

		return $rules;
	}

	/**
	 * Rewrite one source image selector for native image block wrappers.
	 *
	 * @param string $selector Source selector.
	 * @return array{reset:string,selectors:array<int,string>}|null Rewritten selectors.
	 */
	private static function source_image_block_selector_bridge_selector( string $selector ): ?array {
		if ( '' === $selector || str_contains( $selector, '.wp-block-' ) ) {
			return null;
		}

		if ( ! preg_match( '/^((?:[a-z][a-z0-9_-]*)?(?:\.[a-z0-9_-]+)+)\s*>?\s*img((?::first-child|:not\(:first-child\))?)$/i', $selector, $match ) ) {
			return null;
		}

		$container = self::source_display_selector_bridge_selector( $match[1] );
		if ( null === $container ) {
			return null;
		}

		$pseudo = $match[2] ?? '';
		$figure = $container . ' > .wp-block-image' . $pseudo;
		return array(
			'reset'     => $container . ' > .wp-block-image',
			'selectors' => array( $figure, $figure . ' img' ),
		);
	}

	/**
	 * Build selector parity rules for source form controls converted to block surrogates.
	 *
	 * @param string $css Source CSS.
	 * @return string Additional CSS rules.
	 */
	private static function source_form_control_selector_bridge_css( string $css ): string {
		$css = preg_replace( '/\/\*.*?\*\//s', '', $css ) ?? $css;
		if ( '' === trim( $css ) || ! preg_match( '/\b(?:label|input|select|textarea)\b/i', $css ) ) {
			return '';
		}

		$rules = self::source_form_control_selector_bridge_rules_from_css( $css );
		if ( empty( $rules ) ) {
			return '';
		}

		return "\n/* Static Site Importer: preserve source form-control selectors on native static form surrogates. */\n" . implode( "\n", array_unique( $rules ) ) . "\n";
	}

	/**
	 * Build source form-control selector bridge rules from one CSS scope.
	 *
	 * @param string $css CSS to inspect.
	 * @return array<int,string> CSS rules.
	 */
	private static function source_form_control_selector_bridge_rules_from_css( string $css ): array {
		$rules  = array();
		$length = strlen( $css );
		$offset = 0;

		while ( $offset < $length && preg_match( '/\G\s*([^{}]+)\{/', $css, $match, 0, $offset ) ) {
			$prelude    = trim( $match[1] );
			$body_start = $offset + strlen( $match[0] );
			$body_end   = self::find_css_block_end( $css, $body_start );
			if ( null === $body_end ) {
				break;
			}

			$body   = trim( substr( $css, $body_start, $body_end - $body_start ) );
			$offset = $body_end + 1;

			if ( str_starts_with( $prelude, '@' ) ) {
				$nested = self::source_form_control_selector_bridge_rules_from_css( $body );
				foreach ( $nested as $rule ) {
					$rules[] = $prelude . ' { ' . $rule . ' }';
				}
				continue;
			}

			$selectors = array();
			foreach ( explode( ',', $prelude ) as $selector ) {
				$rewritten = self::source_form_control_selector_bridge_selector( trim( $selector ) );
				if ( null !== $rewritten ) {
					$selectors[] = $rewritten;
				}
			}

			if ( empty( $selectors ) ) {
				continue;
			}

			$rules[] = implode( ', ', array_unique( $selectors ) ) . ' {' . $body . '}';
		}

		return $rules;
	}

	/**
	 * Rewrite a source form-control selector for static form surrogates.
	 *
	 * @param string $selector Source selector.
	 * @return string|null Rewritten selector, or null when not applicable.
	 */
	private static function source_form_control_selector_bridge_selector( string $selector ): ?string {
		if ( '' === $selector || str_contains( $selector, '.wp-block-' ) ) {
			return null;
		}

		$rewritten = preg_replace_callback(
			'/(^|[\s>+~,(])(?:label|input|select|textarea)(?=($|[\s>+~),.#:\[]))/i',
			static function ( array $match ): string {
				$token = strtolower( $match[0] );
				$lead  = $match[1];
				$tag   = trim( substr( $token, strlen( $lead ) ) );

				switch ( $tag ) {
					case 'label':
						$replacement = '.static-form-field';
						break;
					case 'input':
						$replacement = '.static-form-control.static-form-input';
						break;
					case 'select':
						$replacement = '.static-form-control.static-form-select';
						break;
					case 'textarea':
						$replacement = '.static-form-control.static-form-textarea';
						break;
					default:
						$replacement = $tag;
				}

				return $lead . $replacement;
			},
			$selector
		);

		if ( ! is_string( $rewritten ) || $rewritten === $selector ) {
			return null;
		}

		return $rewritten;
	}

	/**
	 * Build selector parity rules for source nav wrappers converted to group blocks.
	 *
	 * @param string $css Source CSS.
	 * @return string Additional CSS rules.
	 */
	private static function source_nav_selector_bridge_css( string $css ): string {
		$css = preg_replace( '/\/\*.*?\*\//s', '', $css ) ?? $css;
		if ( '' === trim( $css ) || ! str_contains( strtolower( $css ), 'nav' ) ) {
			return '';
		}

		$rules = self::source_nav_selector_bridge_rules_from_css( $css );
		if ( empty( $rules ) ) {
			return '';
		}

		return "\n/* Static Site Importer: preserve source nav wrapper selectors on converted navigation groups. */\n" . implode( "\n", array_unique( $rules ) ) . "\n";
	}

	/**
	 * Build source nav selector bridge rules from one CSS scope.
	 *
	 * @param string $css CSS to inspect.
	 * @return array<int, string> CSS rules.
	 */
	private static function source_nav_selector_bridge_rules_from_css( string $css ): array {
		$rules  = array();
		$length = strlen( $css );
		$offset = 0;

		while ( $offset < $length && preg_match( '/\G\s*([^{}]+)\{/', $css, $match, 0, $offset ) ) {
			$prelude    = trim( $match[1] );
			$body_start = $offset + strlen( $match[0] );
			$body_end   = self::find_css_block_end( $css, $body_start );
			if ( null === $body_end ) {
				break;
			}

			$body   = trim( substr( $css, $body_start, $body_end - $body_start ) );
			$offset = $body_end + 1;

			if ( str_starts_with( $prelude, '@' ) ) {
				$nested = self::source_nav_selector_bridge_rules_from_css( $body );
				foreach ( $nested as $rule ) {
					$rules[] = $prelude . ' { ' . $rule . ' }';
				}
				continue;
			}

			$selectors = array();
			foreach ( explode( ',', $prelude ) as $selector ) {
				$rewritten = self::source_nav_selector_bridge_selector( trim( $selector ) );
				if ( null !== $rewritten ) {
					$selectors[] = $rewritten;
				}
			}

			if ( empty( $selectors ) ) {
				continue;
			}

			$rules[] = implode( ', ', array_unique( $selectors ) ) . ' { ' . $body . ' }';
		}

		return $rules;
	}

	/**
	 * Rewrite a selector so source nav wrappers match converted group wrappers.
	 *
	 * @param string $selector CSS selector.
	 * @return string|null Rewritten selector, or null when no safe nav token exists.
	 */
	private static function source_nav_selector_bridge_selector( string $selector ): ?string {
		if ( '' === $selector || str_starts_with( $selector, '@' ) ) {
			return null;
		}

		$rewritten = preg_replace( '/(^|[\s>+~])nav(?=($|[\s>+~.#:\[]))/', '$1.static-site-importer-source-nav', $selector );
		if ( ! is_string( $rewritten ) || $rewritten === $selector ) {
			return null;
		}

		return $rewritten;
	}

	/**
	 * Build frontend admin-bar offsets for imported fixed/sticky top chrome.
	 *
	 * WordPress adds body.admin-bar but does not offset arbitrary imported fixed
	 * headers. Only selectors that already declare fixed/sticky positioning,
	 * a top value, and header/nav-like naming receive generated offsets.
	 *
	 * @param string $css Source CSS.
	 * @return string Additional frontend CSS rules.
	 */
	private static function admin_bar_top_chrome_css( string $css ): string {
		$css = preg_replace( '/\/\*.*?\*\//s', '', $css ) ?? $css;
		if ( '' === trim( $css ) || ! str_contains( $css, 'position' ) || ! str_contains( $css, 'top' ) ) {
			return '';
		}

		$rules = self::admin_bar_top_chrome_rules_from_css( $css );
		if ( empty( $rules ) ) {
			return '';
		}

		return "\n/* Static Site Importer: offset imported fixed/sticky top chrome below the WordPress admin bar. */\n" . implode( "\n", array_unique( $rules ) ) . "\n";
	}

	/**
	 * Build admin-bar offset rules from one CSS scope.
	 *
	 * @param string $css CSS to inspect.
	 * @return array<int, string> CSS rules.
	 */
	private static function admin_bar_top_chrome_rules_from_css( string $css ): array {
		$rules  = array();
		$length = strlen( $css );
		$offset = 0;

		while ( $offset < $length && preg_match( '/\G\s*([^{}]+)\{/', $css, $match, 0, $offset ) ) {
			$prelude    = trim( $match[1] );
			$body_start = $offset + strlen( $match[0] );
			$body_end   = self::find_css_block_end( $css, $body_start );
			if ( null === $body_end ) {
				break;
			}

			$body   = trim( substr( $css, $body_start, $body_end - $body_start ) );
			$offset = $body_end + 1;

			if ( str_starts_with( $prelude, '@' ) ) {
				$rules = array_merge( $rules, self::admin_bar_top_chrome_rules_from_css( $body ) );
				continue;
			}

			if ( ! preg_match( '/(?:^|;)\s*position\s*:\s*(?:fixed|sticky)\s*(?:!important\s*)?(?:;|$)/i', $body ) ) {
				continue;
			}

			$top = self::css_declaration_value( $body, 'top' );
			if ( null === $top ) {
				continue;
			}

			$desktop_top = self::admin_bar_offset_top_value( $top, '32px' );
			$mobile_top  = self::admin_bar_offset_top_value( $top, '46px' );
			if ( null === $desktop_top || null === $mobile_top ) {
				continue;
			}

			$selectors = array();
			foreach ( explode( ',', $prelude ) as $selector ) {
				$selector = trim( $selector );
				if ( self::selector_is_plausible_top_chrome( $selector ) ) {
					$source_nav_selector = self::source_nav_selector_bridge_selector( $selector );
					$selectors[]         = 'body.admin-bar ' . ( $source_nav_selector ?? $selector );
				}
			}

			if ( empty( $selectors ) ) {
				continue;
			}

			$selector_list = implode( ', ', array_unique( $selectors ) );
			$rules[]       = $selector_list . ' { top: ' . $desktop_top . '; }';
			$rules[]       = '@media screen and (max-width: 782px) { ' . $selector_list . ' { top: ' . $mobile_top . '; } }';
		}

		return $rules;
	}

	/**
	 * Extract one CSS declaration value from a rule body.
	 *
	 * @param string $body     CSS declaration body.
	 * @param string $property Property name.
	 * @return string|null Declaration value.
	 */
	private static function css_declaration_value( string $body, string $property ): ?string {
		if ( ! preg_match( '/(?:^|;)\s*' . preg_quote( $property, '/' ) . '\s*:\s*([^;]+)\s*(?:;|$)/i', $body, $match ) ) {
			return null;
		}

		return trim( $match[1] );
	}

	/**
	 * Add one WordPress admin-bar height to a source top value.
	 *
	 * @param string $top    Source top declaration value.
	 * @param string $offset Admin-bar height.
	 * @return string|null Offset top value, or null when unsafe to rewrite.
	 */
	private static function admin_bar_offset_top_value( string $top, string $offset ): ?string {
		$top       = trim( $top );
		$important = '';
		if ( preg_match( '/\s*!important\s*$/i', $top ) ) {
			$important = ' !important';
			$top       = trim( preg_replace( '/\s*!important\s*$/i', '', $top ) ?? $top );
		}

		if ( '' === $top || preg_match( '/[;{}]/', $top ) || preg_match( '/^(?:auto|inherit|initial|revert|unset)$/i', $top ) || str_starts_with( $top, '-' ) ) {
			return null;
		}

		if ( preg_match( '/^0(?:[a-z%]+)?$/i', $top ) ) {
			return $offset . $important;
		}

		return 'calc(' . $top . ' + ' . $offset . ')' . $important;
	}

	/**
	 * Determine whether a selector plausibly targets imported top chrome.
	 *
	 * @param string $selector CSS selector.
	 * @return bool Whether the selector is narrow enough for admin-bar offsets.
	 */
	private static function selector_is_plausible_top_chrome( string $selector ): bool {
		$selector = trim( strtolower( $selector ) );
		if ( '' === $selector || str_starts_with( $selector, '@' ) || preg_match( '/(?:footer|bottom|modal|dialog|popup|overlay|sidebar|drawer)/', $selector ) ) {
			return false;
		}

		return (bool) preg_match( '/(?:header|masthead|nav|navbar|navigation|topbar|app-bar|toolbar|fixed-top|sticky-top)/', $selector );
	}

	/**
	 * Build editor-only wrapper normalization for imported absolute overlay blocks.
	 *
	 * The Site Editor inserts block-list wrappers between imported section/group blocks
	 * and their children. When a source child is absolutely positioned, that extra
	 * wrapper can become the visible stack item instead of the imported child.
	 *
	 * @param string $css Source CSS.
	 * @return string Additional editor-only CSS rules.
	 */
	private static function editor_absolute_overlay_css( string $css ): string {
		$classes = self::absolute_position_classes_from_css( $css );
		if ( empty( $classes ) ) {
			return '';
		}

		$selectors = array();
		foreach ( $classes as $class ) {
			if ( preg_match( '/^[A-Za-z_-][A-Za-z0-9_-]*$/', $class ) ) {
				$selectors[] = '.editor-styles-wrapper .block-editor-block-list__layout > .wp-block:has(> .' . $class . ')';
			}
		}

		if ( empty( $selectors ) ) {
			return '';
		}

		$selectors[] = '.editor-styles-wrapper .block-editor-block-list__layout > .wp-block:has(> .wp-block-group.static-site-importer-decorative-layer)';
		$selectors[] = '.editor-styles-wrapper .block-editor-block-list__layout > .wp-block.wp-block-group.static-site-importer-decorative-layer';

		$group_selector    = '.editor-styles-wrapper .wp-block-group.static-site-importer-decorative-layer';
		$placeholder_rules = array(
			$group_selector . ' .block-editor-block-variation-picker',
			$group_selector . ' .components-placeholder',
			$group_selector . ' .block-list-appender',
			$group_selector . ' .block-editor-button-block-appender',
		);

		$css  = "\n/* Static Site Importer: let Site Editor wrappers preserve imported absolute overlay stacking. */\n" . implode( ', ', array_unique( $selectors ) ) . ' { display: contents; }' . "\n";
		$css .= "\n/* Static Site Importer: hide empty decorative layer group controls in the Site Editor. */\n" . implode( ', ', array_unique( $placeholder_rules ) ) . ' { display: none; }' . "\n";

		return $css;
	}

	/**
	 * Build editor-only visibility resets for JS-driven reveal animation starts.
	 *
	 * Source sites often initialize reveal targets with opacity:0 plus a transform,
	 * then use frontend JavaScript to animate them into place. The editor canvas does
	 * not run that frontend script, so scope the neutralization to editor styles.
	 *
	 * @param string $css Source CSS.
	 * @return string Additional editor-only CSS rules.
	 */
	private static function editor_reveal_animation_css( string $css ): string {
		$classes = self::reveal_animation_classes_from_css( $css );
		if ( empty( $classes ) ) {
			return '';
		}

		$selectors = array();
		foreach ( $classes as $class ) {
			if ( preg_match( '/^[A-Za-z_-][A-Za-z0-9_-]*$/', $class ) ) {
				$selectors[] = '.editor-styles-wrapper .' . $class;
			}
		}

		if ( empty( $selectors ) ) {
			return '';
		}

		return "\n/* Static Site Importer: show JS-revealed animation content in editor canvases. */\n" . implode( ', ', array_unique( $selectors ) ) . ' { opacity: 1 !important; transform: none !important; }' . "\n";
	}

	/**
	 * Collect terminal selector classes from rules declaring hidden transform reveals.
	 *
	 * @param string $css Source CSS.
	 * @return array<int, string> Class names.
	 */
	private static function reveal_animation_classes_from_css( string $css ): array {
		$css       = preg_replace( '/\/\*.*?\*\//s', '', $css ) ?? $css;
		$lower_css = strtolower( $css );
		if ( '' === trim( $css ) || ! str_contains( $lower_css, 'opacity' ) || ! str_contains( $lower_css, 'transform' ) || ! str_contains( $css, '.' ) ) {
			return array();
		}

		$classes = self::reveal_animation_classes_from_css_scope( $css );
		sort( $classes, SORT_STRING );

		return $classes;
	}

	/**
	 * Collect hidden transform reveal classes inside one CSS block list.
	 *
	 * @param string $css CSS to inspect.
	 * @return array<int, string> Class names.
	 */
	private static function reveal_animation_classes_from_css_scope( string $css ): array {
		$classes = array();
		$length  = strlen( $css );
		$offset  = 0;

		while ( $offset < $length && preg_match( '/\G\s*([^{}]+)\{/', $css, $match, 0, $offset ) ) {
			$prelude    = trim( $match[1] );
			$body_start = $offset + strlen( $match[0] );
			$body_end   = self::find_css_block_end( $css, $body_start );
			if ( null === $body_end ) {
				break;
			}

			$body   = substr( $css, $body_start, $body_end - $body_start );
			$offset = $body_end + 1;

			if ( str_starts_with( $prelude, '@' ) ) {
				$classes = array_merge( $classes, self::reveal_animation_classes_from_css_scope( $body ) );
				continue;
			}

			$opacity   = self::css_declaration_value( $body, 'opacity' );
			$transform = self::css_declaration_value( $body, 'transform' );
			if ( ! self::css_opacity_is_zero( $opacity ) || null === $transform || preg_match( '/^none\s*(?:!important\s*)?$/i', $transform ) ) {
				continue;
			}

			foreach ( explode( ',', $prelude ) as $selector ) {
				$classes = array_merge( $classes, self::selector_terminal_classes( trim( $selector ) ) );
			}
		}

		return array_values( array_unique( $classes ) );
	}

	/**
	 * Determine whether an opacity declaration hides the element.
	 *
	 * @param string|null $opacity Opacity declaration value.
	 * @return bool Whether the value is zero.
	 */
	private static function css_opacity_is_zero( ?string $opacity ): bool {
		if ( null === $opacity ) {
			return false;
		}

		$opacity = trim( preg_replace( '/\s*!important\s*$/i', '', $opacity ) ?? $opacity );
		return (bool) preg_match( '/^0(?:\.0+)?%?$/', $opacity );
	}

	/**
	 * Collect terminal selector classes from rules declaring position:absolute.
	 *
	 * @param string $css Source CSS.
	 * @return array<int, string> Class names.
	 */
	private static function absolute_position_classes_from_css( string $css ): array {
		$css = preg_replace( '/\/\*.*?\*\//s', '', $css ) ?? $css;
		if ( '' === trim( $css ) || ! str_contains( $css, 'position' ) || ! str_contains( $css, '.' ) ) {
			return array();
		}

		$classes = self::absolute_position_classes_from_css_scope( $css );
		sort( $classes, SORT_STRING );

		return $classes;
	}

	/**
	 * Collect absolute-position classes inside one CSS block list.
	 *
	 * @param string $css CSS to inspect.
	 * @return array<int, string> Class names.
	 */
	private static function absolute_position_classes_from_css_scope( string $css ): array {
		$classes = array();
		$length  = strlen( $css );
		$offset  = 0;

		while ( $offset < $length && preg_match( '/\G\s*([^{}]+)\{/', $css, $match, 0, $offset ) ) {
			$prelude    = trim( $match[1] );
			$body_start = $offset + strlen( $match[0] );
			$body_end   = self::find_css_block_end( $css, $body_start );
			if ( null === $body_end ) {
				break;
			}

			$body   = substr( $css, $body_start, $body_end - $body_start );
			$offset = $body_end + 1;

			if ( str_starts_with( $prelude, '@' ) ) {
				$classes = array_merge( $classes, self::absolute_position_classes_from_css_scope( $body ) );
				continue;
			}

			if ( ! preg_match( '/(?:^|;)\s*position\s*:\s*absolute\s*(?:;|$)/i', $body ) ) {
				continue;
			}

			foreach ( explode( ',', $prelude ) as $selector ) {
				$classes = array_merge( $classes, self::selector_terminal_classes( trim( $selector ) ) );
			}
		}

		return array_values( array_unique( $classes ) );
	}

	/**
	 * Extract classes from the final compound selector that identifies the styled element.
	 *
	 * @param string $selector CSS selector.
	 * @return array<int, string> Class names.
	 */
	private static function selector_terminal_classes( string $selector ): array {
		if ( '' === $selector || ! preg_match( '/([^\s>+~]+)(?::[A-Za-z_-][A-Za-z0-9_-]*(?:\([^)]*\))?)*\s*$/', $selector, $selector_match ) ) {
			return array();
		}

		if ( ! preg_match_all( '/\.([A-Za-z_-][A-Za-z0-9_-]*)/', $selector_match[1], $class_matches ) ) {
			return array();
		}

		return array_values( array_unique( $class_matches[1] ) );
	}

	/**
	 * Keep copied source button selectors from restyling generated core/button wrappers.
	 *
	 * @param string             $css            Source CSS.
	 * @param array<int, string> $button_classes Classes observed on generated core/button wrappers.
	 * @return string Scoped source CSS.
	 */
	private static function scope_source_button_css( string $css, array $button_classes ): string {
		$button_classes = array_fill_keys( array_filter( array_map( 'strval', $button_classes ) ), true );
		if ( '' === trim( $css ) || ! str_contains( $css, '.' ) || empty( $button_classes ) ) {
			return $css;
		}

		return self::scope_source_button_css_scope( $css, $button_classes );
	}

	/**
	 * Scope selectors inside one CSS block list, preserving nested media/supports scopes.
	 *
	 * @param string              $css            CSS to rewrite.
	 * @param array<string, true> $button_classes Classes observed on generated core/button wrappers.
	 * @return string Rewritten CSS.
	 */
	private static function scope_source_button_css_scope( string $css, array $button_classes ): string {
		$rewritten = '';
		$length    = strlen( $css );
		$offset    = 0;

		while ( $offset < $length && preg_match( '/\G(\s*)([^{}]+)\{/', $css, $match, 0, $offset ) ) {
			$leading    = $match[1];
			$prelude    = trim( $match[2] );
			$body_start = $offset + strlen( $match[0] );
			$body_end   = self::find_css_block_end( $css, $body_start );
			if ( null === $body_end ) {
				break;
			}

			$body   = substr( $css, $body_start, $body_end - $body_start );
			$offset = $body_end + 1;

			if ( str_starts_with( $prelude, '@' ) ) {
				$body = self::scope_source_button_css_scope( $body, $button_classes );
			} else {
				$selectors = array();
				foreach ( explode( ',', $prelude ) as $selector ) {
					$selectors[] = self::source_button_selector_without_wrapper_match( trim( $selector ), $button_classes ) ?? trim( $selector );
				}

				$prelude = implode( ', ', $selectors );
			}

			$rewritten .= $leading . $prelude . ' {' . $body . '}';
		}

		return $rewritten . substr( $css, $offset );
	}

	/**
	 * Build CSS bridge rules for source anchor classes moved onto core/button wrappers.
	 *
	 * @param string             $css            Source CSS.
	 * @param array<int, string> $button_classes Classes observed on generated core/button wrappers.
	 * @return string Additional CSS rules.
	 */
	private static function button_style_bridge_css( string $css, array $button_classes ): string {
		$css            = preg_replace( '/\/\*.*?\*\//s', '', $css ) ?? $css;
		$button_classes = array_fill_keys( array_filter( array_map( 'strval', $button_classes ) ), true );
		if ( '' === trim( $css ) || ! str_contains( $css, '.' ) || empty( $button_classes ) ) {
			return '';
		}

		$rules = array_merge(
			self::button_style_bridge_reset_rules( $button_classes ),
			self::button_style_bridge_rules_from_css( $css, $button_classes )
		);

		if ( ! $rules ) {
			return '';
		}

		return "\n/* Static Site Importer: preserve source anchor styles on core/button links. */\n" . implode( "\n", array_unique( $rules ) ) . "\n";
	}

	/**
	 * Build reset rules that prevent WordPress button defaults from leaking into source-converted buttons.
	 *
	 * @param array<string, true> $button_classes Classes observed on generated core/button wrappers.
	 * @return array<int, string>
	 */
	private static function button_style_bridge_reset_rules( array $button_classes ): array {
		$selectors = array();
		foreach ( array_keys( $button_classes ) as $class ) {
			if ( preg_match( '/^[A-Za-z_-][A-Za-z0-9_-]*$/', $class ) ) {
				$selectors[] = '.wp-block-button.' . $class . ' > .wp-block-button__link:where(.wp-element-button)';
			}
		}

		if ( empty( $selectors ) ) {
			return array();
		}

		return array(
			implode( ', ', $selectors ) . ' { background: transparent; border: 0; border-radius: 0; box-shadow: none; color: inherit; display: inline; font: inherit; height: auto; line-height: inherit; max-width: none; min-width: 0; padding: 0; text-decoration: inherit; width: auto; }',
		);
	}

	/**
	 * Build bridge rules from one CSS scope, preserving nested media/supports scopes.
	 *
	 * @param string              $css            CSS to inspect.
	 * @param array<string, true> $button_classes Classes observed on generated core/button wrappers.
	 * @return array<int, string>
	 */
	private static function button_style_bridge_rules_from_css( string $css, array $button_classes ): array {
		$rules  = array();
		$length = strlen( $css );
		$offset = 0;

		while ( $offset < $length && preg_match( '/\G\s*([^{}]+)\{/', $css, $match, 0, $offset ) ) {
			$prelude    = trim( $match[1] );
			$body_start = $offset + strlen( $match[0] );
			$body_end   = self::find_css_block_end( $css, $body_start );
			if ( null === $body_end ) {
				break;
			}

			$body   = trim( substr( $css, $body_start, $body_end - $body_start ) );
			$offset = $body_end + 1;

			if ( '' === $prelude || '' === $body ) {
				continue;
			}

			if ( str_starts_with( $prelude, '@' ) ) {
				$nested_rules = self::button_style_bridge_rules_from_css( $body, $button_classes );
				if ( $nested_rules ) {
					$rules[] = $prelude . " {\n" . implode( "\n", $nested_rules ) . "\n}";
				}
				continue;
			}

			$link_selectors    = array();
			$wrapper_selectors = array();
			foreach ( explode( ',', $prelude ) as $selector ) {
				$bridge_selector = self::button_style_bridge_selector( trim( $selector ), $button_classes );
				if ( null !== $bridge_selector ) {
					$link_selectors[] = $bridge_selector;
				}

				$wrapper_selector = self::button_wrapper_layout_bridge_selector( trim( $selector ), $button_classes );
				if ( null !== $wrapper_selector ) {
					$wrapper_selectors[] = $wrapper_selector;
				}
			}

			if ( $link_selectors ) {
				$rules[] = implode( ', ', array_unique( $link_selectors ) ) . ' { ' . $body . ' }';
			}

			$layout_body = self::button_wrapper_layout_declarations( $body );
			if ( $wrapper_selectors && '' !== $layout_body ) {
				$rules[] = implode( ', ', array_unique( $wrapper_selectors ) ) . ' { ' . $layout_body . ' }';
			}
		}

		return $rules;
	}

	/**
	 * Find the matching closing brace for a CSS block body.
	 *
	 * @param string $css        CSS text.
	 * @param int    $body_start Offset immediately after the opening brace.
	 * @return int|null Offset of the matching closing brace.
	 */
	private static function find_css_block_end( string $css, int $body_start ): ?int {
		$depth  = 1;
		$length = strlen( $css );
		for ( $index = $body_start; $index < $length; $index++ ) {
			if ( '{' === $css[ $index ] ) {
				++$depth;
			} elseif ( '}' === $css[ $index ] ) {
				--$depth;
				if ( 0 === $depth ) {
					return $index;
				}
			}
		}

		return null;
	}

	/**
	 * Rewrite one source selector to target a generated core/button inner link.
	 *
	 * @param string              $selector       Source selector.
	 * @param array<string, true> $button_classes Classes observed on generated core/button wrappers.
	 * @return string|null Bridge selector, or null when selector does not target a known button class.
	 */
	private static function button_style_bridge_selector( string $selector, array $button_classes ): ?string {
		if ( '' === $selector || ! preg_match( '/^(.*?)([^\s>+~]+)$/', $selector, $selector_match ) ) {
			return null;
		}

		$prefix = $selector_match[1];
		$target = $selector_match[2];
		if ( ! preg_match( '/^(?:(a|button))?((?:\.[A-Za-z_-][A-Za-z0-9_-]*)+)((?::[A-Za-z_-][A-Za-z0-9_-]*(?:\([^)]*\))?)*)$/i', $target, $target_match ) ) {
			return null;
		}

		$classes = array();
		foreach ( explode( '.', ltrim( $target_match[2], '.' ) ) as $class ) {
			if ( isset( $button_classes[ $class ] ) ) {
				$classes[] = $class;
			}
		}

		if ( empty( $classes ) ) {
			return null;
		}

		return $prefix . '.wp-block-button.' . implode( '.', $classes ) . ' > .wp-block-button__link' . $target_match[3];
	}

	/**
	 * Rewrite one source selector to target a generated core/button wrapper for layout declarations.
	 *
	 * @param string              $selector       Source selector.
	 * @param array<string, true> $button_classes Classes observed on generated core/button wrappers.
	 * @return string|null Bridge selector, or null when selector does not target a known button class.
	 */
	private static function button_wrapper_layout_bridge_selector( string $selector, array $button_classes ): ?string {
		if ( '' === $selector || ! preg_match( '/^(.*?)([^\s>+~]+)$/', $selector, $selector_match ) ) {
			return null;
		}

		$prefix = $selector_match[1];
		$target = $selector_match[2];
		if ( ! preg_match( '/^(?:(a|button))?((?:\.[A-Za-z_-][A-Za-z0-9_-]*)+)(?::[A-Za-z_-][A-Za-z0-9_-]*(?:\([^)]*\))?)*$/i', $target, $target_match ) ) {
			return null;
		}

		$classes = array();
		foreach ( explode( '.', ltrim( $target_match[2], '.' ) ) as $class ) {
			if ( isset( $button_classes[ $class ] ) ) {
				$classes[] = $class;
			}
		}

		if ( empty( $classes ) ) {
			return null;
		}

		return $prefix . '.wp-block-button.' . implode( '.', $classes );
	}

	/**
	 * Keep layout-affecting declarations on the core/button wrapper.
	 *
	 * @param string $body CSS declaration body.
	 * @return string Filtered declaration body.
	 */
	private static function button_wrapper_layout_declarations( string $body ): string {
		$declarations = array();
		foreach ( explode( ';', $body ) as $declaration ) {
			$declaration = trim( $declaration );
			if ( '' === $declaration || ! str_contains( $declaration, ':' ) ) {
				continue;
			}

			list( $property ) = explode( ':', $declaration, 2 );
			$property         = strtolower( trim( $property ) );
			if ( preg_match( '/^(?:width|min-width|max-width|flex(?:-.+)?|margin(?:-.+)?|align-self|justify-self|place-self|order)$/', $property ) ) {
				$declarations[] = $declaration;
			}
		}

		return implode( ';', $declarations );
	}

	/**
	 * Exclude generated core/button wrappers from a copied source selector.
	 *
	 * @param string              $selector       Source selector.
	 * @param array<string, true> $button_classes Classes observed on generated core/button wrappers.
	 * @return string|null Rewritten selector, or null when the selector cannot match a wrapper.
	 */
	private static function source_button_selector_without_wrapper_match( string $selector, array $button_classes ): ?string {
		if ( '' === $selector || str_contains( $selector, '.wp-block-button' ) || ! preg_match( '/^(.*?)([^\s>+~]+)$/', $selector, $selector_match ) ) {
			return null;
		}

		$target = $selector_match[2];
		if ( ! preg_match( '/^([A-Za-z][A-Za-z0-9_-]*)?((?:\.[A-Za-z_-][A-Za-z0-9_-]*)+)((?::[A-Za-z_-][A-Za-z0-9_-]*(?:\([^)]*\))?)*)$/i', $target, $target_match ) ) {
			return null;
		}

		$tag_name = strtolower( $target_match[1] );
		if ( in_array( $tag_name, array( 'a', 'button' ), true ) ) {
			return null;
		}

		foreach ( explode( '.', ltrim( $target_match[2], '.' ) ) as $class ) {
			if ( isset( $button_classes[ $class ] ) ) {
				return $selector_match[1] . $target_match[1] . $target_match[2] . ':not(.wp-block-button)' . $target_match[3];
			}
		}

		return null;
	}

	/**
	 * Build functions.php.
	 *
	 * @param string $theme_slug Theme slug.
	 * @return string
	 */
	private static function functions_php( string $theme_slug ): string {
		$style_handle  = sanitize_key( $theme_slug ) . '-style';
		$editor_handle = sanitize_key( $theme_slug ) . '-editor-style';
		$script_handle = sanitize_key( $theme_slug ) . '-site';

		return "<?php\n" .
			"/**\n" .
			" * Generated theme bootstrap.\n" .
			" */\n\n" .
			"add_action( 'after_setup_theme', static function (): void {\n" .
			"\tadd_theme_support( 'editor-styles' );\n" .
			"\tadd_editor_style( 'assets/css/editor-style.css' );\n" .
			"} );\n\n" .
			"add_action( 'wp_enqueue_scripts', static function (): void {\n" .
			"\twp_enqueue_style( '" . $style_handle . "', get_stylesheet_uri(), array(), wp_get_theme()->get( 'Version' ) );\n" .
			"\tif ( file_exists( get_template_directory() . '/assets/site.js' ) ) {\n" .
			"\t\twp_enqueue_script( '" . $script_handle . "', get_template_directory_uri() . '/assets/site.js', array(), wp_get_theme()->get( 'Version' ), true );\n" .
			"\t}\n" .
			"} );\n\n" .
			"add_action( 'enqueue_block_editor_assets', static function (): void {\n" .
			"\twp_enqueue_style( '" . $editor_handle . "', get_template_directory_uri() . '/assets/css/editor-style.css', array(), wp_get_theme()->get( 'Version' ) );\n" .
			"} );\n";
	}

	/**
	 * Build theme.json.
	 *
	 * @param string $theme_name Theme name.
	 * @param string $css        Source CSS.
	 * @return string
	 */
	private static function theme_json( string $theme_name, string $css ): string {
		$data = array(
			'$schema'  => 'https://schemas.wp.org/trunk/theme.json',
			'version'  => 3,
			'title'    => $theme_name,
			'settings' => array(
				'layout' => array(
					'contentSize' => '760px',
					'wideSize'    => '1200px',
				),
			),
		);

		$design_tokens = self::design_tokens_from_css( $css );
		if ( ! empty( $design_tokens['palette'] ) ) {
			$data['settings']['color']['palette'] = $design_tokens['palette'];
		}

		$data['styles']['spacing']['blockGap'] = '0';

		if ( ! empty( $design_tokens['styles'] ) ) {
			$data['styles']['color'] = $design_tokens['styles'];
		}

		return wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
	}

	/**
	 * Extract conservative design tokens from obvious :root custom properties.
	 *
	 * @param string $css Source CSS.
	 * @return array{palette:array<int,array{slug:string,name:string,color:string}>,styles:array<string,string>}
	 */
	private static function design_tokens_from_css( string $css ): array {
		$palette = array();
		$styles  = array();
		$seen    = array();

		if ( '' === trim( $css ) || ! preg_match_all( '/:root\s*\{([^}]*)\}/i', $css, $root_matches ) ) {
			return array(
				'palette' => $palette,
				'styles'  => $styles,
			);
		}

		foreach ( $root_matches[1] as $root_body ) {
			$root_body = (string) preg_replace( '/\/\*.*?\*\//s', '', $root_body );
			if ( ! preg_match_all( '/--([A-Za-z0-9_-]+)\s*:\s*([^;{}]+);/', $root_body, $property_matches, PREG_SET_ORDER ) ) {
				continue;
			}

			foreach ( $property_matches as $property_match ) {
				$token_name = strtolower( $property_match[1] );
				$color      = trim( $property_match[2] );
				$slug       = sanitize_title( $token_name );

				if ( '' === $slug || isset( $seen[ $slug ] ) || ! self::is_safe_color_value( $color ) ) {
					continue;
				}

				$seen[ $slug ] = true;
				$palette[]     = array(
					'slug'  => $slug,
					'name'  => ucwords( str_replace( array( '-', '_' ), ' ', $token_name ) ),
					'color' => $color,
				);

				if ( ! isset( $styles['background'] ) && in_array( $slug, array( 'bg', 'background' ), true ) ) {
					$styles['background'] = 'var(--wp--preset--color--' . $slug . ')';
				}

				if ( ! isset( $styles['text'] ) && in_array( $slug, array( 'fg', 'foreground', 'text' ), true ) ) {
					$styles['text'] = 'var(--wp--preset--color--' . $slug . ')';
				}
			}
		}

		return array(
			'palette' => $palette,
			'styles'  => $styles,
		);
	}

	/**
	 * Check whether a CSS value is safe to expose as a theme palette color.
	 *
	 * @param string $value CSS value.
	 * @return bool
	 */
	private static function is_safe_color_value( string $value ): bool {
		$value = trim( $value );

		if ( preg_match( '/^#(?:[0-9a-f]{3}|[0-9a-f]{4}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $value ) ) {
			return true;
		}

		return (bool) preg_match( '/^(?:rgb|rgba|hsl|hsla)\(\s*[-+0-9.%\s,\/]+\s*\)$/i', $value );
	}

	/**
	 * Build a template that renders imported page content.
	 *
	 * @param string $background_blocks Background decoration blocks.
	 * @param bool   $has_footer_part   Whether a shared footer template part was generated.
	 * @return string
	 */
	private static function content_template( string $background_blocks, bool $has_footer_part ): string {
		$footer_part = $has_footer_part ? '<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->' : '';

		return trim(
			'<!-- wp:template-part {"slug":"header","tagName":"header"} /-->' . "\n\n" .
			$background_blocks . "\n\n" .
			'<!-- wp:post-content {"tagName":"main"} /-->' . "\n\n" .
			$footer_part
		) . "\n";
	}

	/**
	 * Build a theme pattern file for an imported page body.
	 *
	 * @param string $title        Pattern title.
	 * @param string $pattern_slug Pattern slug.
	 * @param string $content      Block markup.
	 * @return string
	 */
	private static function pattern_file( string $title, string $pattern_slug, string $content ): string {
		return "<?php\n" .
			"/**\n" .
			' * Title: ' . $title . "\n" .
			' * Slug: ' . $pattern_slug . "\n" .
			" * Categories: static-site-importer\n" .
			" */\n" .
			"?>\n" .
			trim( $content ) . "\n";
	}
}

<?php
/**
 * Block theme generator.
 *
 * @package StaticSiteImporter
 */

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
	 * Materialized inline SVG assets keyed by SVG content hash.
	 *
	 * @var array<string, array<string, string>>
	 */
	private static array $materialized_svg_assets = array();

	/**
	 * Classes observed on generated core/button wrappers during this import.
	 *
	 * @var array<string, true>
	 */
	private static array $button_wrapper_classes = array();

	/**
	 * CSS classes that identify absolute-positioned imported visual layers.
	 *
	 * @var array<string, true>
	 */
	private static array $decorative_empty_group_classes = array();

	/**
	 * Import an HTML file as a block theme.
	 *
	 * @param string $html_path  HTML file path.
	 * @param array  $args       Import args.
	 * @return array{theme_slug:string,theme_name:string,theme_dir:string,report_path:string,external_report_path:string,source_dir:string,source_deleted:bool,source_cleanup_error:string,pages:array<string,int>,quality:array<string,mixed>}|WP_Error
	 */
	public static function import_theme( string $html_path, array $args = array() ) {
		if ( ! function_exists( 'bfb_convert' ) ) {
			return new WP_Error( 'static_site_importer_missing_bfb', 'Block Format Bridge is required to import a static site.' );
		}

		$document = Static_Site_Importer_Document::from_file( $html_path );
		if ( is_wp_error( $document ) ) {
			return $document;
		}

		$theme_name = isset( $args['name'] ) && '' !== trim( (string) $args['name'] ) ? sanitize_text_field( (string) $args['name'] ) : $document->title();
		$theme_slug = isset( $args['slug'] ) && '' !== trim( (string) $args['slug'] ) ? sanitize_title( (string) $args['slug'] ) : sanitize_title( $theme_name );
		if ( '' === $theme_slug ) {
			$theme_slug = 'imported-static-site';
		}

		$theme_root = get_theme_root();
		$theme_dir  = trailingslashit( $theme_root ) . $theme_slug;

		if ( file_exists( $theme_dir ) && empty( $args['overwrite'] ) ) {
			return new WP_Error( 'static_site_importer_theme_exists', sprintf( 'Theme already exists: %s', $theme_slug ) );
		}

		$result = self::ensure_dirs( $theme_dir );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$site_dir = dirname( $html_path );
		$pages    = self::collect_pages( $site_dir );
		if ( empty( $pages ) ) {
			$pages = array(
				basename( $html_path ) => array(
					'path'     => $html_path,
					'document' => $document,
				),
			);
		}

		$page_ids = self::create_page_shells( $pages );
		if ( is_wp_error( $page_ids ) ) {
			return $page_ids;
		}

		$permalinks              = self::page_permalinks( $page_ids );
		$fragments               = $document->fragments();
		self::$conversion_report = self::new_conversion_report( $html_path );

		self::$active_theme_dir        = $theme_dir;
		self::$active_theme_uri        = trailingslashit( get_theme_root_uri( $theme_slug ) ) . $theme_slug;
		self::$materialized_svg_assets = array();
		self::$button_wrapper_classes  = array();

		$site_css                             = self::site_css( $site_dir, $document );
		self::$decorative_empty_group_classes = array_fill_keys(
			self::absolute_position_classes_from_css( $site_css ),
			true
		);

		$background_blocks = self::convert_fragment( self::rewrite_internal_links( $fragments['background'], $permalinks ), 'background:index.html' );
		$header_blocks     = self::convert_header_fragment( self::strip_active_classes( self::rewrite_internal_links( $fragments['header'], $permalinks ) ), $theme_slug );
		$has_footer_part   = '' !== trim( $fragments['footer'] );
		$footer_blocks     = $has_footer_part ? self::convert_footer_fragment( self::rewrite_internal_links( $fragments['footer'], $permalinks ), $theme_slug ) : '';

		$page_artifacts = self::page_artifacts( $pages, $permalinks, $theme_slug );

		$result = self::write_page_shell_contents( $pages, $page_ids );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$writes = array(
			$theme_dir . '/style.css'                 => self::style_css( $theme_name, $site_css, array_keys( self::$button_wrapper_classes ) ),
			$theme_dir . '/functions.php'             => self::functions_php( $theme_slug ),
			$theme_dir . '/theme.json'                => self::theme_json( $theme_name, $site_css ),
			$theme_dir . '/parts/header.html'         => $header_blocks,
			$theme_dir . '/templates/front-page.html' => self::page_pattern_template( $background_blocks, $page_artifacts['patterns']['index.html'] ?? '', $has_footer_part ),
			$theme_dir . '/templates/page.html'       => self::content_template( $background_blocks, $has_footer_part ),
			$theme_dir . '/templates/index.html'      => self::content_template( $background_blocks, $has_footer_part ),
		);
		if ( $has_footer_part ) {
			$writes[ $theme_dir . '/parts/footer.html' ] = $footer_blocks;
		}

		foreach ( $page_artifacts['patterns'] as $filename => $pattern_slug ) {
			$slug = self::page_slug( $filename );
			if ( '' === $pattern_slug || '' === $slug ) {
				continue;
			}

			$writes[ $theme_dir . '/templates/page-' . $slug . '.html' ] = self::page_pattern_template( $background_blocks, $pattern_slug, $has_footer_part );
			$writes[ $theme_dir . '/patterns/page-' . $slug . '.php' ]   = $page_artifacts['files'][ $filename ] ?? '';
		}

		$inline_js = $document->inline_js();
		if ( '' !== $inline_js ) {
			$writes[ $theme_dir . '/assets/site.js' ] = $inline_js;
		}

		self::analyze_generated_theme_block_documents( $writes, $theme_dir );
		self::record_visual_fidelity_targets( $pages, $page_ids, $permalinks, $writes, $theme_dir );
		$quality     = self::finalize_quality_report( $args );
		$report_json = wp_json_encode( self::$conversion_report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		if ( false === $report_json ) {
			return new WP_Error( 'static_site_importer_report_encode_failed', 'Failed to encode import report JSON.' );
		}

		if ( ! $has_footer_part && file_exists( $theme_dir . '/parts/footer.html' ) && ! wp_delete_file( $theme_dir . '/parts/footer.html' ) ) {
			return new WP_Error( 'static_site_importer_stale_footer_delete_failed', 'Failed to remove stale footer template part.' );
		}

		$writes[ $theme_dir . '/import-report.json' ] = $report_json . "\n";

		foreach ( $writes as $path => $content ) {
			$result = self::write_file( $path, $content );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		$external_report_path = '';
		if ( isset( $args['report'] ) && '' !== trim( (string) $args['report'] ) ) {
			$external_report_path = (string) $args['report'];
			$result               = self::write_external_report( $external_report_path, $report_json . "\n" );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		if ( ! empty( $args['activate'] ) ) {
			switch_theme( $theme_slug );

			if ( isset( $page_ids['index.html'] ) ) {
				update_option( 'show_on_front', 'page' );
				update_option( 'page_on_front', $page_ids['index.html'] );
			}
		}

		$source_deleted       = false;
		$source_cleanup_error = '';
		if ( empty( $args['keep_source'] ) ) {
			if ( ! empty( $quality['pass'] ) ) {
				$cleanup_result = self::delete_source_dir( $site_dir, $html_path );
				if ( is_wp_error( $cleanup_result ) ) {
					$source_cleanup_error = $cleanup_result->get_error_message();
				} else {
					$source_deleted = true;
				}
			} else {
				$source_cleanup_error = 'import quality checks reported issues';
			}
		}

		return array(
			'theme_slug'           => $theme_slug,
			'theme_name'           => $theme_name,
			'theme_dir'            => $theme_dir,
			'report_path'          => $theme_dir . '/import-report.json',
			'external_report_path' => $external_report_path,
			'source_dir'           => $site_dir,
			'source_deleted'       => $source_deleted,
			'source_cleanup_error' => $source_cleanup_error,
			'pages'                => $page_ids,
			'quality'              => $quality,
		);
	}

	/**
	 * Collect sibling HTML pages from a static site directory.
	 *
	 * @param string $site_dir Site directory.
	 * @return array<string, array{path:string,document:Static_Site_Importer_Document}>
	 */
	private static function collect_pages( string $site_dir ): array {
		$pages = array();
		$paths = glob( trailingslashit( $site_dir ) . '*.html' );
		foreach ( false === $paths ? array() : $paths as $path ) {
			$document = Static_Site_Importer_Document::from_file( $path );
			if ( is_wp_error( $document ) ) {
				continue;
			}

			$pages[ basename( $path ) ] = array(
				'path'     => $path,
				'document' => $document,
			);
		}

		uksort(
			$pages,
			static function ( string $left, string $right ): int {
				if ( 'index.html' === $left ) {
					return -1;
				}
				if ( 'index.html' === $right ) {
					return 1;
				}

				return strnatcasecmp( $left, $right );
			}
		);

		return $pages;
	}

	/**
	 * Create page shells so links can be rewritten before content conversion.
	 *
	 * @param array<string, array{path:string,document:Static_Site_Importer_Document}> $pages Pages.
	 * @return array<string,int>|WP_Error
	 */
	private static function create_page_shells( array $pages ) {
		$page_ids = array();
		foreach ( $pages as $filename => $page ) {
			$title = self::page_title( $filename, $page['document'] );
			$slug  = self::page_slug( $filename );

			$existing = get_page_by_path( $slug, OBJECT, 'page' );
			$postarr  = array(
				'post_title'   => $title,
				'post_name'    => $slug,
				'post_status'  => 'publish',
				'post_type'    => 'page',
				'post_content' => self::page_shell_content(),
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
	 * @param array<string, array{path:string,document:Static_Site_Importer_Document}> $pages      Pages.
	 * @param array<string,string>                                                      $permalinks Permalinks keyed by filename.
	 * @param string                                                                    $theme_slug Theme slug.
	 * @return array{patterns:array<string,string>,files:array<string,string>}
	 */
	private static function page_artifacts( array $pages, array $permalinks, string $theme_slug ): array {
		$patterns = array();
		$files    = array();

		foreach ( $pages as $filename => $page ) {
			$slug         = self::page_slug( $filename );
			$pattern_slug = sanitize_key( $theme_slug ) . '/page-' . $slug;
			$fragments    = $page['document']->fragments();
			$content      = self::convert_fragment( self::rewrite_internal_links( $fragments['main'], $permalinks ), 'main:' . $filename );

			$patterns[ $filename ] = $pattern_slug;
			$files[ $filename ]    = self::pattern_file( self::page_title( $filename, $page['document'] ), $pattern_slug, $content );
		}

		return array(
			'patterns' => $patterns,
			'files'    => $files,
		);
	}

	/**
	 * Keep imported pages as routing/editing shells; their visible layout lives in page templates.
	 *
	 * @param array<string, array{path:string,document:Static_Site_Importer_Document}> $pages    Pages.
	 * @param array<string,int>                                                         $page_ids Page IDs keyed by filename.
	 * @return true|WP_Error
	 */
	private static function write_page_shell_contents( array $pages, array $page_ids ) {
		foreach ( array_keys( $pages ) as $filename ) {
			$page_id = $page_ids[ $filename ] ?? 0;

			$result = wp_update_post(
				array(
					'ID'           => $page_id,
					'post_content' => self::page_shell_content(),
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
			}
		}

		return $permalinks;
	}

	/**
	 * Rewrite local .html links to imported WordPress page permalinks.
	 *
	 * @param string               $html       HTML fragment.
	 * @param array<string,string> $permalinks Permalinks keyed by filename.
	 * @return string
	 */
	private static function rewrite_internal_links( string $html, array $permalinks ): string {
		if ( '' === trim( $html ) || empty( $permalinks ) ) {
			return $html;
		}

		return preg_replace_callback(
			'/\bhref=("|\')([^"\']+)(\1)/i',
			static function ( array $matches ) use ( $permalinks ): string {
				$href               = html_entity_decode( $matches[2], ENT_QUOTES );
				$parts              = explode( '#', $href, 2 );
				$path_without_query = strtok( $parts[0], '?' );
				$filename           = basename( false === $path_without_query ? $parts[0] : $path_without_query );
				if ( ! isset( $permalinks[ $filename ] ) ) {
					return $matches[0];
				}

				$replacement = $permalinks[ $filename ];
				if ( isset( $parts[1] ) && '' !== $parts[1] ) {
					$replacement .= '#' . $parts[1];
				}

				return 'href=' . $matches[1] . esc_url( $replacement ) . $matches[3];
			},
			$html
		) ?? $html;
	}

	/**
	 * Strip static active classes from shared chrome before every page reuses it.
	 *
	 * @param string $html HTML fragment.
	 * @return string
	 */
	private static function strip_active_classes( string $html ): string {
		if ( '' === trim( $html ) || ! str_contains( $html, 'active' ) ) {
			return $html;
		}

		return preg_replace_callback(
			'/\sclass=("|\')([^"\']*)(\1)/i',
			static function ( array $matches ): string {
				$classes = preg_split( '/\s+/', trim( $matches[2] ) );
				$classes = false === $classes ? array() : $classes;
				$classes = array_values(
					array_filter(
						$classes,
						static fn ( string $class_name ): bool => 'active' !== $class_name
					)
				);

				if ( empty( $classes ) ) {
					return '';
				}

				return ' class=' . $matches[1] . implode( ' ', $classes ) . $matches[3];
			},
			$html
		) ?? $html;
	}

	/**
	 * Convert shared header chrome while preserving navigation as an editable entity.
	 *
	 * @param string $html       Header HTML fragment.
	 * @param string $theme_slug Imported theme slug.
	 * @return string
	 */
	private static function convert_header_fragment( string $html, string $theme_slug ): string {
		$html   = self::materialize_inline_svg_icons( $html, 'theme-part:header' );
		$doc    = self::load_fragment_document( $html );
		$header = self::sole_child_element( $doc );
		$root   = $doc->documentElement;
		if ( ! $header instanceof DOMElement && $root instanceof DOMElement ) {
			$children = self::direct_element_children( $root );
			if ( count( $children ) > 1 ) {
				return implode(
					'',
					array_map(
						static fn ( DOMElement $child ): string => self::theme_part_element_block( $doc, $child, $theme_slug, 'header' ),
						$children
					)
				);
			}
		}

		if ( $header instanceof DOMElement && 'nav' === strtolower( $header->tagName ) ) {
			return self::theme_part_element_block( $doc, $header, $theme_slug, 'header' );
		}
		if ( ! $header instanceof DOMElement || 'header' !== strtolower( $header->tagName ) ) {
			return self::convert_fragment( $html, 'theme-part:header' );
		}

		$header_children = self::direct_element_children( $header );
		if ( 1 !== count( $header_children ) || 'div' !== strtolower( $header_children[0]->tagName ) ) {
			return self::theme_part_element_block( $doc, $header, $theme_slug, 'header' );
		}

		$inner          = $header_children[0];
		$inner_children = self::direct_element_children( $inner );
		if ( 2 !== count( $inner_children ) || 'a' !== strtolower( $inner_children[0]->tagName ) || 'nav' !== strtolower( $inner_children[1]->tagName ) ) {
			return self::theme_part_element_block( $doc, $header, $theme_slug, 'header' );
		}

		$navigation_blocks = self::navigation_ref_block( $inner_children[1], $theme_slug, 'header' );
		if ( null === $navigation_blocks ) {
			return self::theme_part_element_block( $doc, $header, $theme_slug, 'header' );
		}

		$inner_blocks = self::html_block( self::node_html( $doc, $inner_children[0] ) ) . $navigation_blocks;
		return self::group_block( self::group_block( $inner_blocks, $inner->getAttribute( 'class' ) ), $header->getAttribute( 'class' ), 'header' );
	}

	/**
	 * Convert shared footer chrome while preserving navigation as an editable entity.
	 *
	 * @param string $html       Footer HTML fragment.
	 * @param string $theme_slug Imported theme slug.
	 * @return string
	 */
	private static function convert_footer_fragment( string $html, string $theme_slug ): string {
		$html   = self::materialize_inline_svg_icons( $html, 'theme-part:footer' );
		$doc    = self::load_fragment_document( $html );
		$footer = self::sole_child_element( $doc );
		if ( ! $footer instanceof DOMElement || 'footer' !== strtolower( $footer->tagName ) ) {
			return self::convert_fragment( $html, 'theme-part:footer' );
		}

		$footer_children = self::direct_element_children( $footer );
		if ( 1 !== count( $footer_children ) || 'div' !== strtolower( $footer_children[0]->tagName ) ) {
			return self::theme_part_element_block( $doc, $footer, $theme_slug, 'footer' );
		}

		$container          = $footer_children[0];
		$container_children = self::direct_element_children( $container );
		if ( 1 !== count( $container_children ) || 'div' !== strtolower( $container_children[0]->tagName ) ) {
			return self::theme_part_element_block( $doc, $footer, $theme_slug, 'footer' );
		}

		$row          = $container_children[0];
		$row_children = self::direct_element_children( $row );
		if ( 2 !== count( $row_children ) || 'div' !== strtolower( $row_children[0]->tagName ) || 'ul' !== strtolower( $row_children[1]->tagName ) ) {
			return self::theme_part_element_block( $doc, $footer, $theme_slug, 'footer' );
		}

		$navigation_blocks = self::navigation_ref_block( $row_children[1], $theme_slug, 'footer' );
		if ( null === $navigation_blocks ) {
			return self::theme_part_element_block( $doc, $footer, $theme_slug, 'footer' );
		}

		$row_blocks       = self::html_block( self::node_html( $doc, $row_children[0] ) ) . $navigation_blocks;
		$container_blocks = self::group_block( $row_blocks, $row->getAttribute( 'class' ) );
		return self::group_block( self::group_block( $container_blocks, $container->getAttribute( 'class' ) ), $footer->getAttribute( 'class' ), 'footer' );
	}

	/**
	 * Build editable-enough block markup for shared theme chrome without delegating the whole part to core/html.
	 *
	 * @param DOMDocument $doc        Source DOM document.
	 * @param DOMElement  $element    Source element.
	 * @param string      $theme_slug Imported theme slug.
	 * @param string      $location   Theme part location.
	 * @return string
	 */
	private static function theme_part_element_block( DOMDocument $doc, DOMElement $element, string $theme_slug, string $location ): string {
		$tag = strtolower( $element->tagName );
		if ( self::can_convert_element_to_navigation( $element ) ) {
			$navigation = self::navigation_ref_block( $element, $theme_slug, $location );
			if ( null !== $navigation ) {
				return self::group_block( $navigation, $element->getAttribute( 'class' ), 'nav' === $tag ? 'nav' : 'div' );
			}
		}

		if ( 'a' === $tag ) {
			return self::link_element_block( $doc, $element );
		}

		$children = self::theme_part_child_blocks( $doc, $element, $theme_slug, $location );
		if ( '' === trim( $children ) ) {
			$text = trim( $element->textContent );
			if ( '' !== $text ) {
				return self::paragraph_block( esc_html( $text ), $element->getAttribute( 'class' ) );
			}

			return self::html_block( self::node_html( $doc, $element ) );
		}

		$wrapper_tag = in_array( $tag, array( 'header', 'footer', 'nav' ), true ) ? $tag : 'div';
		return self::group_block( $children, $element->getAttribute( 'class' ), $wrapper_tag );
	}

	/**
	 * Convert direct child nodes for a shared theme part.
	 *
	 * @param DOMDocument $doc        Source DOM document.
	 * @param DOMElement  $element    Source element.
	 * @param string      $theme_slug Imported theme slug.
	 * @param string      $location   Theme part location.
	 * @return string
	 */
	private static function theme_part_child_blocks( DOMDocument $doc, DOMElement $element, string $theme_slug, string $location ): string {
		$blocks = array();
		foreach ( $element->childNodes as $child ) {
			if ( $child instanceof DOMText && '' !== trim( $child->textContent ) ) {
				$blocks[] = self::paragraph_block( esc_html( trim( $child->textContent ) ) );
				continue;
			}

			if ( ! $child instanceof DOMElement ) {
				continue;
			}

			if ( self::element_contains_svg_or_form( $child ) ) {
				$blocks[] = self::html_block( self::node_html( $doc, $child ) );
				continue;
			}

			$blocks[] = self::theme_part_element_block( $doc, $child, $theme_slug, $location );
		}

		return implode( '', array_filter( $blocks ) );
	}

	/**
	 * Check whether direct text exists outside child elements.
	 *
	 * @param DOMElement $element Source element.
	 * @return bool
	 */
	private static function element_has_direct_non_whitespace_text( DOMElement $element ): bool {
		foreach ( $element->childNodes as $child ) {
			if ( $child instanceof DOMText && '' !== trim( $child->textContent ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check whether an element is pure navigation rather than branded chrome containing links.
	 *
	 * @param DOMElement $element Source element.
	 * @return bool
	 */
	private static function can_convert_element_to_navigation( DOMElement $element ): bool {
		$tag = strtolower( $element->tagName );
		if ( ! in_array( $tag, array( 'nav', 'ul', 'ol' ), true ) || self::element_has_direct_non_whitespace_text( $element ) ) {
			return false;
		}

		foreach ( self::direct_element_children( $element ) as $child ) {
			$child_tag = strtolower( $child->tagName );
			if ( 'nav' === $tag && in_array( $child_tag, array( 'a', 'ul', 'ol' ), true ) ) {
				continue;
			}

			if ( in_array( $tag, array( 'ul', 'ol' ), true ) && 'li' === $child_tag ) {
				continue;
			}

			return false;
		}

		return true;
	}

	/**
	 * Detect decorative or interactive markup better preserved as raw HTML inside a smaller island.
	 *
	 * @param DOMElement $element Source element.
	 * @return bool
	 */
	private static function element_contains_svg_or_form( DOMElement $element ): bool {
		foreach ( array( 'svg', 'canvas', 'form', 'input', 'button', 'select', 'textarea' ) as $tag ) {
			if ( $element->getElementsByTagName( $tag )->length > 0 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Convert an anchor in shared chrome to a button block when it visually acts like a CTA.
	 *
	 * @param DOMDocument $doc     Source DOM document.
	 * @param DOMElement  $element Anchor element.
	 * @return string
	 */
	private static function link_element_block( DOMDocument $doc, DOMElement $element ): string {
		$href  = trim( $element->getAttribute( 'href' ) );
		$label = trim( $element->textContent );
		if ( '' === $href || '' === $label ) {
			return self::html_block( self::node_html( $doc, $element ) );
		}

		$class = trim( $element->getAttribute( 'class' ) );
		if ( preg_match( '/(^|[-_\s])(btn|button|cta|pill)([-_\s]|$)/i', $class ) ) {
			$attrs = array( 'url' => esc_url_raw( $href ) );
			if ( '' !== $class ) {
				$attrs['className'] = $class;
				self::record_button_wrapper_classes( $class );
			}

			return '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button ' . wp_json_encode( $attrs, JSON_UNESCAPED_SLASHES ) . ' --><div class="wp-block-button ' . esc_attr( $class ) . '"><a class="wp-block-button__link wp-element-button" href="' . esc_url( $href ) . '">' . esc_html( $label ) . '</a></div><!-- /wp:button --></div><!-- /wp:buttons -->';
		}

		return self::paragraph_block( '<a href="' . esc_url( $href ) . '"' . ( '' !== $class ? ' class="' . esc_attr( $class ) . '"' : '' ) . '>' . esc_html( $label ) . '</a>' );
	}

	/**
	 * Build a reference to a deterministic wp_navigation entity.
	 *
	 * @param DOMElement $element    Navigation source element.
	 * @param string     $theme_slug Imported theme slug.
	 * @param string     $location   Navigation location name.
	 * @return string|null
	 */
	private static function navigation_ref_block( DOMElement $element, string $theme_slug, string $location ): ?string {
		$links = self::navigation_links_from_element( $element );
		if ( empty( $links ) ) {
			return null;
		}

		$navigation_id = self::upsert_navigation_post( $theme_slug, $location, $links );
		if ( is_wp_error( $navigation_id ) ) {
			return null;
		}

		$attrs = array( 'ref' => (int) $navigation_id );
		$class = trim( $element->getAttribute( 'class' ) );
		if ( '' !== $class ) {
			$attrs['className'] = $class;
		}

		return '<!-- wp:navigation ' . wp_json_encode( $attrs, JSON_UNESCAPED_SLASHES ) . ' /-->';
	}

	/**
	 * Extract top-level navigation links from a nav or list element.
	 *
	 * @param DOMElement $element Navigation source element.
	 * @return array<int, array{label:string,url:string,className?:string}>
	 */
	private static function navigation_links_from_element( DOMElement $element ): array {
		$links = array();
		foreach ( $element->getElementsByTagName( 'a' ) as $anchor ) {
			if ( '' === trim( $anchor->getAttribute( 'href' ) ) ) {
				continue;
			}

			$link  = array(
				'label' => trim( $anchor->textContent ),
				'url'   => esc_url_raw( $anchor->getAttribute( 'href' ) ),
			);
			$class = trim( $anchor->getAttribute( 'class' ) );
			if ( '' !== $class ) {
				$link['className'] = $class;
			}

			if ( '' !== $link['label'] && '' !== $link['url'] ) {
				$links[] = $link;
			}
		}

		return $links;
	}

	/**
	 * Create or update a deterministic wp_navigation post.
	 *
	 * @param string                                                        $theme_slug Imported theme slug.
	 * @param string                                                        $location   Navigation location name.
	 * @param array<int, array{label:string,url:string,className?:string}> $links      Navigation links.
	 * @return int|WP_Error
	 */
	private static function upsert_navigation_post( string $theme_slug, string $location, array $links ) {
		if ( ! post_type_exists( 'wp_navigation' ) ) {
			return new WP_Error( 'static_site_importer_missing_navigation_post_type', 'The wp_navigation post type is not available.' );
		}

		$slug     = sanitize_title( $theme_slug . '-' . $location . '-navigation' );
		$existing = get_page_by_path( $slug, OBJECT, 'wp_navigation' );
		$postarr  = array(
			'post_title'   => ucwords( str_replace( '-', ' ', $theme_slug ) ) . ' ' . ucfirst( $location ) . ' Navigation',
			'post_name'    => $slug,
			'post_status'  => 'publish',
			'post_type'    => 'wp_navigation',
			'post_content' => self::navigation_post_content( $links ),
		);

		if ( $existing instanceof WP_Post ) {
			$postarr['ID'] = $existing->ID;
		}

		return wp_insert_post( $postarr, true );
	}

	/**
	 * Build wp_navigation entity content from link data.
	 *
	 * @param array<int, array{label:string,url:string,className?:string}> $links Navigation links.
	 * @return string
	 */
	private static function navigation_post_content( array $links ): string {
		$blocks = array();
		foreach ( $links as $link ) {
			$attrs = array(
				'label'          => $link['label'],
				'url'            => $link['url'],
				'kind'           => 'custom',
				'isTopLevelLink' => true,
			);
			if ( ! empty( $link['className'] ) ) {
				$attrs['className'] = $link['className'];
			}

			$blocks[] = '<!-- wp:navigation-link ' . wp_json_encode( $attrs, JSON_UNESCAPED_SLASHES ) . ' /-->';
		}

		return implode( "\n", $blocks );
	}

	/**
	 * Build a group block wrapper.
	 *
	 * @param string $inner     Inner block markup.
	 * @param string $class_name Source class attribute.
	 * @param string $tag_name   Wrapper tag name.
	 * @return string
	 */
	private static function group_block( string $inner, string $class_name = '', string $tag_name = 'div' ): string {
		$class_name = trim( $class_name );
		$tag_name   = strtolower( $tag_name );
		$attrs      = array();
		if ( '' !== $class_name ) {
			$attrs['className'] = $class_name;
		}
		if ( 'div' !== $tag_name ) {
			$attrs['tagName'] = $tag_name;
		}

		$comment_attrs = empty( $attrs ) ? '' : ' ' . wp_json_encode( $attrs, JSON_UNESCAPED_SLASHES );
		$class_attr    = trim( 'wp-block-group ' . $class_name );

		return '<!-- wp:group' . $comment_attrs . ' --><' . $tag_name . ' class="' . esc_attr( $class_attr ) . '">' . $inner . '</' . $tag_name . '><!-- /wp:group -->';
	}

	/**
	 * Build an HTML block.
	 *
	 * @param string $html Raw HTML.
	 * @return string
	 */
	private static function html_block( string $html ): string {
		return '<!-- wp:html -->' . $html . '<!-- /wp:html -->';
	}

	/**
	 * Build a paragraph block.
	 *
	 * @param string $inner_html  Paragraph inner HTML.
	 * @param string $class_name  Source class attribute.
	 * @return string
	 */
	private static function paragraph_block( string $inner_html, string $class_name = '' ): string {
		$class_name = trim( $class_name );
		$attrs      = '' === $class_name ? '' : ' ' . wp_json_encode( array( 'className' => $class_name ), JSON_UNESCAPED_SLASHES );
		$class_attr = '' === $class_name ? '' : ' class="' . esc_attr( $class_name ) . '"';

		return '<!-- wp:paragraph' . $attrs . ' --><p' . $class_attr . '>' . $inner_html . '</p><!-- /wp:paragraph -->';
	}

	/**
	 * Parse an HTML fragment into a wrapper document.
	 *
	 * @param string $html HTML fragment.
	 * @return DOMDocument
	 */
	private static function load_fragment_document( string $html ): DOMDocument {
		$doc      = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="UTF-8"><div data-static-site-importer-root="1">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return $doc;
	}

	/**
	 * Get the only direct child element from a wrapped fragment document.
	 *
	 * @param DOMDocument $doc DOM document.
	 * @return DOMElement|null
	 */
	private static function sole_child_element( DOMDocument $doc ): ?DOMElement {
		$root = $doc->documentElement;
		if ( ! $root instanceof DOMElement ) {
			return null;
		}

		$children = self::direct_element_children( $root );
		return 1 === count( $children ) ? $children[0] : null;
	}

	/**
	 * Get direct element children.
	 *
	 * @param DOMElement $element Element.
	 * @return array<int, DOMElement>
	 */
	private static function direct_element_children( DOMElement $element ): array {
		$children = array();
		foreach ( $element->childNodes as $child ) {
			if ( $child instanceof DOMElement ) {
				$children[] = $child;
			}
		}

		return $children;
	}

	/**
	 * Serialize a DOM element.
	 *
	 * @param DOMDocument $doc  DOM document.
	 * @param DOMElement  $node Element.
	 * @return string
	 */
	private static function node_html( DOMDocument $doc, DOMElement $node ): string {
		$html = $doc->saveHTML( $node );
		return false === $html ? '' : $html;
	}

	/**
	 * Build a WordPress page title from a source document.
	 *
	 * @param string                        $filename Source filename.
	 * @param Static_Site_Importer_Document $document Source document.
	 * @return string
	 */
	private static function page_title( string $filename, Static_Site_Importer_Document $document ): string {
		if ( 'index.html' === $filename ) {
			return 'Home';
		}

		$title = preg_replace( '/\s+[—-]\s+.+$/u', '', $document->title() );
		return '' === trim( (string) $title ) ? ucwords( str_replace( '-', ' ', self::page_slug( $filename ) ) ) : trim( (string) $title );
	}

	/**
	 * Build a WordPress page slug from a source filename.
	 *
	 * @param string $filename Source filename.
	 * @return string
	 */
	private static function page_slug( string $filename ): string {
		if ( 'index.html' === $filename ) {
			return 'home';
		}

		return sanitize_title( preg_replace( '/\.html?$/i', '', $filename ) );
	}

	/**
	 * Collect inline and linked local CSS.
	 *
	 * @param string                        $site_dir Site directory.
	 * @param Static_Site_Importer_Document $document Source document.
	 * @return string
	 */
	private static function site_css( string $site_dir, Static_Site_Importer_Document $document ): string {
		$css           = array();
		$real_site_dir = realpath( $site_dir );
		$real_site_dir = false === $real_site_dir ? $site_dir : $real_site_dir;
		foreach ( $document->stylesheet_hrefs() as $href ) {
			$href_path = strtok( $href, '?' );
			$path      = realpath( trailingslashit( $site_dir ) . ltrim( false === $href_path ? $href : $href_path, '/' ) );
			if ( false === $path || ! str_starts_with( $path, $real_site_dir ) || ! is_readable( $path ) ) {
				continue;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads local static-site stylesheet files from the import directory.
			$contents = file_get_contents( $path );
			if ( false !== $contents ) {
				$css[] = trim( $contents );
			}
		}

		$inline = $document->inline_css();
		if ( '' !== $inline ) {
			$css[] = $inline;
		}

		return trim( implode( "\n\n", array_filter( $css ) ) );
	}

	/**
	 * Materialize safe inline SVG elements as generated theme assets.
	 *
	 * @param string $html   HTML fragment.
	 * @param string $source Source fragment label.
	 * @return string
	 */
	private static function materialize_inline_svg_icons( string $html, string $source ): string {
		if ( '' === trim( $html ) || ! str_contains( strtolower( $html ), '<svg' ) || '' === self::$active_theme_dir ) {
			return $html;
		}

		$doc      = self::load_fragment_document( $html );
		$svgs     = iterator_to_array( $doc->getElementsByTagName( 'svg' ) );
		$changed  = false;
		$sequence = 0;

		foreach ( $svgs as $svg ) {
			++$sequence;
			$svg_html = self::node_html( $doc, $svg );
			$safe_svg = self::sanitize_inline_svg( $svg_html );
			if ( null === $safe_svg ) {
				self::record_unsafe_inline_svg( $source, $svg_html );
				continue;
			}

			$asset = self::write_svg_icon_asset( $safe_svg, $source, $sequence );
			if ( is_wp_error( $asset ) ) {
				self::record_svg_materialization_failure( $source, $svg_html, $asset );
				continue;
			}

			$img = $doc->createElement( 'img' );
			$img->setAttribute( 'src', $asset['url'] );
			$img->setAttribute( 'alt', self::svg_accessible_label( $svg ) );
			$img->setAttribute( 'decoding', 'async' );
			if ( $svg->hasAttribute( 'class' ) ) {
				$img->setAttribute( 'class', $svg->getAttribute( 'class' ) );
			}
			foreach ( array( 'width', 'height', 'aria-hidden', 'role' ) as $attribute ) {
				if ( $svg->hasAttribute( $attribute ) ) {
					$img->setAttribute( $attribute, $svg->getAttribute( $attribute ) );
				}
			}

			if ( $svg->parentNode instanceof DOMNode ) {
				$svg->parentNode->replaceChild( $img, $svg );
				$changed = true;
			}
		}

		if ( ! $changed ) {
			return $html;
		}

		$root = $doc->documentElement;
		if ( ! $root instanceof DOMElement ) {
			return $html;
		}

		$output = '';
		foreach ( $root->childNodes as $child ) {
			$fragment = $doc->saveHTML( $child );
			if ( false !== $fragment ) {
				$output .= $fragment;
			}
		}

		return '' === trim( $output ) ? $html : $output;
	}

	/**
	 * Validate an inline SVG against a conservative icon-safe subset.
	 *
	 * @param string $svg_html SVG markup.
	 * @return string|null
	 */
	private static function sanitize_inline_svg( string $svg_html ): ?string {
		$doc      = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$loaded   = $doc->loadXML( trim( $svg_html ) );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		if ( ! $loaded || ! $doc->documentElement instanceof DOMElement || 'svg' !== strtolower( $doc->documentElement->tagName ) ) {
			return null;
		}

		$allowed_tags       = array_fill_keys(
			array(
				'svg',
				'g',
				'path',
				'circle',
				'rect',
				'line',
				'polyline',
				'polygon',
				'ellipse',
				'title',
				'desc',
				'defs',
				'clipPath',
				'linearGradient',
				'radialGradient',
				'stop',
			),
			true
		);
		$allowed_attributes = array_fill_keys(
			array(
				'xmlns',
				'viewBox',
				'viewbox',
				'width',
				'height',
				'fill',
				'stroke',
				'stroke-width',
				'stroke-linecap',
				'stroke-linejoin',
				'stroke-miterlimit',
				'stroke-dasharray',
				'stroke-dashoffset',
				'd',
				'cx',
				'cy',
				'r',
				'rx',
				'ry',
				'x',
				'y',
				'x1',
				'y1',
				'x2',
				'y2',
				'points',
				'transform',
				'opacity',
				'fill-rule',
				'clip-rule',
				'clip-path',
				'class',
				'role',
				'aria-hidden',
				'aria-label',
				'focusable',
				'id',
				'offset',
				'stop-color',
				'stop-opacity',
				'gradientUnits',
				'gradientTransform',
			),
			true
		);

		foreach ( $doc->getElementsByTagName( '*' ) as $node ) {
			if ( ! isset( $allowed_tags[ $node->tagName ] ) ) {
				return null;
			}

			foreach ( iterator_to_array( $node->attributes ) as $attribute ) {
				$name  = $attribute->name;
				$value = $attribute->value;
				if ( str_starts_with( strtolower( $name ), 'on' ) || ! isset( $allowed_attributes[ $name ] ) || preg_match( '/(?:javascript:|data:|url\s*\()/i', $value ) ) {
					return null;
				}
			}
		}

		if ( ! $doc->documentElement->hasAttribute( 'xmlns' ) ) {
			$doc->documentElement->setAttribute( 'xmlns', 'http://www.w3.org/2000/svg' );
		}
		if ( $doc->documentElement->hasAttribute( 'viewbox' ) && ! $doc->documentElement->hasAttribute( 'viewBox' ) ) {
			$doc->documentElement->setAttribute( 'viewBox', $doc->documentElement->getAttribute( 'viewbox' ) );
			$doc->documentElement->removeAttribute( 'viewbox' );
		}

		$svg = $doc->saveXML( $doc->documentElement );
		return false === $svg ? null : $svg;
	}

	/**
	 * Write one sanitized SVG icon asset and return its generated metadata.
	 *
	 * @param string $svg      Sanitized SVG markup.
	 * @param string $source   Source fragment label.
	 * @param int    $sequence Sequence within the fragment.
	 * @return array<string, string>|WP_Error
	 */
	private static function write_svg_icon_asset( string $svg, string $source, int $sequence ) {
		$hash = substr( hash( 'sha256', $svg ), 0, 16 );
		if ( isset( self::$materialized_svg_assets[ $hash ] ) ) {
			return self::$materialized_svg_assets[ $hash ];
		}

		$name     = sanitize_title( preg_replace( '/[^A-Za-z0-9_-]+/', '-', $source ) . '-' . $sequence );
		$name     = '' === $name ? 'icon-' . $sequence : $name;
		$relative = 'assets/icons/' . $name . '-' . $hash . '.svg';
		$path     = trailingslashit( self::$active_theme_dir ) . $relative;
		$dir      = dirname( $path );
		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'static_site_importer_svg_icon_mkdir_failed', sprintf( 'Failed to create SVG icon directory: %s', $dir ) );
		}

		$result = self::write_file( $path, $svg . "\n" );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// phpcs:ignore Generic.Formatting.MultipleStatementAlignment.NotSameWarning -- Keep the compact local variable readable beside the longer static writes below.
		$asset = array(
			'name'   => basename( $relative ),
			'path'   => $relative,
			'url'    => trailingslashit( self::$active_theme_uri ) . $relative,
			'hash'   => $hash,
			'source' => $source,
			'block'  => 'core/image',
		);
		self::$materialized_svg_assets[ $hash ] = $asset;
		self::$conversion_report['assets']['svg_icons'][] = $asset;

		return $asset;
	}

	/**
	 * Extract a safe alt label from SVG accessibility nodes/attributes.
	 *
	 * @param DOMElement $svg SVG element.
	 * @return string
	 */
	private static function svg_accessible_label( DOMElement $svg ): string {
		foreach ( array( 'aria-label', 'title' ) as $attribute ) {
			$label = trim( $svg->getAttribute( $attribute ) );
			if ( '' !== $label ) {
				return $label;
			}
		}

		$title = $svg->getElementsByTagName( 'title' )->item( 0 );
		return $title instanceof DOMElement ? trim( $title->textContent ) : '';
	}

	/**
	 * Record an unsafe inline SVG that could not be materialized.
	 *
	 * @param string $source   Source fragment label.
	 * @param string $svg_html SVG markup.
	 * @return void
	 */
	private static function record_unsafe_inline_svg( string $source, string $svg_html ): void {
		++self::$conversion_report['quality']['unsafe_svg_count'];
		self::$conversion_report['diagnostics'][] = array(
			'type'         => 'unsafe_inline_svg',
			'source'       => $source,
			'html_length'  => strlen( $svg_html ),
			'html_excerpt' => self::diagnostic_excerpt( $svg_html ),
		);
	}

	/**
	 * Record a filesystem failure while materializing a safe inline SVG.
	 *
	 * @param string   $source   Source fragment label.
	 * @param string   $svg_html SVG markup.
	 * @param WP_Error $error    Write error.
	 * @return void
	 */
	private static function record_svg_materialization_failure( string $source, string $svg_html, WP_Error $error ): void {
		++self::$conversion_report['quality']['svg_materialization_failure_count'];
		self::$conversion_report['diagnostics'][] = array(
			'type'          => 'svg_materialization_failure',
			'source'        => $source,
			'error_code'    => $error->get_error_code(),
			'error_message' => $error->get_error_message(),
			'html_length'   => strlen( $svg_html ),
			'html_excerpt'  => self::diagnostic_excerpt( $svg_html ),
		);
	}

	/**
	 * Convert HTML to block markup.
	 *
	 * @param string $html HTML fragment.
	 * @return string
	 */
	private static function convert_fragment( string $html, string $source = 'fragment' ): string {
		if ( '' === trim( $html ) ) {
			return '';
		}

		$html = self::materialize_inline_svg_icons( $html, $source );

		self::start_conversion_fragment( $source, $html );
		$fallback_listener     = static function ( string $element_html, array $context, array $block ) use ( $source ): void {
			self::record_unsupported_fallback( $source, $element_html, $context, $block );
		};
		$content_loss_listener = static function ( int $original_text_length, int $serialized_text_length ) use ( $source ): void {
			self::record_content_loss( $source, $original_text_length, $serialized_text_length );
		};

		add_action( 'html_to_blocks_unsupported_html_fallback', $fallback_listener, 10, 3 );
		add_action( 'html_to_blocks_conversion_aborted_content_loss', $content_loss_listener, 10, 2 );
		// @phpstan-ignore-next-line function.notFound -- Loaded by the bundled Block Format Bridge runtime.
		$blocks = bfb_convert( $html, 'html', 'blocks' );
		remove_action( 'html_to_blocks_unsupported_html_fallback', $fallback_listener, 10 );
		remove_action( 'html_to_blocks_conversion_aborted_content_loss', $content_loss_listener, 10 );

		if ( '' === $blocks ) {
			self::record_conversion_empty( $source, $html );
		}
		$blocks = self::mark_empty_decorative_group_blocks( $blocks, $source );
		self::finish_conversion_fragment( $source, $blocks );

		return '' === $blocks ? '<!-- wp:html -->' . "\n" . $html . "\n" . '<!-- /wp:html -->' : $blocks;
	}

	/**
	 * Mark empty absolute-positioned groups so editor CSS can hide only decorative placeholders.
	 *
	 * @param string $block_markup Serialized block markup.
	 * @return string Serialized block markup.
	 */
	private static function mark_empty_decorative_group_blocks( string $block_markup, string $source = '' ): string {
		if ( '' === trim( $block_markup ) || empty( self::$decorative_empty_group_classes ) || ! function_exists( 'parse_blocks' ) || ! function_exists( 'serialize_blocks' ) ) {
			return $block_markup;
		}

		/** @var array<int, array<string, mixed>> $blocks */
		$blocks                 = parse_blocks( $block_markup );
		$changed                = false;
		$normalized_html_blocks = 0;
		self::normalize_decorative_html_blocks_in_tree( $blocks, $changed, $normalized_html_blocks );
		if ( $normalized_html_blocks > 0 && '' !== $source ) {
			self::clear_normalized_decorative_fallbacks( $source, $normalized_html_blocks );
		}
		self::mark_empty_decorative_group_blocks_in_tree( $blocks, $changed );

		// @phpstan-ignore-next-line argument.type -- Parsed blocks are normalized before serializing.
		return $changed ? serialize_blocks( $blocks ) : $block_markup;
	}

	/**
	 * Convert raw HTML islands made only of decorative empty divs into native groups.
	 *
	 * @param array<int, array<string, mixed>> $blocks  Parsed blocks.
	 * @param bool                            $changed Whether any block changed.
	 * @return void
	 */
	private static function normalize_decorative_html_blocks_in_tree( array &$blocks, bool &$changed, int &$normalized_html_blocks ): void {
		$block_total = count( $blocks );
		for ( $index = 0; $index < $block_total; ++$index ) {
			if ( ! empty( $blocks[ $index ]['innerBlocks'] ) && is_array( $blocks[ $index ]['innerBlocks'] ) ) {
				self::normalize_decorative_html_blocks_in_tree( $blocks[ $index ]['innerBlocks'], $changed, $normalized_html_blocks );
			}

			if ( 'core/html' !== ( $blocks[ $index ]['blockName'] ?? '' ) ) {
				continue;
			}

			$replacement = self::decorative_group_blocks_from_html( (string) ( $blocks[ $index ]['innerHTML'] ?? '' ) );
			if ( null === $replacement ) {
				continue;
			}

			array_splice( $blocks, $index, 1, $replacement );
			$replacement_count = count( $replacement );
			$index            += $replacement_count - 1;
			$block_total      += $replacement_count - 1;
			$changed           = true;
			++$normalized_html_blocks;
		}
	}

	/**
	 * Remove fallback diagnostics that were recovered as native decorative group blocks.
	 *
	 * @param string $source Source fragment label.
	 * @param int    $count  Number of normalized fallback blocks.
	 * @return void
	 */
	private static function clear_normalized_decorative_fallbacks( string $source, int $count ): void {
		self::$conversion_report['quality']['fallback_count'] = max( 0, (int) self::$conversion_report['quality']['fallback_count'] - $count );
		if ( isset( self::$conversion_report['conversion_fragments'][ $source ]['fallback_count'] ) ) {
			self::$conversion_report['conversion_fragments'][ $source ]['fallback_count'] = max( 0, (int) self::$conversion_report['conversion_fragments'][ $source ]['fallback_count'] - $count );
		}

		for ( $index = count( self::$conversion_report['diagnostics'] ) - 1; $index >= 0 && $count > 0; --$index ) {
			$diagnostic = self::$conversion_report['diagnostics'][ $index ];
			if ( 'unsupported_html_fallback' !== ( $diagnostic['type'] ?? '' ) || ( $diagnostic['source'] ?? '' ) !== $source ) {
				continue;
			}

			array_splice( self::$conversion_report['diagnostics'], $index, 1 );
			--$count;
		}
	}

	/**
	 * Convert an HTML fragment to group blocks when it only contains decorative empty div layers.
	 *
	 * @param string $html HTML fragment.
	 * @return array<int, array<string, mixed>>|null Replacement group blocks, or null when not safe.
	 */
	private static function decorative_group_blocks_from_html( string $html ): ?array {
		if ( '' === trim( $html ) || ! str_contains( $html, '<div' ) ) {
			return null;
		}

		$doc      = self::load_fragment_document( $html );
		$root     = $doc->documentElement;
		$blocks   = array();
		$matched  = false;
		$has_node = false;
		if ( ! $root instanceof DOMElement ) {
			return null;
		}

		foreach ( $root->childNodes as $child ) {
			if ( $child instanceof DOMText && '' === trim( $child->textContent ) ) {
				continue;
			}

			if ( ! $child instanceof DOMElement ) {
				return null;
			}

			$has_node = true;
			$block    = self::decorative_group_block_from_element( $child, $matched );
			if ( null === $block ) {
				return null;
			}

			$blocks[] = $block;
		}

		return $has_node && $matched ? $blocks : null;
	}

	/**
	 * Convert one empty decorative div tree to a parsed group block.
	 *
	 * @param DOMElement $element Source element.
	 * @param bool       $matched Whether a decorative class was found.
	 * @return array<string, mixed>|null Parsed block, or null when not safe.
	 */
	private static function decorative_group_block_from_element( DOMElement $element, bool &$matched ): ?array {
		if ( 'div' !== strtolower( $element->tagName ) ) {
			return null;
		}

		$children = array();
		foreach ( $element->childNodes as $child ) {
			if ( $child instanceof DOMText && '' === trim( $child->textContent ) ) {
				continue;
			}

			if ( ! $child instanceof DOMElement ) {
				return null;
			}

			$child_block = self::decorative_group_block_from_element( $child, $matched );
			if ( null === $child_block ) {
				return null;
			}

			$children[] = $child_block;
		}

		$class_name = trim( $element->getAttribute( 'class' ) );
		$classes    = preg_split( '/\s+/', $class_name );
		$classes    = false === $classes ? array() : $classes;
		$is_layer   = false;
		foreach ( $classes as $class ) {
			if ( isset( self::$decorative_empty_group_classes[ $class ] ) ) {
				$is_layer = true;
				$matched  = true;
				break;
			}
		}

		if ( empty( $children ) && $is_layer ) {
			$class_name = self::append_class_token( $class_name, 'static-site-importer-decorative-layer' );
		}

		$attrs = array();
		if ( '' !== $class_name ) {
			$attrs['className'] = $class_name;
		}

		$class_attr    = esc_attr( trim( 'wp-block-group ' . $class_name ) );
		$inner_content = array( '<div class="' . $class_attr . '">' );
		foreach ( $children as $_child ) {
			$inner_content[] = null;
		}
		$inner_content[] = '</div>';

		if ( empty( $children ) ) {
			$inner_content = array( '<div class="' . $class_attr . '"></div>' );
		}

		return array(
			'blockName'    => 'core/group',
			'attrs'        => $attrs,
			'innerBlocks'  => $children,
			'innerHTML'    => implode( '', array_filter( $inner_content, 'is_string' ) ),
			'innerContent' => $inner_content,
		);
	}

	/**
	 * Recursively mark empty decorative group blocks.
	 *
	 * @param array<int, array<string, mixed>> $blocks  Parsed blocks.
	 * @param bool                            $changed Whether any block changed.
	 * @return void
	 */
	private static function mark_empty_decorative_group_blocks_in_tree( array &$blocks, bool &$changed ): void {
		foreach ( $blocks as &$block ) {
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				self::mark_empty_decorative_group_blocks_in_tree( $block['innerBlocks'], $changed );
			}

			if ( 'core/group' !== ( $block['blockName'] ?? '' ) || ! self::is_empty_decorative_group_block( $block ) ) {
				continue;
			}

			$attrs              = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
			$attrs['className'] = self::append_class_token( (string) ( $attrs['className'] ?? '' ), 'static-site-importer-decorative-layer' );
			$block['attrs']     = $attrs;

			foreach ( array( 'innerHTML' ) as $key ) {
				if ( isset( $block[ $key ] ) && is_string( $block[ $key ] ) ) {
					$block[ $key ] = self::append_class_to_first_html_class_attribute( $block[ $key ], 'static-site-importer-decorative-layer' );
				}
			}

			if ( isset( $block['innerContent'] ) && is_array( $block['innerContent'] ) ) {
				foreach ( $block['innerContent'] as &$content ) {
					if ( is_string( $content ) ) {
						$content = self::append_class_to_first_html_class_attribute( $content, 'static-site-importer-decorative-layer' );
					}
				}
				unset( $content );
			}

			$changed = true;
		}
		unset( $block );
	}

	/**
	 * Check whether a parsed group block is empty and styled as a decorative layer.
	 *
	 * @param array<string, mixed> $block Parsed block.
	 * @return bool Whether the block is an empty decorative group.
	 */
	private static function is_empty_decorative_group_block( array $block ): bool {
		if ( ! empty( $block['innerBlocks'] ) ) {
			return false;
		}

		$inner_html = (string) ( $block['innerHTML'] ?? '' );
		if ( '' !== trim( wp_strip_all_tags( $inner_html ) ) ) {
			return false;
		}

		$attrs   = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		$classes = preg_split( '/\s+/', trim( (string) ( $attrs['className'] ?? '' ) ) );
		$classes = false === $classes ? array() : $classes;
		foreach ( $classes as $class ) {
			if ( isset( self::$decorative_empty_group_classes[ $class ] ) ) {
				return true;
			}
		}

		return false;
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
	}

	/**
	 * Append a class to the first HTML class attribute in a serialized block fragment.
	 *
	 * @param string $html  HTML fragment.
	 * @param string $class_name_to_append Class to append.
	 * @return string Updated HTML fragment.
	 */
	private static function append_class_to_first_html_class_attribute( string $html, string $class_name_to_append ): string {
		$updated = preg_replace_callback(
			'/class="([^"]*)"/',
			static function ( array $matches ) use ( $class_name_to_append ): string {
				return 'class="' . esc_attr( self::append_class_token( html_entity_decode( $matches[1], ENT_QUOTES ), $class_name_to_append ) ) . '"';
			},
			$html,
			1
		);

		return null === $updated ? $html : $updated;
	}

	/**
	 * Initialize a conversion report.
	 *
	 * @param string $html_path Imported entry file.
	 * @return array<string, mixed>
	 */
	private static function new_conversion_report( string $html_path ): array {
		return array(
			'version'              => 1,
			'entry_file'           => $html_path,
			'quality'              => array(
				'pass'                              => true,
				'fallback_count'                    => 0,
				'content_loss_count'                => 0,
				'empty_conversion_count'            => 0,
				'core_html_block_count'             => 0,
				'invalid_block_count'               => 0,
				'invalid_block_document_count'      => 0,
				'unsafe_svg_count'                  => 0,
				'svg_materialization_failure_count' => 0,
				'failure_reasons'                   => array(),
			),
			'conversion_fragments' => array(),
			'assets'               => array(
				'svg_icons' => array(),
			),
			'generated_theme'      => array(
				'block_documents' => array(),
			),
			'visual_fidelity'      => array(
				'status'             => 'requires_external_render_check',
				'gate_owner'         => 'benchmark_harness',
				'comparison_targets' => array(),
				'notes'              => array(
					'Static Site Importer records source and generated DOM probes, render URLs, and theme artifacts for visual comparison; screenshot capture and computed-style/layout thresholds belong to the benchmark harness.',
				),
			),
			'diagnostics'          => array(),
			'notes'                => array(
				'Block Format Bridge owns HTML-to-block transform fidelity; Static Site Importer records converter diagnostics and quality gates the generated theme.',
				'Generated-theme block validation uses WordPress server-side block parsing and serialization checks; editor-runtime validation remains the exact Gutenberg authority.',
				'Visual fidelity requires browser rendering; use visual_fidelity.comparison_targets to compare source static HTML against the generated WordPress URL.',
			),
		);
	}

	/**
	 * Record practical source/generated targets for external visual fidelity gates.
	 *
	 * @param array<string, array{path:string,document:Static_Site_Importer_Document}> $pages          Imported pages.
	 * @param array<string, int>                                                       $page_ids       Page IDs keyed by source filename.
	 * @param array<string, string>                                                    $permalinks     Page permalinks keyed by source filename.
	 * @param array<string, string>                                                    $writes         Generated files keyed by absolute path.
	 * @param string                                                                   $theme_dir      Generated theme directory.
	 * @return void
	 */
	private static function record_visual_fidelity_targets( array $pages, array $page_ids, array $permalinks, array $writes, string $theme_dir ): void {
		$theme_prefix = trailingslashit( $theme_dir );

		foreach ( $pages as $filename => $page ) {
			$source_html = self::read_visual_probe_file( $page['path'] );
			$slug        = self::page_slug( $filename );
			$template    = '' === $slug ? '' : 'templates/page-' . $slug . '.html';
			$pattern     = '' === $slug ? '' : 'patterns/page-' . $slug . '.php';
			$generated   = self::generated_visual_probe_markup( $writes, $theme_prefix, $pattern );
			$footer_part = isset( $writes[ $theme_prefix . 'parts/footer.html' ] ) ? 'parts/footer.html' : '';

			self::$conversion_report['visual_fidelity']['comparison_targets'][] = array(
				'source_file'            => $page['path'],
				'source_filename'        => $filename,
				'wordpress_page_id'      => $page_ids[ $filename ] ?? null,
				'wordpress_url'          => $permalinks[ $filename ] ?? '',
				'generated_template'     => $template,
				'generated_pattern'      => $pattern,
				'source_probe_counts'    => self::visual_probe_counts( $source_html ),
				'generated_probe_counts' => self::visual_probe_counts( $generated ),
				'comparison_hooks'       => array(
					'screenshot'      => array(
						'source'    => $page['path'],
						'generated' => $permalinks[ $filename ] ?? '',
					),
					'hero'            => array( '.hero', 'header', '[class*=hero]' ),
					'buttons'         => array( 'a[class*=btn]', 'a[class*=button]', 'a[class*=cta]', 'button', '.wp-block-button__link' ),
					'visible_chrome'  => array( 'nav', 'header', 'footer' ),
					'generated_files' => array_values( array_filter( array( $template, $pattern, 'parts/header.html', $footer_part, 'style.css' ) ) ),
				),
			);
		}
	}

	/**
	 * Read an HTML file for best-effort visual probe metadata.
	 *
	 * @param string $path File path.
	 * @return string
	 */
	private static function read_visual_probe_file( string $path ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads local static-site source files for report-only visual probe metadata.
		$contents = is_readable( $path ) ? file_get_contents( $path ) : '';
		return false === $contents ? '' : $contents;
	}

	/**
	 * Collect generated markup relevant to one page's visual comparison.
	 *
	 * @param array<string, string> $writes       Generated files keyed by absolute path.
	 * @param string                $theme_prefix Absolute theme directory with trailing slash.
	 * @param string                $pattern      Theme-relative page pattern path.
	 * @return string
	 */
	private static function generated_visual_probe_markup( array $writes, string $theme_prefix, string $pattern ): string {
		$parts = array();
		foreach ( array( 'parts/header.html', $pattern, 'parts/footer.html' ) as $relative_path ) {
			if ( '' === $relative_path ) {
				continue;
			}

			$path = $theme_prefix . $relative_path;
			if ( isset( $writes[ $path ] ) ) {
				$parts[] = self::generated_block_document_markup( $relative_path, $writes[ $path ] );
			}
		}

		return trim( implode( "\n", $parts ) );
	}

	/**
	 * Count visual probe anchors that a browser-based harness should compare.
	 *
	 * @param string $html HTML or block markup.
	 * @return array<string, int>
	 */
	private static function visual_probe_counts( string $html ): array {
		return array(
			'hero_candidates'    => self::visual_probe_count( $html, 'hero' ),
			'button_candidates'  => self::visual_probe_count( $html, 'button' ),
			'nav_candidates'     => self::visual_probe_count( $html, 'nav' ),
			'footer_candidates'  => self::visual_probe_count( $html, 'footer' ),
			'core_button_blocks' => self::count_block_name_in_markup( $html, 'core/button' ),
		);
	}

	/**
	 * Count one visual probe family in markup.
	 *
	 * @param string $html  HTML or block markup.
	 * @param string $probe Probe family.
	 * @return int
	 */
	private static function visual_probe_count( string $html, string $probe ): int {
		if ( '' === trim( $html ) ) {
			return 0;
		}

		$doc      = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$loaded   = $doc->loadHTML( '<!doctype html><html><body>' . $html . '</body></html>' );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		if ( ! $loaded ) {
			return 0;
		}

		$count = 0;
		foreach ( $doc->getElementsByTagName( '*' ) as $element ) {
			if ( self::element_matches_visual_probe( $element, $probe ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Check whether one DOM element matches a visual probe family.
	 *
	 * @param DOMElement $element DOM element.
	 * @param string     $probe   Probe family.
	 * @return bool
	 */
	private static function element_matches_visual_probe( DOMElement $element, string $probe ): bool {
		$tag     = strtolower( $element->tagName );
		$classes = preg_split( '/\s+/', strtolower( trim( $element->getAttribute( 'class' ) ) ) );
		$classes = is_array( $classes ) ? array_filter( $classes ) : array();

		if ( 'hero' === $probe ) {
			return 'header' === $tag || self::class_tokens_contain_fragment( $classes, 'hero' );
		}

		if ( 'button' === $probe ) {
			return 'button' === $tag || 'button' === strtolower( $element->getAttribute( 'role' ) ) || self::class_tokens_contain_any_fragment( $classes, array( 'btn', 'button', 'cta', 'pill' ) );
		}

		if ( 'nav' === $probe ) {
			return 'nav' === $tag || self::class_tokens_contain_any_fragment( $classes, array( 'nav', 'navigation' ) );
		}

		if ( 'footer' === $probe ) {
			return 'footer' === $tag || self::class_tokens_contain_fragment( $classes, 'footer' );
		}

		return false;
	}

	/**
	 * Check whether class tokens contain any listed fragment.
	 *
	 * @param array<int, string> $classes   Class tokens.
	 * @param array<int, string> $fragments Fragments to match.
	 * @return bool
	 */
	private static function class_tokens_contain_any_fragment( array $classes, array $fragments ): bool {
		foreach ( $fragments as $fragment ) {
			if ( self::class_tokens_contain_fragment( $classes, $fragment ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check whether class tokens contain a fragment.
	 *
	 * @param array<int, string> $classes  Class tokens.
	 * @param string             $fragment Fragment to match.
	 * @return bool
	 */
	private static function class_tokens_contain_fragment( array $classes, string $fragment ): bool {
		foreach ( $classes as $class ) {
			if ( str_contains( $class, $fragment ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Count one block type in serialized block markup.
	 *
	 * @param string $markup     Serialized block markup.
	 * @param string $block_name Block name.
	 * @return int
	 */
	private static function count_block_name_in_markup( string $markup, string $block_name ): int {
		if ( '' === trim( $markup ) || ! function_exists( 'parse_blocks' ) ) {
			return 0;
		}

		$count = 0;
		/** @var array<int, array<string, mixed>> $blocks */
		$blocks = parse_blocks( $markup );
		self::count_block_name_in_blocks( $blocks, $block_name, $count );

		return $count;
	}

	/**
	 * Recursively count one block type in parsed blocks.
	 *
	 * @param array<int, array<string, mixed>> $blocks     Parsed block list.
	 * @param string                           $block_name Block name.
	 * @param int                              $count      Running count.
	 * @return void
	 */
	private static function count_block_name_in_blocks( array $blocks, string $block_name, int &$count ): void {
		foreach ( $blocks as $block ) {
			if ( ( $block['blockName'] ?? null ) === $block_name ) {
				++$count;
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				self::count_block_name_in_blocks( $block['innerBlocks'], $block_name, $count );
			}
		}
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
		self::analyze_generated_block_list( $analyzed_blocks, $block_count, $core_html_count, $freeform_count, $invalid_count );

		$serialized             = serialize_blocks( $blocks );
		$serialization_mismatch = self::normalize_block_document_for_report( $block_markup ) !== self::normalize_block_document_for_report( $serialized );
		if ( $serialization_mismatch ) {
			++$invalid_count;
		}

		self::$conversion_report['quality']['core_html_block_count'] += $core_html_count;
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
	 * @return void
	 */
	private static function analyze_generated_block_list( array $blocks, int &$block_count, int &$core_html_count, int &$freeform_count, int &$invalid_count ): void {
		foreach ( $blocks as $block ) {
			$name = isset( $block['blockName'] ) ? $block['blockName'] : null;
			if ( is_string( $name ) && '' !== $name ) {
				++$block_count;
				if ( 'core/html' === $name ) {
					++$core_html_count;
				}
			} elseif ( '' !== trim( isset( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ? $block['innerHTML'] : '' ) ) {
				++$freeform_count;
				++$invalid_count;
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				self::analyze_generated_block_list( $block['innerBlocks'], $block_count, $core_html_count, $freeform_count, $invalid_count );
			}
		}
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
	 * Record the start of one conversion fragment.
	 *
	 * @param string $source Source fragment label.
	 * @param string $html   Source HTML.
	 * @return void
	 */
	private static function start_conversion_fragment( string $source, string $html ): void {
		self::$conversion_report['conversion_fragments'][ $source ] = array(
			'source'             => $source,
			'input_length'       => strlen( $html ),
			'input_text_length'  => strlen( wp_strip_all_tags( $html ) ),
			'output_length'      => 0,
			'fallback_count'     => 0,
			'content_loss_count' => 0,
			'empty_conversion'   => false,
		);
	}

	/**
	 * Record the end of one conversion fragment.
	 *
	 * @param string $source Source fragment label.
	 * @param string $blocks Converted block markup.
	 * @return void
	 */
	private static function finish_conversion_fragment( string $source, string $blocks ): void {
		if ( ! isset( self::$conversion_report['conversion_fragments'][ $source ] ) ) {
			return;
		}

		self::$conversion_report['conversion_fragments'][ $source ]['output_length']      = strlen( $blocks );
		self::$conversion_report['conversion_fragments'][ $source ]['output_text_length'] = strlen( wp_strip_all_tags( $blocks ) );
		self::record_button_wrapper_classes_from_blocks( $blocks );
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
	}

	/**
	 * Record an unsupported HTML fallback emitted by h2bc.
	 *
	 * @param string $source       Source fragment label.
	 * @param string $element_html Unsupported HTML.
	 * @param array  $context      Fallback context.
	 * @param array  $block        Generated block.
	 * @return void
	 */
	private static function record_unsupported_fallback( string $source, string $element_html, array $context, array $block ): void {
		++self::$conversion_report['quality']['fallback_count'];
		++self::$conversion_report['conversion_fragments'][ $source ]['fallback_count'];
		self::$conversion_report['diagnostics'][] = array(
			'type'         => 'unsupported_html_fallback',
			'source'       => $source,
			'reason'       => isset( $context['reason'] ) ? (string) $context['reason'] : 'unknown',
			'tag_name'     => isset( $context['tag_name'] ) ? (string) $context['tag_name'] : null,
			'block_name'   => isset( $block['blockName'] ) ? (string) $block['blockName'] : null,
			'html_length'  => strlen( $element_html ),
			'html_excerpt' => self::diagnostic_excerpt( $element_html ),
		);
	}

	/**
	 * Record a content-loss abort emitted by h2bc.
	 *
	 * @param string $source                 Source fragment label.
	 * @param int    $original_text_length   Original text length.
	 * @param int    $serialized_text_length Serialized text length.
	 * @return void
	 */
	private static function record_content_loss( string $source, int $original_text_length, int $serialized_text_length ): void {
		++self::$conversion_report['quality']['content_loss_count'];
		++self::$conversion_report['conversion_fragments'][ $source ]['content_loss_count'];
		self::$conversion_report['diagnostics'][] = array(
			'type'                   => 'content_loss_abort',
			'source'                 => $source,
			'original_text_length'   => $original_text_length,
			'serialized_text_length' => $serialized_text_length,
		);
	}

	/**
	 * Record an empty conversion result for a non-empty fragment.
	 *
	 * @param string $source Source fragment label.
	 * @param string $html   Source HTML.
	 * @return void
	 */
	private static function record_conversion_empty( string $source, string $html ): void {
		++self::$conversion_report['quality']['empty_conversion_count'];
		self::$conversion_report['conversion_fragments'][ $source ]['empty_conversion'] = true;

		self::$conversion_report['diagnostics'][] = array(
			'type'         => 'empty_conversion',
			'source'       => $source,
			'html_length'  => strlen( $html ),
			'html_excerpt' => self::diagnostic_excerpt( $html ),
		);
	}

	/**
	 * Finalize quality summary and gate status.
	 *
	 * @param array $args Import args.
	 * @return array<string, mixed>
	 */
	private static function finalize_quality_report( array $args ): array {
		$quality = self::$conversion_report['quality'];
		$reasons = array();
		if ( $quality['fallback_count'] > 0 ) {
			$reasons[] = 'unsupported_html_fallback';
		}
		if ( $quality['content_loss_count'] > 0 ) {
			$reasons[] = 'content_loss_abort';
		}
		if ( $quality['empty_conversion_count'] > 0 ) {
			$reasons[] = 'empty_conversion';
		}
		if ( $quality['core_html_block_count'] > 0 ) {
			$reasons[] = 'core_html_block';
		}
		if ( $quality['invalid_block_count'] > 0 ) {
			$reasons[] = 'invalid_block';
		}
		if ( $quality['unsafe_svg_count'] > 0 ) {
			$reasons[] = 'unsafe_inline_svg';
		}
		if ( $quality['svg_materialization_failure_count'] > 0 ) {
			$reasons[] = 'svg_materialization_failure';
		}

		$quality['pass']            = empty( $reasons );
		$quality['failure_reasons'] = $reasons;
		$quality['fail_import']     = false;
		if ( ! empty( $args['fail_on_quality'] ) && ! $quality['pass'] ) {
			$quality['fail_import'] = true;
		}
		if ( array_key_exists( 'max_fallbacks', $args ) && null !== $args['max_fallbacks'] && $quality['fallback_count'] > (int) $args['max_fallbacks'] ) {
			$quality['fail_import'] = true;
		}

		self::$conversion_report['quality'] = $quality;
		return $quality;
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
	 * Ensure theme directories exist.
	 *
	 * @param string $theme_dir Theme directory.
	 * @return true|WP_Error
	 */
	private static function ensure_dirs( string $theme_dir ) {
		foreach ( array( $theme_dir, $theme_dir . '/templates', $theme_dir . '/parts', $theme_dir . '/patterns', $theme_dir . '/assets', $theme_dir . '/assets/icons' ) as $dir ) {
			if ( ! wp_mkdir_p( $dir ) ) {
				return new WP_Error( 'static_site_importer_mkdir_failed', sprintf( 'Failed to create directory: %s', $dir ) );
			}
		}

		return true;
	}

	/**
	 * Write a generated file.
	 *
	 * @param string $path    File path.
	 * @param string $content File content.
	 * @return true|WP_Error
	 */
	private static function write_file( string $path, string $content ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writes generated block-theme files to the selected theme directory.
		$result = file_put_contents( $path, $content );
		if ( false === $result ) {
			return new WP_Error( 'static_site_importer_write_failed', sprintf( 'Failed to write file: %s', $path ) );
		}

		return true;
	}

	/**
	 * Write a copy of the import report to a caller-selected path.
	 *
	 * @param string $path    Report path.
	 * @param string $content Report JSON.
	 * @return true|WP_Error
	 */
	private static function write_external_report( string $path, string $content ) {
		$dir = dirname( $path );
		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'static_site_importer_report_mkdir_failed', sprintf( 'Failed to create report directory: %s', $dir ) );
		}

		return self::write_file( $path, $content );
	}

	/**
	 * Delete the source static-site directory after a clean import.
	 *
	 * @param string $site_dir  Static site source directory.
	 * @param string $html_path Entry HTML file path.
	 * @return true|WP_Error
	 */
	private static function delete_source_dir( string $site_dir, string $html_path ) {
		$source_dir = realpath( $site_dir );
		$entry_file = realpath( $html_path );
		if ( false === $source_dir || false === $entry_file || ! is_dir( $source_dir ) ) {
			return new WP_Error( 'static_site_importer_source_cleanup_missing', 'Source directory is not available.' );
		}

		if ( 0 !== strpos( $entry_file, trailingslashit( $source_dir ) ) ) {
			return new WP_Error( 'static_site_importer_source_cleanup_mismatch', 'Entry HTML file is not inside the source directory.' );
		}

		$protected_dirs = array_filter(
			array_map(
				'realpath',
				array(
					ABSPATH,
					WP_CONTENT_DIR,
					get_theme_root(),
				)
			)
		);
		if ( DIRECTORY_SEPARATOR === $source_dir || in_array( $source_dir, $protected_dirs, true ) ) {
			return new WP_Error( 'static_site_importer_source_cleanup_protected', sprintf( 'Refusing to delete protected source directory: %s', $source_dir ) );
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $source_dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $item ) {
			$path = $item->getPathname();
			if ( $item->isDir() && ! $item->isLink() ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removes caller-provided temporary static-site source directories after a clean import.
				if ( ! rmdir( $path ) ) {
					return new WP_Error( 'static_site_importer_source_cleanup_failed', sprintf( 'Failed to remove source directory: %s', $path ) );
				}
				continue;
			}

			if ( ! wp_delete_file( $path ) ) {
				return new WP_Error( 'static_site_importer_source_cleanup_failed', sprintf( 'Failed to remove source file: %s', $path ) );
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removes caller-provided temporary static-site source directories after a clean import.
		if ( ! rmdir( $source_dir ) ) {
			return new WP_Error( 'static_site_importer_source_cleanup_failed', sprintf( 'Failed to remove source directory: %s', $source_dir ) );
		}

		return true;
	}

	/**
	 * Build style.css.
	 *
	 * @param string $theme_name Theme name.
	 * @param string $css        Source CSS.
	 * @return string
	 */
	private static function style_css( string $theme_name, string $css, array $button_classes = array() ): string {
		$button_bridge    = self::button_style_bridge_css( $css, $button_classes );
		$editor_bridge    = self::editor_absolute_overlay_css( $css );
		$admin_bar_bridge = self::admin_bar_top_chrome_css( $css );
		$css              = self::scope_source_button_css( $css, $button_classes );

		return "/*\nTheme Name: " . $theme_name . "\nAuthor: Static Site Importer\nDescription: Imported from static HTML using Block Format Bridge.\nVersion: 0.1.0\nRequires at least: 6.6\n*/\n\n" . $css . "\n" . $button_bridge . $editor_bridge . $admin_bar_bridge;
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
					$selectors[] = 'body.admin-bar ' . $selector;
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
	 * Build compatibility rules for source anchor classes moved onto core/button wrappers.
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

			$selectors = array();
			foreach ( explode( ',', $prelude ) as $selector ) {
				$bridge_selector = self::button_style_bridge_selector( trim( $selector ), $button_classes );
				if ( null !== $bridge_selector ) {
					$selectors[] = $bridge_selector;
				}
			}

			if ( $selectors ) {
				$rules[] = implode( ', ', array_unique( $selectors ) ) . ' { ' . $body . ' }';
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
			"\tadd_editor_style( 'style.css' );\n" .
			"} );\n\n" .
			"add_action( 'wp_enqueue_scripts', static function (): void {\n" .
			"\twp_enqueue_style( '" . $style_handle . "', get_stylesheet_uri(), array(), wp_get_theme()->get( 'Version' ) );\n" .
			"\tif ( file_exists( get_template_directory() . '/assets/site.js' ) ) {\n" .
			"\t\twp_enqueue_script( '" . $script_handle . "', get_template_directory_uri() . '/assets/site.js', array(), wp_get_theme()->get( 'Version' ), true );\n" .
			"\t}\n" .
			"} );\n\n" .
			"add_action( 'enqueue_block_editor_assets', static function (): void {\n" .
			"\twp_enqueue_style( '" . $editor_handle . "', get_stylesheet_uri(), array(), wp_get_theme()->get( 'Version' ) );\n" .
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
			'<!-- wp:post-content /-->' . "\n\n" .
			$footer_part
		) . "\n";
	}

	/**
	 * Build a template that renders one imported page layout pattern.
	 *
	 * @param string $background_blocks Background decoration blocks.
	 * @param string $pattern_slug      Pattern slug.
	 * @param bool   $has_footer_part   Whether a shared footer template part was generated.
	 * @return string
	 */
	private static function page_pattern_template( string $background_blocks, string $pattern_slug, bool $has_footer_part ): string {
		$body        = '' === $pattern_slug ? '<!-- wp:post-content /-->' : '<!-- wp:pattern {"slug":"' . esc_attr( $pattern_slug ) . '"} /-->';
		$footer_part = $has_footer_part ? '<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->' : '';

		return trim(
			'<!-- wp:template-part {"slug":"header","tagName":"header"} /-->' . "\n\n" .
			$background_blocks . "\n\n" .
			$body . "\n\n" .
			$footer_part
		) . "\n";
	}

	/**
	 * Build the placeholder stored on imported page posts.
	 *
	 * @return string
	 */
	private static function page_shell_content(): string {
		return '<!-- wp:paragraph --><p>Imported page layout lives in this page\'s generated block theme template and pattern.</p><!-- /wp:paragraph -->';
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

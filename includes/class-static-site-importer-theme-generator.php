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
	 * Import an HTML file as a block theme.
	 *
	 * @param string $html_path  HTML file path.
	 * @param array  $args       Import args.
	 * @return array{theme_slug:string,theme_name:string,theme_dir:string,pages:array<string,int>}|WP_Error
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

		$permalinks = self::page_permalinks( $page_ids );
		$fragments  = $document->fragments();

		$background_blocks = self::convert_fragment( self::rewrite_internal_links( $fragments['background'], $permalinks ) );
		$header_blocks     = self::convert_header_fragment( self::strip_active_classes( self::rewrite_internal_links( $fragments['header'], $permalinks ) ) );
		$footer_blocks     = self::convert_footer_fragment( self::rewrite_internal_links( $fragments['footer'], $permalinks ) );

		$result = self::write_page_contents( $pages, $page_ids, $permalinks );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$writes = array(
			$theme_dir . '/style.css'                  => self::style_css( $theme_name, self::site_css( $site_dir, $document ) ),
			$theme_dir . '/functions.php'              => self::functions_php( $theme_slug ),
			$theme_dir . '/theme.json'                 => self::theme_json( $theme_name ),
			$theme_dir . '/parts/header.html'          => $header_blocks,
			$theme_dir . '/parts/footer.html'          => $footer_blocks,
			$theme_dir . '/templates/front-page.html'  => self::content_template( $background_blocks ),
			$theme_dir . '/templates/page.html'        => self::content_template( $background_blocks ),
			$theme_dir . '/templates/index.html'       => self::content_template( $background_blocks ),
		);

		$inline_js = $document->inline_js();
		if ( '' !== $inline_js ) {
			$writes[ $theme_dir . '/assets/site.js' ] = $inline_js;
		}

		foreach ( $writes as $path => $content ) {
			$result = self::write_file( $path, $content );
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

		return array(
			'theme_slug' => $theme_slug,
			'theme_name' => $theme_name,
			'theme_dir'  => $theme_dir,
			'pages'      => $page_ids,
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
		foreach ( glob( trailingslashit( $site_dir ) . '*.html' ) ?: array() as $path ) {
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
	 * Write converted page body content.
	 *
	 * @param array<string, array{path:string,document:Static_Site_Importer_Document}> $pages      Pages.
	 * @param array<string,int>                                                         $page_ids   Page IDs keyed by filename.
	 * @param array<string,string>                                                      $permalinks Permalinks keyed by filename.
	 * @return true|WP_Error
	 */
	private static function write_page_contents( array $pages, array $page_ids, array $permalinks ) {
		foreach ( $pages as $filename => $page ) {
			$fragments = $page['document']->fragments();
			$content   = self::convert_fragment( self::rewrite_internal_links( $fragments['main'], $permalinks ) );
			$page_id   = $page_ids[ $filename ] ?? 0;

			$result = wp_update_post(
				array(
					'ID'           => $page_id,
					'post_content' => $content,
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
			$permalinks[ $filename ] = get_permalink( $page_id );
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
				$href     = html_entity_decode( $matches[2], ENT_QUOTES );
				$parts    = explode( '#', $href, 2 );
				$filename = basename( strtok( $parts[0], '?' ) ?: $parts[0] );
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
				$classes = preg_split( '/\s+/', trim( $matches[2] ) ) ?: array();
				$classes = array_values(
					array_filter(
						$classes,
						static fn ( string $class ): bool => 'active' !== $class
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
	 * Convert shared header chrome while preserving static navigation as native blocks.
	 *
	 * @param string $html Header HTML fragment.
	 * @return string
	 */
	private static function convert_header_fragment( string $html ): string {
		$doc    = self::load_fragment_document( $html );
		$header = self::sole_child_element( $doc );
		if ( ! $header instanceof DOMElement || 'header' !== strtolower( $header->tagName ) ) {
			return self::convert_fragment( $html );
		}

		$header_children = self::direct_element_children( $header );
		if ( 1 !== count( $header_children ) || 'div' !== strtolower( $header_children[0]->tagName ) ) {
			return self::convert_fragment( $html );
		}

		$inner          = $header_children[0];
		$inner_children = self::direct_element_children( $inner );
		if ( 2 !== count( $inner_children ) || 'a' !== strtolower( $inner_children[0]->tagName ) || 'nav' !== strtolower( $inner_children[1]->tagName ) ) {
			return self::convert_fragment( $html );
		}

		$navigation_blocks = self::convert_static_navigation_html( self::node_html( $doc, $inner_children[1] ) );
		if ( null === $navigation_blocks ) {
			return self::convert_fragment( $html );
		}

		$inner_blocks = self::html_block( self::node_html( $doc, $inner_children[0] ) ) . $navigation_blocks;
		return self::group_block( self::group_block( $inner_blocks, $inner->getAttribute( 'class' ) ), $header->getAttribute( 'class' ), 'header' );
	}

	/**
	 * Convert shared footer chrome while preserving simple footer links as native blocks.
	 *
	 * @param string $html Footer HTML fragment.
	 * @return string
	 */
	private static function convert_footer_fragment( string $html ): string {
		$doc    = self::load_fragment_document( $html );
		$footer = self::sole_child_element( $doc );
		if ( ! $footer instanceof DOMElement || 'footer' !== strtolower( $footer->tagName ) ) {
			return self::convert_fragment( $html );
		}

		$footer_children = self::direct_element_children( $footer );
		if ( 1 !== count( $footer_children ) || 'div' !== strtolower( $footer_children[0]->tagName ) ) {
			return self::convert_fragment( $html );
		}

		$container          = $footer_children[0];
		$container_children = self::direct_element_children( $container );
		if ( 1 !== count( $container_children ) || 'div' !== strtolower( $container_children[0]->tagName ) ) {
			return self::convert_fragment( $html );
		}

		$row          = $container_children[0];
		$row_children = self::direct_element_children( $row );
		if ( 2 !== count( $row_children ) || 'div' !== strtolower( $row_children[0]->tagName ) || 'ul' !== strtolower( $row_children[1]->tagName ) ) {
			return self::convert_fragment( $html );
		}

		$navigation_blocks = self::convert_static_navigation_html( self::navigation_html_from_list( $doc, $row_children[1] ) );
		if ( null === $navigation_blocks ) {
			return self::convert_fragment( $html );
		}

		$row_blocks       = self::html_block( self::node_html( $doc, $row_children[0] ) ) . $navigation_blocks;
		$container_blocks = self::group_block( $row_blocks, $row->getAttribute( 'class' ) );
		return self::group_block( self::group_block( $container_blocks, $container->getAttribute( 'class' ) ), $footer->getAttribute( 'class' ), 'footer' );
	}

	/**
	 * Convert static navigation markup through BFB, requiring native navigation output.
	 *
	 * @param string $html Navigation HTML.
	 * @return string|null
	 */
	private static function convert_static_navigation_html( string $html ): ?string {
		$blocks = self::convert_fragment( $html );
		if ( ! str_contains( $blocks, '<!-- wp:navigation' ) || str_contains( $blocks, '<!-- wp:html' ) ) {
			return null;
		}

		return $blocks;
	}

	/**
	 * Wrap a static list in nav markup so BFB can emit native navigation blocks.
	 *
	 * @param DOMDocument $doc  DOM document.
	 * @param DOMElement  $list List element.
	 * @return string
	 */
	private static function navigation_html_from_list( DOMDocument $doc, DOMElement $list ): string {
		$class = trim( $list->getAttribute( 'class' ) );
		return '<nav' . ( '' !== $class ? ' class="' . esc_attr( $class ) . '"' : '' ) . '>' . self::node_html( $doc, $list ) . '</nav>';
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
		$attrs     = array();
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
		$css = array();
		foreach ( $document->stylesheet_hrefs() as $href ) {
			$path = realpath( trailingslashit( $site_dir ) . ltrim( strtok( $href, '?' ) ?: $href, '/' ) );
			if ( false === $path || ! str_starts_with( $path, realpath( $site_dir ) ?: $site_dir ) || ! is_readable( $path ) ) {
				continue;
			}

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
	 * Convert HTML to block markup.
	 *
	 * @param string $html HTML fragment.
	 * @return string
	 */
	private static function convert_fragment( string $html ): string {
		if ( '' === trim( $html ) ) {
			return '';
		}

		$blocks = bfb_convert( $html, 'html', 'blocks' );
		return '' === $blocks ? '<!-- wp:html -->' . "\n" . $html . "\n" . '<!-- /wp:html -->' : $blocks;
	}

	/**
	 * Ensure theme directories exist.
	 *
	 * @param string $theme_dir Theme directory.
	 * @return true|WP_Error
	 */
	private static function ensure_dirs( string $theme_dir ) {
		foreach ( array( $theme_dir, $theme_dir . '/templates', $theme_dir . '/parts', $theme_dir . '/assets' ) as $dir ) {
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
		$result = file_put_contents( $path, $content );
		if ( false === $result ) {
			return new WP_Error( 'static_site_importer_write_failed', sprintf( 'Failed to write file: %s', $path ) );
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
	private static function style_css( string $theme_name, string $css ): string {
		return "/*\nTheme Name: " . $theme_name . "\nAuthor: Static Site Importer\nDescription: Imported from static HTML using Block Format Bridge.\nVersion: 0.1.0\nRequires at least: 6.6\n*/\n\n" . $css . "\n";
	}

	/**
	 * Build functions.php.
	 *
	 * @param string $theme_slug Theme slug.
	 * @return string
	 */
	private static function functions_php( string $theme_slug ): string {
		$style_handle = sanitize_key( $theme_slug ) . '-style';
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
	 * @return string
	 */
	private static function theme_json( string $theme_name ): string {
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

		return wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
	}

	/**
	 * Build a template that renders imported page content.
	 *
	 * @param string $background_blocks Background decoration blocks.
	 * @return string
	 */
	private static function content_template( string $background_blocks ): string {
		return trim(
			'<!-- wp:template-part {"slug":"header","tagName":"header"} /-->' . "\n\n" .
			$background_blocks . "\n\n" .
			'<!-- wp:post-content /-->' . "\n\n" .
			'<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->'
		) . "\n";
	}
}

<?php
/**
 * Static HTML document parser.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Extracts usable fragments from a static HTML document.
 */
class Static_Site_Importer_Document {

	/**
	 * Source HTML.
	 *
	 * @var string
	 */
	private string $html;

	/**
	 * DOM document.
	 *
	 * @var DOMDocument
	 */
	private DOMDocument $dom;

	/**
	 * Constructor.
	 *
	 * @param string $html Source HTML.
	 */
	public function __construct( string $html ) {
		$this->html = $html;
		$this->dom  = new DOMDocument();

		$previous = libxml_use_internal_errors( true );
		$this->dom->loadHTML( $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
	}

	/**
	 * Create a document object from a file.
	 *
	 * @param string $path HTML file path.
	 * @return self|WP_Error
	 */
	public static function from_file( string $path ) {
		if ( ! is_readable( $path ) ) {
			return new WP_Error( 'static_site_importer_unreadable_file', sprintf( 'HTML file is not readable: %s', $path ) );
		}

		$html = file_get_contents( $path );
		if ( false === $html || '' === trim( $html ) ) {
			return new WP_Error( 'static_site_importer_empty_file', sprintf( 'HTML file is empty: %s', $path ) );
		}

		return new self( $html );
	}

	/**
	 * Get the document title.
	 *
	 * @return string
	 */
	public function title(): string {
		$nodes = $this->dom->getElementsByTagName( 'title' );
		if ( $nodes->length > 0 ) {
			return trim( (string) $nodes->item( 0 )->textContent );
		}

		$nodes = $this->dom->getElementsByTagName( 'h1' );
		if ( $nodes->length > 0 ) {
			return trim( (string) $nodes->item( 0 )->textContent );
		}

		return 'Imported Static Site';
	}

	/**
	 * Get CSS from inline style tags.
	 *
	 * @return string
	 */
	public function inline_css(): string {
		$css = array();
		foreach ( $this->dom->getElementsByTagName( 'style' ) as $style ) {
			$css[] = trim( (string) $style->textContent );
		}

		return trim( implode( "\n\n", array_filter( $css ) ) );
	}

	/**
	 * Get linked stylesheet hrefs.
	 *
	 * @return array<int, string>
	 */
	public function stylesheet_hrefs(): array {
		$hrefs = array();
		foreach ( $this->dom->getElementsByTagName( 'link' ) as $link ) {
			if ( ! $link instanceof DOMElement ) {
				continue;
			}

			$rel  = strtolower( trim( $link->getAttribute( 'rel' ) ) );
			$href = trim( $link->getAttribute( 'href' ) );
			if ( '' === $href || ! str_contains( ' ' . $rel . ' ', ' stylesheet ' ) ) {
				continue;
			}

			$hrefs[] = $href;
		}

		return array_values( array_unique( $hrefs ) );
	}

	/**
	 * Get inline JavaScript from script tags without src attributes.
	 *
	 * @return string
	 */
	public function inline_js(): string {
		$js = array();
		foreach ( $this->dom->getElementsByTagName( 'script' ) as $script ) {
			if ( $script->hasAttribute( 'src' ) ) {
				continue;
			}

			$js[] = trim( (string) $script->textContent );
		}

		return trim( implode( "\n\n", array_filter( $js ) ) );
	}

	/**
	 * Extract site fragments for theme generation.
	 *
	 * @return array{background:string,header:string,main:string,footer:string}
	 */
	public function fragments(): array {
		$body = $this->first_element( 'body' );
		$root = $body instanceof DOMElement ? $body : $this->dom->documentElement;

		$nav    = $this->first_element( 'nav' );
		$header = $this->first_element( 'header' );
		$footer = $this->first_element( 'footer' );

		$header_parts = array();
		if ( $nav instanceof DOMElement && $header instanceof DOMElement && $this->is_leading_sibling( $root, $nav, $header ) ) {
			$header_parts[] = $this->outer_html( $nav );
		}

		if ( $header instanceof DOMElement ) {
			$header_parts[] = $this->outer_html( $header );
		} elseif ( $nav instanceof DOMElement ) {
			$header_parts[] = $this->outer_html( $nav );
		}

		$header_html = implode( "\n", $header_parts );

		$footer_html = $footer instanceof DOMElement ? $this->outer_html( $footer ) : '';

		$main_parts = array();
		$background = array();

		foreach ( iterator_to_array( $root->childNodes ) as $child ) {
			if ( ! $child instanceof DOMElement ) {
				continue;
			}

			$tag = strtolower( $child->tagName );
			if ( in_array( $tag, array( 'style', 'script' ), true ) ) {
				continue;
			}

			if ( $this->same_node( $child, $nav ) || $this->same_node( $child, $header ) || $this->same_node( $child, $footer ) ) {
				continue;
			}

			if ( $this->looks_like_background_chrome( $child ) ) {
				$background[] = $this->outer_html( $child );
				continue;
			}

			$main_parts[] = $this->outer_html( $child );
		}

		return array(
			'background' => trim( implode( "\n", $background ) ),
			'header'     => trim( $header_html ),
			'main'       => trim( implode( "\n", $main_parts ) ),
			'footer'     => trim( $footer_html ),
		);
	}

	/**
	 * Find the first tag in the document.
	 *
	 * @param string $tag Tag name.
	 * @return DOMElement|null
	 */
	private function first_element( string $tag ): ?DOMElement {
		$nodes = $this->dom->getElementsByTagName( $tag );
		$node  = $nodes->length > 0 ? $nodes->item( 0 ) : null;

		return $node instanceof DOMElement ? $node : null;
	}

	/**
	 * Serialize a DOM node.
	 *
	 * @param DOMNode $node Node.
	 * @return string
	 */
	private function outer_html( DOMNode $node ): string {
		$html = $this->dom->saveHTML( $node );
		return false === $html ? '' : $html;
	}

	/**
	 * Compare two DOM elements by identity.
	 *
	 * @param DOMElement      $left  Left node.
	 * @param DOMElement|null $right Right node.
	 * @return bool
	 */
	private function same_node( DOMElement $left, ?DOMElement $right ): bool {
		return $right instanceof DOMElement && $left->isSameNode( $right );
	}

	/**
	 * Check whether one element is a direct sibling before another under the page root.
	 *
	 * @param DOMElement $root   Page root element.
	 * @param DOMElement $before Candidate leading element.
	 * @param DOMElement $after  Candidate following element.
	 * @return bool
	 */
	private function is_leading_sibling( DOMElement $root, DOMElement $before, DOMElement $after ): bool {
		foreach ( iterator_to_array( $root->childNodes ) as $child ) {
			if ( ! $child instanceof DOMElement ) {
				continue;
			}

			if ( $child->isSameNode( $before ) ) {
				return true;
			}

			if ( $child->isSameNode( $after ) ) {
				return false;
			}
		}

		return false;
	}

	/**
	 * Detect decorative wrappers that should stay outside main content.
	 *
	 * @param DOMElement $node Node.
	 * @return bool
	 */
	private function looks_like_background_chrome( DOMElement $node ): bool {
		$class = ' ' . $node->getAttribute( 'class' ) . ' ';

		return str_contains( $class, ' bg-' ) || str_contains( $class, ' orb ' ) || str_contains( $class, ' grid-' );
	}
}

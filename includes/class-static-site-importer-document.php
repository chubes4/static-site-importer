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
		$this->dom = new DOMDocument();

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
		if ( ! is_file( $path ) ) {
			return new WP_Error( 'static_site_importer_unreadable_file', sprintf( 'HTML file is not readable: %s', $path ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local static-site HTML file selected for import.
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

		$footer             = $this->first_element( 'footer' );
		$main               = $this->first_element( 'main' );
		$effective_children = $this->effective_root_children( $root, $main );

		$header       = $this->first_plausible_global_header( $effective_children );
		$nav          = $this->first_plausible_global_nav( $effective_children, $header );
		$body_headers = $this->body_content_headers( $effective_children, $header, $nav );

		if ( $header instanceof DOMElement && $this->contains_same_node( $body_headers, $header ) ) {
			$header = null;
		}

		$header_parts = array();
		if ( $nav instanceof DOMElement && $header instanceof DOMElement && $this->is_leading_sibling_in( $effective_children, $nav, $header ) ) {
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
		$main_html  = $main instanceof DOMElement ? $this->inner_html( $main ) : '';

		foreach ( $effective_children as $child ) {
			$tag = strtolower( $child->tagName );
			if ( in_array( $tag, array( 'style', 'script' ), true ) ) {
				continue;
			}

			if ( $this->same_node( $child, $main ) ) {
				$main_parts[] = $main_html;
				continue;
			}

			if ( $this->same_node( $child, $nav ) || $this->same_node( $child, $header ) || $this->same_node( $child, $footer ) ) {
				continue;
			}

			if ( $this->looks_like_background_chrome( $child ) ) {
				$background[] = $this->outer_html( $child );
				continue;
			}

			if ( ! $main instanceof DOMElement || $this->contains_same_node( $body_headers, $child ) ) {
				$main_parts[] = $this->outer_html( $child );
			}
		}

		if ( $main instanceof DOMElement && empty( $main_parts ) ) {
			$main_parts[] = $main_html;
		}

		return array(
			'background' => trim( implode( "\n", $background ) ),
			'header'     => trim( $header_html ),
			'main'       => trim( implode( "\n", $main_parts ) ),
			'footer'     => trim( $footer_html ),
		);
	}

	/**
	 * Resolve the effective list of body-level direct children, unwrapping a
	 * single generic page wrapper that holds pre-main chrome.
	 *
	 * Some static sites wrap pre-main chrome (nav + hero header) inside a generic
	 * `<div class="page">` sibling of `<main>`. Treating that wrapper opaquely
	 * drops the chrome from fragment decomposition. Unwrapping it surfaces the
	 * inner nav/header as effective body-level siblings while leaving any
	 * direct-child main/footer alone.
	 *
	 * @param DOMElement      $root Page root element (typically <body>).
	 * @param DOMElement|null $main Main landmark element if present.
	 * @return DOMElement[]
	 */
	private function effective_root_children( DOMElement $root, ?DOMElement $main ): array {
		$effective = array();

		foreach ( iterator_to_array( $root->childNodes ) as $child ) {
			if ( ! $child instanceof DOMElement ) {
				continue;
			}

			if ( $this->is_unwrappable_page_wrapper( $child, $main ) ) {
				foreach ( iterator_to_array( $child->childNodes ) as $grandchild ) {
					if ( $grandchild instanceof DOMElement ) {
						$effective[] = $grandchild;
					}
				}

				continue;
			}

			$effective[] = $child;
		}

		return $effective;
	}

	/**
	 * Whether a body-level element is a generic page wrapper that should be
	 * unwrapped for fragment decomposition.
	 *
	 * The wrapper must be a plain `<div>` (no semantic landmark of its own),
	 * must not contain `<main>` (so we never flatten main's container), must
	 * not look like background chrome, and must contain at least one
	 * meaningful pre-main landmark (`<nav>` or `<header>`). This is intentionally
	 * narrow: it only unwraps wrappers that demonstrably hold chrome the
	 * decomposition would otherwise drop.
	 *
	 * @param DOMElement      $element Candidate wrapper.
	 * @param DOMElement|null $main    Main landmark element if present.
	 * @return bool
	 */
	private function is_unwrappable_page_wrapper( DOMElement $element, ?DOMElement $main ): bool {
		if ( 'div' !== strtolower( $element->tagName ) ) {
			return false;
		}

		if ( $main instanceof DOMElement && $this->is_descendant_of( $main, $element ) ) {
			return false;
		}

		if ( $this->looks_like_background_chrome( $element ) ) {
			return false;
		}

		$has_landmark = $element->getElementsByTagName( 'nav' )->length > 0
			|| $element->getElementsByTagName( 'header' )->length > 0;

		return $has_landmark;
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
	 * Find a header that is plausible reusable site chrome.
	 *
	 * @param DOMElement[] $effective_children Effective body-level direct children.
	 * @return DOMElement|null
	 */
	private function first_plausible_global_header( array $effective_children ): ?DOMElement {
		foreach ( $this->dom->getElementsByTagName( 'header' ) as $header ) {
			if ( $this->is_plausible_global_header( $effective_children, $header ) ) {
				return $header;
			}
		}

		return null;
	}

	/**
	 * Find a nav that is plausible reusable site chrome.
	 *
	 * @param DOMElement[]    $effective_children Effective body-level direct children.
	 * @param DOMElement|null $header             Selected header element.
	 * @return DOMElement|null
	 */
	private function first_plausible_global_nav( array $effective_children, ?DOMElement $header ): ?DOMElement {
		foreach ( $this->dom->getElementsByTagName( 'nav' ) as $nav ) {
			if ( $header instanceof DOMElement && $this->is_descendant_of( $nav, $header ) ) {
				return $nav;
			}

			if ( $this->contains_same_node( $effective_children, $nav ) || $this->has_global_chrome_signal( $nav ) ) {
				return $nav;
			}
		}

		return null;
	}

	/**
	 * Check whether a header looks like global site chrome rather than section content.
	 *
	 * @param DOMElement[] $effective_children Effective body-level direct children.
	 * @param DOMElement   $header             Header element.
	 * @return bool
	 */
	private function is_plausible_global_header( array $effective_children, DOMElement $header ): bool {
		if ( $this->has_global_chrome_signal( $header ) ) {
			return true;
		}

		return $this->contains_same_node( $effective_children, $header );
	}

	/**
	 * Collect effective body-level headers that should remain in page content.
	 *
	 * @param DOMElement[]    $effective_children Effective body-level direct children.
	 * @param DOMElement|null $header             Selected header element.
	 * @param DOMElement|null $nav                Selected nav element.
	 * @return DOMElement[]
	 */
	private function body_content_headers( array $effective_children, ?DOMElement $header, ?DOMElement $nav ): array {
		$body_headers = array();
		$seen_header  = false;

		foreach ( $effective_children as $child ) {
			if ( 'header' !== strtolower( $child->tagName ) ) {
				continue;
			}

			if ( $header instanceof DOMElement && $this->same_node( $child, $header ) ) {
				$seen_header = true;

				if ( $nav instanceof DOMElement && ! $this->has_global_chrome_signal( $child ) && $this->is_leading_sibling_in( $effective_children, $nav, $child ) ) {
					$body_headers[] = $child;
				}

				continue;
			}

			if ( $seen_header ) {
				$body_headers[] = $child;
			}
		}

		return $body_headers;
	}

	/**
	 * Check whether an array contains a DOM node.
	 *
	 * @param DOMElement[] $nodes     Nodes to search.
	 * @param DOMElement   $candidate Candidate element.
	 * @return bool
	 */
	private function contains_same_node( array $nodes, DOMElement $candidate ): bool {
		foreach ( $nodes as $node ) {
			if ( $this->same_node( $node, $candidate ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check whether an element carries explicit global-chrome signals.
	 *
	 * @param DOMElement $element Element.
	 * @return bool
	 */
	private function has_global_chrome_signal( DOMElement $element ): bool {
		$role = strtolower( trim( $element->getAttribute( 'role' ) ) );
		if ( 'banner' === $role || 'navigation' === $role ) {
			return true;
		}

		$classes = strtolower( $element->getAttribute( 'class' ) );
		if ( preg_match( '/(^|[\s_-])(site-header|site-nav|navbar|navigation|masthead|brand|branding)([\s_-]|$)/', $classes ) ) {
			return true;
		}

		return $element->getElementsByTagName( 'nav' )->length > 0;
	}

	/**
	 * Check whether a candidate is contained by another element.
	 *
	 * @param DOMElement $candidate Candidate element.
	 * @param DOMElement $ancestor  Ancestor element.
	 * @return bool
	 */
	private function is_descendant_of( DOMElement $candidate, DOMElement $ancestor ): bool {
		$node = $candidate->parentNode;
		while ( $node instanceof DOMNode ) {
			if ( $node->isSameNode( $ancestor ) ) {
				return true;
			}

			$node = $node->parentNode;
		}

		return false;
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
	 * Serialize a DOM element's child nodes.
	 *
	 * @param DOMElement $element Element.
	 * @return string
	 */
	private function inner_html( DOMElement $element ): string {
		$html = array();
		foreach ( iterator_to_array( $element->childNodes ) as $child ) {
			$html[] = $this->outer_html( $child );
		}

		return implode( '', $html );
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
	 * Check whether one element appears before another in an ordered effective sibling list.
	 *
	 * @param DOMElement[] $effective_children Ordered list of effective siblings.
	 * @param DOMElement   $before             Candidate leading element.
	 * @param DOMElement   $after              Candidate following element.
	 * @return bool
	 */
	private function is_leading_sibling_in( array $effective_children, DOMElement $before, DOMElement $after ): bool {
		foreach ( $effective_children as $child ) {
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

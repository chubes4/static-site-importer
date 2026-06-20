<?php
/**
 * Product-owned adapter for transformer APIs.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps SSI workflows insulated from transformer package implementation details.
 */
class Static_Site_Importer_Transformer_Adapter {

	/**
	 * Check whether the default Blocks Engine artifact compiler is available.
	 *
	 * @return bool
	 */
	public function supports_website_artifact_compile(): bool {
		return class_exists( '\Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler' );
	}

	/**
	 * Check whether the default Blocks Engine block-to-HTML bridge is available.
	 *
	 * @return bool
	 */
	public function supports_blocks_to_html(): bool {
		return class_exists( '\Automattic\BlocksEngine\PhpTransformer\FormatBridge\FormatBridge' );
	}

	/**
	 * Compile a website artifact into the BAC-compatible envelope SSI consumes.
	 *
	 * @param array<string,mixed> $artifact Website artifact bundle.
	 * @param array<string,mixed> $options  Compiler options.
	 * @return array<string,mixed>|WP_Error
	 */
	public function compile_website_artifact( array $artifact, array $options = array() ) {
		unset( $options );

		if ( ! $this->supports_website_artifact_compile() ) {
			return new WP_Error( 'static_site_importer_missing_transformer', 'Blocks Engine php-transformer is required to import a website artifact.' );
		}

		$compiler = new \Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler();
		$result   = $compiler->compile( $artifact );
		if ( is_object( $result ) && method_exists( $result, 'toArray' ) ) {
			$result = $result->toArray();
		}

		if ( is_array( $result ) ) {
			return $this->transformer_result_to_bac_result( $result );
		}

		return new WP_Error( 'static_site_importer_transformer_compile_failed', 'Blocks Engine php-transformer did not return a compiler result.' );
	}

	/**
	 * Map the generic php-transformer result into SSI's current BAC-compatible envelope.
	 *
	 * @param array<string,mixed> $result TransformerResult::toArray() output.
	 * @return array<string,mixed>
	 */
	private function transformer_result_to_bac_result( array $result ): array {
		$source_reports = isset( $result['source_reports'] ) && is_array( $result['source_reports'] ) ? $result['source_reports'] : array();
		$source_report  = isset( $source_reports['artifact'] ) && is_array( $source_reports['artifact'] ) ? $source_reports['artifact'] : array();
		$compiled_site  = isset( $source_reports['compiled_site'] ) && is_array( $source_reports['compiled_site'] ) ? $source_reports['compiled_site'] : array();
		$entry_path     = isset( $source_report['entry_path'] ) && is_scalar( $source_report['entry_path'] ) ? (string) $source_report['entry_path'] : '';
		$provenance     = isset( $result['provenance'][0] ) && is_array( $result['provenance'][0] ) ? $result['provenance'][0] : array();
		$blocks         = isset( $result['blocks'] ) && is_array( $result['blocks'] ) ? $result['blocks'] : array();
		$serialized     = isset( $result['serialized_blocks'] ) && is_scalar( $result['serialized_blocks'] ) ? (string) $result['serialized_blocks'] : '';
		$documents      = $this->documents_from_transformer_result( $result, $compiled_site );
		$products       = $this->products_manifest_from_transformer_reports( $result, $compiled_site );

		$compiled = array(
			'schema'              => 'block-artifact-compiler/result/v1',
			'status'              => isset( $result['status'] ) && is_scalar( $result['status'] ) ? (string) $result['status'] : 'failed',
			'input'               => array(
				'schema'          => isset( $source_report['schema'] ) && is_scalar( $source_report['schema'] ) ? (string) $source_report['schema'] : 'blocks-engine/php-transformer/site-artifact/v1',
				'entry_path'      => $entry_path,
				'entrypoints'     => isset( $source_report['entrypoints'] ) && is_array( $source_report['entrypoints'] ) ? $source_report['entrypoints'] : array(),
				'file_count'      => (int) ( $source_report['file_count'] ?? 0 ),
				'accepted_count'  => (int) ( $source_report['accepted_count'] ?? 0 ),
				'rejected_count'  => (int) ( $source_report['rejected_count'] ?? 0 ),
				'bytes'           => (int) ( $source_report['bytes'] ?? 0 ),
				'files_by_kind'   => isset( $source_report['files_by_kind'] ) && is_array( $source_report['files_by_kind'] ) ? $source_report['files_by_kind'] : array(),
				'files_by_role'   => isset( $source_report['files_by_role'] ) && is_array( $source_report['files_by_role'] ) ? $source_report['files_by_role'] : array(),
				'files_by_mime'   => isset( $source_report['files_by_mime'] ) && is_array( $source_report['files_by_mime'] ) ? $source_report['files_by_mime'] : array(),
				'original_schema' => isset( $source_report['original_schema'] ) && is_scalar( $source_report['original_schema'] ) ? (string) $source_report['original_schema'] : '',
				'source_report'   => $source_report,
			),
			'wordpress_artifacts' => array(
				'block_markup' => $serialized,
				'blocks'       => $blocks,
				'block_tree'   => $this->block_tree_report( $blocks ),
				'block_types'  => isset( $result['block_types'] ) && is_array( $result['block_types'] ) ? $result['block_types'] : array(),
				'components'   => isset( $result['components'] ) && is_array( $result['components'] ) ? $result['components'] : array(),
				'document_metadata' => $this->document_metadata_from_compiled_site_report( $compiled_site ),
				'documents'    => $documents,
				'files'        => isset( $result['assets'] ) && is_array( $result['assets'] ) ? $result['assets'] : array(),
				'site'         => $this->site_from_compiled_site_report( $compiled_site, $documents ),
				'template_parts' => $this->template_parts_from_compiled_site_report( $compiled_site ),
				'visual_repair' => $this->visual_repair_from_compiled_site_report( $compiled_site ),
			),
			'provenance'          => array(
				'source_hash' => isset( $provenance['source_hash'] ) && is_scalar( $provenance['source_hash'] ) ? (string) $provenance['source_hash'] : (string) ( $source_report['source_hash'] ?? '' ),
				'source'      => '' !== $entry_path ? $entry_path : 'website_artifact',
			),
			'diagnostics'         => isset( $result['diagnostics'] ) && is_array( $result['diagnostics'] ) ? $result['diagnostics'] : array(),
			'bfb_report'          => array(
				'status'            => isset( $result['status'] ) && is_scalar( $result['status'] ) ? (string) $result['status'] : 'failed',
				'serialized_blocks' => $serialized,
				'diagnostics'       => isset( $result['diagnostics'] ) && is_array( $result['diagnostics'] ) ? $result['diagnostics'] : array(),
				'fallbacks'         => isset( $result['fallbacks'] ) && is_array( $result['fallbacks'] ) ? $result['fallbacks'] : array(),
			),
		);

		if ( ! empty( $products ) ) {
			$compiled['products_manifest'] = $products;
		}

		return $compiled;
	}

	/**
	 * Extract SSI products from generic compiled-site/document reports.
	 *
	 * @param array<string,mixed> $result        Transformer result array.
	 * @param array<string,mixed> $compiled_site Generic compiled-site report.
	 * @return array<int,array<string,mixed>>
	 */
	private function products_manifest_from_transformer_reports( array $result, array $compiled_site ): array {
		$candidates = array();
		foreach ( array( $compiled_site['products'] ?? null, $compiled_site['commerce']['products'] ?? null, $result['products'] ?? null, $result['commerce']['products'] ?? null ) as $rows ) {
			if ( is_array( $rows ) ) {
				$candidates = array_merge( $candidates, $rows );
			}
		}

		foreach ( isset( $compiled_site['pages'] ) && is_array( $compiled_site['pages'] ) ? $compiled_site['pages'] : array() as $page ) {
			if ( is_array( $page ) && $this->is_product_report_row( $page ) ) {
				$candidates[] = $page;
			}
		}

		foreach ( isset( $result['documents'] ) && is_array( $result['documents'] ) ? $result['documents'] : array() as $document ) {
			if ( is_array( $document ) && $this->is_product_report_row( $document ) ) {
				$candidates[] = $document;
			}
		}

		$products = array();
		$seen     = array();
		foreach ( $candidates as $candidate ) {
			if ( ! is_array( $candidate ) ) {
				continue;
			}

			$product = $this->normalize_product_report_row( $candidate );
			if ( empty( $product ) || isset( $seen[ $product['slug'] ] ) ) {
				continue;
			}

			$seen[ $product['slug'] ] = true;
			$products[]              = $product;
		}

		return $products;
	}

	/**
	 * Check whether a generic report row declares product content.
	 *
	 * @param array<string,mixed> $row Generic report row.
	 * @return bool
	 */
	private function is_product_report_row( array $row ): bool {
		foreach ( array( 'post_type', 'type', 'kind' ) as $key ) {
			if ( isset( $row[ $key ] ) && is_scalar( $row[ $key ] ) && 'product' === strtolower( (string) $row[ $key ] ) ) {
				return true;
			}
		}

		$metadata = isset( $row['metadata'] ) && is_array( $row['metadata'] ) ? $row['metadata'] : array();
		return isset( $metadata['post_type'] ) && is_scalar( $metadata['post_type'] ) && 'product' === strtolower( (string) $metadata['post_type'] );
	}

	/**
	 * Normalize one generic product report row to SSI's products manifest contract.
	 *
	 * @param array<string,mixed> $row Generic report row.
	 * @return array<string,mixed>
	 */
	private function normalize_product_report_row( array $row ): array {
		$metadata = isset( $row['metadata'] ) && is_array( $row['metadata'] ) ? $row['metadata'] : array();
		$data     = array_merge( $metadata, $row );

		$name          = $this->scalar_string( $data, array( 'name', 'title' ) );
		$slug          = $this->sanitize_slug( $this->scalar_string( $data, array( 'slug' ) ) );
		$regular_price = $this->price_string( $this->scalar_string( $data, array( 'regular_price', 'price' ) ) );
		if ( '' === $name || '' === $slug || '' === $regular_price ) {
			return array();
		}

		$product = array(
			'name'          => $name,
			'slug'          => $slug,
			'regular_price' => $regular_price,
		);

		foreach ( array( 'sale_price', 'description', 'short_description', 'image', 'status', 'stock_status' ) as $field ) {
			$value = $this->scalar_string( $data, array( $field ) );
			if ( '' !== $value ) {
				$product[ $field ] = 'sale_price' === $field ? $this->price_string( $value ) : $value;
			}
		}

		foreach ( array( 'categories', 'source_selectors' ) as $field ) {
			if ( isset( $data[ $field ] ) && is_array( $data[ $field ] ) ) {
				$product[ $field ] = array_values( array_filter( $data[ $field ], 'is_string' ) );
			}
		}

		if ( isset( $data['stock_quantity'] ) && is_numeric( $data['stock_quantity'] ) ) {
			$product['stock_quantity'] = (int) $data['stock_quantity'];
		}

		return array_filter( $product, static fn ( $value ): bool => '' !== $value && array() !== $value );
	}

	/**
	 * Return the first scalar string value for candidate keys.
	 *
	 * @param array<string,mixed> $data Data row.
	 * @param array<int,string>   $keys Candidate keys.
	 * @return string
	 */
	private function scalar_string( array $data, array $keys ): string {
		foreach ( $keys as $key ) {
			if ( isset( $data[ $key ] ) && is_scalar( $data[ $key ] ) && '' !== trim( (string) $data[ $key ] ) ) {
				return trim( (string) $data[ $key ] );
			}
		}

		return '';
	}

	/**
	 * Normalize a numeric price into SSI's decimal string contract.
	 *
	 * @param string $value Raw price.
	 * @return string
	 */
	private function price_string( string $value ): string {
		if ( '' === trim( $value ) || ! is_numeric( $value ) ) {
			return '';
		}

		return number_format( (float) $value, 2, '.', '' );
	}

	/**
	 * Convert generic compiled-site document metadata into SSI's BAC metadata shape.
	 *
	 * @param array<string,mixed> $compiled_site Generic compiled-site report.
	 * @return array<string,mixed>
	 */
	private function document_metadata_from_compiled_site_report( array $compiled_site ): array {
		$page = $this->entrypoint_compiled_site_page( $compiled_site );
		if ( empty( $page ) ) {
			return array();
		}

		$html = isset( $page['html'] ) && is_scalar( $page['html'] ) ? (string) $page['html'] : '';
		if ( '' === trim( $html ) ) {
			return array();
		}

		$metadata = $this->html_document_metadata( $html );
		$metadata['schema']      = 'block-artifact-compiler/document-metadata/v1';
		$metadata['source_path'] = isset( $page['source_path'] ) && is_scalar( $page['source_path'] ) ? (string) $page['source_path'] : '';

		return $metadata;
	}

	/**
	 * Return the compiled-site entrypoint page, falling back to the first page.
	 *
	 * @param array<string,mixed> $compiled_site Generic compiled-site report.
	 * @return array<string,mixed>
	 */
	private function entrypoint_compiled_site_page( array $compiled_site ): array {
		$pages = isset( $compiled_site['pages'] ) && is_array( $compiled_site['pages'] ) ? $compiled_site['pages'] : array();
		foreach ( $pages as $page ) {
			if ( is_array( $page ) && ! empty( $page['entrypoint'] ) ) {
				return $page;
			}
		}

		return isset( $pages[0] ) && is_array( $pages[0] ) ? $pages[0] : array();
	}

	/**
	 * Extract title/meta/link/script rows from a full HTML document.
	 *
	 * @param string $html Full HTML document.
	 * @return array<string,mixed>
	 */
	private function html_document_metadata( string $html ): array {
		$dom      = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$dom->loadHTML( $html );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$title_nodes = $dom->getElementsByTagName( 'title' );
		$title       = $title_nodes->length > 0 ? trim( (string) $title_nodes->item( 0 )->textContent ) : '';

		$metadata = array(
			'title'   => $title,
			'meta'    => array(),
			'links'   => array(),
			'scripts' => array(),
		);

		foreach ( $dom->getElementsByTagName( 'meta' ) as $node ) {
			$row = $this->html_attributes( $node, array( 'charset', 'name', 'property', 'http-equiv', 'content' ) );
			if ( isset( $row['http-equiv'] ) ) {
				$row['http_equiv'] = $row['http-equiv'];
				unset( $row['http-equiv'] );
			}
			if ( ! empty( $row ) ) {
				$metadata['meta'][] = $row;
			}
		}

		foreach ( $dom->getElementsByTagName( 'link' ) as $node ) {
			$row = $this->html_attributes( $node, array( 'rel', 'href', 'as', 'type', 'media', 'crossorigin', 'integrity' ) );
			if ( ! empty( $row ) ) {
				$metadata['links'][] = $row;
			}
		}

		foreach ( $dom->getElementsByTagName( 'script' ) as $node ) {
			$row = $this->html_attributes( $node, array( 'src', 'type', 'crossorigin', 'integrity' ) );
			if ( $node->hasAttribute( 'defer' ) ) {
				$row['defer'] = true;
			}
			if ( $node->hasAttribute( 'async' ) ) {
				$row['async'] = true;
			}
			if ( '' !== trim( (string) $node->textContent ) && ! isset( $row['src'] ) ) {
				$row['inline'] = array(
					'bytes' => strlen( (string) $node->textContent ),
					'hash'  => hash( 'sha256', (string) $node->textContent ),
				);
			}
			$row['placement'] = $this->node_is_inside_head( $node ) ? 'head' : 'body';
			if ( count( $row ) > 1 || isset( $row['src'] ) || isset( $row['inline'] ) ) {
				$metadata['scripts'][] = $row;
			}
		}

		return $metadata;
	}

	/**
	 * Read selected scalar attributes from an element.
	 *
	 * @param DOMElement        $node Element.
	 * @param array<int,string> $names Attribute names.
	 * @return array<string,string>
	 */
	private function html_attributes( DOMElement $node, array $names ): array {
		$attributes = array();
		foreach ( $names as $name ) {
			if ( $node->hasAttribute( $name ) && '' !== trim( $node->getAttribute( $name ) ) ) {
				$attributes[ $name ] = trim( $node->getAttribute( $name ) );
			}
		}

		return $attributes;
	}

	/**
	 * Check whether a node is within the document head.
	 *
	 * @param DOMNode $node Node.
	 * @return bool
	 */
	private function node_is_inside_head( DOMNode $node ): bool {
		$parent = $node->parentNode;
		while ( $parent instanceof DOMNode ) {
			if ( $parent instanceof DOMElement && 'head' === strtolower( $parent->tagName ) ) {
				return true;
			}
			$parent = $parent->parentNode;
		}

		return false;
	}

	/**
	 * Convert generic compiled-site template parts into BAC template part artifacts.
	 *
	 * @param array<string,mixed> $compiled_site Generic compiled-site report.
	 * @return array<int,array<string,mixed>>
	 */
	private function template_parts_from_compiled_site_report( array $compiled_site ): array {
		$template_parts = array();
		foreach ( isset( $compiled_site['template_parts'] ) && is_array( $compiled_site['template_parts'] ) ? $compiled_site['template_parts'] : array() as $template_part ) {
			if ( ! is_array( $template_part ) || ! isset( $template_part['block_markup'] ) || '' === trim( (string) $template_part['block_markup'] ) ) {
				continue;
			}

			$template_parts[] = array_filter(
				array(
					'source_path'  => isset( $template_part['source_path'] ) && is_scalar( $template_part['source_path'] ) ? (string) $template_part['source_path'] : '',
					'slug'         => isset( $template_part['slug'] ) && is_scalar( $template_part['slug'] ) ? $this->sanitize_slug( (string) $template_part['slug'] ) : '',
					'title'        => isset( $template_part['title'] ) && is_scalar( $template_part['title'] ) ? (string) $template_part['title'] : '',
					'area'         => isset( $template_part['area'] ) && is_scalar( $template_part['area'] ) ? (string) $template_part['area'] : '',
					'body_format'  => isset( $template_part['body_format'] ) && is_scalar( $template_part['body_format'] ) ? (string) $template_part['body_format'] : '',
					'block_markup' => (string) $template_part['block_markup'],
				),
				static fn ( $value ): bool => null !== $value && '' !== $value
			);
		}

		if ( ! empty( $template_parts ) ) {
			return $template_parts;
		}

		$page = $this->entrypoint_compiled_site_page( $compiled_site );
		$html = isset( $page['html'] ) && is_scalar( $page['html'] ) ? (string) $page['html'] : '';
		if ( '' === trim( $html ) ) {
			return array();
		}

		foreach ( array( 'header', 'footer' ) as $area ) {
			$markup = $this->template_part_markup_from_html( $html, $area );
			if ( '' === trim( $markup ) ) {
				continue;
			}

			$template_parts[] = array(
				'source_path'  => isset( $page['source_path'] ) && is_scalar( $page['source_path'] ) ? (string) $page['source_path'] : '',
				'slug'         => $area,
				'area'         => $area,
				'body_format'  => 'html',
				'block_markup' => $markup,
			);
		}

		return $template_parts;
	}

	/**
	 * Build a simple block template part from a full HTML document landmark.
	 *
	 * @param string $html Full HTML document.
	 * @param string $tag  Landmark tag name.
	 * @return string Serialized block markup.
	 */
	private function template_part_markup_from_html( string $html, string $tag ): string {
		$dom      = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$dom->loadHTML( $html );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$nodes = $dom->getElementsByTagName( $tag );
		if ( 0 === $nodes->length ) {
			return '';
		}

		$node = $nodes->item( 0 );
		if ( ! $node instanceof DOMNode ) {
			return '';
		}

		$markup = '';
		foreach ( $node->childNodes as $child ) {
			$child_markup = $dom->saveHTML( $child );
			if ( is_string( $child_markup ) ) {
				$markup .= $child_markup;
			}
		}

		$markup = trim( $markup );
		if ( '' === $markup ) {
			$text = trim( (string) $node->textContent );
			if ( '' === $text ) {
				return '';
			}

			$markup = htmlspecialchars( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}

		$block_name = 'group';
		$tag_name   = 'footer' === $tag ? 'footer' : 'header';
		return '<!-- wp:' . $block_name . ' {"tagName":"' . $tag_name . '"} -->' . "\n" . '<' . $tag_name . ' class="wp-block-group">' . $markup . '</' . $tag_name . '>' . "\n" . '<!-- /wp:' . $block_name . ' -->';
	}

	/**
	 * Convert generic compiled-site visual repair CSS into BAC visual repair artifacts.
	 *
	 * @param array<string,mixed> $compiled_site Generic compiled-site report.
	 * @return array<string,mixed>
	 */
	private function visual_repair_from_compiled_site_report( array $compiled_site ): array {
		$visual_repair = isset( $compiled_site['visual_repair'] ) && is_array( $compiled_site['visual_repair'] ) ? $compiled_site['visual_repair'] : array();
		$css           = isset( $visual_repair['css'] ) && is_scalar( $visual_repair['css'] ) ? trim( (string) $visual_repair['css'] ) : '';
		if ( '' === $css ) {
			return array();
		}

		return array(
			'styles' => array(
				array(
					'target'  => 'frontend',
					'content' => $css,
				),
				array(
					'target'  => 'editor',
					'content' => $css,
				),
			),
		);
	}

	/**
	 * Convert generic compiled-site pages into SSI's BAC compiled-site page shape.
	 *
	 * @param array<string,mixed>            $compiled_site Generic compiled-site report.
	 * @param array<int,array<string,mixed>> $documents     Materializable document artifacts.
	 * @return array<string,mixed>
	 */
	private function site_from_compiled_site_report( array $compiled_site, array $documents ): array {
		$pages            = array();
		$document_sources = array();
		foreach ( $documents as $document ) {
			if ( isset( $document['source_path'] ) && is_scalar( $document['source_path'] ) && '' !== (string) $document['source_path'] ) {
				$document_sources[ (string) $document['source_path'] ] = true;
			}
		}

		foreach ( isset( $compiled_site['pages'] ) && is_array( $compiled_site['pages'] ) ? $compiled_site['pages'] : array() as $page ) {
			if ( ! is_array( $page ) ) {
				continue;
			}

			$source_path = isset( $page['source_path'] ) && is_scalar( $page['source_path'] ) ? (string) $page['source_path'] : '';
			if ( '' === $source_path ) {
				continue;
			}
			if ( empty( $document_sources[ $source_path ] ) ) {
				continue;
			}

			$slug    = isset( $page['slug'] ) && is_scalar( $page['slug'] ) && '' !== trim( (string) $page['slug'] ) ? $this->sanitize_slug( (string) $page['slug'] ) : $this->document_slug( $page, $source_path, ! empty( $page['entrypoint'] ) );
			$pages[] = array_filter(
				array(
					'source_path' => $source_path,
					'route_key'   => $slug,
					'slug'        => $slug,
					'post_type'   => 'page',
					'title'       => isset( $page['title'] ) && is_scalar( $page['title'] ) ? (string) $page['title'] : '',
					'entrypoint'  => ! empty( $page['entrypoint'] ),
				),
				static fn ( $value ): bool => null !== $value && '' !== $value
			);
		}

		return array_filter(
			array(
				'schema'      => 'block-artifact-compiler/compiled-site/v1',
				'pages'       => $pages,
				'assets'      => isset( $compiled_site['assets'] ) && is_array( $compiled_site['assets'] ) ? $compiled_site['assets'] : array(),
				'theme'       => isset( $compiled_site['theme'] ) && is_array( $compiled_site['theme'] ) ? $compiled_site['theme'] : array(),
				'source'      => 'blocks-engine/php-transformer/compiled-site/v1',
				'source_hash' => isset( $compiled_site['source_hash'] ) && is_scalar( $compiled_site['source_hash'] ) ? (string) $compiled_site['source_hash'] : '',
			),
			static fn ( $value ): bool => array() !== $value && '' !== $value
		);
	}

	/**
	 * Prefer compiled-site pages that include block markup, then include source documents.
	 *
	 * @param array<string,mixed> $result        Transformer result array.
	 * @param array<string,mixed> $compiled_site Generic compiled-site report.
	 * @return array<int,array<string,mixed>>
	 */
	private function documents_from_transformer_result( array $result, array $compiled_site ): array {
		$documents = array();
		foreach ( isset( $compiled_site['pages'] ) && is_array( $compiled_site['pages'] ) ? $compiled_site['pages'] : array() as $page ) {
			if ( ! is_array( $page ) ) {
				continue;
			}

			$source_path = isset( $page['source_path'] ) && is_scalar( $page['source_path'] ) ? (string) $page['source_path'] : '';
			if ( '' === $source_path ) {
				continue;
			}

			$block_markup = $this->block_markup_from_compiled_site_page( $page );
			if ( '' === trim( $block_markup ) ) {
				continue;
			}

			$slug        = isset( $page['slug'] ) && is_scalar( $page['slug'] ) && '' !== trim( (string) $page['slug'] ) ? $this->sanitize_slug( (string) $page['slug'] ) : $this->document_slug( $page, $source_path, ! empty( $page['entrypoint'] ) );
			$documents[] = array_filter(
				array(
					'source_path'  => $source_path,
					'slug'         => $slug,
					'route_key'    => $slug,
					'post_type'    => 'page',
					'title'        => isset( $page['title'] ) && is_scalar( $page['title'] ) ? (string) $page['title'] : '',
					'entrypoint'   => ! empty( $page['entrypoint'] ) ? '1' : '',
					'block_markup' => $block_markup,
				),
				static fn ( $value ): bool => null !== $value && '' !== $value
			);
		}

		foreach ( isset( $result['documents'] ) && is_array( $result['documents'] ) ? $result['documents'] : array() as $document ) {
			if ( is_array( $document ) ) {
				$documents[] = $document;
			}
		}

		return $documents;
	}

	/**
	 * Resolve importable block markup from a generic compiled-site page row.
	 *
	 * @param array<string,mixed> $page Generic compiled-site page row.
	 * @return string Serialized block markup.
	 */
	private function block_markup_from_compiled_site_page( array $page ): string {
		return isset( $page['block_markup'] ) && is_scalar( $page['block_markup'] ) ? (string) $page['block_markup'] : '';
	}

	/**
	 * Build a compact block tree report without depending on BAC helpers.
	 *
	 * @param array<int|string,mixed> $blocks Parsed blocks.
	 * @return array<string,int>
	 */
	private function block_tree_report( array $blocks ): array {
		$report = array(
			'block_count' => 0,
			'max_depth'   => 0,
		);
		$walk   = function ( array $items, int $depth ) use ( &$walk, &$report ): void {
			foreach ( $items as $block ) {
				if ( ! is_array( $block ) ) {
					continue;
				}

				++$report['block_count'];
				$report['max_depth'] = max( $report['max_depth'], $depth );
				if ( isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
					$walk( $block['innerBlocks'], $depth + 1 );
				}
			}
		};
		$walk( $blocks, 1 );

		return $report;
	}

	/**
	 * Resolve a WordPress page slug from document metadata.
	 *
	 * @param array<string,mixed> $document    Document artifact.
	 * @param string              $source_path Source path.
	 * @param bool                $entrypoint  Whether this is the entrypoint document.
	 * @return string
	 */
	private function document_slug( array $document, string $source_path, bool $entrypoint ): string {
		if ( isset( $document['slug'] ) && is_scalar( $document['slug'] ) && '' !== trim( (string) $document['slug'] ) ) {
			return $this->sanitize_slug( (string) $document['slug'] );
		}

		$basename = pathinfo( $source_path, PATHINFO_FILENAME );
		if ( $entrypoint && in_array( strtolower( $basename ), array( 'index', 'home', '' ), true ) ) {
			return 'home';
		}

		$slug = $this->sanitize_slug( $basename );
		return '' !== $slug ? $slug : 'page';
	}

	/**
	 * Sanitize a slug without requiring full WordPress bootstrap in adapter tests.
	 *
	 * @param string $value Raw slug value.
	 * @return string
	 */
	private function sanitize_slug( string $value ): string {
		if ( function_exists( 'sanitize_title' ) ) {
			return sanitize_title( $value );
		}

		return trim( strtolower( (string) preg_replace( '/[^a-z0-9]+/i', '-', $value ) ), '-' );
	}

	/**
	 * Summarize a BAC-compatible compiler result.
	 *
	 * @param array<string,mixed> $compiled Compiler result envelope.
	 * @return array<string,mixed>
	 */
	public function summarize_result( array $compiled ): array {
		$artifacts   = isset( $compiled['wordpress_artifacts'] ) && is_array( $compiled['wordpress_artifacts'] ) ? $compiled['wordpress_artifacts'] : array();
		$block_tree  = isset( $artifacts['block_tree'] ) && is_array( $artifacts['block_tree'] ) ? $artifacts['block_tree'] : array();
		$block_types = isset( $artifacts['block_types'] ) && is_array( $artifacts['block_types'] ) ? $artifacts['block_types'] : array();
		$components  = isset( $artifacts['components'] ) && is_array( $artifacts['components'] ) ? $artifacts['components'] : array();
		$files       = isset( $artifacts['files'] ) && is_array( $artifacts['files'] ) ? $artifacts['files'] : array();
		$diagnostics = isset( $compiled['diagnostics'] ) && is_array( $compiled['diagnostics'] ) ? $compiled['diagnostics'] : array();
		$source      = isset( $compiled['input']['source_report'] ) && is_array( $compiled['input']['source_report'] ) ? $compiled['input']['source_report'] : array();

		return array(
			'schema'                    => isset( $compiled['schema'] ) ? (string) $compiled['schema'] : '',
			'status'                    => isset( $compiled['status'] ) ? (string) $compiled['status'] : '',
			'source'                    => isset( $compiled['provenance']['source'] ) ? (string) $compiled['provenance']['source'] : '',
			'source_element_count'      => (int) ( $source['html']['element_count'] ?? 0 ),
			'source_class_count'        => (int) ( $source['html']['class_count'] ?? 0 ),
			'source_css_selector_count' => (int) ( $source['css']['selector_count'] ?? 0 ),
			'block_count'               => (int) ( $block_tree['block_count'] ?? 0 ),
			'block_depth'               => (int) ( $block_tree['max_depth'] ?? 0 ),
			'block_type_count'          => count( $block_types ),
			'component_count'           => count( $components ),
			'file_count'                => count( $files ),
			'diagnostic_count'          => count( $diagnostics ),
		);
	}

	/**
	 * Render serialized block markup to HTML.
	 *
	 * @param string              $block_markup Serialized blocks.
	 * @param array<string,mixed> $options      Render options.
	 * @return string
	 */
	public function blocks_to_html( string $block_markup, array $options = array() ): string {
		if ( $this->supports_blocks_to_html() ) {
			$bridge = new \Automattic\BlocksEngine\PhpTransformer\FormatBridge\FormatBridge();
			return $bridge->convert( $block_markup, 'blocks', 'html', $options );
		}

		return '';
	}
}

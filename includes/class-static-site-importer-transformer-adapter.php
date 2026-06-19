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
	 * Compile a website artifact into the BAC-compatible envelope SSI consumes.
	 *
	 * @param array<string,mixed> $artifact Website artifact bundle.
	 * @param array<string,mixed> $options  Compiler options.
	 * @return array<string,mixed>|WP_Error
	 */
	public function compile_website_artifact( array $artifact, array $options = array() ) {
		if ( ! function_exists( 'bac_compile_website_artifact' ) ) {
			return new WP_Error( 'static_site_importer_missing_bac', 'Block Artifact Compiler is required until php-transformer emits the compiled-site contract SSI imports.' );
		}

		$compiled = bac_compile_website_artifact( $artifact, $options );
		if ( is_wp_error( $compiled ) || ! is_array( $compiled ) ) {
			return $compiled;
		}

		return $this->with_compiled_site_fallback( $compiled );
	}

	/**
	 * Backfill the compiled-site contract from legacy document artifacts.
	 *
	 * @param array<string,mixed> $compiled Compiler result envelope.
	 * @return array<string,mixed>
	 */
	private function with_compiled_site_fallback( array $compiled ): array {
		$artifacts = isset( $compiled['wordpress_artifacts'] ) && is_array( $compiled['wordpress_artifacts'] ) ? $compiled['wordpress_artifacts'] : array();
		$site      = isset( $artifacts['site'] ) && is_array( $artifacts['site'] ) ? $artifacts['site'] : array();
		if ( 'block-artifact-compiler/compiled-site/v1' === (string) ( $site['schema'] ?? '' ) && isset( $site['pages'] ) && is_array( $site['pages'] ) ) {
			return $compiled;
		}

		$documents = isset( $artifacts['documents'] ) && is_array( $artifacts['documents'] ) ? $artifacts['documents'] : array();
		if ( empty( $documents ) ) {
			return $compiled;
		}

		$entry_path = isset( $compiled['input']['entry_path'] ) && is_scalar( $compiled['input']['entry_path'] ) ? (string) $compiled['input']['entry_path'] : '';
		$pages      = array();
		foreach ( $documents as $index => $document ) {
			if ( ! is_array( $document ) ) {
				continue;
			}

			$source_path = $this->document_source_path( $document, $index );
			if ( '' === $source_path ) {
				continue;
			}

			$is_entrypoint = ! empty( $document['entrypoint'] ) || ( '' !== $entry_path && $source_path === $entry_path ) || ( '' === $entry_path && 0 === $index );
			$slug          = $this->document_slug( $document, $source_path, $is_entrypoint );
			$pages[] = array_filter(
				array(
					'source_path' => $source_path,
					'route_key'   => $slug,
					'slug'        => $slug,
					'post_type'   => 'page',
					'title'       => isset( $document['title'] ) && is_scalar( $document['title'] ) ? (string) $document['title'] : '',
					'entrypoint'  => $is_entrypoint,
				),
				static fn ( $value ): bool => null !== $value && '' !== $value
			);
		}

		if ( empty( $pages ) ) {
			return $compiled;
		}

		$compiled['wordpress_artifacts']['site'] = array(
			'schema'  => 'block-artifact-compiler/compiled-site/v1',
			'pages'   => $pages,
			'source'  => 'static-site-importer-legacy-adapter-fallback',
			'warning' => 'BAC did not emit compiled-site pages; SSI synthesized page routing from document artifacts.',
		);
		$diagnostics                            = isset( $compiled['diagnostics'] ) && is_array( $compiled['diagnostics'] ) ? $compiled['diagnostics'] : array();
		$diagnostics[]                          = array(
			'level'   => 'warning',
			'code'    => 'static_site_importer_legacy_compiled_site_fallback',
			'message' => 'BAC did not emit compiled-site pages; SSI synthesized page routing from document artifacts.',
		);
		$compiled['diagnostics']                = $diagnostics;

		return $compiled;
	}

	/**
	 * Resolve a document source path from legacy document fields.
	 *
	 * @param array<string,mixed> $document Document artifact.
	 * @param int                 $index    Document index.
	 * @return string
	 */
	private function document_source_path( array $document, int $index ): string {
		foreach ( array( 'source_path', 'path', 'slug' ) as $key ) {
			if ( isset( $document[ $key ] ) && is_scalar( $document[ $key ] ) && '' !== trim( (string) $document[ $key ] ) ) {
				return (string) $document[ $key ];
			}
		}

		return 0 === $index ? 'index.html' : '';
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
		if ( function_exists( 'bac_summarize_result' ) ) {
			$summary = bac_summarize_result( $compiled );
			return is_array( $summary ) ? $summary : array();
		}

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
		if ( class_exists( '\Automattic\BlocksEngine\PhpTransformer\FormatBridge\FormatBridge' ) ) {
			$bridge = new \Automattic\BlocksEngine\PhpTransformer\FormatBridge\FormatBridge();
			return $bridge->convert( $block_markup, 'blocks', 'html', $options );
		}

		return function_exists( 'bfb_convert' ) ? bfb_convert( $block_markup, 'blocks', 'html' ) : '';
	}
}

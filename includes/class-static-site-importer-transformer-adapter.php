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
		if ( class_exists( '\Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler' ) ) {
			unset( $options );

			$compiler = new \Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler();
			$result   = $compiler->compile( $artifact );
			if ( is_object( $result ) && method_exists( $result, 'toArray' ) ) {
				$result = $result->toArray();
			}

			if ( is_array( $result ) ) {
				return $this->transformer_result_to_bac_result( $result );
			}
		}

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

		return array(
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
				'documents'    => $documents,
				'files'        => isset( $result['assets'] ) && is_array( $result['assets'] ) ? $result['assets'] : array(),
				'site'         => $this->site_from_compiled_site_report( $compiled_site, $documents ),
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
			if ( ! is_array( $page ) || ! isset( $page['block_markup'] ) || '' === trim( (string) $page['block_markup'] ) ) {
				continue;
			}

			$source_path = isset( $page['source_path'] ) && is_scalar( $page['source_path'] ) ? (string) $page['source_path'] : '';
			if ( '' === $source_path ) {
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
					'block_markup' => (string) $page['block_markup'],
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

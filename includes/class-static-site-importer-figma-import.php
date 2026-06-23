<?php
/**
 * Figma import adapter.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Converts Figma import requests into website artifacts and imports them.
 */
class Static_Site_Importer_Figma_Import {
	/**
	 * Import a Figma request through the existing website artifact import ability.
	 *
	 * @param array<string,mixed> $input Figma import input.
	 * @return array<string,mixed>
	 */
	public static function import( array $input ): array {
		$artifact = self::website_artifact_from_input( $input );
		if ( is_wp_error( $artifact ) ) {
			/** @var WP_Error $artifact */
			return static_site_importer_ability_error( (string) $artifact->get_error_code(), $artifact->get_error_message(), $artifact->get_error_data() );
		}

		$import_input = self::import_input( $input, $artifact );
		if ( is_callable( 'static_site_importer_ability_import_website_artifact' ) ) {
			return static_site_importer_ability_import_website_artifact( $import_input );
		}

		$result = Static_Site_Importer_Theme_Generator::import_website_artifact( $artifact, $import_input );
		if ( is_wp_error( $result ) ) {
			/** @var WP_Error $result */
			return static_site_importer_ability_error( (string) $result->get_error_code(), $result->get_error_message(), $result->get_error_data() );
		}

		return array(
			'success' => true,
			'result'  => $result,
		);
	}

	/**
	 * Build a Figma plugin runner response for browser clients.
	 *
	 * @param array<string,mixed> $ability_result Ability result.
	 * @return array<string,mixed>
	 */
	public static function runner_response( array $ability_result ): array {
		if ( empty( $ability_result['success'] ) ) {
			$error = isset( $ability_result['error'] ) && is_array( $ability_result['error'] ) ? $ability_result['error'] : array();

			return array(
				'schema'  => 'figma-to-wordpress/runner-response/v1',
				'success' => false,
				'status'  => 'failed',
				'error'   => array(
					'code'    => (string) ( $error['code'] ?? 'static_site_importer_figma_import_failed' ),
					'message' => (string) ( $error['message'] ?? 'Figma import failed.' ),
					'data'    => $error['data'] ?? null,
				),
			);
		}

		$result = isset( $ability_result['result'] ) && is_array( $ability_result['result'] ) ? $ability_result['result'] : array();

		return array(
			'schema'                => 'figma-to-wordpress/runner-response/v1',
			'success'               => true,
			'status'                => 'created',
			'open_url'              => home_url( '/' ),
			'materialization'       => $ability_result,
			'import_report_summary' => isset( $result['import_report_summary'] ) && is_array( $result['import_report_summary'] ) ? $result['import_report_summary'] : array(),
		);
	}

	/**
	 * Build a website artifact from a Figma import request.
	 *
	 * @param array<string,mixed> $input Figma import input.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function website_artifact_from_input( array $input ) {
		if ( isset( $input['artifact_bundle'] ) && is_array( $input['artifact_bundle'] ) ) {
			return self::website_artifact_from_bundle( $input['artifact_bundle'], $input );
		}

		$scenegraph = self::scenegraph_from_input( $input );
		if ( ! empty( $scenegraph ) ) {
			return self::website_artifact_from_scenegraph( $scenegraph, $input );
		}

		return new WP_Error( 'static_site_importer_figma_source_missing', 'Figma imports require an artifact_bundle or a Figma scenegraph.', array( 'status' => 400 ) );
	}

	/**
	 * Convert the current Figma plugin artifact bundle into a website artifact.
	 *
	 * @param array<string,mixed> $bundle Figma plugin artifact bundle.
	 * @param array<string,mixed> $input  Full request input.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function website_artifact_from_bundle( array $bundle, array $input ) {
		$files = self::normalize_files( isset( $bundle['files'] ) && is_array( $bundle['files'] ) ? $bundle['files'] : array(), (string) ( $bundle['root'] ?? 'website/' ) );
		if ( empty( $files ) ) {
			return new WP_Error( 'static_site_importer_figma_bundle_empty', 'The Figma artifact bundle did not contain importable files.', array( 'status' => 400 ) );
		}

		return array(
			'schema'     => Static_Site_Importer_Transformer_Adapter::WEBSITE_ARTIFACT_SCHEMA,
			'root'       => 'website',
			'entrypoint' => self::entrypoint( isset( $bundle['entrypoint'] ) ? (string) $bundle['entrypoint'] : '', $files ),
			'files'      => $files,
			'provenance' => self::provenance( $input ),
		);
	}

	/**
	 * Transform a raw Figma scenegraph into a website artifact.
	 *
	 * @param array<string,mixed> $scenegraph Figma scenegraph.
	 * @param array<string,mixed> $input      Full request input.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function website_artifact_from_scenegraph( array $scenegraph, array $input ) {
		if ( ! function_exists( 'blocks_engine_figma_transformer_transform_scenegraph' ) ) {
			return new WP_Error( 'static_site_importer_figma_transformer_unavailable', 'Blocks Engine Figma transformer is not available.', array( 'status' => 501 ) );
		}

		$transform = blocks_engine_figma_transformer_transform_scenegraph( $scenegraph, self::transform_options( $input ) );
		if ( is_object( $transform ) && is_callable( array( $transform, 'toArray' ) ) ) {
			$transform = $transform->toArray();
		}
		if ( ! is_array( $transform ) ) {
			return new WP_Error( 'static_site_importer_figma_transform_failed', 'Blocks Engine Figma transformer returned an invalid result.', array( 'status' => 500 ) );
		}
		if ( isset( $transform['status'] ) && 'failed' === (string) $transform['status'] ) {
			return new WP_Error( 'static_site_importer_figma_transform_failed', 'Blocks Engine Figma transformer failed.', array( 'status' => 500, 'transform' => $transform ) );
		}

		$files = self::normalize_files( isset( $transform['files'] ) && is_array( $transform['files'] ) ? $transform['files'] : array(), 'website/' );
		if ( empty( $files ) ) {
			return new WP_Error( 'static_site_importer_figma_transform_empty', 'Blocks Engine Figma transformer did not produce importable files.', array( 'status' => 500, 'transform' => $transform ) );
		}

		return array(
			'schema'     => Static_Site_Importer_Transformer_Adapter::WEBSITE_ARTIFACT_SCHEMA,
			'root'       => 'website',
			'entrypoint' => self::entrypoint( 'website/index.html', $files ),
			'files'      => $files,
			'provenance' => self::provenance( $input ) + array( 'transform' => 'blocks-engine/figma-transformer' ),
		);
	}

	/**
	 * Normalize import files into the website/ artifact root.
	 *
	 * @param array<int,array<string,mixed>> $files Files.
	 * @param string                         $root  Source root.
	 * @return array<int,array<string,mixed>>
	 */
	private static function normalize_files( array $files, string $root ): array {
		$normalized = array();
		$root       = trim( str_replace( '\\', '/', $root ), '/' );
		foreach ( $files as $file ) {
			if ( ! is_array( $file ) || ! isset( $file['path'] ) ) {
				continue;
			}

			$path = self::artifact_path( (string) $file['path'], $root );
			if ( '' === $path ) {
				continue;
			}

			$record = array( 'path' => $path );
			if ( isset( $file['content_base64'] ) ) {
				$record['content_base64'] = (string) $file['content_base64'];
			} else {
				$record['content'] = isset( $file['content'] ) ? (string) $file['content'] : '';
			}

			if ( isset( $file['mime_type'] ) ) {
				$record['mime_type'] = (string) $file['mime_type'];
			}

			$normalized[] = $record;
		}

		return $normalized;
	}

	/**
	 * Normalize an artifact path under website/.
	 */
	private static function artifact_path( string $path, string $root ): string {
		$path = str_replace( '\\', '/', $path );
		$path = preg_replace( '#(^|/)\.\.(?=/|$)#', '', $path );
		$path = preg_replace( '#[^A-Za-z0-9_./-]#', '-', (string) $path );
		$path = trim( preg_replace( '#/+#', '/', (string) $path ), '/' );

		if ( '' !== $root && str_starts_with( $path, $root . '/' ) ) {
			$path = substr( $path, strlen( $root ) + 1 );
		}

		return '' === $path ? '' : 'website/' . ltrim( $path, '/' );
	}

	/**
	 * Pick the entrypoint file.
	 *
	 * @param string                         $entrypoint Requested entrypoint.
	 * @param array<int,array<string,mixed>> $files      Normalized files.
	 */
	private static function entrypoint( string $entrypoint, array $files ): string {
		$paths = array_map( static fn( array $file ): string => (string) ( $file['path'] ?? '' ), $files );
		$entrypoint = self::artifact_path( $entrypoint, 'website' );

		if ( '' !== $entrypoint && in_array( $entrypoint, $paths, true ) ) {
			return $entrypoint;
		}

		return in_array( 'website/index.html', $paths, true ) ? 'website/index.html' : (string) reset( $paths );
	}

	/**
	 * Extract a scenegraph from supported request shapes.
	 *
	 * @param array<string,mixed> $input Figma import input.
	 * @return array<string,mixed>
	 */
	private static function scenegraph_from_input( array $input ): array {
		if ( isset( $input['scenegraph'] ) && is_array( $input['scenegraph'] ) ) {
			return $input['scenegraph'];
		}
		if ( isset( $input['figma'] ) && is_array( $input['figma'] ) ) {
			$figma = $input['figma'];
			if ( isset( $figma['scenegraph'] ) && is_array( $figma['scenegraph'] ) ) {
				return $figma['scenegraph'];
			}

			return $figma;
		}

		return array();
	}

	/**
	 * Build import ability input from Figma request fields.
	 *
	 * @param array<string,mixed> $input    Figma import input.
	 * @param array<string,mixed> $artifact Website artifact.
	 * @return array<string,mixed>
	 */
	private static function import_input( array $input, array $artifact ): array {
		return array(
			'artifact'                  => $artifact,
			'slug'                      => isset( $input['slug'] ) ? (string) $input['slug'] : 'figma-import',
			'name'                      => isset( $input['name'] ) ? (string) $input['name'] : 'Figma Import',
			'activate'                  => array_key_exists( 'activate', $input ) ? ! empty( $input['activate'] ) : true,
			'overwrite'                 => array_key_exists( 'overwrite', $input ) ? ! empty( $input['overwrite'] ) : true,
			'fail_on_quality'           => ! empty( $input['fail_on_quality'] ),
			'allow_missing_woocommerce' => ! empty( $input['allow_missing_woocommerce'] ),
			'compiler_options'          => isset( $input['compiler_options'] ) && is_array( $input['compiler_options'] ) ? $input['compiler_options'] : array(),
			'source_metadata'           => self::provenance( $input ),
		);
	}

	/**
	 * Build transformer options from request fields.
	 *
	 * @param array<string,mixed> $input Figma import input.
	 * @return array<string,mixed>
	 */
	private static function transform_options( array $input ): array {
		$options = isset( $input['transform_options'] ) && is_array( $input['transform_options'] ) ? $input['transform_options'] : array();
		if ( isset( $input['frame_id'] ) ) {
			$options['frame_id'] = (string) $input['frame_id'];
		}

		return $options;
	}

	/**
	 * Build provenance metadata for generated artifacts/imports.
	 *
	 * @param array<string,mixed> $input Figma import input.
	 * @return array<string,mixed>
	 */
	private static function provenance( array $input ): array {
		$source = isset( $input['source'] ) && is_array( $input['source'] ) ? $input['source'] : array();

		return array(
			'source'      => 'figma-to-wordpress',
			'schema'      => (string) ( $input['schema'] ?? 'static-site-importer/import-figma/v1' ),
			'figma'       => array_filter(
				array(
					'file_key'    => isset( $source['fileKey'] ) ? (string) $source['fileKey'] : '',
					'node_ids'    => isset( $source['nodeIds'] ) && is_array( $source['nodeIds'] ) ? array_values( array_map( 'strval', $source['nodeIds'] ) ) : array(),
					'exported_at' => isset( $source['exportedAt'] ) ? (string) $source['exportedAt'] : '',
				)
			),
			'import_goal' => isset( $input['goal'] ) ? (string) $input['goal'] : '',
		);
	}
}

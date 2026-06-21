<?php
/**
 * Importer REST routes.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register Static Site Importer REST routes.
 *
 * @return void
 */
function static_site_importer_register_rest_routes(): void {
	register_rest_route(
		'static-site-importer/v1',
		'/imports',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'static_site_importer_rest_create_import',
			'permission_callback' => 'static_site_importer_rest_manage_permission',
		)
	);
}

/**
 * Require a site operator for import mutations.
 *
 * @return true|WP_Error
 */
function static_site_importer_rest_manage_permission() {
	if ( function_exists( 'current_user_can' ) && current_user_can( 'switch_themes' ) ) {
		return true;
	}

	return new WP_Error(
		'static_site_importer_forbidden',
		__( 'You are not allowed to run static site imports on this site.', 'static-site-importer' ),
		array( 'status' => function_exists( 'is_user_logged_in' ) && is_user_logged_in() ? 403 : 401 )
	);
}

/**
 * Create an import from a URL, raw HTML, or uploaded file bundle.
 *
 * @param WP_REST_Request $request REST request.
 * @return WP_REST_Response|WP_Error
 */
function static_site_importer_rest_create_import( WP_REST_Request $request ) {
	$params = $request->get_json_params();
	if ( ! is_array( $params ) ) {
		$params = array();
	}

	$source = isset( $params['source'] ) && is_array( $params['source'] ) ? $params['source'] : array();
	$input  = static_site_importer_rest_import_args( $params );

	if ( isset( $source['url'] ) && '' !== trim( (string) $source['url'] ) ) {
		$input['url'] = esc_url_raw( (string) $source['url'] );
		if ( isset( $params['provider'] ) ) {
			$input['provider'] = sanitize_key( (string) $params['provider'] );
		}
		if ( isset( $params['provider_args'] ) && is_array( $params['provider_args'] ) ) {
			$input['provider_args'] = $params['provider_args'];
		}

		$result = Static_Site_Importer_URL_Import_Runtime::import_url( $input );
	} else {
		$artifact = static_site_importer_rest_source_artifact( $source );
		if ( is_wp_error( $artifact ) ) {
			return $artifact;
		}

		$result = Static_Site_Importer_Theme_Generator::import_website_artifact( $artifact, $input );
	}

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response(
		array(
			'success'               => true,
			'result'                => $result,
			'import_report_summary' => isset( $result['import_report_summary'] ) && is_array( $result['import_report_summary'] ) ? $result['import_report_summary'] : array(),
		)
	);
}

/**
 * Build import args from REST input.
 *
 * @param array<string,mixed> $params Request params.
 * @return array<string,mixed>
 */
function static_site_importer_rest_import_args( array $params ): array {
	return array(
		'slug'                      => isset( $params['slug'] ) ? sanitize_title( (string) $params['slug'] ) : '',
		'name'                      => isset( $params['name'] ) ? sanitize_text_field( (string) $params['name'] ) : '',
		'activate'                  => ! empty( $params['activate'] ),
		'overwrite'                 => ! empty( $params['overwrite'] ),
		'fail_on_quality'           => ! empty( $params['fail_on_quality'] ),
		'allow_missing_woocommerce' => ! empty( $params['allow_missing_woocommerce'] ),
		'source_metadata'           => array(
			'source' => 'static_site_importer_block',
		),
	);
}

/**
 * Convert raw HTML or uploaded file JSON into a website artifact.
 *
 * @param array<string,mixed> $source Source payload.
 * @return array<string,mixed>|WP_Error
 */
function static_site_importer_rest_source_artifact( array $source ) {
	$files = array();

	if ( isset( $source['html'] ) && '' !== trim( (string) $source['html'] ) ) {
		$files[] = array(
			'path'    => 'website/index.html',
			'content' => (string) $source['html'],
		);
	}

	if ( isset( $source['files'] ) && is_array( $source['files'] ) ) {
		foreach ( $source['files'] as $file ) {
			if ( ! is_array( $file ) ) {
				continue;
			}

			$path = isset( $file['path'] ) ? static_site_importer_rest_artifact_path( (string) $file['path'] ) : '';
			if ( '' === $path ) {
				continue;
			}

			if ( isset( $file['content'] ) ) {
				$files[] = array(
					'path'    => $path,
					'content' => (string) $file['content'],
				);
				continue;
			}

			if ( isset( $file['content_base64'] ) ) {
				$content = base64_decode( (string) $file['content_base64'], true );
				if ( false === $content ) {
					return new WP_Error( 'static_site_importer_invalid_file_content', __( 'Uploaded file content could not be decoded.', 'static-site-importer' ), array( 'status' => 400 ) );
				}

				$files[] = array(
					'path'           => $path,
					'content_base64' => base64_encode( $content ),
				);
			}
		}
	}

	if ( empty( $files ) ) {
		return new WP_Error( 'static_site_importer_missing_source', __( 'Add a website URL, site files, or raw HTML to start.', 'static-site-importer' ), array( 'status' => 400 ) );
	}

	return array(
		'schema'     => Static_Site_Importer_Transformer_Adapter::WEBSITE_ARTIFACT_SCHEMA,
		'entrypoint' => static_site_importer_rest_entrypoint( $files ),
		'files'      => $files,
	);
}

/**
 * Normalize uploaded file paths into artifact paths.
 *
 * @param string $path File path.
 * @return string
 */
function static_site_importer_rest_artifact_path( string $path ): string {
	$path = str_replace( '\\', '/', $path );
	$path = preg_replace( '#(^|/)\.\.(?=/|$)#', '', $path );
	$path = ltrim( (string) $path, '/' );
	$path = preg_replace( '#/+#', '/', $path );

	if ( '' === $path ) {
		return '';
	}

	return str_starts_with( $path, 'website/' ) ? $path : 'website/' . $path;
}

/**
 * Pick an entrypoint from artifact files.
 *
 * @param array<int,array<string,mixed>> $files Artifact files.
 * @return string
 */
function static_site_importer_rest_entrypoint( array $files ): string {
	foreach ( array( 'website/index.html', 'website/home.html' ) as $candidate ) {
		foreach ( $files as $file ) {
			if ( isset( $file['path'] ) && $candidate === (string) $file['path'] ) {
				return $candidate;
			}
		}
	}

	foreach ( $files as $file ) {
		$path = isset( $file['path'] ) ? (string) $file['path'] : '';
		if ( preg_match( '/\.html?$/i', $path ) ) {
			return $path;
		}
	}

	return 'website/index.html';
}

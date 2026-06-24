<?php
/**
 * Codebox-backed validation product path contract.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds and dispatches SSI validation requests for disposable Codebox runtimes.
 */
class Static_Site_Importer_Codebox_Validation {

	private const REQUEST_SCHEMA             = 'static-site-importer/codebox-validation-request/v1';
	private const RESULT_SCHEMA              = 'static-site-importer/codebox-validation-result/v1';
	private const ARTIFACT_SCHEMA            = 'static-site-importer/codebox-validation-artifacts/v1';
	private const FIXTURE_DIAGNOSTICS_SCHEMA = 'static-site-importer/codebox-fixture-diagnostics/v1';

	/**
	 * Register the default WP Codebox host-delegation bridge.
	 *
	 * @return void
	 */
	public static function register_default_provider(): void {
		add_filter( 'static_site_importer_codebox_validation_result', array( self::class, 'delegate_to_wp_codebox_host_delegation' ), 10, 3 );
	}

	/**
	 * Delegate SSI validation requests to WP Codebox/Homeboy host providers.
	 *
	 * @param mixed               $result  Existing provider result.
	 * @param array<string,mixed> $request SSI validation request.
	 * @param array<string,mixed> $input   Raw caller input.
	 * @return array<string,mixed>|WP_Error|null
	 */
	public static function delegate_to_wp_codebox_host_delegation( mixed $result, array $request, array $input ) {
		if ( null !== $result ) {
			return $result;
		}

		$delegation_request       = self::host_delegation_request( $request, $input );
		$host_delegation_callback = array( 'WP_Codebox_Abilities', 'request_host_delegation' );
		$host_delegation_methods  = class_exists( 'WP_Codebox_Abilities' ) ? get_class_methods( 'WP_Codebox_Abilities' ) : array();
		if ( in_array( 'request_host_delegation', $host_delegation_methods, true ) && is_callable( $host_delegation_callback ) ) {
			$delegation_result = call_user_func( $host_delegation_callback, $delegation_request );
		} elseif ( function_exists( 'has_filter' ) && has_filter( 'wp_codebox_host_delegation_request' ) ) {
			$delegation_result = apply_filters( 'wp_codebox_host_delegation_request', null, $delegation_request );
		} else {
			return null;
		}

		if ( is_wp_error( $delegation_result ) ) {
			return $delegation_result;
		}

		return self::provider_result_from_host_delegation( $delegation_result );
	}

	/**
	 * Validate an imported/generated site in a disposable Codebox runtime.
	 *
	 * @param array<string,mixed> $input Validation input.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function validate( array $input ) {
		$request = self::build_request( $input );
		if ( is_wp_error( $request ) ) {
			return $request;
		}

		/**
		 * Filters the SSI Codebox validation request before runtime dispatch.
		 *
		 * WP Codebox/Homeboy integrations may attach orchestration metadata here, but
		 * should not execute the run. Execution belongs to
		 * `static_site_importer_codebox_validation_result`.
		 *
		 * @param array<string,mixed> $request Validation request.
		 * @param array<string,mixed> $input   Raw caller input.
		 */
		$request = apply_filters( 'static_site_importer_codebox_validation_request', $request, $input );

		/**
		 * Executes an SSI validation request in a disposable Codebox runtime.
		 *
		 * The provider should import the supplied website artifact or generated theme
		 * output into the sandbox, run SSI import validation, run block validation,
		 * collect browser/render evidence, and return durable artifact references.
		 * Return null when no provider is available.
		 *
		 * @param array<string,mixed>|WP_Error|null $result  Provider result.
		 * @param array<string,mixed>               $request Validation request.
		 * @param array<string,mixed>               $input   Raw caller input.
		 */
		$result = apply_filters( 'static_site_importer_codebox_validation_result', null, $request, $input );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( null === $result ) {
			return self::provider_unavailable_result( $request );
		}

		return self::normalize_provider_result( $request, $result );
	}

	/**
	 * Build a structured validation result for failures that happen before provider output exists.
	 *
	 * @param WP_Error            $error Validation error.
	 * @param array<string,mixed> $input Raw caller input.
	 * @return array<string,mixed>
	 */
	public static function error_result_from_wp_error( WP_Error $error, array $input = array() ): array {
		$request = self::build_error_request_summary( $input );
		$status  = 'static_site_importer_codebox_validation_source_missing' === $error->get_error_code() ? 'blocked' : 'failed';

		$result = array(
			'success'       => false,
			'schema'        => self::RESULT_SCHEMA,
			'status'        => $status,
			'product_path'  => 'static-site-importer/validate-in-codebox',
			'request'       => $request,
			'artifacts'     => self::artifact_contract( array( 'source' => array() ), array() ),
			'runtime'       => array(),
			'summary'       => array(
				'quality_pass' => false,
				'error_code'   => $error->get_error_code(),
			),
			'upstream_gaps' => array(),
		);

		$result['fixture_diagnostics'] = self::fixture_diagnostics(
			array(
				'slug'        => isset( $request['import_args']['slug'] ) ? (string) $request['import_args']['slug'] : '',
				'name'        => isset( $request['import_args']['name'] ) ? (string) $request['import_args']['name'] : '',
				'request'     => $request,
				'status'      => $status,
				'success'     => false,
				'artifacts'   => $result['artifacts'],
				'diagnostics' => array(
					array(
						'type'        => 'validation_request_error',
						'severity'    => 'error',
						'code'        => $error->get_error_code(),
						'reason_code' => $error->get_error_code(),
						'message'     => $error->get_error_message(),
						'stage'       => 'request_validation',
						'owner'       => 'static-site-importer',
					),
				),
			)
		);

		return $result;
	}

	/**
	 * Build the runtime request envelope.
	 *
	 * @param array<string,mixed> $input Validation input.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function build_request( array $input ) {
		$artifact            = isset( $input['artifact'] ) && is_array( $input['artifact'] ) ? $input['artifact'] : array();
		$generated_theme_ref = self::normalize_artifact_ref( $input['generated_theme_ref'] ?? array() );
		$theme_archive_ref   = self::normalize_artifact_ref( $input['theme_archive_ref'] ?? array() );

		if ( empty( $artifact ) && empty( $generated_theme_ref ) && empty( $theme_archive_ref ) ) {
			return new WP_Error( 'static_site_importer_codebox_validation_source_missing', 'Codebox validation requires an artifact, generated_theme_ref, or theme_archive_ref input.' );
		}

		$import_args = array(
			'slug'                         => isset( $input['slug'] ) ? sanitize_title( (string) $input['slug'] ) : '',
			'name'                         => isset( $input['name'] ) ? sanitize_text_field( (string) $input['name'] ) : '',
			'activate'                     => array_key_exists( 'activate', $input ) ? (bool) $input['activate'] : true,
			'overwrite'                    => array_key_exists( 'overwrite', $input ) ? (bool) $input['overwrite'] : true,
			'fail_on_quality'              => ! empty( $input['fail_on_quality'] ),
			'allow_missing_woocommerce'    => ! empty( $input['allow_missing_woocommerce'] ),
			'asset_materialization_policy' => isset( $input['asset_materialization_policy'] ) ? (string) $input['asset_materialization_policy'] : '',
			'compiler_options'             => isset( $input['compiler_options'] ) && is_array( $input['compiler_options'] ) ? $input['compiler_options'] : array(),
			'source_metadata'              => isset( $input['source_metadata'] ) && is_array( $input['source_metadata'] ) ? $input['source_metadata'] : array(),
		);

		return array(
			'schema'             => self::REQUEST_SCHEMA,
			'product_path'       => 'static-site-importer/validate-in-codebox',
			'execution_scope'    => 'disposable-wp-codebox-runtime',
			'source'             => array(
				'artifact'            => $artifact,
				'generated_theme_ref' => $generated_theme_ref,
				'theme_archive_ref'   => $theme_archive_ref,
			),
			'import_args'        => array_filter(
				$import_args,
				static fn ( mixed $value ): bool => ! ( '' === $value || array() === $value )
			),
			'validation'         => array(
				'import_report'    => true,
				'block_validation' => true,
				'browser_render'   => true,
				'screenshots'      => true,
				'visual_diff'      => true,
			),
			'required_artifacts' => self::required_artifacts(),
			'operator_notes'     => self::operator_notes( $input ),
		);
	}

	/**
	 * Build a WP Codebox host-delegation request for SSI validation.
	 *
	 * @param array<string,mixed> $request SSI validation request.
	 * @param array<string,mixed> $input   Raw caller input.
	 * @return array<string,mixed>
	 */
	private static function host_delegation_request( array $request, array $input ): array {
		return array(
			'schema'     => 'wp-codebox/host-delegation-request/v1',
			'request_id' => self::request_id(),
			'task'       => 'static-site-importer.validate-in-codebox',
			'task_input' => $request,
			'execution'  => array(
				'kind'         => 'runtime-validation',
				'product_path' => 'static-site-importer/validate-in-codebox',
			),
			'metadata'   => array(
				'product'        => 'static-site-importer',
				'request_schema' => self::REQUEST_SCHEMA,
				'input_keys'     => array_map( 'strval', array_keys( $input ) ),
			),
		);
	}

	/**
	 * Normalize WP Codebox host-delegation output into the SSI provider shape.
	 *
	 * @param mixed $delegation_result WP Codebox host delegation result.
	 * @return array<string,mixed>|WP_Error|null
	 */
	private static function provider_result_from_host_delegation( mixed $delegation_result ) {
		if ( null === $delegation_result ) {
			return null;
		}

		if ( ! is_array( $delegation_result ) ) {
			return new WP_Error( 'static_site_importer_codebox_host_delegation_result_invalid', 'WP Codebox host delegation providers must return an array, WP_Error, or null.' );
		}

		if ( 'wp-codebox/host-delegation-result/v1' !== (string) ( $delegation_result['schema'] ?? '' ) ) {
			return $delegation_result;
		}

		$status = sanitize_key( (string) ( $delegation_result['status'] ?? '' ) );
		if ( 'unavailable' === $status ) {
			return null;
		}

		$result    = isset( $delegation_result['result'] ) && is_array( $delegation_result['result'] ) ? $delegation_result['result'] : array();
		$artifacts = isset( $result['artifacts'] ) && is_array( $result['artifacts'] ) ? $result['artifacts'] : ( isset( $delegation_result['artifacts'] ) && is_array( $delegation_result['artifacts'] ) ? $delegation_result['artifacts'] : array() );
		$summary   = isset( $result['summary'] ) && is_array( $result['summary'] ) ? $result['summary'] : array();

		$provider_result = array(
			'success'   => ! empty( $delegation_result['success'] ),
			'status'    => self::provider_status_from_host_delegation_status( $status, ! empty( $delegation_result['success'] ) ),
			'runtime'   => array(
				'provider'                => (string) ( $delegation_result['provider'] ?? 'wp-codebox/host-delegation' ),
				'host_delegation_status'  => $status,
				'host_delegation_request' => (string) ( $delegation_result['request_id'] ?? '' ),
			),
			'summary'   => $summary,
			'artifacts' => $artifacts,
		);

		if ( ! empty( $result['upstream_gaps'] ) && is_array( $result['upstream_gaps'] ) ) {
			$provider_result['upstream_gaps'] = array_values( $result['upstream_gaps'] );
		} elseif ( empty( $provider_result['success'] ) && ! empty( $delegation_result['error'] ) ) {
			$provider_result['upstream_gaps'] = array( $delegation_result['error'] );
		}

		return $provider_result;
	}

	/**
	 * Map WP Codebox host-delegation statuses to SSI provider statuses.
	 *
	 * @param string $status  Host-delegation status.
	 * @param bool   $success Whether host delegation succeeded.
	 * @return string
	 */
	private static function provider_status_from_host_delegation_status( string $status, bool $success ): string {
		if ( 'completed' === $status || ( $success && '' === $status ) ) {
			return 'succeeded';
		}

		if ( 'accepted' === $status ) {
			return 'running';
		}

		return $success ? 'succeeded' : 'failed';
	}

	/**
	 * Create a stable-enough request id for host delegation.
	 *
	 * @return string
	 */
	private static function request_id(): string {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return wp_generate_uuid4();
		}

		return uniqid( 'ssi-codebox-', true );
	}

	/**
	 * Normalize provider output into the SSI result contract.
	 *
	 * @param array<string,mixed> $request Validation request.
	 * @param array<string,mixed> $result  Provider result.
	 * @return array<string,mixed>
	 */
	private static function normalize_provider_result( array $request, array $result ): array {
		$status    = isset( $result['status'] ) ? sanitize_key( (string) $result['status'] ) : ( ! empty( $result['success'] ) ? 'succeeded' : 'failed' );
		$artifacts = self::artifact_contract( $request, isset( $result['artifacts'] ) && is_array( $result['artifacts'] ) ? $result['artifacts'] : array() );
		$output    = array(
			'success'       => 'succeeded' === $status,
			'schema'        => self::RESULT_SCHEMA,
			'status'        => $status,
			'product_path'  => 'static-site-importer/validate-in-codebox',
			'request'       => self::request_summary( $request ),
			'artifacts'     => $artifacts,
			'runtime'       => isset( $result['runtime'] ) && is_array( $result['runtime'] ) ? $result['runtime'] : array(),
			'summary'       => isset( $result['summary'] ) && is_array( $result['summary'] ) ? $result['summary'] : array(),
			'upstream_gaps' => isset( $result['upstream_gaps'] ) && is_array( $result['upstream_gaps'] ) ? array_values( $result['upstream_gaps'] ) : array(),
		);

		$output['fixture_diagnostics'] = self::fixture_diagnostics(
			array_merge(
				$result,
				array(
					'status'    => $status,
					'success'   => $output['success'],
					'request'   => $output['request'],
					'artifacts' => $artifacts,
				)
			)
		);

		return $output;
	}

	/**
	 * Build a concrete blocked result when no runtime provider is registered.
	 *
	 * @param array<string,mixed> $request Validation request.
	 * @return array<string,mixed>
	 */
	private static function provider_unavailable_result( array $request ): array {
		$result = array(
			'success'       => false,
			'schema'        => self::RESULT_SCHEMA,
			'status'        => 'blocked',
			'product_path'  => 'static-site-importer/validate-in-codebox',
			'request'       => self::request_summary( $request ),
			'artifacts'     => self::artifact_contract( $request, array() ),
			'runtime'       => array(
				'provider' => 'wp-codebox/homeboy',
				'status'   => 'missing_provider',
			),
			'summary'       => array(
				'quality_pass'          => false,
				'import_report'         => 'missing',
				'block_validation'      => 'missing',
				'browser_render'        => 'missing',
				'screenshot_artifacts'  => 0,
				'visual_diff_artifacts' => 0,
			),
			'upstream_gaps' => array(
				array(
					'owner'        => 'wp-codebox/homeboy',
					'capability'   => 'static-site-importer Codebox validation provider',
					'missing'      => 'No provider is registered on static_site_importer_codebox_validation_result to run the SSI import, block validation, browser render capture, screenshot capture, and visual diff capture inside a disposable WP Codebox runtime.',
					'needed_shape' => 'Accept static-site-importer/codebox-validation-request/v1 and return static-site-importer/codebox-validation-result/v1 with durable artifact references for generated theme/archive, import report, block validation result, browser/render evidence metadata, screenshots, and diffs.',
				),
			),
		);

		$result['fixture_diagnostics'] = self::fixture_diagnostics(
			array(
				'status'        => 'blocked',
				'success'       => false,
				'request'       => $result['request'],
				'artifacts'     => $result['artifacts'],
				'summary'       => $result['summary'],
				'upstream_gaps' => $result['upstream_gaps'],
				'diagnostics'   => array(
					array(
						'type'        => 'runtime_provider_unavailable',
						'severity'    => 'error',
						'code'        => 'missing_provider',
						'reason_code' => 'missing_provider',
						'message'     => 'No Codebox validation provider is registered.',
						'stage'       => 'runtime_dispatch',
						'owner'       => 'wp-codebox/homeboy',
					),
				),
			)
		);

		return $result;
	}

	/**
	 * Build the fixture-level diagnostics envelope consumed by matrix runners.
	 *
	 * @param array<string,mixed> $result Provider or synthesized result.
	 * @return array<string,mixed>
	 */
	private static function fixture_diagnostics( array $result ): array {
		$request       = isset( $result['request'] ) && is_array( $result['request'] ) ? $result['request'] : array();
		$import_args   = isset( $request['import_args'] ) && is_array( $request['import_args'] ) ? $request['import_args'] : array();
		$import_report = self::provider_import_report( $result );
		$summary       = isset( $result['summary'] ) && is_array( $result['summary'] ) ? $result['summary'] : array();
		$artifacts     = isset( $result['artifacts'] ) && is_array( $result['artifacts'] ) ? $result['artifacts'] : array();

		$diagnostics = array_merge(
			self::diagnostic_rows_from_result( $result ),
			self::diagnostic_rows_from_import_report( $import_report ),
			self::blocks_engine_conversion_diagnostics( $import_report ),
			self::runtime_dependency_target_gaps( $import_report )
		);
		$diagnostics = self::dedupe_diagnostics( $diagnostics );

		$quality_counts = self::quality_counts( $import_report, $summary );

		return array(
			'schema'                         => self::FIXTURE_DIAGNOSTICS_SCHEMA,
			'fixture'                        => array(
				'slug' => isset( $result['slug'] ) && is_scalar( $result['slug'] ) ? (string) $result['slug'] : ( isset( $import_args['slug'] ) ? (string) $import_args['slug'] : '' ),
				'name' => isset( $result['name'] ) && is_scalar( $result['name'] ) ? (string) $result['name'] : ( isset( $import_args['name'] ) ? (string) $import_args['name'] : '' ),
			),
			'status'                         => isset( $result['status'] ) && is_scalar( $result['status'] ) ? (string) $result['status'] : '',
			'success'                        => ! empty( $result['success'] ),
			'quality_counts'                 => $quality_counts,
			'import_report_quality_counts'   => $quality_counts,
			'diagnostic_summary'             => self::diagnostic_summary( $diagnostics ),
			'diagnostics'                    => $diagnostics,
			'by_category'                    => self::diagnostics_by_category( $diagnostics ),
			'blocks_engine'                  => self::blocks_engine_summary( $import_report ),
			'runtime_dependency_target_gaps' => self::runtime_dependency_target_gaps( $import_report ),
			'asset_diagnostics'              => self::diagnostics_matching_types( $diagnostics, array( 'asset', 'image', 'local_asset_not_materialized', 'missing_asset', 'dropped_image' ) ),
			'svg_diagnostics'                => self::diagnostics_matching_types( $diagnostics, array( 'svg', 'unsafe_inline_svg', 'svg_materialization_failure', 'svg_sprite_reference_failure' ) ),
			'button_style_loss_hints'        => self::diagnostics_matching_types( $diagnostics, array( 'button', 'style_loss', 'presentation_gap' ) ),
			'artifact_refs'                  => self::fixture_artifact_refs( $artifacts, $import_report ),
		);
	}

	/**
	 * Extract an import report from common provider result slots.
	 *
	 * @param array<string,mixed> $result Provider result.
	 * @return array<string,mixed>
	 */
	private static function provider_import_report( array $result ): array {
		foreach ( array( $result['import_report'] ?? null, $result['summary']['import_report'] ?? null, $result['artifacts']['import_report'] ?? null ) as $candidate ) {
			if ( is_array( $candidate ) && ( isset( $candidate['quality'] ) || isset( $candidate['diagnostics'] ) || isset( $candidate['blocks_engine'] ) ) ) {
				return $candidate;
			}
		}

		return array();
	}

	/**
	 * Build a request summary from raw input when request creation failed.
	 *
	 * @param array<string,mixed> $input Raw caller input.
	 * @return array<string,mixed>
	 */
	private static function build_error_request_summary( array $input ): array {
		return array(
			'schema'              => self::REQUEST_SCHEMA,
			'product_path'        => 'static-site-importer/validate-in-codebox',
			'execution_scope'     => 'disposable-wp-codebox-runtime',
			'has_inline_artifact' => isset( $input['artifact'] ) && is_array( $input['artifact'] ) && ! empty( $input['artifact'] ),
			'artifact_schema'     => isset( $input['artifact']['schema'] ) && is_scalar( $input['artifact']['schema'] ) ? (string) $input['artifact']['schema'] : '',
			'import_args'         => array_filter(
				array(
					'slug' => isset( $input['slug'] ) ? sanitize_title( (string) $input['slug'] ) : '',
					'name' => isset( $input['name'] ) ? sanitize_text_field( (string) $input['name'] ) : '',
				),
				static fn ( string $value ): bool => '' !== $value
			),
			'required_artifacts'  => self::required_artifacts(),
		);
	}

	/**
	 * Read provider-level diagnostic rows.
	 *
	 * @param array<string,mixed> $result Provider result.
	 * @return array<int,array<string,mixed>>
	 */
	private static function diagnostic_rows_from_result( array $result ): array {
		$rows = array();
		foreach ( array( $result['diagnostics'] ?? array(), $result['artifact_diagnostics']['diagnostics'] ?? array(), $result['import_validation_result']['diagnostics'] ?? array() ) as $candidate ) {
			if ( is_array( $candidate ) ) {
				$rows = array_merge( $rows, self::normalize_diagnostic_rows( $candidate ) );
			}
		}

		return $rows;
	}

	/**
	 * Read import-report diagnostic rows.
	 *
	 * @param array<string,mixed> $import_report Import report.
	 * @return array<int,array<string,mixed>>
	 */
	private static function diagnostic_rows_from_import_report( array $import_report ): array {
		$rows = self::normalize_diagnostic_rows( isset( $import_report['diagnostics'] ) && is_array( $import_report['diagnostics'] ) ? $import_report['diagnostics'] : array() );
		if ( isset( $import_report['artifact_diagnostics']['diagnostics'] ) && is_array( $import_report['artifact_diagnostics']['diagnostics'] ) ) {
			$rows = array_merge( $rows, self::normalize_diagnostic_rows( $import_report['artifact_diagnostics']['diagnostics'] ) );
		}

		return $rows;
	}

	/**
	 * Extract Blocks Engine conversion-report diagnostics and fallback rows.
	 *
	 * @param array<string,mixed> $import_report Import report.
	 * @return array<int,array<string,mixed>>
	 */
	private static function blocks_engine_conversion_diagnostics( array $import_report ): array {
		$conversion_report = isset( $import_report['blocks_engine']['conversion_report'] ) && is_array( $import_report['blocks_engine']['conversion_report'] ) ? $import_report['blocks_engine']['conversion_report'] : array();
		$rows              = array();
		foreach ( array( 'diagnostics', 'fallback_diagnostics', 'fallbacks', 'presentation_gaps', 'interaction_candidates' ) as $field ) {
			if ( isset( $conversion_report[ $field ] ) && is_array( $conversion_report[ $field ] ) ) {
				$rows = array_merge( $rows, self::normalize_diagnostic_rows( $conversion_report[ $field ], 'blocks_engine_conversion_report' ) );
			}
		}

		return $rows;
	}

	/**
	 * Extract runtime dependency parity target gaps.
	 *
	 * @param array<string,mixed> $import_report Import report.
	 * @return array<int,array<string,mixed>>
	 */
	private static function runtime_dependency_target_gaps( array $import_report ): array {
		$runtime_dependency_parity = isset( $import_report['blocks_engine']['runtime_dependency_parity'] ) && is_array( $import_report['blocks_engine']['runtime_dependency_parity'] ) ? $import_report['blocks_engine']['runtime_dependency_parity'] : array();
		$rows                      = array();
		foreach ( array( 'findings', 'missing_dom_targets', 'unsupported_elements' ) as $field ) {
			if ( isset( $runtime_dependency_parity[ $field ] ) && is_array( $runtime_dependency_parity[ $field ] ) ) {
				$rows = array_merge( $rows, self::normalize_diagnostic_rows( $runtime_dependency_parity[ $field ], 'runtime_dependency_parity' ) );
			}
		}

		return $rows;
	}

	/**
	 * Normalize diagnostic rows to a stable matrix-consumable subset.
	 *
	 * @param array<int|string,mixed> $rows          Raw diagnostic rows.
	 * @param string                  $default_stage Default stage.
	 * @return array<int,array<string,mixed>>
	 */
	private static function normalize_diagnostic_rows( array $rows, string $default_stage = '' ): array {
		$normalized = array();
		foreach ( array_values( $rows ) as $index => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$type        = self::first_scalar( $row, array( 'type', 'kind', 'code', 'reason_code' ), 'diagnostic' );
			$reason_code = self::first_scalar( $row, array( 'reason_code', 'code', 'reason', 'kind', 'type' ), $type );
			$source_path = self::first_scalar( $row, array( 'source_path', 'path', 'source', 'file', 'script_path' ), '' );
			$diagnostic  = array(
				'id'          => self::first_scalar( $row, array( 'id' ), sprintf( 'diag-%03d-%s', $index + 1, sanitize_key( $type . '-' . $reason_code . '-' . $source_path ) ) ),
				'type'        => sanitize_key( $type ),
				'severity'    => self::first_scalar( $row, array( 'severity', 'level' ), self::default_diagnostic_severity( $type ) ),
				'category'    => self::diagnostic_category( $type ),
				'reason_code' => sanitize_key( $reason_code ),
				'source_path' => $source_path,
				'selector'    => self::first_scalar( $row, array( 'selector', 'target_selector', 'css_selector' ), '' ),
				'code'        => self::first_scalar( $row, array( 'code', 'error_code' ), sanitize_key( $reason_code ) ),
				'stage'       => self::first_scalar( $row, array( 'stage' ), $default_stage ),
				'owner'       => self::first_scalar( $row, array( 'owner', 'engine', 'converter' ), '' ),
			);

			foreach ( array( 'message', 'reason', 'excerpt', 'source_html_preview', 'emitted_block_preview', 'html_excerpt', 'block_name', 'block_path', 'script_path', 'element', 'tag_name', 'src', 'href', 'expected', 'observed' ) as $field ) {
				$value = self::first_scalar( $row, array( $field ), '' );
				if ( '' !== $value ) {
					$diagnostic[ $field ] = $value;
				}
			}

			$normalized[] = array_filter(
				$diagnostic,
				static fn ( mixed $value ): bool => '' !== $value
			);
		}

		return $normalized;
	}

	/**
	 * Return quality counts from import report first, then compact summaries.
	 *
	 * @param array<string,mixed> $import_report Import report.
	 * @param array<string,mixed> $summary Provider summary.
	 * @return array<string,int>
	 */
	private static function quality_counts( array $import_report, array $summary ): array {
		$quality = isset( $import_report['quality'] ) && is_array( $import_report['quality'] ) ? $import_report['quality'] : $summary;
		$keys    = array( 'fallback_count', 'content_loss_count', 'empty_conversion_count', 'core_html_block_count', 'freeform_block_count', 'invalid_block_count', 'invalid_block_document_count', 'unsafe_svg_count', 'svg_materialization_failure_count', 'svg_sprite_reference_failure_count', 'commerce_dependency_failures', 'interaction_candidate_count', 'runtime_dependency_parity_issue_count', 'semantic_parity_failure_count' );

		$counts = array();
		foreach ( $keys as $key ) {
			$counts[ $key ] = isset( $quality[ $key ] ) && is_numeric( $quality[ $key ] ) ? (int) $quality[ $key ] : 0;
		}

		return $counts;
	}

	/**
	 * Summarize Blocks Engine import-report details.
	 *
	 * @param array<string,mixed> $import_report Import report.
	 * @return array<string,mixed>
	 */
	private static function blocks_engine_summary( array $import_report ): array {
		$blocks_engine = isset( $import_report['blocks_engine'] ) && is_array( $import_report['blocks_engine'] ) ? $import_report['blocks_engine'] : array();

		$summary = array();
		foreach ( array( 'website_artifact', 'conversion_report', 'runtime_dependency_parity', 'semantic_parity' ) as $field ) {
			if ( isset( $blocks_engine[ $field ] ) && is_array( $blocks_engine[ $field ] ) && ! empty( $blocks_engine[ $field ] ) ) {
				$summary[ $field ] = $blocks_engine[ $field ];
			}
		}

		return $summary;
	}

	/**
	 * Build diagnostic counts by severity and category.
	 *
	 * @param array<int,array<string,mixed>> $diagnostics Diagnostics.
	 * @return array<string,mixed>
	 */
	private static function diagnostic_summary( array $diagnostics ): array {
		$summary = array(
			'total'    => count( $diagnostics ),
			'severity' => array(),
			'category' => array(),
			'type'     => array(),
		);
		foreach ( $diagnostics as $diagnostic ) {
			foreach ( array( 'severity', 'category', 'type' ) as $field ) {
				$value                       = isset( $diagnostic[ $field ] ) && is_scalar( $diagnostic[ $field ] ) ? (string) $diagnostic[ $field ] : 'unknown';
				$summary[ $field ][ $value ] = ( $summary[ $field ][ $value ] ?? 0 ) + 1;
			}
		}

		return $summary;
	}

	/**
	 * Group diagnostics by category.
	 *
	 * @param array<int,array<string,mixed>> $diagnostics Diagnostics.
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	private static function diagnostics_by_category( array $diagnostics ): array {
		$grouped = array();
		foreach ( $diagnostics as $diagnostic ) {
			$category               = isset( $diagnostic['category'] ) && is_scalar( $diagnostic['category'] ) ? (string) $diagnostic['category'] : 'uncategorized';
			$grouped[ $category ][] = $diagnostic;
		}

		return $grouped;
	}

	/**
	 * Select diagnostics matching types or type fragments.
	 *
	 * @param array<int,array<string,mixed>> $diagnostics Diagnostics.
	 * @param array<int,string>              $needles     Type needles.
	 * @return array<int,array<string,mixed>>
	 */
	private static function diagnostics_matching_types( array $diagnostics, array $needles ): array {
		return array_values(
			array_filter(
				$diagnostics,
				static function ( array $diagnostic ) use ( $needles ): bool {
					$type     = isset( $diagnostic['type'] ) && is_scalar( $diagnostic['type'] ) ? (string) $diagnostic['type'] : '';
					$category = isset( $diagnostic['category'] ) && is_scalar( $diagnostic['category'] ) ? (string) $diagnostic['category'] : '';
					foreach ( $needles as $needle ) {
						if ( $needle === $type || str_contains( $type, $needle ) || str_contains( $category, $needle ) ) {
							return true;
						}
					}

					return false;
				}
			)
		);
	}

	/**
	 * Stable artifact references for matrix output.
	 *
	 * @param array<string,mixed> $artifacts     Validation artifacts.
	 * @param array<string,mixed> $import_report Import report.
	 * @return array<string,mixed>
	 */
	private static function fixture_artifact_refs( array $artifacts, array $import_report ): array {
		$refs = $artifacts;
		if ( isset( $import_report['import_validation_result']['artifacts'] ) && is_array( $import_report['import_validation_result']['artifacts'] ) ) {
			$refs['import_validation_artifacts'] = $import_report['import_validation_result']['artifacts'];
		}
		if ( isset( $import_report['visual_parity_artifacts'] ) && is_array( $import_report['visual_parity_artifacts'] ) ) {
			$refs['visual_parity_artifacts'] = $import_report['visual_parity_artifacts'];
		}

		return $refs;
	}

	/**
	 * Remove duplicate diagnostics by id/type/source/selector/code.
	 *
	 * @param array<int,array<string,mixed>> $diagnostics Diagnostics.
	 * @return array<int,array<string,mixed>>
	 */
	private static function dedupe_diagnostics( array $diagnostics ): array {
		$seen   = array();
		$unique = array();
		foreach ( $diagnostics as $diagnostic ) {
			$key = implode( '|', array_map( 'strval', array( $diagnostic['id'] ?? '', $diagnostic['type'] ?? '', $diagnostic['source_path'] ?? '', $diagnostic['selector'] ?? '', $diagnostic['code'] ?? '' ) ) );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;
			$unique[]     = $diagnostic;
		}

		return $unique;
	}

	/**
	 * Resolve a scalar value from candidate fields.
	 *
	 * @param array<string,mixed> $row      Source row.
	 * @param array<int,string>   $fields   Candidate fields.
	 * @param string              $fallback Fallback value.
	 * @return string
	 */
	private static function first_scalar( array $row, array $fields, string $fallback = '' ): string {
		foreach ( $fields as $field ) {
			if ( isset( $row[ $field ] ) && is_scalar( $row[ $field ] ) && '' !== trim( (string) $row[ $field ] ) ) {
				return (string) $row[ $field ];
			}
		}

		return $fallback;
	}

	/**
	 * Classify diagnostics by generic category.
	 *
	 * @param string $type Diagnostic type.
	 * @return string
	 */
	private static function diagnostic_category( string $type ): string {
		if ( str_contains( $type, 'svg' ) ) {
			return 'svg';
		}
		if ( str_contains( $type, 'asset' ) || str_contains( $type, 'image' ) ) {
			return 'asset';
		}
		if ( str_contains( $type, 'runtime_dependency' ) || str_contains( $type, 'dom_target' ) ) {
			return 'runtime_dependency_parity';
		}
		if ( str_contains( $type, 'core_html' ) || str_contains( $type, 'freeform' ) || str_contains( $type, 'fallback' ) ) {
			return 'fallback_block';
		}
		if ( str_contains( $type, 'button' ) || str_contains( $type, 'style' ) || str_contains( $type, 'presentation' ) ) {
			return 'style_loss_hint';
		}

		return 'import_quality';
	}

	/**
	 * Default diagnostic severity.
	 *
	 * @param string $type Diagnostic type.
	 * @return string
	 */
	private static function default_diagnostic_severity( string $type ): string {
		return str_contains( $type, 'missing' ) || str_contains( $type, 'invalid' ) || str_contains( $type, 'error' ) ? 'error' : 'warning';
	}

	/**
	 * Build the artifact metadata contract.
	 *
	 * @param array<string,mixed> $request   Validation request.
	 * @param array<string,mixed> $artifacts Provider artifacts.
	 * @return array<string,mixed>
	 */
	private static function artifact_contract( array $request, array $artifacts ): array {
		return array(
			'schema'                  => self::ARTIFACT_SCHEMA,
			'generated_theme'         => self::first_ref( $artifacts['generated_theme'] ?? array(), $request['source']['generated_theme_ref'] ?? array() ),
			'theme_archive'           => self::first_ref( $artifacts['theme_archive'] ?? array(), $request['source']['theme_archive_ref'] ?? array() ),
			'import_report'           => self::normalize_artifact_ref( $artifacts['import_report'] ?? array() ),
			'block_validation_result' => self::normalize_artifact_ref( $artifacts['block_validation_result'] ?? array() ),
			'browser_render_evidence' => self::normalize_artifact_ref( $artifacts['browser_render_evidence'] ?? array() ),
			'screenshots'             => self::normalize_artifact_refs( $artifacts['screenshots'] ?? array() ),
			'diffs'                   => self::normalize_artifact_refs( $artifacts['diffs'] ?? array() ),
			'raw'                     => self::normalize_artifact_refs( $artifacts['raw'] ?? array() ),
			'required'                => self::required_artifacts(),
		);
	}

	/**
	 * Required validation artifacts.
	 *
	 * @return array<int,string>
	 */
	private static function required_artifacts(): array {
		return array(
			'generated_theme_or_theme_archive',
			'import_report',
			'block_validation_result',
			'browser_render_evidence',
			'screenshot_refs_when_available',
			'diff_refs_when_available',
		);
	}

	/**
	 * Summarize a request without inline artifact content.
	 *
	 * @param array<string,mixed> $request Validation request.
	 * @return array<string,mixed>
	 */
	private static function request_summary( array $request ): array {
		$source   = isset( $request['source'] ) && is_array( $request['source'] ) ? $request['source'] : array();
		$artifact = isset( $source['artifact'] ) && is_array( $source['artifact'] ) ? $source['artifact'] : array();

		return array(
			'schema'              => $request['schema'] ?? self::REQUEST_SCHEMA,
			'product_path'        => $request['product_path'] ?? 'static-site-importer/validate-in-codebox',
			'execution_scope'     => $request['execution_scope'] ?? 'disposable-wp-codebox-runtime',
			'has_inline_artifact' => ! empty( $artifact ),
			'artifact_schema'     => isset( $artifact['schema'] ) ? (string) $artifact['schema'] : '',
			'import_args'         => isset( $request['import_args'] ) && is_array( $request['import_args'] ) ? $request['import_args'] : array(),
			'required_artifacts'  => isset( $request['required_artifacts'] ) && is_array( $request['required_artifacts'] ) ? array_values( $request['required_artifacts'] ) : self::required_artifacts(),
		);
	}

	/**
	 * Preserve local-only paths only as operator notes.
	 *
	 * @param array<string,mixed> $input Raw input.
	 * @return array<string,mixed>
	 */
	private static function operator_notes( array $input ): array {
		$notes = array();
		foreach ( array( 'generated_theme_ref', 'theme_archive_ref' ) as $field ) {
			if ( empty( $input[ $field ] ) || ! is_array( $input[ $field ] ) ) {
				continue;
			}

			foreach ( array( 'path', 'local_path', 'file' ) as $key ) {
				$value = isset( $input[ $field ][ $key ] ) ? (string) $input[ $field ][ $key ] : '';
				if ( self::is_local_only_reference( $value ) ) {
					$notes['local_refs'][ $field ][ $key ] = $value;
				}
			}
		}

		return $notes;
	}

	/**
	 * Normalize a list of artifact references.
	 *
	 * @param mixed $refs Raw refs.
	 * @return array<int,array<string,mixed>>
	 */
	private static function normalize_artifact_refs( mixed $refs ): array {
		if ( ! is_array( $refs ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $refs as $ref ) {
			$normalized_ref = self::normalize_artifact_ref( $ref );
			if ( ! empty( $normalized_ref ) ) {
				$normalized[] = $normalized_ref;
			}
		}

		return $normalized;
	}

	/**
	 * Normalize an artifact reference for reviewer-facing output.
	 *
	 * @param mixed $ref Raw ref.
	 * @return array<string,mixed>
	 */
	private static function normalize_artifact_ref( mixed $ref ): array {
		if ( ! is_array( $ref ) ) {
			return array();
		}

		$allowed = array( 'artifact_id', 'artifact_ref', 'artifact_name', 'url', 'path', 'relative_path', 'sha256', 'media_type', 'label', 'kind', 'status' );
		$output  = array();
		foreach ( $allowed as $key ) {
			if ( ! array_key_exists( $key, $ref ) ) {
				continue;
			}

			$value = is_scalar( $ref[ $key ] ) ? (string) $ref[ $key ] : $ref[ $key ];
			if ( is_string( $value ) && self::is_local_only_reference( $value ) ) {
				continue;
			}

			$output[ $key ] = $value;
		}

		return $output;
	}

	/**
	 * Return the first non-empty normalized ref.
	 *
	 * @param mixed $primary  Primary ref.
	 * @param mixed $fallback Fallback ref.
	 * @return array<string,mixed>
	 */
	private static function first_ref( mixed $primary, mixed $fallback ): array {
		$primary_ref = self::normalize_artifact_ref( $primary );
		if ( ! empty( $primary_ref ) ) {
			return $primary_ref;
		}

		return self::normalize_artifact_ref( $fallback );
	}

	/**
	 * Detect host-local paths and URLs that should not be reviewer-facing refs.
	 *
	 * @param string $value Reference value.
	 * @return bool
	 */
	private static function is_local_only_reference( string $value ): bool {
		if ( '' === $value ) {
			return false;
		}

		return str_starts_with( $value, '/' )
			|| str_starts_with( $value, 'file:' )
			|| str_contains( $value, 'localhost' )
			|| str_contains( $value, '127.0.0.1' );
	}
}

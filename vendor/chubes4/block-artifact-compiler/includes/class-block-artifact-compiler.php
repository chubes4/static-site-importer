<?php
/**
 * Website artifact to WordPress artifact compiler.
 *
 * @package BlockArtifactCompiler
 */

/**
 * Compiles website artifact bundles into WordPress-native artifact bundles.
 */
class Block_Artifact_Compiler {
	private const RESULT_SCHEMA = 'chubes4/block-artifact-compiler-result/v1';
	private const INPUT_SCHEMA  = 'chubes4/website-artifact/v1';

	/**
	 * Compile a website artifact bundle.
	 *
	 * This initial implementation establishes the artifact envelope and delegates
	 * HTML-to-block conversion to BFB/H2BC when present. Component extraction and
	 * generated custom block synthesis belong behind this same contract.
	 *
	 * @param array<string,mixed> $artifact Website artifact input.
	 * @param array<string,mixed> $options  Compiler options.
	 * @return array<string,mixed> Compiler result envelope.
	 */
	public function compile( array $artifact, array $options = array() ): array {
		$normalized = $this->normalize_artifact( $artifact );
		$entry      = $this->entry_file( $normalized );
		$html       = is_array( $entry ) ? (string) ( $entry['content'] ?? '' ) : '';
		$source     = is_array( $entry ) ? (string) ( $entry['path'] ?? '' ) : '';

		$diagnostics = array();
		if ( '' === trim( $html ) ) {
			$diagnostics[] = $this->diagnostic( 'missing_entry_html', 'error', 'No HTML entry file was available to compile.' );
		}

		$conversion = '' !== trim( $html ) ? $this->convert_html_to_blocks( $html, $options ) : array(
			'serialized_blocks' => '',
			'blocks'            => array(),
			'diagnostics'       => array(),
			'report'            => array(),
		);

		$diagnostics = array_merge( $diagnostics, $conversion['diagnostics'] );

		return array(
			'schema'              => self::RESULT_SCHEMA,
			'status'              => $this->status_from_diagnostics( $diagnostics ),
			'input'               => array(
				'schema'     => self::INPUT_SCHEMA,
				'entry_path' => $source,
				'file_count' => count( $normalized['files'] ),
			),
			'wordpress_artifacts' => array(
				'block_markup' => $conversion['serialized_blocks'],
				'blocks'       => $conversion['blocks'],
				'block_types'  => array(),
				'files'        => array(),
			),
			'provenance'          => array(
				'source_hash' => hash( 'sha256', $this->artifact_hash_payload( $normalized ) ),
				'source'      => $source,
			),
			'diagnostics'         => $diagnostics,
			'bfb_report'          => $conversion['report'],
		);
	}

	/**
	 * Normalize supported website artifact input shapes.
	 *
	 * @param array<string,mixed> $artifact Raw artifact.
	 * @return array{files:array<int,array{path:string,content:string,kind:string}>}
	 */
	private function normalize_artifact( array $artifact ): array {
		$files = $artifact['files'] ?? array();
		if ( ! is_array( $files ) ) {
			$files = array();
		}

		if ( isset( $artifact['html'] ) && is_string( $artifact['html'] ) ) {
			$files[] = array(
				'path'    => 'index.html',
				'content' => $artifact['html'],
				'kind'    => 'html',
			);
		}

		$normalized = array();
		foreach ( $files as $file ) {
			if ( ! is_array( $file ) ) {
				continue;
			}

			$path = trim( (string) ( $file['path'] ?? '' ) );
			if ( '' === $path ) {
				continue;
			}

			$normalized[] = array(
				'path'    => $path,
				'content' => (string) ( $file['content'] ?? '' ),
				'kind'    => (string) ( $file['kind'] ?? $this->kind_from_path( $path ) ),
			);
		}

		return array( 'files' => $normalized );
	}

	/**
	 * Return the HTML entry file.
	 *
	 * @param array{files:array<int,array{path:string,content:string,kind:string}>} $artifact Normalized artifact.
	 * @return array{path:string,content:string,kind:string}|null
	 */
	private function entry_file( array $artifact ): ?array {
		foreach ( $artifact['files'] as $file ) {
			if ( 'index.html' === strtolower( basename( $file['path'] ) ) ) {
				return $file;
			}
		}

		foreach ( $artifact['files'] as $file ) {
			if ( 'html' === $file['kind'] ) {
				return $file;
			}
		}

		return null;
	}

	/**
	 * Convert HTML to block markup through BFB/H2BC when available.
	 *
	 * @param string              $html    Source HTML.
	 * @param array<string,mixed> $options Compiler options.
	 * @return array{serialized_blocks:string,blocks:array,diagnostics:array<int,array<string,mixed>>,report:array<string,mixed>}
	 */
	private function convert_html_to_blocks( string $html, array $options ): array {
		if ( function_exists( 'bfb_convert' ) ) {
			$block_markup = (string) bfb_convert( $html, 'html', 'blocks', $options );
			$report       = array( 'status' => '' === trim( $block_markup ) ? 'failed' : 'success_native' );
			if ( ! empty( $options['include_bfb_report'] ) && function_exists( 'bfb_conversion_report' ) ) {
				$report = bfb_conversion_report( $html, 'html', $options );
			}

			return array(
				'serialized_blocks' => $block_markup,
				'blocks'            => array(),
				'diagnostics'       => isset( $report['diagnostics'] ) && is_array( $report['diagnostics'] ) ? $report['diagnostics'] : array(),
				'report'            => $report,
			);
		}

		return array(
			'serialized_blocks' => '<!-- wp:html -->' . "\n" . $html . "\n" . '<!-- /wp:html -->',
			'blocks'            => array(),
			'diagnostics'       => array(
				$this->diagnostic( 'bfb_unavailable', 'warning', 'BFB is unavailable; preserved source HTML as a core/html fallback.' ),
			),
			'report'            => array( 'status' => 'success_with_fallbacks' ),
		);
	}

	/**
	 * Infer a simple file kind from path.
	 */
	private function kind_from_path( string $path ): string {
		$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		return match ( $extension ) {
			'html', 'htm' => 'html',
			'css'         => 'css',
			'js', 'mjs'    => 'js',
			default       => 'asset',
		};
	}

	/**
	 * Build a normalized diagnostic entry.
	 *
	 * @return array<string,mixed>
	 */
	private function diagnostic( string $code, string $severity, string $message ): array {
		return array(
			'code'     => $code,
			'severity' => $severity,
			'message'  => $message,
		);
	}

	/**
	 * Resolve result status from diagnostics.
	 *
	 * @param array<int,array<string,mixed>> $diagnostics Diagnostics.
	 */
	private function status_from_diagnostics( array $diagnostics ): string {
		foreach ( $diagnostics as $diagnostic ) {
			if ( 'error' === ( $diagnostic['severity'] ?? '' ) ) {
				return 'failed';
			}
		}

		foreach ( $diagnostics as $diagnostic ) {
			if ( 'warning' === ( $diagnostic['severity'] ?? '' ) ) {
				return 'success_with_warnings';
			}
		}

		return 'success';
	}

	/**
	 * Build a stable hash payload for provenance.
	 *
	 * @param array{files:array<int,array{path:string,content:string,kind:string}>} $artifact Normalized artifact.
	 */
	private function artifact_hash_payload( array $artifact ): string {
		$payload = '';
		foreach ( $artifact['files'] as $file ) {
			$payload .= $file['path'] . "\0" . $file['kind'] . "\0" . $file['content'] . "\0";
		}

		return $payload;
	}
}

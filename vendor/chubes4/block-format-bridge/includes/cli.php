<?php
/**
 * WP-CLI integration.
 *
 * @package BlockFormatBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_CLI' ) ) {
	return;
}

if ( ! class_exists( 'BFB_CLI_Command' ) ) {
	/**
	 * Block Format Bridge WP-CLI commands.
	 */
	class BFB_CLI_Command {

		/**
		 * Report active conversion substrate capabilities.
		 *
		 * ## OPTIONS
		 *
		 * [--format=<format>]
		 * : Output format. Supports `json` or `summary`.
		 * ---
		 * default: summary
		 * ---
		 *
		 * @param array<int, string>   $args       Positional arguments.
		 * @param array<string, mixed> $assoc_args Associative arguments.
		 * @return void
		 */
		public function capabilities( array $args, array $assoc_args ): void {
			unset( $args );

			$format = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'summary';
			$report = bfb_capabilities();

			if ( 'json' === $format ) {
				$output = wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
				if ( false === $output ) {
					WP_CLI::error( 'Failed to encode capabilities as JSON.' );
					return;
				}
				WP_CLI::line( $output );
				return;
			}

			if ( 'summary' !== $format ) {
				WP_CLI::error( 'Unsupported --format value. Use "summary" or "json".' );
			}

			$bridge = isset( $report['bridge'] ) && is_array( $report['bridge'] ) ? $report['bridge'] : array();
			$h2bc   = isset( $report['h2bc'] ) && is_array( $report['h2bc'] ) ? $report['h2bc'] : array();
			WP_CLI::line( sprintf( 'BFB: %s', isset( $bridge['version'] ) ? (string) $bridge['version'] : 'unknown' ) );
			WP_CLI::line( sprintf( 'Formats: %s', implode( ', ', array_keys( (array) $report['formats'] ) ) ) );
			WP_CLI::line( sprintf( 'HTML -> blocks: %s', ! empty( $h2bc['available'] ) ? 'available' : 'unavailable' ) );
		}

		/**
		 * Convert content between formats.
		 *
		 * ## OPTIONS
		 *
		 * --from=<format>
		 * : Source format slug.
		 *
		 * --to=<format>
		 * : Target format slug.
		 *
		 * [--input=<file>]
		 * : Read input from a file instead of STDIN.
		 *
		 * [--output=<file>]
		 * : Write output to a file instead of STDOUT.
		 *
		 * [--as=<format>]
		 * : Output representation. Use `json` with `--to=blocks` for parsed block arrays.
		 *
		 * @param array<int, string>   $args       Positional arguments.
		 * @param array<string, mixed> $assoc_args Associative arguments.
		 * @return void
		 */
		public function convert( array $args, array $assoc_args ): void {
			unset( $args );

			$from = isset( $assoc_args['from'] ) ? (string) $assoc_args['from'] : '';
			$to   = isset( $assoc_args['to'] ) ? (string) $assoc_args['to'] : '';
			$as   = isset( $assoc_args['as'] ) ? (string) $assoc_args['as'] : 'content';

			if ( '' === $from ) {
				WP_CLI::error( 'Missing required --from=<format> argument.' );
			}

			if ( '' === $to ) {
				WP_CLI::error( 'Missing required --to=<format> argument.' );
			}

			if ( ! in_array( $as, array( 'content', 'json' ), true ) ) {
				WP_CLI::error( 'Unsupported --as value. Use "content" or "json".' );
			}

			if ( 'json' === $as && 'blocks' !== $to ) {
				WP_CLI::error( '--as=json is only supported with --to=blocks.' );
			}

			if ( 'blocks' !== $from && ! bfb_get_adapter( $from ) ) {
				WP_CLI::error( sprintf( 'No BFB adapter registered for source format: %s', $from ) );
			}

			if ( 'blocks' !== $to && ! bfb_get_adapter( $to ) ) {
				WP_CLI::error( sprintf( 'No BFB adapter registered for target format: %s', $to ) );
			}

			$content = $this->read_input( isset( $assoc_args['input'] ) ? (string) $assoc_args['input'] : '' );
			if ( 'json' === $as ) {
				$output = wp_json_encode( bfb_to_blocks( $content, $from ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
				if ( false === $output ) {
					WP_CLI::error( 'Failed to encode block output as JSON.' );
					return;
				}
			} else {
				$output = bfb_convert( $content, $from, $to );
			}

			if ( '' === $output ) {
				WP_CLI::error( sprintf( 'BFB conversion failed for %s -> %s.', $from, $to ) );
				return;
			}

			$this->write_output( $output, isset( $assoc_args['output'] ) ? (string) $assoc_args['output'] : '' );
		}

		/**
		 * Analyze conversion quality for content.
		 *
		 * ## OPTIONS
		 *
		 * --from=<format>
		 * : Source format slug.
		 *
		 * [--input=<file>]
		 * : Read input from a file instead of STDIN.
		 *
		 * [--format=<format>]
		 * : Output format. Supports `json` or `summary`.
		 * ---
		 * default: summary
		 * ---
		 *
		 * @param array<int, string>   $args       Positional arguments.
		 * @param array<string, mixed> $assoc_args Associative arguments.
		 * @return void
		 */
		public function analyze( array $args, array $assoc_args ): void {
			unset( $args );

			$from   = isset( $assoc_args['from'] ) ? (string) $assoc_args['from'] : '';
			$format = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'summary';

			if ( '' === $from ) {
				WP_CLI::error( 'Missing required --from=<format> argument.' );
			}

			if ( 'blocks' !== $from && ! bfb_get_adapter( $from ) ) {
				WP_CLI::error( sprintf( 'No BFB adapter registered for source format: %s', $from ) );
			}

			if ( ! in_array( $format, array( 'summary', 'json' ), true ) ) {
				WP_CLI::error( 'Unsupported --format value. Use "summary" or "json".' );
			}

			$content = $this->read_input( isset( $assoc_args['input'] ) ? (string) $assoc_args['input'] : '' );
			$report  = bfb_conversion_report( $content, $from );

			if ( 'json' === $format ) {
				$output = wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
				if ( false === $output ) {
					WP_CLI::error( 'Failed to encode analysis report as JSON.' );
					return;
				}
				WP_CLI::line( $output );
				return;
			}

			WP_CLI::line( sprintf( 'Blocks: %d', (int) $report['total_blocks'] ) );
			WP_CLI::line( sprintf( 'Status: %s', isset( $report['status'] ) ? (string) $report['status'] : 'unknown' ) );
			WP_CLI::line( sprintf( 'core/html blocks: %d', (int) $report['core_html_blocks'] ) );
			WP_CLI::line( sprintf( 'h2bc fallback events: %d', (int) $report['fallback_event_count'] ) );
			WP_CLI::line( sprintf( 'text retention: %.2f', isset( $report['text_retention_ratio'] ) ? (float) $report['text_retention_ratio'] : 1.0 ) );

			if ( ! empty( $report['diagnostics'] ) && is_array( $report['diagnostics'] ) ) {
				foreach ( $report['diagnostics'] as $diagnostic ) {
					if ( ! is_array( $diagnostic ) ) {
						continue;
					}

					WP_CLI::line(
						sprintf(
							'Diagnostic: %s (%s) - %s',
							isset( $diagnostic['code'] ) ? (string) $diagnostic['code'] : 'unknown',
							isset( $diagnostic['severity'] ) ? (string) $diagnostic['severity'] : 'info',
							isset( $diagnostic['message'] ) ? (string) $diagnostic['message'] : ''
						)
					);
				}
			}

			if ( ! empty( $report['agent_guidance'] ) ) {
				WP_CLI::line( sprintf( 'Agent guidance: %s', (string) $report['agent_guidance'] ) );
			}
		}

		/**
		 * Read command input.
		 *
		 * @param string $path Optional input file path.
		 * @return string Input content.
		 */
		private function read_input( string $path ): string {
			if ( '' === $path ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- php://stdin is the WP-CLI input stream.
				$content = file_get_contents( 'php://stdin' );
				return false === $content ? '' : $content;
			}

			if ( ! is_readable( $path ) ) {
				WP_CLI::error( sprintf( 'Input file is not readable: %s', $path ) );
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file input is the command contract.
			$content = file_get_contents( $path );
			if ( false === $content ) {
				WP_CLI::error( sprintf( 'Failed to read input file: %s', $path ) );
			}

			return (string) $content;
		}

		/**
		 * Write command output.
		 *
		 * @param string $content Output content.
		 * @param string $path    Optional output file path.
		 * @return void
		 */
		private function write_output( string $content, string $path ): void {
			if ( '' === $path ) {
				WP_CLI::line( $content );
				return;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Local file output is the command contract.
			$result = file_put_contents( $path, $content );
			if ( false === $result ) {
				WP_CLI::error( sprintf( 'Failed to write output file: %s', $path ) );
			}
		}
	}
}

WP_CLI::add_command( 'bfb', 'BFB_CLI_Command' );

<?php
/**
 * Version registry for dual-mode package/plugin loading.
 *
 * Multiple plugins may bundle block-format-bridge as a Composer package
 * while the standalone plugin is also installed. Every copy registers
 * its version + initializer; on `plugins_loaded:1`, the latest version
 * wins and only that initializer loads the bridge, registers adapters,
 * and installs hooks.
 *
 * @package BlockFormatBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'BFB_Versions', false ) ) {
	/**
	 * Tracks loaded block-format-bridge versions and initializes one.
	 */
	class BFB_Versions {

		/**
		 * Singleton instance.
		 *
		 * @var self|null
		 */
		private static $instance = null;

		/**
		 * Registered bridge copies.
		 *
		 * @var array<int, array{version: string, initializer: callable, source: string, order: int}>
		 */
		private $versions = array();

		/**
		 * Monotonic registration counter for deterministic same-version ties.
		 *
		 * @var int
		 */
		private $registration_order = 0;

		/**
		 * Whether the winning version has initialized.
		 *
		 * @var bool
		 */
		private $initialized = false;

		/**
		 * Whether the plugins_loaded hook has been registered.
		 *
		 * @var bool
		 */
		private static $hooked = false;

		/**
		 * Get singleton.
		 *
		 * @return self
		 */
		public static function instance(): self {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		/**
		 * Ensure latest-version initialization runs once per request.
		 *
		 * @return void
		 */
		public static function register_hooks(): void {
			if ( self::$hooked || ! function_exists( 'add_action' ) ) {
				return;
			}

			add_action( 'plugins_loaded', array( __CLASS__, 'initialize_latest_version' ), 1 );
			self::$hooked = true;
		}

		/**
		 * Register one copy of the bridge.
		 *
		 * @param string      $version     Semantic version string.
		 * @param callable    $initializer Initializer that loads this copy's files.
		 * @param string|null $source      Optional source path or label for diagnostics.
		 * @return void
		 */
		public function register( string $version, callable $initializer, ?string $source = null ): void {
			if ( $this->initialized ) {
				return;
			}

			$source = $source ? $source : $this->describe_initializer( $initializer );

			foreach ( $this->versions as $entry ) {
				if ( $version === $entry['version'] && $source !== $entry['source'] ) {
					$this->warn_duplicate_version( $version, $entry['source'], $source );
					break;
				}
			}

			$this->versions[] = array(
				'version'     => $version,
				'initializer' => $initializer,
				'source'      => $source,
				'order'       => ++$this->registration_order,
			);
		}

		/**
		 * Initialize the highest registered version.
		 *
		 * @return void
		 */
		public static function initialize_latest_version(): void {
			self::instance()->initialize_latest();
		}

		/**
		 * Initialize the highest registered version.
		 *
		 * @return void
		 */
		public function initialize_latest(): void {
			if ( $this->initialized || empty( $this->versions ) ) {
				return;
			}

			$versions = $this->versions;
			usort(
				$versions,
				static function ( array $left, array $right ): int {
					$version_compare = version_compare( $left['version'], $right['version'] );
					if ( 0 !== $version_compare ) {
						return $version_compare;
					}

					return $left['order'] <=> $right['order'];
				}
			);

			$winner      = $versions[ array_key_last( $versions ) ];
			$version     = $winner['version'];
			$initializer = $winner['initializer'];

			$this->initialized = true;
			$initializer();

			/**
			 * Fires after the winning block-format-bridge version loads.
			 *
			 * @param string $version Loaded version.
			 */
			do_action( 'bfb_loaded', $version );
		}

		/**
		 * Create a useful source label when the caller does not provide one.
		 *
		 * @param callable $initializer Initializer callback.
		 * @return string
		 */
		private function describe_initializer( callable $initializer ): string {
			if ( $initializer instanceof Closure ) {
				$reflection = new ReflectionFunction( $initializer );
				$file_name  = $reflection->getFileName();
				return $file_name ? $file_name : 'closure:' . spl_object_id( $initializer );
			}

			if ( is_string( $initializer ) ) {
				return $initializer;
			}

			if ( is_array( $initializer ) && 2 === count( $initializer ) ) {
				$class = is_object( $initializer[0] ) ? get_class( $initializer[0] ) : (string) $initializer[0];
				return $class . '::' . (string) $initializer[1];
			}

			if ( is_object( $initializer ) ) {
				return get_class( $initializer ) . ':' . spl_object_id( $initializer );
			}

			return 'callable:' . md5( gettype( $initializer ) );
		}

		/**
		 * Warn when two different sources claim the same semantic version.
		 *
		 * @param string $version         Duplicate semantic version.
		 * @param string $existing_source First registered source.
		 * @param string $new_source      Later registered source.
		 * @return void
		 */
		private function warn_duplicate_version( string $version, string $existing_source, string $new_source ): void {
			$message = sprintf(
				'Block Format Bridge version %1$s was registered by multiple sources. Same-version ties are deterministic but unsafe for different dev-main commits; the last registration wins. Existing source: %2$s. New source: %3$s.',
				$version,
				$existing_source,
				$new_source
			);

			if ( function_exists( '_doing_it_wrong' ) ) {
				_doing_it_wrong( __METHOD__, esc_html( $message ), esc_html( $version ) );
				return;
			}

			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error -- Deliberate early-load diagnostic before BFB helpers are available.
			trigger_error( esc_html( $message ), E_USER_WARNING );
		}
	}
}

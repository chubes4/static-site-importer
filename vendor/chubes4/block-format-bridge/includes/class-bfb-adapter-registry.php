<?php
/**
 * Adapter registry.
 *
 * Static map keyed by adapter slug. Adapters are typically registered
 * during the `bfb_bootstrap()` hook on `plugins_loaded`, but extensions
 * may register additional adapters at any time before they are
 * resolved by `bfb_convert()`.
 *
 * Use `bfb_get_adapter()` from outside the registry to perform a
 * filter-aware lookup.
 *
 * @package BlockFormatBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static adapter registry.
 */
class BFB_Adapter_Registry {

	/**
	 * Slug => adapter instance map.
	 *
	 * @var array<string, BFB_Format_Adapter>
	 */
	private static $adapters = array();

	/**
	 * Register a format adapter.
	 *
	 * Later registrations replace earlier ones with the same slug, so
	 * consumers can override built-in adapters by registering after
	 * `bfb_adapters_registered`.
	 *
	 * @param BFB_Format_Adapter $adapter Adapter to register.
	 * @return void
	 */
	public static function register( BFB_Format_Adapter $adapter ): void {
		self::$adapters[ $adapter->slug() ] = $adapter;
	}

	/**
	 * Look up an adapter by slug.
	 *
	 * The `bfb_register_format_adapter` filter runs on every lookup so
	 * consumers can lazily provide adapters without committing to the
	 * registration order.
	 *
	 * @param string $slug Adapter slug.
	 * @return BFB_Format_Adapter|null Adapter, or null if none matched.
	 */
	public static function get( string $slug ): ?BFB_Format_Adapter {
		$adapter = self::$adapters[ $slug ] ?? null;

		/**
		 * Filters the adapter resolved for a given slug.
		 *
		 * Allows lazy registration: hook into this filter and return a
		 * fresh adapter instance when $slug matches your format.
		 *
		 * @since 0.1.0
		 *
		 * @param BFB_Format_Adapter|null $adapter The resolved adapter, or null if none registered.
		 * @param string                  $slug    The requested slug.
		 */
		$adapter = apply_filters( 'bfb_register_format_adapter', $adapter, $slug );

		return $adapter instanceof BFB_Format_Adapter ? $adapter : null;
	}

	/**
	 * Returns every registered slug.
	 *
	 * Useful for diagnostics. Does not run the filter — it only
	 * reflects what has been explicitly registered.
	 *
	 * @return string[]
	 */
	public static function slugs(): array {
		return array_keys( self::$adapters );
	}

	/**
	 * Reset the registry (test helper).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$adapters = array();
	}
}

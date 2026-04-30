<?php
/**
 * Runtime bootstrap for the winning bridge version.
 *
 * Loaded by `library.php` after the version registry selects this copy.
 * Registers built-in adapters and installs the bridge's write/read hooks.
 *
 * @package BlockFormatBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'bfb_bootstrap' ) ) {
	/**
	 * Registers the built-in adapters exactly once.
	 *
	 * Other plugins can register additional adapters by hooking into
	 * the `bfb_register_format_adapter` filter, which fires for each
	 * lookup in the registry.
	 *
	 * @return void
	 */
	function bfb_bootstrap(): void {
		static $bootstrapped = false;
		if ( $bootstrapped ) {
			return;
		}
		$bootstrapped = true;

		BFB_Adapter_Registry::register( new BFB_HTML_Adapter() );
		BFB_Adapter_Registry::register( new BFB_Markdown_Adapter() );

		/**
		 * Fires after the built-in adapters are registered, so consumers
		 * can register additional format adapters.
		 *
		 * @since 0.1.0
		 */
		do_action( 'bfb_adapters_registered' );
	}
}

bfb_bootstrap();

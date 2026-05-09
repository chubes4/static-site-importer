<?php

/**
 * Convert simple semantic hero sections before they fall back to raw HTML.
 *
 * The importer smoke fixtures and many generated storefronts use a direct
 * `<section class="hero">` with a heading and optional paragraph. Registering
 * this shape as a reusable section converter keeps that semantic content in
 * structured core blocks instead of emitting a `core/freeform` safety fallback.
 */
function ssi_convert_simple_hero_section( $html ) {
	if ( ! is_string( $html ) || '' === trim( $html ) ) {
		return $html;
	}

	$pattern = '#<section\b([^>]*)class=(["\'])(?=[^"\']*\bhero\b)([^"\']*)\2([^>]*)>(.*?)</section>#is';

	return preg_replace_callback(
		$pattern,
		static function ( $matches ) {
			$inner = $matches[5];

			if ( ! preg_match( '#<h([1-6])\b[^>]*>(.*?)</h\1>#is', $inner, $heading_match ) ) {
				return $matches[0];
			}

			$level        = max( 1, min( 6, (int) $heading_match[1] ) );
			$heading_text = trim( wp_strip_all_tags( $heading_match[2] ) );
			if ( '' === $heading_text ) {
				return $matches[0];
			}

			$paragraph_text = '';
			if ( preg_match( '#<p\b[^>]*>(.*?)</p>#is', $inner, $paragraph_match ) ) {
				$paragraph_text = trim( wp_strip_all_tags( $paragraph_match[1] ) );
			}

			$blocks = array(
				'<!-- wp:group {"className":"hero","layout":{"type":"constrained"}} -->',
				'<div class="wp-block-group hero">',
				'<!-- wp:heading {"level":' . $level . '} -->',
				'<h' . $level . ' class="wp-block-heading">' . esc_html( $heading_text ) . '</h' . $level . '>',
				'<!-- /wp:heading -->',
			);

			if ( '' !== $paragraph_text ) {
				$blocks[] = '<!-- wp:paragraph -->';
				$blocks[] = '<p>' . esc_html( $paragraph_text ) . '</p>';
				$blocks[] = '<!-- /wp:paragraph -->';
			}

			$blocks[] = '</div>';
			$blocks[] = '<!-- /wp:group -->';

			return implode( "\n", $blocks );
		},
		$html
	);
}


/**
 * Plugin Name: Static Site Importer
 * Description: Import static HTML sites into WordPress pages or block themes using Block Format Bridge.
 * Version: 0.4.0
 * Author: Chris Huber
 * Requires at least: 6.9
 * Requires PHP: 8.1
 * Text Domain: static-site-importer
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'STATIC_SITE_IMPORTER_PATH', plugin_dir_path( __FILE__ ) );
define( 'STATIC_SITE_IMPORTER_URL', plugin_dir_url( __FILE__ ) );

if ( is_readable( STATIC_SITE_IMPORTER_PATH . 'vendor/autoload.php' ) ) {
	require_once STATIC_SITE_IMPORTER_PATH . 'vendor/autoload.php';
}

require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-document.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-source-page.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-url-fetcher.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-woo-product-seeder.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-theme-generator.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/abilities.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-admin.php';

if ( defined( 'WP_CLI' ) ) {
	require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-cli-command.php';
}

add_action(
	'plugins_loaded',
	static function (): void {
		Static_Site_Importer_Admin::register();

		if ( defined( 'WP_CLI' ) ) {
			WP_CLI::add_command( 'static-site-importer', 'Static_Site_Importer_CLI_Command' );
		}
	}
);

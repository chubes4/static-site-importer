<?php
/**
 * Markdown source parser.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Parses Markdown files and strips conservative YAML frontmatter.
 */
class Static_Site_Importer_Markdown_Source {

	/**
	 * Parse a Markdown source file.
	 *
	 * @param string $path Markdown file path.
	 * @return array{body:string,metadata:array<string,string>}|WP_Error
	 */
	public static function from_file( string $path ) {
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return new WP_Error( 'static_site_importer_unreadable_markdown_file', sprintf( 'Markdown file is not readable: %s', $path ) );
		}

		$markdown = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local Markdown source file selected for import.
		if ( false === $markdown || '' === trim( $markdown ) ) {
			return new WP_Error( 'static_site_importer_empty_markdown_file', sprintf( 'Markdown file is empty: %s', $path ) );
		}

		return self::parse( $markdown, basename( $path ) );
	}

	/**
	 * Parse Markdown source content.
	 *
	 * @param string $markdown Markdown source.
	 * @param string $source   Source label for diagnostics.
	 * @return array{body:string,metadata:array<string,string>}|WP_Error
	 */
	public static function parse( string $markdown, string $source = 'markdown source' ) {
		$markdown = preg_replace( "/\r\n?|\n/", "\n", $markdown ) ?? $markdown;
		$markdown = preg_replace( '/^\xEF\xBB\xBF/', '', $markdown ) ?? $markdown;

		$first_line = strtok( $markdown, "\n" );
		$first_line = false === $first_line ? '' : $first_line;
		if ( ! str_starts_with( $markdown, "---\n" ) && '---' !== trim( $first_line ) ) {
			return array(
				'body'     => $markdown,
				'metadata' => array(),
			);
		}

		$lines = explode( "\n", $markdown );
		if ( '---' !== trim( $lines[0] ) ) {
			return array(
				'body'     => $markdown,
				'metadata' => array(),
			);
		}

		$closing_line = null;
		$count        = count( $lines );
		for ( $i = 1; $i < $count; $i++ ) {
			if ( '---' === trim( $lines[ $i ] ) ) {
				$closing_line = $i;
				break;
			}
		}

		if ( null === $closing_line ) {
			return new WP_Error( 'static_site_importer_malformed_markdown_frontmatter', sprintf( 'Malformed frontmatter in %s: missing closing --- delimiter.', $source ) );
		}

		$metadata = self::parse_frontmatter_lines( array_slice( $lines, 1, $closing_line - 1 ), $source );
		if ( is_wp_error( $metadata ) ) {
			return $metadata;
		}

		return array(
			'body'     => ltrim( implode( "\n", array_slice( $lines, $closing_line + 1 ) ), "\n" ),
			'metadata' => $metadata,
		);
	}

	/**
	 * Parse conservative key/value YAML frontmatter lines.
	 *
	 * @param array<int,string> $lines  Frontmatter lines.
	 * @param string            $source Source label for diagnostics.
	 * @return array<string,string>|WP_Error
	 */
	private static function parse_frontmatter_lines( array $lines, string $source ) {
		$metadata = array();
		foreach ( $lines as $index => $line ) {
			$trimmed = trim( $line );
			if ( '' === $trimmed || str_starts_with( $trimmed, '#' ) ) {
				continue;
			}

			if ( ! preg_match( '/^([A-Za-z0-9_-]+):\s*(.*)$/', $line, $matches ) ) {
				return new WP_Error( 'static_site_importer_malformed_markdown_frontmatter', sprintf( 'Malformed frontmatter in %s on line %d: expected "key: value".', $source, $index + 2 ) );
			}

			$key   = strtolower( str_replace( '-', '_', $matches[1] ) );
			$value = trim( $matches[2] );
			if ( '' !== $value && ( '[' === $value[0] || '{' === $value[0] ) ) {
				return new WP_Error( 'static_site_importer_malformed_markdown_frontmatter', sprintf( 'Malformed frontmatter in %s on line %d: only scalar metadata values are supported.', $source, $index + 2 ) );
			}

			$metadata[ $key ] = self::unquote_scalar( $value );
		}

		return $metadata;
	}

	/**
	 * Unquote a simple YAML scalar value.
	 *
	 * @param string $value Scalar value.
	 * @return string
	 */
	private static function unquote_scalar( string $value ): string {
		$length = strlen( $value );
		if ( $length >= 2 ) {
			$quote = $value[0];
			if ( ( '"' === $quote || "'" === $quote ) && $quote === $value[ $length - 1 ] ) {
				return substr( $value, 1, -1 );
			}
		}

		return $value;
	}
}

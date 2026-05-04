<?php
/**
 * Static site source page model.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Represents one importable source page, regardless of source format.
 */
class Static_Site_Importer_Source_Page {

	/**
	 * Source path relative to the static site root.
	 *
	 * @var string
	 */
	private string $source_key;

	/**
	 * Absolute source file path.
	 *
	 * @var string
	 */
	private string $path;

	/**
	 * Source kind.
	 *
	 * @var string
	 */
	private string $type;

	/**
	 * HTML document used by the importer.
	 *
	 * @var Static_Site_Importer_Document
	 */
	private Static_Site_Importer_Document $document;

	/**
	 * Raw frontmatter captured for follow-up metadata parsing work.
	 *
	 * @var string
	 */
	private string $frontmatter;

	/**
	 * Constructor.
	 *
	 * @param string                        $source_key  Source key.
	 * @param string                        $path        Absolute source path.
	 * @param string                        $type        Source type.
	 * @param Static_Site_Importer_Document $document    Parsed HTML document.
	 * @param string                        $frontmatter Raw frontmatter.
	 */
	private function __construct( string $source_key, string $path, string $type, Static_Site_Importer_Document $document, string $frontmatter = '' ) {
		$this->source_key  = $source_key;
		$this->path        = $path;
		$this->type        = $type;
		$this->document    = $document;
		$this->frontmatter = $frontmatter;
	}

	/**
	 * Create a source page from an HTML file.
	 *
	 * @param string $site_dir Static site root.
	 * @param string $path     Absolute HTML file path.
	 * @return self|WP_Error
	 */
	public static function from_html_file( string $site_dir, string $path ) {
		$document = Static_Site_Importer_Document::from_file( $path );
		if ( is_wp_error( $document ) ) {
			return $document;
		}

		return new self( self::relative_source_key( $site_dir, $path ), $path, 'html', $document );
	}

	/**
	 * Create a source page from a Markdown file.
	 *
	 * This intentionally only strips and carries raw frontmatter. Issue #137 owns
	 * full metadata parsing; this class leaves the seam without baking schema in.
	 *
	 * @param string $site_dir Static site root.
	 * @param string $path     Absolute Markdown file path.
	 * @return self|WP_Error
	 */
	public static function from_markdown_file( string $site_dir, string $path ) {
		if ( ! is_file( $path ) ) {
			return new WP_Error( 'static_site_importer_unreadable_file', sprintf( 'Markdown file is not readable: %s', $path ) );
		}

		$markdown = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads local static-site Markdown source files from the import directory.
		if ( false === $markdown || '' === trim( $markdown ) ) {
			return new WP_Error( 'static_site_importer_empty_file', sprintf( 'Markdown file is empty: %s', $path ) );
		}

		$parts       = self::strip_frontmatter( $markdown );
		$content     = $parts['content'];
		$frontmatter = $parts['frontmatter'];
		$html        = self::markdown_to_html( $content );
		if ( is_wp_error( $html ) ) {
			return $html;
		}

		return new self( self::relative_source_key( $site_dir, $path ), $path, 'markdown', new Static_Site_Importer_Document( '<main>' . $html . '</main>' ), $frontmatter );
	}

	/**
	 * Source key used by result maps and reports.
	 *
	 * @return string
	 */
	public function source_key(): string {
		return $this->source_key;
	}

	/**
	 * Absolute source file path.
	 *
	 * @return string
	 */
	public function path(): string {
		return $this->path;
	}

	/**
	 * Source type.
	 *
	 * @return string
	 */
	public function type(): string {
		return $this->type;
	}

	/**
	 * Parsed document.
	 *
	 * @return Static_Site_Importer_Document
	 */
	public function document(): Static_Site_Importer_Document {
		return $this->document;
	}

	/**
	 * Raw frontmatter for future metadata coordination.
	 *
	 * @return string
	 */
	public function frontmatter(): string {
		return $this->frontmatter;
	}

	/**
	 * Build a normalized source key relative to the site root.
	 *
	 * @param string $site_dir Static site root.
	 * @param string $path     Absolute source path.
	 * @return string
	 */
	private static function relative_source_key( string $site_dir, string $path ): string {
		$root = realpath( $site_dir );
		$file = realpath( $path );
		if ( false !== $root && false !== $file && str_starts_with( $file, trailingslashit( $root ) ) ) {
			return str_replace( DIRECTORY_SEPARATOR, '/', substr( $file, strlen( trailingslashit( $root ) ) ) );
		}

		return basename( $path );
	}

	/**
	 * Strip leading YAML-style frontmatter without interpreting its schema.
	 *
	 * @param string $markdown Markdown source.
	 * @return array{frontmatter:string,content:string}
	 */
	private static function strip_frontmatter( string $markdown ): array {
		if ( 1 !== preg_match( '/\A---\R(?P<frontmatter>.*?)\R---\R?/s', $markdown, $matches ) ) {
			return array(
				'frontmatter' => '',
				'content'     => $markdown,
			);
		}

		return array(
			'frontmatter' => (string) $matches['frontmatter'],
			'content'     => substr( $markdown, strlen( $matches[0] ) ),
		);
	}

	/**
	 * Convert Markdown to HTML using the bundled CommonMark runtime.
	 *
	 * @param string $markdown Markdown source.
	 * @return string|WP_Error
	 */
	private static function markdown_to_html( string $markdown ) {
		if ( ! class_exists( '\League\CommonMark\CommonMarkConverter' ) ) {
			return new WP_Error( 'static_site_importer_missing_commonmark', 'League CommonMark is required to import Markdown content files.' );
		}

		$converter = new \League\CommonMark\CommonMarkConverter();
		return (string) $converter->convert( $markdown );
	}
}

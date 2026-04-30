<?php
/**
 * Smoke coverage for malformed and mixed content normalization.
 *
 * @package BlockFormatBridge
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/../' );

class WP_Error {
	/**
	 * @var string
	 */
	private $code;

	/**
	 * @var string
	 */
	private $message;

	/**
	 * @var mixed
	 */
	private $data;

	public function __construct( string $code = '', string $message = '', $data = null ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}

	public function get_error_code(): string {
		return $this->code;
	}

	public function get_error_message(): string {
		return $this->message;
	}

	public function get_error_data() {
		return $this->data;
	}
}

function is_wp_error( $value ): bool {
	return $value instanceof WP_Error;
}

function bfb_smoke_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

function bfb_smoke_assert_error( $value, string $code, string $message ): void {
	bfb_smoke_assert( is_wp_error( $value ), $message . ' should return WP_Error.' );
	bfb_smoke_assert( $code === $value->get_error_code(), $message . " should use {$code}." );
	bfb_smoke_assert( '' !== $value->get_error_message(), $message . ' should include a repairable message.' );
}

function bfb_get_adapter( string $format ) {
	return in_array( $format, array( 'html', 'markdown' ), true ) ? new stdClass() : null;
}

require_once __DIR__ . '/../includes/normalization.php';

$valid_blocks = '<!-- wp:heading {"level":2} --><h2>Title</h2><!-- /wp:heading -->'
	. '<!-- wp:paragraph --><p>Copy.</p><!-- /wp:paragraph -->'
	. '<!-- wp:latest-posts /-->';

$normalized = bfb_normalize( $valid_blocks, 'blocks' );
bfb_smoke_assert( $valid_blocks === $normalized, 'Valid block markup should normalize stably.' );
bfb_smoke_assert( $normalized === bfb_normalize( $normalized, 'blocks' ), 'Valid block normalization should be idempotent.' );

bfb_smoke_assert_error(
	bfb_normalize( '<!-- wp:paragraph --><p>Unclosed</p>', 'blocks' ),
	'bfb_blocks_unclosed_comment',
	'Unclosed block comment'
);

bfb_smoke_assert_error(
	bfb_normalize( '<!-- wp:quote --><blockquote><!-- wp:paragraph --><p>Copy</p><!-- /wp:quote --></blockquote><!-- /wp:paragraph -->', 'blocks' ),
	'bfb_blocks_mismatched_comment',
	'Mismatched block comment'
);

bfb_smoke_assert_error(
	bfb_normalize( '<!-- wp:heading --><h2>Title</h2><!-- /wp:heading -->\n# Markdown outside\n<!-- wp:paragraph --><p>Copy</p><!-- /wp:paragraph -->', 'blocks' ),
	'bfb_blocks_mixed_content',
	'Raw markdown between serialized blocks'
);

bfb_smoke_assert_error(
	bfb_normalize( '<!-- wp:heading --><h2>Title</h2><!-- /wp:heading --><div>Raw HTML</div><!-- wp:paragraph --><p>Copy</p><!-- /wp:paragraph -->', 'blocks' ),
	'bfb_blocks_mixed_content',
	'Raw HTML between serialized blocks'
);

bfb_smoke_assert_error(
	bfb_normalize( '<!-- wp:paragraph --><p>Bad</p><!-- /wp:paragraph', 'blocks' ),
	'bfb_blocks_unclosed_comment',
	'Malformed trailing block comment should not pass as valid blocks'
);

bfb_smoke_assert_error(
	bfb_normalize( '# Declared markdown\n\n<!-- wp:paragraph --><p>Block</p><!-- /wp:paragraph -->', 'markdown' ),
	'bfb_markdown_contains_blocks',
	'Markdown containing serialized block comments'
);

$markdown = "# Heading\r\n\r\nParagraph.";
bfb_smoke_assert( "# Heading\n\nParagraph." === bfb_normalize( $markdown, 'markdown' ), 'Markdown normalization should normalize line endings.' );

bfb_smoke_assert_error(
	bfb_normalize( "<h1>Declared HTML</h1>\n```php\necho \"hi\";\n```", 'html' ),
	'bfb_html_contains_markdown',
	'HTML containing markdown fences'
);

bfb_smoke_assert_error(
	bfb_normalize( "<p>Declared HTML</p>\n# Markdown heading", 'html' ),
	'bfb_html_contains_markdown',
	'HTML containing markdown headings'
);

bfb_smoke_assert_error(
	bfb_normalize( '<p>Declared HTML</p><!-- wp:paragraph --><p>Block</p><!-- /wp:paragraph -->', 'html' ),
	'bfb_html_contains_blocks',
	'HTML containing serialized block comments'
);

$html = '<section><h2>Valid HTML</h2><p>Copy.</p></section>';
bfb_smoke_assert( $html === bfb_normalize( $html, 'html' ), 'Valid HTML should normalize stably.' );
bfb_smoke_assert( $html === bfb_normalize( bfb_normalize( $html, 'html' ), 'html' ), 'Valid HTML normalization should be idempotent.' );

$same_format_bad = bfb_normalize( '<!-- wp:paragraph --><p>Still open</p>', 'blocks' );
bfb_smoke_assert_error( $same_format_bad, 'bfb_blocks_unclosed_comment', 'Bad same-format block input' );

echo "PASS: content normalization contract\n";

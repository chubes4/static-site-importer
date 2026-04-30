<?php
/**
 * Focused smoke coverage for bfb_normalize() branch and error contracts.
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

function bfb_smoke_assert_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		fwrite( STDERR, "FAIL: {$message}\nExpected: " . var_export( $expected, true ) . "\nActual:   " . var_export( $actual, true ) . "\n" );
		exit( 1 );
	}
}

function bfb_smoke_assert_error( $value, string $code, array $data, string $message ): void {
	bfb_smoke_assert( is_wp_error( $value ), $message . ' should return WP_Error.' );
	bfb_smoke_assert_same( $code, $value->get_error_code(), $message . ' should use the expected code.' );
	bfb_smoke_assert( '' !== $value->get_error_message(), $message . ' should include a useful message.' );

	foreach ( $data as $key => $expected ) {
		$error_data = $value->get_error_data();
		bfb_smoke_assert( is_array( $error_data ) && array_key_exists( $key, $error_data ), $message . " should include error data key {$key}." );
		bfb_smoke_assert_same( $expected, $error_data[ $key ], $message . " should include expected error data for {$key}." );
	}
}

function bfb_get_adapter( string $format ) {
	return 'custom' === $format ? new stdClass() : null;
}

require_once __DIR__ . '/../includes/normalization.php';

// Mode validation.
bfb_smoke_assert_error(
	bfb_normalize( 'copy', 'markdown', array( 'mode' => 'careful' ) ),
	'bfb_invalid_normalize_mode',
	array( 'mode' => 'careful' ),
	'Unsupported normalization mode'
);

// Blocks: valid, nested, empty, and every explicit malformed branch.
$valid_blocks = '<!-- wp:heading {"level":2} --><h2>Title</h2><!-- /wp:heading -->'
	. '<!-- wp:paragraph --><p>Copy.</p><!-- /wp:paragraph -->'
	. '<!-- wp:latest-posts /-->';
bfb_smoke_assert_same( $valid_blocks, bfb_normalize( $valid_blocks, 'blocks' ), 'Valid serialized blocks should normalize stably.' );

$nested_blocks = '<!-- wp:group --><div class="wp-block-group">'
	. '<!-- wp:columns --><div class="wp-block-columns">'
	. '<!-- wp:column --><div class="wp-block-column"><!-- wp:paragraph --><p>Nested.</p><!-- /wp:paragraph --></div><!-- /wp:column -->'
	. '</div><!-- /wp:columns -->'
	. '</div><!-- /wp:group -->';
bfb_smoke_assert_same( $nested_blocks, bfb_normalize( $nested_blocks, 'blocks' ), 'Nested serialized blocks should normalize stably.' );

bfb_smoke_assert_same( '', bfb_normalize( "\n\t ", 'blocks' ), 'Empty block input should normalize to an empty string.' );

bfb_smoke_assert_error(
	bfb_normalize( '<p>Freeform only.</p>', 'blocks' ),
	'bfb_blocks_missing_comments',
	array( 'format' => 'blocks' ),
	'Blocks without serialized comments'
);

bfb_smoke_assert_error(
	bfb_normalize( '<!-- wp:paragraph --><p>Unclosed.</p>', 'blocks' ),
	'bfb_blocks_unclosed_comment',
	array( 'open_blocks' => array( 'paragraph' ) ),
	'Unclosed serialized block comment'
);

bfb_smoke_assert_error(
	bfb_normalize( '<!-- wp:quote --><blockquote><!-- wp:paragraph --><p>Copy.</p><!-- /wp:quote --></blockquote><!-- /wp:paragraph -->', 'blocks' ),
	'bfb_blocks_mismatched_comment',
	array(
		'expected' => 'paragraph',
		'actual'   => 'quote',
	),
	'Mismatched serialized block close comment'
);

bfb_smoke_assert_error(
	bfb_normalize( '<!-- wp:heading --><h2>Title</h2><!-- /wp:heading --><div>Raw HTML</div><!-- wp:paragraph --><p>Copy.</p><!-- /wp:paragraph -->', 'blocks' ),
	'bfb_blocks_mixed_content',
	array(
		'format'  => 'blocks',
		'excerpt' => '<div>Raw HTML</div>',
	),
	'Freeform HTML mixed between serialized blocks'
);

bfb_smoke_assert_error(
	bfb_normalize( '<!-- wp:Paragraph --><p>Copy.</p><!-- /wp:Paragraph -->', 'blocks' ),
	'bfb_blocks_malformed_comment',
	array(
		'format'  => 'blocks',
		'comment' => '<!-- wp:Paragraph -->',
	),
	'Malformed serialized block comment'
);

// Markdown: stable line endings, block-looking syntax rejection, empty input.
$markdown = "# Heading\r\n\r\nPlain **markdown**.";
bfb_smoke_assert_same( "# Heading\n\nPlain **markdown**.", bfb_normalize( $markdown, 'markdown' ), 'Markdown should normalize CRLF to LF.' );
bfb_smoke_assert_same( '', bfb_normalize( '', 'markdown' ), 'Empty markdown should remain empty.' );
bfb_smoke_assert_error(
	bfb_normalize( "# Heading\n\n<!-- wp:paragraph --><p>Block-looking.</p><!-- /wp:paragraph -->", 'markdown' ),
	'bfb_markdown_contains_blocks',
	array( 'format' => 'markdown' ),
	'Markdown containing serialized block comments'
);

// HTML: stable valid/tag-soup input, block-comment rejection, markdown marker rejection, empty input.
$html = '<section><h2>Valid HTML</h2><p>Copy.</p></section>';
bfb_smoke_assert_same( $html, bfb_normalize( $html, 'html' ), 'Valid HTML should normalize stably.' );

$tag_soup = '<div><p>Malformed but declared HTML';
bfb_smoke_assert_same( $tag_soup, bfb_normalize( $tag_soup, 'html' ), 'Malformed tag soup should stay HTML unless it mixes formats.' );
bfb_smoke_assert_same( '', bfb_normalize( '', 'html' ), 'Empty HTML should remain empty.' );

bfb_smoke_assert_error(
	bfb_normalize( '<p>Declared HTML</p><!-- wp:paragraph --><p>Block.</p><!-- /wp:paragraph -->', 'html' ),
	'bfb_html_contains_blocks',
	array( 'format' => 'html' ),
	'HTML containing serialized block comments'
);

bfb_smoke_assert_error(
	bfb_normalize( "<p>Declared HTML</p>\n> Markdown quote", 'html' ),
	'bfb_html_contains_markdown',
	array( 'format' => 'html' ),
	'HTML containing markdown markers'
);

// Unknown formats reject deterministically; registered non-core adapters pass through.
bfb_smoke_assert_error(
	bfb_normalize( 'content', 'asciidoc' ),
	'bfb_unknown_format',
	array( 'format' => 'asciidoc' ),
	'Unknown normalization format'
);
bfb_smoke_assert_same( 'custom content', bfb_normalize( 'custom content', 'custom' ), 'Registered custom formats should pass through unchanged.' );

echo "PASS: normalization API branch contracts\n";

<?php

namespace BlockFormatBridge\Vendor;

/**
 * Gutenberg rawHandler parity contract for deterministic static HTML.
 *
 * @package HtmlToBlocksConverter
 */
/**
 * Exercises the supported subset h2bc intentionally keeps aligned with
 * Gutenberg rawHandler behavior.
 */
class GutenbergRawHandlerParityUnitTest extends WP_UnitTestCase
{
    /**
     * Static raw HTML fixtures should produce the same core block families that
     * Gutenberg rawHandler infers from equivalent deterministic markup.
     */
    public function test_static_gutenberg_rawhandler_parity_fixtures(): void
    {
        foreach ($this->parity_fixtures() as $label => $fixture) {
            $fallbacks = array();
            $listener = static function (string $html, array $context, array $block) use (&$fallbacks): void {
                $fallbacks[] = array('html' => $html, 'context' => $context, 'block' => $block);
            };
            \add_action('html_to_blocks_unsupported_html_fallback', $listener, 10, 3);
            try {
                $blocks = html_to_blocks_raw_handler(array('HTML' => $fixture['html']));
            } finally {
                remove_action('html_to_blocks_unsupported_html_fallback', $listener, 10);
            }
            $this->assertSame($fixture['expected_names'], $this->flatten_block_names($blocks), "{$label} should match Gutenberg rawHandler block family parity.");
            $this->assertSame(array(), $fallbacks, "{$label} should not fall back to core/html.");
            $combined = $this->combined_block_payload($blocks);
            foreach ($fixture['snippets'] ?? array() as $snippet) {
                $this->assertStringContainsString($snippet, $combined, "{$label} should preserve {$snippet}.");
            }
        }
    }
    /**
     * The parity documentation should name both the covered static contract and
     * the paste/editor behavior that remains intentionally out of scope.
     */
    public function test_parity_document_names_scope_boundary(): void
    {
        $doc = \file_get_contents(\dirname(__DIR__) . '/docs/gutenberg-rawhandler-parity.md');
        $this->assertIsString($doc);
        $this->assertStringContainsString('deterministic static HTML', $doc);
        $this->assertStringContainsString('Google Docs', $doc);
        $this->assertStringContainsString('Microsoft Word', $doc);
        $this->assertStringContainsString('Dynamic, contextual, or Site Editor block inference', $doc);
    }
    /**
     * Gutenberg-compatible static fixture matrix.
     *
     * @return array<string,array{html:string,expected_names:string[],snippets?:string[]}>
     */
    private function parity_fixtures(): array
    {
        return array('heading' => array('html' => '<h2>Fixture Heading</h2>', 'expected_names' => array('core/heading'), 'snippets' => array('Fixture Heading')), 'paragraph' => array('html' => '<p>Fixture <strong>paragraph</strong>.</p>', 'expected_names' => array('core/paragraph'), 'snippets' => array('<strong>paragraph</strong>')), 'list' => array('html' => '<ul><li>One<ul><li>Child</li></ul></li><li>Two</li></ul>', 'expected_names' => array('core/list', 'core/list-item', 'core/list', 'core/list-item', 'core/list-item'), 'snippets' => array('Child', 'Two')), 'quote' => array('html' => '<blockquote><p>Quote text</p><cite>Source</cite></blockquote>', 'expected_names' => array('core/quote', 'core/paragraph', 'core/paragraph'), 'snippets' => array('Quote text', 'Source')), 'image' => array('html' => '<figure><img src="photo.jpg" alt="Alt text"><figcaption>Caption text</figcaption></figure>', 'expected_names' => array('core/image'), 'snippets' => array('photo.jpg', 'Alt text', 'Caption text')), 'code' => array('html' => '<pre><code>const answer = 42;</code></pre>', 'expected_names' => array('core/code'), 'snippets' => array('const answer = 42;')), 'preformatted' => array('html' => '<pre>Plain preformatted text</pre>', 'expected_names' => array('core/preformatted'), 'snippets' => array('Plain preformatted text')), 'separator' => array('html' => '<hr>', 'expected_names' => array('core/separator')), 'table' => array('html' => '<table><thead><tr><th>Name</th></tr></thead><tbody><tr><td>Ada</td></tr></tbody></table>', 'expected_names' => array('core/table'), 'snippets' => array('Name', 'Ada')), 'shortcode' => array('html' => '[gallery ids="1,2"]', 'expected_names' => array('core/shortcode'), 'snippets' => array('[gallery ids="1,2"]')), 'group' => array('html' => '<div class="wp-block-group"><p>Grouped copy</p></div>', 'expected_names' => array('core/group', 'core/paragraph'), 'snippets' => array('Grouped copy')), 'columns' => array('html' => '<div class="wp-block-columns"><div class="wp-block-column"><p>Left</p></div><div class="wp-block-column"><p>Right</p></div></div>', 'expected_names' => array('core/columns', 'core/column', 'core/paragraph', 'core/column', 'core/paragraph'), 'snippets' => array('Left', 'Right')), 'cover' => array('html' => '<section class="hero cover" style="background-image: url(cover.jpg)"><p>Cover text</p></section>', 'expected_names' => array('core/cover', 'core/paragraph'), 'snippets' => array('cover.jpg', 'Cover text')), 'spacer' => array('html' => '<div class="wp-block-spacer" style="height: 48px"></div>', 'expected_names' => array('core/spacer'), 'snippets' => array('48px')), 'buttons' => array('html' => '<a class="wp-block-button__link wp-element-button" href="https://example.com">Click</a>', 'expected_names' => array('core/buttons', 'core/button'), 'snippets' => array('https://example.com', 'Click')), 'details' => array('html' => '<details><summary>Question</summary><p>Answer</p></details>', 'expected_names' => array('core/details', 'core/paragraph'), 'snippets' => array('Question', 'Answer')), 'pullquote' => array('html' => '<blockquote class="wp-block-pullquote"><p>Pull this</p><cite>Citation</cite></blockquote>', 'expected_names' => array('core/pullquote'), 'snippets' => array('Pull this', 'Citation')), 'verse' => array('html' => '<pre class="wp-block-verse">Line one\nLine two</pre>', 'expected_names' => array('core/verse'), 'snippets' => array('Line one', 'Line two')), 'video' => array('html' => '<video controls src="movie.mp4" poster="poster.jpg"></video>', 'expected_names' => array('core/video'), 'snippets' => array('movie.mp4', 'poster.jpg')), 'audio' => array('html' => '<audio controls><source src="clip.mp3" type="audio/mpeg"></audio>', 'expected_names' => array('core/audio'), 'snippets' => array('clip.mp3')), 'gallery' => array('html' => '<div class="gallery columns-2"><figure><img src="a.jpg" alt="A"><figcaption>Caption A</figcaption></figure><figure><img src="b.jpg" alt="B"><figcaption>Caption B</figcaption></figure></div>', 'expected_names' => array('core/gallery', 'core/image', 'core/image'), 'snippets' => array('Caption A', 'b.jpg')), 'media-text' => array('html' => '<div class="wp-block-media-text"><figure><img src="hero.jpg" alt="Hero"></figure><div class="wp-block-media-text__content"><p>Media copy</p></div></div>', 'expected_names' => array('core/media-text', 'core/paragraph'), 'snippets' => array('hero.jpg', 'Media copy')), 'file' => array('html' => '<a href="https://example.com/report.pdf">Download report</a>', 'expected_names' => array('core/file'), 'snippets' => array('report.pdf', 'Download report')), 'embed' => array('html' => '<iframe src="https://www.youtube.com/embed/abc123"></iframe>', 'expected_names' => array('core/embed'), 'snippets' => array('youtube.com/watch?v=abc123')));
    }
    /**
     * Flatten block names recursively.
     *
     * @param array<int,array<string,mixed>> $blocks Blocks.
     * @return string[] Block names.
     */
    private function flatten_block_names(array $blocks): array
    {
        $names = array();
        foreach ($blocks as $block) {
            $names[] = $block['blockName'] ?? null;
            if (!empty($block['innerBlocks'])) {
                $names = \array_merge($names, $this->flatten_block_names($block['innerBlocks']));
            }
        }
        return $names;
    }
    /**
     * Collect block HTML, attrs, and nested payloads for fixture assertions.
     *
     * @param array<int,array<string,mixed>> $blocks Blocks.
     * @return string Combined payload.
     */
    private function combined_block_payload(array $blocks): string
    {
        $combined = '';
        foreach ($blocks as $block) {
            foreach (array('innerHTML', 'content') as $key) {
                if (isset($block[$key]) && \is_string($block[$key])) {
                    $combined .= "\n" . $block[$key];
                }
            }
            foreach ($block['attrs'] ?? array() as $value) {
                if (\is_string($value)) {
                    $combined .= "\n" . $value;
                }
            }
            if (!empty($block['innerBlocks'])) {
                $combined .= $this->combined_block_payload($block['innerBlocks']);
            }
        }
        return $combined;
    }
}

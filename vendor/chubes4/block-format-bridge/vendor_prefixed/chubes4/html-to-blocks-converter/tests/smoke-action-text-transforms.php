<?php

namespace BlockFormatBridge\Vendor;

/**
 * Smoke test: action/text raw transforms.
 *
 * Run: php tests/smoke-action-text-transforms.php
 *
 * Exits 0 on pass, 1 on failure. No WordPress required.
 */
// phpcs:disable
\define('ABSPATH', __DIR__);
if (!\function_exists('BlockFormatBridge\Vendor\esc_attr')) {
    function esc_attr($value)
    {
        return \htmlspecialchars((string) $value, \ENT_QUOTES, 'UTF-8');
    }
}
if (!\function_exists('BlockFormatBridge\Vendor\esc_url')) {
    function esc_url($value)
    {
        return \htmlspecialchars((string) $value, \ENT_QUOTES, 'UTF-8');
    }
}
if (!\function_exists('BlockFormatBridge\Vendor\wp_strip_all_tags')) {
    function wp_strip_all_tags($value)
    {
        return \strip_tags((string) $value);
    }
}
class WP_Block_Type_Registry
{
    private static $instance;
    public static function get_instance()
    {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    public function is_registered($name)
    {
        return \true;
    }
    public function get_registered($name)
    {
        $attributes = \array_fill_keys(['anchor', 'citation', 'className', 'content', 'level', 'linkTarget', 'rel', 'summary', 'text', 'url', 'value'], ['type' => 'string']);
        return (object) ['attributes' => $attributes];
    }
}
\class_alias('BlockFormatBridge\Vendor\WP_Block_Type_Registry', 'WP_Block_Type_Registry', \false);
require_once \dirname(__DIR__) . '/includes/class-html-element.php';
require_once \dirname(__DIR__) . '/includes/class-block-factory.php';
require_once \dirname(__DIR__) . '/includes/class-transform-registry.php';
$failures = [];
$assertions = 0;
$smoke_assert = function ($condition, $label, $detail = '') use (&$failures, &$assertions) {
    $assertions++;
    if (!$condition) {
        $failures[] = 'FAIL [' . $label . ']' . ($detail !== '' ? ': ' . $detail : '');
    }
};
$find_transform = function ($element) {
    foreach (HTML_To_Blocks_Transform_Registry::get_raw_transforms() as $transform) {
        try {
            $is_match = \call_user_func($transform['isMatch'], $element);
        } catch (\Throwable $e) {
            $is_match = \false;
        }
        if ($is_match) {
            return $transform;
        }
    }
    return null;
};
$handler = function ($args) {
    return [HTML_To_Blocks_Block_Factory::create_block('core/paragraph', ['content' => \trim($args['HTML'] ?? '')])];
};
// -------------------------------------------------------------------------
// Buttons: explicit button-like anchors become core/buttons > core/button.
// -------------------------------------------------------------------------
$button_paragraph = new HTML_To_Blocks_HTML_Element('p', [], '<p><a href="/buy" target="_blank" rel="nofollow" class="btn btn-primary wp-block-button__link">Buy <strong>Now</strong></a></p>', '<a href="/buy" target="_blank" rel="nofollow" class="btn btn-primary wp-block-button__link">Buy <strong>Now</strong></a>');
$button_transform = $find_transform($button_paragraph);
$button_block = \call_user_func($button_transform['transform'], $button_paragraph, $handler);
$smoke_assert($button_transform['blockName'] === 'core/buttons', 'button-transform-selected');
$smoke_assert($button_block['blockName'] === 'core/buttons', 'button-wrapper-block-name');
$smoke_assert(\count($button_block['innerBlocks']) === 1, 'button-wrapper-has-one-child');
$smoke_assert($button_block['innerBlocks'][0]['blockName'] === 'core/button', 'button-child-block-name');
$smoke_assert($button_block['innerBlocks'][0]['attrs']['url'] === '/buy', 'button-url-preserved');
$smoke_assert($button_block['innerBlocks'][0]['attrs']['linkTarget'] === '_blank', 'button-target-preserved');
$smoke_assert($button_block['innerBlocks'][0]['attrs']['rel'] === 'nofollow', 'button-rel-preserved');
$smoke_assert(\strpos($button_block['innerBlocks'][0]['attrs']['className'], 'btn-primary') !== \false, 'button-class-preserved');
$smoke_assert(\strpos($button_block['innerBlocks'][0]['innerHTML'], 'Buy <strong>Now</strong>') !== \false, 'button-rich-text-preserved');
$button_row = new HTML_To_Blocks_HTML_Element('div', ['class' => 'hero-actions'], '<div class="hero-actions"><a class="btn primary" href="/manifesto/">Read the Manifesto &rarr;</a><a class="btn ghost" href="/proof/">See The Proof</a></div>', '<a class="btn primary" href="/manifesto/">Read the Manifesto &rarr;</a><a class="btn ghost" href="/proof/">See The Proof</a>');
$button_row_transform = $find_transform($button_row);
$button_row_block = \call_user_func($button_row_transform['transform'], $button_row, $handler);
$smoke_assert($button_row_transform['blockName'] === 'core/buttons', 'button-row-transform-selected');
$smoke_assert($button_row_block['blockName'] === 'core/buttons', 'button-row-wrapper-block-name');
$smoke_assert(\count($button_row_block['innerBlocks']) === 2, 'button-row-has-two-children');
$smoke_assert($button_row_block['innerBlocks'][0]['blockName'] === 'core/button', 'button-row-first-child-block-name');
$smoke_assert($button_row_block['innerBlocks'][1]['blockName'] === 'core/button', 'button-row-second-child-block-name');
$smoke_assert($button_row_block['innerBlocks'][0]['attrs']['url'] === '/manifesto/', 'button-row-first-url-preserved');
$smoke_assert($button_row_block['innerBlocks'][1]['attrs']['url'] === '/proof/', 'button-row-second-url-preserved');
$smoke_assert(\strpos($button_row_block['innerBlocks'][0]['attrs']['className'], 'primary') !== \false, 'button-row-first-class-preserved');
$smoke_assert(\strpos($button_row_block['innerBlocks'][1]['attrs']['className'], 'ghost') !== \false, 'button-row-second-class-preserved');
$smoke_assert(\strpos($button_row_block['innerBlocks'][0]['innerHTML'], 'href="/proof/"') === \false, 'button-row-first-child-does-not-contain-second-anchor');
$ordinary_link = new HTML_To_Blocks_HTML_Element('p', [], '<p>Read <a href="/more">more</a>.</p>', 'Read <a href="/more">more</a>.');
$ordinary_link_transform = $find_transform($ordinary_link);
$smoke_assert($ordinary_link_transform['blockName'] === 'core/paragraph', 'ordinary-link-stays-paragraph');
// -------------------------------------------------------------------------
// Details: summary becomes an attribute and nested content becomes blocks.
// -------------------------------------------------------------------------
$details = new HTML_To_Blocks_HTML_Element('details', [], '<details><summary>More <strong>info</strong></summary><p>Nested copy</p><ul><li>One</li></ul></details>', '<summary>More <strong>info</strong></summary><p>Nested copy</p><ul><li>One</li></ul>');
$details_transform = $find_transform($details);
$details_block = \call_user_func($details_transform['transform'], $details, $handler);
$smoke_assert($details_transform['blockName'] === 'core/details', 'details-transform-selected');
$smoke_assert($details_block['attrs']['summary'] === 'More <strong>info</strong>', 'details-summary-preserved');
$smoke_assert(\count($details_block['innerBlocks']) === 1, 'details-content-routed-through-handler');
$smoke_assert(\strpos($details_block['innerBlocks'][0]['attrs']['content'], '<p>Nested copy</p>') !== \false, 'details-nested-content-preserved');
// -------------------------------------------------------------------------
// Pullquote: explicit pullquote class wins, ordinary blockquote stays quote.
// -------------------------------------------------------------------------
$pullquote = new HTML_To_Blocks_HTML_Element('blockquote', ['class' => 'wp-block-pullquote'], '<blockquote class="wp-block-pullquote"><p>Big line</p><cite>Author</cite></blockquote>', '<p>Big line</p><cite>Author</cite>');
$pullquote_transform = $find_transform($pullquote);
$pullquote_block = \call_user_func($pullquote_transform['transform'], $pullquote, $handler);
$smoke_assert($pullquote_transform['blockName'] === 'core/pullquote', 'pullquote-transform-selected');
$smoke_assert($pullquote_block['attrs']['value'] === '<p>Big line</p>', 'pullquote-value-preserved');
$smoke_assert($pullquote_block['attrs']['citation'] === 'Author', 'pullquote-citation-preserved');
$quote = new HTML_To_Blocks_HTML_Element('blockquote', [], '<blockquote><p>Regular quote</p></blockquote>', '<p>Regular quote</p>');
$quote_transform = $find_transform($quote);
$smoke_assert($quote_transform['blockName'] === 'core/quote', 'ordinary-blockquote-stays-quote');
// -------------------------------------------------------------------------
// Verse: explicit verse pre blocks preserve line breaks.
// -------------------------------------------------------------------------
$verse = new HTML_To_Blocks_HTML_Element('pre', ['class' => 'wp-block-verse'], "<pre class=\"wp-block-verse\">Line 1\nLine 2<br>Line 3</pre>", "Line 1\nLine 2<br>Line 3");
$verse_transform = $find_transform($verse);
$verse_block = \call_user_func($verse_transform['transform'], $verse, $handler);
$smoke_assert($verse_transform['blockName'] === 'core/verse', 'verse-transform-selected');
$smoke_assert($verse_block['attrs']['content'] === "Line 1\nLine 2<br>Line 3", 'verse-content-preserves-line-breaks');
$smoke_assert(\strpos($verse_block['innerHTML'], "Line 1\nLine 2<br>Line 3") !== \false, 'verse-inner-html-preserves-line-breaks');
echo 'Assertions: ' . $assertions . \PHP_EOL;
if (empty($failures)) {
    echo 'ALL PASS' . \PHP_EOL;
    exit(0);
}
echo 'FAILURES (' . \count($failures) . '):' . \PHP_EOL;
foreach ($failures as $failure) {
    echo '  - ' . $failure . \PHP_EOL;
}
exit(1);

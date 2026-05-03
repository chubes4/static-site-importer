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
// Buttons: native WordPress button anchors become core/buttons > core/button.
// -------------------------------------------------------------------------
$button_paragraph = new HTML_To_Blocks_HTML_Element('p', [], '<p><a href="/buy" target="_blank" rel="nofollow" class="btn btn-primary wp-block-button__link wp-element-button">Buy <strong>Now</strong></a></p>', '<a href="/buy" target="_blank" rel="nofollow" class="btn btn-primary wp-block-button__link wp-element-button">Buy <strong>Now</strong></a>');
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
$button_row = new HTML_To_Blocks_HTML_Element('div', ['class' => 'hero-actions'], '<div class="hero-actions"><a class="wp-block-button__link" href="/manifesto/">Read the Manifesto &rarr;</a><a class="wp-block-button__link" href="/proof/">See The Proof</a></div>', '<a class="wp-block-button__link" href="/manifesto/">Read the Manifesto &rarr;</a><a class="wp-block-button__link" href="/proof/">See The Proof</a>');
$button_row_transform = $find_transform($button_row);
$button_row_block = \call_user_func($button_row_transform['transform'], $button_row, $handler);
$smoke_assert($button_row_transform['blockName'] === 'core/buttons', 'button-row-transform-selected');
$smoke_assert($button_row_block['blockName'] === 'core/buttons', 'button-row-wrapper-block-name');
$smoke_assert(\count($button_row_block['innerBlocks']) === 2, 'button-row-has-two-children');
$smoke_assert($button_row_block['innerBlocks'][0]['blockName'] === 'core/button', 'button-row-first-child-block-name');
$smoke_assert($button_row_block['innerBlocks'][1]['blockName'] === 'core/button', 'button-row-second-child-block-name');
$smoke_assert($button_row_block['innerBlocks'][0]['attrs']['url'] === '/manifesto/', 'button-row-first-url-preserved');
$smoke_assert($button_row_block['innerBlocks'][1]['attrs']['url'] === '/proof/', 'button-row-second-url-preserved');
$smoke_assert(\strpos($button_row_block['innerBlocks'][0]['innerHTML'], 'href="/proof/"') === \false, 'button-row-first-child-does-not-contain-second-anchor');
$custom_button_paragraph = new HTML_To_Blocks_HTML_Element('p', [], '<p><a href="#order" class="btn btn-primary">Order Online</a></p>', '<a href="#order" class="btn btn-primary">Order Online</a>');
$custom_button_transform = $find_transform($custom_button_paragraph);
$custom_button_block = \call_user_func($custom_button_transform['transform'], $custom_button_paragraph, $handler);
$smoke_assert($custom_button_transform['blockName'] === 'core/buttons', 'custom-button-anchor-becomes-buttons');
$smoke_assert(\count($custom_button_block['innerBlocks']) === 1, 'custom-button-anchor-has-one-button');
$smoke_assert($custom_button_block['innerBlocks'][0]['blockName'] === 'core/button', 'custom-button-anchor-child-block-name');
$smoke_assert($custom_button_block['innerBlocks'][0]['attrs']['url'] === '#order', 'custom-button-anchor-url-preserved');
$smoke_assert($custom_button_block['innerBlocks'][0]['attrs']['className'] === 'btn btn-primary', 'custom-button-anchor-class-preserved');
$nav_cta_paragraph = new HTML_To_Blocks_HTML_Element('p', [], '<p><a href="#try" class="btn nav-cta">Request Access</a></p>', '<a href="#try" class="btn nav-cta">Request Access</a>');
$nav_cta_transform = $find_transform($nav_cta_paragraph);
$nav_cta_block = \call_user_func($nav_cta_transform['transform'], $nav_cta_paragraph, $handler);
$smoke_assert($nav_cta_transform['blockName'] === 'core/buttons', 'nav-cta-button-anchor-becomes-buttons');
$smoke_assert(\count($nav_cta_block['innerBlocks']) === 1, 'nav-cta-button-anchor-has-one-button');
$smoke_assert($nav_cta_block['innerBlocks'][0]['attrs']['url'] === '#try', 'nav-cta-button-anchor-url-preserved');
$smoke_assert($nav_cta_block['innerBlocks'][0]['attrs']['className'] === 'btn nav-cta', 'nav-cta-button-anchor-class-preserved');
$custom_button_row = new HTML_To_Blocks_HTML_Element('div', ['class' => 'hero-actions'], '<div class="hero-actions"><a href="#order" class="btn btn-primary">Order Online</a><a href="#our-bakes" class="btn btn-ghost">See Our Bakes</a></div>', '<a href="#order" class="btn btn-primary">Order Online</a><a href="#our-bakes" class="btn btn-ghost">See Our Bakes</a>');
$custom_button_row_transform = $find_transform($custom_button_row);
$custom_button_row_block = \call_user_func($custom_button_row_transform['transform'], $custom_button_row, $handler);
$smoke_assert($custom_button_row_transform['blockName'] === 'core/buttons', 'custom-button-row-becomes-buttons');
$smoke_assert($custom_button_row_block['blockName'] === 'core/buttons', 'custom-button-row-block-name');
$smoke_assert(\count($custom_button_row_block['innerBlocks']) === 2, 'custom-button-row-has-two-buttons');
$smoke_assert($custom_button_row_block['attrs']['className'] === 'hero-actions', 'custom-button-row-wrapper-class-preserved');
$smoke_assert($custom_button_row_block['innerBlocks'][0]['attrs']['url'] === '#order', 'custom-button-row-first-url-preserved');
$smoke_assert($custom_button_row_block['innerBlocks'][1]['attrs']['url'] === '#our-bakes', 'custom-button-row-second-url-preserved');
$smoke_assert($custom_button_row_block['innerBlocks'][0]['attrs']['className'] === 'btn btn-primary', 'custom-button-row-first-class-preserved');
$smoke_assert($custom_button_row_block['innerBlocks'][1]['attrs']['className'] === 'btn btn-ghost', 'custom-button-row-second-class-preserved');
$custom_cta_row = new HTML_To_Blocks_HTML_Element('div', ['class' => 'cta-actions'], '<div class="cta-actions"><a href="/early-access/" class="cta-primary">Get Early Access <img src="/assets/arrow.svg" alt="" class="materialized-icon"></a><a href="/demo/" class="cta-secondary">View Demo</a></div>', '<a href="/early-access/" class="cta-primary">Get Early Access <img src="/assets/arrow.svg" alt="" class="materialized-icon"></a><a href="/demo/" class="cta-secondary">View Demo</a>');
$custom_cta_row_transform = $find_transform($custom_cta_row);
$custom_cta_row_block = \call_user_func($custom_cta_row_transform['transform'], $custom_cta_row, $handler);
$smoke_assert($custom_cta_row_transform['blockName'] === 'core/buttons', 'custom-cta-row-becomes-buttons');
$smoke_assert(\count($custom_cta_row_block['innerBlocks']) === 2, 'custom-cta-row-has-two-buttons');
$smoke_assert($custom_cta_row_block['attrs']['className'] === 'cta-actions', 'custom-cta-row-wrapper-class-preserved');
$smoke_assert($custom_cta_row_block['innerBlocks'][0]['attrs']['url'] === '/early-access/', 'custom-cta-row-first-url-preserved');
$smoke_assert($custom_cta_row_block['innerBlocks'][0]['attrs']['className'] === 'cta-primary', 'custom-cta-row-first-class-preserved');
$smoke_assert(\strpos($custom_cta_row_block['innerBlocks'][0]['attrs']['text'], 'Get Early Access') !== \false, 'custom-cta-row-text-preserved');
$smoke_assert(\strpos($custom_cta_row_block['innerBlocks'][0]['attrs']['text'], '<img src="/assets/arrow.svg" alt="" class="materialized-icon">') !== \false, 'custom-cta-row-icon-image-preserved');
$custom_cta_anchor = new HTML_To_Blocks_HTML_Element('a', ['href' => 'mailto:hello@saltandstar.com', 'class' => 'btn-cta'], '<a href="mailto:hello@saltandstar.com" class="btn-cta">Place an Order</a>', 'Place an Order');
$custom_cta_transform = $find_transform($custom_cta_anchor);
$custom_cta_block = \call_user_func($custom_cta_transform['transform'], $custom_cta_anchor, $handler);
$smoke_assert($custom_cta_transform['blockName'] === 'core/paragraph', 'custom-cta-anchor-stays-paragraph');
$smoke_assert(\strpos($custom_cta_block['attrs']['content'], '<a href="mailto:hello@saltandstar.com" class="btn-cta">Place an Order</a>') !== \false, 'custom-cta-anchor-preserved');
$smoke_assert(\strpos($custom_cta_block['attrs']['content'], 'wp-element-button') === \false, 'custom-cta-anchor-avoids-wp-button-class');
$ordinary_link = new HTML_To_Blocks_HTML_Element('p', [], '<p>Read <a href="/more">more</a>.</p>', 'Read <a href="/more">more</a>.');
$ordinary_link_transform = $find_transform($ordinary_link);
$smoke_assert($ordinary_link_transform['blockName'] === 'core/paragraph', 'ordinary-link-stays-paragraph');
$static_button_tabs = new HTML_To_Blocks_HTML_Element('div', ['class' => 'use-case-tabs'], '<div class="use-case-tabs"><button class="use-case-tab active">Product Managers</button><button class="use-case-tab">Engineering Leads</button><button class="use-case-tab">GTM Teams</button><button class="use-case-tab">Executives</button></div>', '<button class="use-case-tab active">Product Managers</button><button class="use-case-tab">Engineering Leads</button><button class="use-case-tab">GTM Teams</button><button class="use-case-tab">Executives</button>');
$static_button_tabs_transform = $find_transform($static_button_tabs);
$static_button_tabs_block = \call_user_func($static_button_tabs_transform['transform'], $static_button_tabs, $handler);
$smoke_assert($static_button_tabs_transform['blockName'] === 'core/group', 'static-button-tabs-become-group');
$smoke_assert($static_button_tabs_block['attrs']['className'] === 'use-case-tabs', 'static-button-tab-wrapper-class-preserved');
$smoke_assert(\count($static_button_tabs_block['innerBlocks']) === 4, 'static-button-tabs-children-preserved');
$smoke_assert($static_button_tabs_block['innerBlocks'][0]['blockName'] === 'core/paragraph', 'static-button-tab-child-becomes-paragraph');
$smoke_assert($static_button_tabs_block['innerBlocks'][0]['attrs']['content'] === 'Product Managers', 'static-button-tab-label-preserved');
$smoke_assert($static_button_tabs_block['innerBlocks'][0]['attrs']['className'] === 'use-case-tab active', 'static-button-tab-class-preserved');
$smoke_assert(\strpos($static_button_tabs_block['innerHTML'], '<!-- wp:html -->') === \false, 'static-button-tabs-avoid-wp-html');
$static_chip_button = new HTML_To_Blocks_HTML_Element('button', ['class' => 'filter-chip active', 'type' => 'button'], '<button class="filter-chip active" type="button">Analytics</button>', 'Analytics');
$static_chip_button_transform = $find_transform($static_chip_button);
$static_chip_button_block = \call_user_func($static_chip_button_transform['transform'], $static_chip_button);
$smoke_assert($static_chip_button_transform['blockName'] === 'core/paragraph', 'static-chip-button-becomes-paragraph');
$smoke_assert($static_chip_button_block['attrs']['content'] === 'Analytics', 'static-chip-button-label-preserved');
$smoke_assert($static_chip_button_block['attrs']['className'] === 'filter-chip active', 'static-chip-button-class-preserved');
$submit_button = new HTML_To_Blocks_HTML_Element('button', ['class' => 'use-case-tab', 'type' => 'submit'], '<button class="use-case-tab" type="submit">Submit</button>', 'Submit');
$smoke_assert($find_transform($submit_button) === null, 'submit-button-falls-through');
$form_owned_button = new HTML_To_Blocks_HTML_Element('button', ['class' => 'filter-chip', 'form' => 'filters'], '<button class="filter-chip" form="filters">Apply</button>', 'Apply');
$smoke_assert($find_transform($form_owned_button) === null, 'form-owned-button-falls-through');
// -------------------------------------------------------------------------
// Labels: static visual UI labels become text, real form labels fall through.
// -------------------------------------------------------------------------
$visual_label = new HTML_To_Blocks_HTML_Element('label', ['class' => 'inspector-label'], '<label class="inspector-label">Type</label>', 'Type');
$visual_label_transform = $find_transform($visual_label);
$visual_label_block = \call_user_func($visual_label_transform['transform'], $visual_label, $handler);
$smoke_assert($visual_label_transform['blockName'] === 'core/paragraph', 'visual-label-becomes-paragraph');
$smoke_assert($visual_label_block['attrs']['content'] === 'Type', 'visual-label-content-preserved');
$smoke_assert($visual_label_block['attrs']['className'] === 'inspector-label', 'visual-label-class-preserved');
$smoke_assert(\strpos($visual_label_block['innerHTML'], '<p class="inspector-label">Type</p>') !== \false, 'visual-label-renders-native-paragraph');
$rich_visual_label = new HTML_To_Blocks_HTML_Element('label', [], '<label>Overlay <strong>Color</strong></label>', 'Overlay <strong>Color</strong>');
$rich_visual_label_transform = $find_transform($rich_visual_label);
$rich_visual_label_block = \call_user_func($rich_visual_label_transform['transform'], $rich_visual_label, $handler);
$smoke_assert($rich_visual_label_transform['blockName'] === 'core/paragraph', 'rich-visual-label-becomes-paragraph');
$smoke_assert($rich_visual_label_block['attrs']['content'] === 'Overlay <strong>Color</strong>', 'rich-visual-label-inline-markup-preserved');
$form_label_for = new HTML_To_Blocks_HTML_Element('label', ['for' => 'field-type'], '<label for="field-type">Type</label>', 'Type');
$smoke_assert($find_transform($form_label_for) === null, 'form-label-for-falls-through');
$form_label_wrapping_input = new HTML_To_Blocks_HTML_Element('label', [], '<label>Type <input name="type"></label>', 'Type <input name="type">');
$smoke_assert($find_transform($form_label_wrapping_input) === null, 'form-label-wrapping-input-falls-through');
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

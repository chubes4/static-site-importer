<?php

namespace BlockFormatBridge\Vendor;

/**
 * Smoke test: explicit Site Editor primitive markers.
 *
 * Run: php tests/smoke-site-editor-marker-transforms.php
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
        return \in_array($name, ['core/pattern', 'core/template-part', 'core/group', 'core/html'], \true);
    }
    public function get_registered($name)
    {
        $attributes = \array_fill_keys(['area', 'slug'], ['type' => 'string']);
        return (object) ['attributes' => $attributes];
    }
}
\class_alias('BlockFormatBridge\Vendor\WP_Block_Type_Registry', 'WP_Block_Type_Registry', \false);
require_once \dirname(__DIR__) . '/includes/class-block-factory.php';
require_once \dirname(__DIR__) . '/includes/class-transform-registry.php';
class Site_Editor_Marker_Smoke_Element
{
    private string $tag_name;
    private array $attributes;
    private string $inner_html;
    public function __construct(string $tag_name, array $attributes = [], string $inner_html = '')
    {
        $this->tag_name = \strtoupper($tag_name);
        $this->attributes = \array_change_key_case($attributes, \CASE_LOWER);
        $this->inner_html = $inner_html;
    }
    public function get_tag_name(): string
    {
        return $this->tag_name;
    }
    public function has_attribute(string $name): bool
    {
        return \array_key_exists(\strtolower($name), $this->attributes);
    }
    public function get_attribute(string $name): ?string
    {
        return $this->attributes[\strtolower($name)] ?? null;
    }
    public function get_inner_html(): string
    {
        return $this->inner_html;
    }
    public function get_outer_html(): string
    {
        return '<' . \strtolower($this->tag_name) . '>' . $this->inner_html . '</' . \strtolower($this->tag_name) . '>';
    }
    public function get_child_elements(): array
    {
        return [];
    }
}
$failures = [];
$assertions = 0;
$assert = static function ($condition, $label) use (&$failures, &$assertions) {
    $assertions++;
    if (!$condition) {
        $failures[] = 'FAIL [' . $label . ']';
    }
};
$find_transform = static function ($element, string $block_name) {
    foreach (HTML_To_Blocks_Transform_Registry::get_raw_transforms() as $transform) {
        if (($transform['blockName'] ?? '') !== $block_name) {
            continue;
        }
        if (\is_callable($transform['isMatch'] ?? null) && \call_user_func($transform['isMatch'], $element)) {
            return $transform;
        }
    }
    return null;
};
$pattern_element = new Site_Editor_Marker_Smoke_Element('section', ['data-bfb-pattern' => 'theme/pricing-table'], '<h2>Pricing</h2>');
$pattern_transform = $find_transform($pattern_element, 'core/pattern');
$pattern_block = $pattern_transform ? \call_user_func($pattern_transform['transform'], $pattern_element) : null;
$assert(null !== $pattern_transform, 'pattern-marker-transform-selected');
$assert('core/pattern' === $pattern_block['blockName'], 'pattern-marker-block-name');
$assert('theme/pricing-table' === $pattern_block['attrs']['slug'], 'pattern-marker-slug');
$invalid_pattern = new Site_Editor_Marker_Smoke_Element('section', ['data-bfb-pattern' => 'pricing-table'], '<h2>Pricing</h2>');
$assert($find_transform($invalid_pattern, 'core/pattern') === null, 'pattern-marker-requires-namespace');
$blank_pattern = new Site_Editor_Marker_Smoke_Element('section', ['data-bfb-pattern' => '   '], '<h2>Pricing</h2>');
$assert($find_transform($blank_pattern, 'core/pattern') === null, 'blank-pattern-marker-falls-through');
$header_element = new Site_Editor_Marker_Smoke_Element('header', ['data-bfb-template-part' => 'header'], '<h1>Site</h1>');
$header_transform = $find_transform($header_element, 'core/template-part');
$header_block = $header_transform ? \call_user_func($header_transform['transform'], $header_element) : null;
$assert(null !== $header_transform, 'template-part-marker-transform-selected');
$assert('core/template-part' === $header_block['blockName'], 'template-part-marker-block-name');
$assert('header' === $header_block['attrs']['slug'], 'template-part-marker-slug');
$assert('header' === $header_block['attrs']['area'], 'template-part-marker-area');
foreach (['footer', 'sidebar'] as $area) {
    $area_element = new Site_Editor_Marker_Smoke_Element('sidebar' === $area ? 'aside' : $area, ['data-bfb-template-part' => $area], '<p>' . $area . '</p>');
    $area_transform = $find_transform($area_element, 'core/template-part');
    $area_block = $area_transform ? \call_user_func($area_transform['transform'], $area_element) : null;
    $assert(null !== $area_transform, $area . '-template-part-transform-selected');
    $assert($area_block['attrs']['slug'] === $area, $area . '-template-part-marker-slug');
    $assert($area_block['attrs']['area'] === $area, $area . '-template-part-marker-area');
}
$custom_template = new Site_Editor_Marker_Smoke_Element('section', ['data-bfb-template-part' => 'landing-hero'], '<h1>Hero</h1>');
$custom_block = \call_user_func($find_transform($custom_template, 'core/template-part')['transform'], $custom_template);
$assert('landing-hero' === $custom_block['attrs']['slug'], 'custom-template-part-slug');
$assert(!isset($custom_block['attrs']['area']), 'custom-template-part-has-no-area');
$unmarked_header = new Site_Editor_Marker_Smoke_Element('header', [], '<h1>Site</h1>');
$assert($find_transform($unmarked_header, 'core/template-part') === null, 'unmarked-header-not-template-part');
foreach (['footer', 'aside'] as $tag_name) {
    $unmarked_template_part = new Site_Editor_Marker_Smoke_Element($tag_name, [], '<p>Content</p>');
    $assert($find_transform($unmarked_template_part, 'core/template-part') === null, 'unmarked-' . $tag_name . '-not-template-part');
}
$unmarked_pattern = new Site_Editor_Marker_Smoke_Element('section', ['class' => 'pricing-table'], '<h2>Pricing</h2>');
$assert($find_transform($unmarked_pattern, 'core/pattern') === null, 'unmarked-pattern-lookalike-not-pattern');
$wp_pattern_alias = new Site_Editor_Marker_Smoke_Element('section', ['data-wp-pattern' => 'theme/pricing-table'], '<h2>Pricing</h2>');
$assert($find_transform($wp_pattern_alias, 'core/pattern') === null, 'wp-pattern-alias-not-accepted');
$wp_template_part_alias = new Site_Editor_Marker_Smoke_Element('header', ['data-wp-template-part' => 'header'], '<h1>Site</h1>');
$assert($find_transform($wp_template_part_alias, 'core/template-part') === null, 'wp-template-part-alias-not-accepted');
$malformed_template_part = new Site_Editor_Marker_Smoke_Element('section', ['data-bfb-template-part' => 'template/part'], '<h1>Hero</h1>');
$assert($find_transform($malformed_template_part, 'core/template-part') === null, 'malformed-template-part-falls-through');
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

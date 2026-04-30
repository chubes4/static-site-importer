<?php

namespace BlockFormatBridge\Vendor;

/**
 * Smoke test: static navigation raw transforms.
 *
 * Run: php tests/smoke-static-navigation-transforms.php
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
        return $name === 'core/html';
    }
    public function get_registered($name)
    {
        return (object) ['attributes' => ['content' => ['type' => 'string']]];
    }
}
\class_alias('BlockFormatBridge\Vendor\WP_Block_Type_Registry', 'WP_Block_Type_Registry', \false);
require_once \dirname(__DIR__) . '/includes/class-block-factory.php';
require_once \dirname(__DIR__) . '/includes/class-transform-registry.php';
class Static_Nav_Smoke_Element
{
    private string $tag_name;
    private array $attributes;
    private string $inner_html;
    private string $outer_html;
    private array $children;
    public function __construct(string $tag_name, array $attributes = [], string $inner_html = '', array $children = [], string $outer_html = '')
    {
        $this->tag_name = \strtoupper($tag_name);
        $this->attributes = \array_change_key_case($attributes, \CASE_LOWER);
        $this->inner_html = $inner_html;
        $this->children = $children;
        $this->outer_html = $outer_html !== '' ? $outer_html : '<' . \strtolower($tag_name) . '>' . $inner_html . '</' . \strtolower($tag_name) . '>';
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
        return $this->outer_html;
    }
    public function get_child_elements(): array
    {
        return $this->children;
    }
}
$failures = [];
$assertions = 0;
$smoke_assert = static function ($condition, $label, $detail = '') use (&$failures, &$assertions) {
    $assertions++;
    if (!$condition) {
        $failures[] = 'FAIL [' . $label . ']' . ($detail !== '' ? ': ' . $detail : '');
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
$anchor = static function (string $href, string $label, array $attributes = []) {
    $attributes['href'] = $href;
    return new Static_Nav_Smoke_Element('a', $attributes, $label, [], '<a href="' . $href . '">' . $label . '</a>');
};
$li = static function (array $children, string $inner_html = '') {
    if ($inner_html === '') {
        $inner_html = \implode('', \array_map(static fn($child) => $child->get_outer_html(), $children));
    }
    return new Static_Nav_Smoke_Element('li', [], $inner_html, $children);
};
$ul = static function (array $items) {
    $inner_html = \implode('', \array_map(static fn($item) => $item->get_outer_html(), $items));
    return new Static_Nav_Smoke_Element('ul', [], $inner_html, $items);
};
// -------------------------------------------------------------------------
// Static nav does not claim native core/navigation in default raw conversion.
// -------------------------------------------------------------------------
$about = $li([$anchor('/about/', 'About')]);
$contact = $li([$anchor('/contact/', 'Contact', ['target' => '_blank', 'rel' => 'noopener'])]);
$flat_list = $ul([$about, $contact]);
$flat_nav = new Static_Nav_Smoke_Element('nav', ['aria-label' => 'Primary', 'class' => 'wp-block-navigation primary alignwide'], $flat_list->get_outer_html(), [$flat_list]);
$transform = $find_transform($flat_nav, 'core/navigation');
$smoke_assert($transform === null, 'flat-nav-does-not-emit-native-navigation');
$fallback = HTML_To_Blocks_Block_Factory::create_block('core/html', ['content' => $flat_nav->get_outer_html()]);
$smoke_assert($fallback['blockName'] === 'core/html', 'flat-nav-fallback-block-name');
$smoke_assert(\str_contains($fallback['attrs']['content'] ?? '', '<nav'), 'flat-nav-fallback-preserves-nav');
$smoke_assert(\str_contains($fallback['attrs']['content'] ?? '', '/contact/'), 'flat-nav-fallback-preserves-link-url');
// -------------------------------------------------------------------------
// Nested static nav also stays out of native navigation block generation.
// -------------------------------------------------------------------------
$child_one = $li([$anchor('/products/a/', 'Product A')]);
$child_two = $li([$anchor('/products/b/', 'Product B')]);
$nested_list = $ul([$child_one, $child_two]);
$products = $li([$anchor('/products/', 'Products'), $nested_list]);
$nested_root = $ul([$products]);
$nested_nav = new Static_Nav_Smoke_Element('nav', [], $nested_root->get_outer_html(), [$nested_root]);
$smoke_assert($find_transform($nested_nav, 'core/navigation') === null, 'nested-nav-does-not-emit-native-navigation');
$smoke_assert($find_transform($nested_nav, 'core/navigation-submenu') === null, 'nested-nav-does-not-emit-native-submenu');
// -------------------------------------------------------------------------
// Mixed-content nav is unsupported instead of guessed.
// -------------------------------------------------------------------------
$mixed_nav = new Static_Nav_Smoke_Element('nav', [], '<p>Intro</p>' . $flat_list->get_outer_html(), [new Static_Nav_Smoke_Element('p', [], 'Intro'), $flat_list]);
$smoke_assert($find_transform($mixed_nav, 'core/navigation') === null, 'mixed-nav-unsupported');
$non_link_item = $li([new Static_Nav_Smoke_Element('span', [], 'No link')]);
$bad_list = $ul([$non_link_item]);
$bad_nav = new Static_Nav_Smoke_Element('nav', [], $bad_list->get_outer_html(), [$bad_list]);
$smoke_assert($find_transform($bad_nav, 'core/navigation') === null, 'nav-list-item-without-link-unsupported');
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

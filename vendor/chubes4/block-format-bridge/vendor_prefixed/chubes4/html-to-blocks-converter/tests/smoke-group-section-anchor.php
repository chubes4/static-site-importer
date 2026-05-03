<?php

namespace BlockFormatBridge\Vendor;

/**
 * Smoke test: section IDs survive core/group conversion as anchors.
 *
 * Run: php tests/smoke-group-section-anchor.php
 */
// phpcs:disable
if (!\defined('ABSPATH')) {
    \define('ABSPATH', __DIR__);
}
if (!\function_exists('BlockFormatBridge\Vendor\esc_attr')) {
    function esc_attr($value)
    {
        return \htmlspecialchars((string) $value, \ENT_QUOTES, 'UTF-8');
    }
}
if (!\function_exists('BlockFormatBridge\Vendor\wp_strip_all_tags')) {
    function wp_strip_all_tags($text)
    {
        return \strip_tags((string) $text);
    }
}
if (!\class_exists('WP_Block_Type_Registry', \false)) {
    class WP_Block_Type_Registry
    {
        public static function get_instance()
        {
            return new self();
        }
        public function is_registered($name)
        {
            return \true;
        }
        public function get_registered($name)
        {
            if ($name === 'core/group') {
                return (object) ['attributes' => ['tagName' => ['type' => 'string'], 'className' => ['type' => 'string']]];
            }
            return (object) ['attributes' => []];
        }
    }
    \class_alias('BlockFormatBridge\Vendor\WP_Block_Type_Registry', 'WP_Block_Type_Registry', \false);
}
require_once \dirname(__DIR__) . '/includes/class-block-factory.php';
require_once \dirname(__DIR__) . '/includes/class-transform-registry.php';
class Group_Section_Anchor_Element
{
    private string $tag;
    private array $attrs;
    private string $inner_html;
    public function __construct(string $tag, array $attrs, string $inner_html)
    {
        $this->tag = \strtoupper($tag);
        $this->attrs = $attrs;
        $this->inner_html = $inner_html;
    }
    public function get_tag_name()
    {
        return $this->tag;
    }
    public function has_attribute($name)
    {
        return \array_key_exists($name, $this->attrs);
    }
    public function get_attribute($name)
    {
        return $this->attrs[$name] ?? '';
    }
    public function get_inner_html()
    {
        return $this->inner_html;
    }
    public function get_text_content()
    {
        return \trim(\strip_tags($this->inner_html));
    }
    public function get_child_elements()
    {
        return [];
    }
    public function query_selector($selector)
    {
        return null;
    }
}
$failures = [];
$assertions = 0;
$assert = static function ($condition, $label, $detail = '') use (&$failures, &$assertions) {
    $assertions++;
    if (!$condition) {
        $failures[] = 'FAIL [' . $label . ']' . ($detail !== '' ? ': ' . $detail : '');
    }
};
$source = new Group_Section_Anchor_Element('section', ['id' => 'hero', 'class' => 'hero'], '<h1>Build faster</h1>');
$group_transform = null;
foreach (HTML_To_Blocks_Transform_Registry::get_raw_transforms() as $transform) {
    if (($transform['blockName'] ?? '') === 'core/group' && $transform['isMatch']($source)) {
        $group_transform = $transform;
        break;
    }
}
$assert($group_transform !== null, 'group-transform-registered');
$handler = static function () {
    return [];
};
$block = $group_transform['transform']($source, $handler);
$assert(($block['blockName'] ?? '') === 'core/group', 'block-name');
$assert(($block['attrs']['tagName'] ?? '') === 'section', 'tag-name-preserved', \json_encode($block['attrs'] ?? []));
$assert(($block['attrs']['anchor'] ?? '') === 'hero', 'anchor-preserved-despite-schema-filter', \json_encode($block['attrs'] ?? []));
$assert(\strpos($block['innerHTML'] ?? '', '<section id="hero" class="wp-block-group hero">') !== \false, 'static-html-preserves-id', $block['innerHTML'] ?? '');
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

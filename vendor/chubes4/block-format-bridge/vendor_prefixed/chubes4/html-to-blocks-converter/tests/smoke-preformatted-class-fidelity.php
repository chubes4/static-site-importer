<?php

namespace BlockFormatBridge\Vendor;

/**
 * Smoke test: preformatted raw transforms preserve custom wrapper classes.
 *
 * Run: php tests/smoke-preformatted-class-fidelity.php
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
            return (object) ['attributes' => []];
        }
    }
    \class_alias('BlockFormatBridge\Vendor\WP_Block_Type_Registry', 'WP_Block_Type_Registry', \false);
}
require_once \dirname(__DIR__) . '/includes/class-block-factory.php';
require_once \dirname(__DIR__) . '/includes/class-transform-registry.php';
class Preformatted_Class_Fidelity_Element
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
        $failures[] = 'FAIL [' . $label . ']' . ('' !== $detail ? ': ' . $detail : '');
    }
};
$source = new Preformatted_Class_Fidelity_Element('pre', ['class' => 'prompt wp-block-preformatted alignwide has-small-font-size unsafe@class', 'id' => 'demo-prompt'], '<span class="label">— The Prompt —</span>Generate a site.');
$preformatted_transform = null;
foreach (HTML_To_Blocks_Transform_Registry::get_raw_transforms() as $transform) {
    if (($transform['blockName'] ?? '') === 'core/preformatted' && $transform['isMatch']($source)) {
        $preformatted_transform = $transform;
        break;
    }
}
$assert(null !== $preformatted_transform, 'preformatted-transform-registered');
$assert($preformatted_transform['isMatch']($source) === \true, 'preformatted-transform-matches-pre-without-code');
$block = $preformatted_transform['transform']($source);
$assert(($block['blockName'] ?? '') === 'core/preformatted', 'block-name');
$assert(($block['attrs']['className'] ?? '') === 'prompt', 'safe-class-preserved', \json_encode($block['attrs'] ?? []));
$assert(($block['attrs']['anchor'] ?? '') === 'demo-prompt', 'anchor-preserved', \json_encode($block['attrs'] ?? []));
$assert(\strpos($block['innerHTML'] ?? '', '<pre class="wp-block-preformatted prompt">') !== \false, 'static-html-preserves-class', $block['innerHTML'] ?? '');
$assert(\substr_count($block['innerHTML'] ?? '', 'prompt') === 1, 'static-html-has-single-custom-class', $block['innerHTML'] ?? '');
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

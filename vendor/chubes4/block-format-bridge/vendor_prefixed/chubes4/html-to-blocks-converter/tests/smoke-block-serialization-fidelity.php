<?php

namespace BlockFormatBridge\Vendor;

/**
 * Smoke test: stored block attrs are reflected in serialized static HTML.
 *
 * Run: php tests/smoke-block-serialization-fidelity.php
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
if (!\function_exists('BlockFormatBridge\Vendor\serialize_block_attributes')) {
    function serialize_block_attributes($attributes)
    {
        if (empty($attributes)) {
            return '';
        }
        return ' ' . \json_encode($attributes, \JSON_UNESCAPED_SLASHES);
    }
}
if (!\function_exists('BlockFormatBridge\Vendor\serialize_block')) {
    function serialize_block($block)
    {
        $name = $block['blockName'] ?? null;
        $attrs = $block['attrs'] ?? [];
        $inner_blocks = $block['innerBlocks'] ?? [];
        $inner_content = $block['innerContent'] ?? [];
        if (!$name) {
            return $block['innerHTML'] ?? '';
        }
        $content = '';
        $index = 0;
        foreach ($inner_content as $chunk) {
            if ($chunk === null) {
                $content .= serialize_block($inner_blocks[$index] ?? []);
                $index++;
                continue;
            }
            $content .= $chunk;
        }
        return '<!-- wp:' . \substr($name, 5) . serialize_block_attributes($attrs) . ' -->' . $content . '<!-- /wp:' . \substr($name, 5) . ' -->';
    }
}
if (!\function_exists('BlockFormatBridge\Vendor\serialize_blocks')) {
    function serialize_blocks($blocks)
    {
        return \implode('', \array_map('serialize_block', $blocks));
    }
}
require_once \dirname(__DIR__) . '/includes/class-block-factory.php';
$failures = [];
$assertions = 0;
$assert = static function ($condition, $label, $detail = '') use (&$failures, &$assertions) {
    $assertions++;
    if (!$condition) {
        $failures[] = 'FAIL [' . $label . ']' . ($detail !== '' ? ': ' . $detail : '');
    }
};
$paragraph = HTML_To_Blocks_Block_Factory::create_block('core/paragraph', ['content' => 'A generated static website.', 'className' => 'lede']);
$styled_paragraph = HTML_To_Blocks_Block_Factory::create_block('core/paragraph', ['content' => 'Styled paragraph.', 'className' => 'center', 'style' => ['color' => ['text' => 'var(--muted)'], 'spacing' => ['margin' => ['top' => '36px']]]]);
$heading = HTML_To_Blocks_Block_Factory::create_block('core/heading', ['level' => 1, 'content' => 'WordPress is officially dead.', 'className' => 'hero-title']);
$group = HTML_To_Blocks_Block_Factory::create_block('core/group', ['className' => 'hero', 'tagName' => 'section'], [$heading, $paragraph, $styled_paragraph]);
$list = HTML_To_Blocks_Block_Factory::create_block('core/list', ['ordered' => \true, 'className' => 'manifesto-list'], [HTML_To_Blocks_Block_Factory::create_block('core/list-item', ['content' => 'One'])]);
$preformatted = HTML_To_Blocks_Block_Factory::create_block('core/preformatted', ['content' => '<span class="label">The Prompt</span>Generate a site.', 'className' => 'prompt']);
$serialized = serialize_blocks([$group, $list, $preformatted]);
$assert(\strpos($serialized, '<section class="wp-block-group hero">') !== \false, 'group-static-html-uses-tag-and-class', $serialized);
$assert(\strpos($serialized, '<h1 class="wp-block-heading hero-title">WordPress is officially dead.</h1>') !== \false, 'heading-static-html-preserves-class', $serialized);
$assert(\strpos($serialized, '<p class="lede">A generated static website.</p>') !== \false, 'paragraph-static-html-preserves-class', $serialized);
$assert(\strpos($serialized, '<p class="center has-text-color" style="color:var(--muted);margin-top:36px">Styled paragraph.</p>') !== \false, 'paragraph-static-html-preserves-style-supports', $serialized);
$assert(\substr_count($serialized, 'manifesto-list') === 2, 'list-class-in-attrs-and-static-html', $serialized);
$assert(\strpos($serialized, '<ol class="wp-block-list manifesto-list">') !== \false, 'list-static-html-preserves-class', $serialized);
$assert(\substr_count($serialized, 'prompt') === 2, 'preformatted-class-in-attrs-and-static-html', $serialized);
$assert(\strpos($serialized, '<pre class="wp-block-preformatted prompt"><span class="label">The Prompt</span>Generate a site.</pre>') !== \false, 'preformatted-static-html-preserves-class', $serialized);
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

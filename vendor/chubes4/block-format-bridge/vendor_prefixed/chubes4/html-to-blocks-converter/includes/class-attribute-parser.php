<?php

namespace BlockFormatBridge\Vendor;

/**
 * Attribute Parser - Extracts block attributes from HTML using WordPress HTML API
 *
 * PHP port of Gutenberg's getBlockAttributes() from packages/blocks/src/api/parser/get-block-attributes.js
 */
if (!\defined('ABSPATH')) {
    exit;
}
class HTML_To_Blocks_Attribute_Parser
{
    /**
     * Selector chain cache (keyed by root outer HTML + selector)
     *
     * @var array
     */
    private static $selector_chain_cache = [];
    /**
     * Gets block attributes from HTML based on block schema
     *
     * @param string $block_name Block name
     * @param string $html       Raw HTML
     * @param array  $overrides  Attribute overrides
     * @return array Parsed attributes
     */
    public static function get_block_attributes($block_name, $html, $overrides = [])
    {
        $registry = \WP_Block_Type_Registry::get_instance();
        $block_type = $registry->get_registered($block_name);
        if (!$block_type || empty($block_type->attributes)) {
            return $overrides;
        }
        $doc = self::parse_html($html);
        if (!$doc) {
            return $overrides;
        }
        $attributes = [];
        foreach ($block_type->attributes as $key => $schema) {
            $value = self::parse_attribute($doc, $schema, $html);
            if ($value !== null) {
                $attributes[$key] = $value;
            }
        }
        return \array_merge($attributes, $overrides);
    }
    /**
     * Parses HTML string into HTML_Element wrapper rooted at a container div
     *
     * @param string $html HTML string
     * @return HTML_To_Blocks_HTML_Element|null
     */
    private static function parse_html($html)
    {
        if (empty($html)) {
            return null;
        }
        return HTML_To_Blocks_HTML_Element::from_html('<div data-html-to-blocks-root="1">' . $html . '</div>');
    }
    /**
     * Parses a single attribute based on schema
     *
     * @param HTML_To_Blocks_HTML_Element $element HTML element wrapper
     * @param array                       $schema  Attribute schema
     * @param string                      $html    Original HTML
     * @return mixed Parsed value or null
     */
    private static function parse_attribute($element, $schema, $html)
    {
        $source = $schema['source'] ?? null;
        $selector = $schema['selector'] ?? null;
        switch ($source) {
            case 'html':
                return self::get_inner_html($element, $selector, $schema['multiline'] ?? null);
            case 'text':
                return self::get_text_content($element, $selector);
            case 'attribute':
                return self::get_dom_attribute($element, $selector, $schema['attribute'] ?? '');
            case 'raw':
                return $html;
            case 'query':
                return self::query_elements($element, $selector, $schema['query'] ?? []);
            case 'tag':
                return self::get_tag_name($element, $selector);
            default:
                return $schema['default'] ?? null;
        }
    }
    /**
     * Gets inner HTML of an element matching selector
     *
     * @param HTML_To_Blocks_HTML_Element $element   HTML element wrapper
     * @param string|null                 $selector  CSS-like selector
     * @param string|null                 $multiline Multiline tag type
     * @return string|null
     */
    private static function get_inner_html($element, $selector, $multiline = null)
    {
        $node = self::query_selector($element, $selector);
        if (!$node) {
            return null;
        }
        return \trim($node->get_inner_html());
    }
    /**
     * Gets text content of an element matching selector
     *
     * @param HTML_To_Blocks_HTML_Element $element  HTML element wrapper
     * @param string|null                 $selector CSS-like selector
     * @return string|null
     */
    private static function get_text_content($element, $selector)
    {
        $node = self::query_selector($element, $selector);
        if (!$node) {
            return null;
        }
        return \trim($node->get_text_content());
    }
    /**
     * Gets an attribute value from an element matching selector
     *
     * @param HTML_To_Blocks_HTML_Element $element   HTML element wrapper
     * @param string|null                 $selector  CSS-like selector
     * @param string                      $attribute Attribute name
     * @return string|null
     */
    private static function get_dom_attribute($element, $selector, $attribute)
    {
        $node = self::query_selector($element, $selector);
        if (!$node) {
            return null;
        }
        if (!$node->has_attribute($attribute)) {
            return null;
        }
        return $node->get_attribute($attribute);
    }
    /**
     * Gets the tag name of an element matching selector
     *
     * @param HTML_To_Blocks_HTML_Element $element  HTML element wrapper
     * @param string|null                 $selector CSS-like selector
     * @return string|null
     */
    private static function get_tag_name($element, $selector)
    {
        $node = self::query_selector($element, $selector);
        if (!$node) {
            return null;
        }
        return \strtolower($node->get_tag_name());
    }
    /**
     * Queries multiple elements and extracts data based on sub-schema
     *
     * @param HTML_To_Blocks_HTML_Element $element  HTML element wrapper
     * @param string|null                 $selector CSS-like selector
     * @param array                       $query    Query schema for each element
     * @return array
     */
    private static function query_elements($element, $selector, $query)
    {
        $nodes = self::query_selector_all($element, $selector);
        $results = [];
        foreach ($nodes as $node) {
            $item = [];
            foreach ($query as $key => $sub_schema) {
                $item[$key] = self::parse_attribute($node, $sub_schema, $node->get_outer_html());
            }
            $results[] = $item;
        }
        return $results;
    }
    /**
     * Simple CSS selector query (supports tag, .class, #id, and combinations)
     *
     * @param HTML_To_Blocks_HTML_Element $element  HTML element wrapper
     * @param string|null                 $selector CSS-like selector
     * @return HTML_To_Blocks_HTML_Element|null
     */
    public static function query_selector($element, $selector)
    {
        if (!$element) {
            return null;
        }
        if (empty($selector)) {
            return $element;
        }
        $matches = self::query_selector_all($element, $selector);
        return $matches[0] ?? null;
    }
    /**
     * Query all elements matching selector
     *
     * @param HTML_To_Blocks_HTML_Element $element  HTML element wrapper
     * @param string|null                 $selector CSS-like selector
     * @return array
     */
    public static function query_selector_all($element, $selector)
    {
        if (!$element || empty($selector)) {
            return [];
        }
        $cache_key = \md5($element->get_outer_html() . '|' . $selector);
        if (isset(self::$selector_chain_cache[$cache_key])) {
            return self::$selector_chain_cache[$cache_key];
        }
        $selector_groups = \preg_split('/\s*,\s*/', \trim($selector));
        if (\false === $selector_groups) {
            return [];
        }
        $all_matches = [];
        foreach ($selector_groups as $group) {
            if ($group === '') {
                continue;
            }
            $group_matches = self::query_selector_chain($element, $group);
            foreach ($group_matches as $match) {
                $key = \md5($match->get_outer_html());
                if (!isset($all_matches[$key])) {
                    $all_matches[$key] = $match;
                }
            }
        }
        self::$selector_chain_cache[$cache_key] = \array_values($all_matches);
        return self::$selector_chain_cache[$cache_key];
    }
    /**
     * Queries selector chain with child/descendant combinators
     *
     * @param HTML_To_Blocks_HTML_Element $root     Root element
     * @param string                      $selector Selector chain
     * @return array
     */
    private static function query_selector_chain($root, $selector)
    {
        $tokens = \preg_split('/\s+/', \trim(\str_replace('>', ' > ', $selector)));
        if (\false === $tokens) {
            return [];
        }
        $tokens = \array_values(\array_filter($tokens, static function ($token) {
            return $token !== '';
        }));
        if (empty($tokens)) {
            return [];
        }
        $steps = [];
        $next_combinator = 'descendant';
        foreach ($tokens as $token) {
            if ($token === '>') {
                $next_combinator = 'child';
                continue;
            }
            $steps[] = ['combinator' => $next_combinator, 'selector' => $token];
            $next_combinator = 'descendant';
        }
        $current = [$root];
        foreach ($steps as $step) {
            $next = [];
            foreach ($current as $context) {
                $base_selector = self::extract_base_selector($step['selector']);
                if ($base_selector === null) {
                    continue;
                }
                // WP HTML API adapter currently supports descendant matching only,
                // so child combinators are approximated as descendant matching.
                $candidates = $context->query_selector_all($base_selector);
                foreach ($candidates as $candidate) {
                    if (self::matches_simple_selector($candidate, $step['selector'])) {
                        $next[] = $candidate;
                    }
                }
            }
            if (empty($next)) {
                return [];
            }
            $current = $next;
        }
        return $current;
    }
    /**
     * Extracts base selector supported by HTML element adapter
     *
     * @param string $selector Selector token
     * @return string|null
     */
    private static function extract_base_selector($selector)
    {
        $selector = \trim($selector);
        $pattern = '/^([a-z0-9*]+)?(?:\.([a-z0-9_-]+))?(?:#([a-z0-9_-]+))?(?:\[[^\]]+\])?(?::not\(\[[^\]]+\]\))?$/i';
        if (!\preg_match($pattern, $selector, $matches)) {
            return null;
        }
        $tag = $matches[1] ?? '';
        $class = $matches[2] ?? '';
        $id = $matches[3] ?? '';
        if ($tag === '*' || $tag === '') {
            if ($class) {
                return '.' . $class;
            }
            if ($id) {
                return '#' . $id;
            }
            return null;
        }
        return $tag . ($class ? '.' . $class : '') . ($id ? '#' . $id : '');
    }
    /**
     * Matches a simple selector against one element
     *
     * Supports: tag, .class, #id, [attr], [attr=value], :not([attr]), and combinations.
     *
     * @param HTML_To_Blocks_HTML_Element $element  Candidate element
     * @param string                      $selector Simple selector
     * @return bool
     */
    private static function matches_simple_selector($element, $selector)
    {
        $selector = \trim($selector);
        if ($selector === '*') {
            return \true;
        }
        $pattern = '/^([a-z0-9*]+)?(?:\.([a-z0-9_-]+))?(?:#([a-z0-9_-]+))?(?:\[([a-z0-9_-]+)(?:=["\']?([^"\'\]]+)["\']?)?\])?(?::not\(\[([a-z0-9_-]+)(?:=["\']?([^"\'\]]+)["\']?)?\]\))?$/i';
        if (!\preg_match($pattern, $selector, $matches)) {
            return \false;
        }
        $tag = $matches[1] ?? null;
        $class = $matches[2] ?? null;
        $id = $matches[3] ?? null;
        $attr_name = $matches[4] ?? null;
        $attr_value = $matches[5] ?? null;
        $not_attr_name = $matches[6] ?? null;
        $not_attr_value = $matches[7] ?? null;
        if ($tag && $tag !== '*' && \strtoupper($tag) !== $element->get_tag_name()) {
            return \false;
        }
        if ($class) {
            $class_attr = $element->get_attribute('class');
            if (!$class_attr || !\preg_match('/(?:^|\s)' . \preg_quote($class, '/') . '(?:$|\s)/', $class_attr)) {
                return \false;
            }
        }
        if ($id) {
            if ($element->get_attribute('id') !== $id) {
                return \false;
            }
        }
        if ($attr_name) {
            if (!$element->has_attribute($attr_name)) {
                return \false;
            }
            if ($attr_value !== null && (string) $element->get_attribute($attr_name) !== $attr_value) {
                return \false;
            }
        }
        if ($not_attr_name) {
            if (!$element->has_attribute($not_attr_name)) {
                return \true;
            }
            if ($not_attr_value === null) {
                return \false;
            }
            if ((string) $element->get_attribute($not_attr_name) === $not_attr_value) {
                return \false;
            }
        }
        return \true;
    }
}

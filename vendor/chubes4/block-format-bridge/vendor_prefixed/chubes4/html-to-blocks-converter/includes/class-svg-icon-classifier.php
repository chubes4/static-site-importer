<?php

namespace BlockFormatBridge\Vendor;

/**
 * Safe inline SVG icon classification and sanitization.
 *
 * @package HTML_To_Blocks_Converter
 */
if (!\defined('ABSPATH')) {
    exit;
}
class HTML_To_Blocks_SVG_Icon_Classifier
{
    private const MAX_BYTES = 5120;
    private const MAX_NODES = 50;
    private const MAX_DEPTH = 5;
    private const MAX_ICON_SIZE = 256;
    private const ALLOWED_TAGS = ['svg', 'path', 'circle', 'rect', 'line', 'polyline', 'polygon', 'ellipse', 'g', 'title', 'desc'];
    private const ALLOWED_ATTRIBUTES = ['aria-hidden', 'aria-label', 'class', 'cx', 'cy', 'd', 'fill', 'fill-opacity', 'height', 'points', 'r', 'role', 'rx', 'ry', 'stroke', 'stroke-linecap', 'stroke-linejoin', 'stroke-opacity', 'stroke-width', 'viewbox', 'width', 'x', 'x1', 'x2', 'y', 'y1', 'y2'];
    /**
     * Classifies a source fragment as a safe inline SVG icon or a rejected SVG.
     *
     * @param string $svg Source SVG fragment.
     * @return array Classification result with is_safe, svg, metadata, and reason keys.
     */
    public static function classify(string $svg): array
    {
        $svg = \trim($svg);
        $result = ['is_safe' => \false, 'svg' => '', 'metadata' => [], 'reason' => ''];
        if ($svg === '' || \strlen($svg) > self::MAX_BYTES) {
            $result['reason'] = 'size_limit';
            return $result;
        }
        if (!\class_exists('DOMDocument', \false)) {
            $result['reason'] = 'dom_unavailable';
            return $result;
        }
        $document = new \DOMDocument();
        $previous = \libxml_use_internal_errors(\true);
        $loaded = $document->loadXML($svg, \LIBXML_NONET | \LIBXML_NOERROR | \LIBXML_NOWARNING);
        \libxml_clear_errors();
        \libxml_use_internal_errors($previous);
        if (!$loaded || !$document->documentElement || \strtolower($document->documentElement->tagName) !== 'svg') {
            $result['reason'] = 'invalid_svg';
            return $result;
        }
        $state = ['nodes' => 0, 'max_depth' => 0, 'tags' => [], 'reason' => ''];
        $sanitized = self::sanitize_element($document->documentElement, $document, 1, $state);
        if (!$sanitized) {
            $result['reason'] = $state['reason'] ?: 'unsafe_svg';
            return $result;
        }
        $view_box = $sanitized->getAttribute('viewBox');
        $width = $sanitized->getAttribute('width');
        $height = $sanitized->getAttribute('height');
        if (!self::has_small_icon_dimensions($view_box, $width, $height)) {
            $result['reason'] = 'dimension_limit';
            return $result;
        }
        $sanitized_svg = $document->saveXML($sanitized);
        if (!\is_string($sanitized_svg) || $sanitized_svg === '') {
            $result['reason'] = 'serialization_failed';
            return $result;
        }
        $result['is_safe'] = \true;
        $result['svg'] = $sanitized_svg;
        $result['reason'] = 'safe_svg_icon';
        $result['metadata'] = ['kind' => 'inline-svg-icon', 'viewBox' => $view_box, 'width' => $width, 'height' => $height, 'className' => $sanitized->getAttribute('class'), 'ariaLabel' => $sanitized->getAttribute('aria-label'), 'nodeCount' => $state['nodes'], 'maxDepth' => $state['max_depth'], 'tags' => \array_values(\array_unique($state['tags']))];
        return $result;
    }
    /**
     * Recursively validates and copies an allowed SVG element into the sanitizer document.
     *
     * @param DOMElement  $source Source element.
     * @param DOMDocument $document Sanitizer document.
     * @param int         $depth Current element depth.
     * @param array       $state Mutable traversal state.
     * @return DOMElement|null Sanitized element or null when unsafe.
     */
    private static function sanitize_element(\DOMElement $source, \DOMDocument $document, int $depth, array &$state): ?\DOMElement
    {
        $tag = \strtolower($source->tagName);
        if (!\in_array($tag, self::ALLOWED_TAGS, \true) || \preg_match('/^animate/i', $tag) === 1) {
            $state['reason'] = 'disallowed_tag';
            return null;
        }
        $state['nodes']++;
        $state['max_depth'] = \max($state['max_depth'], $depth);
        $state['tags'][] = $tag;
        if ($state['nodes'] > self::MAX_NODES || $depth > self::MAX_DEPTH) {
            $state['reason'] = 'complexity_limit';
            return null;
        }
        $target = $document->createElement($tag);
        foreach (\iterator_to_array($source->attributes) as $attribute) {
            $name = \strtolower($attribute->name);
            $value = \trim($attribute->value);
            if (!self::is_allowed_attribute($name, $value)) {
                $state['reason'] = 'disallowed_attribute';
                return null;
            }
            $target->setAttribute($name === 'viewbox' ? 'viewBox' : $name, $value);
        }
        foreach (\iterator_to_array($source->childNodes) as $child) {
            if ($child instanceof \DOMText) {
                $text = \trim($child->textContent);
                if ($text !== '') {
                    $target->appendChild($document->createTextNode($text));
                }
                continue;
            }
            if ($child instanceof \DOMElement) {
                $child_element = self::sanitize_element($child, $document, $depth + 1, $state);
                if (!$child_element) {
                    return null;
                }
                $target->appendChild($child_element);
                continue;
            }
            if (\in_array($child->nodeType, [\XML_COMMENT_NODE, \XML_CDATA_SECTION_NODE, \XML_PI_NODE], \true)) {
                $state['reason'] = 'disallowed_node';
                return null;
            }
        }
        return $target;
    }
    /**
     * Checks whether an SVG attribute is allowed and reference-free.
     *
     * @param string $name Attribute name.
     * @param string $value Attribute value.
     * @return bool True when safe.
     */
    private static function is_allowed_attribute(string $name, string $value): bool
    {
        if (\strpos($name, 'on') === 0 || \in_array($name, ['href', 'xlink:href', 'src', 'style'], \true)) {
            return \false;
        }
        if ($name === 'xmlns' && $value === 'http://www.w3.org/2000/svg') {
            return \true;
        }
        if (!\in_array($name, self::ALLOWED_ATTRIBUTES, \true)) {
            return \false;
        }
        return \preg_match('/url\s*\(|(?:https?:)?\/\/|data:/i', $value) !== 1;
    }
    /**
     * Applies small icon dimension limits.
     *
     * @param string $view_box SVG viewBox attribute.
     * @param string $width SVG width attribute.
     * @param string $height SVG height attribute.
     * @return bool True when dimensions look icon-sized.
     */
    private static function has_small_icon_dimensions(string $view_box, string $width, string $height): bool
    {
        if ($view_box !== '') {
            $parts = \preg_split('/[\s,]+/', \trim($view_box));
            if (!\is_array($parts)) {
                return \false;
            }
            if (\count($parts) !== 4 || !\is_numeric($parts[2]) || !\is_numeric($parts[3])) {
                return \false;
            }
            if ((float) $parts[2] <= 0 || (float) $parts[3] <= 0 || (float) $parts[2] > self::MAX_ICON_SIZE || (float) $parts[3] > self::MAX_ICON_SIZE) {
                return \false;
            }
        }
        foreach ([$width, $height] as $dimension) {
            if ($dimension === '') {
                continue;
            }
            if (\preg_match('/^([0-9]+(?:\.[0-9]+)?)(?:px)?$/', $dimension, $matches) !== 1 || (float) $matches[1] > self::MAX_ICON_SIZE) {
                return \false;
            }
        }
        return $view_box !== '' || $width !== '' || $height !== '';
    }
}
if (!\function_exists('BlockFormatBridge\Vendor\html_to_blocks_classify_inline_svg_icon')) {
    /**
     * Classifies and sanitizes an inline SVG icon for downstream materialization.
     *
     * @param string $svg Source SVG fragment.
     * @return array Classification result with is_safe, svg, metadata, and reason keys.
     */
    function html_to_blocks_classify_inline_svg_icon(string $svg): array
    {
        return HTML_To_Blocks_SVG_Icon_Classifier::classify($svg);
    }
}

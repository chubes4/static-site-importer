<?php

namespace BlockFormatBridge\Vendor;

/**
 * Safe inline SVG icon helper functions.
 *
 * @package HTML_To_Blocks_Converter
 */
if (!\defined('ABSPATH')) {
    exit;
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

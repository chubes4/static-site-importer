<?php

namespace BlockFormatBridge\Vendor;

/**
 * Raw handler pipeline ported from Gutenberg JavaScript to PHP
 *
 * Uses WordPress HTML API (WP_HTML_Processor) for spec-compliant HTML5 parsing.
 * Converts HTML to Gutenberg blocks using registered transforms.
 */
if (!\defined('ABSPATH')) {
    exit;
}
/**
 * Main raw handler function - converts HTML to blocks
 *
 * @param array $args Arguments array with 'HTML' key
 * @return array Array of block arrays
 */
function html_to_blocks_raw_handler($args)
{
    $html = $args['HTML'] ?? '';
    if (empty($html)) {
        return [];
    }
    if (\strpos($html, '<!-- wp:') !== \false) {
        $blocks = parse_blocks($html);
        $is_single_freeform = \count($blocks) === 1 && isset($blocks[0]['blockName']) && $blocks[0]['blockName'] === 'core/freeform';
        if (!$is_single_freeform) {
            return $blocks;
        }
    }
    $pieces = html_to_blocks_shortcode_converter($html);
    $result = [];
    foreach ($pieces as $piece) {
        if (!\is_string($piece)) {
            $result[] = $piece;
            continue;
        }
        $piece = html_to_blocks_normalise_blocks($piece);
        $blocks = html_to_blocks_convert($piece);
        $result = \array_merge($result, $blocks);
    }
    return \array_filter($result);
}
/**
 * Converts HTML directly to blocks using registered transforms
 *
 * @param string $html HTML to convert
 * @return array Array of blocks
 */
function html_to_blocks_convert($html)
{
    if (empty(\trim($html))) {
        return [];
    }
    $processor = \WP_HTML_Processor::create_fragment($html);
    if (!$processor) {
        \error_log(\sprintf('[HTML to Blocks] create_fragment() failed | HTML length: %d | Preview: %s', \strlen($html), \substr($html, 0, 300)));
        return [];
    }
    $original_html_length = \strlen($html);
    $blocks = [];
    $transforms = HTML_To_Blocks_Transform_Registry::get_raw_transforms();
    $body_depth = 2;
    $top_level_depth = $body_depth + 1;
    $tag_occurrences = [];
    $tag_positions = [];
    while ($processor->next_token()) {
        $token_type = $processor->get_token_type();
        $depth = $processor->get_current_depth();
        if ('#text' === $token_type && $depth === $body_depth) {
            $text = \trim($processor->get_modifiable_text());
            if (!empty($text)) {
                $blocks[] = HTML_To_Blocks_Block_Factory::create_block('core/paragraph', ['content' => \htmlspecialchars($text, \ENT_QUOTES, 'UTF-8')]);
            }
            continue;
        }
        if ('#tag' !== $token_type) {
            continue;
        }
        if ($processor->is_tag_closer()) {
            continue;
        }
        $tag_name = $processor->get_tag();
        if (!isset($tag_occurrences[$tag_name])) {
            $tag_occurrences[$tag_name] = 0;
            $tag_positions[$tag_name] = html_to_blocks_find_all_tag_positions($html, $tag_name);
        }
        $occurrence = $tag_occurrences[$tag_name]++;
        if ($depth !== $top_level_depth) {
            continue;
        }
        $element_html = html_to_blocks_extract_element_at_occurrence($html, $tag_name, $tag_positions[$tag_name], $occurrence);
        if (!$element_html) {
            \error_log(\sprintf('[HTML to Blocks] Element extraction failed | Tag: %s | Occurrence: %d | HTML preview: %s', $tag_name, $occurrence, \substr($html, 0, 300)));
            continue;
        }
        $element = HTML_To_Blocks_HTML_Element::from_html($element_html);
        if (!$element) {
            $blocks[] = html_to_blocks_create_unsupported_html_fallback_block($element_html, ['reason' => 'element_parse_failed', 'tag_name' => $tag_name, 'occurrence' => $occurrence]);
            continue;
        }
        $raw_transform = html_to_blocks_find_transform($element, $transforms);
        if (!$raw_transform) {
            $blocks[] = html_to_blocks_create_unsupported_html_fallback_block($element_html, ['reason' => 'no_transform', 'tag_name' => $element->get_tag_name(), 'occurrence' => $occurrence]);
        } else {
            $transform_fn = $raw_transform['transform'] ?? null;
            if ($transform_fn && \is_callable($transform_fn)) {
                $raw_handler_callback = __NAMESPACE__ ? __NAMESPACE__ . '\html_to_blocks_raw_handler' : 'html_to_blocks_raw_handler';
                $block = \call_user_func($transform_fn, $element, $raw_handler_callback);
                if ($element->has_attribute('class')) {
                    $existing_class = $block['attrs']['className'] ?? '';
                    $node_class = $element->get_attribute('class');
                    $inner_html = $block['innerHTML'] ?? '';
                    if (!empty($node_class) && \strpos($existing_class, $node_class) === \false && \strpos($inner_html, $node_class) === \false) {
                        $block['attrs']['className'] = \trim($existing_class . ' ' . $node_class);
                    }
                }
                $blocks[] = $block;
            } else {
                $block_name = $raw_transform['blockName'];
                $attributes = HTML_To_Blocks_Attribute_Parser::get_block_attributes($block_name, $element_html);
                $blocks[] = HTML_To_Blocks_Block_Factory::create_block($block_name, $attributes);
            }
        }
    }
    // Check if processor bailed due to unsupported HTML
    $last_error = $processor->get_last_error();
    if ($last_error !== null) {
        \error_log(\sprintf('[HTML to Blocks] WP_HTML_Processor bailed | Error: %s | Blocks created: %d | HTML length: %d | Preview: %s', $last_error, \count($blocks), $original_html_length, \substr($html, 0, 500)));
    }
    // Check for significant content loss (input had content but output is empty/minimal)
    $output_content_length = 0;
    foreach ($blocks as $block) {
        $output_content_length += \strlen($block['innerHTML'] ?? '');
    }
    if ($original_html_length > 100 && $output_content_length < $original_html_length * 0.1) {
        \error_log(\sprintf('[HTML to Blocks] Significant content loss detected | Input: %d chars | Output: %d chars | Blocks: %d | Processor error: %s | Preview: %s', $original_html_length, $output_content_length, \count($blocks), $last_error ?? 'none', \substr($html, 0, 500)));
    }
    return $blocks;
}
/**
 * Creates the core/html fallback block and emits an observability hook.
 *
 * @param string $element_html Unsupported HTML fragment.
 * @param array  $context      Fallback context such as reason, tag_name, and occurrence.
 * @return array Block array.
 */
function html_to_blocks_create_unsupported_html_fallback_block(string $element_html, array $context = []): array
{
    $block = HTML_To_Blocks_Block_Factory::create_block('core/html', ['content' => $element_html]);
    if (\function_exists('do_action')) {
        /**
         * Fires when h2bc falls back to core/html because no supported transform exists.
         *
         * @param string $element_html Unsupported HTML fragment.
         * @param array  $context      Context including reason, tag_name, and occurrence when available.
         * @param array  $block        The generated core/html fallback block.
         */
        \do_action('html_to_blocks_unsupported_html_fallback', $element_html, $context, $block);
    }
    return $block;
}
/**
 * Finds all positions of a tag's opening tags in HTML
 *
 * @param string $html     Source HTML
 * @param string $tag_name Tag name to find
 * @return array Array of start positions
 */
function html_to_blocks_find_all_tag_positions($html, $tag_name)
{
    $positions = [];
    $pattern = '/<' . \preg_quote($tag_name, '/') . '(?:\s[^>]*)?>/i';
    if (\preg_match_all($pattern, $html, $matches, \PREG_OFFSET_CAPTURE)) {
        foreach ($matches[0] as $match) {
            $positions[] = $match[1];
        }
    }
    return $positions;
}
/**
 * Extracts element HTML at a specific occurrence
 *
 * @param string $html       Source HTML
 * @param string $tag_name   Tag name
 * @param array  $positions  Array of tag start positions
 * @param int    $occurrence Which occurrence (0-based)
 * @return string|null Element HTML or null
 */
function html_to_blocks_extract_element_at_occurrence($html, $tag_name, $positions, $occurrence)
{
    if (!isset($positions[$occurrence])) {
        return null;
    }
    $start_pos = $positions[$occurrence];
    $html_from_start = \substr($html, $start_pos);
    $void_elements = ['AREA', 'BASE', 'BR', 'COL', 'EMBED', 'HR', 'IMG', 'INPUT', 'LINK', 'META', 'PARAM', 'SOURCE', 'TRACK', 'WBR'];
    if (\in_array(\strtoupper($tag_name), $void_elements, \true)) {
        $pattern = '/<' . \preg_quote($tag_name, '/') . '(?:\s[^>]*)?\/?>/i';
        if (\preg_match($pattern, $html_from_start, $matches)) {
            return $matches[0];
        }
        return null;
    }
    return html_to_blocks_extract_balanced_element($html_from_start, $tag_name);
}
/**
 * Extracts a balanced element including nested elements of the same type
 *
 * @param string $html     HTML starting with the opening tag
 * @param string $tag_name Tag name to balance
 * @return string|null Balanced element HTML or null
 */
function html_to_blocks_extract_balanced_element($html, $tag_name)
{
    $depth = 0;
    $len = \strlen($html);
    $i = 0;
    $open_pattern = '/^<' . \preg_quote($tag_name, '/') . '(?:\s|>)/i';
    $close_pattern = '/^<\/' . \preg_quote($tag_name, '/') . '\s*>/i';
    while ($i < $len) {
        $remaining = \substr($html, $i);
        if (\preg_match($open_pattern, $remaining)) {
            $depth++;
        } elseif (\preg_match($close_pattern, $remaining, $close_match)) {
            $depth--;
            if ($depth === 0) {
                return \substr($html, 0, $i + \strlen($close_match[0]));
            }
        }
        $i++;
    }
    return null;
}
/**
 * Finds a matching raw transform for an element
 *
 * @param HTML_To_Blocks_HTML_Element $element    The element to match
 * @param array                       $transforms Array of transforms
 * @return array|null The transform data or null
 */
function html_to_blocks_find_transform($element, $transforms)
{
    foreach ($transforms as $transform) {
        $is_match = $transform['isMatch'] ?? null;
        if ($is_match && \is_callable($is_match) && \call_user_func($is_match, $element)) {
            return $transform;
        }
    }
    return null;
}
/**
 * Converts shortcodes in HTML to blocks
 *
 * @param string $html The HTML containing shortcodes
 * @return array Array of pieces (strings or blocks)
 */
function html_to_blocks_shortcode_converter($html)
{
    $pieces = [];
    $last_index = 0;
    \preg_match_all('/' . get_shortcode_regex() . '/', $html, $matches, \PREG_OFFSET_CAPTURE);
    if (empty($matches[0])) {
        return [$html];
    }
    foreach ($matches[0] as $match) {
        $shortcode = $match[0];
        $index = $match[1];
        if ($index > $last_index) {
            $pieces[] = \substr($html, $last_index, $index - $last_index);
        }
        $parsed = html_to_blocks_parse_shortcode($shortcode);
        $pieces[] = $parsed !== null ? $parsed : $shortcode;
        $last_index = $index + \strlen($shortcode);
    }
    if ($last_index < \strlen($html)) {
        $pieces[] = \substr($html, $last_index);
    }
    return $pieces;
}
/**
 * Parses a shortcode and returns a block if possible
 *
 * @param string $shortcode The shortcode string
 * @return array|null The block array or null
 */
function html_to_blocks_parse_shortcode($shortcode)
{
    $pattern = get_shortcode_regex();
    if (!\preg_match("/{$pattern}/", $shortcode, $match)) {
        return null;
    }
    return HTML_To_Blocks_Block_Factory::create_block('core/shortcode', ['text' => $shortcode]);
}
/**
 * Normalises blocks in HTML - wraps inline content in paragraphs
 *
 * @param string $html The HTML
 * @return string The normalized HTML
 */
function html_to_blocks_normalise_blocks($html)
{
    $processor = \WP_HTML_Processor::create_fragment($html);
    if (!$processor) {
        return $html;
    }
    $phrasing_tags = ['A', 'ABBR', 'B', 'BDI', 'BDO', 'BR', 'CITE', 'CODE', 'DATA', 'DFN', 'EM', 'I', 'KBD', 'MARK', 'Q', 'RP', 'RT', 'RUBY', 'S', 'SAMP', 'SMALL', 'SPAN', 'STRONG', 'SUB', 'SUP', 'TIME', 'U', 'VAR', 'WBR'];
    $body_depth = 2;
    $top_level_depth = $body_depth + 1;
    $output = '';
    $paragraph_buffer = '';
    $in_paragraph = \false;
    $last_was_br = \false;
    $tag_occurrences = [];
    $tag_positions = [];
    while ($processor->next_token()) {
        $token_type = $processor->get_token_type();
        $depth = $processor->get_current_depth();
        if ('#text' === $token_type && $depth === $body_depth) {
            $text = $processor->get_modifiable_text();
            if (\trim($text) === '') {
                continue;
            }
            if (!$in_paragraph) {
                $in_paragraph = \true;
            }
            $paragraph_buffer .= \htmlspecialchars($text, \ENT_QUOTES, 'UTF-8');
            $last_was_br = \false;
            continue;
        }
        if ('#tag' !== $token_type) {
            continue;
        }
        if ($depth < $top_level_depth && !$processor->is_tag_closer()) {
            continue;
        }
        $tag_name = $processor->get_tag();
        $tag_upper = \strtoupper($tag_name);
        $is_closer = $processor->is_tag_closer();
        $occurrence = null;
        if (!$is_closer) {
            if (!isset($tag_occurrences[$tag_name])) {
                $tag_occurrences[$tag_name] = 0;
                $tag_positions[$tag_name] = html_to_blocks_find_all_tag_positions($html, $tag_name);
            }
            $occurrence = $tag_occurrences[$tag_name]++;
        }
        if ($depth !== $top_level_depth && $depth !== $body_depth) {
            continue;
        }
        if ('BR' === $tag_upper && !$is_closer) {
            if ($last_was_br) {
                if (!empty(\trim($paragraph_buffer))) {
                    $output .= '<p>' . \trim($paragraph_buffer) . '</p>';
                }
                $paragraph_buffer = '';
                $in_paragraph = \false;
                $last_was_br = \false;
            } else {
                if ($in_paragraph && !empty($paragraph_buffer)) {
                    $paragraph_buffer .= '<br>';
                }
                $last_was_br = \true;
            }
            continue;
        }
        $last_was_br = \false;
        if (\in_array($tag_upper, $phrasing_tags, \true) && !$is_closer) {
            if (!$in_paragraph) {
                $in_paragraph = \true;
            }
            $element_html = html_to_blocks_extract_element_at_occurrence($html, $tag_name, $tag_positions[$tag_name], $occurrence);
            if ($element_html) {
                $paragraph_buffer .= $element_html;
            }
            continue;
        }
        if (!$is_closer && !\in_array($tag_upper, $phrasing_tags, \true)) {
            if ($in_paragraph && !empty(\trim($paragraph_buffer))) {
                $output .= '<p>' . \trim($paragraph_buffer) . '</p>';
            }
            $paragraph_buffer = '';
            $in_paragraph = \false;
            $element_html = html_to_blocks_extract_element_at_occurrence($html, $tag_name, $tag_positions[$tag_name], $occurrence);
            if ($element_html) {
                $output .= $element_html;
            }
        }
    }
    if ($in_paragraph && !empty(\trim($paragraph_buffer))) {
        $output .= '<p>' . \trim($paragraph_buffer) . '</p>';
    }
    return !empty($output) ? $output : $html;
}

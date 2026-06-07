# HTML to Blocks Converter

Server-side HTML-to-Gutenberg-blocks conversion plugin using WordPress Core's HTML API.

## Architecture Overview

### Core Components (Single Responsibility)

| File | Responsibility |
|------|---------------|
| `html-to-blocks-converter.php` | Plugin bootstrap, WordPress hook registration |
| `raw-handler.php` | Main conversion pipeline, HTML normalization, shortcode handling |
| `includes/class-html-element.php` | DOM-like interface adapter over WP_HTML_Processor |
| `includes/class-transform-registry.php` | Block transform definitions (heading, list, image, quote, code, preformatted, separator, table, paragraph) |
| `includes/class-block-factory.php` | Block array creation compatible with serialize_blocks() |
| `includes/class-attribute-parser.php` | Block attribute extraction from HTML using DOM parsing |

### Conversion Pipeline Flow

1. `html_to_blocks_convert_on_insert()` - Hooks into `wp_insert_post_data` filter
2. `html_to_blocks_raw_handler()` - Main entry point, handles shortcode preservation
3. `html_to_blocks_normalise_blocks()` - Wraps orphan inline content in paragraphs
4. `html_to_blocks_convert()` - Iterates top-level elements, matches transforms
5. Transform callbacks create blocks via `HTML_To_Blocks_Block_Factory::create_block()`

### Key Technical Decisions

- **WordPress HTML API (`WP_HTML_Processor`)**: HTML5 spec-compliant parsing that matches browser behavior
- **Token-based iteration**: Uses depth tracking to identify top-level elements
- **Balanced element extraction**: Custom regex-based extraction for nested structures
- **Transform priority system**: Lower priority = higher precedence (code transforms before preformatted)

## Supported Block Transforms

| HTML Element | Block Type | Priority |
|-------------|------------|----------|
| `h1`-`h6` | `core/heading` | 10 |
| `ol`, `ul` | `core/list` with `core/list-item` | 10 |
| `figure > img` | `core/image` | 10 |
| `img` | `core/image` | 15 |
| `blockquote` | `core/quote` | 10 |
| `pre > code` | `core/code` | 10 |
| `pre` | `core/preformatted` | 11 |
| `hr` | `core/separator` | 10 |
| `table` | `core/table` | 10 |
| `p` | `core/paragraph` | 20 |

## Public API

### Functions

- `html_to_blocks_raw_handler( array $args )` - Direct HTML-to-blocks conversion
  - `$args['HTML']` - HTML string to convert
  - Returns: Array of block arrays

### Filters

- `html_to_blocks_supported_post_types` - Modify supported post types (default: `['post', 'page']`)

## Requirements

- WordPress 6.4+ (WP_HTML_Processor dependency)
- PHP 7.4+

## Class Reference

### HTML_To_Blocks_HTML_Element

DOM-like interface over WP_HTML_Processor:
- `from_html( string $html )` - Static constructor
- `get_tag_name()` - Returns uppercase tag name
- `get_attribute( string $name )` - Get attribute value
- `has_attribute( string $name )` - Check attribute exists
- `get_inner_html()` - Get inner HTML content
- `get_outer_html()` - Get full element HTML
- `get_text_content()` - Get stripped text content
- `query_selector( string $selector )` - Find descendant element
- `query_selector_all( string $selector )` - Find all matching descendants
- `get_child_elements()` - Get direct child elements

### HTML_To_Blocks_Block_Factory

- `create_block( string $name, array $attributes, array $inner_blocks )` - Creates block array structure

### HTML_To_Blocks_Transform_Registry

- `get_raw_transforms()` - Returns all registered transforms sorted by priority

### HTML_To_Blocks_Attribute_Parser

- `get_block_attributes( string $block_name, string $html, array $overrides )` - Parse attributes from HTML

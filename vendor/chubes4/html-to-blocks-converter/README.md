# HTML to Blocks Converter

A WordPress plugin **and Composer package** that converts raw HTML to Gutenberg block arrays using WordPress Core's HTML API.

It works in two modes:

- **Plugin mode:** activate the plugin and it automatically converts raw HTML to blocks on `wp_insert_post()` and REST editor reads for public REST-enabled post types.
- **Package mode:** `composer require chubes4/html-to-blocks-converter` and load WordPress. Composer autoload registers the same conversion library and automatic hooks through the version registry. Consumers can also call `html_to_blocks_raw_handler()` directly.

## Description

This plugin provides server-side HTML-to-blocks conversion using WordPress Core's HTML API (`WP_HTML_Processor`) for spec-compliant HTML5 parsing. Inspired by Gutenberg's client-side `rawHandler` function from [`packages/blocks/src/api/raw-handling`](https://github.com/WordPress/gutenberg/tree/trunk/packages/blocks/src/api/raw-handling), it enables programmatic content creation with proper block structure, and ensures the block editor sees proper blocks for supported post types even when `post_content` contains raw HTML.

### Use Cases

- Migrating legacy content to Gutenberg blocks
- Importing content from external sources via REST API
- Programmatically creating posts with block-based content
- Converting HTML from headless CMS or content pipelines

## Supported Block Transforms

The plugin converts high-confidence static HTML patterns to their corresponding Gutenberg blocks:

| HTML signal | Block type |
|-------------|------------|
| `<h1>` - `<h6>` | `core/heading` |
| `<p>` and plain text | `core/paragraph` |
| `<ul>`, `<ol>` | `core/list` with `core/list-item` children |
| `<blockquote>` | `core/quote` |
| `<blockquote class="wp-block-pullquote">` | `core/pullquote` |
| `<figure><img>`, `<img>` | `core/image` |
| gallery-like wrappers with multiple images | `core/gallery` with `core/image` children |
| `<video>` / `<audio>` with a source | `core/video` / `core/audio` |
| recognized provider `<iframe>` embeds | `core/embed` |
| downloadable file anchors | `core/file` |
| media-text wrappers | `core/media-text` |
| WordPress button anchors | `core/buttons` with `core/button` children |
| `<details>` | `core/details` |
| `<pre class="wp-block-verse">` | `core/verse` |
| `<pre><code>` | `core/code` |
| `<pre>` | `core/preformatted` |
| `<hr>` | `core/separator` |
| `<table>` | `core/table` |
| WordPress shortcodes | `core/shortcode` |
| high-confidence semantic/layout wrappers | `core/group`, `core/columns`, `core/column`, `core/cover`, `core/spacer` |

Nested lists and blockquotes with multiple paragraphs are fully supported.

For the source-of-truth status of supported transforms, observed fallbacks,
future candidates, and context-required block families, see the
[Core Block Coverage Matrix](docs/core-block-coverage.md).

For Site Editor and block theme boundaries, including which block families
should not be inferred from raw HTML alone, see
[Site Editor Boundary](docs/site-editor-boundary.md).

For the supported subset h2bc intentionally keeps aligned with Gutenberg's
`rawHandler`, see [Gutenberg rawHandler Parity](docs/gutenberg-rawhandler-parity.md).

Unsupported top-level elements are preserved as `core/html` instead of guessed.
When that fallback is used, h2bc fires `html_to_blocks_unsupported_html_fallback`
with the unsupported HTML fragment, fallback context, and generated block so
production pipelines can log, warn, or fail on unexpected fallback usage.

Downstream tools can call `html_to_blocks_get_capabilities()` for a stable
capability inventory instead of parsing transform source. The inventory reports
the package version, raw handler availability, transform families, supported core
blocks, explicit Site Editor marker attributes, and fallback/metrics hook names.

## Installation

1. Download the plugin zip file
2. Navigate to Plugins > Add New > Upload Plugin
3. Upload the zip file and activate

Or clone directly to your plugins directory:

```bash
cd wp-content/plugins
git clone https://github.com/chubes4/html-to-blocks-converter.git
```

Or install as a Composer package:

```bash
composer require chubes4/html-to-blocks-converter
```

Composer autoloads `library.php`, which registers the conversion library
through an Action-Scheduler-style version registry. The winning library version
loads the raw handler and the automatic write/read hooks so bundled consumers get
the same HTML → blocks behavior as the standalone plugin.

When h2bc is bundled through php-scoper, callbacks registered with WordPress hook
APIs must resolve inside the scoped namespace. Build hook callback strings from
`__NAMESPACE__` so the same source works as the standalone plugin and as a scoped
dependency.

## Usage

The plugin hooks into `wp_insert_post_data` and automatically converts HTML content to blocks for supported post types. No configuration required for public REST-enabled post types.

### Programmatic Usage

```php
// Content will be automatically converted to blocks
wp_insert_post([
    'post_title'   => 'My Post',
    'post_content' => '<h1>Hello World</h1><p>This is my content.</p>',
    'post_status'  => 'publish',
    'post_type'    => 'post',
]);
```

### REST API Usage

```bash
curl -X POST https://yoursite.com/wp-json/wp/v2/posts \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "title": "My Post",
    "content": "<h1>Hello World</h1><p>This is my content.</p>",
    "status": "publish"
  }'
```

### Direct Conversion

```php
$html = '<h1>Title</h1><p>Paragraph with <strong>bold</strong> text.</p>';
$blocks = html_to_blocks_raw_handler(['HTML' => $html]);
$block_content = serialize_blocks($blocks);
```

## REST API Read Path (v0.4.0+)

The plugin also converts HTML to blocks when the block editor loads a post via the REST API. When `context=edit` is requested, any post with HTML in `content.raw` (no `<!-- wp:` block markup) is automatically converted to proper block markup before the editor sees it.

This means the block editor always shows proper blocks — even when `post_content` was written as raw HTML by a migration script, an external API, or another plugin. No "Convert to blocks" prompt.

The REST filters are registered at `init` priority 20 to ensure all custom post types are available.

### Package Mode

When loaded by Composer inside WordPress, the version registry loads both the
conversion API and the automatic hooks. Consumers that only need direct
conversion can call the raw handler without going through the hooks:

```php
// Available after Composer autoload runs.
$blocks = html_to_blocks_raw_handler([
    'HTML' => '<h1>Hello</h1><p>World</p>',
]);
```

Package consumers can call the raw handler directly for adapter pipelines, while
h2bc still registers its normal hooks for plain HTML write/read paths.

## Filters

### `html_to_blocks_supported_post_types`

Modify which post types support automatic HTML-to-blocks conversion.

```php
add_filter('html_to_blocks_supported_post_types', function($post_types) {
    $post_types[] = 'custom_post_type';
    return $post_types;
});
```

Default: all public REST-enabled post types via `get_post_types(['show_in_rest' => true, 'public' => true])`

### `html_to_blocks_unsupported_html_fallback`

Observe unsupported or intentionally ambiguous fragments that are preserved as
`core/html` instead of guessed.

```php
add_action('html_to_blocks_unsupported_html_fallback', function($html, $context, $block) {
    error_log('h2bc fallback: ' . ($context['reason'] ?? 'unknown'));
}, 10, 3);
```

### `html_to_blocks_loaded`

Runs after the version registry initializes the winning h2bc copy. Receives the
loaded version string.

## Architecture

The plugin uses WordPress Core's HTML API for parsing:

- **HTML Element Adapter** - DOM-like interface over `WP_HTML_Processor` for familiar traversal methods
- **Transform Registry** - PHP port of block transforms from `packages/block-library/src/*/transforms.js`
- **Block Factory** - Creates block arrays compatible with `serialize_blocks()`
- **Raw Handler** - Main conversion pipeline using `WP_HTML_Processor::create_fragment()`
- **Attribute Parser** - Extracts block attributes from HTML using WordPress HTML API

### Dual-mode loading

`library.php` is the package entry point. It registers the local copy's version
and initializer with `HTML_To_Blocks_Versions`. On `plugins_loaded:1`, the
registry initializes the highest registered version exactly once. This lets
multiple plugins bundle the package while the standalone plugin is also active;
everyone gets the newest loaded conversion library and no duplicate class/function
definitions.

`html-to-blocks-converter.php` is the plugin shell. It performs the standalone
plugin's WordPress/PHP guard checks, then loads `library.php`. Composer consumers
skip the plugin shell but still load the raw handler and automatic hooks through
the library initializer.

### Why WordPress HTML API?

- HTML5 spec-compliant parsing that matches browser behavior
- Proper UTF-8 character encoding handling
- Correct handling of implied/virtual tags
- WordPress Core maintained and security hardened
- Future-proof as the API continues to improve

## Requirements

- WordPress 6.4+ (required for `WP_HTML_Processor`)
- PHP 7.4+

## License

GPL v2 or later

## Credits

Directly inspired by the [Gutenberg](https://github.com/WordPress/gutenberg) project's client-side raw handling implementation.

## Author

[Chris Huber](https://chubes.net)

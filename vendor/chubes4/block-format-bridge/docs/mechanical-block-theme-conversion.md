# Mechanical Block-Theme Conversion Matrix

Issue: https://github.com/chubes4/block-format-bridge/issues/64

This matrix defines the public scope boundary for deterministic conversion from static HTML/CSS into WordPress block data for block themes. It is written for site-builder and compiler consumers that need to decide which work belongs in Block Format Bridge (BFB), which work belongs in html-to-blocks-converter (h2bc), and which work belongs in a higher-level compiler.

For the stack-level workflow across h2bc inventory/classification, BFB capability surfaces, and compiler consumers, see
[`block-theme-conversion-workflow.md`](block-theme-conversion-workflow.md).

BFB and h2bc handle deterministic conversion. They can map explicit source markup into WordPress block arrays and serialized block markup. They do not infer creative intent, site strategy, editorial structure, navigation decisions, template hierarchy, global Styles, or theme.json design systems from arbitrary markup. Those decisions belong above BFB.

## Categories

| Category | Meaning |
| --- | --- |
| Handled mechanically | Deterministic conversion can happen directly from source markup without extra site intent. |
| Explicit marker only | Conversion is safe only when the source carries an explicit marker naming the WordPress concept. |
| Compiler-only | A site compiler or generation layer must decide the intent before BFB/h2bc can serialize the result. |
| Unsupported/deferred | Not part of the current BFB/h2bc scope; tracked for later design or implementation. |

## Matrix

| Feature | Category | Scope boundary | Tracking |
| --- | --- | --- | --- |
| Groups and containers | Handled mechanically | Wrapper elements can become `core/group` or related layout blocks when the markup exposes a deterministic container boundary. h2bc owns the per-block transform; BFB routes the conversion. | h2bc #40: https://github.com/chubes4/html-to-blocks-converter/issues/40 |
| Columns | Handled mechanically | Repeated column-like child containers can map to `core/columns` and `core/column` when the source structure is explicit enough to preserve ordering and nesting. | h2bc #40: https://github.com/chubes4/html-to-blocks-converter/issues/40 |
| Cover and spacer | Handled mechanically | Background-image sections and spacing-only elements can map mechanically when the source element carries concrete image or spacing data. | h2bc transform coverage |
| Buttons | Handled mechanically | Anchor or button-like controls can map to `core/buttons` / `core/button` when the source exposes link, text, and button grouping directly. | h2bc transform coverage |
| Media, gallery, embed, and file | Handled mechanically | Media elements and recognized external embeds can map to their corresponding content blocks when source URLs and captions are explicit. BFB does not choose media strategy or replace assets. | h2bc transform coverage |
| Block supports | Handled mechanically | Classes, inline styles, anchors, spacing, alignment, and color-like values can be preserved when h2bc maps them to block attributes that WordPress core serializes. BFB's contract is preserving the block arrays it receives. | h2bc #39: https://github.com/chubes4/html-to-blocks-converter/issues/39 |
| Patterns | Explicit marker only | BFB/h2bc should not infer that an arbitrary repeated layout is a reusable pattern. A compiler may emit explicit pattern markers that BFB/h2bc can preserve or expand. | BFB #67: https://github.com/chubes4/block-format-bridge/issues/67 |
| Template parts | Explicit marker only | Header, footer, sidebar, and other template-part boundaries require explicit source markers. Without markers, they are just containers. | BFB #63: https://github.com/chubes4/block-format-bridge/issues/63 |
| Static navigation | Explicit marker only | Static navigation HTML can be represented as block markup only when source intent is explicit enough to distinguish menu structure from ordinary lists and links. | h2bc #41: https://github.com/chubes4/html-to-blocks-converter/issues/41 |
| Navigation persistence | Compiler-only | Creating or updating persisted WordPress navigation entities is a site decision, not a format-conversion decision. A compiler can decide persistence, then hand resulting block markup to BFB. | BFB #62: https://github.com/chubes4/block-format-bridge/issues/62 |
| Query, comments, and post theme blocks | Explicit marker only | Dynamic theme blocks represent WordPress runtime queries and post context. BFB/h2bc can only emit them safely from explicit markers, not from similar-looking static markup. | h2bc #42: https://github.com/chubes4/html-to-blocks-converter/issues/42 |
| theme.json generation | Compiler-only | Global Styles, design tokens, presets, and theme.json settings require site-wide design decisions. BFB does not generate theme.json. | BFB #61: https://github.com/chubes4/block-format-bridge/issues/61 |
| Block-array helper surface | Unsupported/deferred | Compiler consumers may need a public block-array helper so they can inspect and split converted output before serialization. That is an API addition, not part of this docs change. | BFB #65: https://github.com/chubes4/block-format-bridge/issues/65 |
| WP-CLI conversion command | Unsupported/deferred | Non-PHP compiler tools may need a CLI wrapper around BFB's public API. The command should be a thin wrapper after the PHP helper surface stabilizes. | BFB #66: https://github.com/chubes4/block-format-bridge/issues/66 |

## Boundary In Practice

Use BFB/h2bc for deterministic format conversion:

```text
HTML / Markdown / declared source format
        |
        v
     BFB adapter
        |
        v
   h2bc block transforms
        |
        v
WordPress block arrays / serialized block markup
```

Use a compiler above BFB when the work requires site intent:

```text
Site goal / design system / content strategy
        |
        v
 compiler or generation layer
        |
        +--> templates and template parts
        +--> patterns
        +--> navigation persistence
        +--> Styles and theme.json
        |
        v
 BFB/h2bc for deterministic block serialization
```

The split is intentional: BFB should make the mechanical conversion reliable and boring. Creative intent, editorial hierarchy, design-token selection, and site assembly should remain explicit inputs to the layer that calls BFB.

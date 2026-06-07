# Site Editor Boundary

`html-to-blocks-converter` is a raw-transform library. It converts deterministic
HTML fragments into Gutenberg block arrays and falls back safely when a fragment
does not match a known transform. It is not a block theme or Site Editor
compiler.

## Raw Handler Pattern

The `html_to_blocks_raw_handler()` flow mirrors Gutenberg's raw-handler shape:

```text
HTML fragment
  -> shortcode split
  -> HTML5 fragment parse through WP_HTML_Processor
  -> registered raw block transforms
  -> core/html fallback for unknown top-level elements
  -> block arrays for serialize_blocks()
```

This layer is intentionally deterministic. It should only produce a semantic
block when the HTML fragment itself contains enough information to choose that
block without knowing the surrounding template, site identity, query, or theme.
The source-of-truth list of supported transforms, observed fallbacks, future
candidates, and context-required block families lives in the
[Core Block Coverage Matrix](core-block-coverage.md).

## Block Family Boundary

| Block family | Status | Boundary |
|---|---|---|
| `core/paragraph` | Raw-transformable | Plain text and `<p>` map directly. |
| `core/heading` | Raw-transformable | `<h1>` through `<h6>` map to heading levels. A compiler may later choose a site-title, post-title, or query-title block when it has that intent. |
| `core/list`, `core/list-item` | Raw-transformable | `<ul>` and `<ol>` map directly, including nested lists. |
| `core/quote` | Raw-transformable | `<blockquote>` maps directly, with nested static content handled recursively. |
| `core/image` | Raw-transformable with conservative heuristics | `<img>` and `<figure><img>` map to static image blocks. Media-library attachment identity is not inferred. |
| `core/code`, `core/preformatted` | Raw-transformable | `<pre><code>` and `<pre>` map directly. |
| `core/separator` | Raw-transformable | `<hr>` maps directly. |
| `core/table` | Raw-transformable | `<table>` maps to a static table block. |
| `core/html` | Safe fallback | Unknown or intentionally unsupported fragments are preserved as custom HTML instead of guessed. |
| Layout-only static containers | Raw-transformable with conservative heuristics | Groups, columns, covers, buttons, and similar layout blocks may be added only when the HTML pattern is unambiguous and the fallback remains lossless. |
| `core/pattern` | Explicit-marker raw-transformable | Requires `data-h2bc-pattern="namespace/slug"` or the shared BFB alias `data-bfb-pattern="namespace/slug"`. Similar-looking layout is not enough. |
| `core/template-part` | Explicit-marker raw-transformable | Requires `data-h2bc-template-part="area-or-slug"` or the shared BFB alias `data-bfb-template-part="area-or-slug"`. Header/footer-looking layout is not enough. |
| Rendered navigation markup | Safe fallback | `<nav>` fragments are preserved as `core/html` in default raw conversion because native navigation blocks do not have a valid static serialization shape here. |
| Native `core/navigation*` | Context-required | Requires editor-valid serialization, menu intent, site route knowledge, menu-location policy, and optional `wp_navigation` post lifecycle ownership. |
| `core/navigation-link`, `core/navigation-submenu` | Context-required | These are native navigation children. Standalone links and nested lists are static content unless a compiler owns the parent navigation block contract. |
| `core/site-title`, `core/site-logo`, `core/site-tagline` | Context-required | Requires site identity metadata. |
| `core/post-title`, `core/post-content`, `core/post-excerpt`, `core/post-featured-image`, and related post-data blocks | Context-required | Requires current template and post context. |
| `core/query*`, `core/post-template` | Context-required | Requires content model and loop intent. |
| `core/comments*`, `core/comment-*` | Context-required | Requires comment-template context. |
| Dynamic utility blocks | Context-required | Archives, categories, latest posts, RSS, tag cloud, loginout, and similar blocks require site data intent. |
| WooCommerce product/catalog blocks | Context-required | Requires explicit commerce/product context, product identity, and importer policy. Static product cards remain editable static blocks by default. |
| Interactive or stateful app blocks | Intentionally unsupported | Arbitrary HTML is not enough to infer application state, data sources, or editor controls. |

The important rule is that rendered HTML is not identity. The same `<h1>` could
be a static heading, site title, post title, or query title. This package should
choose `core/heading` because that is the only answer proven by the fragment.

## Public Capability Inventory

Consumers should call `html_to_blocks_get_capabilities()` instead of scraping
registry source files. The returned inventory includes the package version, raw
handler function name and availability, transform families, supported core block
names, explicit Site Editor marker attributes, and fallback/metrics hook names.

The transform family inventory is stable enough for downstream capability checks,
but individual callback names and registry internals remain private.

## Heuristic Review Standard

Heuristic transforms are allowed when they identify generic static HTML patterns
using class-token families paired with structure or content checks. Examples are
button-like anchors with visible text, code-window chrome around real code, cards
with repeated child structure, or decorative wrappers that preserve meaningful
descendant content.

Navigation, WooCommerce identity, query/post/site-title/template intent stay
compiler-only. The raw transform layer may preserve visible text, links, images,
classes, and safe static layout; it must not emit native context-required blocks
from visual similarity alone.

Exact brand, site, product, or fixture names belong in tests and docs, not
production transform rules. Production rules should be phrased as reusable
tokens, attributes, structure checks, or explicit context gates. If a new rule
needs a named brand or product in production code, document why that identifier is
part of a shared public contract rather than a fixture shortcut.

Destructive simplifications must preserve visible text and safe source classes,
or fall back to `core/html`.

## Static Navigation Boundary

Rendered navigation is intentionally preserved instead of converted to native
navigation blocks by default. A simple static list can fully describe the visible
links, but core navigation blocks also need an editor-valid serialization shape:

```html
<nav aria-label="Primary">
  <ul>
    <li><a href="/about/">About</a></li>
    <li><a href="/products/">Products</a>
      <ul>
        <li><a href="/products/a/">Product A</a></li>
      </ul>
    </li>
  </ul>
</nav>
```

Default raw conversion therefore preserves that fragment as `core/html`. This is
mechanical and side-effect free:

- No `wp_navigation` posts are created, queried, or reused.
- No native `core/navigation` / `core/navigation-link` blocks are emitted.
- No menu location, current menu, site route, homepage, or global navigation
  intent is inferred.
- The visible source markup remains intact for the editor and frontend.

Native navigation belongs to a higher-level WordPress integration layer that owns
editor validation, optional `wp_navigation` entity lifecycle, and site policy
decisions.

## Commerce Product Boundary

Rendered product cards can describe visible catalog content, but they do not prove
WooCommerce product identity or checkout behavior. A static card like this is
only rendered output:

```html
<article class="product-card" data-product-slug="country-sourdough">
  <img src="/products/country-sourdough.jpg" alt="Country sourdough loaf" />
  <h3>Country Sourdough</h3>
  <p>A 48-hour cold-fermented loaf.</p>
  <span class="product-card__price">$14 per loaf</span>
  <a href="/shop/country-sourdough/">View loaf</a>
</article>
```

Default raw conversion should preserve that content as editable static blocks,
not as WooCommerce-native state. This is intentional:

- No WooCommerce products are created, queried, or matched.
- No `woocommerce/*` blocks are emitted from visual similarity alone.
- Prices, badges, CTAs, images, and product-looking classes stay visible in the
  serialized static output.
- Diagnostics and fixtures should make unmatched or low-confidence commerce
  regions observable without changing normal content conversion.

WooCommerce-native blocks or materialized product placeholders belong to an
importer/compiler layer that owns explicit product context, SKU/slug matching,
inventory policy, checkout routing, and any WooCommerce entity lifecycle. For the
current implementation ladder, h2bc only provides the safe raw-transform substrate
and gated fixture coverage; product data creation and context forwarding remain
owned by upstream Static Site Importer and Block Format Bridge work.

## Theme Block Classification

| Block family | Classification | Why |
|---|---|---|
| Rendered navigation markup | Fallback observed | The fragment carries visible links but not a valid native navigation serialization contract. |
| `core/pattern` | Explicit-marker supported | Requires `data-h2bc-pattern="namespace/slug"`; `data-bfb-pattern` is a documented shared alias for BFB. h2bc does not choose patterns by visual similarity. |
| `core/template-part` | Explicit-marker supported | Requires `data-h2bc-template-part="area-or-slug"`; `data-bfb-template-part` is a documented shared alias for BFB. h2bc does not split regions by visual similarity. |
| Native `core/navigation` blocks | Compiler-only | Requires editor-valid serialization, route knowledge, menu policy, and optional `wp_navigation` post lifecycle. |
| `core/site-title`, `core/site-logo`, `core/site-tagline` | Compiler-only | Requires site identity metadata; rendered HTML is only static output. |
| `core/post-title`, `core/post-content`, `core/post-excerpt`, `core/post-featured-image` | Compiler-only | Requires current post/template context. |
| `core/query`, `core/post-template`, query pagination/title blocks | Compiler-only | Requires query args, loop intent, and content-model context. |
| `core/comments` and `core/comment-*` blocks | Compiler-only | Requires comment-query context and per-comment state. |
| Dynamic utility blocks | Unsupported | Raw HTML does not carry site-data intent for archives, latest posts, RSS, search, calendars, login state, or tag clouds. |
| WooCommerce product/catalog blocks | Compiler-only | Requires explicit commerce context and product identity. Product-looking static cards remain static layout/content unless an importer has already supplied product data and materialization policy. |

## Future Block Theme Compiler Layer

Block theme generation belongs above this package and above format bridges that
use it. A site compiler can carry the intent that raw HTML lacks:

```text
static HTML/CSS/site spec
  -> block theme compiler
      -> split regions: header, footer, main, templates, parts
      -> infer theme.json tokens: palette, typography, spacing
      -> call h2bc for static fragments
      -> insert explicit Site Editor blocks where intent is known
  -> block theme files
      -> theme.json
      -> templates/*.html
      -> parts/*.html
```

That compiler can decide that a region is a `core/template-part`, that a heading
is `core/site-title`, or that repeated cards are a `core/query` loop. Once it has
made those intent-aware decisions, it can still delegate static fragments to
`html_to_blocks_raw_handler()`.

## Recommendation

Keep h2bc focused on deterministic raw transforms. Template identity, query
semantics, navigation intent, and theme design-token extraction should live in a
separate block theme compiler package or plugin layered above h2bc and Block Format
Bridge. That keeps this package small, predictable, and safe as a reusable
conversion primitive.

# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.7.2] - 2026-06-02

### Changed
- Preserve class-sensitive action link rows as HTML
- Preserve generic button variant action rows
- Preserve semantic tag spans as paragraph labels
- Reset inline preservation paragraph margins
- Convert numeric label spans to paragraph blocks
- Narrow numbered card span fallback
- Add regression for visible diagram span containers
- Preserve classed span card children as HTML islands
- Transform diagram span containers to paragraphs
- align visible span ruler coverage
- align leaf span smoke coverage
- Preserve aria-hidden span rulers as paragraphs
- Add classed leaf span regression coverage
- Preserve classed leaf spans as fallback HTML
- consolidate branded anchor smoke coverage
- Preserve button variant anchor classes
- Transform nested decorative figures with captions
- Normalize brand anchor wrappers before paragraph conversion
- Allow small tags in branded link text
- Normalize standalone hash anchors before conversion
- Transform branded hash anchors to paragraphs
- Preserve standalone anchor attributes in paragraph fallback
- Add regression for formatted branded links
- Avoid inferred hero flex layout
- Convert caption-only decorative figures to native blocks
- Tighten placeholder form matching
- Add static placeholder form transform
- Add regression for empty ruler separators
- Transform empty divider elements as separators
- Treat ruler classes as decorative empty elements

### Fixed
- satisfy image URL comparison lint
- Fix decorative figure fallback
- apply resolved asset metadata
- Fix action rows and card images
- keep branded anchor classes inline
- match separator block serialization

## [0.7.1] - 2026-05-09

### Changed
- Add wrapped definition list smoke coverage
- Handle plain hero sections as block groups
- cover product card group conversion
- Handle product card raw fallback as structured blocks
- gate product grid commerce diagnostics
- cover Extra Chill fallback reductions

### Fixed
- convert simple definition lists natively
- convert simple checkbox labels natively
- convert branded freeform links natively
- preserve empty scan divs as native groups
- preserve inline justify-content on core/buttons containers
- drop empty structural div fragments
- serialize svg image dimensions with CSS units
- avoid redundant large card scans
- reduce repeated card child traversal
- prioritize repeated card grid matching
- speed up large repeated card conversion

## [0.7.0] - 2026-05-04

### Added
- expose safe inline SVG icon contract

### Changed
- add gated product grid fixture
- cover rich UI cluster fallback regression
- Create LICENSE

### Fixed
- resolve lint residuals (#236)
- convert static visual buttons with inline JS handlers to native blocks
- convert decorative product placeholders
- thread raw handler context through transforms
- preserve CTA anchor class ownership
- serialize separators with opacity class
- normalize group border support extraction
- convert terminal blank spacer spans natively
- accept safe SVG primitive icon attributes
- cover text-only metric stat cards
- convert project card status divs
- convert decorative and CTA wrapper divs
- drop empty code dot chrome
- Fix SVG image resize serialization
- recurse through generic wrapper sections
- fix decorative group inline styles
- convert repeated card grids
- localize form fallback scope
- convert task-check status divs
- convert traffic-light decorative dots
- convert static button tabs
- convert decorative empty div classes
- convert progress fill divs
- convert code comparison panels
- convert static visual labels to paragraphs

## [0.6.12] - 2026-05-03

### Fixed
- preserve explicit btn anchors as buttons

## [0.6.11] - 2026-05-03

### Fixed
- preserve inline scroller rows
- convert decorative inline spans safely

## [0.6.10] - 2026-05-03

### Fixed
- convert custom button anchors natively

## [0.6.9] - 2026-05-03

### Changed
- Keep standalone smoke checks green

### Fixed
- convert visual lists to groups
- convert code preview panels natively
- preserve hero code window panels
- convert action link groups to buttons
- convert parsed image html blocks
- convert empty connector divs natively
- convert image-only wrappers natively
- convert workflow code panels
- preserve group section anchors
- ignore decorative nav logo dots
- normalize decorative strip items
- preserve hero group flex layout
- convert div line code panels
- ignore trivial theme part fragments
- serialize group background styles
- serialize empty wrapper blocks
- convert code-window and accent chrome natively
- convert decorative code chrome natively
- convert multiline code display divs natively
- ignore empty decorative icon placeholders

## [0.6.8] - 2026-05-01

### Changed
- cover quote author avatar metadata conversion
- cover scoped inline script fallbacks
- cover step timeline connector conversion

### Fixed
- convert code display divs natively
- convert code-window snippets natively
- expose safe SVG icon classification
- preserve rich static chrome clusters
- convert empty glow decorative divs
- convert div code snippets natively
- convert testimonial figure quotes natively
- convert code demo widgets natively
- convert empty BEM decorative divs
- convert code-window demos natively
- isolate code-window fallbacks
- preserve custom CTA anchor styling
- convert static nav wrappers
- convert empty background decorative divs
- convert address wrappers natively
- convert empty decorative overlays
- convert quote attribution wrappers
- convert info contact blocks natively
- convert decorative visual clusters
- convert product card bodies natively
- convert decorative div chrome to native blocks
- convert detail wrappers to groups
- isolate inline SVG fallbacks
- preserve nested landing-page layout content
- preserve inline text in footer groups

## [0.6.7] - 2026-04-30

### Fixed
- convert common static wrappers to groups

## [0.6.6] - 2026-04-29

### Fixed
- preserve navigation markup as fallback

## [0.6.5] - 2026-04-29

### Fixed
- include color support classes in static HTML

## [0.6.4] - 2026-04-29

### Fixed
- serialize paragraph style supports

## [0.6.3] - 2026-04-29

### Fixed
- split multi-anchor CTA rows into buttons
- preserve static navigation classes before serialization

## [0.6.2] - 2026-04-29

### Fixed
- preserve preformatted wrapper classes
- avoid duplicate classed list wrappers
- reduce static chrome html fallbacks

## [0.6.1] - 2026-04-29

### Changed
- align media-text fixture expectations
- gate core block coverage docs
- gate core block inventory classification
- tighten smoke harness lint contracts

### Fixed
- avoid duplicate descendants in raw conversion
- honor block attrs during static serialization
- clean production PHPStan findings

## [0.6.0] - 2026-04-28

### Added
- preserve explicit block support signals
- support explicit Site Editor markers
- convert static navigation HTML
- map mechanical block supports from HTML

### Changed
- cover explicit Site Editor primitive markers

## [0.5.1] - 2026-04-28

### Changed
- add core transform matrix smoke

## [0.5.0] - 2026-04-28

### Added
- expose unsupported html fallback hook
- add media embed raw transforms
- add action text raw transforms
- add conservative layout raw transforms
- support dual-mode package loading

### Changed
- isolate scoped REST smoke globals
- make scoped REST smoke prefix-safe
- keep scoped REST smoke namespace-safe
- add Gutenberg rawHandler parity fixtures
- run smoke tests on pull requests
- cover raw handler fixture fallbacks

### Fixed
- register scoped REST callback safely
- support landmark containers
- namespace-safe callback for php-scoper compatibility
- register hooks in package mode
- make package autoload no-op outside WordPress

## [0.4.0] - 2026-04-15

### Added
- HTML→blocks conversion on REST API read path for the block editor — posts with raw HTML in `content.raw` are automatically converted to block markup when the editor requests `context=edit`

### Fixed
- Register REST filters at `init` priority 20 so custom post types (e.g. Intelligence wiki) are available when `get_post_types()` is called

## [0.2.3] - 2026-01-18

### Fixed
- Fixed block detection to check if content contains blocks anywhere, not just at the start
- Added content loss prevention that aborts conversion when >70% of text content would be lost

## [0.2.2] - 2026-01-08

### Added

- Enhanced error logging and content loss detection
- Added validation checks for `WP_HTML_Processor` failures
- Improved handling of element extraction failures with detailed logs

### Fixed

- Added detection for significant content loss during conversion process
- Improved robustness of HTML fragment processing

## [0.2.1] - 2026-01-07

### Changed

- Default supported post types now include all public REST API post types (via `get_post_types()` with `public` + `show_in_rest`), instead of only `post` and `page`

## [0.2.0] - 2025-11-27

### Changed

- Migrated HTML parsing from PHP's DOMDocument to WordPress Core's HTML API (`WP_HTML_Processor`)
- HTML5 spec-compliant parsing that matches browser behavior
- Proper UTF-8 character encoding handling
- Improved handling of nested elements (lists, blockquotes, tables)

### Added

- `HTML_To_Blocks_HTML_Element` adapter class for DOM-like interface over WordPress HTML API
- WordPress 6.4+ version requirement check with admin notice

### Fixed

- Fixed duplicate content when processing multiple elements of the same tag type by using occurrence-based element tracking

### Technical

- Replaced `DOMDocument::loadHTML()` with `WP_HTML_Processor::create_fragment()`
- Replaced DOM traversal with token-based iteration
- Added occurrence-based element extraction for accurate sequential processing
- Transform callbacks now receive `HTML_To_Blocks_HTML_Element` instead of `DOMNode`

## [0.1.0] - 2025-11-26

### Added

- Initial release
- Server-side HTML to Gutenberg blocks conversion
- Support for core block transforms:
  - `core/heading` (h1-h6)
  - `core/paragraph` (p)
  - `core/list` and `core/list-item` (ul, ol, li with nested list support)
  - `core/quote` (blockquote with inner block support)
  - `core/image` (figure with img, standalone img)
  - `core/code` (pre > code)
  - `core/preformatted` (pre without code)
  - `core/separator` (hr)
  - `core/table` (table with thead, tbody, tfoot)
- Automatic conversion on `wp_insert_post()` and REST API post creation
- `html_to_blocks_supported_post_types` filter for customizing supported post types
- `html_to_blocks_raw_handler()` function for direct conversion
- Shortcode preservation during conversion
- Inline content normalization (wraps orphan text in paragraphs)

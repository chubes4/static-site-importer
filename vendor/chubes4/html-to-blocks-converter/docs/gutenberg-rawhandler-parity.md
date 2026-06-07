# Gutenberg rawHandler Parity

This project tracks Gutenberg `rawHandler` parity for deterministic static HTML
patterns only. The goal is not to reimplement the full paste pipeline. Gutenberg
also normalizes source-specific clipboard markup from Google Docs, Microsoft
Word, Slack, Markdown paste, and browser image clipboard data. Those behaviors
depend on editor/browser context and are outside h2bc's current server-side
boundary.

The parity contract is:

- If Gutenberg can infer a static core block from ordinary raw HTML alone, h2bc
  should either match that block family or document why it intentionally does
  not.
- If a block requires site, template, query, post, comment, navigation, media
  library, editor, or browser clipboard context, h2bc must not guess.
- Ambiguous or unsupported top-level fragments should remain observable as
  `core/html` fallbacks.

The executable parity contract lives in
`tests/GutenbergRawHandlerParityUnitTest.php`. The broader support matrix lives
in `docs/core-block-coverage.md`.

## Covered Static Expectations

The parity fixture suite currently covers these Gutenberg-compatible static
expectations:

- headings
- paragraphs and inline formatting
- lists and nested list items
- quotes
- images with captions
- code and preformatted text
- separators
- tables
- WordPress shortcodes
- explicit layout wrappers: group, columns, cover, spacer
- explicit action/text blocks: buttons, details, pullquote, verse
- explicit media/embed blocks: video, audio, gallery, media-text, file, embed

## Intentionally Out Of Scope

These are Gutenberg paste/rawHandler-adjacent capabilities, but h2bc should not
claim support until a separate fixture and implementation lands:

- Google Docs, Microsoft Word, Apple Pages, LibreOffice, Slack, and Evernote
  cleanup passes.
- Browser clipboard image data.
- Markdown paste conversion as a source format. h2bc consumes HTML; format
  orchestration belongs to callers.
- Dynamic, contextual, or Site Editor block inference such as navigation, template
  parts, query loops, site identity blocks, post data blocks, comments, and
  dynamic utility blocks.

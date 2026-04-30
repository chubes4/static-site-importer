# Static Site Importer

Import a static HTML site into WordPress as a block theme using Block Format Bridge.

## MVP

- Adds `Appearance -> Import Static Site`.
- Accepts a ZIP containing `index.html`.
- Extracts inline CSS into `style.css`.
- Splits `nav`/`header`, main sections, and `footer` into block theme files.
- Writes imported page bodies as theme patterns and references them from page-specific templates.
- Converts HTML fragments through `bfb_convert( $html, 'html', 'blocks' )`.
- Generates a minimal block theme and optionally activates it.

## CLI

```bash
wp static-site-importer import-theme /path/to/index.html \
  --slug=wordpress-is-dead \
  --name="WordPress Is Dead" \
  --activate \
  --overwrite
```

## Smokes

PHP smokes run inside WordPress with Block Format Bridge active:

```bash
wp eval-file tests/smoke-wordpress-is-dead-fixture.php
wp eval-file tests/smoke-editor-style-support.php
```

The generated-theme JavaScript smoke runs Gutenberg's block validator against imported theme artifacts:

```bash
npm install
npm run test:js-block-validation -- /path/to/wp-content/themes/wordpress-is-dead
```

If no path is passed, the smoke uses `STATIC_SITE_IMPORTER_THEME_DIR`, then `WP_CONTENT_DIR/themes/wordpress-is-dead`
when `WP_CONTENT_DIR` is set. It validates `parts/header.html`, `parts/footer.html`, `patterns/*.php`, and
`templates/*.html`, and reports invalid blocks with the file, nested block path, and validation reason.

The smoke is expected to fail against the current generated `wordpress-is-dead` theme until the upstream h2bc native
blockability fixes in chubes4/html-to-blocks-converter#70 and chubes4/html-to-blocks-converter#71 land. The failure
output is still useful: it identifies exactly which generated artifact and nested block would trigger Site Editor block
validation warnings.

## Boundary

This plugin owns static-site import workflow. Block Format Bridge owns format conversion.

Imported pages remain WordPress pages for routing, titles, front-page assignment, and editor visibility. Their imported
body layouts live in generated block-theme artifacts: `patterns/page-*.php` plus matching `templates/page-*.html` files.
The generic `templates/page.html` stays a `wp:post-content` fallback for pages created after import.

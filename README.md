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

## Validation Harness

The importer uses a fixture-first TDD loop:

1. `tests/fixtures/wordpress-is-dead/` is the source static site.
2. The harness imports that fixture into a generated block theme.
3. PHP smokes prove importer behaviour and frontend fidelity in WordPress.
4. The JS smoke runs Gutenberg's block parser/validator over generated theme artifacts so editor-invalid block
   serialization is caught before manual Site Editor checks.

Run the full local contract from the repository root:

```bash
npm install
npm run test:validation
```

The runner uses `studio wp --path /Users/chubes/Studio/intelligence-chubes4` by default, imports the fixture as the
active `wordpress-is-dead` theme, then runs the PHP and JS smokes in dependency order. Override the Studio site path
with `STATIC_SITE_IMPORTER_SITE_PATH=/path/to/site`, override the whole WP-CLI command with
`STATIC_SITE_IMPORTER_WP_CLI="wp"`, or validate an already-imported theme without mutating it:

```bash
npm run test:validation -- --skip-import /path/to/wp-content/themes/wordpress-is-dead
```

Machine-readable output is available for future CI wiring:

```bash
npm run test:validation -- --json
```

## Individual Smokes

PHP smokes run inside WordPress with Block Format Bridge active:

```bash
wp eval-file tests/smoke-wordpress-is-dead-fixture.php
wp eval-file tests/smoke-editor-style-support.php
wp eval-file tests/smoke-admin-import-html-entry.php
```

The generated-theme JavaScript smoke runs Gutenberg's block validator against imported theme artifacts:

```bash
npm install
npm run test:js-block-validation -- /path/to/wp-content/themes/wordpress-is-dead
npm run test:js-block-validation -- --json /path/to/wp-content/themes/wordpress-is-dead
```

If no path is passed, the smoke uses `STATIC_SITE_IMPORTER_THEME_DIR`, then `WP_CONTENT_DIR/themes/wordpress-is-dead`
when `WP_CONTENT_DIR` is set. It validates `parts/header.html`, `parts/footer.html`, `patterns/*.php`, and
`templates/*.html`, and reports invalid blocks with the file, nested block path, block name, validation reason, and
failure summaries grouped by block name and file.

When generated markup still contains editor-invalid blocks, the JS smoke is expected to fail. The failure output is the
contract: it identifies exactly which generated artifact, block type, and nested block path would trigger Site Editor
validation warnings. Current known failures are expected to cluster around native navigation serialization until the
upstream navigation fix lands.

## Boundary

This plugin owns static-site import workflow. Block Format Bridge owns format conversion.

Imported pages remain WordPress pages for routing, titles, front-page assignment, and editor visibility. Their imported
body layouts live in generated block-theme artifacts: `patterns/page-*.php` plus matching `templates/page-*.html` files.
The generic `templates/page.html` stays a `wp:post-content` fallback for pages created after import.

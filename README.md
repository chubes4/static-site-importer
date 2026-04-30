# Static Site Importer

Import a static HTML site into WordPress as a block theme using the bundled [Block Format Bridge](https://github.com/chubes4/block-format-bridge) converter.

Static Site Importer is a WordPress plugin. It installs [Block Format Bridge](https://github.com/chubes4/block-format-bridge) through Composer and loads its bundled converter directly from `vendor/`.

## What It Does

- Adds an **Import HTML** button on the **Appearance -> Themes** screen.
- Accepts pasted HTML, a direct `.html` / `.htm` upload, or a ZIP containing an `index.html` file.
- Provides a WP-CLI importer for a local HTML entry file.
- Discovers sibling `*.html` files beside the entry file and imports them as WordPress pages.
- Converts static HTML fragments through `bfb_convert( $html, 'html', 'blocks' )`.
- Generates a block theme with shared header/footer template parts, page templates, page patterns, `theme.json`, `style.css`, and optional `assets/site.js`.
- Rewrites local `.html` links to the imported WordPress page permalinks.
- Creates deterministic `wp_navigation` posts for supported header/footer navigation and references them from generated template parts.
- Preserves imported pages as editor-visible WordPress page shells while the visible imported layouts live in generated block-theme patterns and templates.
- Optionally activates the generated theme and assigns the imported `index.html` page as the front page.

## Requirements

- WordPress 6.6 or later.
- PHP 8.1 or later.
- Composer dependencies installed for development/source checkouts.
- Node dependencies installed only when running the JavaScript block-validation smoke tests.

The current Composer dependency is [`chubes4/block-format-bridge:^0.6.7`](https://github.com/chubes4/block-format-bridge). That package includes the prefixed HTML-to-blocks converter used by the importer.

## Admin Usage

1. Open **Appearance -> Themes** and click **Import HTML** beside the standard **Add Theme** button.
2. Paste a single HTML document, upload a single `.html` / `.htm` file, or upload a ZIP containing `index.html`.
3. Optionally provide a theme name and slug.
4. Leave **Activate imported theme** checked if the generated theme should become active immediately.

The admin path always overwrites an existing generated theme with the same slug. Pasted HTML and direct HTML uploads are copied into a generated upload work directory as `index.html` and imported as a single-page site. ZIP uploads are extracted to an upload work directory, the first `index.html` is used as the entry file, and sibling HTML files from that extracted site directory are imported.

## CLI Usage

```bash
wp static-site-importer import-theme /path/to/index.html \
  --slug=wordpress-is-dead \
  --name="WordPress Is Dead" \
  --activate \
  --overwrite
```

The CLI path imports all readable sibling `*.html` files in the same directory as the provided entry file. `index.html` becomes the `home` page slug and is used for the generated `front-page.html` pattern reference.

## Generated Theme Shape

An import writes a conventional block theme directory under `wp-content/themes/<slug>/`:

```text
<slug>/
  style.css
  functions.php
  theme.json
  assets/site.js          # only when the source has inline JS
  parts/header.html
  parts/footer.html
  templates/front-page.html
  templates/index.html
  templates/page.html
  templates/page-<page>.html
  patterns/page-<page>.php
```

Important behavior:

- `style.css` contains the source linked local stylesheets, inline styles, and compatibility rules that preserve source button classes on `core/button` links.
- `functions.php` enqueues frontend styles, editor styles, and optional generated `assets/site.js`.
- `theme.json` extracts conservative color palette tokens from obvious `:root` CSS custom properties.
- Shared chrome is stored in `parts/header.html` and `parts/footer.html`.
- Page-specific layouts are stored in `patterns/page-*.php` and referenced from matching `templates/page-*.html` files.
- Imported WordPress page posts keep lightweight placeholder content so routing, titles, front-page assignment, and editor visibility stay native.

## Validation

The repository has both WordPress-side fixture coverage and generated-artifact validation.

### Full Validation Harness

Run the full local contract from the repository root:

```bash
npm install
npm run test:validation
```

The harness imports `tests/fixtures/wordpress-is-dead/` into the configured WordPress site, then runs the PHP smokes and the JavaScript block-validation smoke in dependency order.

By default it uses:

```text
studio wp --path /Users/chubes/Studio/intelligence-chubes4
```

Useful overrides:

```bash
STATIC_SITE_IMPORTER_SITE_PATH=/path/to/site npm run test:validation
STATIC_SITE_IMPORTER_WP_CLI="wp" npm run test:validation
npm run test:validation -- --skip-import /path/to/wp-content/themes/wordpress-is-dead
npm run test:validation -- --json
```

### PHP Smokes

PHP smokes run inside WordPress and load the plugin's bundled Block Format Bridge copy through the plugin runtime:

```bash
wp eval-file tests/smoke-admin-import-html-entry.php
wp eval-file tests/smoke-editor-style-support.php
wp eval-file tests/smoke-wordpress-is-dead-fixture.php
```

The `wordpress-is-dead` smoke verifies the multi-page fixture, generated block-theme artifacts, internal-link rewrites, persistent navigation entities, source CSS preservation, editor style support, conservative `theme.json` palette extraction, and selector fidelity across stored/rendered paths.

### PHPUnit Fixture Test

`tests/StaticSiteImporterFixtureTest.php` mirrors the `wordpress-is-dead` fixture contract in PHPUnit form for the Homeboy WordPress test runner and CI.

```bash
homeboy test static-site-importer --path /path/to/static-site-importer
```

The GitHub workflow runs `Extra-Chill/homeboy-action@v2` with the `test` command across PHP 8.1, 8.2, 8.3, and 8.4.

### JavaScript Block Validation

The generated-theme JavaScript smoke runs Gutenberg's parser and block validator against generated theme artifacts:

```bash
npm install
npm run test:js-block-validation -- /path/to/wp-content/themes/wordpress-is-dead
npm run test:js-block-validation -- --json /path/to/wp-content/themes/wordpress-is-dead
```

If no path is passed, the smoke uses `STATIC_SITE_IMPORTER_THEME_DIR`, then `WP_CONTENT_DIR/themes/wordpress-is-dead` when `WP_CONTENT_DIR` is set, then a local `wordpress-is-dead` directory under the repository root.

It validates `parts/header.html`, `parts/footer.html`, `patterns/*.php`, and `templates/*.html`, and reports invalid blocks with the file, nested block path, block name, validation reason, and failure summaries grouped by block name and file.

## Release Workflow

This repo is Homeboy-managed:

- `homeboy.json` declares the component ID, WordPress extension, version target in `static-site-importer.php`, and generated changelog target at `docs/CHANGELOG.md`.
- Do not edit `docs/CHANGELOG.md` manually. Homeboy owns changelog generation from commits at release time.
- Do not hand-bump plugin versions. Homeboy updates version targets during release.
- Use conventional commits so release notes and changelog entries are meaningful.

## Current Boundaries And Limitations

- The importer is intentionally static-site-to-block-theme glue. Block Format Bridge owns format conversion; HTML-to-block transform fidelity belongs upstream in BFB/h2bc.
- The importer currently discovers flat sibling `*.html` files beside the entry file; it does not crawl arbitrary nested routes.
- Admin imports accept pasted HTML, a direct `.html` / `.htm` file, or a ZIP with an `index.html`; CLI imports take a direct HTML file path.
- Linked local stylesheets and inline styles are copied into `style.css`; inline scripts are copied into `assets/site.js`. Other asset copying is not a general-purpose crawler yet.
- Navigation persistence is limited to supported header/footer shapes that can be converted into deterministic `wp_navigation` entities without guessing.
- External live triage has exercised additional static sites, but the committed first-party fixture is `tests/fixtures/wordpress-is-dead/`.

## Boundary

This plugin owns the static-site import workflow and generated WordPress artifacts. [Block Format Bridge](https://github.com/chubes4/block-format-bridge) owns content-format conversion.

Imported pages remain WordPress pages for routing, titles, front-page assignment, and editor visibility. Their imported body layouts live in generated block-theme artifacts: `patterns/page-*.php` plus matching `templates/page-*.html` files. The generic `templates/page.html` stays a `wp:post-content` fallback for pages created after import.

# Static Site Importer

Import a static HTML site into WordPress as a block theme using Block Format Bridge.

## MVP

- Adds `Appearance -> Import Static Site`.
- Accepts a ZIP containing `index.html`.
- Extracts inline CSS into `style.css`.
- Splits `nav`/`header`, main sections, and `footer` into block theme files.
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

## Boundary

This plugin owns static-site import workflow. Block Format Bridge owns format conversion.

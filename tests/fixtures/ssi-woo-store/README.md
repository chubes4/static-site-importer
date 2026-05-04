# SSI WooCommerce Store Fixture

This fixture is a static storefront used to validate Static Site Importer WooCommerce primitives before Studio consumes them.

The fixture is intentionally data-only:

- `products.json` declares the expected product manifest shape for the benchmark.
- `index.html`, `shop.html`, and `about.html` reference products by stable handles.
- `styles.css` provides local static styling and product card classes.

The importer owns this fixture because product manifest validation, WooCommerce product seeding, and product context forwarding are Static Site Importer behavior.

Tracked dependencies:

- https://github.com/chubes4/static-site-importer/issues/111
- https://github.com/chubes4/static-site-importer/issues/112
- https://github.com/chubes4/static-site-importer/issues/113

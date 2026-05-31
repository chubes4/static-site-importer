# Block Artifact Compiler

Block Artifact Compiler is the semantic compiler layer between generated website artifacts and WordPress materialization.

It is intentionally separate from Studio Web, Static Site Importer, Block Format Bridge, and HTML to Blocks Converter.

```text
Studio Web
  -> Static Site Importer
      -> Block Artifact Compiler
          -> Block Format Bridge
              -> HTML to Blocks Converter
```

## Responsibility

Input: a user-owned website artifact bundle.

Output: a WordPress-native artifact bundle.

The initial contract returns:

- serialized block markup
- parsed block placeholder field
- generated block type manifest placeholder
- generated file manifest placeholder
- diagnostics
- provenance
- optional BFB conversion report

Future compiler passes can add component detection and generated custom block artifacts without changing the caller boundary.

## Public API

```php
$result = bac_compile_website_artifact(
	array(
		'files' => array(
			array(
				'path'    => 'index.html',
				'content' => '<main><h1>Hello</h1></main>',
			),
		),
	)
);
```

Result shape:

```php
array(
	'schema'              => 'chubes4/block-artifact-compiler-result/v1',
	'status'              => 'success',
	'input'               => array(...),
	'wordpress_artifacts' => array(
		'block_markup' => '<!-- wp:paragraph -->...',
		'blocks'       => array(),
		'block_types'  => array(),
		'files'        => array(),
	),
	'provenance'          => array(...),
	'diagnostics'         => array(),
	'bfb_report'          => array(),
)
```

## Boundaries

Block Artifact Compiler does not orchestrate agents, import WordPress sites, or deploy outputs.

- Studio Web owns product orchestration, review, preview, and push flows.
- Static Site Importer owns WordPress import and materialization.
- Block Format Bridge owns format-to-block conversion APIs.
- HTML to Blocks Converter owns low-level HTML-to-known-block transforms.

## Smoke Test

```bash
composer test
```

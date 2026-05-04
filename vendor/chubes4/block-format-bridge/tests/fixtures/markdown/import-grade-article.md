# The Import-Grade Article

Long-form editorial content should survive Markdown conversion as readable WordPress blocks.

![Migration diagram](https://example.com/migration-diagram.png "Migration diagram")

## Why It Matters

Paragraphs can mix **bold decisions**, *editorial nuance*, and [traceable links](https://example.com/source) without dropping inline semantics.

> A converted article should preserve quotes as quotes, not opaque HTML.

- Preserve headings.
- Preserve media references.
- Preserve list structure.

## Implementation Notes

```php
bfb_convert( $markdown, 'markdown', 'blocks' );
```

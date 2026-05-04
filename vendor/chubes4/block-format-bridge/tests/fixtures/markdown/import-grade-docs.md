# Import Runbook

Use this checklist when validating a mixed-source import before writing content.

## Preflight

1. Confirm the source document has no frontmatter in the body handed to BFB.
2. Verify images are reachable before materialization.
3. Record any unsupported markup as upstream converter gaps.

## Fixture Matrix

| Source | Expected |
| ------ | -------- |
| HTML   | Native blocks |
| Markdown | Native blocks |

```js
const format = 'markdown';
```

> Frontmatter parsing belongs to the caller before conversion.

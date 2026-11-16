---
title: Module Cookbook
---

# Modules

Source of truth: `src/lib/modules.ts`.

## The closed set

```ts
export const MODULE_NAMES = ['image','video','embed','slide','note','details','more'] as const;
```

Seven names, pinned. Any other directive name is de-fanged to
`<p class="sm-unknown-module">` — never a live tag.

## Syntax

remark-directive: `:::name{key=value "label"}` — container, leaf or text
directive; body may be plain Markdown.

## The injection wall

```ts
export const MODULE_ATTRS = {
  image:   ['file','caption'],
  video:   ['url','file'],
  embed:   ['url'],
  slide:   ['title'],
  note:    ['kind'],
  details: ['summary'],
  more:    []
};
```

Any attribute outside this table never reaches the HTML — no `on*`, no
`style`, no `data-*`, no `srcdoc`. The allowlist is applied in the remark
phase (`stampDirectives`), before hast exists.

The editor's write-side validates submitted attributes against the **same**
wall, so an author is told the wall refuses a key instead of losing it
silently.

## One directive per module

```markdown
:::image{file="photo.jpg" caption="The bakery, 2026"}
:::

:::video{url="https://example.org/clip.mp4"}
:::

:::embed{url="https://example.org/embed"}
:::

:::slide{title="The proof"}
Slide body — markdown inside.
:::

:::note{kind="tip"}
Knead gently.
:::

:::details{summary="More"}
Folded body.
:::

:::more
:::
```

## Notes per module

- **image** — `file` is a page-local filename or an http(s) URL; local paths must match `^[\w./-]+$`. No file → a visible, fillable empty slot (`figure.sm-empty-slot`). A caption wraps the image in `figcaption`.
- **video** — `url` (http(s) only) or `file`; one of the two must yield a source. Renders `figure.sm-video > video[controls]`.
- **embed** — `url` http(s) only → `iframe` inside `figure.sm-embed`.
- **slide** — `title` becomes the anchor id (lowercase, non-alphanumerics → one hyphen); duplicates get `-2`, `-3`. Children pass through untouched.
- **note** — `kind="tip"` or `kind="warn"` adds `sm-note-tip` / `sm-note-warn`; anything else is the plain `aside.sm-note`.
- **details** — `summary` labels the disclosure; falls back to the directive label, then `More`. Native `details`/`summary`, zero JS.
- **more** — the teaser fold; renders as `hr.sm-more`. No attributes allowed.

## The URL gate

`safeUrls()` (rehype phase) sweeps every `href`/`src`/`poster` afterwards:
`http(s)`, `mailto`, `tel` and site-relative paths pass; dangerous schemes
collapse to `#` (links) or lose the attribute (media).

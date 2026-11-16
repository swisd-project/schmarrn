---
title: Theme Contract
---

# The Theme Contract

What a theme may assume, what it receives, what it must never do.
Full book: `docs/theme-contract.md`. This page: the bakery, as baked.

## The layout (bakery)

`bridge/templates/bakery/page.html` — one unified sidebar layout, worn by
every page. The sidebar (left, full height):

1. brand — `{{logoHtml}}` + `{{siteName}}`
2. search + filter — theme furniture
3. nav — `{{navHtml}}` (the page entries)
4. footer — `© {{year}} {{siteName}}`, pinned to the bottom

Under 60rem the sidebar stacks to a top band; content flows under it.

## Placeholders the layout fills

`{{navHtml}}` `{{bodyHtml}}` `{{themeBase}}` `{{siteName}}` `{{claim}}`
`{{claimHtml}}` `{{logoUrl}}` `{{logoHtml}}` `{{pageTitle}}` `{{seoHead}}`
`{{year}}`

Filled by `fill()` in `bridge/serve.php`; the bake fills the same names from
`docs/content/` (`scripts/bake-docs.ts`). All escaped except `{{bodyHtml}}`
and `{{seoHead}}`, which core generates. A template carries no styles — it
only links the one stylesheet.

## Assets

- `<link rel="stylesheet" href="{{themeBase}}assets/theme.css">` — the ONE stylesheet
- `<script src="{{themeBase}}assets/menu.js" defer></script>`
- `/bridge/assets/noto/noto.css` — the fonts

In the bake `{{themeBase}}` is `''` (assets sit next to the page) and the noto
link is repointed to `assets/noto/noto.css`; `theme.css`, `menu.js` and the
noto folder are copied into `docs/build/assets/`.

## Search and filter

- **Search** — client-side over the **rendered nav** (`.sm-nav` entries). No fetch, no core route, works for a stranger.
- **Filter** — a bare scaffold: the `.sm-filter` select has no tag data door yet (no core tags endpoint). It stands honest and does nothing.

## The class namespace

`sm-` is shared between core, the render pipeline, the editor and themes.
Core emits `sm-nav`, `sm-fallback`, `sm-notfound`, `claim`, `logo`; the
pipeline emits `sm-slide`, `sm-module`, `sm-details`, `sm-embed`,
`sm-image`, `sm-empty-slot`, `sm-video`, `sm-note`, `sm-note-tip`,
`sm-note-warn`, `sm-more`, `sm-unknown-module`. Themes extend the contract
here — they emit no `sm-` classes of their own.

## What a theme must never do

- Assume DOM timing (some doors deliver the body asynchronously)
- Style or depend on editor-internal classes (`sm-edit-*`, `data-sm-*`)
- Carry styles in the markup — the one stylesheet holds them
- Emit `sm-` classes of its own
- Parse content HTML structurally — style it

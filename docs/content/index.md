---
title: Schmarrn Docs
---

# Schmarrn

A tiny Markdown CMS. Pages are plain `index.md` files in a content tree; the
PHP core stores and serves them and **never parses Markdown**
(`bridge/store.php:6`). One renderer — mdms / `renderMdms` (`src/lib/render.ts`) —
turns Markdown into HTML, for the live site and for these pages alike.

## These pages are the bakery, baked

Same theme as the live CMS — the bakery layout
(`bridge/templates/bakery/page.html`) — but **baked to static HTML** for
GitHub Pages.

Not live: GitHub Pages runs no PHP, so no `bridge/serve.php`, no session, no
editor. The bake (`scripts/bake-docs.ts`, `npm run build:docs`) renders
`docs/content/*.md` through `renderMdms`, fills the bakery template with a
docs nav, copies `theme.css`, `menu.js` and the noto fonts into
`docs/build/assets/`, and writes one `.html` per page.

The CMS content tree (`src/content/`) is untouched by this bake.

## The docs

Links point at the baked `.html` siblings — the bake emits HTML, so `.md`
targets would 404 on Pages. Sources: `module-cookbook.md`, `theme-contract.md`,
`api-shape.md` in `docs/content/`.

- [Module Cookbook](module-cookbook.html) — `module-cookbook.md` — the closed module set and its injection wall
- [Theme Contract](theme-contract.html) — `theme-contract.md` — what a theme may assume
- [API Shape](api-shape.html) — `api-shape.md` — the API doors and their refusals

## Why static

The docs are read far more than they are written; a static bake needs no
server, no PHP, no database. The live CMS keeps the editing; the bake keeps
the reading.

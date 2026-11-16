---
title: API Shape
---

# API Shape

The doors under `/bridge/`. Pinned by the test battery: `tests/snapshot.php`
→ `urls()` is the exact door list.

Pages come from the CMS store (`bridge/store.php`, `src/content/<type>/<slug>/index.md`).

## The doors

| Door | Method | Answers |
|---|---|---|
| `/bridge/pages` | GET | The page index — `{type: [{slug, updatedAt, draft, hasDraft, tags, title, home?}]}`. Drafts filtered for strangers, full tree for editors. |
| `/bridge/page/:type/:slug` | GET | The page's **raw Markdown** (not JSON; `Content-Type: text/markdown`). `X-Cache: HIT`/`MISS`. |
| `/bridge/public/:type/:slug` | GET | Public JSON per page: `type`, `slug`, `title`, `bodyHtml` (null when the cache is stale), `children` (siblings), `files` (page-local media). Drafts never ride along. |
| `/bridge/search?q=` | GET | Authed text search over the editorial copies; same tree shape as the index. |
| `/bridge/session` | GET | `authenticated`, `user`, `role`, `csrf`. |
| `/bridge/templates` | GET | Installed themes with their manifest face: `name`, `title`, `version`, `description`, `styles`, `defaultStyle`, `default`, `active`, `preview`. Admin-only. |
| `/bridge/plugins` | GET | Installed plugins with state. Admin-only. |
| `/bridge/drawer` | GET | `{handles: [...]}` — the admin drawer's retail band (plugin handles + theme handles). Any authed session. |
| `/bridge/editor-blocks` | GET | The canvas insertables of every **enabled** plugin. Authed. |
| `/bridge/pagetypes` | GET | The fillable scaffolds from `pagetypes/`: `name`, `title`, `body`. Authed. |
| `/bridge/core` | GET | The core's self-update verdict: `current`, `latest`, `dashboard`, `updatable`, `error`, `localVersion`, `releaseName`, `published`, `contributors`. Admin-only. |

## The refusals

Statuses below are the pinned ones (`tests/fixtures/baseline.json`).

| Path | Status |
|---|---|
| `/bridge/page/pages/no-such-page`, `/bridge/public/pages/no-such-page` | 404 |
| `/bridge/no-such-route` | 404 |
| `/bridge/page/pages/NOT_A_SLUG`, `/bridge/page/NOT_A_TYPE/hello` | 404 — an uppercase identifier misses the route pattern; the store's own lid (`Invalid slug/type`, `bridge/store.php`) answers 400 only when it is reached |
| `/bridge/search?q=x` | 401 |
| `/bridge/templates`, `/bridge/plugins`, `/bridge/drawer`, `/bridge/editor-blocks`, `/bridge/pagetypes`, `/bridge/core` | 401 (anonymous) |
| `/bridge/plugin/<name>/<route>` | 404 — unknown plugin or undeclared route |
| `/bridge/media/pages/hello` | 401 |

Errors answer `{"error": "…"}` (`Router\refuse`).

## The wall

The API is the ware's boundary: reads are open where they are safe
(`/bridge/pages`, `/bridge/page/…`, `/bridge/public/…`, `/bridge/session`),
and everything editorial or administrative — search, drawer, editor blocks,
page types, templates, plugins, core — is closed behind a session, admin
where the inventory demands it. The PHP core still never parses Markdown;
the API serves bytes, or rendered HTML from the cache.

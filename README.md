# Schmarrn

A flat-file, headless-ish CMS you dump onto any FTP host and it just runs. No database, no Node on the server, no build step on the host.

- **PHP core** (`api/`) — serves a strict JSON API: raw `.md` read/write, auth, file-based cache.
- **Svelte dashboard** (`src/`) — SvelteKit + `adapter-static` editor; preview and public rendering share one `parseMdms()` call.
- **Content** (`src/content/`) — one folder per page/post, `index.md` with strictly validated frontmatter.
- **Parser** — [`@swisd-project/mdms`](https://github.com/swisd-project/mdms), the sole Markdown→HTML transformer.

## Layout

```
api/        PHP core (front controller + /api endpoints)
src/        SvelteKit dashboard + content tree
MCP/        GOSPEL (law), BACKLOG (ledger), GUIDE (kinks)
```

## Dev

```sh
php -S localhost:8080 -t api          # API
cd src && npm run dev                 # dashboard
```

## Deploy

Upload `api/`, the built dashboard assets and `src/content/` to the FTP host. The only writable directory is `api/cache/`.

SPDX-License-Identifier: MPL-2.0 · Copyright (c) 2026 Homahuki GmbH

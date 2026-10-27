<!--
1. Relative path: MCP/GOSPEL.md
2. Description: The unbreakable doctrine for Schmarrn — rules, environment, and architecture invariants.
3. Expects: Nothing. It is the law.
4. Provides: Locked decisions, structure, and environment for the Schmarrn flat-file CMS.
5. SPDX-License-Identifier: MPL-2.0
6. Copyright (c) 2026 Homahuki GmbH
-->

SCHMARRN GOSPEL

The flat-file CMS that you just dump onto any FTP server and it runs. No database, no Node on the server, no build step on the host.

Locked Decisions (Reference)

- Name: Schmarrn (one "m" in Schmarrn, exactly two "r"s — "Schmarrrn" with three is the pancake you eat, not the project you ship).
- Namespace: `@swisd-project/*` on npm (e.g. shared types). Core stays plain PHP.
- Architecture: PHP core (`api/`) serves a strict JSON API under `/api` — fetch raw `.md`, save, list, auth, file-based HTML cache. No Markdown parsing in PHP.
- Parsing core: `mdms` (`@swisd-project/mdms`) is the sole Markdown→HTML transformer. One renderer everywhere; no `marked`, no `gray-matter` duplicates.
- Content store: `/src/content/<type>/<slug>/index.md` with YAML frontmatter, validated by one strict Zod schema per content type. Slug-vs-filename mismatch = hard error.
- Admin dashboard: Svelte(5)/SvelteKit built with `adapter-static`; the built assets are committed/served by the PHP side as static files. Editor preview = the exact same `parseMdms()` call the public site uses.
- Trust boundary: `mdms` outputs raw HTML. Admin-authored content is trusted; anything untrusted MUST be sanitized (DOMPurify) before injection. Guardrail lives in the API layer, day one.
- Domain splitting: `previewHtml`/`fullHtml` is a blog feature, not a page-model feature.
- No CRDT.md: this project has no distributed math. Files on disk, one editor at a time, coarse last-write-wins.
- MCP ledger discipline: BACKLOG.md = do-not-forget ledger; FILETREE.md = sanity check; GUIDE.md = kinks and necessities developed on the go; GOSPEL.md = this file. All decisions go through MCP entries before code.

Environment

- Local dev: PHP 8.5 built-in server (`php -S`) for `api/`, Vite dev server for `dashboard/`.
- Deploy: FTP upload of one folder (api + built dashboard + content). Writable dir needed only for the cache.
- Requirements: PHP 8.4+ on deploy (dev on 8.5 — 8.2's security support ends Dec 2026, don't build on it), gd+zip only if we support image resizing. No composer, no database, no cron.

Folders

- `api/`     — PHP core: `/api` JSON endpoints, auth, file cache, content store access.
- `src/`     — SvelteKit dashboard (source); builds to static assets consumed by `api/`.
- `src/content/` — the canonical `.md` content tree, one folder per page/post.
- `MCP/`     — governance files (GOSPEL, BACKLOG, GUIDE, FILETREE).

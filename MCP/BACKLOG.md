<!--
1. Relative path: MCP/BACKLOG.md
2. Description: The "don't you dare forget" ledger for Schmarrn.
3. Expects: Continuous updates as milestones are conquered and new layers defined.
4. Provides: Project state, locked decisions, and roadmap.
5. SPDX-License-Identifier: MPL-2.0
6. Copyright (c) 2026 Homahuki GmbH
-->

Schmarrn BACKLOG
The "don't you dare forget" ledger. One project, one app: PHP `api/` + SvelteKit `dashboard/`.

🔒 Locked Decisions (Reference)
See GOSPEL.md — all locked doctrine lives there. Backlog references, does not restate.

SECTION 0 — SCAFFOLD (do first)
---
P0 — PHP core
[x] `/api` router skeleton (front controller, strict JSON out, CORS for dashboard). (`api/index.php`, `api/router.php`)
[x] Content store: read/list `src/content/**/index.md`, slug validation, frontmatter sanity. (`api/store.php` — slug/type regexes, atomic temp+rename writes)
[x] Auth: single password → session (Argon2id template hash in `api/config/auth.php`, real hash in git-ignored `auth.local.php`), 24h lifetime. TOTP optional later.
[x] Endpoints: `GET /api/pages`, `GET /api/page/<type>/<slug>` (raw `.md`), `PUT /api/page/<type>/<slug>`, login/logout/session. (`api/index.php`)
[x] File cache: raw-body cache keyed by mtime sentinel, dropped on write. (`api/cache.php`)
[ ] Smoke test with PHP 8.4+ — ~~no PHP binary on this dev machine~~ **DONE 2026-10-27 (PHP 8.5.12):** login/session/pages/raw-GET/cache-HIT verified via curl. 3 defects fixed: `self::` calls in plain namespace functions (fatal), `\SLUG_PATTERN` root-lookup (undefined const), list-destructuring of assoc-array `Cache\files()`. Note: PHP `password_verify` rejects the Node-`argon2` lib's PHC variant — generate admin hashes with PHP itself.
[ ] Draft/public gating: ~~schema flags~~ **DONE:** PHP regex-sniffs `draft: true` (no Markdown parsing); anon `/api/pages` excludes drafts + raw draft GET = 404; authed sees all with `draft` badge data.

P1 — SvelteKit dashboard
[x] `adapter-static` scaffold (fallback SPA), Svelte 5 runes, svelte-check clean. Builds: `npm run build` → `build/`.
[ ] Tailwind (currently hand-rolled tokens in `src/lib/app.css`).
[x] Login view → PHP session. (`src/routes/+page.svelte`)
[x] Page tree browser over `/api/pages` — clickable, draft badges, refresh on save.
[x] Editor: raw-`.md` textarea + LIVE preview via `parseMdms()` with per-type Zod gate (`src/lib/components/PageEditor.svelte`). One renderer, by law.
[x] Save flow: raw `.md` via `PUT` (API atomic-writes + drops cache — verified roundtrip).

P2 — Public serving strategy
[ ] Decide: prerendered adapter-static public site vs. PHP routed cached HTML (Recipe A) — lock in GOSPEL when decided.
[ ] `mdms` bumped with `hasMore` flag; wire preview/full split for blog type.
P3 — Nice-to-haves
[ ] Image handling (upload, resize via gd) — only if needed.
[ ] TOTP 2FA, multilingual pages, aliases.

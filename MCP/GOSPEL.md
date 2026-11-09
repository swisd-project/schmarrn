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
- Architecture: PHP core (`bridge/`) serves a strict JSON API under `/bridge` — fetch raw `.md`, save, list, auth, file-based HTML cache. No Markdown parsing in PHP.
- Parsing core: `mdms` (`@swisd-project/mdms`) is the sole Markdown→HTML transformer. One renderer everywhere; no `marked`, no `gray-matter` duplicates.
- Content store: `/src/content/<type>/<slug>/index.md` with YAML frontmatter, validated by one strict Zod schema per content type. Slug-vs-filename mismatch = hard error.
- Admin dashboard: Svelte(5)/SvelteKit built with `adapter-static`; the built assets are committed/served by the PHP side as static files. Editing model: full-page visual editor — the public page IS the canvas (Automad-style click-to-edit); complex items are edited via save-confirm modals; no two-column textarea|preview layout.
- Trust boundary: `mdms` outputs raw HTML. Admin-authored content is trusted; anything untrusted MUST be sanitized (DOMPurify) before injection. Guardrail lives in the API layer, day one.
- Domain splitting (previewHtml/fullHtml): superseded by the module model — any preview/full split is decided per module spec, not page-model-wide.
- No CRDT.md: this project has no distributed math. Files on disk, one editor at a time, coarse last-write-wins.
- Multi-editor: named accounts (`admin`, `editor` roles), Argon2id hashes in a file-backed account store (git-ignored real file), server-side session records in the writable dir, CSRF tokens, login rate-limiting via the file cache. Plain FTP hosting is a supported target — cookies/sessions work under PHP+Apache.
- Mail: SMTP for invites/password-resets via one bundled "shoulders of giants" PHP mail library (e.g. PHPMailer vendored into the repo — no composer invocation). Deliberate bend of the no-third-party posture; SMTP credentials are admin config.
- Modules: Markdown stays the source of truth. A small closed set of preset module boundary tags (text, image, image+caption, video/html embed) reference page-local structured data; media references are by ID to immutable uploaded assets. mdms is extended to render modules — one parser law still holds everywhere.
- Module data location (frontmatter vs sidecar JSON): DECIDED ON THE GO — pinned in the module spec as work reveals it.
- Themes are installable without touching the codebase: theme contract = manifest + assets/templates; enabled from the dashboard. Global brand settings (logo, claim, name) are referencable across pages.
- Public JSON API: read-only, per page (title, body HTML, children, files) — Schmarrn doubles as a quick-deploy backend. CORS scoped for known consumers.
- Serving: clean URLs via front controller + `.htaccess`. Editors' browsers render HTML at save time; PHP stores/serves/caches cached HTML per page (per-page hash file + site-mtime sentinel, Automad pattern). SEO/link previews are requirements.
- Requirements phasing: `gd` + `zip` are v2 requirements (image processing, packaging). v1 uploads store originals untouched. PHP 8.4+ still stands for everything.
- MCP ledger discipline: BACKLOG.md = do-not-forget ledger; FILETREE.md = sanity check; GUIDE.md = kinks and necessities developed on the go; GOSPEL.md = this file. All decisions go through MCP entries before code.

Environment

- Local dev: PHP 8.5 built-in server (`php -S`) alone on `:8080` — it serves the public site, the API, the live editor AND the dashboard (`/_`, from `build/`). Vite dev is opt-in (`dev:app`) for dashboard-UI HMR only (2026-11-04, user call: "two servers is confusing").
- Deploy: FTP upload of one folder (api + built dashboard + content). Writable dirs needed for: cache, per-host sessions, account/file-backed store, uploads (per-host reality, all under the install).
- Requirements: PHP 8.4+ on deploy (dev on 8.5 — 8.2's security support ends Dec 2026, don't build on it). gd+zip enter only in v2 (see Locked Decisions). No composer, no database, no cron.
- The PORT LAW (2026-11-07, user-mandated after a live dev session was killed under them): a running session is SACRED. Never kill a process on a port the agent did not start — `fuser -k` against a foreign port is forbidden; if a port must move and it isn't ours, ASK. A new dev server auto-steps to the next free port instead: `npm run dev` → `scripts/dev-serve.sh [start-port]` (default 8080), which KNOCKS (a /dev/tcp TCP probe — harmless) and never kicks.

Releases & Versioning (2026-11-06, user law)

- ASK BEFORE ANY PUSH whether the work ships as a new release — pushing is never silently bundled with releasing. (The ask is answered in advance when the instruction itself carries the publish word — see the release law below.)
- When asking, STATE THE CURRENT VERSION (read `bridge/CORE-VERSION`, cross-check `package.json`) and ASK HOW TO INCREMENT (major/minor/patch) — the agent never picks the number alone.
- A go for push means commit + tag + push AND the release steps (bump, tag, asset zip) ONLY when the user granted release permission; a push-go alone stays a push-go. One permission never implies the other.
- The release asset: GitHub's zipball is source-only (`build/` is git-ignored), so a release moves the dashboard ONLY when it wears the asset zip — `npm run build && scripts/ship-release.sh vX.Y.Z`, then attach `schmarrn-<version>.zip` to the release on GitHub (the maintainer's hand — or the agent's when the instruction carries the publish word; the release law below rules). The core self-update prefers the release's zip asset and falls back to the codeload zipball (code without the face).
- A TAG IS CUT ONLY TOGETHER WITH ITS RELEASE (2026-11-07, the v1.2.5 lesson): the version floor must never outrun the newest release — an unreleased tag made a rig claim a version GitHub couldn't serve, and the updater's world broke. The updater follows the RELEASES list (semver max, `Core\announce()`); the tag list answers only when no release exists at all (pre-release era fallback).
- THE VERSION SCHEME IS NUMERIC ONLY (2026-11-09, user order after the v1.2.9a wound): no letter suffixes, ever — a post-release revision of 1.2.9 is `1.2.9.1`, never `1.2.9a`. PHP's `version_compare` reads a trailing letter as an alpha PRE-release (`1.2.9a < 1.2.9`), which made the suffixed release invisible to `announce()` AND let a 1.2.9a host pass the strict-newer gate and DERATE to 1.2.9 (hit for real on the dev rig, 2026-11-09). Numeric sub-versions pass the existing regex and compare correctly with zero code.
- THE HALF-RIDE BAN (2026-11-07, enforced in `Core\update()` after the v1.2.7→v1.2.8 dashboard-less ride proved a law without a lid is decoration): a packet that carries NO dashboard is REFUSED before the knife leaves the drawer — no blanko, no swap, no half-state, from hub AND local alike. The floor does not move without the face; the repair is the release's asset zip, attached or parked. A release page without its asset simply cannot be ridden — that is the point.
- DO NOT TOUCH THE RELEASES (2026-11-07, user order — absolute; AMENDED 2026-11-07, user order "amend the release law — don't assume any version, simply ASK ME"): no release, no tag, no version bump, no version-bearing push in ANY repo — Schmarrn, the Vitrine, the wares registry, anything — without an explicit, current, per-action instruction. The ask must NAME the exact version and wait for a yes; a tool-recorded approval from earlier in a session does not carry forward. THE VERSION LAW (user order, emphatic): the agent NEVER assumes, derives, or hallucinates a version — not from memory, not from a session summary, not from next-in-line logic, not ever. A version-bearing order without an explicit version gets ONE question and a wait. Every version the agent writes is the user's word read verbatim, or the disk's own (both bearers cross-checked: tag ↔ `bridge/CORE-VERSION` ↔ `package.json` — never just one, never from recall). THE PUBLISH WORD: an instruction that names a version AND carries "publish" (e.g. "push core 1.2.10 and publish") also does the release steps — the agent creates the GitHub release page for the named tag and attaches the staged asset zip. The publish word is per-instruction only and never carries forward. Without it, the release page and the asset upload are the user's hand alone. Version events are the user's sovereignty; the agent stages, asks, and waits.

The Admin Drawer & /_ Mode (2026-11-07, user-designed — the sidebar quest's verdict)

- Admin mode is URL law: a live page under the /_ prefix (`/_/<type>/<slug>`, `/_/<slug>`) carries the visual editor; the plain URL is the public face for everyone, authed or not. A visitor under /_ bounces to the plain URL (302). `/_` and `/_/` stay the dashboard's ground.
- Two cues tell the admin they are in admin mode: the URL itself, and the drawer button (the right-edge triangle).
- After login and after first-visit setup the user lands on the LANDING PAGE in admin mode — `/_/<home>` (site home slug, else first published page; no pages → the dashboard).
- The admin drawer (live page, right edge, full height): tab links on top (admins get all five, editors Contents), Log out at the bottom, and between them the RETAIL BAND: quality-of-life edit handles placed by enabled plugins (`plugin.json` `"drawer": [{label, href}]`, lid-validated in `Plugins\validate()`) and the active theme (`theme.json` `"drawer"`, root-relative hrefs only). Served by `GET /bridge/drawer` (any authed hand); the door re-measures when the band lands; fail-silent — a dark shelf is bare, never broken.
- The admin overlay is chrome-less by law: the tab bar on top and the close X at the right edge (the triangle's spot on the live page). Log out lives in the drawer only.

Folders

- `bridge/`   — PHP core: JSON core under `/bridge`, auth, file cache, content store access. The core's dedicated homes: `bridge/templates/` (theme contract wares) and `bridge/plugins/` (plugin homes, manifest + code, core-lid enforced). Rename 2026-11-03, user call: bots bombard `/api`. Plugin capability contract (p6): slice 1 = template placeholders + API routes; slice 2 (2026-11-04, user-approved in session) = serve-time `contentFilters` over stored bodyHtml — manifest-declared namespaced keys, applied in `Serve\fill()` after the save-time render, broken filter = log line + untouched HTML (fails open, never a dead page). Slice 3 (2026-11-05, user-ordered) = Vitrine fetch + install (`bridge/wares.php`): registry wares.json (one GET, TTL-cached) + one codeload packet zip of the branch — ONLY the ware's `<path>/` is unpacked (ZipArchive, PharData fallback) into `bridge/plugins|templates/<name>`; discernment rules at the lid (name/manifest match, requiresSchmarrn floor = `Wares\HOST_VERSION` = the repo's tagged version, overwrite only strictly-newer versions, disable-first upgrades with rollback); installed-disabled arrival, enable stays the user's hand; `GET /bridge/wares` + `POST /bridge/wares/install` are admin+CSRF; URLs overridable in git-ignored `config/wares.local.php` (local test shelf). Wares may declare `"requires": [plugin-names]` in the registry — the lid refuses an install whose deps are not on the host (409, the shelf shows the gap + locks the button), the ENABLE lid honours the same law (`PUT /bridge/plugins` refuses enabling a plugin whose deps are not ENABLED too — the UI pre-checks and shows the same refusal), and every successful install is recorded in git-ignored `config/wares.lock.php` (provenance: version, requires, installedAt). Slice 4 (2026-11-05): editorBlocks are WIRED — capable manifests may declare {name,label,template} blocks (namespaced with the plugin's underscored prefix law), served to any authed session via `GET /bridge/editor-blocks` (enabled plugins only); the canvas Add menu gains a "Plugins:" row and inserts the template through the pre-filled text modal.
- `src/`     — SvelteKit dashboard (source); builds to static assets consumed by the core.
- `src/content/` — the canonical `.md` content tree, one folder per page/post.
- `MCP/`     — governance files (GOSPEL, BACKLOG, GUIDE, FILETREE).

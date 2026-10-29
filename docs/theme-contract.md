# The Theme Contract

What a theme may assume, what it will receive, and what it must never do.
Every claim here is enforced by code — the enforcing file is named next to it.
If code and this document disagree, one of them is a bug: fix both or neither.

## The three serving doors

`api/serve.php` `page()` serves every page through one of three doors. A theme
must look right and behave right through **all three**:

| Door | Who | Body content | Slides at theme-JS execution |
|---|---|---|---|
| Authed | logged-in admin/editor | empty `[data-sm-edit-root]` + `edit.esm.js` (renders async) | **not yet** |
| Fresh cache | anonymous | cached rendered HTML (static) | **yes** |
| Fallback shell | anonymous, cache stale/missing | `div.sm-fallback` + `render.esm.js` (renders async) | **not yet** |

**Readiness law for theme JavaScript:** never assume your markup exists when
your script runs. Two of three doors deliver the body *asynchronously*. Scan
immediately *and* watch (`MutationObserver`) — see `themes/*/assets/deck.js`
for the reference implementation (wait, wire, rewire on node replacement,
teardown via `AbortController`).

The cache holds the **body only**; the theme wrap happens at serve time, so
theme and style switches apply to cached pages without a re-publish.

## Templates

- `page.html` — required. The classical template.
- `slide.html` — optional. Used when a page's style resolves to `slide`;
  falls back to `page.html` when absent (`themeTemplate()` in `api/serve.php`).
  A slide template **must** wrap the body in an element with class
  `slide-stage` — the deck companion keys on it (`themes/*/assets/deck.js`
  scans for `.slide-stage > .sm-slide`), so a slide template without the
  stage silently has no deck.
- If the configured theme directory is missing or broken, core serves a
  built-in house template — a broken theme degrades, never a dead site.
- Templates are filled by `fill()` (`api/serve.php`) — the single choke point
  for every serving path (page, shell, 404).

## Placeholders

Filled by `str_replace` in `fill()`; all HTML-escaped except `{{bodyHtml}}`
and `{{seoHead}}`, which core generates:

`{{siteName}}` `{{claim}}` `{{claimHtml}}` `{{logoUrl}}` `{{logoHtml}}`
`{{pageTitle}}` `{{bodyHtml}}` `{{seoHead}}` `{{navHtml}}` `{{themeBase}}`
`{{year}}`

Plugins may add placeholders, namespaced `<name>_…`, and only manifest-declared
keys are honored (`Plugins\placeholderFills`). Unknown placeholders pass
through untouched — a typo is visible text, not a crash.

## The class namespace

`sm-` is the shared namespace between core, the render pipeline, the editor,
and themes. Ownership:

- **Emitted by core serving** (`api/serve.php`): `sm-nav`, `sm-fallback`,
  `sm-notfound`, `claim`, `logo`.
- **Emitted by the render pipeline** (`src/lib/modules.ts`): `sm-slide`,
  `sm-module`, `sm-details`, `sm-embed`, `sm-image`, `sm-empty-slot`,
  `sm-video`, `sm-note`, `sm-note-tip`, `sm-note-warn`, `sm-more`,
  `sm-unknown-module`, plus per-slide `aria-label`/`id` (the deck's dots read
  them). The `.slide-stage` wrapper around them is the **slide template's**
  job, not the pipeline's.
- **Emitted by deck.js** (theme asset): `sm-dots`, `sm-dot`, `active`.
- **Editor-internal** (`src/public/edit.ts`, ships its own CSS): `sm-edit-*`,
  `sm-float*`, `sm-btn*`, `sm-field`, `sm-input`, `sm-alert*`, `sm-toast`,
  `data-sm-*`. Themes must not style these and must not depend on them.

The full styled list lives in each theme's CSS; when core adds a class, this
document and the themes move together.

## Manifest (`theme.json`)

Core reads exactly one field today: `defaultStyle` (`"slide"` or `"classical"`,
read by `styleOf()`). A `theme.json` file must exist for the theme to be
offered at setup and accepted in settings (`api/index.php`).

## Assets

- Served from `{{themeBase}}assets/…`, whitelisted as real files in
  `.htaccess`, cached `max-age=3600, must-revalidate`. Assets are **not**
  content-hashed — a theme asset change goes live within the hour, and a
  hard reload settles it sooner.
- `deck.js` is a **per-theme** asset: themes own what they create. Two themes
  may ship byte-identical twins today and diverge tomorrow. Core never
  centralizes theme behavior without an explicit decision.

## The style enum

`classical` and `slide` are the only styles. Their homes, all of which must
move together when a style is added:

1. `styleOf()` regex — `api/serve.php` (frontmatter `style:` byte-peek)
2. Site setting validation — `api/site.php` (PUT rejects anything else)
3. Template filenames — `slide.html` / `page.html`
4. Manifest `defaultStyle` — `theme.json`
5. Editor selects — `STYLE_OPTIONS` in `src/public/edit.ts` (single home
   inside the editor; it feeds both the page style and the global style)

Precedence in `styleOf()`: page frontmatter → site global setting → manifest
default → `classical`.

## What a theme must never do

- Assume DOM timing (the readiness law above).
- Style or depend on editor-internal classes.
- Emit `sm-` classes of its own — extend the contract here instead, so the
  namespace stays collision-free.
- Parse content HTML structurally; style it. Structure is the pipeline's job.

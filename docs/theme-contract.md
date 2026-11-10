# The Theme Contract

What a theme may assume, what it will receive, and what it must never do.
Every claim here is enforced by code — the enforcing file is named next to it.
If code and this document disagree, one of them is a bug: fix both or neither.

## The three serving doors

`bridge/serve.php` `page()` serves every page through one of three doors. A theme
must look right and behave right through **all three**:

| Door | Who | Body content | Slides at theme-JS execution |
|---|---|---|---|
| Authed | logged-in admin/editor **under the `/_` prefix** (admin mode, 2026-11-07) | empty `[data-sm-edit-root]` + `edit.esm.js` (renders async) | **not yet** |
| Fresh cache | anonymous — and authed users on plain URLs (the public face for everyone) | cached rendered HTML (static) | **yes** |
| Fallback shell | anonymous, cache stale/missing | `div.sm-fallback` + `render.esm.js` (renders async) | **not yet** |

**Readiness law for theme JavaScript:** never assume your markup exists when
your script runs. Two of three doors deliver the body *asynchronously*. Scan
immediately *and* watch (`MutationObserver`) — see `bridge/templates/*/assets/deck.js`
for the reference implementation (wait, wire, rewire on node replacement,
teardown via `AbortController`).

The cache holds the **body only**; the theme wrap happens at serve time, so
theme and style switches apply to cached pages without a re-publish.

## Templates

- `page.html` — required. The base floor — a declared style without its own file renders here.
- `blanko.md` — optional (2026-11-07, sidequest law). The theme's blanko hello-world page: when a site is born (setup) or a template is activated onto an **empty** tree, core seeds it as the published `pages/index` — the index IS the landing, and the front door never opens onto a 404. Core never surprise-plants a site that has pages.
- `slide.html` — optional. Used when a page's style resolves to `slide`;
  falls back to `page.html` when absent (`themeTemplate()` in `bridge/serve.php`).
  A slide template **must** wrap the body in an element with class
  `slide-stage` — the deck companion keys on it (`bridge/templates/*/assets/deck.js`
  scans for `.slide-stage > .sm-slide`), so a slide template without the
  stage silently has no deck.
- A template carries NO styles: it only LINKS the theme's one stylesheet
  (the blueprint law — see Assets below).
- If the configured theme directory is missing or broken, core serves a
  built-in house template — a broken theme degrades, never a dead site.
- Templates are filled by `fill()` (`bridge/serve.php`) — the single choke point
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

- **Emitted by core serving** (`bridge/serve.php`): `sm-nav`, `sm-fallback`,
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

Core reads these fields today: `defaultStyle` (the theme's own floor — honored
only when the theme's `styles` list names it, else `''`, the base; read by
`styleOf()`), `drawer` — an array of `{label, href}` quality-of-life
edit handles the admin drawer's retail band carries (2026-11-07; `href` must be
root-relative (`/…` — a theme lives on this site); labels trim to 60 chars,
eight handles max; served to any authed session via `GET /bridge/drawer`
alongside the plugins' handles — `plugin.json` `"drawer"`, same shape, absolute
http(s) hrefs allowed there) — `requires` (the Chain Law, 2026-11-07: an
array of `"name"` / `"name >=1.2"` entries judged against EFFECTIVE plugins at
template activation — a theme whose dependencies sleep cannot dress the site;
409 names the unmet) — and `handles` (the style knobs, below — a different
animal from the drawer's edit handles). A theme must exist with a `theme.json`
for the theme to be offered at setup and accepted in settings (`bridge/index.php`).

`handles` (2026-11-10, user order) is the THEME's word: the theme declares the
CSS custom properties it offers the admin, the core only validates them and
carries the values. Each entry is `{var, label, type, default}` — `var` is a
custom property (`--[a-z0-9-]{1,40}`), `type` is `color` or `text`, `default`
is the value the Settings tab shows as the reset target. A declaration that
fails the lid does not exist at all (`themeHandles()` in `bridge/serve.php`).

- The VALUE lid (`validHandleValue()`, `bridge/serve.php`): a color is a hex,
  an `rgb()`/`hsl()`, or a named colour; a text is a short safe charset. The
  value lands in a `<style>` block on the public face — nothing that could
  close that block survives the door. An invalid value is refused with 400.
- The manifest's `default` MUST equal the handle's value in the theme's
  stylesheet `:root` — the Settings tab shows the manifest default as the
  reset target, and the two disagreeing means the reset lies.
- The values live per theme in `bridge/config/site.local.php` (git-ignored)
  under `styles` (`Site\styles()`, `Site\sanitizeStyles()` in
  `bridge/site.php`) — switching themes keeps each theme's own values, coming
  back finds them waiting.
- `PUT /bridge/site` takes `handles` as a PATCH over the ACTIVE theme's
  stored overrides: a value SETS a handle, an EMPTY value RESETS it (back to
  the theme default), an ABSENT handle stands — a partial map never wipes the
  others. Only DECLARED handles are honored (`bridge/index.php`).
- The effective values ride the page as `<style id="sm-theme-vars">:root{…}</style>`
  — LAST in the document, right before `</body>` (`withThemeVars()` in
  `bridge/serve.php`), so the theme's own `:root` loses to it (same
  specificity, later wins). A theme that declares no handles serves exactly
  as before: no block, no knob.

## Assets

- Served from `{{themeBase}}assets/…`, whitelisted as real files in
  `.htaccess`, cached `max-age=3600, must-revalidate`. Assets are **not**
  content-hashed — a theme asset change goes live within the hour, and a
  hard reload settles it sooner.
- `deck.js` is a **per-theme** asset: themes own what they create. Two themes
  may ship byte-identical twins today and diverge tomorrow. Core never
  centralizes theme behavior without an explicit decision.
- **ONE stylesheet per theme** (2026-11-10, user order): the markup carries
  NO styles — every rule lives in a single stylesheet the markup only LINKS
  (`<link rel="stylesheet" href="{{themeBase}}assets/theme.css">`). The
  default theme (bakery) is the blueprint: `bridge/templates/bakery/assets/theme.css`
  (renamed from `bakery.css` on 2026-11-10 — the old name is dead) holds every
  style, and its head block teaches the path — copy the folder, rename
  `theme.json`, rewrite the markup, restyle HERE. The `:root` block at the
  top is the TOKEN LAYER: the rules below consume the tokens, so one token
  edit restyles the whole theme. The stylesheet's NAME is the THEME's word
  (`theme.css` is the blueprint's convention, not a contract requirement) —
  the core names no stylesheet at all.
- **The shelf image** (2026-11-10, user order): a theme may hold a preview
  image NEXT TO its `theme.json` — `preview.png`, `preview.jpg`, `preview.jpeg`
  or `preview.webp`, checked in that order (`Serve\themePreview()` in
  `bridge/serve.php`; `GET /bridge/templates` carries `preview`, null when the
  theme ships none). The file IS the image: nothing is generated, nothing is
  fetched. A theme without one wears one of the HOUSE's pictures — five
  jpegs in `src/static` (served under the dashboard's base), picked AT
  RANDOM and KEPT per theme (a card never shuffles on re-render); an image
  that FAILS to load falls back the same way. The theme's OWN picture still
  wins when it ships one. The shots are SQUARE (the shelf renders a
  square image, cropped to the band where the theme's face sits — the brand,
  the headline, the rule, the footer). A registry entry may carry `"preview"`
  (a repo-relative path) — the core builds the raw URL from its own raw base
  (a mirrored registry serves its own pictures) and hands it out as
  `previewUrl` — and `"styles"` (the manifest's own words, mirrored so the
  shelf can show the tags before the install).

## The styles

The style enum is the THEME's word (2026-11-09, user order): `theme.json`
`"styles"` declares the names, the theme ships one template file per style
it wants a distinct layout for (`<style>.html`), and `defaultStyle` names
the floor. The core hardcodes nothing.

- `frontpage` is a RESERVED name: the home page wears it automatically —
  the crown IS the frontpage, unique by law (one home), never written to a
  page's frontmatter, and ignored there. A theme that declares `frontpage`
  ships `frontpage.html`; the create-page modal lists it, and choosing it
  crowns the new page the moment it exists.
- A declared style without its file renders on the base (`page.html`).
- A page's frontmatter may carry `style: <name>` — honored only when the
  theme declares that name.
- `defaultStyle` answers for pages without a frontmatter style.

Precedence in `styleOf()`: the home (frontpage) → page frontmatter
(theme-validated) → site global setting (theme-validated; the settings UI
no longer offers it) → manifest default → `''` (the base).

Style is a CREATION-TIME choice: the new-page modal lists the active
theme's declared styles; no settings surface edits style any more.

The default theme (bakery) declares exactly two: `frontpage` and
`sub-page-1` — the latter a worked example showing theme authors how a
page style is implemented (declare it, ship the file, done).

## What a theme must never do

- Assume DOM timing (the readiness law above).
- Style or depend on editor-internal classes.
- Carry styles in the markup — the one stylesheet holds them (see Assets).
- Emit `sm-` classes of its own — extend the contract here instead, so the
  namespace stays collision-free.
- Parse content HTML structurally; style it. Structure is the pipeline's job.

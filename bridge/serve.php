<?php

/**
 * 1. Relative path: bridge/serve.php
 * 2. Description: The public serving engine — clean-URL dispatch, theme rendering,
 *                 cached-HTML emission with a client-side fallback shell, 404s, SEO head.
 *                 GOSPEL law: PHP never parses Markdown; it serves what the editor's
 *                 browser rendered, and falls back to a client render when stale.
 * 3. Expects: bridge/templates/<name>/ theme contract (theme.json + page.html + assets/),
 *             \Cache\ HTML store, \Site\ settings, \Store\ byte-peek discipline.
 * 4. Provides: Serve\dispatch() — the one call the root front controller makes.
 * 5. SPDX-License-Identifier: MPL-2.0
 * 6. Copyright (c) 2026 Homahuki GmbH
 */

declare(strict_types=1);

namespace Serve;

require_once __DIR__ . '/router.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/cache.php';
require_once __DIR__ . '/site.php';
require_once __DIR__ . '/plugins.php';
require_once __DIR__ . '/domain/meta.php';

const IDENTIFIER = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

/** The one door: dispatch a public request path. Never returns. */
function dispatch(string $path): never
{
    \Plugins\load(); // enabled plugin code in place before any render fills placeholders
    $site = \Site\load();
    $path = trim($path, '/');
    if ($path === '') {
        home($site);
    }
    $segments = explode('/', $path);
    if (count($segments) === 1) {
        page('pages', $segments[0], $site);
    }
    if (count($segments) === 2) {
        page($segments[0], $segments[1], $site);
    }
    notFound($site);
}

/**
 * Admin mode is URL law (2026-11-07, user-designed): a live page under the
 * /_ prefix carries the visual editor — the plain URL is the public face.
 * /_ and /_/ themselves are the dashboard's ground, not a page's.
 */
function adminMode(): bool
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
    return str_starts_with($path, '/_/') && strlen($path) > 3;
}

/** The public face of an admin-mode URL: /_/hello rides home to /hello. */
function plainPath(): string
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
    return str_starts_with($path, '/_/') ? '/' . ltrim(substr($path, 3), '/') : $path;
}

/**
 * The /_-prefixed admin ride: resolve the sub-path as a page and serve it in
 * admin mode. Returns FALSE when the path names no page — the root controller
 * falls through to the dashboard's own assets. Never 404s: a wrong /_/hello
 * is the dashboard's business, not a dead end.
 */
function tryDispatch(string $path): bool
{
    \Plugins\load(); // enabled plugin code in place before any render fills placeholders
    $site = \Site\load();
    $sub = trim(substr($path, 3), '/');
    if ($sub === '') {
        return false; // the dashboard itself, not a page
    }
    $segments = explode('/', $sub);
    $live = static fn (string $type, string $slug): bool =>
        preg_match(IDENTIFIER, $type) === 1
        && preg_match(IDENTIFIER, $slug) === 1
        && \Store\read($type, $slug) !== null
        && !\Store\isDraft($type, $slug);
    if (count($segments) === 2 && $live($segments[0], $segments[1])) {
        page($segments[0], $segments[1], $site); // serves + exits
    }
    if (count($segments) === 1 && $live('pages', $segments[0])) {
        page('pages', $segments[0], $site); // serves + exits
    }
    return false;
}

/** The active theme's drawer handles — theme.json "drawer": [{label, href}].
 *  Root-relative only: a theme lives on this site, it points at this site. */
function themeDrawerHandles(): array
{
    $out = [];
    foreach ((array)(manifest(\Site\load())['drawer'] ?? []) as $handle) {
        if (!is_array($handle)) {
            continue;
        }
        $label = is_string($handle['label'] ?? null) ? trim((string)$handle['label']) : '';
        $href = is_string($handle['href'] ?? null) ? trim((string)$handle['href']) : '';
        if ($label === '' || $href === ''
            || !str_starts_with($href, '/') || str_starts_with($href, '//') || str_contains($href, '\\')) {
            continue;
        }
        $out[] = ['label' => mb_substr($label, 0, 60), 'href' => $href, 'kind' => 'template'];
        if (count($out) >= 8) {
            break;
        }
    }
    return $out;
}

/** The front door: \Site\home slug, else the first published page of "pages". */
function homeSlug(array $site): string
{
    $home = (string)($site['home'] ?? '');
    if ($home === '') {
        foreach (\Store\listAll()['pages'] ?? [] as $candidate) {
            if (!($candidate['draft'] ?? false)) {
                $home = (string)$candidate['slug'];
                break;
            }
        }
    }
    return $home;
}

/** The front door SERVED: the home slug, or the 404 when the bakery is empty. */
function home(array $site): never
{
    $home = homeSlug($site);
    if ($home === '') {
        notFound($site);
    }
    page('pages', $home, $site);
}

/** Serve one page: fresh cached HTML straight out, stale/missing → client fallback shell. */
function page(string $type, string $slug, array $site): never
{
    if (preg_match(IDENTIFIER, $type) !== 1 || preg_match(IDENTIFIER, $slug) !== 1) {
        notFound($site);
    }
    // Drafts and ghosts are the same thing to the public: they do not exist.
    if (\Store\read($type, $slug) === null || \Store\isDraft($type, $slug)) {
        notFound($site);
    }
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-cache');
    // Admin mode is URL law (2026-11-07): the editor rides ONLY under /_ — a
    // visitor there bounces to the public face; an authed user on the plain
    // URL reads the public face like anyone else (same bytes, same cache law).
    if (adminMode() && !\Auth\check()) {
        header('Location: ' . plainPath(), true, 302);
        exit;
    }
    // Logged-in editors in admin mode: the live-page editor renders client-side
    // from the raw .md — no cache question, no double render. Everyone else
    // (visitors anywhere, authed users on plain URLs): cached HTML straight
    // out, stale/missing → the client fallback shell. Anon HTML never changes.
    if (\Auth\check() && adminMode()) {
        $html = '<div data-sm-edit-root data-type="' . htmlspecialchars($type, ENT_QUOTES)
            . '" data-slug="' . htmlspecialchars($slug, ENT_QUOTES) . '"></div>'
            . "\n" . '<script type="module" src="/bridge/assets/edit.esm.js"></script>';
        $theme = themeTemplate($site, styleOf($type, $slug, $site));
        $title = titleOf($type, $slug, $slug);
        echo fill($theme, $site, $title, $html, seoHead($title, $site['name'], "{$type}/{$slug}"), $slug, $type);
        exit;
    }
    // Staleness first, body second (the optimization pass, 2026-11-07): a
    // stale page costs the mtime peek only, never a wasted body read.
    if (!\Cache\htmlStale($type, $slug) && ($html = \Cache\html($type, $slug)) !== null) {
        // The cache holds the BODY only — the theme wrap happens here, at serve
        // time, so theme and style switches apply to cached pages without a re-publish.
        $theme = themeTemplate($site, styleOf($type, $slug, $site));
        $title = titleOf($type, $slug, $slug);
        echo fill($theme, $site, $title, $html, seoHead($title, $site['name'], "{$type}/{$slug}"), $slug, $type);
        exit;
    }
    // Stale or never rendered — the visitor's browser picks up the slack (GOSPEL serving law).
    echo shell($site, $type, $slug);
    exit;
}

// ——— theme contract: manifest + page.html/slide.html + assets/, placeholders filled
// ——— by str_replace. Style comes from the page's frontmatter, default from the theme.

/** Validated theme directory — a rogue theme name in settings falls back to bakery. */
function themeDir(array $site): string
{
    $name = $site['theme'];
    if (preg_match(IDENTIFIER, $name) !== 1) {
        $name = 'bakery';
    }
    return __DIR__ . '/templates/' . $name;
}

/** The theme manifest, or [] when absent/broken — never a fatal for decoration data.
 *  Memoized per request (the optimization pass, 2026-11-07): styleOf asks per
 *  page and the drawer asks again; theme.json never changes mid-request (a
 *  theme switch rides Site\save + a fresh request). */
function manifest(array $site): array
{
    static $memo = [];
    $dir = themeDir($site);
    if (!array_key_exists($dir, $memo)) {
        $file = $dir . '/theme.json';
        $manifest = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
        $memo[$dir] = is_array($manifest) ? $manifest : [];
    }
    return $memo[$dir];
}

/** The active theme's declared styles — the style enum is the theme's word
 *  now, not the core's (2026-11-09, user order): a theme adds a style by
 *  declaring it in theme.json "styles" and shipping its <style>.html.
 *  Slug-shaped names only; anything else in the list is dust. */
function themeStyles(array $site): array
{
    $styles = manifest($site)['styles'] ?? null;
    if (!is_array($styles)) {
        return [];
    }
    $out = [];
    foreach ($styles as $style) {
        if (is_string($style) && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $style) === 1) {
            $out[] = $style;
        }
    }
    return $out;
}

/** Page style. THE FRONTPAGE LAW (2026-11-09, user order): the home page
 *  wears the theme's `frontpage` style automatically — the crown IS the
 *  frontpage, unique by law (one home), never written to frontmatter, and
 *  the name is reserved there (frontmatter frontpage on a non-home page is
 *  dust). Else the page's own frontmatter style, honored only when the
 *  theme declares it; else the site's global style, same rule; else the
 *  theme's declared default; else '' — the theme's base template. */
function styleOf(string $type, string $slug, array $site): string
{
    $styles = themeStyles($site);
    if ($slug === homeSlug($site) && in_array('frontpage', $styles, true)) {
        return 'frontpage';
    }
    $raw = \Store\read($type, $slug);
    $style = $raw !== null ? \Meta\peek($raw)['style'] : null;
    if ($style !== null && $style !== 'frontpage' && in_array($style, $styles, true)) {
        return $style;
    }
    if (($site['style'] ?? '') !== '' && in_array((string)$site['style'], $styles, true)) {
        return (string)$site['style'];
    }
    $default = manifest($site)['defaultStyle'] ?? '';
    return is_string($default) && in_array($default, $styles, true) ? $default : '';
}

/** Template for a style: the style's own file when the theme ships one
 *  (<style>.html), else the theme's base floor (page.html) — a declared
 *  style without its file renders on the base. */
function themeTemplate(array $site, string $style): array
{
    $dir = themeDir($site);
    $base = '/bridge/templates/' . basename($dir) . '/';
    $candidates = $style !== '' ? [$style . '.html', 'page.html'] : ['page.html'];
    foreach ($candidates as $candidate) {
        $file = $dir . '/' . $candidate;
        if (is_file($file)) {
            return ['base' => $base, 'template' => (string)file_get_contents($file)];
        }
    }
    // ——— THE THEME SAFEGUARD (2026-11-09, user order — closes the BACKLOG): a
    // missing or broken configured theme never leaves the site standing on the
    // bare floor — the house default (bakery, undeletable by the same word)
    // serves before `houseTemplate()` would. Same candidates, same law: if the
    // house default is whole, its face carries the page; only a broken house
    // default falls to the bare floor. ———
    $house = \Site\DEFAULTS['theme'];
    if (basename($dir) !== $house) {
        foreach ($candidates as $candidate) {
            $file = __DIR__ . '/templates/' . $house . '/' . $candidate;
            if (is_file($file)) {
                return ['base' => '/bridge/templates/' . $house . '/', 'template' => (string)file_get_contents($file)];
            }
        }
    }
    return ['base' => $base, 'template' => houseTemplate()];
}

// ——— the theme handles: the CSS variables a theme offers to the Settings tab.
// ——— THE THEME'S WORD (2026-11-10): theme.json "handles" declares them, the
// ——— core only carries the stored values to the page. The core hardcodes nothing.

/** The handle value lid: a color is a color, a text is a short safe token.
 *  The value lands in a <style> block on the public face — nothing that could
 *  close that block survives the door. */
function validHandleValue(string $type, string $value): bool
{
    if ($value === '' || strlen($value) > 80) {
        return false;
    }
    if ($type === 'color') {
        if (preg_match('/^#(?:[0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $value) === 1) {
            return true;
        }
        if (preg_match('/^(?:rgb|rgba|hsl|hsla)\([0-9a-zA-Z.,%\/ ]{1,60}\)$/', $value) === 1) {
            return true;
        }
        return preg_match('/^[a-zA-Z]{3,24}$/', $value) === 1; // a named color
    }
    return preg_match('/^[#a-zA-Z0-9 ,.%()\/_-]{1,80}$/', $value) === 1;
}

/** The active theme's declared handles — {var, label, type, default}. A handle
 *  that does not pass the lid does not exist: the name must be a CSS custom
 *  property, the type a known one, the default a value of that type. */
function themeHandles(array $site): array
{
    $declared = manifest($site)['handles'] ?? null;
    if (!is_array($declared)) {
        return [];
    }
    $out = [];
    foreach ($declared as $handle) {
        if (!is_array($handle)) {
            continue;
        }
        $var = is_string($handle['var'] ?? null) ? trim((string)$handle['var']) : '';
        if (preg_match('/^--[a-z0-9-]{1,40}$/', $var) !== 1) {
            continue;
        }
        $type = is_string($handle['type'] ?? null) ? trim((string)$handle['type']) : 'color';
        if (!in_array($type, ['color', 'text'], true)) {
            continue;
        }
        $default = is_string($handle['default'] ?? null) ? trim((string)$handle['default']) : '';
        if (!validHandleValue($type, $default)) {
            continue;
        }
        $label = is_string($handle['label'] ?? null) ? trim((string)$handle['label']) : trim($var, '-');
        $out[] = ['var' => $var, 'label' => mb_substr($label, 0, 60), 'type' => $type, 'default' => $default];
    }
    return $out;
}

/**
 * The theme's SHELF IMAGE (2026-11-10, user order): a theme may hold a
 * preview image next to its theme.json — preview.png, preview.jpg, preview.jpeg
 * or preview.webp. The URL, or null when the theme ships none (the dashboard
 * draws a little notice instead — an honest absence, never a broken picture).
 * The file IS the image: nothing is generated, nothing is fetched, nothing is
 * rendered on the server.
 */
function themePreview(string $name): ?string
{
    if (preg_match(IDENTIFIER, $name) !== 1) {
        return null;
    }
    foreach (['png', 'jpg', 'jpeg', 'webp'] as $ext) {
        if (is_file(__DIR__ . '/templates/' . $name . '/preview.' . $ext)) {
            return '/bridge/templates/' . $name . '/preview.' . $ext;
        }
    }
    return null;
}

/** The chosen theme's handles with their effective value — the stored override, else the
 *  theme's default. This is what the Settings tab draws. */
function handlesFor(array $site): array
{
    $stored = \Site\styles((string)$site['theme']);
    $out = [];
    foreach (themeHandles($site) as $handle) {
        $value = $stored[$handle['var']] ?? '';
        if (!is_string($value) || !validHandleValue($handle['type'], $value)) {
            $value = $handle['default'];
        }
        $handle['value'] = $value;
        $out[] = $handle;
    }
    return $out;
}

/** The stored handle values as a CSS :root block — empty when the theme
 *  declares no handles or nothing was changed. It lands LAST in the document,
 *  so the theme's own :root loses to it (same specificity, later wins). */
function themeVars(array $site): string
{
    $handles = themeHandles($site);
    if ($handles === []) {
        return '';
    }
    $stored = \Site\styles((string)$site['theme']);
    $decls = [];
    foreach ($handles as $handle) {
        $value = $stored[$handle['var']] ?? '';
        if (!is_string($value) || $value === '' || !validHandleValue($handle['type'], $value)) {
            continue;
        }
        $decls[] = $handle['var'] . ':' . $value;
    }
    if ($decls === []) {
        return '';
    }
    return '<style id="sm-theme-vars">:root{' . implode(';', $decls) . '}</style>';
}

/** Last-resort template when the configured theme is missing or broken. */
function houseTemplate(): string
{
    return <<<'HTML'
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
{{seoHead}}
</head>
<body>
<header>{{logoHtml}}<h1>{{siteName}}</h1>{{claimHtml}}</header>
<main>{{bodyHtml}}</main>
<footer><p>© {{year}} {{siteName}}</p></footer>
</body>
</html>
HTML;
}

/** Fill the theme template. Body HTML is trusted (walled pipeline); everything else escapes.
 *  Plugin content filters (p6 slice 2) transform the body first; plugin placeholders (p6)
 *  merge in FIRST, core tokens AFTER — a core token structurally wins on any collision
 *  (defense-in-depth, boundary audit B5, 2026-11-07: the manifest lid namespaces plugin
 *  placeholders "<prefix>_", so no collision exists today; if one is ever speakable,
 *  the core's own word is the one the page obeys). Values are the plugin's trusted HTML. */
function fill(array $theme, array $site, string $title, string $bodyHtml, string $seoHead, string $currentSlug = '', string $type = ''): string
{
    /** Plugin content filters (p6 slice 2) transform the stored body HTML — serve-time, live. */
    $bodyHtml = \Plugins\contentFilters(['site' => $site, 'type' => $type, 'slug' => $currentSlug, 'title' => $title], $bodyHtml);
    $replacements = [];
    foreach (\Plugins\placeholderFills(['site' => $site, 'type' => $type, 'slug' => $currentSlug, 'title' => $title]) as $token => $value) {
        $replacements[$token] = $value;
    }
    $replacements['{{siteName}}'] = htmlspecialchars($site['name'], ENT_QUOTES);
    $replacements['{{claim}}'] = htmlspecialchars($site['claim'], ENT_QUOTES);
    $replacements['{{claimHtml}}'] = $site['claim'] === ''
        ? ''
        : '<p class="claim">' . htmlspecialchars($site['claim'], ENT_QUOTES) . '</p>';
    $replacements['{{logoUrl}}'] = htmlspecialchars(logoUrl($site['logo']), ENT_QUOTES);
    $replacements['{{logoHtml}}'] = $site['logo'] === ''
        ? ''
        : '<img class="logo" src="' . htmlspecialchars(logoUrl($site['logo']), ENT_QUOTES)
            . '" alt="' . htmlspecialchars($site['name'], ENT_QUOTES) . '">';
    $replacements['{{pageTitle}}'] = htmlspecialchars($title, ENT_QUOTES);
    $replacements['{{bodyHtml}}'] = $bodyHtml;
    $replacements['{{seoHead}}'] = $seoHead;
    $replacements['{{navHtml}}'] = navHtml($site, $currentSlug);
    $replacements['{{themeBase}}'] = htmlspecialchars($theme['base'], ENT_QUOTES);
    $replacements['{{year}}'] = date('Y');
    $out = str_replace(array_keys($replacements), array_values($replacements), $theme['template']);
    return withThemeVars($out, $site);
}

/** The face's font (2026-11-12, user order): head furniture for the site's
 *  chosen font — a <link> for an https stylesheet (the family name is lifted
 *  back out of a Google css2 URL), an @font-face for an uploaded asset, or a
 *  Google css2 link built from a bare family name — plus the ONE late rule
 *  that applies the family to the body. It lands with the theme vars, last in
 *  the document, so it beats the theme's own font stack (same law as the
 *  handles: the core turns the knob, the theme keeps the layout). */
function fontHead(array $site): string
{
    $font = trim((string)($site['font'] ?? ''));
    if ($font === '') {
        return '';
    }
    $family = '';
    if (str_starts_with($font, '/')) {
        // uploaded font asset — the lid in Site\sanitizeFont() vouched for the path shape
        $url = htmlspecialchars($font, ENT_QUOTES);
        $head = "<style>@font-face{font-family:'Schmarrn Custom';src:url('{$url}') format('woff2');font-display:swap;}</style>";
        $family = 'Schmarrn Custom';
    } elseif (str_starts_with($font, 'https://')) {
        $head = '<link rel="preload" as="style" href="' . htmlspecialchars($font, ENT_QUOTES) . '" />';
        $head .= '<link rel="stylesheet" href="' . htmlspecialchars($font, ENT_QUOTES) . '" />';
        if (preg_match('/[?&]family=([^&]+)/', $font, $m) === 1) {
            $family = trim(rawurldecode((string)$m[1]));
        }
    } else {
        $family = $font;
        [$namePart, $axisPart] = array_pad(explode(':', $font, 2), 2, '');
        $head = '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family='
            . rawurlencode($namePart)
            . ($axisPart !== '' ? ':' . $axisPart : '') . '&display=swap" />';
    }
    // the family must be a plain CSS identifier-ish token before it enters a style rule
    // (the axis part ":wght@…" lives in the URL only — the family name is the bare name)
    $family = explode(':', $family, 2)[0];
    if ($family !== '' && preg_match("/^[A-Za-z0-9 ,+'_-]{1,60}$/", $family) === 1) {
        $stack = "'" . str_replace(["'", '"', '\\', ';'], '', $family) . "', system-ui, sans-serif";
        $head .= '<style>body{font-family:' . $stack . ';}</style>';
    }
    return $head;
}

/** The theme handles ride the page as a :root block — LAST in the document,
 *  so they beat the theme's own :root (same specificity, later wins). The
 *  font head rides the same late block. The theme's stylesheet still owns the
 *  layout; this only turns the knobs the theme declared — plus the face. */
function withThemeVars(string $html, array $site): string
{
    $extra = fontHead($site) . themeVars($site);
    if ($extra === '') {
        return $html;
    }
    $pos = stripos($html, '</body>');
    if ($pos === false) {
        return $html . $extra;
    }
    return substr($html, 0, $pos) . $extra . substr($html, $pos);
}

/** The menu: published pages of "pages" as links — the navitem entity, core-owned.
 *  Classical style's spine. Plain core nav, same bytes for editors and visitors —
 *  the in-page nav ghost (data-sm-nav + data-sm-nav-add plus) retired 2026-11-04:
 *  menu management lives in the dashboard overlay (menu editing is a management
 *  concern, not a live-canvas one). Templates never wire nav themselves. */
function navHtml(array $site, string $currentSlug): string
{
    $items = [];
    foreach (\Store\listAll()['pages'] ?? [] as $candidate) {
        if ($candidate['draft'] ?? false) {
            continue;
        }
        $slug = (string)$candidate['slug'];
        $current = $slug === $currentSlug ? ' aria-current="page"' : '';
        $items[] = '<a href="/' . htmlspecialchars($slug, ENT_QUOTES) . '"' . $current . '>'
            . htmlspecialchars(titleOf('pages', $slug, $slug), ENT_QUOTES) . '</a>';
    }
    if ($items === []) {
        return '';
    }
    return '<nav class="sm-nav">' . implode('', $items) . '</nav>';
}

/** Logo setting → URL. Absolute or root-relative passes through; anything else roots it. */
function logoUrl(string $logo): string
{
    if ($logo === '' || str_starts_with($logo, '/') || str_starts_with($logo, 'http://') || str_starts_with($logo, 'https://')) {
        return $logo;
    }
    return '/' . $logo;
}

/** Byte-peek the title out of the frontmatter — ONE owner (Meta\peek). */
function titleOf(string $type, string $slug, string $fallback): string
{
    $raw = \Store\read($type, $slug);
    $title = $raw !== null ? \Meta\peek($raw)['title'] : null;
    return $title !== null ? $title : $fallback;
}

/** SEO head: title tag + OpenGraph basics. og:url is best-effort (shared hosts lie). */
function seoHead(string $pageTitle, string $siteName, string $path): string
{
    $full = $path === '' ? $siteName : "{$pageTitle} · {$siteName}";
    $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES);
    $scheme = (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off') ? 'https' : 'http';
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
    $url = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $uri;
    return '<title>' . $escape($full) . "</title>\n"
        . '<meta property="og:title" content="' . $escape($full) . '">' . "\n"
        . '<meta property="og:site_name" content="' . $escape($siteName) . '">' . "\n"
        . '<meta property="og:type" content="website">' . "\n"
        . '<meta property="og:url" content="' . $escape($url) . '">' . "\n"
        . '<meta name="twitter:card" content="summary">';
}

/** The fallback shell: themed page whose body re-renders client-side from the raw .md. */
function shell(array $site, string $type, string $slug): string
{
    $theme = themeTemplate($site, styleOf($type, $slug, $site));
    $title = titleOf($type, $slug, $slug);
    $body = '<div class="sm-fallback" data-schmarrn-root data-type="' . htmlspecialchars($type, ENT_QUOTES)
        . '" data-slug="' . htmlspecialchars($slug, ENT_QUOTES) . '"></div>' . "\n"
        . '<script type="module" src="/bridge/assets/render.esm.js"></script>';
    return fill($theme, $site, $title, $body, seoHead($title, $site['name'], "{$type}/{$slug}"), $slug, $type);
}

/** Themed 404 — the public never sees JSON from the serving engine. */
function notFound(array $site): never
{
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    $theme = themeTemplate($site, ''); // the theme's base floor carries the 404
    $body = '<article class="sm-notfound"><h1>404</h1>'
        . '<p>This page has left the display case.</p>'
        . '<p><a href="/">Back to the front</a></p></article>';
    echo fill($theme, $site, 'Not found', $body, seoHead('Not found', $site['name'], '404'));
    exit;
}

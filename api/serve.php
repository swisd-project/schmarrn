<?php

/**
 * 1. Relative path: api/serve.php
 * 2. Description: The public serving engine — clean-URL dispatch, theme rendering,
 *                 cached-HTML emission with a client-side fallback shell, 404s, SEO head.
 *                 GOSPEL law: PHP never parses Markdown; it serves what the editor's
 *                 browser rendered, and falls back to a client render when stale.
 * 3. Expects: themes/<name>/ theme contract (theme.json + page.html + assets/),
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
    if ($path === 'design') {
        design($site);
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

/** The design workbench — static bytes, public, not content. The system gets steered here. */
function design(array $site): never
{
    $file = __DIR__ . '/assets/design.html';
    if (!is_file($file)) {
        notFound($site);
    }
    header('Content-Type: text/html; charset=utf-8');
    readfile($file);
    exit;
}

/** The front door: \Site\home slug, else the first published page of "pages". */
function home(array $site): never
{
    $home = $site['home'];
    if ($home === '') {
        foreach (\Store\listAll()['pages'] ?? [] as $candidate) {
            if (!($candidate['draft'] ?? false)) {
                $home = (string)$candidate['slug'];
                break;
            }
        }
    }
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
    // Logged-in editors: the live-page editor renders client-side from the raw .md —
    // no cache question, no double render. Anonymous visitors: cached HTML straight
    // out, stale/missing → the client fallback shell. Anon HTML never changes.
    if (\Auth\check()) {
        $html = '<div data-sm-edit-root data-type="' . htmlspecialchars($type, ENT_QUOTES)
            . '" data-slug="' . htmlspecialchars($slug, ENT_QUOTES) . '"></div>'
            . "\n" . '<script type="module" src="/api/assets/edit.esm.js"></script>';
        $theme = themeTemplate($site, styleOf($type, $slug, $site));
        $title = titleOf($type, $slug, $slug);
        echo fill($theme, $site, $title, $html, seoHead($title, $site['name'], "{$type}/{$slug}"), $slug, $type);
        exit;
    }
    $html = \Cache\html($type, $slug);
    if ($html !== null && !\Cache\htmlStale($type, $slug)) {
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
    return dirname(__DIR__) . '/themes/' . $name;
}

/** The theme manifest, or [] when absent/broken — never a fatal for decoration data. */
function manifest(array $site): array
{
    $file = themeDir($site) . '/theme.json';
    $manifest = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
    return is_array($manifest) ? $manifest : [];
}

/** Page style: frontmatter byte-peek wins, else the theme's declared default. */
function styleOf(string $type, string $slug, array $site): string
{
    $raw = \Store\read($type, $slug);
    if ($raw !== null && preg_match('/^style:\s*["\']?(slide|classical)["\']?\s*$/m', $raw, $m) === 1) {
        return $m[1];
    }
    return (manifest($site)['defaultStyle'] ?? null) === 'slide' ? 'slide' : 'classical';
}

/** Template for a style; a theme without slide.html falls back to its classical floor. */
function themeTemplate(array $site, string $style): array
{
    $dir = themeDir($site);
    $base = '/themes/' . basename($dir) . '/';
    $candidates = $style === 'slide' ? ['slide.html', 'page.html'] : ['page.html'];
    foreach ($candidates as $candidate) {
        $file = $dir . '/' . $candidate;
        if (is_file($file)) {
            return ['base' => $base, 'template' => (string)file_get_contents($file)];
        }
    }
    return ['base' => $base, 'template' => houseTemplate()];
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
 *  Plugin placeholders (p6) merge in last — manifest-declared only, values are the plugin's trusted HTML. */
function fill(array $theme, array $site, string $title, string $bodyHtml, string $seoHead, string $currentSlug = '', string $type = ''): string
{
    $replacements = [
        '{{siteName}}' => htmlspecialchars($site['name'], ENT_QUOTES),
        '{{claim}}' => htmlspecialchars($site['claim'], ENT_QUOTES),
        '{{claimHtml}}' => $site['claim'] === ''
            ? ''
            : '<p class="claim">' . htmlspecialchars($site['claim'], ENT_QUOTES) . '</p>',
        '{{logoUrl}}' => htmlspecialchars(logoUrl($site['logo']), ENT_QUOTES),
        '{{logoHtml}}' => $site['logo'] === ''
            ? ''
            : '<img class="logo" src="' . htmlspecialchars(logoUrl($site['logo']), ENT_QUOTES)
                . '" alt="' . htmlspecialchars($site['name'], ENT_QUOTES) . '">',
        '{{pageTitle}}' => htmlspecialchars($title, ENT_QUOTES),
        '{{bodyHtml}}' => $bodyHtml,
        '{{seoHead}}' => $seoHead,
        '{{navHtml}}' => navHtml($site, $currentSlug),
        '{{themeBase}}' => htmlspecialchars($theme['base'], ENT_QUOTES),
        '{{year}}' => date('Y'),
    ];
    foreach (\Plugins\placeholderFills(['site' => $site, 'type' => $type, 'slug' => $currentSlug, 'title' => $title]) as $token => $value) {
        $replacements[$token] = $value;
    }
    return str_replace(array_keys($replacements), array_values($replacements), $theme['template']);
}

/** The menu: published pages of "pages" as links, the current one marked. Classical style's spine. */
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

/** Byte-peek the title out of the frontmatter — sniffing, not parsing (Store discipline). */
function titleOf(string $type, string $slug, string $fallback): string
{
    $raw = \Store\read($type, $slug);
    if ($raw !== null && preg_match('/^title:\s*(.+)$/m', $raw, $m) === 1) {
        $title = trim($m[1]);
        $quote = $title[0] ?? '';
        if (strlen($title) >= 2 && ($quote === '"' || $quote === "'") && str_ends_with($title, $quote)) {
            $title = substr($title, 1, -1);
        }
        if ($title !== '') {
            return $title;
        }
    }
    return $fallback;
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
        . '<script type="module" src="/api/assets/render.esm.js"></script>';
    return fill($theme, $site, $title, $body, seoHead($title, $site['name'], "{$type}/{$slug}"), $slug, $type);
}

/** Themed 404 — the public never sees JSON from the serving engine. */
function notFound(array $site): never
{
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    $theme = themeTemplate($site, 'classical');
    $body = '<article class="sm-notfound"><h1>404</h1>'
        . '<p>This page has left the display case.</p>'
        . '<p><a href="/">Back to the front</a></p></article>';
    echo fill($theme, $site, 'Not found', $body, seoHead('Not found', $site['name'], '404'));
    exit;
}

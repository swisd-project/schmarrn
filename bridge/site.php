<?php

/**
 * 1. Relative path: bridge/site.php
 * 2. Description: Global brand/site settings loader — template defaults, overridden by
 *                 site.local.php (git-ignored). Pure data, no I/O beyond the two files.
 *                 Also the home of the THEME HANDLES: the stored per-theme overrides of
 *                 the CSS variables a theme declares in theme.json "handles".
 * 3. Expects: bridge/config/site.php (+ optional site.local.php).
 * 4. Provides: Site\load(), Site\save(), Site\styles() for the setup wizard / settings UI.
 * 5. SPDX-License-Identifier: MPL-2.0
 * 6. Copyright (c) 2026 Homahuki GmbH
 */

declare(strict_types=1);

namespace Site;

const DEFAULTS = [
    'name' => 'Schmarrn',
    'claim' => '',
    'logo' => '',
    'theme' => 'bakery',
    'home' => '',
    'style' => '', // the GLOBAL style: '' = theme default, else a theme-declared style
    'styles' => [], // the theme handles: ['<theme>' => ['--var' => 'value', …]]
];

const KEYS = ['name', 'claim', 'logo', 'theme', 'home', 'style'];

/** The handle map's shape lid: theme names, CSS variable names, safe values.
 *  The value lands in a <style> block on the public face — nothing that could
 *  close that block survives the door. The TYPE lid (is this a color?) lives
 *  with the theme contract in Serve\validHandleValue(). */
function sanitizeStyles(mixed $styles): array
{
    if (!is_array($styles)) {
        return [];
    }
    $out = [];
    foreach ($styles as $theme => $map) {
        if (!is_string($theme) || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $theme) !== 1 || !is_array($map)) {
            continue;
        }
        $vars = [];
        foreach ($map as $var => $value) {
            if (!is_string($var) || preg_match('/^--[a-z0-9-]{1,40}$/', $var) !== 1) {
                continue;
            }
            if (!is_string($value) || preg_match('/^[#a-zA-Z0-9 ,.%()\/_-]{1,80}$/', $value) !== 1) {
                continue;
            }
            $vars[$var] = $value;
        }
        if ($vars !== []) {
            $out[$theme] = $vars;
        }
    }
    return $out;
}

/** The stored handle overrides of ONE theme — ['--crust' => '#fff', …]. */
function styles(string $theme): array
{
    $all = load()['styles'] ?? [];
    $mine = is_array($all[$theme] ?? null) ? $all[$theme] : [];
    return is_array($mine) ? $mine : [];
}

function configFile(): string
{
    return __DIR__ . '/config/site.local.php';
}

/** Merged settings: template < local. Unknown keys ignored — the contract is the array.
 *  MEMOIZED per request (the optimization pass, 2026-11-07): the serve path
 *  asks 2-3 times per request; save() forgets the memo — the only writer. */
function load(bool $wipe = false): array
{
    static $memo = null;
    if ($wipe) {
        $memo = null; // forget()'s word — the next call re-reads both files
        return [];
    }
    if ($memo !== null) {
        return $memo;
    }
    $settings = DEFAULTS;
    $template = is_file(__DIR__ . '/config/site.php') ? (array)(require __DIR__ . '/config/site.php') : [];
    foreach (KEYS as $key) {
        if (isset($template[$key]) && is_string($template[$key]) && $template[$key] !== '') {
            $settings[$key] = $template[$key];
        }
    }
    if (is_file(configFile())) {
        $local = (array)(require configFile());
        foreach (KEYS as $key) {
            if (isset($local[$key]) && is_string($local[$key])) {
                $settings[$key] = $local[$key];
            }
        }
        // The theme handles ride along in the same file (runtime state, never
        // in the shipped template config).
        if (isset($local['styles']) && is_array($local['styles'])) {
            $settings['styles'] = $local['styles'];
        }
    }
    return $memo = $settings;
}

/** Drop the load() memo — save() calls it; the settings just changed on disk. */
function forget(): void
{
    load(true); // the boolean is the wipe word
}

/** Write the local settings file (var_export — data, never code). */
function save(array $settings): void
{
    $clean = [];
    foreach (KEYS as $key) {
        $value = isset($settings[$key]) && is_string($settings[$key]) ? $settings[$key] : DEFAULTS[$key];
        if ($key === 'style' && $value !== '' && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $value) !== 1) {
            $value = '';
        }
        $clean[$key] = $value;
    }
    $clean['styles'] = sanitizeStyles($settings['styles'] ?? []);
    $export = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($clean, true) . ";\n";
    if (file_put_contents(configFile(), $export) === false) {
        \Router\refuse(500, 'Site settings slid off the plate — write failed.');
    }
    // The next request may land inside opcache's revalidation window (default 2s;
    // some hosts ship validate_timestamps=0) and read the PREVIOUS settings —
    // theme switches would appear to do nothing. Drop the cached bytecode now.
    if (function_exists('opcache_invalidate')) {
        opcache_invalidate(configFile(), true);
    }
    forget(); // the settings changed — no memo may serve the old site
}

// ——— site-level assets (the logo's home): src/content/.assets/ — a dot-dir,
// ——— invisible to the page tree (listAll skips dot-dirs), install-local, writable.

function assetsDir(): string
{
    return \Store\root() . '/.assets';
}

/** Immutable by law: an existing filename is never overwritten — the upload gets a fresh prefix. */
function assetStore(string $originalName, string $tmpPath): array
{
    \Store\assertUploadSize($tmpPath);
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($ext, \Store\MEDIA_EXTENSIONS, true)) {
        \Router\refuse(400, "Unsupported asset type: .{$ext}");
    }
    if (!is_dir(assetsDir()) && !@mkdir(assetsDir(), 0775, true)) {
        \Router\refuse(500, 'Cannot build the pantry — the asset directory would not rise.');
    }
    $prefix = bin2hex(random_bytes(4));
    $base = strtolower(pathinfo($originalName, PATHINFO_FILENAME));
    $base = preg_replace('/[^a-z0-9_-]+/', '-', $base) ?: 'asset';
    $base = trim(substr($base, 0, 40), '-') ?: 'asset';
    $name = "{$prefix}-{$base}.{$ext}";
    $target = assetsDir() . '/' . $name;
    if (!@rename($tmpPath, $target) && !@copy($tmpPath, $target)) {
        \Router\refuse(500, 'Asset storage burned — check the host writable paths.');
    }
    @chmod($target, 0664);
    return ['name' => $name, 'url' => '/bridge/site-assets/' . rawurlencode($name)];
}

/** The real path of a stored asset, or null when the name is foreign. */
function assetPath(string $name): ?string
{
    if (preg_match('/^[A-Za-z0-9._-]+$/', $name) !== 1 || str_starts_with($name, '.')) {
        return null;
    }
    $path = assetsDir() . '/' . $name;
    return is_file($path) ? $path : null;
}

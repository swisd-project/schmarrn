<?php

/**
 * 1. Relative path: api/site.php
 * 2. Description: Global brand/site settings loader — template defaults, overridden by
 *                 site.local.php (git-ignored). Pure data, no I/O beyond the two files.
 * 3. Expects: api/config/site.php (+ optional site.local.php).
 * 4. Provides: Site\load(), Site\save() for the setup wizard / settings UI.
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
];

const KEYS = ['name', 'claim', 'logo', 'theme', 'home'];

function configFile(): string
{
    return __DIR__ . '/config/site.local.php';
}

/** Merged settings: template < local. Unknown keys ignored — the contract is the array. */
function load(): array
{
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
    }
    return $settings;
}

/** Write the local settings file (var_export — data, never code). */
function save(array $settings): void
{
    $clean = [];
    foreach (KEYS as $key) {
        $clean[$key] = isset($settings[$key]) && is_string($settings[$key]) ? $settings[$key] : DEFAULTS[$key];
    }
    $export = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($clean, true) . ";\n";
    if (file_put_contents(configFile(), $export) === false) {
        \Router\refuse(500, 'Cannot write site settings.');
    }
    // The next request may land inside opcache's revalidation window (default 2s;
    // some hosts ship validate_timestamps=0) and read the PREVIOUS settings —
    // theme switches would appear to do nothing. Drop the cached bytecode now.
    if (function_exists('opcache_invalidate')) {
        opcache_invalidate(configFile(), true);
    }
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
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($ext, \Store\MEDIA_EXTENSIONS, true)) {
        \Router\refuse(400, "Unsupported asset type: .{$ext}");
    }
    if (!is_dir(assetsDir()) && !@mkdir(assetsDir(), 0775, true)) {
        \Router\refuse(500, 'Cannot create the asset directory.');
    }
    $prefix = bin2hex(random_bytes(4));
    $base = strtolower(pathinfo($originalName, PATHINFO_FILENAME));
    $base = preg_replace('/[^a-z0-9_-]+/', '-', $base) ?: 'asset';
    $base = trim(substr($base, 0, 40), '-') ?: 'asset';
    $name = "{$prefix}-{$base}.{$ext}";
    $target = assetsDir() . '/' . $name;
    if (!@rename($tmpPath, $target) && !@copy($tmpPath, $target)) {
        \Router\refuse(500, 'Asset storage failed.');
    }
    @chmod($target, 0664);
    return ['name' => $name, 'url' => '/api/site-assets/' . rawurlencode($name)];
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

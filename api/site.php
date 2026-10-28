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

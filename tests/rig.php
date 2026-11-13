<?php

/**
 * 1. Relative path: tests/rig.php
 * 2. Description: The RIG BUILDER — assembles a throwaway Schmarrn from the
 *                 current tree so an integration battery never depends on a
 *                 hand-made rig in /tmp (the old batteries died with the
 *                 machine; this one rebuilds itself every run).
 * 3. Expects: The repo root as argv[1] (default: the parent of this file).
 * 4. Provides: rigBuild() — a sandbox with the core, a bakery theme, content
 *                 (a published page + a DRAFT page), an admin account and the
 *                 bienenstich ware installed and enabled.
 * 5. SPDX-License-Identifier: MPL-2.0
 * 6. Copyright (c) 2026 Homahuki GmbH
 */

declare(strict_types=1);

/**
 * Build a rig. Returns [root, port] — the port is picked by the caller (the
 * rig needs a door only when a battery serves it).
 */
function rigBuild(string $root, array $opts = []): string
{
    $rig = sys_get_temp_dir() . '/schmarrn-rig-' . getmypid() . '-' . bin2hex(random_bytes(3));

    // the core
    @mkdir($rig . '/bridge/config', 0775, true);
    @mkdir($rig . '/bridge/plugins', 0775, true);
    @mkdir($rig . '/bridge/templates', 0775, true);
    @mkdir($rig . '/bridge/cache', 0775, true);
    @mkdir($rig . '/src/content/pages', 0775, true);
    @mkdir($rig . '/pagetypes', 0775, true);

    foreach (glob($root . '/bridge/*.php') ?: [] as $file) {
        copy($file, $rig . '/bridge/' . basename($file));
    }
    copy($root . '/bridge/CORE-VERSION', $rig . '/bridge/CORE-VERSION');
    // the domain ring — the core's libs throw Domain\Error now (STEP 2)
    rigCopyTree($root . '/bridge/domain', $rig . '/bridge/domain');
    // the application ring — commands + queries (STEP 4)
    rigCopyTree($root . '/bridge/application', $rig . '/bridge/application');
    copy($root . '/index.php', $rig . '/index.php');
    copy($root . '/.htaccess', $rig . '/.htaccess');
    foreach (glob($root . '/pagetypes/*.md') ?: [] as $file) {
        copy($file, $rig . '/pagetypes/' . basename($file));
    }
    // the vendored mail library — mail.php requires it at the top
    rigCopyTree($root . '/bridge/lib', $rig . '/bridge/lib');
    // the theme — the house default dresses the rig
    rigCopyTree($root . '/bridge/templates/bakery', $rig . '/bridge/templates/bakery');
    // the assets (the editor/fallback bundles) — the public face links them
    @mkdir($rig . '/bridge/assets', 0775, true);
    foreach (glob($root . '/bridge/assets/*') ?: [] as $file) {
        if (is_file($file)) {
            copy($file, $rig . '/bridge/assets/' . basename($file));
        }
    }

    // the admin account — a known password, so a battery can log in
    $password = $opts['password'] ?? 'rig-test-pw';
    file_put_contents($rig . '/bridge/config/accounts.local.php', "<?php\n\nreturn " . var_export([
        'admin' => [
            'passwordHash' => password_hash($password, PASSWORD_ARGON2ID),
            'role' => 'admin',
            'email' => 'admin@rig.test',
            'epoch' => 0,
        ],
    ], true) . ";\n");
    // the auth template the account store falls back to
    copy($root . '/bridge/config/accounts.php', $rig . '/bridge/config/accounts.php');
    copy($root . '/bridge/config/auth.php', $rig . '/bridge/config/auth.php');
    copy($root . '/bridge/config/site.php', $rig . '/bridge/config/site.php');

    // the site settings — the bakery, the home page
    file_put_contents($rig . '/bridge/config/site.local.php', "<?php\n\nreturn " . var_export([
        'name' => 'Rig',
        'claim' => 'A rig is a test rig.',
        'logo' => '',
        'theme' => 'bakery',
        'home' => 'hello',
        'style' => '',
        'font' => '',
        'styles' => [],
    ], true) . ";\n");

    // the content — a published page and a DRAFT page (the battery proves
    // the draft never rides along)
    rigWritePage($rig, 'hello', "Hello World", false, "The published page.\n");
    rigWritePage($rig, 'secret', 'Secret Draft', true, "Honeyed secret content.\n");

    // the ware under test — the core's own biennstich copy, enabled
    if (($opts['bienenstich'] ?? true) !== false) {
        rigCopyTree($root . '/bridge/plugins/bienenstich', $rig . '/bridge/plugins/bienenstich');
        file_put_contents($rig . '/bridge/config/plugins.local.php', "<?php\n\nreturn " . var_export([
            'bienenstich' => true,
        ], true) . ";\n");
        // the key: the ware's own setting, so the door answers with a key
        file_put_contents($rig . '/bridge/config/plugins.settings.local.php', "<?php\n\nreturn " . var_export([
            'bienenstich' => ['api_key' => 'rig-key-abc123'],
        ], true) . ";\n");
    }

    return $rig;
}

/** One page with its frontmatter. */
function rigWritePage(string $rig, string $slug, string $title, bool $draft, string $body): void
{
    $dir = $rig . "/src/content/pages/{$slug}";
    @mkdir($dir, 0775, true);
    file_put_contents($dir . '/index.md', "---\n"
        . 'title: ' . json_encode($title) . "\n"
        . "slug: {$slug}\n"
        . "published: true\n"
        . 'draft: ' . ($draft ? 'true' : 'false') . "\n"
        . "date: 2026-01-01\n"
        . "tags: []\n"
        . "---\n\n"
        . $body);
}

/** Copy a directory tree. */
function rigCopyTree(string $from, string $to): void
{
    if (!is_dir($from)) {
        return;
    }
    @mkdir($to, 0775, true);
    foreach (scandir($from) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $src = $from . '/' . $entry;
        $dst = $to . '/' . $entry;
        if (is_dir($src)) {
            rigCopyTree($src, $dst);
        } else {
            copy($src, $dst);
        }
    }
}

/** Tear the rig down. */
function rigDestroy(string $rig): void
{
    if (!is_dir($rig)) {
        return;
    }
    foreach (scandir($rig) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $rig . '/' . $entry;
        if (is_dir($path) && !is_link($path)) {
            rigDestroy($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($rig);
}

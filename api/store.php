<?php

/**
 * 1. Relative path: api/store.php
 * 2. Description: Content store. One directory per page, index.md inside.
 *                 PHP stores and serves raw markdown — it NEVER parses it.
 * 3. Expects: Content root ../src/content; strict slug/type patterns.
 * 4. Provides: listAll(), read(), write() with atomic temp+rename, slug discipline.
 * 5. SPDX-License-Identifier: MPL-2.0
 * 6. Copyright (c) 2026 Homahuki GmbH
 */

declare(strict_types=1);

namespace Store;

const TYPE_PATTERN = '[a-z0-9]+(?:-[a-z0-9]+)*';
const SLUG_PATTERN = '[a-z0-9]+(?:-[a-z0-9]+)*';

function root(): string
{
    return dirname(__DIR__) . '/src/content';
}

function pageDir(string $type, string $slug): string
{
    self::assertIdentifier($type, 'type');
    self::assertIdentifier($slug, 'slug');
    return self::root() . '/' . $type . '/' . $slug;
}

function assertIdentifier(string $value, string $what): void
{
    if (preg_match('/^' . \SLUG_PATTERN . '$/', $value) !== 1) {
        Router\refuse(400, "Invalid {$what}: {$value}");
    }
}

/** All pages: {type: [{slug, updatedAt}...]} — existence is truth, frontmatter is TS-side business. */
function listAll(): array
{
    $result = [];
    foreach (scandir(self::root()) ?: [] as $type) {
        $typeDir = self::root() . '/' . $type;
        if (!is_dir($typeDir) || str_starts_with($type, '.')) {
            continue;
        }
        $pages = [];
        foreach (scandir($typeDir) ?: [] as $slug) {
            $file = $typeDir . '/' . $slug . '/index.md';
            if (!is_file($file) || str_starts_with($slug, '.')) {
                continue;
            }
            $pages[] = ['slug' => $slug, 'updatedAt' => filemtime($file)];
        }
        usort($pages, fn($a, $b) => $a['slug'] <=> $b['slug']);
        $result[$type] = $pages;
    }
    return $result;
}

function read(string $type, string $slug): ?string
{
    $file = self::pageDir($type, $slug) . '/index.md';
    if (!is_file($file)) {
        return null;
    }
    $raw = file_get_contents($file);
    return $raw === false ? null : $raw;
}

/** Atomic write: validation → temp file → rename. Never leaves a half-Shmarrn behind. */
function write(string $type, string $slug, string $raw): void
{
    self::assertNonEmpty($raw);
    self::assertDistinctFilenameSlug($type, $slug, $raw);

    $dir = self::pageDir($type, $slug);
    if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
        Router\refuse(500, "Cannot create page directory: {$dir}");
    }

    $tmp = $dir . '/.index.' . bin2hex(random_bytes(6)) . '.tmp';
    if (file_put_contents($tmp, $raw) === false || !rename($tmp, $dir . '/index.md')) {
        @unlink($tmp);
        Router\refuse(500, 'Write failed.');
    }
}

function assertNonEmpty(string $raw): void
{
    if (trim($raw) === '') {
        Router\refuse(400, 'Refusing to save empty page.');
    }
}

/** Slug discipline lives on both sides of the wire: filename is the law here. */
function assertDistinctFilenameSlug(string $type, string $slug, string $raw): void
{
    if (preg_match('#^\s*<!--\s*slug:\s*([a-z0-9-]+)\s*-->\s*$#m', $raw, $m) === 1 && $m[1] !== $slug) {
        Router\refuse(400, "Slug mismatch: URL says '{$slug}', file declares '{$m[1]}'");
    }
}

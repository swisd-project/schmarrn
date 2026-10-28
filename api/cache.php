<?php

/**
 * 1. Relative path: api/cache.php
 * 2. Description: File-based response cache keyed by content mtime sentinel.
 *                 Stores the raw page so repeated public fetches skip disk hits on the source.
 * 3. Expects: Writable api/cache/ directory. Automad-pattern: serve if sentinel matches.
 * 4. Provides: isFresh(), emit(), drop().
 * 5. SPDX-License-Identifier: MPL-2.0
 * 6. Copyright (c) 2026 Homahuki GmbH
 */

declare(strict_types=1);

namespace Cache;

// Library files stand alone: each requires its dependencies explicitly.
require_once __DIR__ . '/store.php';

const TTL = 43200; // 12 h — same coquettish restraint Automad showed me.

function dir(): string
{
    return __DIR__ . '/cache';
}

function files(string $type, string $slug): array
{
    return [
        'body'   => dir() . "/{$type}-{$slug}.md",
        'sentinel' => dir() . "/{$type}-{$slug}.meta",
    ];
}

function isFresh(string $type, string $slug): bool
{
    ["body" => $body, "sentinel" => $sentinel] = files($type, $slug);
    if (!is_file($body) || !is_file($sentinel)) {
        return false;
    }
    $sourceMtime = @filemtime(\Store\pageDir($type, $slug) . '/index.md');
    if ($sourceMtime === false || time() - (int)@filemtime($body) > TTL) {
        return false;
    }
    return (int)file_get_contents($sentinel) === $sourceMtime;
}

/** Write body + mtime sentinel in one breath. */
function store(string $type, string $slug, string $raw): void
{
    ["body" => $body, "sentinel" => $sentinel] = files($type, $slug);
    $sourceMtime = @filemtime(\Store\pageDir($type, $slug) . '/index.md');
    if ($sourceMtime === false) {
        return;
    }
    if (!is_dir(dir())) {
        @mkdir(dir(), 0775, true);
    }
    file_put_contents($sentinel, (string)$sourceMtime);
    file_put_contents($body, $raw);
}

/** Stream the cached body for our GET route. Raw passthrough, already validated at write. */
function emit(string $type, string $slug): void
{
    readfile(files($type, $slug)['body']);
}

function drop(string $type, string $slug): void
{
    foreach (files($type, $slug) as $file) {
        @unlink($file);
    }
    dropHtml($type, $slug);
}

// ——— rendered-HTML cache: the editor's browser renders at save time (GOSPEL: no
// ——— Markdown parsing in PHP); PHP only stores, staleness-checks and serves.

function htmlDir(): string
{
    return dir() . '/html';
}

function htmlFiles(string $type, string $slug): array
{
    return [
        'body' => htmlDir() . "/{$type}-{$slug}.html",
        'meta' => htmlDir() . "/{$type}-{$slug}.meta",
    ];
}

/** Store the browser-rendered body HTML. Atomic, with the source mtime as sentinel. */
function storeHtml(string $type, string $slug, string $html): void
{
    \Store\assertIdentifier($type, 'type');
    \Store\assertIdentifier($slug, 'slug');
    $sourceMtime = @filemtime(\Store\pageDir($type, $slug) . '/index.md');
    if ($sourceMtime === false) {
        return;
    }
    if (!is_dir(htmlDir())) {
        @mkdir(htmlDir(), 0775, true);
    }
    ["body" => $body, "meta" => $meta] = htmlFiles($type, $slug);
    $tmp = $body . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (file_put_contents($tmp, $html) === false || !\rename($tmp, $body)) {
        @unlink($tmp);
        return;
    }
    file_put_contents($meta, (string)$sourceMtime);
}

/** The cached body HTML, or null when absent. */
function html(string $type, string $slug): ?string
{
    ["body" => $body, "meta" => $meta] = htmlFiles($type, $slug);
    if (!is_file($body) || !is_file($meta)) {
        return null;
    }
    $contents = file_get_contents($body);
    return $contents === false ? null : $contents;
}

/** True when the source .md is newer than the stored render (FTP edits land here). */
function htmlStale(string $type, string $slug): bool
{
    ["body" => $body, "meta" => $meta] = htmlFiles($type, $slug);
    if (!is_file($body) || !is_file($meta)) {
        return true;
    }
    $sourceMtime = @filemtime(\Store\pageDir($type, $slug) . '/index.md');
    if ($sourceMtime === false) {
        return true;
    }
    return (int)file_get_contents($meta) !== $sourceMtime;
}

function dropHtml(string $type, string $slug): void
{
    foreach (htmlFiles($type, $slug) as $file) {
        @unlink($file);
    }
}

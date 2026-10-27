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
}

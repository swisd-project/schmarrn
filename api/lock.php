<?php

/**
 * 1. Relative path: api/lock.php
 * 2. Description: Coarse per-page edit lock — one editor at a time, no distributed math.
 *                 Lock lives in the file cache; TTL heartbeat keeps it warm, silence frees it.
 * 3. Expects: Writable api/cache/ dir.
 * 4. Provides: peek(), acquire(), assertFreeOrMine(), release().
 * 5. SPDX-License-Identifier: MPL-2.0
 * 6. Copyright (c) 2026 Homahuki GmbH
 */

declare(strict_types=1);

namespace Lock;

const TTL = 120; // s — heartbeat must arrive before the lock goes cold.

function fileFor(string $type, string $slug): string
{
    return __DIR__ . '/cache/locks/' . $type . '-' . $slug . '.json';
}

function record(string $type, string $slug): ?array
{
    $file = fileFor($type, $slug);
    if (!is_file($file)) {
        return null;
    }
    $record = json_decode((string)file_get_contents($file), true);
    if (!is_array($record) || ($record['exp'] ?? 0) < time()) {
        @unlink($file);
        return null;
    }
    return $record;
}

/** {holder: string|null, expiresIn: int} */
function peek(string $type, string $slug): array
{
    $record = record($type, $slug);
    return $record === null
        ? ['holder' => null, 'expiresIn' => 0]
        : ['holder' => (string)$record['user'], 'expiresIn' => (int)$record['exp'] - time()];
}

/** Warm (or take) the lock. Refuses with who the tea belongs to when held and not owned. */
function acquire(string $type, string $slug, string $user, bool $force = false): void
{
    $record = record($type, $slug);
    if ($record !== null && $record['user'] !== $user && !$force) {
        \Router\refuse(409, "Page locked by {$record['user']}. Take over or wait.");
        return;
    }
    $file = fileFor($type, $slug);
    @mkdir(dirname($file), 0775, true);
    $tmp = $file . '.tmp';
    $fresh = json_encode(['user' => $user, 'exp' => time() + TTL]);
    if (file_put_contents($tmp, $fresh) === false || !rename($tmp, $file)) {
        @unlink($tmp);
        \Router\refuse(500, 'Cannot store lock.');
    }
    if ($force && $record !== null && $record['user'] !== $user) {
        @error_log("[schmarrn-lock] {$user} took over {$type}/{$slug} from {$record['user']}");
    }
}

/** write-gate: the holder passes (and bakes on); strangers eat the 409. */
function assertFreeOrMine(string $type, string $slug, string $user): void
{
    $record = record($type, $slug);
    if ($record !== null && $record['user'] !== $user) {
        \Router\refuse(409, "Page locked by {$record['user']}. Take over or wait.");
    }
}

function release(string $type, string $slug, string $user): void
{
    $file = fileFor($type, $slug);
    $record = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
    if (is_array($record) && ($record['user'] ?? '') === $user) {
        @unlink($file);
    }
}

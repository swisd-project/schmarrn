<?php

/**
 * 1. Relative path: bridge/application/commands/page.php
 * 2. Description: THE PAGE COMMANDS — the write side of the md-only store.
 *                 Every command validates, writes, and invalidates the
 *                 projections itself. Before this layer the cache drop was
 *                 the CALLER's job, scattered over seven route sites — a
 *                 new write path that forgot it served stale HTML forever.
 *                 Now the rule is enforced by construction: a command that
 *                 forgets its invalidation is a bug you can see in one file.
 * 3. Expects: The Store and Cache adapters (required once, below).
 * 4. Provides: PageCommand\create, saveDraft, publish, unpublish, rename, remove.
 * 5. SPDX-License-Identifier: MPL-2.0
 * 6. Copyright (c) 2026 Homahuki GmbH
 */

declare(strict_types=1);

namespace PageCommand;

require_once __DIR__ . '/../../store.php';
require_once __DIR__ . '/../../cache.php';
require_once __DIR__ . '/../../lock.php';
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../domain/error.php';
require_once __DIR__ . '/../../domain/leash.php';

/**
 * Create a fresh page. 409 when the slug is taken — the check is the
 * command's own law, not the route's.
 */
function create(string $type, string $slug, string $raw): void
{
    if (\Store\read($type, $slug) !== null) {
        throw new \Domain\Error(409, "Page already exists: {$type}/{$slug}");
    }
    \Lock\assertFreeOrMine($type, $slug, \Auth\user());
    \Store\write($type, $slug, $raw);
    \Cache\drop($type, $slug);
}

/**
 * Autosave-to-draft. Lock-gated, hash-guarded; returns the new fingerprint.
 */
function saveDraft(string $type, string $slug, string $raw, ?string $baseHash): string
{
    \Lock\assertFreeOrMine($type, $slug, \Auth\user());
    $hash = \Store\writeDraft($type, $slug, $raw, $baseHash);
    \Lock\acquire($type, $slug, \Auth\user());
    return $hash;
}

/**
 * Save the PUBLISHED file directly (the raw desk's save). Lock-gated; the
 * writer owns the lock afterwards — kitchen etiquette.
 */
function save(string $type, string $slug, string $raw): void
{
    \Lock\assertFreeOrMine($type, $slug, \Auth\user());
    \Store\write($type, $slug, $raw);
    \Cache\drop($type, $slug);
    \Lock\acquire($type, $slug, \Auth\user());
}

/**
 * Publish: the sidecar becomes the page, the projections refresh. The
 * browser-rendered body rides along (the GOSPEL serving law: the editor's
 * browser renders, PHP stores bytes) — the editor's publish is leashed,
 * the admin's is not.
 */
function publish(string $type, string $slug, string $bodyHtml, bool $isAdmin): void
{
    \Lock\assertFreeOrMine($type, $slug, \Auth\user());
    \Store\publish($type, $slug);
    \Cache\drop($type, $slug); // raw + HTML caches (drop() sweeps both)
    if ($bodyHtml !== '') {
        \Cache\storeHtml($type, $slug, $isAdmin ? $bodyHtml : \Leash\stripExecutableHtml($bodyHtml));
    }
    \Lock\acquire($type, $slug, \Auth\user());
}

/** Unpublish: the published copy becomes the editorial copy, hidden. */
function unpublish(string $type, string $slug): void
{
    \Lock\assertFreeOrMine($type, $slug, \Auth\user());
    \Store\unpublish($type, $slug);
    \Cache\drop($type, $slug);
}

/** Rename: content untouched, both projections dropped. */
function rename(string $type, string $slug, string $to): string
{
    \Lock\assertFreeOrMine($type, $slug, \Auth\user());
    \Store\move($type, $slug, $to);
    \Cache\drop($type, $slug);
    \Cache\drop($type, $to);
    return $to;
}

/**
 * Delete: the home page stands (the crown's law), the lock is honoured,
 * the projections go with the page.
 */
function remove(string $type, string $slug, string $homeSlug): void
{
    if ($type === 'pages' && $slug === $homeSlug) {
        throw new \Domain\Error(409, 'The home page stands — the index page cannot be deleted.');
    }
    \Lock\assertFreeOrMine($type, $slug, \Auth\user());
    \Store\remove($type, $slug);
    \Cache\drop($type, $slug);
}

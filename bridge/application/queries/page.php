<?php

/**
 * 1. Relative path: bridge/application/queries/page.php
 * 2. Description: THE PAGE QUERIES — the read side of the md-only store.
 *                 Pure readers over the store and the projections; they never
 *                 write, never invalidate, never decide what the caller may
 *                 see beyond what the caller's VIEW says. A query answers a
 *                 value array, not an HTTP response.
 * 3. Expects: The Store, Site and Serve adapters (required once, below).
 * 4. Provides: PageQuery\index, one, publicOne, search.
 * 5. SPDX-License-Identifier: MPL-2.0
 * 6. Copyright (c) 2026 Homahuki GmbH
 */

declare(strict_types=1);

namespace PageQuery;

require_once __DIR__ . '/../../store.php';
require_once __DIR__ . '/../../site.php';
require_once __DIR__ . '/../../serve.php';
require_once __DIR__ . '/../projections/html.php';
require_once __DIR__ . '/../projections/index.php';

/**
 * The page index for the desk: every page of every type, with titles and
 * the home crown. The caller passes the VIEW (whether drafts are visible) —
 * the query never reads the session itself, so the answer depends only on
 * what was asked, not who happened to be logged in.
 */
function index(bool $includeDrafts): array
{
    return \Projection\Index\all($includeDrafts);
}

/**
 * One page's raw bytes — 404 when the page is a ghost or a draft the view
 * may not see. The raw markdown, body passthrough (not JSON).
 */
function one(string $type, string $slug, bool $includeDrafts): string
{
    $raw = \Store\read($type, $slug);
    if ($raw === null || (! $includeDrafts && \Store\isDraft($type, $slug))) {
        throw new \Domain\Error(404, "Page not found: {$type}/{$slug}");
    }
    return $raw;
}

/**
 * The public JSON API's per-page answer: title, rendered body HTML (or null
 * when the cache is stale), sibling pages, page-local files. Drafts never
 * ride along — the public face answers the same to every hand.
 */
function publicOne(string $type, string $slug): array
{
    if (\Store\read($type, $slug) === null || \Store\isDraft($type, $slug)) {
        throw new \Domain\Error(404, "Page not found: {$type}/{$slug}");
    }
    $bodyHtml = \Projection\Html\body($type, $slug);
    $children = [];
    foreach (\Store\listAll()[$type] ?? [] as $sibling) {
        if (($sibling['slug'] ?? '') !== $slug && !($sibling['draft'] ?? false)) {
            $childSlug = (string)$sibling['slug'];
            $children[] = ['slug' => $childSlug, 'title' => \Serve\titleOf($type, $childSlug, $childSlug)];
        }
    }
    $files = [];
    foreach (\Store\mediaList($type, $slug) as $file) {
        $files[] = [
            'name' => $file['name'],
            'size' => $file['size'],
            'url' => "/bridge/media/{$type}/{$slug}/" . rawurlencode((string)$file['name']),
        ];
    }
    return [
        'type' => $type,
        'slug' => $slug,
        'title' => \Serve\titleOf($type, $slug, $slug),
        'bodyHtml' => $bodyHtml,
        'children' => $children,
        'files' => $files,
    ];
}

/** The desk's text search — authed callers only (it reads drafts). */
function search(string $q): array
{
    if (trim($q) === '') {
        throw new \Domain\Error(400, 'The search needs a needle — q cannot be empty.');
    }
    return \Store\search($q);
}

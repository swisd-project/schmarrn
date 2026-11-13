<?php

/**
 * 1. Relative path: bridge/application/projections/index.php
 * 2. Description: THE PAGE INDEX — the site's second read model. Every page
 *                 of every type with its facts (title, tags, draft, home
 *                 crown), derived from the md tree by byte-peek. This module
 *                 NAMES the read model: the queries read it, the commands
 *                 invalidate it (Store\forget() on every mutation), and the
 *                 view (whether drafts are visible) rides in explicitly —
 *                 the read side never reads the session itself.
 * 3. Expects: The Store, Site and Serve adapters (required once, below).
 * 4. Provides: Projection\Index\all() — the full tree with titles + crown.
 * 5. SPDX-License-Identifier: MPL-2.0
 * 6. Copyright (c) 2026 Homahuki GmbH
 */

declare(strict_types=1);

namespace Projection\Index;

require_once __DIR__ . '/../../store.php';
require_once __DIR__ . '/../../site.php';
require_once __DIR__ . '/../../serve.php';

/**
 * The full page index: {type: [{slug, updatedAt, draft, hasDraft, tags,
 * title, home?}...]}. $includeDrafts is the VIEW — explicit, never ambient.
 */
function all(bool $includeDrafts): array
{
    $tree = \Store\listAll($includeDrafts);
    foreach ($tree as $type => $pages) {
        foreach ($pages as $i => $p) {
            $tree[$type][$i]['title'] = \Serve\titleOf((string)$type, (string)$p['slug'], (string)$p['slug']);
        }
    }
    $home = \Serve\homeSlug(\Site\load());
    if ($home !== '' && isset($tree['pages'])) {
        foreach ($tree['pages'] as $i => $p) {
            if (($p['slug'] ?? null) === $home) {
                $tree['pages'][$i]['home'] = true;
            }
        }
    }
    return $tree;
}

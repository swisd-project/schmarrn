<?php

/**
 * 1. Relative path: bridge/application/projections/html.php
 * 2. Description: THE HTML PROJECTION — the site's first read model. The
 *                 editor's browser renders the page at publish (the GOSPEL
 *                 serving law: PHP never parses Markdown), the bytes land
 *                 here keyed by the source mtime, and every public serve
 *                 reads them back. A stale render answers null — the visitor's
 *                 browser picks up the slack via the fallback shell. This
 *                 module NAMES the read model; the file cache underneath is
 *                 an implementation the commands invalidate.
 * 3. Expects: The Cache adapter (required once, below).
 * 4. Provides: Projection\Html\body() — the rendered body, or null when stale.
 * 5. SPDX-License-Identifier: MPL-2.0
 * 6. Copyright (c) 2026 Homahuki GmbH
 */

declare(strict_types=1);

namespace Projection\Html;

require_once __DIR__ . '/../../cache.php';

/** The rendered body of a page — null when the projection is stale or absent.
 *  Staleness first, body second (the optimization pass): a stale render
 *  costs the mtime peek only, never a wasted body read. */
function body(string $type, string $slug): ?string
{
    if (\Cache\htmlStale($type, $slug)) {
        return null;
    }
    return \Cache\html($type, $slug);
}

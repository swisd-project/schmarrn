<?php

/**
 * 1. Relative path: bridge/application/commands/meta-repair.php
 * 2. Description: THE FRONTMATTER REPAIR SERVICE (STEP 5, user order) —
 *                 callable on demand, never silent. It reads a page's raw
 *                 bytes (or every page in the tree), reports every defect,
 *                 repairs what is safely repairable, writes the repaired
 *                 bytes atomically, and answers the user with the per-page
 *                 list of what was wrong and what was changed.
 * 3. Expects: The Meta domain module + the Store adapter.
 * 4. Provides: MetaRepair\run(type, slug, scope) — the report is the answer.
 * 5. SPDX-License-Identifier: MPL-2.0
 * 6. Copyright (c) 2026 Homahuki GmbH
 */

declare(strict_types=1);

namespace MetaRepair;

require_once __DIR__ . '/../../domain/meta.php';
require_once __DIR__ . '/../../store.php';

/**
 * Run the repair over one page or the whole tree.
 *
 * @return array{scope: string, pages: list<array{type: string, slug: string,
 *                repaired: bool, report: list<string>}>}
 */
function run(string $type, string $slug, string $scope): array
{
    $pages = $scope === 'page'
        ? [['type' => $type, 'slug' => $slug]]
        : tree();

    $out = ['scope' => $scope, 'pages' => []];
    foreach ($pages as $page) {
        $out['pages'][] = repairOne((string)$page['type'], (string)$page['slug']);
    }
    return $out;
}

/** Every page in the content tree (the repair walks the whole bakery). */
function tree(): array
{
    $out = [];
    foreach (\Store\listAll(true) as $type => $pages) {
        foreach ($pages as $page) {
            $out[] = ['type' => (string)$type, 'slug' => (string)$page['slug']];
        }
    }
    return $out;
}

/** One page: repair, write, report. A page that is not there answers 404. */
function repairOne(string $type, string $slug): array
{
    $raw = \Store\read($type, $slug);
    if ($raw === null) {
        throw new \Domain\Error(404, "Page not found: {$type}/{$slug}");
    }
    $answer = \Meta\repair($raw, $slug);
    if ($answer['repaired']) {
        // The repair rides the store's own atomic write — the gate accepts
        // the repaired shape by construction (that is what repair is for).
        \Store\write($type, $slug, $answer['raw']);
    }
    return [
        'type' => $type,
        'slug' => $slug,
        'repaired' => $answer['repaired'],
        'report' => $answer['report'],
    ];
}

<?php

/**
 * 1. Relative path: scripts/repair-frontmatter.php
 * 2. Description: THE FRONTMATTER REPAIR SERVICE, CLI door — the same service
 *                 the dashboard's POST /bridge/meta/repair rides, callable
 *                 from the operator's shell over the WHOLE tree (or one page
 *                 with --type and --slug). Never silent: every page's report
 *                 prints, and the exit code says whether anything moved.
 * 3. Expects: The repo root (cwd, or --root <dir>).
 * 4. Provides: The repair report on stdout; exit 0 (clean or repaired), 1 on
 *                 a failure.
 * 5. SPDX-License-Identifier: MPL-2.0
 * 6. Copyright (c) 2026 Homahuki GmbH
 */

declare(strict_types=1);

$root = getcwd() ?: '.';
$type = '';
$slug = '';
$argv = array_slice($argv, 1);
foreach ($argv as $i => $arg) {
    if ($arg === '--root' && isset($argv[$i + 1])) {
        $root = $argv[$i + 1];
    } elseif ($arg === '--type' && isset($argv[$i + 1])) {
        $type = $argv[$i + 1];
    } elseif ($arg === '--slug' && isset($argv[$i + 1])) {
        $slug = $argv[$i + 1];
    }
}

require_once $root . '/bridge/application/commands/meta-repair.php';

$scope = ($type !== '' && $slug !== '') ? 'page' : 'tree';
$answer = \MetaRepair\run($type, $slug, $scope);

$repaired = 0;
$clean = 0;
foreach ($answer['pages'] as $page) {
    $label = "{$page['type']}/{$page['slug']}";
    if ($page['repaired']) {
        $repaired++;
        echo "REPAIRED  {$label}\n";
        foreach ($page['report'] as $line) {
            echo "    - {$line}\n";
        }
    } else {
        $clean++;
        echo "clean     {$label} — {$page['report'][0]}\n";
    }
}

echo "\nrepair: {$repaired} repaired, {$clean} clean, scope={$scope}\n";
exit(0);

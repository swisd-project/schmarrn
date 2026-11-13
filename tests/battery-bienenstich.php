<?php

/**
 * 1. Relative path: tests/battery-bienenstich.php
 * 2. Description: The THIN WRAPPER (the ware-battery hook ride, 2026-11-13):
 *                 the Bienenstich wall is the WARE's law now — its cases live
 *                 in the ware's repo (plugins/bienenstich/battery.php in the
 *                 Vitrine checkout) and ride the core's hook
 *                 (tests/ware-battery.php). This file only points the runner
 *                 at the host's ware and passes the verdict through. The ware
 *                 is REPO-EXTERNAL by law (the Vitrine pours it; the base
 *                 keeps only hello tracked) — on a host without the ware (or
 *                 its battery) there is nothing to prove, and the battery
 *                 says so instead of dying.
 * 3. Expects: The sibling Vitrine checkout (../10_Vitrine), or wherever the
 *                 ware is installed. PHP 8.4+.
 * 4. Provides: The runner's exit code — GREEN/RED for the suite.
 * 5. SPDX-License-Identifier: MPL-2.0
 * 6. Copyright (c) 2026 Homahuki GmbH
 */

declare(strict_types=1);

require_once __DIR__ . '/harness.php';

// the ware's home: the host's installed copy first (what the rig would pour
// anyway), then the sibling Vitrine checkout
$root = dirname(__DIR__);
$candidates = [
    $root . '/bridge/plugins/bienenstich',
    dirname($root, 1) . '/10_Vitrine/plugins/bienenstich',
];
$ware = '';
foreach ($candidates as $candidate) {
    if (is_file($candidate . '/plugin.json') && is_file($candidate . '/battery.php')) {
        $ware = $candidate;
        break;
    }
}
if ($ware === '') {
    echo "SKIP  no bienenstich ware with a battery on this host (repo-external by law) — nothing to prove\n";
    exit(0);
}

passthru(PHP_BINARY . ' ' . escapeshellarg(__DIR__ . '/ware-battery.php') . ' ' . escapeshellarg($ware), $code);
exit($code === 0 ? 0 : 1);

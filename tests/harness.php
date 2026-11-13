<?php

/**
 * 1. Relative path: tests/harness.php
 * 2. Description: The shared verdict harness for every battery. A case is
 *                 either a MUST-PASS (check) or a KNOWN DEFECT (xfail) — the
 *                 known defects are real bugs in the core, found by these
 *                 batteries, waiting for the user's word. They are printed
 *                 loudly on every run and do NOT block the ride; a MUST-PASS
 *                 case that starts failing stops everything.
 * 3. Expects: Nothing.
 * 4. Provides: check(), xfail(), the verdict line, and the exit code.
 * 5. SPDX-License-Identifier: MPL-2.0
 * 6. Copyright (c) 2026 Homahuki GmbH
 */

declare(strict_types=1);

final class Harness
{
    public static int $pass = 0;
    public static int $fail = 0;
    /** @var array<int, array{name: string, detail: string}> */
    public static array $defects = [];
    /** @var array<int, string> */
    public static array $results = [];

    /** A case that MUST hold. A failure here stops the ride. */
    public static function check(string $name, bool $ok, string $detail = ''): void
    {
        if ($ok) {
            self::$pass++;
            self::$results[] = "PASS  {$name}";
            return;
        }
        self::$fail++;
        self::$results[] = "FAIL  {$name}" . ($detail !== '' ? " — {$detail}" : '');
    }

    /**
     * A KNOWN DEFECT — the case does not hold today, and that is a known,
     * recorded truth (a real bug in the core, awaiting the user's word).
     * The verdict is printed as a DEFECT, never as a silent pass: when the
     * core is fixed, this case flips to a MUST-PASS and the battery tells
     * the truth again.
     */
    public static function xfail(string $name, bool $stillBroken, string $detail = ''): void
    {
        if ($stillBroken) {
            self::$pass++;
            self::$defects[] = ['name' => $name, 'detail' => $detail];
            self::$results[] = "DEFECT  {$name}" . ($detail !== '' ? " — {$detail}" : '')
                . "  [known defect — does not block the ride]";
            return;
        }
        // The defect is GONE — the case now holds. It must become a MUST-PASS:
        // the battery says so out loud, so nobody forgets to promote it.
        self::$fail++;
        self::$results[] = "STALE  {$name} — the defect is FIXED; promote this case to check()";
    }

    /** The verdict: the lines, the defect list, the count, the exit code. */
    public static function verdict(string $label): never
    {
        echo implode("\n", self::$results), "\n";
        if (self::$defects !== []) {
            echo "\n  KNOWN DEFECTS (real bugs in the core — the user's word is pending):\n";
            foreach (self::$defects as $d) {
                echo "   - {$d['name']}" . ($d['detail'] !== '' ? " — {$d['detail']}" : '') . "\n";
            }
        }
        echo "\n{$label}: " . self::$pass . ' pass, ' . self::$fail . " fail, "
            . count(self::$defects) . " known defects\n";
        exit(self::$fail === 0 ? 0 : 1);
    }
}

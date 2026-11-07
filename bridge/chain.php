<?php

/**
 * 1. Relative path: bridge/chain.php
 * 2. Description: THE CHAIN LAW (2026-11-07, user law) — wares dependencies
 *                 that cannot silently break. A ware declares its own
 *                 requirements IN ITS MANIFEST (plugin.json / theme.json);
 *                 the manifest rides onto the host, so the truth travels with
 *                 the ware and no registry rewrite, no lost lock line, no
 *                 forgotten bump can evaporate it. The registry and the lock
 *                 only MIRROR — they are never the enforced truth.
 *                 Entry shape: "name" or "name >=1.2" (version_compare ops
 *                 >=, <=, >, <, ==, =; bare name = any version). Anything
 *                 else is dough the gate refuses — a malformed requires list
 *                 is a broken manifest, not a hint.
 *                 Effective state is DERIVED, never stored: intent (the
 *                 user's switch) ∧ dependencies effective at a satisfying
 *                 version, walked to a fixpoint. Orphaned enable-states are
 *                 unconstructible; rings never promote — they refuse at the
 *                 switch or sit visibly blocked, never silently active.
 * 3. Expects: nothing — pure functions over arrays.
 * 4. Provides: Chain\entry/parse/describe/pool/unmet.
 * 5. SPDX-License-Identifier: MPL-2.0
 * 6. Copyright (c) 2026 Homahuki GmbH
 */

declare(strict_types=1);

namespace Chain;

const ENTRY = '/^([a-z0-9]+(?:-[a-z0-9]+)*)\s*(?:(>=|<=|>|<|==|=)\s*(\d+(?:\.\d+){0,2}))?$/';

/** One entry string → {name, op, version} | null (unbaked dough). */
function entry(string $raw): ?array
{
    $raw = trim($raw);
    if (preg_match(ENTRY, $raw, $m) !== 1) {
        return null;
    }
    $op = (string)($m[2] ?? '');
    return [
        'name' => (string)$m[1],
        'op' => $op === '=' ? '==' : $op,
        'version' => (string)($m[3] ?? ''),
    ];
}

/** A manifest's requires value → clean entries; null when the shape itself is broken. */
function parse(mixed $raw): ?array
{
    if (!is_array($raw)) {
        return null;
    }
    $out = [];
    foreach ($raw as $item) {
        if (!is_string($item)) {
            return null;
        }
        $e = entry($item);
        if ($e === null) {
            return null;
        }
        $out[] = $e;
    }
    return $out;
}

/** The human form of one requirement — what refusals and shelves name. */
function describe(array $req): string
{
    return $req['version'] !== ''
        ? "{$req['name']} {$req['op']} {$req['version']}"
        : $req['name'];
}

/**
 * The pool a requirement is judged against: name => version map plus the set
 * of names that count as PRESENT/effective. unmet() reads this shape.
 */
function pool(array $versions, array $present): array
{
    $out = [];
    foreach ($versions as $name => $version) {
        $out[$name] = [
            'version' => (string)$version,
            'effective' => isset($present[$name]) || in_array($name, $present, true),
        ];
    }
    return $out;
}

/**
 * The gate's answer: which requirements are UNMET, as human strings.
 * A requirement is met only when the dependency is present/effective AND its
 * version satisfies the constraint. An empty answer lets the ware through.
 */
function unmet(array $requires, array $pool): array
{
    $out = [];
    foreach ($requires as $req) {
        $dep = $pool[$req['name']] ?? null;
        if ($dep === null || ($dep['effective'] ?? false) !== true) {
            $out[] = describe($req);
            continue;
        }
        if ($req['version'] !== ''
            && version_compare((string)$dep['version'], $req['version'], $req['op']) === false) {
            $out[] = describe($req) . ' (installed: ' . (string)$dep['version'] . ')';
        }
    }
    return $out;
}

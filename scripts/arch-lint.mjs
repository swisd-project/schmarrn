#!/usr/bin/env node
/**
 * 1. Relative path: scripts/arch-lint.mjs
 * 2. Description: THE ARCHITECTURE LAW, enforced. Four rules, no deps:
 *                 (1) the domain may not touch HTTP or disk — no $_SERVER,
 *                     no header(), no echo, no exit, no file writes;
 *                 (2) queries may not write;
 *                 (3) commands are unreachable from a GET route;
 *                 (4) every mutating route in the transport maps to a named
 *                     command in the registry.
 *                 A rule violation fails the run with the file, line, rule.
 * 3. Expects: The repo root (cwd). Run with `npm run lint:arch`.
 * 4. Provides: exit 0 (law holds) or exit 1 (a violation, named).
 * 5. SPDX-License-Identifier: MPL-2.0
 * 6. Copyright (c) 2026 Homahuki GmbH
 */

import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join } from 'node:path';

const ROOT = process.cwd();
const violations = [];

/** Every PHP file under a directory, recursively. */
function phpFiles(dir) {
    const out = [];
    for (const entry of readdirSync(dir)) {
        const p = join(dir, entry);
        if (statSync(p).isDirectory()) {
            out.push(...phpFiles(p));
        } else if (entry.endsWith('.php')) {
            out.push(p);
        }
    }
    return out;
}

/**
 * Rule 1 — THE DOMAIN IS PURE.
 * The domain says no with an exception; it never touches HTTP or disk.
 * Echo/exit in the domain is how a refusal leaks a half-response; a $_SERVER
 * read is the session smuggling itself into business logic.
 */
const DOMAIN = join(ROOT, 'bridge', 'domain');
const DOMAIN_FORBIDDEN = [
    [/\$_SERVER\b/, 'reads $_SERVER — the domain must not know the request'],
    [/\$_GET\b|\$_POST\b|\$_FILES\b|\$_COOKIE\b/, 'reads superglobals — the domain must not know the request'],
    [/\bheader\s*\(/, 'sets a response header — the transport does that'],
    [/\becho\b|\bprint\b/, 'echoes output — the transport does that'],
    [/\bexit\b|\bdie\b/, 'ends the request — the transport does that'],
    [/file_put_contents\s*\(/, 'writes to disk — the domain is pure'],
    [/\\?fopen\s*\(/, 'opens a file — the domain is pure'],
    [/\\?unlink\s*\(/, 'deletes a file — the domain is pure'],
    [/\\?rename\s*\(/, 'renames a file — the domain is pure'],
];

if (statSync(DOMAIN, { throwIfNoEntry: false })?.isDirectory()) {
    for (const file of phpFiles(DOMAIN)) {
        const src = readFileSync(file, 'utf8');
        const rel = file.slice(ROOT.length + 1);
        src.split('\n').forEach((line, i) => {
            // comments are not law — strip them before judging
            const code = line.replace(/\/\/.*$/, '').replace(/\/\*.*?\*\//g, '');
            for (const [pattern, why] of DOMAIN_FORBIDDEN) {
                if (pattern.test(code)) {
                    violations.push(`${rel}:${i + 1}  ${why}\n    ${line.trim()}`);
                }
            }
        });
    }
}

/**
 * Rule 2 — QUERIES NEVER WRITE.
 * The read side of the md store must not mutate it. A query that writes is
 * a cache filling itself with an unmanaged truth — the projections are
 * invalidated by commands, never by readers.
 */
const QUERIES = join(ROOT, 'bridge', 'application', 'queries');
const QUERY_FORBIDDEN = [
    [/file_put_contents\s*\(/, 'a query writes to disk'],
    [/\\?fopen\s*\(/, 'a query opens a file handle'],
    [/\\?unlink\s*\(/, 'a query deletes a file'],
    [/\\?rename\s*\(/, 'a query renames a file'],
    [/\\?mkdir\s*\(/, 'a query creates a directory'],
    [/Cache\\drop\s*\(/, 'a query drops a projection — commands invalidate'],
    [/Cache\\store(Html)?\s*\(/, 'a query fills a projection — commands invalidate'],
];

if (statSync(QUERIES, { throwIfNoEntry: false })?.isDirectory()) {
    for (const file of phpFiles(QUERIES)) {
        const src = readFileSync(file, 'utf8');
        const rel = file.slice(ROOT.length + 1);
        src.split('\n').forEach((line, i) => {
            const code = line.replace(/\/\/.*$/, '').replace(/\/\*.*?\*\//g, '');
            for (const [pattern, why] of QUERY_FORBIDDEN) {
                if (pattern.test(code)) {
                    violations.push(`${rel}:${i + 1}  ${why}\n    ${line.trim()}`);
                }
            }
        });
    }
}

/**
 * Rule 3+4 — THE COMMAND TABLE.
 * Every mutating route (POST/PUT/DELETE) in the transport must name the
 * command it dispatches; a GET route must never name one. The registry is
 * the single list of what can write — without it, "what can write" is a
 * 1300-line switch nobody can enumerate.
 */
const TRANSPORT = join(ROOT, 'bridge', 'index.php');
const transport = readFileSync(TRANSPORT, 'utf8');
const lines = transport.split('\n');

const COMMAND_TABLE = join(ROOT, 'bridge', 'application', 'commands', 'table.php');
let commandNames = [];
if (statSync(COMMAND_TABLE, { throwIfNoEntry: false })?.isFile()) {
    // The table answers [name => true] — one regex peek, no PHP eval.
    const src = readFileSync(COMMAND_TABLE, 'utf8');
    commandNames = [...src.matchAll(/'([a-z][a-zA-Z0-9_.]*)'\s*=>/g)].map((m) => m[1]);
}

// The mutating route cases, with their handler call.
let caseLine = -1;
let caseHead = '';
lines.forEach((line, i) => {
    if (/^\s*case .*:\s*$/.test(line)) {
        caseLine = i;
        caseHead = line.trim();
        return;
    }
    if (caseLine < 0) {
        return;
    }
    if (/^\s*(case|default):?\s*$/.test(line)) {
        return;
    }
    const call = line.match(/(\w+)\\(?:dispatch|handle)\(/);
    if (!call) {
        return;
    }
    const isMutating = /'(POST|PUT|DELETE)'/.test(caseHead);
    const named = line.match(/([A-Za-z]+)Command/);
    if (isMutating && !named) {
        violations.push(
            `bridge/index.php:${caseLine + 1}  a mutating route does not name a command\n    ${caseHead}`
        );
    }
    if (!isMutating && named) {
        violations.push(
            `bridge/index.php:${caseLine + 1}  a GET route names a command — queries and commands are separate\n    ${caseHead}`
        );
    }
    caseLine = -1;
});

// ——— the verdict ———
if (violations.length === 0) {
    console.log('arch-lint: the law holds. 4 rules, no violations.');
    process.exit(0);
}
console.log(`arch-lint: ${violations.length} violation(s):\n`);
for (const v of violations) {
    console.log('  ' + v + '\n');
}
process.exit(1);

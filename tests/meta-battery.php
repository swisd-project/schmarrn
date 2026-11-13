<?php

/**
 * 1. Relative path: tests/meta-battery.php
 * 2. Description: THE FRONTMATTER CONTRACT BATTERY — pins Meta\peek()'s reads.
 *                 Found 2026-11-13: the visual editor's yaml serializer writes
 *                 tags: [moops, test] — BARE flow-array, valid YAML, garbage
 *                 JSON — and the old tagsOf() read the line with json_decode,
 *                 so the listAll view silently lost the tags (the desk's tag
 *                 filter stayed empty even after publish). The reader is now
 *                 tolerant per-item (quoted through JSON, bare as itself).
 * 3. Expects: bridge/domain/meta.php on the repo (no HTTP, no disk).
 * 4. Provides: The battery — run: php tests/meta-battery.php
 * 5. SPDX-License-Identifier: MPL-2.0
 * 6. Copyright (c) 2026 Homahuki GmbH
 */

declare(strict_types=1);

require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/../bridge/domain/meta.php';

$fm = static function (string $tagsLine = ''): string {
    return "---\ntitle: \"x\"\nslug: x\ndraft: false\npublished: true\n"
        . ($tagsLine !== '' ? $tagsLine . "\n" : '')
        . "---\n\nbody";
};

// the editor's dialect (the bug this battery was born from)
Harness::check('M1 bare yaml flow tags read back (the editor)', \Meta\peek($fm('tags: [moops, test]'))['tags'] === ['moops', 'test'],
    json_encode(\Meta\peek($fm('tags: [moops, test]'))['tags']));

// the ✑ writer's dialect rides verbatim
Harness::check('M2 json-quoted tags read back (the settings writer)', \Meta\peek($fm('tags: ["bier", "amphetamine"]'))['tags'] === ['bier', 'amphetamine']);

// mixed quotes/spacing, one line
Harness::check('M3 mixed dialect in one list', \Meta\peek($fm('tags: [ moops, "that test", \'deep\', ]'))['tags'] === ['moops', 'that test', 'deep']);

// a single tag, bare
Harness::check('M4 one bare word', \Meta\peek($fm('tags: [strudel]'))['tags'] === ['strudel']);

// absent key stays empty — no hallucination
Harness::check('M5 no tags line reads empty', \Meta\peek($fm())['tags'] === []);

// a tag with a handled escape rides through JSON
Harness::check('M6 an escaped quote survives', \Meta\peek($fm('tags: ["say \\"hi\\""]'))['tags'] === ['say "hi"']);

// other contract reads still hold (the big three: title, draft, published)
$p = \Meta\peek($fm('tags: [x]'));
Harness::check('M7 title/draft/published peek unchanged', $p['title'] === 'x' && $p['draft'] === false && $p['published'] === true);

Harness::verdict('meta battery (the frontmatter contract)');

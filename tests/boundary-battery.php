<?php

/**
 * 1. Relative path: tests/boundary-battery.php
 * 2. Description: The template/plugin × core BOUNDARY battery — 19 hostile
 *                 cases (guardrails §VI). Rebuilt into the repo (the original
 *                 lived in /tmp and died with the machine). Every case names a
 *                 threat and an expectation: the core must refuse what a ware
 *                 is not allowed to say, do, or reach.
 * 3. Expects: The repo root as argv[1]. No network, no Vitrine, no rig.
 * 4. Provides: PASS/FAIL per case, a count, exit 1 on any failure.
 * 5. SPDX-License-Identifier: MPL-2.0
 * 6. Copyright (c) 2026 Homahuki GmbH
 */

declare(strict_types=1);

require_once __DIR__ . '/harness.php';

$root = dirname(__DIR__);


require_once $root . '/bridge/plugins.php';
require_once $root . '/bridge/chain.php';
require_once $root . '/bridge/serve.php';

// ——— B1. the manifest gate: name === dir ———
$verdict = \Plugins\validate('probe', ['name' => 'other', 'title' => 'Other', 'version' => '0.1.0']);
Harness::check('B1 a manifest naming another ware is refused', is_string($verdict), (string)$verdict);

// ——— B2. the route namespace ———
// A route path is a single slug segment — nothing deeper, nothing climbing.
$m = \Plugins\validate('probe', [
    'name' => 'probe', 'title' => 'Probe', 'version' => '0.1.0',
    'capabilities' => ['routes' => [
        ['path' => 'ok', 'auth' => 'public'],
        ['path' => '../climb', 'auth' => 'public'],
        ['path' => 'a/b', 'auth' => 'public'],
        ['path' => 'UPPER', 'auth' => 'public'],
    ]],
]);
$routePaths = array_column(is_array($m) ? $m['routes'] : [], 'path');
Harness::check('B2 only the well-shaped route survives', $routePaths === ['ok'], implode(',', $routePaths));

// ——— B3. the auth law ———
// An unknown auth level falls back to 'user' — never to 'public'.
$m = \Plugins\validate('probe', [
    'name' => 'probe', 'title' => 'Probe', 'version' => '0.1.0',
    'capabilities' => ['routes' => [
        ['path' => 'a', 'auth' => 'public'],
        ['path' => 'b', 'auth' => 'user'],
        ['path' => 'c', 'auth' => 'admin'],
        ['path' => 'd', 'auth' => 'superuser'],
    ]],
]);
$byPath = [];
foreach ((array)$m['routes'] as $r) {
    $byPath[$r['path']] = $r;
}
Harness::check('B3 an unknown auth level falls back to user', ($byPath['d']['auth'] ?? '') === 'user', $byPath['d']['auth'] ?? 'none');
Harness::check('B4 a public route carries no CSRF', ($byPath['a']['csrf'] ?? true) === false, 'csrf on a public route');
Harness::check('B5 a sessioned route carries CSRF', ($byPath['b']['csrf'] ?? false) === true, 'no csrf on a sessioned route');

// ——— B6. the placeholder namespace ———
// Every placeholder must carry the plugin's OWN underscored prefix.
$m = \Plugins\validate('probe', [
    'name' => 'probe', 'title' => 'Probe', 'version' => '0.1.0',
    'capabilities' => ['placeholders' => ['probe_badge', 'other_badge', 'bodyHtml', 'probe-hyphen']],
]);
$ph = (array)($m['placeholders'] ?? []);
Harness::check('B6 a foreign placeholder is dropped', !in_array('other_badge', $ph, true), implode(',', $ph));
Harness::check('B7 the own placeholder survives', in_array('probe_badge', $ph, true), implode(',', $ph));
Harness::check('B8 a core token cannot be claimed as a placeholder', !in_array('bodyHtml', $ph, true), implode(',', $ph));
Harness::check('B9 a dashed placeholder is dropped (the prefix law speaks underscores)',
    !in_array('probe-hyphen', $ph, true), implode(',', $ph));

// ——— B10. the content-filter namespace ———
// A filter key is the tail of a FUNCTION name — one ware cannot hijack another's.
$m = \Plugins\validate('probe', [
    'name' => 'probe', 'title' => 'Probe', 'version' => '0.1.0',
    'capabilities' => ['contentFilters' => ['probe_filter', 'other_filter']],
]);
$cf = (array)($m['contentFilters'] ?? []);
Harness::check('B10 a foreign content filter is dropped', !in_array('other_filter', $cf, true), implode(',', $cf));
Harness::check('B11 the own content filter survives', in_array('probe_filter', $cf, true), implode(',', $cf));

// ——— B12. the drawer href law ———
// B4 (the 2026-11-07 scar): a protocol-relative '//host' and a backslash
// href are absolute in drag — both must be refused.
$m = \Plugins\validate('probe', [
    'name' => 'probe', 'title' => 'Probe', 'version' => '0.1.0',
    'capabilities' => ['drawer' => [
        ['label' => 'ok', 'href' => '/bridge/thing'],
        ['label' => 'evil', 'href' => '//evil.example'],
        ['label' => 'evil2', 'href' => '/\\evil.example'],
        ['label' => 'ext', 'href' => 'https://example.com/x'],
    ]],
]);
$hrefs = array_column((array)($m['drawer'] ?? []), 'href');
Harness::check('B12 a protocol-relative drawer href is refused', !in_array('//evil.example', $hrefs, true), implode(',', $hrefs));
Harness::check('B13 a backslash drawer href is refused', !in_array('/\\evil.example', $hrefs, true), implode(',', $hrefs));
Harness::check('B14 a root-relative drawer href survives', in_array('/bridge/thing', $hrefs, true), implode(',', $hrefs));
Harness::check('B15 an absolute https drawer href survives', in_array('https://example.com/x', $hrefs, true), implode(',', $hrefs));

// ——— B16. the chain law: the manifest's own truth ———
// A ware does not require itself; a malformed list is a broken manifest.
$verdict = \Plugins\validate('probe', [
    'name' => 'probe', 'title' => 'Probe', 'version' => '0.1.0', 'requires' => ['probe'],
]);
Harness::check('B16 a ware requiring itself is refused', is_string($verdict), (string)$verdict);

$verdict = \Plugins\validate('probe', [
    'name' => 'probe', 'title' => 'Probe', 'version' => '0.1.0', 'requires' => ['@@@bad'],
]);
Harness::check('B17 a malformed requires list is refused', is_string($verdict), (string)$verdict);

$m = \Plugins\validate('probe', [
    'name' => 'probe', 'title' => 'Probe', 'version' => '0.1.0', 'requires' => ['other >=1.2'],
]);
Harness::check('B18 a well-shaped requirement is parsed', is_array($m) && count($m['requiresParsed']) === 1, 'not parsed');

// ——— B19. the editor-block namespace ———
$m = \Plugins\validate('probe', [
    'name' => 'probe', 'title' => 'Probe', 'version' => '0.1.0',
    'capabilities' => ['editorBlocks' => [
        'probe_block',
        'foreign_block',
        ['name' => 'probe_panel_block', 'label' => 'Block', 'template' => 'x'],
        ['name' => 'foreign_panel_block', 'label' => 'Block', 'template' => 'x'],
    ]],
]);
$blocks = (array)($m['editorBlocks'] ?? []);
$blockNames = array_map(static fn($b) => is_array($b) ? $b['name'] : $b, $blocks);
Harness::check('B19 a foreign editor block is dropped', !in_array('foreign_block', $blockNames, true), implode(',', $blockNames));
Harness::check('B20 a foreign panel editor block is dropped', !in_array('foreign_panel_block', $blockNames, true), implode(',', $blockNames));
Harness::check('B21 the own editor blocks survive',
    in_array('probe_block', $blockNames, true) && in_array('probe_panel_block', $blockNames, true), implode(',', $blockNames));

// ——— B22. the admin-tabs namespace (slice 5a) ———
$m = \Plugins\validate('probe', [
    'name' => 'probe', 'title' => 'Probe', 'version' => '0.1.0',
    'capabilities' => [
        'routes' => [['path' => 'desk', 'auth' => 'user']],
        'admin' => ['tabs' => [
            ['id' => 'probe_desk', 'label' => 'Desk', 'route' => 'desk'],
            ['id' => 'foreign_desk', 'label' => 'Foreign', 'route' => 'desk'],
            ['id' => 'probe_ghost', 'label' => 'Ghost', 'route' => 'no-such-route'],
            ['id' => 'probe_dash', 'label' => 'No Door', 'route' => 'ghost-route'],
            ['id' => 'probe_x', 'label' => '', 'route' => 'desk'],
        ]],
    ],
]);
$tabs = (array)($m['adminTabs'] ?? []);
Harness::check('B22 the own namespaced tab with a declared route survives', count($tabs) === 1 && $tabs[0]['id'] === 'probe_desk', json_encode($tabs));
Harness::check('B23 a foreign tab id is dropped', !in_array('foreign_desk', array_column($tabs, 'id'), true), 'survived');
Harness::check('B24 a tab whose route the plugin never declared is dropped', !in_array('probe_ghost', array_column($tabs, 'id'), true), 'survived');

$m = \Plugins\validate('probe', [
    'name' => 'probe', 'title' => 'Probe', 'version' => '0.1.0',
    'capabilities' => [
        'routes' => [['path' => 'desk', 'auth' => 'user']],
        'admin' => ['tabs' => array_map(
            static fn($i) => ['id' => 'probe_t' . $i, 'label' => 'T' . $i, 'route' => 'desk'],
            range(1, 6)
        )],
    ],
]);
Harness::check('B25 more than four tabs cap at four', count($m['adminTabs']) === 4, (string)count($m['adminTabs']));

$m = \Plugins\validate('probe', [
    'name' => 'probe', 'title' => 'Probe', 'version' => '0.1.0',
    'capabilities' => [
        'routes' => [['path' => 'desk', 'auth' => 'user']],
        'editorBlocks' => [[
            'name' => 'probe_block', 'label' => 'Block', 'template' => 'x',
            'fence' => 'probe', 'preview' => ['route' => 'desk'], 'modal' => ['route' => 'desk'],
            'foreign' => 'shape',
        ]],
    ],
]);
$block = $m['editorBlocks'][0] ?? [];
Harness::check('B26 the grown def carries fence/preview/modal and drops the foreign key',
    ($block['fence'] ?? '') === 'probe'
    && ($block['preview']['route'] ?? '') === 'desk'
    && ($block['modal']['route'] ?? '') === 'desk'
    && !array_key_exists('foreign', $block), json_encode($block));

$m = \Plugins\validate('probe', [
    'name' => 'probe', 'title' => 'Probe', 'version' => '0.1.0',
    'capabilities' => [
        'routes' => [['path' => 'desk', 'auth' => 'user']],
        'editorBlocks' => [[
            'name' => 'probe_block', 'label' => 'Block', 'template' => 'x',
            'fence' => 'php', 'preview' => ['route' => 'ghost-route'], 'modal' => ['route' => 'ghost-route'],
        ]],
    ],
]);
$block = $m['editorBlocks'][0] ?? [];
Harness::check('B27 a foreign fence (a borrowed brand) is dropped',
    ($block['fence'] ?? 'x') === '', (string)($block['fence'] ?? 'x'));
Harness::check('B28 a preview/modal route the plugin never declared is dropped',
    ($block['preview'] ?? null) === null && ($block['modal'] ?? '') === '', json_encode($block));

// ——— the verdict ———
Harness::verdict('boundary battery');

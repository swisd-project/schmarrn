<?php

/**
 * 1. Relative path: bridge/plugins.php
 * 2. Description: The plugin capability contract (p6). Plugins live in bridge/plugins/<name>/
 *                 as manifest (plugin.json) + code (plugin.php). The LID is enforced by
 *                 the core, never by plugin diligence: only manifest-DECLARED
 *                 capabilities are honored, route auth/CSRF is applied HERE before a
 *                 handler ever runs, placeholders are namespaced (<name>_...), and a
 *                 broken plugin degrades to a log line instead of a dead site.
 *                 Capabilities (v1): template placeholders + API routes. Editor blocks
 *                 are declared in the manifest shape but not yet wired (slice 2).
 * 3. Expects: bridge/plugins/ dir at the repo root; state in bridge/config/plugins.local.php.
 * 4. Provides: Plugins\all/enabled/effective/deriveEffective/setState/load/placeholderFills/dispatch.
 * 5. SPDX-License-Identifier: MPL-2.0
 * 6. Copyright (c) 2026 Homahuki GmbH
 */

declare(strict_types=1);

namespace Plugins;

require_once __DIR__ . '/chain.php';

const IDENTIFIER = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

/** The plugin's PHP function prefix: hyphens are illegal in function names. */
function prefix(string $name): string
{
    return str_replace('-', '_', $name);
}

function stateFile(): string
{
    return __DIR__ . '/config/plugins.local.php';
}

/**
 * The scan: every installed plugin, validated manifest, intent flag. Returns
 * [list (display rows incl. broken ones), map (clean manifests by name),
 * intent (the state file's switches)] — the raw material all() dresses and
 * effective() derives from. No derivation here: all() calls this once and
 * decorates; effective() reuses the map without recursing into all().
 *
 * MEMOIZED per request (the optimization pass, 2026-11-07): one public page
 * serve used to run this up to FIVE times (load() once, contentFilters()
 * and placeholderFills() each asking all() + effective()) — each pass a
 * fresh scandir + json_decode of every manifest. Mutations forget via
 * forget(); setState() and a mid-request wares install call it.
 */
function scan(bool $wipe = false): array
{
    static $memo = null;
    if ($wipe) {
        $memo = null; // forget()'s word — the next call re-reads the shelf
        return [];
    }
    if ($memo !== null) {
        return $memo;
    }
    $state = enabled();
    $plugins = [];
    $map = [];
    foreach (scandir(__DIR__ . '/plugins') ?: [] as $name) {
        $dir = __DIR__ . '/plugins/' . $name;
        if (!is_dir($dir) || str_starts_with($name, '.')) {
            continue;
        }
        if (preg_match(IDENTIFIER, $name) !== 1) {
            $plugins[] = ['name' => $name, 'enabled' => false, 'error' => 'Invalid plugin directory name.'];
            continue;
        }
        $manifestFile = $dir . '/plugin.json';
        $manifest = is_file($manifestFile) ? json_decode((string)file_get_contents($manifestFile), true) : null;
        if (!is_array($manifest)) {
            $plugins[] = ['name' => $name, 'enabled' => false, 'error' => 'Missing or broken plugin.json.'];
            continue;
        }
        $clean = validate($name, $manifest);
        if (is_string($clean)) {
            $plugins[] = ['name' => $name, 'enabled' => false, 'error' => $clean];
            continue;
        }
        $map[$name] = $clean;
        $plugins[] = [...$clean, 'enabled' => $state[$name] ?? false];
    }
    usort($plugins, fn($a, $b) => strcmp((string)$a['name'], (string)$b['name']));
    return $memo = [$plugins, $map, $state];
}

/** Drop the scan memo — the shelf changed under it (state write, wares
 *  install). The next scan re-reads the truth from disk. */
function forget(): void
{
    scan(true); // the boolean is the wipe word
}

/**
 * THE CHAIN LAW — effective state is DERIVED, never stored: a plugin stands
 * effective only when its intent switch is on AND every requirement is
 * effective at a satisfying version, walked to a fixpoint. Orphaned
 * enable-states are unconstructible; rings never promote (the walk simply
 * never reaches them — they sit blocked, visible, until a human breaks the
 * circle). Depth-capped at 64 rounds: a chain that deep is a ring in a coat.
 */
function deriveEffective(array $map, array $intent): array
{
    $versions = array_map(fn($m) => (string)$m['version'], $map);
    $eff = [];
    for ($round = 0; $round < 64; $round++) {
        $grew = false;
        foreach ($map as $name => $m) {
            if (isset($eff[$name]) || ($intent[$name] ?? false) !== true) {
                continue;
            }
            if (\Chain\unmet($m['requiresParsed'], \Chain\pool($versions, $eff)) === []) {
                $eff[$name] = true;
                $grew = true;
            }
        }
        if (!$grew) {
            break;
        }
    }
    return $eff;
}

/** The derived truth over the installed shelf: name => true for effective plugins. */
function effective(): array
{
    [, $map, $state] = scan();
    return deriveEffective($map, $state);
}

/** Every installed plugin: manifest + intent + derived effective state + the
 * chain's verdict (blockedBy names what keeps a switched-on plugin from
 * running). Broken manifests degrade to an `error` note. */
function all(): array
{
    [$plugins, $map, $state] = scan();
    $eff = deriveEffective($map, $state);
    $versions = array_map(fn($m) => (string)$m['version'], $map);
    foreach ($plugins as $i => $plugin) {
        if (isset($plugin['error'])) {
            continue;
        }
        $name = (string)$plugin['name'];
        $on = ($state[$name] ?? false) === true;
        $plugins[$i]['effective'] = isset($eff[$name]);
        $plugins[$i]['blockedBy'] = $on && !isset($eff[$name])
            ? \Chain\unmet($map[$name]['requiresParsed'], \Chain\pool($versions, $eff))
            : [];
    }
    return $plugins;
}

/** Validate a manifest against the contract. Returns the clean array or an error string. */
function validate(string $name, array $manifest): array|string
{
    if (($manifest['name'] ?? null) !== $name) {
        return 'Manifest name must match the directory name.';
    }
    $title = is_string($manifest['title'] ?? null) ? $manifest['title'] : $name;
    $version = is_string($manifest['version'] ?? null) ? $manifest['version'] : '0.0.0';
    $description = is_string($manifest['description'] ?? null) ? $manifest['description'] : '';
    $caps = is_array($manifest['capabilities'] ?? null) ? $manifest['capabilities'] : [];

    // The Chain Law (2026-11-07, user law): the manifest carries its own
    // dependency truth — "name" or "name >=1.2". A malformed list is a broken
    // manifest (the plugin reads as errored, never as dependency-free), and a
    // ware does not require itself.
    $requiresParsed = \Chain\parse($manifest['requires'] ?? []);
    if ($requiresParsed === null) {
        return 'The requires list is unbaked dough — entries are "name" or "name >=1.2".';
    }
    foreach ($requiresParsed as $req) {
        if ($req['name'] === $name) {
            return 'A ware does not require itself.';
        }
    }
    $requires = array_map(fn($req) => \Chain\describe($req), $requiresParsed);

    // Placeholders: namespaced by law — every placeholder must start with the
    // plugin's UNDERSCORED prefix (the fill function answers as
    // plugin_<prefix>_placeholders, so the manifest gate must speak the same
    // alphabet: the dashed `$name` gate made dashed-name plugins' placeholders
    // structurally unspeakable — boundary audit, fixed 2026-11-07).
    $placeholders = [];
    foreach ((array)($caps['placeholders'] ?? []) as $ph) {
        if (is_string($ph) && str_starts_with($ph, prefix($name) . '_') && preg_match('/^[a-z0-9_]+$/', $ph) === 1) {
            $placeholders[] = $ph;
        }
    }

    // Routes: auth is declared and ENFORCED here — public, user (session) or admin.
    $routes = [];
    foreach ((array)($caps['routes'] ?? []) as $route) {
        if (!is_array($route)) {
            continue;
        }
        $path = $route['path'] ?? '';
        if (!is_string($path) || preg_match(IDENTIFIER, $path) !== 1) {
            continue;
        }
        $auth = $route['auth'] ?? 'user';
        if (!in_array($auth, ['public', 'user', 'admin'], true)) {
            $auth = 'user';
        }
        $routes[] = [
            'path' => $path,
            'auth' => $auth,
            // CSRF everywhere a session matters; public reads go without.
            'csrf' => $auth !== 'public' ? ($route['csrf'] ?? true) : false,
        ];
    }

    // Editor blocks (p6): manifest-declared canvas insertables, namespaced like
    // placeholders. Two shapes: bare strings (wired with plugin defaults) or
    // {name,label,template} — template = the md the editor inserts, then the
    // author shapes it in the text modal. Everything foreign is refused here.
    $editorBlocks = [];
    foreach ((array)($caps['editorBlocks'] ?? []) as $block) {
        if (is_string($block) && str_starts_with($block, prefix($name) . '_') && preg_match('/^[a-z0-9_-]+$/', $block) === 1) {
            $editorBlocks[] = $block;
        } elseif (is_array($block)
            && is_string($block['name'] ?? null)
            && str_starts_with((string)$block['name'], prefix($name) . '_')
            && preg_match('/^[a-z0-9_-]+$/', (string)$block['name']) === 1
            && is_string($block['label'] ?? null) && trim($block['label']) !== ''
            && is_string($block['template'] ?? null) && strlen($block['template']) <= 4000
            && trim($block['template']) !== ''
        ) {
            $editorBlocks[] = [
                'name' => (string)$block['name'],
                'label' => trim((string)$block['label']),
                'template' => (string)$block['template'],
                'modal' => is_string($block['modal'] ?? null)
                    && preg_match('/^[a-z][a-z-]{0,31}$/', (string)$block['modal']) === 1
                    ? (string)$block['modal'] : '',
            ];
        }
    }

    // Content filters (p6 slice 2): serve-time page transforms. The key IS the function
    // name's tail: entry "drunken_monk_page" → plugin_drunken_monk_page(). The lid here:
    // entry must start with the plugin's own underscored name — no cross-plugin hijinks.
    $contentFilters = [];
    foreach ((array)($caps['contentFilters'] ?? []) as $key) {
        if (is_string($key) && str_starts_with($key, prefix($name) . '_') && preg_match('/^[a-z0-9_]+$/', $key) === 1) {
            $contentFilters[] = $key;
        }
    }

    // Drawer handles (2026-11-07, user law): quality-of-life edit handles the
    // admin drawer's retail band carries. Manifest-declared {label, href} —
    // label ≤ 60 chars, href root-relative (plugin routes live at
    // /bridge/plugin/<name>/<route>) or absolute http(s). A protocol-relative
    // `//host` is an absolute in drag and browsers read `/\host` the same way —
    // both refused (boundary audit B4, fixed 2026-11-07). Everything foreign
    // is refused here; eight handles per plugin is already a crowd.
    $drawer = [];
    foreach ((array)($caps['drawer'] ?? []) as $handle) {
        if (!is_array($handle)) {
            continue;
        }
        $label = is_string($handle['label'] ?? null) ? trim((string)$handle['label']) : '';
        $href = is_string($handle['href'] ?? null) ? trim((string)$handle['href']) : '';
        if ($label === '' || $href === '') {
            continue;
        }
        if (str_starts_with($href, '/') && !str_starts_with($href, '//') && !str_contains($href, '\\')
            || preg_match('#^https?://#i', $href) === 1) {
            $drawer[] = ['label' => mb_substr($label, 0, 60), 'href' => $href];
        }
        if (count($drawer) >= 8) {
            break;
        }
    }

    return [
        'name' => $name,
        'title' => $title,
        'version' => $version,
        'description' => $description,
        'requires' => $requires,
        'requiresParsed' => $requiresParsed,
        'placeholders' => $placeholders,
        'routes' => $routes,
        'editorBlocks' => $editorBlocks,
        'contentFilters' => $contentFilters,
        'drawer' => $drawer,
    ];
}

/** The enabled map from the local state file (missing file = nothing enabled). */
function enabled(): array
{
    try {
        $state = is_file(stateFile()) ? (array)(require stateFile()) : [];
    } catch (\Throwable) {
        return [];
    }
    $clean = [];
    foreach ($state as $name => $on) {
        if (is_string($name) && preg_match(IDENTIFIER, $name) === 1 && $on === true) {
            $clean[$name] = true;
        }
    }
    return $clean;
}

/** The retail band (2026-11-07, user law): drawer handles from EFFECTIVE
 *  plugins — manifest-declared {label, href}, the lid validated them at
 *  load. A sleeping, blocked or broken plugin contributes nothing. */
function drawerHandles(): array
{
    $out = [];
    foreach (all() as $plugin) {
        if (isset($plugin['error']) || !($plugin['effective'] ?? false)) {
            continue;
        }
        foreach ((array)($plugin['drawer'] ?? []) as $handle) {
            if (is_array($handle) && is_string($handle['label'] ?? null) && is_string($handle['href'] ?? null)) {
                $out[] = ['label' => (string)$handle['label'], 'href' => (string)$handle['href'], 'kind' => 'plugin'];
            }
            if (count($out) >= 12) {
                break 2;
            }
        }
    }
    return $out;
}

/** Write the enabled map (var_export — data, never code) + drop stale bytecode. */
function setState(array $enabled): void
{
    $clean = [];
    foreach ($enabled as $name => $on) {
        if (is_string($name) && preg_match(IDENTIFIER, $name) === 1) {
            $clean[$name] = $on === true;
        }
    }
    $export = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($clean, true) . ";\n";
    if (file_put_contents(stateFile(), $export) === false) {
        \Router\refuse(500, 'Plugin state will not set — like jam without sugar.');
    }
    if (function_exists('opcache_invalidate')) {
        opcache_invalidate(stateFile(), true);
    }
    forget(); // the switches moved — no memo may remember the old intent
}

/** Load every EFFECTIVE plugin's code — the chain law's serve-time face: a
 *  plugin whose dependencies sleep (or a ring member) never loads, whatever
 *  its switch says. A broken plugin is skipped with a log line — never a dead site. */
function load(): void
{
    foreach (effective() as $name => $on) {
        $file = __DIR__ . '/plugins/' . $name . '/plugin.php';
        if (!is_file($file)) {
            @error_log("[schmarrn-plugins] enabled plugin '{$name}' has no plugin.php — skipped.");
            continue;
        }
        try {
            require_once $file;
        } catch (\Throwable $e) {
            @error_log("[schmarrn-plugins] '{$name}' failed to load: {$e->getMessage()} — skipped.");
        }
    }
}

/**
 * Collect placeholder fills from loaded plugins. Only manifest-DECLARED placeholders
 * are honored, and only when the plugin's fill function actually returned them.
 * ctx: ['site' => array, 'type' => string, 'slug' => string, 'title' => string]
 */
function placeholderFills(array $ctx): array
{
    $fills = [];
    $installed = all();
    foreach (effective() as $name => $on) {
        $manifest = null;
        foreach ($installed as $plugin) {
            if (($plugin['name'] ?? null) === $name && isset($plugin['placeholders'])) {
                $manifest = $plugin;
                break;
            }
        }
        if ($manifest === null || $manifest['placeholders'] === []) {
            continue;
        }
        $fn = 'plugin_' . prefix($name) . '_placeholders';
        if (!function_exists($fn)) {
            continue;
        }
        try {
            $returned = (array)$fn($ctx);
        } catch (\Throwable $e) {
            @error_log("[schmarrn-plugins] '{$name}' placeholder fill failed: {$e->getMessage()}");
            continue;
        }
        foreach ($manifest['placeholders'] as $ph) {
            if (isset($returned[$ph]) && is_string($returned[$ph])) {
                $fills['{{' . $ph . '}}'] = $returned[$ph];
            }
        }
    }
    return $fills;
}

/**
 * Apply enabled plugins' manifest-declared contentFilters to stored page HTML
 * (p6 slice 2 — serve-time page transforms, after the save-time mdms render).
 * Runs in manifest order per plugin, plugins by name; a broken filter degrades
 * to a log line and the HTML passes through unchanged — never a dead page.
 * ctx: ['site' => array, 'type' => string, 'slug' => string, 'title' => string]
 */
function contentFilters(array $ctx, string $html): string
{
    $installed = all();
    foreach (effective() as $name => $on) {
        foreach ($installed as $plugin) {
            if (($plugin['name'] ?? null) === $name && isset($plugin['contentFilters'])) {
                foreach ($plugin['contentFilters'] as $key) {
                    $fn = 'plugin_' . $key;
                    if (!function_exists($fn)) {
                        continue;
                    }
                    try {
                        $out = (string)$fn($ctx, $html);
                    } catch (\Throwable $e) {
                        @error_log("[schmarrn-plugins] '{$name}' content filter '{$key}' failed: {$e->getMessage()}");
                        continue;
                    }
                    $html = $out;
                }
                break;
            }
        }
    }
    return $html;
}

/** Dispatch a plugin API route. Auth + CSRF are enforced HERE, per the manifest. Never returns. */
function dispatch(string $name, string $route, string $method): never
{
    $plugin = null;
    foreach (all() as $candidate) {
        if (($candidate['name'] ?? null) === $name && !isset($candidate['error'])) {
            $plugin = $candidate;
            break;
        }
    }
    $eff = effective();
    if ($plugin === null || !isset($eff[$name])) {
        \Router\refuse(404, 'That plugin serves nothing — no such route.');
    }
    $declared = null;
    foreach ($plugin['routes'] as $candidate) {
        if ($candidate['path'] === $route) {
            $declared = $candidate;
            break;
        }
    }
    if ($declared === null) {
        \Router\refuse(404, 'That plugin serves nothing — no such route.');
    }
    // The lid: the core gates the door, the handler never has to remember.
    if ($declared['auth'] !== 'public' && !\Auth\check()) {
        \Router\refuse(401, 'The kitchen door is locked — log in first.');
    }
    if ($declared['auth'] === 'admin' && !\Auth\isAdmin()) {
        \Router\refuse(403, 'Admins only — editors do not touch the sugar safe.');
    }
    if ($declared['csrf'] && $method !== 'GET') {
        \Auth\assertCsrf();
    }
    load(); // plugin code in place — only after the lid has gated the door
    $fn = 'plugin_' . prefix($name) . '_route_' . str_replace('-', '_', $route);
    if (!function_exists($fn)) {
        \Router\refuse(500, "Plugin route handler missing: {$fn}");
    }
    $body = json_decode(file_get_contents('php://input') ?: '{}', true);
    $body = is_array($body) ? $body : [];
    try {
        $result = $fn($method, $body);
    } catch (\Throwable $e) {
        @error_log("[schmarrn-plugins] '{$name}/{$route}' failed: {$e->getMessage()}");
        \Router\refuse(500, 'That plugin burned on the route.');
    }
    // A handler may return dough (a string, null, whatever): it answers JSON,
    // never a fatal. _status is read only off arrays.
    if (!is_array($result)) {
        $result = ['body' => $result];
    }
    http_response_code(isset($result['_status']) ? (int)$result['_status'] : 200);
    unset($result['_status']);
    echo json_encode($result, JSON_UNESCAPED_SLASHES);
    exit;
}

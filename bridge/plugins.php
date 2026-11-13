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
 * 4. Provides: Plugins\all/enabled/effective/deriveEffective/setState/load/placeholderFills/dispatch,
 *              plus the CODE LID (codeCheck) the install knife reads an arriving ware with.
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

/**
 * The plugin's shelf image (2026-11-10): a plugin may hold one in its assets
 * folder — assets/preview.png (or .jpg/.jpeg/.webp). The assets folder is the
 * only spot the plugin home serves (the plugin's CODE is never whitelisted),
 * so that is where the picture lives. The URL, or null when the plugin ships
 * none — the dashboard then wears one of the house's fallback pictures.
 */
function shelfShot(string $name, string $dir): ?string
{
    foreach (['png', 'jpg', 'jpeg', 'webp'] as $ext) {
        if (is_file($dir . '/assets/preview.' . $ext)) {
            return '/bridge/plugins/' . $name . '/assets/preview.' . $ext;
        }
    }
    return null;
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
            $plugins[] = ['name' => $name, 'enabled' => false, 'error' => 'Invalid plugin directory name.', 'preview' => null];
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
            $plugins[] = ['name' => $name, 'enabled' => false, 'error' => $clean, 'preview' => null];
            continue;
        }
        $map[$name] = $clean;
        $plugins[] = [...$clean, 'enabled' => $state[$name] ?? false, 'preview' => shelfShot($name, $dir)];
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
        $plugins[$i]['settingsValues'] = storedSettings()[$name] ?? [];
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
    $documentation = is_string($manifest['documentation'] ?? null) ? trim((string)$manifest['documentation']) : '';
    // shelf tags: slugs, same alphabet the registry's styles speak.
    $tags = array_values(array_filter(
        (array)($manifest['tags'] ?? []),
        fn($t) => is_string($t) && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $t) === 1
    ));

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

    // The plugin's own PANEL (2026-11-12): the plugin hands the dashboard a
    // descriptor (labels, buttons, a list) and owns every word in it — the
    // core renders what it is told and never learns what the words mean.
    $panel = null;
    $pnl = $caps['panel'] ?? null;
    if (is_array($pnl)) {
        $pnlRoute = is_string($pnl['route'] ?? null) ? (string)$pnl['route'] : '';
        if (preg_match(IDENTIFIER, $pnlRoute) === 1) {
            $panel = ['route' => $pnlRoute];
        }
    }

    // Admin tabs (2026-11-13, slice 5a): manifest-declared tabs the admin
    // overlay carries beside the built-ins. {id,label,route} — id namespaced
    // with the plugin's underscored prefix (the editorBlocks law), label
    // ≤ 40, route must be one of the plugin's OWN declared routes above (no
    // dead tabs, no borrowed doors). Four tabs per plugin is already a shelf.
    $adminTabs = [];
    foreach ((array)($caps['admin']['tabs'] ?? []) as $tab) {
        if (!is_array($tab)) {
            continue;
        }
        $tabId = is_string($tab['id'] ?? null) ? (string)$tab['id'] : '';
        $tabLabel = is_string($tab['label'] ?? null) ? trim((string)$tab['label']) : '';
        $tabRoute = is_string($tab['route'] ?? null) ? (string)$tab['route'] : '';
        if (!str_starts_with($tabId, prefix($name) . '_')
            || preg_match('/^[a-z0-9_-]{1,63}$/', $tabId) !== 1
            || $tabLabel === ''
            || preg_match(IDENTIFIER, $tabRoute) !== 1
            || !in_array($tabRoute, array_map(fn($r) => $r['path'], $routes), true)) {
            continue;
        }
        $adminTabs[] = ['id' => $tabId, 'label' => mb_substr($tabLabel, 0, 40), 'route' => $tabRoute];
        if (count($adminTabs) >= 4) {
            break;
        }
    }

    // Plugin settings (2026-11-11): manifest-declared admin-editable fields.
    // {key, label, type} — key is a storage slot under the plugin's own name
    // (no shared namespace, so no prefix law), label ≤ 60 chars, type ∈
    // text|secret|toggle. Sixteen fields is already a settings page.
    $settings = [];
    foreach ((array)($caps['settings'] ?? []) as $def) {
        if (!is_array($def)) {
            continue;
        }
        $key = is_string($def['key'] ?? null) ? (string)$def['key'] : '';
        $label = is_string($def['label'] ?? null) ? trim((string)$def['label']) : '';
        $type = is_string($def['type'] ?? null) ? (string)$def['type'] : 'text';
        if (preg_match('/^[a-z0-9_]{1,63}$/', $key) !== 1
            || $label === ''
            || !in_array($type, ['text', 'secret', 'toggle'], true)) {
            continue;
        }
        $settings[] = ['key' => $key, 'label' => mb_substr($label, 0, 60), 'type' => $type];
        if (count($settings) >= 16) {
            break;
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
        'documentation' => $documentation,
        'tags' => $tags,
        'requires' => $requires,
        'requiresParsed' => $requiresParsed,
        'placeholders' => $placeholders,
        'routes' => $routes,
        'editorBlocks' => $editorBlocks,
        'settings' => $settings,
        'panel' => $panel,
        'adminTabs' => $adminTabs,
        'contentFilters' => $contentFilters,
        'drawer' => $drawer,
    ];
}

/** PHP's own words — never a call to something a ware must declare. */
const CODE_WORDS = [
    'isset', 'empty', 'array', 'list', 'echo', 'print', 'exit', 'die', 'eval',
    'unset', 'include', 'include_once', 'require', 'require_once', 'new',
    'clone', 'match', 'fn', 'static', 'self', 'parent', 'int', 'float', 'string',
    'bool', 'void', 'mixed', 'never', 'true', 'false', 'null',
];

/**
 * THE CODE LID (2026-11-10, user law): an arriving ware's CODE is read before
 * it lands. A ware whose code calls a function it never declares died in
 * transit — a duplicated letter inside an identifier survives the grammar
 * (php -l stays quiet, the eye sees nothing) but not the runtime: the call
 * burns at the door, hours later, on the host. The core lides the ware, never
 * the ware's own diligence — so the code is read HERE, once, at the install
 * knife. Namespaced calls (\Store\listAll()) and method calls ($x->foo()) live
 * in other namespaces/objects and are none of this lid's business; a global
 * call must resolve inside the ware's own code or in PHP itself.
 *
 * Returns null when the code resolves, or the reason it does not.
 */
function codeCheck(string $dir): ?string
{
    $files = [];
    try {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
                $files[] = $file->getPathname();
            }
        }
    } catch (\Throwable) {
        return 'its code folder could not be read';
    }
    if ($files === []) {
        return null; // no code at all — the runtime law degrades, as always
    }
    // TWO PASSES (2026-11-13, the lid battery's finding): the first pass
    // collects EVERY function the ware declares, across all its files; the
    // second judges the calls. The old single pass judged as it scanned, so
    // a call BEFORE its declaration in the same file was a false positive —
    // and PHP hoists top-level functions, so that code is legal. The
    // Vitrine's bienenstich carried exactly that shape (call at 101,
    // declaration at 324); a lid that refuses honest wares is worse than
    // no lid.
    $tokensOf = [];
    $declared = [];
    $problems = [];
    foreach ($files as $file) {
        $src = @file_get_contents($file);
        if ($src === false) {
            $problems[] = 'an unreadable code file';
            continue;
        }
        $tokens = @token_get_all($src);
        $tokensOf[] = $tokens;
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $t = $tokens[$i];
            if (!is_array($t) || $t[0] !== T_FUNCTION) {
                continue;
            }
            for ($j = $i + 1; $j < $count; $j++) {
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                    $declared[$tokens[$j][1]] = true;
                    break;
                }
            }
        }
    }
    foreach ($tokensOf as $tokens) {
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $t = $tokens[$i];
            if (!is_array($t) || $t[0] !== T_STRING) {
                continue;
            }
            $name = $t[1];
            $prev = $tokens[$i - 1] ?? null;
            // a method call ($x->foo()), a static one (Foo::bar()) or a new
            // object (new Foo()) — none of them a global function call
            if (is_array($prev) && in_array($prev[0], [
                T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NULLSAFE_OBJECT_OPERATOR, T_NEW,
            ], true)) {
                continue;
            }
            if (($tokens[$i + 1] ?? null) !== '(') {
                continue; // not a call at all (a constant, a class name)
            }
            if (in_array(strtolower($name), CODE_WORDS, true)) {
                continue;
            }
            if (isset($declared[$name]) || function_exists($name)) {
                continue;
            }
            $problems[] = "'{$name}' (line {$t[2]})";
        }
    }
    if ($problems === []) {
        return null;
    }
    return 'its code calls ' . implode(', ', array_slice($problems, 0, 3))
        . ' — functions the ware never declares (a mangled transfer, most likely).';
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

/** Drop one plugin's switch from the state file — a plugin that no longer
 *  exists takes its intent with it (a ghost switch is a lie the state file
 *  must not tell). The RAW map is read here: enabled() only carries the
 *  switched-on ones, and the switched-off entries must go too. */
function forgetState(string $name): void
{
    $state = [];
    if (is_file(stateFile())) {
        try {
            $state = (array)(require stateFile());
        } catch (\Throwable) {
            $state = [];
        }
    }
    if (!array_key_exists($name, $state)) {
        return;
    }
    unset($state[$name]);
    setState($state); // the writer owns the file — opcache drop + memo wipe included
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
        throw new \Domain\Error(500, 'Plugin state will not set — like jam without sugar.');
    }
    if (function_exists('opcache_invalidate')) {
        opcache_invalidate(stateFile(), true);
    }
    forget(); // the switches moved — no memo may remember the old intent
}

/** The plugin settings store — same home, same law as the state file: a
 *  var_export'd PHP map, git-ignored, data never code. Shape:
 *  [<plugin> => [<key> => string|bool]]. */
function settingsFile(): string
{
    return __DIR__ . '/config/plugins.settings.local.php';
}

/** The stored settings, lid-cleaned: only slug plugins, only [a-z0-9_] keys,
 *  only string|bool values. A broken file degrades to an empty store —
 *  settings failing never kills the site. */
function storedSettings(): array
{
    try {
        $all = is_file(settingsFile()) ? (array)(require settingsFile()) : [];
    } catch (\Throwable) {
        return [];
    }
    $out = [];
    foreach ($all as $plugin => $vals) {
        if (!is_string($plugin) || preg_match(IDENTIFIER, $plugin) !== 1 || !is_array($vals)) {
            continue;
        }
        $clean = [];
        foreach ($vals as $key => $value) {
            if (is_string($key) && preg_match('/^[a-z0-9_]{1,63}$/', $key) === 1
                && (is_string($value) || is_bool($value))) {
                $clean[$key] = $value;
            }
        }
        if ($clean !== []) {
            $out[$plugin] = $clean;
        }
    }
    return $out;
}

/** One plugin setting, as stored — null when nothing was ever saved.
 *  The plugin's own reading door (the gate asks here first). */
function setting(string $plugin, string $key): string|bool|null
{
    $vals = storedSettings()[$plugin] ?? null;
    return is_array($vals) && array_key_exists($key, $vals) ? $vals[$key] : null;
}

/** Write one setting (var_export — data, never code; stale bytecode dropped).
 *  No manifest lid HERE by design: the writer is either the core's own
 *  saveSettings() (manifest-lidded) or the plugin's code (its own manifest). */
function setSetting(string $plugin, string $key, string|bool $value): void
{
    if (preg_match(IDENTIFIER, $plugin) !== 1 || preg_match('/^[a-z0-9_]{1,63}$/', $key) !== 1) {
        throw new \Domain\Error(400, 'That settings slot is unbaked dough.');
    }
    $all = storedSettings();
    $all[$plugin][$key] = $value;
    $export = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($all, true) . ";\n";
    if (!is_dir(__DIR__ . '/config')) {
        @mkdir(__DIR__ . '/config', 0775, true);
    }
    if (file_put_contents(settingsFile(), $export, LOCK_EX) === false) {
        throw new \Domain\Error(500, 'Plugin settings will not save — the pantry shelf is full.');
    }
    if (function_exists('opcache_invalidate')) {
        opcache_invalidate(settingsFile(), true);
    }
}

/** The admin's save door: manifest-lidded. Only declared keys survive, values
 *  are typed (toggle → bool, text/secret → trimmed string, '' = cleared).
 *  Returns the clean map that landed. */
function saveSettings(string $plugin, array $values): array
{
    [, $map,] = scan();
    if (!isset($map[$plugin])) {
        return ['ok' => false, 'error' => "No such plugin on the shelf: {$plugin}"];
    }
    $defs = [];
    foreach ($map[$plugin]['settings'] as $def) {
        $defs[$def['key']] = $def;
    }
    $clean = [];
    foreach ($values as $key => $value) {
        if (!isset($defs[$key])) {
            continue; // a foreign key is dropped, not stored — the manifest is the only word
        }
        if ($defs[$key]['type'] === 'toggle') {
            $clean[$key] = $value === true || $value === 1 || $value === '1';
            continue;
        }
        if (!is_string($value)) {
            continue;
        }
        $clean[$key] = mb_substr(trim($value), 0, 2000);
    }
    foreach ($clean as $key => $value) {
        setSetting($plugin, $key, $value);
    }
    return ['ok' => true, 'settings' => $clean];
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
        throw new \Domain\Error(404, 'That plugin serves nothing — no such route.');
    }
    $declared = null;
    foreach ($plugin['routes'] as $candidate) {
        if ($candidate['path'] === $route) {
            $declared = $candidate;
            break;
        }
    }
    if ($declared === null) {
        throw new \Domain\Error(404, 'That plugin serves nothing — no such route.');
    }
    // The lid: the core gates the door, the handler never has to remember.
    if ($declared['auth'] !== 'public' && !\Auth\check()) {
        throw new \Domain\Error(401, 'The kitchen door is locked — log in first.');
    }
    if ($declared['auth'] === 'admin' && !\Auth\isAdmin()) {
        throw new \Domain\Error(403, 'Admins only — editors do not touch the sugar safe.');
    }
    if ($declared['csrf'] && $method !== 'GET') {
        \Auth\assertCsrf();
    }
    load(); // plugin code in place — only after the lid has gated the door
    $fn = 'plugin_' . prefix($name) . '_route_' . str_replace('-', '_', $route);
    if (!function_exists($fn)) {
        throw new \Domain\Error(500, "Plugin route handler missing: {$fn}");
    }
    $body = json_decode(file_get_contents('php://input') ?: '{}', true);
    $body = is_array($body) ? $body : [];
    try {
        $result = $fn($method, $body);
    } catch (\Throwable $e) {
        @error_log("[schmarrn-plugins] '{$name}/{$route}' failed: {$e->getMessage()}");
        throw new \Domain\Error(500, 'That plugin burned on the route.');
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

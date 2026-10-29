<?php

/**
 * 1. Relative path: api/plugins.php
 * 2. Description: The plugin capability contract (p6). Plugins live in plugins/<name>/
 *                 as manifest (plugin.json) + code (plugin.php). The LID is enforced by
 *                 the core, never by plugin diligence: only manifest-DECLARED
 *                 capabilities are honored, route auth/CSRF is applied HERE before a
 *                 handler ever runs, placeholders are namespaced (<name>_...), and a
 *                 broken plugin degrades to a log line instead of a dead site.
 *                 Capabilities (v1): template placeholders + API routes. Editor blocks
 *                 are declared in the manifest shape but not yet wired (slice 2).
 * 3. Expects: plugins/ dir at the repo root; state in api/config/plugins.local.php.
 * 4. Provides: Plugins\all/enabled/setState/load/placeholderFills/dispatch.
 * 5. SPDX-License-Identifier: MPL-2.0
 * 6. Copyright (c) 2026 Homahuki GmbH
 */

declare(strict_types=1);

namespace Plugins;

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

/** Every installed plugin with its validated manifest + enabled state. Broken manifests degrade to an `error` note. */
function all(): array
{
    $state = enabled();
    $plugins = [];
    foreach (scandir(dirname(__DIR__) . '/plugins') ?: [] as $name) {
        $dir = dirname(__DIR__) . '/plugins/' . $name;
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
        $plugins[] = [...$clean, 'enabled' => $state[$name] ?? false];
    }
    usort($plugins, fn($a, $b) => strcmp((string)$a['name'], (string)$b['name']));
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

    // Placeholders: namespaced by law — every placeholder must start with "<name>_".
    $placeholders = [];
    foreach ((array)($caps['placeholders'] ?? []) as $ph) {
        if (is_string($ph) && str_starts_with($ph, $name . '_') && preg_match('/^[a-z0-9_]+$/', $ph) === 1) {
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

    $editorBlocks = [];
    foreach ((array)($caps['editorBlocks'] ?? []) as $block) {
        if (is_string($block) && str_starts_with($block, $name . '_') && preg_match('/^[a-z0-9_-]+$/', $block) === 1) {
            $editorBlocks[] = $block; // declared now, wired in slice 2
        }
    }

    return [
        'name' => $name,
        'title' => $title,
        'version' => $version,
        'description' => $description,
        'placeholders' => $placeholders,
        'routes' => $routes,
        'editorBlocks' => $editorBlocks,
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
}

/** Load every enabled plugin's code. A broken plugin is skipped with a log line — never a dead site. */
function load(): void
{
    foreach (enabled() as $name => $on) {
        $file = dirname(__DIR__) . '/plugins/' . $name . '/plugin.php';
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
    foreach (enabled() as $name => $on) {
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
    if ($plugin === null || !($plugin['enabled'] ?? false)) {
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
    http_response_code(is_array($result) && isset($result['_status']) ? (int)$result['_status'] : 200);
    unset($result['_status']);
    echo json_encode($result, JSON_UNESCAPED_SLASHES);
    exit;
}

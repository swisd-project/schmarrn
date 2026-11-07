<?php

/**
 * 1. Relative path: index.php
 * 2. Description: Root front controller — the one public entry point.
 *                 /bridge/* → the JSON core; real public files (bridge/assets/, bridge/templates/)
 *                 pass through untouched; everything else is the public site.
 *                 Doubles as the php -S router script for dev (`php -S localhost:8080 index.php`).
 * 3. Expects: Apache + mod_rewrite with the shipped .htaccess, or php -S with this file.
 * 4. Provides: Clean URLs for the public site and the API alike.
 * 5. SPDX-License-Identifier: MPL-2.0
 * 6. Copyright (c) 2026 Homahuki GmbH
 */

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';

// ——— the core's blanko window: while the self-update holds the knife, every
// load except the operation itself (/bridge/core…) answers with a blank
// plate — a first load during the swap never sees a half-swapped 404. Public
// site and dashboard shell eat the plate; the API answers 503 JSON. ———
if (is_file(__DIR__ . '/bridge/cache/core-blank')) {
    require_once __DIR__ . '/bridge/core.php';
    // the marker's own TTL answers here (audit U5): a hard kill mid-update
    // never blanks the site until a hand climbs FTP — a stale marker is
    // swept at the read and the request walks on, un-blanked.
    if (\Core\blankKnives() && !str_starts_with($path, '/bridge/core')) {
        if (str_starts_with($path, '/bridge/')) {
            http_response_code(503);
            header('Content-Type: application/json');
            header('Retry-After: 30');
            exit(json_encode(['error' => \Core\blankoNote()]));
        }
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
        header('Retry-After: 30');
        exit(\Core\blanko());
    }
}

// ——— /bridge/* always belongs to the JSON core, real file or not. The three
// static prefixes inside bridge/ (assets, templates, plugin assets) fall
// through to the file whitelist below; only JSON routes land at the core.
if ($path !== '/' && str_starts_with($path, '/bridge/')
    && !str_starts_with($path, '/bridge/assets/')
    && !str_starts_with($path, '/bridge/templates/')
    && !str_starts_with($path, '/bridge/plugins/')) {
    require __DIR__ . '/bridge/index.php';
    exit;
}

// ——— /_ → the dashboard SPA (login + management). The app is BUILT to live under
// ——— /_ (paths.base) — its client router 404s anywhere else. Canonical: /_ → /_/.
if ($path === '/_') {
    header('Location: /_/', true, 301);
    exit;
}
if (str_starts_with($path, '/_/')) {
    $sub = substr($path, 2); // keeps the leading slash: '/dashboard.html' | '/_app/...'
    if ($sub === '' || $sub === '/') {
        $sub = '/index.html'; // the dir itself → the prerendered dashboard root
    }
    // ——— the admin ride (2026-11-07, user law): /_/<page> serves the LIVE page
    // in admin mode — the visual editor's home. The dashboard's own assets keep
    // their paths; a sub-path that names no page falls through to them. ———
    if ($sub !== '/index.html' && $sub !== '/dashboard.html') {
        require_once __DIR__ . '/bridge/serve.php';
        if (\Serve\tryDispatch($path)) {
            exit; // served in admin mode
        }
    }
    // Host layout: a real _/ directory. Repo layout: the flat build/ output
    // (/_/_app/x → build/_app/x — the base prefix collapses into the path).
    foreach ([__DIR__ . '/_', __DIR__ . '/build'] as $baseDir) {
        $file = realpath($baseDir . $sub);
        $root = realpath(__DIR__);
        if ($file !== false && $root !== false && str_starts_with($file, $root . DIRECTORY_SEPARATOR) && is_file($file)) {
            $mime = str_ends_with($sub, '.js') || str_ends_with($sub, '.mjs') ? 'text/javascript'
                : (str_ends_with($sub, '.css') ? 'text/css'
                : (str_ends_with($sub, '.html') ? 'text/html'
                : (str_ends_with($sub, '.svg') ? 'image/svg+xml'
                : (str_ends_with($sub, '.woff2') ? 'font/woff2'
                : (mime_content_type($file) ?: 'application/octet-stream')))));
            header('Content-Type: ' . $mime);
            // Cache law, mirrored from .htaccess: hashed bundles live for a year
            // (immutable); the shell and unhashed assets revalidate — a rebuilt
            // editor bundle reaches a reload, not an era.
            if (str_contains($sub, '/_app/immutable/')) {
                header('Cache-Control: public, max-age=31536000, immutable');
            } else {
                header('Cache-Control: no-cache, must-revalidate');
            }
            header('X-Content-Type-Options: nosniff');
            readfile($file);
            exit;
        }
    }
    // fall through to the public 404
}

// ——— real public files: let the server do its job (php -S router mode) ———
// Whitelist only: themes, plugin assets, and the fallback render bundle. Plugin
// CODE is never whitelisted — a direct hit on bridge/plugins/<name>/plugin.php would
// execute it outside the front controller. Everything else on disk is none of
// the visitor's business — it falls through to the public 404.
if ($path !== '/') {
    $root = realpath(__DIR__);
    $file = realpath(__DIR__ . rawurldecode($path));
    if ($file === false && $root !== false && str_starts_with($path, '/_app/')) {
        // The SPA bundle lives at root on the host, under build/ in the repo.
        $candidate = __DIR__ . '/build' . rawurldecode($path);
        $real = realpath($candidate);
        if ($real !== false && str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
            $file = $real;
        }
    }
    if ($file !== false && $root !== false && str_starts_with($file, $root . DIRECTORY_SEPARATOR) && is_file($file)) {
        $rel = str_replace(DIRECTORY_SEPARATOR, '/', substr($file, strlen($root) + 1));
        $fromBuild = str_starts_with($rel, 'build/');
        if ($fromBuild) {
            $rel = substr($rel, strlen('build/'));
        }
        if (str_starts_with($rel, 'bridge/templates/') || str_starts_with($rel, 'bridge/assets/')
            || str_starts_with($rel, '_app/')
            || preg_match('#^bridge/plugins/[a-z0-9-]+/assets/#', $rel) === 1) {
            // Ware-authored active documents (SVG/HTML in a theme's or a
            // plugin's assets — boundary audit B8, 2026-11-07) ride the media
            // sandbox law: a direct hit executes nothing on this origin, and
            // the file stays a perfectly good <img>/CSS/JS source. Core-owned
            // assets are trusted and stay free. These stream by hand EVEN on
            // php -S: `return false` hands the file back but DISCARDS every
            // router-set header — the sandbox would ride off the response
            // (proven with a live probe against the built-in server).
            $sandboxed = (str_ends_with($rel, '.svg') || str_ends_with($rel, '.html'))
                && (str_starts_with($rel, 'bridge/templates/')
                    || preg_match('#^bridge/plugins/[a-z0-9-]+/assets/#', $rel) === 1);
            if (PHP_SAPI === 'cli-server' && !$fromBuild && !$sandboxed) {
                return false; // php -S serves it with proper MIME, no custom headers needed
            }
            if ($sandboxed) {
                header('Content-Security-Policy: default-src \'none\'; style-src \'unsafe-inline\'; sandbox');
            }
            // Streamed by hand: exotic SAPIs without rewrite, and anything resolved
            // out of build/ (php -S can't serve what isn't at the path it knows).
            $mime = str_ends_with($rel, '.js') || str_ends_with($rel, '.mjs') ? 'text/javascript'
                : (str_ends_with($rel, '.css') ? 'text/css'
                : (str_ends_with($rel, '.html') ? 'text/html'
                : (str_ends_with($rel, '.svg') ? 'image/svg+xml'
                : (str_ends_with($rel, '.woff2') ? 'font/woff2'
                : (mime_content_type($file) ?: 'application/octet-stream')))));
            header('Content-Type: ' . $mime);
            // Theme-contract cache law (3600, revalidate) — PHP streams these in
            // build/dev mode only; Apache hands the .htaccess rule on prod.
            header('Cache-Control: max-age=3600, must-revalidate');
            header('X-Content-Type-Options: nosniff');
            readfile($file);
            exit;
        }
    }
}

// ——— everything else: the public site ———
require_once __DIR__ . '/bridge/serve.php';
\Serve\dispatch($path);

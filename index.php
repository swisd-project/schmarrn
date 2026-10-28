<?php

/**
 * 1. Relative path: index.php
 * 2. Description: Root front controller — the one public entry point.
 *                 /api/* → the JSON core; real public files (themes/, api/assets/)
 *                 pass through untouched; everything else is the public site.
 *                 Doubles as the php -S router script for dev (`php -S localhost:8080 index.php`).
 * 3. Expects: Apache + mod_rewrite with the shipped .htaccess, or php -S with this file.
 * 4. Provides: Clean URLs for the public site and the API alike.
 * 5. SPDX-License-Identifier: MPL-2.0
 * 6. Copyright (c) 2026 Homahuki GmbH
 */

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';

// ——— /api/* always belongs to the JSON core, real file or not ———
if ($path !== '/' && str_starts_with($path, '/api/') && !str_starts_with($path, '/api/assets/')) {
    require __DIR__ . '/api/index.php';
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
            header('X-Content-Type-Options: nosniff');
            readfile($file);
            exit;
        }
    }
    // fall through to the public 404
}

// ——— real public files: let the server do its job (php -S router mode) ———
// Whitelist only: themes, plugin assets, and the fallback render bundle. Plugin
// CODE is never whitelisted — a direct hit on plugins/<name>/plugin.php would
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
        if (str_starts_with($rel, 'themes/') || str_starts_with($rel, 'api/assets/')
            || str_starts_with($rel, '_app/')
            || preg_match('#^plugins/[a-z0-9-]+/assets/#', $rel) === 1) {
            if (PHP_SAPI === 'cli-server' && !$fromBuild) {
                return false; // php -S serves it with proper MIME
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
            header('X-Content-Type-Options: nosniff');
            readfile($file);
            exit;
        }
    }
}

// ——— everything else: the public site ———
require __DIR__ . '/api/serve.php';
\Serve\dispatch($path);

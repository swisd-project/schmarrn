<?php

/**
 * 1. Relative path: api/store.php
 * 2. Description: Content store. One directory per page, index.md inside.
 *                 PHP stores and serves raw markdown — it NEVER parses it.
 * 3. Expects: Content root ../src/content; strict slug/type patterns.
 * 4. Provides: listAll(), read(), write() with atomic temp+rename, slug discipline.
 * 5. SPDX-License-Identifier: MPL-2.0
 * 6. Copyright (c) 2026 Homahuki GmbH
 */

declare(strict_types=1);

namespace Store;

// Library files stand alone: each requires its dependencies explicitly.
require_once __DIR__ . '/router.php';

const TYPE_PATTERN = '[a-z0-9]+(?:-[a-z0-9]+)*';
const SLUG_PATTERN = '[a-z0-9]+(?:-[a-z0-9]+)*';

function root(): string
{
    return dirname(__DIR__) . '/src/content';
}

function pageDir(string $type, string $slug): string
{
    assertIdentifier($type, 'type');
    assertIdentifier($slug, 'slug');
    return root() . '/' . $type . '/' . $slug;
}

function assertIdentifier(string $value, string $what): void
{
    if (preg_match('/^' . SLUG_PATTERN . '$/', $value) !== 1) {
        \Router\refuse(400, "Invalid {$what}: {$value}");
    }
}

/** All pages: {type: [{slug, updatedAt, draft}...]} — existence is truth.
 *  PHP only regex-sniffs the draft flag (no Markdown parsing, byte-peek discipline).
 *  hasDraft tells the dashboard an unpublished editorial copy (.draft.md) is sitting there. */
function listAll(): array
{
    $result = [];
    foreach (scandir(root()) ?: [] as $type) {
        $typeDir = root() . '/' . $type;
        if (!is_dir($typeDir) || str_starts_with($type, '.')) {
            continue;
        }
        $includeDrafts = \Auth\check();
        $pages = [];
        foreach (scandir($typeDir) ?: [] as $slug) {
            $file = $typeDir . '/' . $slug . '/index.md';
            if (!is_file($file) || str_starts_with($slug, '.')) {
                continue;
            }
            $raw = (string)file_get_contents($file);
            $draft = preg_match('/^draft:\s*true\b/m', $raw) === 1;
            if ($draft && !$includeDrafts) {
                continue;
            }
            $pages[] = [
                'slug' => $slug,
                'updatedAt' => filemtime($file),
                'draft' => $draft,
                'hasDraft' => is_file($typeDir . '/' . $slug . '/.draft.md'),
            ];
        }
        usort($pages, fn($a, $b) => $a['slug'] <=> $b['slug']);
        $result[$type] = $pages;
    }
    return $result;
}

/** Anonymous requests are the public: hide drafts from them. */
function isDraft(string $type, string $slug): bool
{
    $raw = read($type, $slug);
    return $raw !== null && preg_match('/^draft:\s*true\b/m', $raw) === 1;
}

function read(string $type, string $slug): ?string
{
    $file = pageDir($type, $slug) . '/index.md';
    if (!is_file($file)) {
        return null;
    }
    $raw = file_get_contents($file);
    return $raw === false ? null : $raw;
}

// ——— editorial copy: .draft.md sits beside index.md, dot-prefixed so listings never trip on it ———

function draftFile(string $type, string $slug): string
{
    return pageDir($type, $slug) . '/.draft.md';
}

/** The editor's working copy: the sidecar draft if one exists, else the published file. */
function readDraft(string $type, string $slug): ?string
{
    assertIdentifier($type, 'type');
    assertIdentifier($slug, 'slug');
    $draft = draftFile($type, $slug);
    if (is_file($draft)) {
        return (string)file_get_contents($draft) ?: null;
    }
    return read($type, $slug);
}

/** Atomic write of the editorial sidecar (no cache involvement — public never sees it). */
function writeDraft(string $type, string $slug, string $raw): void
{
    assertNonEmpty($raw);
    assertDistinctFilenameSlug($type, $slug, $raw);
    $dir = pageDir($type, $slug);
    if (!is_dir($dir)) {
        \Router\refuse(404, "Page not found: {$type}/{$slug}");
    }
    $tmp = $dir . '/.draft.' . bin2hex(random_bytes(6)) . '.tmp';
    if (file_put_contents($tmp, $raw) === false || !\rename($tmp, draftFile($type, $slug))) {
        @unlink($tmp);
        \Router\refuse(500, 'Draft write failed.');
    }
}

function hasDraft(string $type, string $slug): bool
{
    assertIdentifier($type, 'type');
    assertIdentifier($slug, 'slug');
    return is_file(draftFile($type, $slug));
}

/** Publish: sidecar → index.md, sidecar gone, cache dropped. */
function publish(string $type, string $slug): void
{
    if (!hasDraft($type, $slug)) {
        \Router\refuse(400, 'Nothing to publish — no editorial draft on file.');
    }
    assertPageExists($type, $slug);
    $draft = draftFile($type, $slug);
    $tmp = $draft . '.pub';
    if (!copy($draft, $tmp) || !\rename($tmp, pageDir($type, $slug) . '/index.md')) {
        @unlink($tmp);
        \Router\refuse(500, 'Publish failed.');
    }
    @unlink($draft);
}

/** Unpublish: current published copy becomes the editorial copy; index.md is flagged draft (hidden). */
function unpublish(string $type, string $slug): void
{
    $raw = read($type, $slug);
    if ($raw === null) {
        \Router\refuse(404, "Page not found: {$type}/{$slug}");
    }
    writeDraft($type, $slug, $raw);            // editorial copy preserved verbatim
    write($type, $slug, flipDraftFlag($raw, true)); // published file hidden
}

/** Set `draft: true|false` in the frontmatter — byte-level flag flip, still no Markdown parsing. */
function flipDraftFlag(string $raw, bool $on): string
{
    $flag = 'draft: ' . ($on ? 'true' : 'false');
    if (preg_match('/^draft:\s*(true|false)\s*$/m', (string)$raw) === 1) {
        return (string)preg_replace('/^draft:\s*(true|false)\s*$/m', $flag, (string)$raw);
    }
    if (preg_match('/^---\s*\n/', (string)$raw) === 1) {
        // insert into the first frontmatter block
        $end = strpos((string)$raw, "\n---", 4);
        if ($end !== false) {
            return substr($raw, 0, $end) . "\n{$flag}" . substr($raw, $end);
        }
    }
    return ($on && preg_match('/^---\s*\n/', $raw) !== 1) ? "---\n{$flag}\n---\n" . $raw : $raw;
}

function setPublishedFlag(string $type, string $slug, bool $on): void
{
    $raw = read($type, $slug);
    if ($raw === null) {
        \Router\refuse(404, "Page not found: {$type}/{$slug}");
    }
    write($type, $slug, flipDraftFlag($raw, !$on));
}

function assertPageExists(string $type, string $slug): void
{
    if (read($type, $slug) === null) {
        \Router\refuse(404, "Page not found: {$type}/{$slug}");
    }
}

// ——— page lifecycle beyond write: delete + rename ———

/** Delete: the whole page dir goes. Contained, no symlinks games, cache dropped by caller. */
function remove(string $type, string $slug): void
{
    assertPageExists($type, $slug);
    $dir = pageDir($type, $slug);
    foreach (scandir($dir) ?: [] as $entry) {
        if (in_array($entry, ['.', '..'], true)) {
            continue;
        }
        $path = $dir . '/' . $entry;
        if (is_dir($path) && !is_link($path)) {
            continue; // media lives flat; subdirs would be foreign — refuse silently via check below
        }
        @unlink($path);
    }
    if (!@rmdir($dir)) {
        Router\refuse(500, "Cannot delete page dir: {$type}/{$slug}");
    }
}

/** Rename: move the page dir to a new slug. Content untouched, both caches dropped by caller.
 *  Named move() — a Store\rename() would shadow PHP's global rename() across the whole namespace. */
function move(string $type, string $slug, string $newSlug): void
{
    assertPageExists($type, $slug);
    assertIdentifier($newSlug, 'new slug');
    $from = pageDir($type, $slug);
    $to = pageDir($type, $newSlug);
    if (is_dir($to)) {
        Router\refuse(409, "Target exists: {$type}/{$newSlug}");
    }
    if (!@\rename($from, $to)) {
        Router\refuse(500, 'Rename failed.');
    }
}

// ——— page-local media: immutable uploads beside index.md, referenced by filename ———

const MEDIA_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'mp4', 'webm', 'pdf', 'mp3', 'wav'];

function mediaMime(string $file): string
{
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    return match ($ext) {
        'jpg', 'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml',
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
        'pdf' => 'application/pdf',
        'mp3' => 'audio/mpeg',
        'wav' => 'audio/wav',
        default => 'application/octet-stream',
    };
}

function mediaList(string $type, string $slug): array
{
    assertPageExists($type, $slug);
    $dir = pageDir($type, $slug);
    $files = [];
    foreach (scandir($dir) ?: [] as $name) {
        if (in_array($name, ['.', '..', 'index.md'], true) || str_starts_with($name, '.') || str_ends_with($name, '.tmp')) {
            continue;
        }
        $path = $dir . '/' . $name;
        if (!is_file($path)) {
            continue;
        }
        $files[] = ['name' => $name, 'size' => filesize($path), 'mtime' => filemtime($path)];
    }
    usort($files, fn($a, $b) => strcmp((string)$a['name'], (string)$b['name']));
    return $files;
}

/** Immutable by law: an existing filename is never overwritten — the upload gets a fresh prefix. */
function mediaStore(string $type, string $slug, string $originalName, string $tmpPath): array
{
    assertPageExists($type, $slug);
    $base = basename($originalName);
    $base = preg_replace('/[^A-Za-z0-9._-]/', '-', $base) ?? 'file';
    $base = trim($base, '-._');
    $ext = strtolower(pathinfo($base, PATHINFO_EXTENSION));
    if ($ext === '' || !in_array($ext, MEDIA_EXTENSIONS, true)) {
        \Router\refuse(400, "Unsupported media type: .{$ext}");
    }
    $stem = pathinfo($base, PATHINFO_FILENAME);
    if ($stem === '') {
        $stem = 'file';
    }
    $dir = pageDir($type, $slug);
    // Same-second uploads collide otherwise — a dash of entropy keeps immutability honest.
    $name = date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '-' . $stem . '.' . $ext;
    $target = $dir . '/' . $name;
    if (!move_uploaded_file($tmpPath, $target) && !@\rename($tmpPath, $target)) {
        \Router\refuse(500, 'Media upload failed.');
    }
    return ['name' => $name, 'size' => filesize($target), 'mtime' => filemtime($target)];
}

/** Contained read: the file must literally live inside the page dir, no ../ games. */
function mediaPath(string $type, string $slug, string $name): ?string
{
    assertIdentifier($type, 'type');
    assertIdentifier($slug, 'slug');
    if (preg_match('/^[A-Za-z0-9._-]+$/', $name) !== 1 || str_starts_with($name, '.')) {
        return null;
    }
    $dir = realpath(pageDir($type, $slug));
    $path = realpath($dir . '/' . $name);
    if ($dir === false || $path === false || !str_starts_with($path, $dir . DIRECTORY_SEPARATOR)) {
        return null;
    }
    return $path;
}

/** Atomic write: validation → temp file → rename. Never leaves a half-Shmarrn behind. */
function write(string $type, string $slug, string $raw): void
{
    assertNonEmpty($raw);
    assertDistinctFilenameSlug($type, $slug, $raw);

    $dir = pageDir($type, $slug);
    if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
        \Router\refuse(500, "Cannot create page directory: {$dir}");
    }

    $tmp = $dir . '/.index.' . bin2hex(random_bytes(6)) . '.tmp';
    if (file_put_contents($tmp, $raw) === false || !\rename($tmp, $dir . '/index.md')) {
        @unlink($tmp);
        \Router\refuse(500, 'Write failed.');
    }
}

function assertNonEmpty(string $raw): void
{
    if (trim($raw) === '') {
        \Router\refuse(400, 'Refusing to save empty page.');
    }
}

/** Slug discipline lives on both sides of the wire: filename is the law here. */
function assertDistinctFilenameSlug(string $type, string $slug, string $raw): void
{
    if (preg_match('#^\s*<!--\s*slug:\s*([a-z0-9-]+)\s*-->\s*$#m', $raw, $m) === 1 && $m[1] !== $slug) {
        \Router\refuse(400, "Slug mismatch: URL says '{$slug}', file declares '{$m[1]}'");
    }
}

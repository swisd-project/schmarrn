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
        Router\refuse(400, "Invalid {$what}: {$value}");
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
        Router\refuse(404, "Page not found: {$type}/{$slug}");
    }
    $tmp = $dir . '/.draft.' . bin2hex(random_bytes(6)) . '.tmp';
    if (file_put_contents($tmp, $raw) === false || !rename($tmp, draftFile($type, $slug))) {
        @unlink($tmp);
        Router\refuse(500, 'Draft write failed.');
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
        Router\refuse(400, 'Nothing to publish — no editorial draft on file.');
    }
    assertPageExists($type, $slug);
    $draft = draftFile($type, $slug);
    $tmp = $draft . '.pub';
    if (!copy($draft, $tmp) || !rename($tmp, pageDir($type, $slug) . '/index.md')) {
        @unlink($tmp);
        Router\refuse(500, 'Publish failed.');
    }
    @unlink($draft);
}

/** Unpublish: current published copy becomes the editorial copy; index.md is flagged draft (hidden). */
function unpublish(string $type, string $slug): void
{
    $raw = read($type, $slug);
    if ($raw === null) {
        Router\refuse(404, "Page not found: {$type}/{$slug}");
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
        Router\refuse(404, "Page not found: {$type}/{$slug}");
    }
    write($type, $slug, flipDraftFlag($raw, !$on));
}

function assertPageExists(string $type, string $slug): void
{
    if (read($type, $slug) === null) {
        Router\refuse(404, "Page not found: {$type}/{$slug}");
    }
}

/** Atomic write: validation → temp file → rename. Never leaves a half-Shmarrn behind. */
function write(string $type, string $slug, string $raw): void
{
    assertNonEmpty($raw);
    assertDistinctFilenameSlug($type, $slug, $raw);

    $dir = pageDir($type, $slug);
    if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
        Router\refuse(500, "Cannot create page directory: {$dir}");
    }

    $tmp = $dir . '/.index.' . bin2hex(random_bytes(6)) . '.tmp';
    if (file_put_contents($tmp, $raw) === false || !rename($tmp, $dir . '/index.md')) {
        @unlink($tmp);
        Router\refuse(500, 'Write failed.');
    }
}

function assertNonEmpty(string $raw): void
{
    if (trim($raw) === '') {
        Router\refuse(400, 'Refusing to save empty page.');
    }
}

/** Slug discipline lives on both sides of the wire: filename is the law here. */
function assertDistinctFilenameSlug(string $type, string $slug, string $raw): void
{
    if (preg_match('#^\s*<!--\s*slug:\s*([a-z0-9-]+)\s*-->\s*$#m', $raw, $m) === 1 && $m[1] !== $slug) {
        Router\refuse(400, "Slug mismatch: URL says '{$slug}', file declares '{$m[1]}'");
    }
}

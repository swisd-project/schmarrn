<?php

/**
 * 1. Relative path: bridge/domain/meta.php
 * 2. Description: THE FRONTMATTER CONTRACT — one owner for every fact the
 *                 core byte-peeks out of a page's frontmatter. Before this
 *                 module the draft flag lived in two regexes in store.php,
 *                 the style in serve.php, the tags in store.php and the title
 *                 in serve.php — four parsers of the same contract, each free
 *                 to drift (and two of them had already drifted: the slug
 *                 mismatch gate read an HTML COMMENT, never the frontmatter's
 *                 own slug key). No YAML parser, no mdms in PHP — the
 *                 byte-peek discipline stays, it just lives in ONE place.
 * 3. Expects: Raw page bytes. Nothing else — no HTTP, no disk.
 * 4. Provides: Meta\peek() — every fact in one call; Meta\defects() — the
 *                 gate; Meta\repair() — the on-demand repair service.
 * 5. SPDX-License-Identifier: MPL-2.0
 * 6. Copyright (c) 2026 Homahuki GmbH
 */

declare(strict_types=1);

namespace Meta;

/**
 * Every fact the core needs from one page's frontmatter, in one peek.
 * Absent facts are null — the caller decides what absence means (a missing
 * title falls back to the slug, a missing style falls back to the theme).
 *
 * @return array{title: ?string, slug: ?string, draft: ?bool, published: ?bool,
 *                style: ?string, tags: list<string>, raw: string}
 */
function peek(string $raw): array
{
    $out = [
        'title' => null,
        'slug' => null,
        'draft' => null,
        'published' => null,
        'style' => null,
        'tags' => [],
        'raw' => $raw,
    ];

    // title — quoted or bare, one line. NOTE: [ \t], never \s — \s eats the
    // newline, and an EMPTY title value would then swallow the NEXT LINE as
    // the title (the audit found this latent in the old titleOf too).
    if (preg_match('/^title:[ \t]*(.*)$/m', $raw, $m) === 1) {
        $title = trim($m[1]);
        $quote = $title[0] ?? '';
        if (strlen($title) >= 2 && ($quote === '"' || $quote === "'") && str_ends_with($title, $quote)) {
            $title = substr($title, 1, -1);
        }
        $out['title'] = $title !== '' ? $title : null;
    }

    // slug — quoted or bare, one line ([ \t] for the same reason)
    if (preg_match('/^slug:[ \t]*["\']?([a-z0-9]+(?:-[a-z0-9]+)*)["\']?[ \t]*$/m', $raw, $m) === 1) {
        $out['slug'] = $m[1];
    }

    // draft + published — the boolean flags, byte-peeked (no YAML parse)
    $out['draft'] = preg_match('/^draft:\s*true\b/m', $raw) === 1
        ? true : (preg_match('/^draft:\s*false\b/m', $raw) === 1 ? false : null);
    $out['published'] = preg_match('/^published:\s*true\b/m', $raw) === 1
        ? true : (preg_match('/^published:\s*false\b/m', $raw) === 1 ? false : null);

    // style — the theme's declared styles judge the value later; here only shape
    if (preg_match('/^style:[ \t]*["\']?([a-z0-9]+(?:-[a-z0-9]+)*)["\']?[ \t]*$/m', $raw, $m) === 1) {
        $out['style'] = $m[1];
    }

    // tags — the app's own flow-array shape, JSON-decoded in one move
    if (preg_match('/^tags:[ \t]*\[(.*)\][ \t]*$/m', $raw, $m) === 1) {
        $tags = json_decode('[' . trim($m[1]) . ']', true);
        if (is_array($tags)) {
            foreach ($tags as $tag) {
                if (is_string($tag) && trim($tag) !== '') {
                    $out['tags'][] = $tag;
                }
            }
        }
    }

    return $out;
}

/**
 * THE GATE: the defects the WRITE PATH enforces — the law GOSPEL states.
 * Only the slug-vs-URL mismatch rides here: a hard error the old code never
 * enforced (its gate read an HTML COMMENT, never the frontmatter's own slug
 * key). Everything else (a missing title, a missing flag) stays LEGAL on
 * write — the core falls back to the slug everywhere a title is missing, and
 * the blanko seed carries a title with no slug by design. Those belong to
 * the REPAIR SERVICE's report, callable on demand — never to a hard refusal
 * that would strand existing pages.
 *
 * @return list<string> the gate defects, human-readable and empty when clean.
 */
function gate(string $raw, string $urlSlug): array
{
    if (!preg_match('/^---\s*\n/', $raw)) {
        return []; // no frontmatter at all — the blank page is a legal shape
    }
    $out = [];
    // duplicate slug keys are dough the core cannot judge — refused
    $slugCount = preg_match_all('/^slug:/m', $raw);
    if ($slugCount > 1) {
        $out[] = "the frontmatter carries {$slugCount} slug keys — one page, one slug";
    }
    $facts = peek($raw);
    if ($urlSlug !== '' && $facts['slug'] !== null && $facts['slug'] !== $urlSlug) {
        // THE LAW THE OLD CODE NEVER ENFORCED: the frontmatter's own slug key
        // must agree with the URL. The old gate read an HTML COMMENT
        // (<!-- slug: ... -->) and never the key — so slug: other rode in
        // at /pages/hello unmolested (the audit's refusal battery caught it).
        $out[] = "the frontmatter's slug ({$facts['slug']}) disagrees with the URL ({$urlSlug})";
    }
    return $out;
}

/**
 * THE FULL DEFECT LIST — what the REPAIR SERVICE reports, on demand. Wider
 * than the gate: a missing title, a missing draft flag — facts the core
 * tolerates but the author should know about.
 *
 * @return list<string> the defects, human-readable and empty when clean.
 */
function defects(string $raw, string $urlSlug): array
{
    $out = gate($raw, $urlSlug);
    if (!preg_match('/^---\s*\n/', $raw)) {
        return $out; // no frontmatter at all — the blank page is a legal shape
    }
    $facts = peek($raw);
    if ($facts['title'] === null) {
        $out[] = 'the frontmatter carries no title (the slug stands in)';
    }
    if ($facts['slug'] === null) {
        $out[] = 'the frontmatter carries no slug (the URL slug is the truth)';
    }
    if ($facts['draft'] === null) {
        $out[] = 'the frontmatter carries no draft flag';
    }
    return $out;
}

/**
 * THE REPAIR SERVICE — on demand, never silent. Reads a page's raw bytes,
 * reports every defect, repairs what is safely repairable, and answers the
 * user with the per-page list of what was wrong and what was changed.
 *
 * Repairable safely (without inventing content):
 *  - a slug that disagrees with the URL → set to the URL's slug
 *  - a missing title → the URL slug, capitalised (the dashboard's own fallback)
 *  - duplicate slug keys → the last one wins, the duplicates are removed
 *  - a missing draft/published flag → the published shape (draft: false)
 *
 * NOT repairable (reported, never invented): unknown keys (they may be the
 * author's plugin contract), a missing frontmatter block (the page may be a
 * plain body on purpose).
 *
 * @return array{raw: string, report: list<string>, repaired: bool}
 */
function repair(string $raw, string $urlSlug): array
{
    $report = [];
    $repaired = false;

    if (!preg_match('/^---\s*\n/', $raw)) {
        $report[] = 'no frontmatter block — nothing to repair (a plain body is a legal shape)';
        return ['raw' => $raw, 'report' => $report, 'repaired' => false];
    }

    $facts = peek($raw);

    // duplicate slug keys — the LAST one wins, the others leave WHOLE (a line
    // that leaves half of itself behind is the blank line the first cut left)
    $slugCount = preg_match_all('/^slug:.*$\n?/m', $raw);
    if ($slugCount > 1) {
        $seen = 0;
        $raw = (string)preg_replace_callback('/^slug:.*$\n?/m', static function (array $m) use (&$seen): string {
            $seen++;
            return $seen === 1 ? '' : (string)$m[0];
        }, $raw);
        $report[] = "removed " . ($slugCount - 1) . " duplicate slug key(s) (the last one wins)";
        $repaired = true;
    }

    // the slug disagrees with the URL → the URL's slug
    if ($urlSlug !== '' && $facts['slug'] !== null && $facts['slug'] !== $urlSlug) {
        $raw = (string)preg_replace(
            '/^slug:[ \t]*["\']?[a-z0-9]+(?:-[a-z0-9]+)*["\']?[ \t]*$/m',
            'slug: ' . $urlSlug,
            $raw,
            1
        );
        $report[] = "slug repaired: '{$facts['slug']}' → '{$urlSlug}' (the URL is the law)";
        $repaired = true;
    }

    // a missing/empty title → the URL slug, capitalised (the dashboard's own
    // fallback). REPLACE the existing empty line — an inserted twin would
    // leave two title keys, the exact disease the repair exists to cure.
    if ($facts['title'] === null) {
        $title = ucfirst(str_replace('-', ' ', $urlSlug));
        $line = 'title: ' . json_encode($title);
        if (preg_match('/^title:[ \t]*$/m', $raw) === 1) {
            $raw = (string)preg_replace('/^title:[ \t]*$/m', $line, $raw, 1);
            $report[] = "title repaired: the empty value → '{$title}' (the URL slug, capitalised)";
        } else {
            $raw = (string)preg_replace('/^(---[ \t]*\n)/', "\$1{$line}\n", $raw, 1);
            $report[] = "title repaired: absent → '{$title}' (the URL slug, capitalised)";
        }
        $repaired = true;
    }

    // a missing draft flag → the published shape. Same law: replace the
    // existing broken value, insert only when the key is absent.
    if ($facts['draft'] === null) {
        if (preg_match('/^draft:/m', $raw) === 1) {
            $raw = (string)preg_replace('/^draft:.*$/m', 'draft: false', $raw, 1);
            $report[] = 'draft flag repaired: the broken value → draft: false (the published shape)';
        } else {
            $raw = (string)preg_replace('/^(---[ \t]*\n)/', "\$1draft: false\n", $raw, 1);
            $report[] = 'draft flag repaired: absent → draft: false (the published shape)';
        }
        $repaired = true;
    }

    if (!$repaired) {
        $report[] = 'nothing to repair — the frontmatter is clean';
    }
    return ['raw' => $raw, 'report' => $report, 'repaired' => $repaired];
}

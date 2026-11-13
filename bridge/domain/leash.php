<?php

/**
 * 1. Relative path: bridge/domain/leash.php
 * 2. Description: The EDITOR LEASH — the domain's rule for what an editor's
 *                 publish may carry. Published HTML is CLIENT-rendered and
 *                 stored as-is, so whatever an account sends becomes the page
 *                 every visitor executes. A legit editor render can never
 *                 contain executable HTML (the module wall + the URL gate
 *                 strip it at the source) — so anything executable that
 *                 arrives is an editor trying to script the admin's browser.
 *                 Editors get those parts cut; admins publish raw (they own
 *                 the site and its HTML by definition). Cut, not reject: the
 *                 rest of the editor's page is honest work.
 * 3. Expects: Nothing — pure string policy, no HTTP, no disk.
 * 4. Provides: Leash\stripExecutableHtml().
 * 5. SPDX-License-Identifier: MPL-2.0
 * 6. Copyright (c) 2026 Homahuki GmbH
 */

declare(strict_types=1);

namespace Leash;

/** Cut the executable parts out of an editor's rendered HTML. */
function stripExecutableHtml(string $html): string
{
    // <script> blocks — with or without a closing tag (an unclosed opener
    // would swallow the rest of the page into script-land in the browser).
    $html = preg_replace('#<script\b[^>]*>.*?</script\s*>#is', '', $html) ?? '';
    $html = preg_replace('#</?script\b[^>]*>#i', '', $html) ?? '';
    // Inline event handlers: onclick=, onerror=, onload=, … (word-boundary
    // keeps prose like "season=" intact).
    $html = preg_replace('#\s\bon[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html) ?? '';
    // srcdoc hands an iframe a whole executable document of its own.
    $html = preg_replace('#\s\bsrcdoc\s*=\s*("[^"]*"|\'[^\']*\')#i', '', $html) ?? '';
    // Script-bearing URLs in the classic attribute slots — the URL gate
    // already guarantees these never come from a legit render.
    $html = preg_replace('#\s(?:href|src|data|poster|action|xlink:href)\s*=\s*(["\'])\s*(?:javascript|vbscript|data\s*:\s*text\s*/\s*html)[^"\']*\1#i', '', $html) ?? '';
    return $html;
}

/*
 * 1. Relative path: src/public/fallback.ts
 * 2. Description: Client-side fallback renderer — the engine behind the shell PHP
 *                 serves when a page's cached HTML is stale or missing. Fetches the
 *                 raw .md through the public API and renders with the one blessed
 *                 pipeline (renderMdms). Built by scripts/build-fallback.mjs into
 *                 bridge/assets/render.esm.js and committed.
 * 3. Expects: A host page element [data-schmarrn-root] with data-type/data-slug.
 * 4. Provides: The public site's safety net — FTP-edited or cache-less pages still serve.
 * 5. SPDX-License-Identifier: MPL-2.0
 * 6. Copyright (c) 2026 Homahuki GmbH
 */

import { renderMdms } from '../lib/render';

const root = document.querySelector<HTMLElement>('[data-schmarrn-root]');
if (root !== null) {
  const type = root.dataset.type ?? 'pages';
  const slug = root.dataset.slug ?? '';
  fetch(`/bridge/page/${type}/${slug}`)
    .then((res) => (res.ok ? res.text() : Promise.reject(new Error(`API ${res.status}`))))
    .then((raw) => {
      // tokens: true — the fallback shell is a DISPLAY render; the chip
      // previews the tag instead of raw text. The cache/publish renders keep
      // the raw tag for the serve-time PHP filters.
      root.innerHTML = renderMdms(raw, { type, slug, tokens: true }).fullHtml;
      // THE PLUGIN GUARD's fallback face: the shell carries the ENABLED plugin
      // names (data-plugins) — an orphan tag hides here exactly as the cached
      // path's sweep hides it. No plugin names → nothing to judge → the chips
      // stay (fail-open).
      const enabled = (root.dataset.plugins ?? '').split(',').filter(Boolean);
      if (enabled.length > 0) {
        root.querySelectorAll<HTMLElement>('[data-sm-token]').forEach((chip) => {
          const prefix = (chip.dataset.smToken ?? '').split('/')[0] ?? '';
          if (!enabled.includes(prefix)) chip.style.display = 'none';
        });
        // THE FENCE BOUNDARY's fallback face (2026-11-17, user order "fix
        // please"): an orphan PLUGIN fence (4-tick, data-sm-plugin-fence)
        // hides here exactly as the cached path's sweep hides it — same
        // boundary law, both faces. No plugin names → nothing to judge → the
        // fence stays a code block (fail-open).
        root.querySelectorAll<HTMLElement>('[data-sm-plugin-fence]').forEach((pre) => {
          const lang = pre.dataset.smPluginFence ?? '';
          if (lang !== '' && !enabled.includes(lang)) pre.style.display = 'none';
        });
      }
      // Byte-peek the title, same discipline as the PHP side.
      const title = /^title:\s*(.+)$/m.exec(raw)?.[1]?.trim();
      if (title !== undefined && title !== '') {
        document.title = `${title.replace(/^["']|["']$/g, '')} · ${document.title}`;
      }
    })
    .catch(() => {
      root.textContent = 'This page could not be rendered right now.';
    });
}

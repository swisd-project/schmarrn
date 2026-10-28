/*
 * 1. Relative path: src/public/fallback.ts
 * 2. Description: Client-side fallback renderer — the engine behind the shell PHP
 *                 serves when a page's cached HTML is stale or missing. Fetches the
 *                 raw .md through the public API and renders with the one blessed
 *                 pipeline (renderMdms). Built by scripts/build-fallback.mjs into
 *                 api/assets/render.esm.js and committed.
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
  fetch(`/api/page/${type}/${slug}`)
    .then((res) => (res.ok ? res.text() : Promise.reject(new Error(`API ${res.status}`))))
    .then((raw) => {
      root.innerHTML = renderMdms(raw, { type, slug }).fullHtml;
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

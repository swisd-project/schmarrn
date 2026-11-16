/*
 * 1. Relative path: scripts/bake-docs.ts
 * 2. Description: The docs-on-GitHub-Pages bake. Reads a SEPARATE content root
 *                 (docs/content/ — NOT the CMS /src/content/ tree), renders each
 *                 .md through the ONE renderer (renderMdms / mdms; the PHP core
 *                 never parses markdown — store.php:6), fills the bakery template
 *                 with a docs nav, copies the theme assets, and writes static HTML
 *                 to docs/build/ for GitHub Pages. The live CMS is untouched.
 * 3. Expects: docs/content/*.md (the content), the bakery template, tsx.
 * 4. Provides: `npm run build:docs` — static HTML in docs/build/.
 * 5. SPDX-License-Identifier: MPL-2.0
 * 6. Copyright (c) 2026 Homahuki GmbH
 */

import { renderMdms } from '../src/lib/render.ts';
import { readFileSync, writeFileSync, cpSync, mkdirSync, readdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const root = dirname(dirname(fileURLToPath(import.meta.url)));
// The content root defaults to docs/content/ (Murtle's lane); an override lets the
// bake run against any root — a test fixture, or a Pages branch that lives elsewhere.
const contentDir = process.env.DOC_CONTENT_DIR ?? join(root, 'docs', 'content');
const outDir = process.env.DOC_OUT_DIR ?? join(root, 'docs', 'build');
const assetsOut = join(outDir, 'assets');
const themeDir = join(root, 'bridge', 'templates', 'bakery');

// --- The template: the one layout worn by every page (bakery/page.html). ---
// {{themeBase}} in the live app is /bridge/templates/bakery/; on static Pages the
// assets sit next to the page, so the bake drops the base to '' (assets/… resolves
// relatively) and repoints the one absolute noto link at the copied asset.
let template = readFileSync(join(themeDir, 'page.html'), 'utf8');
// Drop the template's authoring comment — it names {{navHtml}} and would get
// filled with the live nav; it's build noise, not page content.
template = template.replace(/<!--[\s\S]*?-->/, '');
// {{themeBase}} in the live app is /bridge/templates/bakery/; on static Pages the
// assets sit next to the page, so the bake drops the base to '' (assets/… resolves
// relatively) and repoints the one absolute noto link at the copied asset.
template = template.replace('/bridge/assets/noto/noto.css', 'assets/noto/noto.css');
// The brand link points at the site root — on GitHub Pages the docs live under a
// SUBPATH (/<repo>/), where "/" is the user's root page, not the docs. Relative
// "./" lands on the docs index under any path.
template = template.replace('href="/"', 'href="./"');

// --- The site (minimal: name + claim, no logo). ---
const site = {
  name: 'Schmarrn',
  claim: 'Markdown CMS, baked to a static docs face.',
};

// --- The content set: index first, then the rest. ---
function slugOf(file: string): string {
  return file.replace(/\.md$/i, '');
}
function humanize(slug: string): string {
  if (slug === 'index') return 'Index';
  return slug.replace(/[-_]+/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase()).trim();
}
function escapeHtml(s: string): string {
  return s
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

const slugs = readdirSync(contentDir)
  .filter((f) => f.endsWith('.md'))
  .map(slugOf)
  .sort((a, b) => {
    if (a === 'index') return -1;
    if (b === 'index') return 1;
    return a.localeCompare(b);
  });

// --- Render every doc first: the nav carries the AUTHOR's title (the frontmatter),
// not a slug-humanized guess ("api-shape" → "API Shape", not "Api Shape").
const docs = slugs.map((slug) => {
  const raw = readFileSync(join(contentDir, `${slug}.md`), 'utf8');
  const { metadata, fullHtml } = renderMdms(raw, { type: 'pages', slug });
  const title =
    typeof metadata.title === 'string' && metadata.title !== '' ? metadata.title : humanize(slug);
  return { slug, title, fullHtml };
});

// --- The docs nav. The live navHtml() reads the CMS store (Serve\navHtml), which
// the bake must NOT touch — so the docs nav is built from the docs set here.
function navHtml(current: string): string {
  const items = docs
    .map(({ slug, title }) => {
      const cur = slug === current ? ' aria-current="page"' : '';
      const href = slug === 'index' ? 'index.html' : `${slug}.html`;
      return `<a href="${href}"${cur}>${escapeHtml(title)}</a>`;
    })
    .join('');
  return items === '' ? '' : `<nav class="sm-nav">${items}</nav>`;
}

function claimHtml(claim: string): string {
  return claim === '' ? '' : `<p class="claim">${escapeHtml(claim)}</p>`;
}

function seoHead(title: string): string {
  const full = title === 'Index' ? site.name : `${title} · ${site.name}`;
  return (
    `<title>${escapeHtml(full)}</title>\n`
    + `<meta property="og:title" content="${escapeHtml(full)}">\n`
    + `<meta property="og:site_name" content="${escapeHtml(site.name)}">\n`
    + `<meta property="og:type" content="website">`
  );
}

// --- Fill the template (the minimal set the layout needs). The live fill() also runs
// plugin contentFilters / placeholderFills + withThemeVars — live-CMS knobs the static
// docs don't carry, so the bake fills only the layout's own placeholders.
function fill(bodyHtml: string, title: string, current: string): string {
  // Authors link to their siblings the natural way ("module-cookbook.md"); the
  // bake ships .html, so a .md link would die on Pages. Rewrite to .html (the
  // anchor rides along).
  const body = bodyHtml.replace(/href="([^"]+)\.md(#[^"]*)?"/g, 'href="$1.html$2"');
  const replacements: Record<string, string> = {
    '{{siteName}}': escapeHtml(site.name),
    '{{claim}}': escapeHtml(site.claim),
    '{{claimHtml}}': claimHtml(site.claim),
    '{{logoUrl}}': '',
    '{{logoHtml}}': '',
    '{{pageTitle}}': escapeHtml(title),
    '{{bodyHtml}}': body,
    '{{seoHead}}': seoHead(title),
    '{{navHtml}}': navHtml(current),
    '{{themeBase}}': '',
    '{{year}}': String(new Date().getFullYear()),
  };
  return template.replace(/{{\w+}}/g, (m) => replacements[m] ?? '');
}

// --- Copy the static assets the layout pulls (theme.css + menu.js + the noto fonts).
mkdirSync(outDir, { recursive: true });
mkdirSync(assetsOut, { recursive: true });
cpSync(join(themeDir, 'assets', 'theme.css'), join(assetsOut, 'theme.css'));
cpSync(join(themeDir, 'assets', 'menu.js'), join(assetsOut, 'menu.js'));
cpSync(join(root, 'bridge', 'assets', 'noto'), join(assetsOut, 'noto'), { recursive: true });

// --- Bake each doc (already rendered above).
for (const { slug, title, fullHtml } of docs) {
  const html = fill(fullHtml, title, slug);
  writeFileSync(join(outDir, `${slug}.html`), html);
  console.log(`  baked ${slug}.html (${fullHtml.length} body bytes)`);
}

console.log(`\nDocs baked → ${outDir}  (${docs.length} pages)`);

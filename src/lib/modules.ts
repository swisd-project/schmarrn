/*
 * 1. Relative path: src/lib/modules.ts
 * 2. Description: Schmarrn v1 modules — the closed set of preset boundaries inside Markdown.
 *                 Syntax: remark-directive (`:::name{key=value "label"}`), rendered to
 *                 clean hast by this rehype plugin, passed to mdms via `plugins`.
 * 3. Expects: remark-directive applied to the mdast; media referenced by page-local filename.
 * 4. Provides: schmarrnModules(context) — the plugin; renderModule() — directive → hast map.
 * 5. SPDX-License-Identifier: MPL-2.0
 * 6. Copyright (c) 2026 Homahuki GmbH
 */

import { visit } from 'unist-util-visit';

// v1 modules (pinned — MCP/BACKLOG Locked Decisions): text is plain Markdown (zero
// boundary). Boundaries shipped: image (+caption, fillable empty slot), video (page
// file or url), embed (url), slide (deck section), note (callout, kind=tip|warn),
// details (disclosure), more (teaser fold marker).
export const MODULE_NAMES = ['image', 'video', 'embed', 'slide', 'note', 'details', 'more'] as const;

/**
 * The injection wall: per-module attribute allowlist. Any directive attribute
 * outside this table never reaches hast — no on*, no style, no data-*, nothing.
 */
const MODULE_ATTRS: Record<string, readonly string[]> = {
  image: ['file', 'caption'],
  video: ['url', 'file'],
  embed: ['url'],
  slide: ['title'],
  note: ['kind'],
  details: ['summary'],
  more: []
};

/** Local media references are plain relative paths — no schemes, no `..` games. */
const SAFE_FILE = /^[\w./-]+$/;

export interface ModuleContext {
  /** Page-local media resolver: filename inside the page folder → URL. */
  readonly mediaUrl?: (file: string) => string;
}

interface HastNode {
  type: 'element' | 'text';
  tagName?: string;
  value?: string;
  properties?: Record<string, unknown>;
  children?: HastNode[];
}

export interface ElementNode {
  readonly tagName: string;
  readonly properties?: Record<string, unknown>;
  readonly children?: NodeAlike[];
}
interface NodeAlike {
  type: string;
  tagName?: string;
  value?: string;
  children?: NodeAlike[];
}

const str = (v: unknown): string => (typeof v === 'string' ? v : Array.isArray(v) ? v.join(' ') : '');

function text(value: string): HastNode {
  return { type: 'text', value };
}

/** Only ever http(s) — anything else in an embed is trickery, not content. */
function safeUrl(url: string): string {
  const trimmed = url.trim();
  return /^https?:\/\//i.test(trimmed) ? trimmed : '';
}

/** Title → anchor id: lowercase, runs of non-alphanumerics become one hyphen. */
function anchorId(title: string): string {
  const slug = title.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
  return slug === '' || /^\d/.test(slug) ? '' : slug;
}

/**
 * The URL gate: the one law every href/src must pass, wherever it came from —
 * plain markdown links, images, module output, all of it. Dangerous schemes
 * (javascript:, data:, vbscript:, and their mixed-case/entity costumes, which
 * arrive here already decoded) never reach published HTML. Site-relative and
 * http(s)/mailto/tel pass; anything unparseable is stripped, not guessed.
 */
function urlPasses(raw: string): boolean {
  const compact = raw.replace(/[\s\x00-\x1f\x7f]/g, '').toLowerCase();
  if (compact === '') return false;
  // A scheme exists only when its ':' comes before the first / ? # — so
  // 'photo.jpg' is a plain relative path (fine), 'mailto:x@y.z' names its
  // scheme honestly (fine), and 'java script:…' with whitespace stripped
  // must name itself honestly too — anything unnamed is refused.
  const colon = compact.indexOf(':');
  const slash = compact.search(/[/?#]/);
  if (colon !== -1 && (slash === -1 || colon < slash)) {
    return /^(https?|mailto|tel):$/.test(compact.slice(0, colon + 1));
  }
  return true;
}

const URL_ATTRS: Record<string, readonly string[]> = {
  a: ['href'],
  img: ['src'],
  video: ['src', 'poster'],
  audio: ['src'],
  source: ['src'],
  track: ['src'],
  iframe: ['src'],
  embed: ['src']
};

/** Rehype plugin: runs after the module wall and sweeps every URL attribute. */
export function safeUrls() {
  return () => (tree: unknown) => {
    visit(tree, 'element' as never, (node: any) => {
      const attrs = URL_ATTRS[node.tagName];
      if (!attrs) return;
      for (const attr of attrs) {
        const value = node.properties?.[attr];
        if (typeof value === 'string' && !urlPasses(value)) {
          // Links collapse to a harmless anchor; media loses the attribute.
          node.properties[attr] = attr === 'href' ? '#' : '';
        }
      }
    });
  };
}

/** Trim the directive payload: only an http(s) URL survives. */
function cleanUrl(url: string): string {
  return safeUrl(url);
}

/** Directive → hast. Unknown names return null (unrendered, plain).
 *  state carries the per-document id registry (slides claim anchors). */
export function renderModule(
  node: ElementNode,
  ctx: ModuleContext,
  state: { readonly usedIds: Map<string, number> } = { usedIds: new Map() }
): HastNode | null {
  const props = node.properties ?? {};
  const alt = str(node.children?.[0]?.value).trim();

  switch (node.tagName) {
    case 'image': {
      const file = str(props.file);
      const caption = str(props.caption);
      if (file === '') {
        // The EMPTY image slot: a visible, fillable placeholder. Page-type
        // scaffolds seed these; the user clicks one and fills it. No file, no
        // src, nothing to inject — a labeled box, by design.
        return {
          type: 'element',
          tagName: 'figure',
          properties: { className: 'sm-module sm-image sm-empty-slot' },
          children: [{ type: 'element' as const, tagName: 'figcaption', properties: {}, children: [text(caption !== '' ? caption : (alt !== '' ? alt : 'Empty image slot — click to fill'))] }]
        };
      }
      const remote = /^https?:\/\//i.test(file);
      // Defense in depth: local files must be plain paths even when the stamper was bypassed.
      if (!remote && !SAFE_FILE.test(file)) return null;
      let src = remote ? cleanUrl(file) : ctx.mediaUrl ? ctx.mediaUrl(file) : file;
      if (src === '') return null;
      let img: HastNode = {
        type: 'element',
        tagName: 'img',
        properties: { src, alt: alt || caption, loading: 'lazy' },
        children: []
      };
      const content = caption
        ? [img, { type: 'element' as const, tagName: 'figcaption', properties: {}, children: [text(caption)] }]
        : [img];
      return {
        type: 'element',
        tagName: 'figure',
        properties: { className: 'sm-module sm-image' },
        children: content
      };
    }
    case 'video': {
      const url = str(props.url);
      const file = str(props.file);
      const src = safeUrl(url) || (/^[\w./-]+$/.test(file) && ctx.mediaUrl ? ctx.mediaUrl(file) : '');
      if (src === '') return null;
      const video: HastNode = {
        type: 'element',
        tagName: 'video',
        properties: { controls: true, src },
        children: []
      };
      return {
        type: 'element',
        tagName: 'figure',
        properties: { className: 'sm-module sm-video' },
        children: [video]
      };
    }
    case 'embed': {
      const src = safeUrl(str(props.url));
      if (src === '') return null;
      const frame: HastNode = {
        type: 'element',
        tagName: 'iframe',
        properties: { src, title: alt || 'Embed', loading: 'lazy', allowfullscreen: true },
        children: []
      };
      return {
        type: 'element',
        tagName: 'figure',
        properties: { className: 'sm-module sm-embed' },
        children: [frame]
      };
    }
    case 'slide': {
      // A deck slide: one full-viewport section. The slide template paginates the
      // stage vertically (scroll-snap, smooth); in a classical page the sections
      // simply stack. Children pass through untouched — slides are markdown inside.
      // The title doubles as the anchor: '[to the proof](#the-proof)' links to it.
      const title = str(props.title).replace(/["<>&]/g, '').trim();
      const id = anchorId(title);
      if (id !== '') {
        const seen = state.usedIds.get(id) ?? 0;
        state.usedIds.set(id, seen + 1);
        // Duplicates rank: "the-proof" then "the-proof-2" — every link lands somewhere.
        const anchor = seen === 0 ? id : `${id}-${seen + 1}`;
        return {
          type: 'element',
          tagName: 'section',
          properties: {
            className: 'sm-slide',
            id: anchor,
            ...(title !== '' ? { 'data-slide-title': title, 'aria-label': title } : {})
          },
          children: (node.children ?? []) as HastNode[]
        };
      }
      return {
        type: 'element',
        tagName: 'section',
        properties: { className: 'sm-slide', ...(title !== '' ? { 'data-slide-title': title, 'aria-label': title } : {}) },
        children: (node.children ?? []) as HastNode[]
      };
    }
    case 'note': {
      // Callout box. kind="tip" or kind="warn"; anything else is the honest default.
      const kind = str(props.kind) === 'tip' || str(props.kind) === 'warn' ? ` sm-note-${str(props.kind)}` : '';
      return {
        type: 'element',
        tagName: 'aside',
        properties: { className: `sm-module sm-note${kind}` },
        children: (node.children ?? []) as HastNode[]
      };
    }
    case 'details': {
      // Native disclosure — details/summary, zero JS.
      const summary = str(props.summary) || alt || 'More';
      const summaryEl: HastNode = {
        type: 'element',
        tagName: 'summary',
        properties: {},
        children: [text(summary.replace(/["<>&]/g, ''))]
      };
      return {
        type: 'element',
        tagName: 'details',
        properties: { className: 'sm-module sm-details' },
        children: [summaryEl, ...((node.children ?? []) as HastNode[])]
      };
    }
    case 'more': {
      // The teaser fold — where a listing (the blog plugin's future home) stops.
      // Renders as a quiet, visible fold so editors always see the boundary.
      return {
        type: 'element',
        tagName: 'hr',
        properties: { className: 'sm-more' },
        children: []
      };
    }
    default:
      return null;
  }
}


/** Drop-in factory for mdms `plugins`. Walks hast, spawns module HTML in place. */
export function schmarrnModules(ctx: ModuleContext = {}) {
  return () => (tree: unknown) => {
    // Generated output must never be re-processed when the walk descends into it.
    const generated = new WeakSet<object>();
    // Per-document registry: slide anchors stay unique within one page render.
    const usedIds = new Map<string, number>();
    const markAll = (node: HastNode): void => {
      generated.add(node);
      node.children?.forEach(markAll);
    };
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    visit(tree, 'element' as never, (node: any) => {
      const html = renderModule(node as ElementNode, ctx, { usedIds });
      if (html !== null) {
        Object.assign(node, { tagName: html.tagName, properties: { ...(html.properties ?? {}) }, children: html.children });
        markAll(node);
        return;
      }
      // A module directive that failed validation must not survive as a raw tag
      // with its raw attributes — de-fang it exactly like an unknown name.
      if ((MODULE_NAMES as readonly string[]).includes(node.tagName) && !generated.has(node)) {
        node.tagName = 'p';
        node.properties = { className: 'sm-unknown-module' };
        node.children = [];
      }
    });
  };
}

/**
 * Remark-phase companion: stamp directive nodes with hName/hProperties so
 * remark-rehype builds real elements (tagName = module name) instead of divs.
 * Register AFTER remarkDirective in mdastPlugins.
 */
export function stampDirectives() {
  return () => (tree: unknown) => {
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    visit(
      tree,
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      (node: any) =>
        node.type === 'containerDirective' || node.type === 'leafDirective' || node.type === 'textDirective',
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      (node: any) => {
        const known = (MODULE_NAMES as readonly string[]).includes(node.name);
        if (!known) {
          // Unknown module names must never become live tags — de-fang to a visible marker.
          node.data = { ...(node.data ?? {}), hName: 'p', hProperties: { className: 'sm-unknown-module' } };
          return;
        }
        // Allowlist pass: only the pinned attributes survive, so `on*`, `style`,
        // `srcdoc` & friends are dead before hast is even built.
        const props: Record<string, string> = {};
        for (const key of MODULE_ATTRS[node.name] ?? []) {
          const value = node.attributes?.[key];
          if (typeof value === 'string' && value !== '') props[key] = value;
        }
        if (typeof props.file === 'string' && !SAFE_FILE.test(props.file) && !/^https?:\/\//i.test(props.file)) {
          delete props.file;
        }
        node.data = { ...(node.data ?? {}), hName: node.name, hProperties: props };
      }
    );
  };
}

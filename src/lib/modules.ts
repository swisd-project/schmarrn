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
// boundary). Boundaries shipped: image (+caption), video (page file or url), embed (url).
export const MODULE_NAMES = ['image', 'video', 'embed'] as const;

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

/** Trim the directive payload: only an http(s) URL survives. */
function cleanUrl(url: string): string {
  return safeUrl(url);
}

/** Directive → hast. Unknown names return null (unrendered, plain). */
export function renderModule(
  node: ElementNode,
  ctx: ModuleContext
): HastNode | null {
  const props = node.properties ?? {};
  const alt = str(node.children?.[0]?.value).trim();

  switch (node.tagName) {
    case 'image': {
      const file = str(props.file);
      const caption = str(props.caption);
      let src = /^https?:\/\//i.test(file) ? cleanUrl(file) : ctx.mediaUrl ? ctx.mediaUrl(file) : file;
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
    default:
      return null;
  }
}


/** Drop-in factory for mdms `plugins`. Walks hast, spawns module HTML in place. */
export function schmarrnModules(ctx: ModuleContext = {}) {
  return () => (tree: unknown) => {
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    visit(tree, 'element' as never, (node: any) => {
      const html = renderModule(node as ElementNode, ctx);
      if (html === null) return;
      Object.assign(node, { tagName: html.tagName, properties: { ...(html.properties ?? {}) }, children: html.children });
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
        node.data = {
          ...(node.data ?? {}),
          // Unknown module names must never become live tags — de-fang to a visible marker.
          hName: known ? node.name : 'p',
          hProperties: known ? { ...(node.attributes ?? {}) } : { className: 'sm-unknown-module' }
        };
      }
    );
  };
}

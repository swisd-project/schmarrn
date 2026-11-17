/*
 * 1. Relative path: src/lib/render.ts
 * 2. Description: The ONE place that wires mdms with Schmarrn's plugin set:
 *                 remark-directive (mdast phase) + module renderer (rehype phase).
 *                 Editor preview and public renders both go through this — by law.
 * 3. Expects: Raw markdown, page context for module media resolution.
 * 4. Provides: renderMdms(), the exact parse call everything shares.
 * 5. SPDX-License-Identifier: MPL-2.0
 * 6. Copyright (c) 2026 Homahuki GmbH
 */

import remarkDirective from 'remark-directive';
import { parseMdms } from '@swisd-project/mdms';
import { schmarrnModules, safeUrls, stampDirectives, tokenHook, fenceBoundary } from './modules';

export interface RenderContext {
  /** Page type for the per-type Zod frontmatter gate. */
  readonly type: string;
  /** Page slug; module files resolve inside this page's media folder. */
  readonly slug: string;
  /** Optional frontmatter validator type (defaults to per-type Zod .parse via schema.ts caller). */
  readonly validateFrontmatter?: (data: unknown) => Record<string, unknown>;
  /** The token hook (p6 slice 6): plugin-tag chips ({{prefix_name}}) in the
   *  rendered DISPLAY. OPT-IN — only the display renders turn it on (the
   *  editor canvas, the public fallback shell). Every cache/publish render
   *  keeps it OFF: the cached HTML must carry the RAW tag or the serve-time
   *  PHP content filters never resolve it. Default: off — a caller that
   *  forgets the flag gets today's bytes, never a broken public render. */
  readonly tokens?: boolean;
}

/** Page-local media module URL (v1: served raw through the PHP media route). */
export function mediaUrl(context: { type: string; slug: string }): (file: string) => string {
  return (file: string) => `/bridge/media/${context.type}/${context.slug}/${encodeURIComponent(file)}`;
}

export function renderMdms(raw: string, context: RenderContext) {
  const plugins = [schmarrnModules({ mediaUrl: mediaUrl(context) }), safeUrls()];
  if (context.tokens === true) plugins.push(tokenHook());
  return parseMdms(raw, {
    mdastPlugins: [remarkDirective, stampDirectives(), fenceBoundary(raw)],
    plugins,
    validateFrontmatter: context.validateFrontmatter
  });
}

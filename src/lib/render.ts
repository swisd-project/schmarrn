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
import { schmarrnModules, stampDirectives } from './modules';

export interface RenderContext {
  /** Page type for the per-type Zod frontmatter gate. */
  readonly type: string;
  /** Page slug; module files resolve inside this page's media folder. */
  readonly slug: string;
  /** Optional frontmatter validator type (defaults to per-type Zod .parse via schema.ts caller). */
  readonly validateFrontmatter?: (data: unknown) => Record<string, unknown>;
}

/** Page-local media module URL (v1: served raw through the PHP media route). */
export function mediaUrl(context: { type: string; slug: string }): (file: string) => string {
  return (file: string) => `/api/media/${context.type}/${context.slug}/${encodeURIComponent(file)}`;
}

export function renderMdms(raw: string, context: RenderContext) {
  const plugins = schmarrnModules({ mediaUrl: mediaUrl(context) });
  return parseMdms(raw, {
    mdastPlugins: [remarkDirective, stampDirectives()],
    plugins: [plugins],
    validateFrontmatter: context.validateFrontmatter
  });
}

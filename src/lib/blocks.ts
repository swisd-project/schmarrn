/*
 * 1. Relative path: src/lib/blocks.ts
 * 2. Description: The canvas editor's block model — splits the markdown body into
 *                 top-level blocks (text or module) with exact source spans, so
 *                 edits replace whole blocks and the document rejoins losslessly.
 *                 Frontmatter is carried separately, untouched.
 * 3. Expects: remark-parse + remark-directive (same parser family as rendering).
 * 4. Provides: splitFrontmatter, splitBlocks, joinPage.
 * 5. SPDX-License-Identifier: MPL-2.0
 * 6. Copyright (c) 2026 Homahuki GmbH
 */

import { unified } from 'unified';
import remarkParse from 'remark-parse';
import remarkDirective from 'remark-directive';

export interface Block {
  /** text = plain Markdown block; module = a `:::name{…}` directive container. */
  readonly kind: 'text' | 'module';
  /** Directive name for modules ('image' | 'video' | 'embed' | unknown). */
  readonly name?: string;
  /** Exact markdown source of this block (no surrounding blank lines). */
  readonly source: string;
}

export interface PageSplit {
  /** Frontmatter including its `---` fences ('' when none). */
  readonly frontmatter: string;
  /** Body without frontmatter, whitespace-trimmed at the edges. */
  readonly body: string;
}

export function splitFrontmatter(raw: string): PageSplit {
  const match = /^---\r?\n([\s\S]*?)\r?\n---(?:\r?\n|$)/.exec(raw);
  if (match === null) {
    return { frontmatter: '', body: raw.trim() };
  }
  return { frontmatter: `---\n${match[1]}\n---`, body: raw.slice(match[0].length).trim() };
}

/** Directive node types per remark-directive. */
function isDirective(type: string): boolean {
  return type === 'containerDirective' || type === 'leafDirective' || type === 'textDirective';
}

/** Top-level blocks with source spans. Blank lines between blocks are normalized away. */
// eslint-disable-next-line @typescript-eslint/no-explicit-any
export function splitBlocks(body: string): Block[] {
  if (body === '') return [];
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  const tree = unified().use(remarkParse).use(remarkDirective).parse(body) as any;
  const lines = body.split('\n');
  const blocks: Block[] = [];
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  for (const child of tree.children as any[]) {
    if (child.position === undefined || child.position === null) continue;
    const source = lines.slice(child.position.start.line - 1, child.position.end.line).join('\n');
    blocks.push(
      isDirective(child.type)
        ? { kind: 'module', name: String(child.name ?? ''), source }
        : { kind: 'text', source }
    );
  }
  return blocks;
}

/** Reassemble the document: frontmatter verbatim, blocks joined by blank lines. */
export function joinPage(split: PageSplit, blocks: readonly Block[]): string {
  const body = blocks.map((b) => b.source).join('\n\n');
  return split.frontmatter === '' ? body : `${split.frontmatter}\n\n${body}`;
}

/** Directive container payload between the fences ('' when absent). */
export function directiveInner(source: string): string {
  const lines = source.split('\n');
  return lines.length >= 3 ? lines.slice(1, -1).join('\n') : '';
}

/** Quote-safe directive attribute value: no quotes, no line breaks. */
export function attr(value: string): string {
  return value.replace(/"/g, '').replace(/\s+/g, ' ').trim();
}

/**
 * 1. Relative path: scripts/directive-debug.mts
 * 2. Description: Manual unified chain probe — where do directives die?
 * 3. Expects: Repo root cwd.
 * 4. Provides: stdout of rendered outputs + mdast dump.
 * 5. SPDX-License-Identifier: MPL-2.0
 * 6. Copyright (c) 2026 Homahuki GmbH
 */
import { unified } from 'unified';
import remarkParse from 'remark-parse';
import remarkDirective from 'remark-directive';
import { visit } from 'unist-util-visit';

const src = ':::image{file="a.jpg"} hi\n:::\n';

const p = unified().use(remarkParse).use(remarkDirective);
const tree = p.runSync(p.parse(src));
visit(tree, (node: unknown) => {
  const t = (node as { type: string }).type;
  return t === 'directive' || t === 'containerDirective' || t === 'leafDirective';
}, (node: unknown) => {
  console.log('directive found:', JSON.stringify(node));
});
console.log(JSON.stringify(tree, null, 1).slice(0, 1200));

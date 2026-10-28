/**
 * 1. Relative path: scripts/syntax-probe.mts
 * 2. Description: Which directive syntax variants micromark-extension-directive accepts?
 * 3. Expects: Repo root cwd.
 * 4. Provides: type lists per variant.
 * 5. SPDX-License-Identifier: MPL-2.0
 * 6. Copyright (c) 2026 Homahuki GmbH
 */
import { fromMarkdown } from 'mdast-util-from-markdown';
import { directiveFromMarkdown } from 'mdast-util-directive';
import { directive as directiveMm } from 'micromark-extension-directive';

const variants: [string, string][] = [
  ['leaf, no attrs', ':::image{file=a.jpg} hi\n:::'],
  ['leaf, attrs line', ':::image\n{file=a.jpg}\n:::'],
  ['container, attrs', ':::image{file=a.jpg}\nhi\n:::'],
  ['container, plain', ':::image\nhi\n:::'],
  ['leaf, plain', '::image\n'],
  ['leaf inline', ':image[a.jpg]{file=a.jpg}'],
];

for (const [label, src] of variants) {
  const tree = fromMarkdown(src, { extensions: [directiveMm()], mdastExtensions: [directiveFromMarkdown()] });
  console.log(label, '=>', JSON.stringify(tree.children.map((c) => c.type)));
}

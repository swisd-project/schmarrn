/**
 * 1. Relative path: scripts/hast-dump.mts
 * 2. Description: Dump the hast after the mdms chain to see module element shapes.
 * 3. Expects: Repo root cwd.
 * 4. Provides: JSON dump.
 * 5. SPDX-License-Identifier: MPL-2.0
 * 6. Copyright (c) 2026 Homahuki GmbH
 */
import remarkDirective from 'remark-directive';
import { parseMdms } from '@swisd-project/mdms';
import { schmarrnModules } from '../src/lib/modules';

const raw = ':::image{file="a.jpg" caption="cap"}\nalt text\n:::\n';
const result = parseMdms(raw, {
  mdastPlugins: [remarkDirective],
  plugins: [schmarrnModules({ mediaUrl: (f) => `/m/${f}` })]
});
console.log(result.fullHtml);

/**
 * 1. Relative path: scripts/mdms-probe.mts
 * 2. Description: Minimal probe — mdastPlugins wiring in the installed mdms build.
 * 3. Expects: Repo root cwd.
 * 4. Provides: Prints rendered HTML for one directive.
 * 5. SPDX-License-Identifier: MPL-2.0
 * 6. Copyright (c) 2026 Homahuki GmbH
 */
import { parseMdms } from '@swisd-project/mdms';
import remarkDirective from 'remark-directive';

const r = parseMdms(':::image{file="a.jpg"} hi\n:::', { mdastPlugins: [remarkDirective] });
console.log(r.fullHtml);

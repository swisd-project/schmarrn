/**
 * 1. Relative path: scripts/module-probe.mts
 * 2. Description: Dev probe — renders the v1 module set through mdms + remark-directive
 *                 and prints the HTML. Manual, like the PHP smoke curl: eyes on.
 * 3. Expects: Repo root cwd (node_modules present).
 * 4. Provides: `npm run probe:modules` output. Not an automated test (yet).
 * 5. SPDX-License-Identifier: MPL-2.0
 * 6. Copyright (c) 2026 Homahuki GmbH
 */
import { renderMdms } from '../src/lib/render';

const raw = `---
title: Module probes
---

Greetings, plain paragraph first.

:::image{file="20261028-cafe.jpg" caption="A cappuccino and a hush"}
cozy shot
:::

:::video{url="https://tube.example/v/xyz"}
:::

:::image{file="https://img.example/pic.png"}
:::

:::evil{onclick="pwn()"}
:::
`;
const html = renderMdms(raw, { type: 'pages', slug: 'probes' }).fullHtml;
console.log(html);

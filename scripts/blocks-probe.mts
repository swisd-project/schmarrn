/**
 * 1. Relative path: scripts/blocks-probe.mts
 * 2. Description: Round-trip probe — split a page into blocks, mutate, rejoin.
 * 3. Expects: Repo root cwd.
 * 4. Provides: stdout of the split + rejoined document.
 * 5. SPDX-License-Identifier: MPL-2.0
 * 6. Copyright (c) 2026 Homahuki GmbH
 */
import { splitFrontmatter, splitBlocks, joinPage } from '../src/lib/blocks';

const raw = `---
title: Probe tart
draft: false
---

First paragraph here.

## A heading

:::image{file="a.jpg" caption="cap"}
alt words
:::

:::embed{url="https://example.test/e"}
:::

Last words.
`;

const split = splitFrontmatter(raw);
console.log('frontmatter:', JSON.stringify(split.frontmatter));
const blocks = splitBlocks(split.body);
for (const [i, b] of blocks.entries()) console.log(i, b.kind, b.name ?? '', JSON.stringify(b.source));
// mutate: move the embed above the heading, delete nothing
const embed = blocks.findIndex((b) => b.name === 'embed');
const heading = blocks.findIndex((b) => b.kind === 'text' && b.source.startsWith('##'));
[blocks[embed], blocks[heading]] = [blocks[heading], blocks[embed]];
const rejoined = joinPage(split, blocks);
console.log('--- rejoined ---');
console.log(rejoined);
// stability: split again → same block count
console.log('re-split count:', splitBlocks(splitFrontmatter(rejoined).body).length);

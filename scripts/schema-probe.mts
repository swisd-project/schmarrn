/**
 * 1. Relative path: scripts/schema-probe.mts
 * 2. Description: Probe — human-readable validation issues + draft-flag flip.
 * 3. Expects: Repo root cwd.
 * 4. Provides: stdout of formatted issues and flag flips.
 * 5. SPDX-License-Identifier: MPL-2.0
 * 6. Copyright (c) 2026 Homahuki GmbH
 */
import { frontmatterValidator, withDraftFlag } from '../src/lib/schema';

// Broken frontmatter → human-readable, no JSON dump.
try {
  frontmatterValidator('pages')({});
} catch (e) {
  console.log('issues:', e instanceof Error ? e.message : e);
}

const doc = '---\ntitle: T\nslug: t\n---\n\nbody';
console.log('insert flag:', JSON.stringify(withDraftFlag(doc, true).split('\n').slice(0, 3)));
console.log('flip flag:', JSON.stringify(withDraftFlag(withDraftFlag(doc, true), false).split('\n').slice(0, 4)));

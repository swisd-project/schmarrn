/*
 * 1. Relative path: scripts/build-edit.mjs
 * 2. Description: Builds the live-page editor (src/public/edit.ts) into
 *                 api/assets/edit.esm.js — a committed, self-contained ES module
 *                 the serving engine injects ONLY for authenticated sessions.
 *                 No Node on the deploy host; the bundle ships baked.
 * 3. Expects: esbuild (devDependency).
 * 4. Provides: `npm run build:edit`.
 * 5. SPDX-License-Identifier: MPL-2.0
 * 6. Copyright (c) 2026 Homahuki GmbH
 */

import { build } from 'esbuild';

await build({
  entryPoints: ['src/public/edit.ts'],
  bundle: true,
  format: 'esm',
  target: 'es2020',
  minify: true,
  outfile: 'api/assets/edit.esm.js',
  logLevel: 'info'
});

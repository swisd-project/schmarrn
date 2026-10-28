/*
 * 1. Relative path: scripts/build-fallback.mjs
 * 2. Description: Builds the client-side fallback renderer (src/public/fallback.ts)
 *                 into api/assets/render.esm.js — a committed, self-contained ES
 *                 module the PHP fallback shell loads. No Node on the deploy host;
 *                 the bundle ships baked.
 * 3. Expects: esbuild (devDependency).
 * 4. Provides: `npm run build:fallback`.
 * 5. SPDX-License-Identifier: MPL-2.0
 * 6. Copyright (c) 2026 Homahuki GmbH
 */

import { build } from 'esbuild';

await build({
  entryPoints: ['src/public/fallback.ts'],
  bundle: true,
  format: 'esm',
  target: 'es2020',
  minify: true,
  outfile: 'api/assets/render.esm.js',
  logLevel: 'info'
});

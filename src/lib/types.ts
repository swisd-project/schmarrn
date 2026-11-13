/*
 * 1. Relative path: src/lib/types.ts
 * 2. Description: Shared dashboard types — a THIN RE-EXPORT since the api.ts
 *                 split (2026-11-13): types live with their bridge module;
 *                 this file keeps the old import paths alive.
 * 3. Expects: The api modules' own type exports.
 * 4. Provides: Strict types; no any, not today, not for you.
 * 5. SPDX-License-Identifier: MPL-2.0
 * 6. Copyright (c) 2026 Homahuki GmbH
 */

export type { PageRef, PageIndex } from './api/pages';
export type { EditorBlockDef } from './api/plugins';

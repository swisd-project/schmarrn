/*
 * 1. Relative path: src/lib/surgery.ts
 * 2. Description: THE SURGERY HANDSHAKE — the one shared vocabulary for the
 *                 raw-sheet surgery flag. The dashboard's raw desk (ContentsTab)
 *                 WRITES the flag; the live-page editor (edit.ts) READS it and
 *                 deafens its edit chrome while surgery is open. One key, one
 *                 value shape, one class name — never three literals drifting
 *                 apart again (2026-11-13, the shared-contract ride).
 * 3. Expects: sessionStorage on the dashboard↔live-page hop (same origin).
 * 4. Provides: SURGERY_KEY, surgeryPath(), SURGERY_CLASS.
 * 5. SPDX-License-Identifier: MPL-2.0
 * 6. Copyright (c) 2026 Homahuki GmbH
 */

/** The sessionStorage key the surgery state rides (dashboard → live page). */
export const SURGERY_KEY = 'sm-raw-surgery';

/** The flag's value: `type/slug` — the file in surgery, exactly one. */
export function surgeryPath(type: string, slug: string): string {
  return `${type}/${slug}`;
}

/** The CSS class the editor wears while the sheet is open — its chrome deafens. */
export const SURGERY_CLASS = 'sm-raw-surgery';

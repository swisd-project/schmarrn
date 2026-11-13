/*
 * 1. Relative path: src/lib/toast.svelte.ts
 * 2. Description: THE TOAST HEARTH (2026-11-13, user order — the dragon): one
 *                 proper toast system for the dashboard, retiring the
 *                 scattered alert-ok note lines. Success rides float in
 *                 bottom-right and burn out on their own; errors stay inline
 *                 where the eye already is (forms, the desk foot). Runes in
 *                 module scope — hence the .svelte.ts.
 * 3. Expects: Mounted once via src/lib/components/Toasts.svelte.
 * 4. Provides: toasts (the live stack), toast() (push one), burnToast().
 * 5. SPDX-License-Identifier: MPL-2.0
 * 6. Copyright (c) 2026 Homahuki GmbH
 */

export interface Toast {
  readonly id: number;
  readonly kind: 'ok' | 'warn';
  readonly message: string;
}

export const toasts = $state<Toast[]>([]);

let nextId = 1;

/** Raise one. `warn` for rides that landed but carry a caveat. */
export function toast(message: string, kind: 'ok' | 'warn' = 'ok'): void {
  const id = nextId++;
  toasts.push({ id, kind, message });
  setTimeout(() => burnToast(id), 4500);
}

export function burnToast(id: number): void {
  const i = toasts.findIndex((t) => t.id === id);
  if (i !== -1) toasts.splice(i, 1);
}

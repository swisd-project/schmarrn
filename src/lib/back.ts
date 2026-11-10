// The pill → overlay → home round trip, as pure law (node-testable, no DOM).
//
// 2026-11-04 audit: the first homecoming attempt used history.back() — a dice
// roll (a session-restored tab can satisfy history.length > 1 with no real back
// entry; back() then no-ops and strands the operator on a blank /_). The pill
// KNOWS the page it lives on, so it carries the destination instead: opening
// goes to /_?back=<where>, and Close walks exactly there.

export const backParam = 'back';

/** The drawer's ride: open the overlay, carrying the page it was born on —
 *  and optionally the tab to land on (contents|settings|wares|users).
 *  Canonical /_/ path (trailing slash) — avoids the server's /_ → /_/ redirect
 *  which would otherwise risk stripping intent params. */
export function backHref(pathname: string, hash = '', tab = ''): string {
  const tabPart = tab !== '' ? `&tab=${encodeURIComponent(tab)}` : '';
  return `/_/?${backParam}=${encodeURIComponent(pathname + hash)}${tabPart}`;
}

/**
 * Same-origin path guard over an ALREADY-decoded draft (URLSearchParams did its
 * one decode; a second decode resurrects smuggled escapes — audited bug).
 * Accepts only a leading single '/' with no scheme tricks (//, \).
 */
export function backTarget(draft: string | null): string | null {
  if (draft === null) return null;
  const decoded = draft;
  if (
    decoded === ''
    || !decoded.startsWith('/')
    || decoded.startsWith('//')
    || decoded.includes('\\')
    || decoded.includes('\n')
    || decoded.includes('\r')
  ) {
    return null;
  }
  return decoded;
}

/** Close target: the carried page when it smells sane, else the front door. */
export function closeTarget(search: string): string {
  return backTarget(new URLSearchParams(search).get(backParam)) ?? '/';
}

/**
 * Close target that keeps ADMIN STATE active (2026-11 user fix): closing the
 * overlay must never strand the editor — it returns to the carried page in
 * admin mode (visual editor + drawer). A plain carried path is promoted under
 * /_ so the editor rides; no carried page returns null and the caller lands on
 * an admin-mode home page. Only Logout leaves admin mode for the public face.
 */
export function adminCloseTarget(search: string): string | null {
  const target = backTarget(new URLSearchParams(search).get(backParam));
  if (target === null) return null;
  if (target === '/_' || target.startsWith('/_/')) return target;
  if (target === '/') return null; // no specific page — let the caller pick a live admin landing
  // KEEP ADMIN MODE: a plain carried path is ridden under /_ so the editor
  // never drops. Logout is the only way out — never return a plain page URL.
  return '/_' + target;
}

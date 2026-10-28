/*
 * deck.js — the slide deck's guard: the rest is native.
 *
 * Layout scroll, snapping (scroll-snap-type on the root, scroll-snap-stop on
 * each slide) and gliding anchor links are pure CSS — the browser does them
 * with its own momentum, its own keys, its own hash handling. The one edge a
 * script is needed for: browsers can arrive mid-air (scroll restoration, back
 * navigation) and snap somewhere strange — deck.js nonzeroes the deck at load
 * unless a hash names a slide.
 *
 * Deployed inside themes/<name>/slide.html. SPDX-License-Identifier: MPL-2.0
 * Copyright (c) 2026 Homahuki GmbH
 */
(() => {
  const stage = document.querySelector('.slide-stage');
  if (!stage || !stage.querySelector(':scope > .sm-slide')) return;

  if ('scrollRestoration' in history) history.scrollRestoration = 'manual';
  window.addEventListener('load', () => {
    if (!location.hash) window.scrollTo({ top: 0 });
  });
})();

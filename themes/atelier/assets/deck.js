/*
 * deck.js — the slide deck's helper script.
 *
 * The deck is CSS-first: the stage scroll-snaps vertically and the scrollbar
 * stays hidden without any script. What a script is genuinely needed for:
 *  - real slide heights (a measured px minimum beats the 100% guesswork that
 *    collapses slides when the stage's own flex height is indeterminate);
 *  - anchor links (#title) gliding instead of jumping — mandatory snap can
 *    fight the browser's instant fragment scroll, and smooth fragment
 *    navigation is unreliable across browsers;
 *  - landing softly on the slide a URL hash points at.
 *
 * Deployed inside themes/<name>/slide.html. No dependencies. Passive listener.
 * SPDX-License-Identifier: MPL-2.0
 * Copyright (c) 2026 Homahuki GmbH
 */
(() => {
  const stage = document.querySelector('.slide-stage');
  if (!stage || !stage.querySelector(':scope > .sm-slide')) return;
  const slides = stage.querySelectorAll(':scope > .sm-slide');

  // Heights: the stage tells the truth; the CSS fallback stays for no-JS eyes.
  const fit = () => stage.style.setProperty('--sm-slide-h', `${stage.clientHeight}px`);
  fit();
  window.addEventListener('resize', fit, { passive: true });

  const byId = (id) =>
    id !== '' && stage.querySelector(`.sm-slide[id="${CSS.escape(id)}"]`);

  // Anchor links inside the deck glide; instant jumps die here.
  document.addEventListener('pointerdown', (e) => {
    const link = e.target.closest('a[href^="#"]');
    if (!link) return;
    const slide = byId(decodeURIComponent(link.hash.slice(1)));
    if (!slide) return;
    e.preventDefault();
    requestAnimationFrame(() => {
      slide.scrollIntoView({ behavior: 'smooth', block: 'start' });
      history.replaceState(null, '', `#${slide.id}`);
    });
  });

  // Arriving with a hash (reload, shared link): land on the slide, smoothly.
  window.addEventListener('load', () => {
    const slide = byId(location.hash.slice(1));
    if (slide) requestAnimationFrame(() => slide.scrollIntoView({ behavior: 'smooth', block: 'start' }));
  });
})();

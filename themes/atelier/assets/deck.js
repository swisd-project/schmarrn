/*
 * deck.js — the slide deck's companion: dots, active state, mid-air guard.
 *
 * The deck's SCROLL is native (scroll-snap on the root, momentum, keys, hash).
 * What the script adds — the parts fullPage.js charges for and we do not:
 *  - dot navigation on the right edge, one dot per slide, tooltips from the
 *    slide titles (`aria-label`), clicking a dot glides;
 *  - the live `.active` class on the visible slide + the matching dot, so
 *    themes can dress the current slide;
 *  - history hash follows the visible slide (bookmarks like fullPage's
 *    anchors, without browser-scroll fights);
 *  - deck lands at the top on load unless a hash names a slide.
 *
 * No dependencies. SPDX-License-Identifier: MPL-2.0
 * Copyright (c) 2026 Homahuki GmbH
 */
(() => {
  const stage = document.querySelector('.slide-stage');
  if (!stage || !stage.querySelector(':scope > .sm-slide')) return;
  const slides = Array.from(stage.querySelectorAll(':scope > .sm-slide'));

  if ('scrollRestoration' in history) history.scrollRestoration = 'manual';
  window.addEventListener('load', () => {
    if (!location.hash) window.scrollTo({ top: 0 });
  });

  // ——— dots: built from the deck itself ———
  const dots = [];
  const nav = document.createElement('nav');
  nav.className = 'sm-dots';
  nav.setAttribute('aria-label', 'Deck slides');
  slides.forEach((slide, i) => {
    const dot = document.createElement('button');
    dot.type = 'button';
    dot.className = 'sm-dot';
    const title = slide.getAttribute('aria-label') || slide.getAttribute('data-slide-title');
    dot.title = title || `Slide ${i + 1}`;
    if (title) dot.setAttribute('aria-label', `Go to ${title}`);
    dot.dataset.index = String(i);
    dot.addEventListener('click', () => {
      window.scrollTo({ top: slide.getBoundingClientRect().top + window.scrollY, behavior: 'smooth' });
    });
    nav.append(dot);
    dots.push(dot);
  });
  document.body.append(nav);

  // ——— active slide + dot + hash, tracked with rAF throttle on native scroll ———
  let ticking = false;
  let seenHash = null;
  const setActive = () => {
    ticking = false;
    const probe = window.scrollY + window.innerHeight * 0.5; // the viewport's midline
    let current = 0;
    slides.forEach((slide, i) => {
      if (slide.getBoundingClientRect().top + window.scrollY <= probe) current = i;
      slide.classList.toggle('active', i === current);
    });
    dots.forEach((dot, i) => dot.classList.toggle('active', i === current));
    const id = slides[current]?.id ?? '';
    if (id !== seenHash) {
      seenHash = id;
      history.replaceState(null, '', id ? `#${id}` : location.pathname);
    }
  };
  window.addEventListener('scroll', () => {
    if (!ticking) {
      ticking = true;
      requestAnimationFrame(setActive);
    }
  }, { passive: true });
  setActive();
})();

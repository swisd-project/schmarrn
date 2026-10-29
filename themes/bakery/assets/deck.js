/*
 * deck.js — the slide deck's companion: dots, active state, keyboard, guard.
 *
 * The deck's WHEEL scroll is native (scroll-snap on the root, momentum, hash).
 * What the script adds:
 *  - dot navigation on the right edge, one dot per slide, tooltips from the
 *    slide titles (`aria-label`), clicking a dot glides;
 *  - the live `.active` class on the visible slide + the matching dot;
 *  - keyboard: ONE key hit = ONE slide, always. Native key scrolling on a
 *    mandatory snap root re-targets mid-animation on every keydown (each hit
 *    advances a shrinking fraction and never lands) — so the keys are driven
 *    here, deterministically, while wheel/touch stay the browser's own;
 *  - history hash follows the visible slide (fullPage-style anchors);
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

  const topOf = (slide) => slide.getBoundingClientRect().top + window.scrollY;
  const nearest = () => {
    const probe = window.scrollY + window.innerHeight * 0.5;
    let current = 0;
    slides.forEach((slide, i) => {
      if (topOf(slide) <= probe) current = i;
    });
    return current;
  };

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
    dot.addEventListener('click', () => {
      window.scrollTo({ top: topOf(slide), behavior: 'smooth' });
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
    const current = nearest();
    slides.forEach((slide, i) => slide.classList.toggle('active', i === current));
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

  // ——— keyboard: one hit, one slide, even mid-animation ———
  // While a key-driven glide is in flight, the target index is FROZEN: a fast
  // second hit continues from the intended slide, not from wherever the
  // interrupted animation happens to hover.
  let keyTarget = null;
  let keyUntil = 0;
  const glideKey = (index) => {
    keyTarget = index;
    keyUntil = performance.now() + 600; // smooth scroll usually lands well inside
    window.scrollTo({ top: topOf(slides[index]), behavior: 'smooth' });
  };
  document.addEventListener('keydown', (e) => {
    const t = e.target;
    if (t && (t.isContentEditable || /^(INPUT|TEXTAREA|SELECT|SUMMARY)$/.test(t.tagName))) return;
    if (e.altKey || e.ctrlKey || e.metaKey) return;
    const step = { ArrowDown: 1, PageDown: 1, ' ': 1, ArrowUp: -1, PageUp: -1 }[e.key];
    if (step !== undefined) {
      e.preventDefault();
      const from = performance.now() < keyUntil && keyTarget !== null ? keyTarget : nearest();
      glideKey(Math.min(Math.max(from + step, 0), slides.length - 1));
    } else if (e.key === 'Home') {
      e.preventDefault();
      glideKey(0);
    } else if (e.key === 'End') {
      e.preventDefault();
      glideKey(slides.length - 1);
    }
  });
  // After any scroll settles (wheel, touch, dot click), the keyboard anchor
  // re-syncs with reality.
  window.addEventListener('scroll', () => {
    if (performance.now() >= keyUntil) keyTarget = null;
  }, { passive: true });
})();

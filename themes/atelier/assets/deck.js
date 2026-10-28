/*
 * deck.js — the slide deck's helper script: full-page scroll, done properly.
 *
 * CSS lays the deck out (vertical scroll-snap, hidden scrollbar). The script
 * gives it the behavior full-page decks are READ for:
 *  - measured slide heights (--sm-slide-h; measured after fonts settle, so no
 *    collapsed first paint and no mega-top);
 *  - one slide per wheel/trackpad gesture (full-page feel), while a slide
 *    taller than the stage keeps normal inner scrolling until its edge;
 *  - keyboard: ↑/↓, PageUp/PageDown, Home/End;
 *  - anchor links (#title) glide instead of jumping;
 *  - arriving with a URL hash lands softly on that slide.
 *
 * No dependencies. SPDX-License-Identifier: MPL-2.0
 * Copyright (c) 2026 Homahuki GmbH
 */
(() => {
  const stage = document.querySelector('.slide-stage');
  if (!stage || !stage.querySelector(':scope > .sm-slide')) return;
  const slides = Array.from(stage.querySelectorAll(':scope > .sm-slide'));

  // ——— heights: measure the stage, not vibes ———
  const fit = () => stage.style.setProperty('--sm-slide-h', `${stage.clientHeight}px`);
  const settle = () => requestAnimationFrame(fit);
  fit();
  window.addEventListener('resize', settle, { passive: true });
  if (document.fonts?.ready) document.fonts.ready.then(fit);

  const index = () =>
    Math.min(Math.max(Math.round(stage.scrollTop / stage.clientHeight), 0), slides.length - 1);

  const glide = (slide) => slide.scrollIntoView({ behavior: 'smooth', block: 'start' });

  const byId = (id) => {
    const el = id !== '' ? stage.querySelector(`.sm-slide[id="${CSS.escape(id)}"]`) : null;
    return el;
  };

  // ——— one slide per gesture; inner scroll wins inside an overflowing slide ———
  let lock = 0;
  stage.addEventListener(
    'wheel',
    (e) => {
      if (Math.abs(e.deltaX) > Math.abs(e.deltaY)) return; // horizontal trackpad flick
      const current = slides[index()];
      const inside = current.scrollHeight - current.clientHeight;
      if (inside > 1) {
        const atTop = current.scrollTop <= 0 && e.deltaY < 0;
        const atBottom = current.scrollTop + current.clientHeight >= inside - 1 && e.deltaY > 0;
        if (!atTop && !atBottom) return; // the slide owns this scroll
      }
      e.preventDefault();
      const now = Date.now();
      if (now < lock) return;
      lock = now + 500;
      const dir = e.deltaY > 0 ? 1 : -1;
      const next = slides[Math.min(Math.max(index() + dir, 0), slides.length - 1)];
      if (next !== current) glide(next);
    },
    { passive: false }
  );

  // ——— keyboard, but never while someone types ———
  document.addEventListener('keydown', (e) => {
    const t = e.target;
    if (t && (t.isContentEditable || /^(INPUT|TEXTAREA|SELECT|SUMMARY)$/.test(t.tagName))) return;
    const map = { ArrowDown: 1, PageDown: 1, ArrowUp: -1, PageUp: -1 };
    if (e.key in map) {
      e.preventDefault();
      const next = slides[Math.min(Math.max(index() + map[e.key], 0), slides.length - 1)];
      glide(next);
    } else if (e.key === 'Home') {
      e.preventDefault();
      glide(slides[0]);
    } else if (e.key === 'End') {
      e.preventDefault();
      glide(slides[slides.length - 1]);
    }
  });

  // ——— anchors glide; the URL stays honest, no second jump ———
  document.addEventListener('pointerdown', (e) => {
    const link = e.target.closest('a[href^="#"]');
    if (!link) return;
    const slide = byId(decodeURIComponent(link.hash.slice(1)));
    if (!slide) return;
    e.preventDefault();
    requestAnimationFrame(() => {
      glide(slide);
      history.replaceState(null, '', `#${slide.id}`);
    });
  });

  // ——— soft landing when the URL already carries a hash ———
  window.addEventListener('load', () => {
    const slide = byId(location.hash.slice(1));
    if (slide) requestAnimationFrame(() => glide(slide));
  });
})();

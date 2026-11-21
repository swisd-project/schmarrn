/* Catflash — the floating head's scroll law.
   The header's background fades in across the landing page's second half:
   opacity 0 at the top, 1 exactly when the following section reaches the
   top of the viewport. Raises --head-bg (0…1) on the header and --head-real
   (the header's measured height) on :root. */
(() => {
  'use strict';

  const boot = () => {
    const head = document.querySelector('.site-head');
    const landing = document.querySelector('main > [data-slug]');
    if (head === null || landing === null) return;

    const paint = () => {
      const rect = landing.getBoundingClientRect();
      const top = rect.top + window.scrollY;
      /* The following section's beginning = the first slide's top edge.
         The root grid CONTAINS the slides, so the root's own height
         overshoots — the slide is the anchor. No slides: the whole root
         is the landing (single-section page). */
      const first = landing.querySelector('.sm-slide');
      const slideTop = first !== null
        ? first.getBoundingClientRect().top + window.scrollY
        : top + rect.height;
      /* Fade end: the first slide's top edge — but a page shorter than the
         viewport can never scroll that far (the browser clamps at
         scrollHeight − innerHeight). The fade completes at whichever
         comes first; on long pages that is the spec, on stub pages the
         scroll end. */
      const maxScroll = document.documentElement.scrollHeight - window.innerHeight;
      const end = Math.min(slideTop, maxScroll);
      const h = slideTop - top;
      const start = top + h / 2;                /* ~50% of the landing */
      const span = end - start;
      const p = span <= 0
        ? (window.scrollY >= start ? 1 : 0)
        : Math.min(1, Math.max(0, (window.scrollY - start) / span));
      head.style.setProperty('--head-bg', p.toFixed(3));
    };

    window.addEventListener('scroll', paint, { passive: true });
    window.addEventListener('resize', paint);
    paint();
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once: true });
  } else {
    boot();
  }
})();

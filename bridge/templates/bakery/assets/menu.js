/**
 * menu.js — the sidebar's SEARCH, client-side over the RENDERED nav.
 *
 * THE SEARCH (2026-11-16, user order): theme furniture, NO plugin, NO core
 * route. The box filters the page entries the core ALREADY rendered
 * ({{navHtml}} → .sm-nav) — zero fetch, works for a stranger, the nav is
 * the data. A query hides the entries that don't match; an empty query
 * shows them all. Nothing is fetched from /bridge/pages — the rendered
 * nav is enough, and it costs the server nothing.
 *
 * THE FILTER is a BARE SCAFFOLD (the .sm-filter select): its tag data door
 * is not built yet — no core tags endpoint, no plugin (flagged in BACKLOG
 * as a follow-up). The control stands honest and does nothing until the
 * data arrives; it is never a fake that pretends to filter.
 */
(function () {
  'use strict';

  var box = document.querySelector('.sm-search');
  var nav = document.querySelector('.sm-nav');
  if (!box || !nav) return;

  var entries = Array.prototype.slice.call(nav.querySelectorAll('a'));
  var label = function (a) { return (a.textContent || '').trim().toLowerCase(); };

  box.addEventListener('input', function () {
    var q = box.value.trim().toLowerCase();
    for (var i = 0; i < entries.length; i++) {
      var a = entries[i];
      a.hidden = q !== '' && label(a).indexOf(q) === -1;
    }
  });
})();

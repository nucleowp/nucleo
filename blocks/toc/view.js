// Indice nc/toc: evidenzia la voce della sezione corrente mentre si scorre la pagina.
// setup(doc, win) collega ogni .nc-toc della pagina; init li collega tutti.
(function () {
  'use strict';

  // Sezione corrente: l'ultima il cui top è sopra questa frazione dell'altezza della finestra.
  const LINE = 0.3;

  // Indice della voce corrente dai top dei target. Senza target -1; in fondo alla pagina l'ultima.
  function pickCurrent(tops, viewportHeight, atBottom) {
    if (tops.length === 0) return -1;
    if (atBottom) return tops.length - 1;
    const line = viewportHeight * LINE;
    let current = 0;
    tops.forEach((top, i) => {
      if (top <= line) current = i;
    });
    return current;
  }

  // Elemento da marcare: il primo antenato del link (o il link stesso) con una classe nc-, senza uscire dal toc.
  function itemOf(link, toc) {
    for (let el = link; el && el !== toc; el = el.parentElement) {
      if (Array.from(el.classList).some((c) => c.startsWith('nc-'))) return el;
    }
    return link;
  }

  function setup(doc, win) {
    const lists = [];
    for (const toc of doc.querySelectorAll('.nc-toc')) {
      const entries = [];
      for (const link of toc.querySelectorAll('a[href^="#"]')) {
        const target = doc.getElementById(link.getAttribute('href').slice(1));
        if (target) entries.push({ link, item: itemOf(link, toc), target });
      }
      if (entries.length > 0) lists.push(entries);
    }
    if (lists.length === 0) return;

    const update = () => {
      const atBottom = win.innerHeight + win.scrollY >= doc.documentElement.scrollHeight - 2;
      for (const entries of lists) {
        const tops = entries.map((e) => e.target.getBoundingClientRect().top);
        const current = pickCurrent(tops, win.innerHeight, atBottom);
        entries.forEach((e, i) => {
          const on = i === current;
          e.item.classList.toggle('is-current', on);
          if (on) e.link.setAttribute('aria-current', 'true');
          else e.link.removeAttribute('aria-current');
        });
      }
    };

    // Un solo aggiornamento per frame, anche con scroll continuo.
    let frame = 0;
    const schedule = () => {
      if (frame) return;
      frame = win.requestAnimationFrame(() => {
        frame = 0;
        update();
      });
    };

    win.addEventListener('scroll', schedule, { passive: true });
    win.addEventListener('resize', schedule);
    update();
  }

  const api = { pickCurrent, setup };

  function init() {
    setup(document, window);
  }

  if (typeof module !== 'undefined') module.exports = api;
  if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
  }
})();

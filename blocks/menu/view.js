// Menu nc/menu: pannello off-canvas e sottomenu a tendina.
// Senza JavaScript il menu resta un elenco di link. setup(nav, doc, win) collega un menu; init li collega tutti.
(function () {
  'use strict';

  // Larghezza sotto la quale l'off-canvas è attivo, per classe nc-menu--bp-*.
  const BREAKPOINTS = { none: 768, lg: 1024, xl: 1920 };

  // Indice successivo con ciclo, avanti o indietro. Con count 0 restituisce -1.
  function nextFocus(count, index, backwards) {
    if (count <= 0) return -1;
    if (backwards) return index <= 0 ? count - 1 : index - 1;
    return (index + 1) % count;
  }

  // Vero se il menu è off-canvas e la larghezza è sotto la soglia della sua classe bp.
  function isOffcanvasActive(nav, width) {
    if (!nav.classList.contains('nc-menu--offcanvas')) return false;
    let bp = 'none';
    if (nav.classList.contains('nc-menu--bp-xl')) bp = 'xl';
    else if (nav.classList.contains('nc-menu--bp-lg')) bp = 'lg';
    return width < BREAKPOINTS[bp];
  }

  const isOpen = (el) => el.classList.contains('is-open');

  function setupOffcanvas(nav, doc, win) {
    const toggle = nav.querySelector('.nc-menu__toggle');
    const list = nav.querySelector('.nc-menu__list');
    if (!toggle || !list) return;

    // Il pannello si abilita subito; l'animazione parte dal frame successivo, così non scorre al caricamento.
    nav.classList.add('is-ready');
    win.requestAnimationFrame(() => nav.classList.add('is-animated'));

    // Elementi del focus trap: il toggle, poi link e pulsanti visibili del pannello.
    const focusables = () => [toggle, ...list.querySelectorAll('a[href], button')]
      .filter((el) => el.getClientRects().length > 0);

    const setOpen = (open) => {
      nav.classList.toggle('is-open', open);
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      const locked = doc.querySelector('.nc-menu--offcanvas.is-open') !== null;
      doc.documentElement.classList.toggle('nc-menu-locked', locked);
    };

    toggle.addEventListener('click', () => {
      const open = !isOpen(nav);
      setOpen(open);
      if (open) {
        const first = list.querySelector('a[href]');
        if (first) first.focus();
      }
    });

    // Un link (anche ad ancora) dentro il pannello aperto lo chiude.
    list.addEventListener('click', (event) => {
      if (isOpen(nav) && event.target.closest('a[href]')) setOpen(false);
    });

    doc.addEventListener('keydown', (event) => {
      if (!isOpen(nav)) return;
      if (event.key === 'Escape') {
        setOpen(false);
        toggle.focus();
        return;
      }
      if (event.key === 'Tab') {
        const items = focusables();
        const next = nextFocus(items.length, items.indexOf(doc.activeElement), event.shiftKey);
        if (next < 0) return;
        event.preventDefault();
        items[next].focus();
      }
    });

    doc.addEventListener('click', (event) => {
      if (isOpen(nav) && !nav.contains(event.target)) setOpen(false);
    });

    win.addEventListener('resize', () => {
      if (isOpen(nav) && !isOffcanvasActive(nav, win.innerWidth)) setOpen(false);
    });
  }

  // Sottomenu a tendina: aperta da hover, focus o clic (is-open). is-closed la tiene chiusa finché non si esce.
  function setupDropdown(toggle, nav, doc) {
    const item = toggle.closest('.nc-menu__item');
    if (!item) return;

    let hover = false;
    let within = false;

    const sync = () => {
      const expanded = isOpen(item) || (!item.classList.contains('is-closed') && (hover || within));
      toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    };

    const shut = () => {
      item.classList.remove('is-open');
      item.classList.add('is-closed');
      sync();
    };

    toggle.addEventListener('click', () => {
      if (isOpen(item)) {
        shut();
      } else {
        item.classList.add('is-open');
        item.classList.remove('is-closed');
        sync();
      }
    });

    item.addEventListener('mouseenter', () => {
      hover = true;
      sync();
    });

    item.addEventListener('mouseleave', () => {
      hover = false;
      item.classList.remove('is-closed');
      sync();
    });

    item.addEventListener('focusin', () => {
      within = true;
      sync();
    });

    item.addEventListener('focusout', (event) => {
      if (item.contains(event.relatedTarget)) return;
      within = false;
      item.classList.remove('is-open', 'is-closed');
      sync();
    });

    // Esc su una tendina aperta la chiude e riporta il focus al suo pulsante.
    // Con il pannello off-canvas aperto le tendine sono annidate e Esc chiude il pannello.
    item.addEventListener('keydown', (event) => {
      if (event.key !== 'Escape' || nav.classList.contains('is-open')) return;
      if (toggle.getAttribute('aria-expanded') !== 'true') return;
      if (!item.contains(doc.activeElement)) return;
      event.stopPropagation();
      shut();
      toggle.focus();
    });

    // Clic fuori dal menu: chiude le tendine aperte dal clic e marca chiuse quelle aperte dal focus.
    doc.addEventListener('click', (event) => {
      if (nav.contains(event.target)) return;
      item.classList.remove('is-open');
      if (within) item.classList.add('is-closed');
      sync();
    });
  }

  function setup(nav, doc, win) {
    if (nav.classList.contains('nc-menu--offcanvas')) setupOffcanvas(nav, doc, win);
    for (const toggle of nav.querySelectorAll('.nc-menu__sub-toggle')) setupDropdown(toggle, nav, doc);
  }

  const api = { nextFocus, isOffcanvasActive, setup };

  function init() {
    for (const nav of document.querySelectorAll('.nc-menu')) setup(nav, document, window);
  }

  if (typeof module !== 'undefined') module.exports = api;
  if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
  }
})();

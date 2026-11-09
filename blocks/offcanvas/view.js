// Off-canvas nc/offcanvas: il pulsante apre un pannello di dialogo laterale. Senza JavaScript il pannello resta nascosto.
// setup(root, doc) collega un blocco; init li collega tutti.
(function () {
  'use strict';

  // Indice successivo con ciclo, avanti o indietro. Con count 0 restituisce -1.
  function nextFocus(count, index, backwards) {
    if (count <= 0) return -1;
    if (backwards) return index <= 0 ? count - 1 : index - 1;
    return (index + 1) % count;
  }

  function setup(root, doc) {
    const trigger = root.querySelector('.nc-offcanvas__trigger');
    const panel = root.querySelector('.nc-offcanvas__panel');
    const backdrop = root.querySelector('.nc-offcanvas__backdrop');
    if (!trigger || !panel) return;

    const isOpen = () => root.classList.contains('is-open');

    // Elementi del focus trap: link, pulsanti e campi visibili del pannello.
    const focusables = () => [...panel.querySelectorAll('a[href], button, input, select, textarea')]
      .filter((el) => el.getClientRects().length > 0);

    const setOpen = (open) => {
      root.classList.toggle('is-open', open);
      trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
      panel.hidden = !open;
      if (backdrop) backdrop.hidden = !open;
      const locked = doc.querySelector('.nc-offcanvas.is-open') !== null;
      doc.documentElement.classList.toggle('nc-offcanvas-locked', locked);
    };

    // Apertura: il focus va al primo elemento del pannello, o al pannello se non ne ha.
    const open = () => {
      setOpen(true);
      (focusables()[0] || panel).focus();
    };

    // Chiusura: il focus torna al pulsante che ha aperto il pannello.
    const close = () => {
      setOpen(false);
      trigger.focus();
    };

    trigger.addEventListener('click', () => (isOpen() ? close() : open()));

    // Clic sul fondo oscurato: chiude il pannello.
    if (backdrop) backdrop.addEventListener('click', close);

    doc.addEventListener('keydown', (event) => {
      if (!isOpen()) return;
      if (event.key === 'Escape') {
        close();
        return;
      }
      if (event.key === 'Tab') {
        const items = focusables();
        const next = nextFocus(items.length, items.indexOf(doc.activeElement), event.shiftKey);
        event.preventDefault();
        if (next < 0) panel.focus();
        else items[next].focus();
      }
    });
  }

  const api = { nextFocus, setup };

  function init() {
    for (const root of document.querySelectorAll('.nc-offcanvas')) setup(root, document);
  }

  if (typeof module !== 'undefined') module.exports = api;
  if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
  }
})();

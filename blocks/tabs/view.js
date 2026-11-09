// Schede: attiva la navigazione con click e frecce sinistra/destra. Senza questo script restano visibili tutti i pannelli.
for (const root of document.querySelectorAll('.nc-tabs')) {
  const tabs = [...root.querySelectorAll('[role="tab"]')];
  const panels = [...root.querySelectorAll('[role="tabpanel"]')];
  if (tabs.length === 0 || tabs.length !== panels.length) {
    continue;
  }

  root.classList.add('is-ready');

  const select = (index, moveFocus) => {
    tabs.forEach((tab, i) => {
      const active = i === index;
      tab.setAttribute('aria-selected', active ? 'true' : 'false');
      tab.tabIndex = active ? 0 : -1;
      panels[i].classList.toggle('is-active', active);
    });
    if (moveFocus) {
      tabs[index].focus();
    }
  };

  tabs.forEach((tab, i) => {
    tab.addEventListener('click', () => select(i, false));
    tab.addEventListener('keydown', (event) => {
      if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') {
        return;
      }
      event.preventDefault();
      const step = event.key === 'ArrowRight' ? 1 : -1;
      select((i + step + tabs.length) % tabs.length, true);
    });
  });

  const initial = Math.max(0, tabs.findIndex((tab) => tab.getAttribute('aria-selected') === 'true'));
  select(initial, false);
}

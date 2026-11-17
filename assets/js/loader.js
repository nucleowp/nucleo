(function () {
  'use strict';

  // CSS completi di Nucleo: link con media="print", che non blocca il rendering.
  var styles = Array.isArray(window.nucleoCss) ? window.nucleoCss : [];
  // I fogli vanno subito dopo il critical CSS (stile inline di nucleo-tokens), nell'ordine dato:
  // così gli stili inline stampati dopo (es. correzioni di WooCommerce) restano più forti, come nella pagina originale.
  var anchor = document.getElementById('nucleo-tokens-inline-css');
  var spots = { '': { parent: anchor ? anchor.parentNode : document.head, before: anchor ? anchor.nextSibling : null } };
  // Una voce con "after" segue il CSS di un documento, rimasto nella pagina: va dopo quell'elemento.
  function spot(after) {
    if (after && !spots[after]) {
      var ref = document.getElementById(after);
      spots[after] = ref ? { parent: ref.parentNode, before: ref.nextSibling } : spots[''];
    }
    return spots[after || ''];
  }
  var links = [];
  var left = styles.length;
  // I fogli passano al loro media tutti insieme, quando l'ultimo è arrivato (o è fallito). Uno alla volta, un foglio
  // già arrivato vince sulle regole del critical che appartengono a un foglio ancora in viaggio: il layout si sposta due volte.
  function settle() {
    this.onload = this.onerror = null;
    left--;
    if (left > 0) return;
    links.forEach(function (item) {
      item.link.media = item.media;
      item.link.removeAttribute('data-nc-css');
    });
  }
  // Voce: href, oppure {href, media, after} per i fogli con media proprio (es. solo schermi piccoli) o con un punto di inserimento.
  styles.forEach(function (entry) {
    var at = spot(typeof entry === 'string' ? '' : entry.after);
    var link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = typeof entry === 'string' ? entry : entry.href;
    link.media = 'print';
    link.setAttribute('data-nc-css', '');
    link.onload = link.onerror = settle;
    links.push({ link: link, media: typeof entry === 'string' ? 'all' : entry.media || 'all' });
    at.parent.insertBefore(link, at.before);
  });

  var list = window.nucleoLazy || [];
  var loaded = {};

  // Carica una volta sola ogni src; done (opzionale) parte al load.
  function load(entry, done) {
    if (loaded[entry.src]) {
      if (done) done();
      return;
    }
    loaded[entry.src] = true;
    var s = document.createElement('script');
    s.src = entry.src;
    s.defer = true;
    if (done) s.onload = done;
    document.body.appendChild(s);
  }

  // Tutte le istanze .nc-<parte> della pagina, con la parte dopo "/" del type (nc/video -> .nc-video).
  // La voce di nucleoLazy vale per tipo: il bundle si carica una volta sola, al primo segnale di una qualsiasi istanza.
  function targets(entry) {
    return Array.prototype.slice.call(document.querySelectorAll('.nc-' + entry.type.split('/').pop()));
  }

  function visible(entry) {
    var els = targets(entry);
    if (!els.length) return;
    if (!('IntersectionObserver' in window)) return load(entry);
    var io = new IntersectionObserver(function (entries) {
      if (entries.some(function (e) { return e.isIntersecting; })) {
        io.disconnect();
        load(entry);
      }
    }, { rootMargin: '200px' });
    els.forEach(function (el) { io.observe(el); });
  }

  function interaction(entry) {
    var els = targets(entry);
    if (!els.length) return;
    var events = ['pointerdown', 'focusin', 'keydown'];
    // Ripete il clic sull'elemento toccato (il pulsante), non sul contenitore, solo se il clic vero è arrivato
    // prima dello script ed è andato perso. Se lo script arriva prima del rilascio, il clic vero lo raggiunge:
    // ripeterlo aprirebbe e richiuderebbe subito il pannello (mini-cart, menu).
    function go(ev) {
      els.forEach(function (el) {
        events.forEach(function (t) { el.removeEventListener(t, go); });
      });
      var owner = ev && ev.currentTarget ? ev.currentTarget : null;
      var hit = ev && ev.target instanceof Element ? ev.target.closest('button, a, [role="button"]') : null;
      var replay = ev && ev.type === 'pointerdown' && hit && owner && owner.contains(hit) ? hit : null;
      var ready = false;
      var lost = false;
      if (replay) {
        var onClick = function () {
          replay.removeEventListener('click', onClick, true);
          if (!ready) lost = true;
        };
        replay.addEventListener('click', onClick, true);
      }
      load(entry, function () {
        ready = true;
        if (replay && lost) replay.dispatchEvent(new MouseEvent('click', { bubbles: true }));
      });
    }
    els.forEach(function (el) {
      events.forEach(function (t) { el.addEventListener(t, go); });
    });
  }

  function idle(entry) {
    var run = function () { load(entry); };
    if ('requestIdleCallback' in window) requestIdleCallback(run);
    else setTimeout(run, 200);
  }

  var modes = { visible: visible, interaction: interaction, idle: idle };
  list.forEach(function (entry) {
    if (modes[entry.strategy]) modes[entry.strategy](entry);
  });

  // Script di Nucleo con data-nc-load="interaction" o "idle": tag type="text/plain" con data-nc-src.
  // Si attivano tutti insieme, nell'ordine del documento (async=false mantiene l'ordine di esecuzione).
  var activated = {};
  function activate(strategy) {
    if (activated[strategy]) return;
    activated[strategy] = true;
    var nodes = Array.prototype.slice.call(document.querySelectorAll('script[data-nc-load="' + strategy + '"]'));
    nodes.forEach(function (node) {
      var s = document.createElement('script');
      s.src = node.getAttribute('data-nc-src');
      if (node.id) s.id = node.id;
      s.async = false;
      document.body.appendChild(s);
    });
  }

  // Primo gesto dell'utente: attiva gli script "interaction" e rimuove gli ascoltatori.
  var gestures = ['pointerdown', 'keydown', 'scroll', 'touchstart'];
  function onGesture() {
    gestures.forEach(function (e) { window.removeEventListener(e, onGesture); });
    activate('interaction');
  }
  if (document.querySelector('script[data-nc-load="interaction"]')) {
    gestures.forEach(function (e) { window.addEventListener(e, onGesture, { passive: true }); });
  }

  // Idle: requestIdleCallback con timeout di 2 s; senza supporto, setTimeout di 2 s.
  if (document.querySelector('script[data-nc-load="idle"]')) {
    var runIdle = function () { activate('idle'); };
    if ('requestIdleCallback' in window) requestIdleCallback(runIdle, { timeout: 2000 });
    else setTimeout(runIdle, 2000);
  }
})();

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

  // Script di Nucleo con data-nc-load="interaction" o "idle": tag type="text/plain" con data-nc-src (o testo inline).
  // Una catena li attiva uno alla volta, nell'ordine del documento: prima quelli senza defer e senza data-nc-module,
  // poi gli altri. Un esterno passa al successivo a load o error; un inline parte subito.
  var IDLE = 'script[data-nc-load="idle"]';
  var DELAYED = 'script[data-nc-load="interaction"], script[data-nc-load="idle"]';
  var running = false;
  var queued = false;
  var queuedGesture = false;
  var gestureDone = false;

  // jQuery: mentre la catena gira, fn.ready raccoglie i callback; alla fine si ripristina e ognuno passa a jQuery().
  var jq = null;
  var jqReady = null;
  var jqHeld = [];
  function holdJquery() {
    if (!window.jQuery || jq) return;
    jq = window.jQuery;
    jqReady = jq.fn.ready;
    jq.fn.ready = function (fn) {
      if (typeof fn !== 'function') return jqReady.apply(this, arguments);
      jqHeld.push(fn);
      return this;
    };
  }
  function releaseJquery() {
    if (!jq) return;
    jq.fn.ready = jqReady;
    var held = jqHeld;
    jqHeld = [];
    held.forEach(function (fn) { jq(fn); });
    jq = null;
  }

  // DOMContentLoaded (su document e su window) e load (solo su window), registrati durante la catena, si raccolgono:
  // alla fine i metodi tornano nativi e ogni listener riceve un evento del suo tipo, una volta sola.
  var nativeDocAdd = document.addEventListener;
  var nativeWinAdd = window.addEventListener;
  var collected = null;
  function collect(target, nativeAdd) {
    return function (type, fn) {
      if (typeof fn === 'function' && (type === 'DOMContentLoaded' || (type === 'load' && target === window))) {
        collected.push({ target: target, type: type, fn: fn });
        return;
      }
      return nativeAdd.apply(target, arguments);
    };
  }
  function holdEvents() {
    collected = [];
    document.addEventListener = collect(document, nativeDocAdd);
    window.addEventListener = collect(window, nativeWinAdd);
  }
  function releaseEvents() {
    document.addEventListener = nativeDocAdd;
    window.addEventListener = nativeWinAdd;
    var calls = collected;
    collected = null;
    calls.forEach(function (c) {
      try { c.fn.call(c.target, new Event(c.type)); } catch (e) { if (window.console) console.error(e); }
    });
  }

  // Clic di ripetizione: selettori data-nc-replay dei tag in attesa. Un clic vero su un controllo che funziona solo
  // con il JS, dentro un selettore e prima della fine della catena di gesto, viene ripetuto a catena finita (vince l'ultimo).
  var replay = [];
  Array.prototype.forEach.call(document.querySelectorAll(DELAYED), function (node) {
    var sel = node.getAttribute('data-nc-replay');
    if (sel) replay.push(sel);
  });
  var clicked = null;
  function jsOnly(el) {
    if (el.tagName === 'BUTTON') return el.type === 'button' || !el.form;
    if (el.tagName === 'A') {
      var href = el.getAttribute('href');
      return href === null || href.charAt(0) === '#';
    }
    return true;
  }
  function remember(ev) {
    if (!ev.isTrusted || !ev.target || !ev.target.closest) return;
    var el = ev.target.closest('button, a, [role="button"]');
    var inside = replay.some(function (sel) { return ev.target.closest(sel); });
    if (el && inside && jsOnly(el)) clicked = el;
  }
  if (replay.length) document.addEventListener('click', remember, true);

  // Tag in attesa del selettore, senza defer e senza data-nc-module prima degli altri, nell'ordine del documento.
  function pending(sel) {
    var all = Array.prototype.slice.call(document.querySelectorAll(sel));
    var late = function (n) { return n.hasAttribute('defer') || n.hasAttribute('data-nc-module'); };
    return all.filter(function (n) { return !late(n); }).concat(all.filter(late));
  }

  // Ogni tag viene sostituito sul posto da uno script vero; quando il giro finisce si interroga di nuovo il documento.
  function chain(sel, gesture) {
    var nodes = pending(sel);
    var i = 0;
    function next() {
      holdJquery();
      if (i >= nodes.length) {
        nodes = pending(sel);
        i = 0;
        if (!nodes.length) return end(gesture);
      }
      var old = nodes[i++];
      var s = document.createElement('script');
      Array.prototype.forEach.call(old.attributes, function (a) {
        if (a.name !== 'type' && a.name.indexOf('data-nc-') !== 0) s.setAttribute(a.name, a.value);
      });
      if (old.hasAttribute('data-nc-module')) s.type = 'module';
      s.async = false;
      var src = old.getAttribute('data-nc-src');
      if (src !== null) {
        s.src = src;
        s.onload = s.onerror = next;
        old.parentNode.replaceChild(s, old);
      } else {
        s.text = old.text;
        old.parentNode.replaceChild(s, old);
        next();
      }
    }
    next();
  }

  function end(gesture) {
    releaseJquery();
    releaseEvents();
    running = false;
    if (gesture) {
      gestureDone = true;
      if (replay.length) document.removeEventListener('click', remember, true);
      if (clicked) {
        var el = clicked;
        setTimeout(function () { el.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true })); }, 300);
      }
    }
    if (queued) {
      var again = queuedGesture;
      queued = false;
      queuedGesture = false;
      start(again);
    }
  }

  // Una richiesta arrivata durante una catena aspetta la sua fine. Il gesto attiva anche gli "idle" ancora in attesa.
  function start(gesture) {
    if (running) {
      queued = true;
      queuedGesture = queuedGesture || gesture;
      return;
    }
    if (!gesture && gestureDone) return;
    running = true;
    holdEvents();
    chain(gesture ? DELAYED : IDLE, gesture);
  }

  // Primo gesto dell'utente: avvia la catena e rimuove gli ascoltatori.
  var gestures = ['pointerdown', 'keydown', 'scroll', 'touchstart'];
  function onGesture() {
    gestures.forEach(function (e) { window.removeEventListener(e, onGesture); });
    start(true);
  }
  if (document.querySelector('script[data-nc-load="interaction"]')) {
    gestures.forEach(function (e) { window.addEventListener(e, onGesture, { passive: true }); });
  }

  // Idle: requestIdleCallback con timeout di 2 s; senza supporto, setTimeout di 2 s.
  if (document.querySelector(IDLE)) {
    var runIdle = function () { start(false); };
    if ('requestIdleCallback' in window) requestIdleCallback(runIdle, { timeout: 2000 });
    else setTimeout(runIdle, 2000);
  }
})();

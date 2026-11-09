// Facade dei video: al click la miniatura viene sostituita dall'iframe del provider.
const EMBED_BASE = new Map([
  ['youtube', 'https://www.youtube-nocookie.com/embed/'],
  ['vimeo', 'https://player.vimeo.com/video/'],
]);

const ID_PATTERN = new Map([
  ['youtube', /^[A-Za-z0-9_-]{6,20}$/],
  ['vimeo', /^[0-9]+$/],
]);

function embedUrl(provider, id) {
  const base = EMBED_BASE.get(provider);
  const pattern = ID_PATTERN.get(provider);
  if (!base || !pattern || !pattern.test(id)) {
    return null;
  }
  return base + id + '?autoplay=1';
}

// Consenso per categoria (marketing, statistics, preferences). WP Consent API prima, poi le API dei plugin.
// Senza nessuna API leggibile il video resta bloccato: meglio un video fermo che una richiesta senza consenso.
const COOKIEYES = { marketing: 'advertisement', statistics: 'analytics', preferences: 'functional' };

function hasConsent(category) {
  const w = window;
  if (typeof w.wp_has_consent === 'function') return Boolean(w.wp_has_consent(category));
  if (typeof w.cmplz_has_consent === 'function') return Boolean(w.cmplz_has_consent(category));
  if (w.Cookiebot && w.Cookiebot.consent) return Boolean(w.Cookiebot.consent[category]);
  if (typeof w.getCkyConsent === 'function') {
    const c = w.getCkyConsent();
    return Boolean(c && c.categories && c.categories[COOKIEYES[category]]);
  }
  if (w._iub && w._iub.cs && w._iub.cs.api && typeof w._iub.cs.api.isConsentGiven === 'function') {
    return Boolean(w._iub.cs.api.isConsentGiven());
  }
  return false;
}

const CONSENT_EVENTS = ['wp_listen_for_consent_change', 'cmplz_status_change', 'CookiebotOnAccept', 'cookieyes_consent_update'];

for (const root of document.querySelectorAll('.nc-video[data-provider]')) {
  const button = root.querySelector('.nc-video__play');
  const src = embedUrl(root.dataset.provider, root.dataset.id ?? '');
  if (!button || src === null) {
    continue;
  }
  const category = root.dataset.ncConsent ?? '';
  const allowed = () => category === '' || hasConsent(category);
  const thumb = root.querySelector('img[data-nc-src]');
  const showThumb = () => {
    if (thumb && allowed()) {
      thumb.src = thumb.dataset.ncSrc;
      thumb.removeAttribute('data-nc-src');
    }
  };
  showThumb();
  for (const name of CONSENT_EVENTS) {
    document.addEventListener(name, showThumb);
  }

  button.addEventListener('click', (event) => {
    if (!allowed()) {
      event.stopImmediatePropagation();
      root.classList.add('is-blocked');
      return;
    }
    play();
  });

  function play() {
    const iframe = document.createElement('iframe');
    iframe.src = src;
    iframe.title = button.getAttribute('aria-label') ?? '';
    iframe.allow = 'autoplay; fullscreen; picture-in-picture';
    iframe.allowFullscreen = true;
    // Attributi di consenso del plugin cookie: passano dal contenitore all'iframe.
    for (const attr of root.attributes) {
      if (/^(data-cookie|data-category$|data-iub-)/.test(attr.name)) {
        iframe.setAttribute(attr.name, attr.value);
      }
    }
    if (root.classList.contains('_iub_cs_activate')) {
      iframe.classList.add('_iub_cs_activate');
    }
    root.replaceChildren(iframe);
  }
}

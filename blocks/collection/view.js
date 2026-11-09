// Collezione dinamica nc/collection: filtri via REST, conteggi, paginazione, pannello mobile, vista.
// Senza JavaScript il modulo è un GET normale. Lo script aggiunge is-enhanced e lavora sul markup esistente.
(function () {
	'use strict';

	const SEARCH_DELAY = 300;
	const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), summary, [tabindex]:not([tabindex="-1"])';

	// Coppie [nome, valore] di FormData in URLSearchParams, senza valori vuoti.
	function paramsFromEntries(entries) {
		const out = new URLSearchParams();
		for (const [name, value] of entries) {
			if (typeof value === 'string' && value !== '') out.append(name, value);
		}
		return out;
	}

	// Base (con eventuale query) più i parametri; extra sovrascrive.
	function endpointUrl(base, params, extra) {
		const cut = base.indexOf('?');
		const path = cut < 0 ? base : base.slice(0, cut);
		const query = new URLSearchParams(cut < 0 ? '' : base.slice(cut + 1));
		for (const [name, value] of new URLSearchParams(params || '')) query.append(name, value);
		for (const name of Object.keys(extra || {})) query.set(name, extra[name]);
		const qs = query.toString();
		return qs ? path + '?' + qs : path;
	}

	// Da {filtro: {valore: n}} a lista piatta; disabled se non ci sono risultati.
	function applyCounts(counts) {
		const list = [];
		for (const filter of Object.keys(counts || {})) {
			const values = counts[filter] || {};
			for (const value of Object.keys(values)) {
				const n = values[value];
				list.push({ filter, value, n, disabled: n === 0 });
			}
		}
		return list;
	}

	// Conteggio di una singola opzione; un'opzione assente da counts vale 0.
	function countFor(counts, filter, value) {
		const values = counts && Object.prototype.hasOwnProperty.call(counts, filter) ? counts[filter] : null;
		if (!values || !Object.prototype.hasOwnProperty.call(values, value)) return 0;
		const n = values[value];
		return typeof n === 'number' ? n : 0;
	}

	// Il server manda conteggi solo per i filtri che li calcola: senza chiave il filtro non si tocca.
	function hasCountsFor(counts, filter) {
		return !!counts && Object.prototype.hasOwnProperty.call(counts, filter);
	}

	// Path e query di currentHref senza i parametri "<prefix>_", più params; l'hash resta.
	function mergeUrl(currentHref, prefix, params) {
		const current = new URL(currentHref, 'http://localhost');
		const own = prefix + '_';
		const out = new URLSearchParams();
		for (const [name, value] of current.searchParams) {
			if (!name.startsWith(own)) out.append(name, value);
		}
		for (const [name, value] of new URLSearchParams(params || '')) out.append(name, value);
		const qs = out.toString();
		return current.pathname + (qs ? '?' + qs : '') + current.hash;
	}

	function statusText(template, count) {
		return String(template).split('{count}').join(String(count));
	}

	function storageKey(doc, node) {
		return 'nc-view-' + doc + '-' + node;
	}

	const api = { paramsFromEntries, endpointUrl, applyCounts, countFor, hasCountsFor, mergeUrl, statusText, storageKey };

	function setup(root) {
		const endpoint = root.getAttribute('data-endpoint');
		const prefix = root.getAttribute('data-prefix') || 'f';
		const doc = root.getAttribute('data-doc');
		const node = root.getAttribute('data-nc-collection');
		const form = root.querySelector('.nc-collection__form');
		const results = root.querySelector('.nc-collection__results');
		const countEl = root.querySelector('.nc-collection-count');
		const statusEl = root.querySelector('.nc-collection__status');
		const openBtn = root.querySelector('.nc-collection__open');
		const panel = root.querySelector('.nc-collection__panel');
		const views = root.querySelector('.nc-collection__views');
		if (!endpoint || !form || !results) return;

		root.classList.add('is-enhanced');
		let seq = 0;
		let timer = null;
		const mobile = window.matchMedia ? window.matchMedia('(max-width: 767px)') : null;

		const hit = (target, selector) => {
			const el = target && target.closest ? target.closest(selector) : null;
			return el && root.contains(el) ? el : null;
		};
		const isDrawerOpen = () => !!panel && panel.classList.contains('is-open');
		const currentParams = () => paramsFromEntries(new FormData(form));
		const load = async (url) => {
			const res = await fetch(url, { headers: { Accept: 'application/json' } });
			const data = await res.json();
			if (!res.ok || !data || data.ok !== true || typeof data.html !== 'string') {
				throw new Error('invalid response');
			}
			return data;
		};

		// Conteggi accanto alle opzioni, testo di stato e contatore.
		function applyResponse(data) {
			if (typeof data.status === 'string') {
				if (countEl) countEl.textContent = data.status;
				if (statusEl) statusEl.textContent = data.status;
			}
			for (const fieldset of root.querySelectorAll('[data-filter]')) {
				const filter = fieldset.getAttribute('data-filter');
				if (!api.hasCountsFor(data.counts, filter)) continue;
				for (const span of fieldset.querySelectorAll('[data-count]')) {
					const n = api.countFor(data.counts, filter, span.getAttribute('data-count'));
					span.textContent = '(' + n + ')';
					const label = span.closest('label');
					const input = label ? label.querySelector('input') : null;
					if (input) input.disabled = n === 0 && !input.checked;
				}
			}
		}

		// Aggiunge gli <li> della risposta e sostituisce il pager.
		function appendItems(html) {
			const items = root.querySelector('.nc-collection__items');
			const wrap = document.createElement('div');
			wrap.innerHTML = html;
			const added = Array.from(wrap.children).filter((el) => el.tagName === 'LI');
			if (items) for (const li of added) items.appendChild(li);
			const oldPager = root.querySelector('.nc-collection__pager');
			const newPager = wrap.querySelector('.nc-collection__pager');
			if (oldPager && newPager) oldPager.replaceWith(newPager);
			else if (oldPager) oldPager.remove();
			else if (newPager) results.appendChild(newPager);
			return added;
		}

		function focusFirst(added) {
			const first = added[0];
			if (!first) return;
			const target = first.querySelector(FOCUSABLE);
			if (target) {
				target.focus();
			} else {
				first.tabIndex = -1;
				first.focus();
			}
		}

		// Svuota il modulo a mano: form.reset() riporterebbe i valori del markup iniziale, cioè dell'URL di partenza.
		function clearForm() {
			for (const input of form.querySelectorAll('input')) {
				const type = input.type;
				if (type === 'checkbox') input.checked = false;
				else if (type === 'radio') input.checked = input.value === '';
				else if (['text', 'number', 'date', 'search'].includes(type)) input.value = '';
			}
			for (const select of form.querySelectorAll('select')) select.selectedIndex = 0;
		}

		// Ricarica i risultati con lo stato del modulo. Errore: invio GET senza script.
		async function refresh() {
			const params = currentParams();
			const mine = ++seq;
			let data;
			try {
				data = await load(endpointUrl(endpoint, params));
			} catch (err) {
				form.submit();
				return;
			}
			if (mine !== seq) return;
			results.innerHTML = data.html;
			applyResponse(data);
			history.replaceState(null, '', mergeUrl(location.href, prefix, params));
		}

		// Link del pager: "Carica altri" accoda, il resto sostituisce i risultati.
		async function goToPage(link, more) {
			const url = new URL(link.getAttribute('href'), location.href);
			const page = url.searchParams.get(prefix + '_page');
			const params = currentParams();
			if (!page) {
				location.assign(url.href);
				return;
			}
			params.set(prefix + '_page', page);
			const mine = ++seq;
			let data;
			try {
				data = await load(endpointUrl(endpoint, params, more ? { append: '1' } : null));
			} catch (err) {
				location.assign(mergeUrl(location.href, prefix, params));
				return;
			}
			if (mine !== seq) return;
			if (more) {
				const added = appendItems(data.html);
				applyResponse(data);
				focusFirst(added);
				return;
			}
			results.innerHTML = data.html;
			applyResponse(data);
			history.pushState(null, '', mergeUrl(location.href, prefix, params));
			results.focus();
		}

		function setView(name) {
			root.setAttribute('data-view', name);
			for (const btn of root.querySelectorAll('.nc-collection__views [data-view]')) {
				btn.setAttribute('aria-pressed', String(btn.getAttribute('data-view') === name));
			}
			try {
				localStorage.setItem(api.storageKey(doc, node), name);
			} catch (err) {
				// Archiviazione non disponibile: la scelta vale solo per questa visita.
			}
		}

		function openDrawer() {
			if (!panel) return;
			panel.classList.add('is-open');
			panel.setAttribute('role', 'dialog');
			panel.setAttribute('aria-modal', 'true');
			if (openBtn) openBtn.setAttribute('aria-expanded', 'true');
			const first = panel.querySelector(FOCUSABLE);
			if (first) first.focus();
		}

		function closeDrawer(restoreFocus) {
			if (!panel || !isDrawerOpen()) return;
			panel.classList.remove('is-open');
			panel.removeAttribute('role');
			panel.removeAttribute('aria-modal');
			if (openBtn) {
				openBtn.setAttribute('aria-expanded', 'false');
				if (restoreFocus) openBtn.focus();
			}
		}

		function trapFocus(event) {
			const items = panel.querySelectorAll(FOCUSABLE);
			if (!items.length) return;
			const first = items[0];
			const last = items[items.length - 1];
			const active = document.activeElement;
			const outside = !panel.contains(active);
			if (event.shiftKey && (active === first || outside)) {
				event.preventDefault();
				last.focus();
			} else if (!event.shiftKey && (active === last || outside)) {
				event.preventDefault();
				first.focus();
			}
		}

		form.addEventListener('submit', (event) => {
			event.preventDefault();
			clearTimeout(timer);
			// Con il drawer aperto, l'invio chiude il drawer dopo l'aggiornamento.
			refresh().then(() => {
				if (isDrawerOpen()) closeDrawer(true);
			});
		});
		form.addEventListener('change', () => {
			clearTimeout(timer);
			refresh();
		});
		form.addEventListener('input', (event) => {
			if (event.target.type !== 'search') return;
			clearTimeout(timer);
			timer = setTimeout(refresh, SEARCH_DELAY);
		});

		root.addEventListener('click', (event) => {
			if (hit(event.target, '.nc-collection-reset')) {
				event.preventDefault();
				clearTimeout(timer);
				clearForm();
				refresh();
				return;
			}
			const pagerLink = hit(event.target, '.nc-collection__pager a');
			if (pagerLink) {
				event.preventDefault();
				goToPage(pagerLink, pagerLink.classList.contains('nc-collection__more'));
				return;
			}
			const view = hit(event.target, '.nc-collection__views [data-view]');
			if (view) {
				setView(view.getAttribute('data-view'));
				return;
			}
			if (hit(event.target, '.nc-collection__open')) {
				if (isDrawerOpen()) closeDrawer(true);
				else openDrawer();
				return;
			}
			if (hit(event.target, '.nc-collection__close')) closeDrawer(true);
		});

		root.addEventListener('keydown', (event) => {
			if (event.key === 'Tab' && isDrawerOpen()) {
				trapFocus(event);
				return;
			}
			if (event.key !== 'Escape') return;
			const dropdown = root.querySelector('details.nc-collection__dropdown[open]');
			if (dropdown) {
				dropdown.open = false;
				const summary = dropdown.querySelector('summary');
				if (summary) summary.focus();
				return;
			}
			if (isDrawerOpen()) closeDrawer(true);
		});

		if (openBtn) {
			const sync = () => {
				openBtn.hidden = mobile ? !mobile.matches : false;
				if (mobile && !mobile.matches && isDrawerOpen()) closeDrawer(false);
			};
			if (mobile && mobile.addEventListener) mobile.addEventListener('change', sync);
			sync();
		}

		if (views) {
			views.hidden = false;
			let saved = null;
			try {
				saved = localStorage.getItem(api.storageKey(doc, node));
			} catch (err) {
				saved = null;
			}
			const known = Array.from(views.querySelectorAll('[data-view]')).some((b) => b.getAttribute('data-view') === saved);
			if (saved && known) setView(saved);
		}
	}

	// Chiude i menu a tendina aperti quando si clicca fuori.
	function closeDropdowns(event) {
		for (const dd of document.querySelectorAll('.nc-collection details.nc-collection__dropdown[open]')) {
			if (!dd.contains(event.target)) dd.open = false;
		}
	}

	function init() {
		for (const root of document.querySelectorAll('.nc-collection')) setup(root);
		document.addEventListener('click', closeDropdowns);
	}

	if (typeof module !== 'undefined') module.exports = api;
	if (typeof document !== 'undefined') {
		if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
		else init();
	}
})();

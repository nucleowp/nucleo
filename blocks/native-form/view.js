// Modulo nativo nc/native-form: passaggi, validazione, invio REST e codice OTP.
// Senza JavaScript il modulo è un POST normale con redirect. Lo script aggiunge is-enhanced e lavora sul markup esistente.
(function () {
	'use strict';

	const FALLBACK_ERROR = 'Sending failed. Try again.';
	const CONTROL = 'input:not([type="hidden"]), select, textarea';
	const CAPTCHA_ACTION = 'nucleo_form';

	// Pulsanti di navigazione per il passaggio corrente (current parte da 0).
	function visibleSteps(count, current) {
		const last = count - 1;
		return { back: current > 0, next: current < last, submit: current >= last };
	}

	// Errori {campo: messaggio} del server in lista {name, message}, nell'ordine ricevuto.
	function errorEntries(errors) {
		if (!errors || typeof errors !== 'object') return [];
		return Object.keys(errors)
			.filter((name) => typeof errors[name] === 'string')
			.map((name) => ({ name, message: errors[name] }));
	}

	// Imposta il nonce fresco nel FormData dell'invio.
	function mergeNonce(formData, nonce) {
		if (typeof nonce !== 'string' || nonce === '') throw new TypeError('invalid nonce');
		formData.set('_nc_nonce', nonce);
		return formData;
	}

	// Testo per l'utente da una risposta; response null vale per errore di rete.
	function messageFor(response, labels) {
		const l = labels || {};
		const text = response && typeof response.message === 'string' ? response.message : '';
		if (response && response.ok === true && response.status === 'done') return text || l.done || '';
		if (response && response.ok === true && response.status === 'otp') return text || l.otp || '';
		return text || l.error || FALLBACK_ERROR;
	}

	// Con più passaggi il browser non valida: lo script valida il passaggio corrente all'invio.
	function shouldNovalidate(steps) {
		return Number(steps) > 1;
	}

	// Azione di Avanti con la verifica OTP a metà modulo: 'send' sul passo che invia il codice,
	// 'verify' con il codice mostrato, null altrove. step è null senza verifica a metà modulo.
	function otpPhase(step, current, codeShown, verified) {
		if (!Number.isInteger(step)) return null;
		if (codeShown) return 'verify';
		if (!verified && current === step) return 'send';
		return null;
	}

	// Etichetta di Avanti per l'azione corrente.
	function nextLabel(phase, labels) {
		const l = labels || {};
		if (phase === 'send' && l.sendCode) return l.sendCode;
		if (phase === 'verify' && l.verify) return l.verify;
		return l.next || '';
	}

	// Risposta che fa ripartire la verifica a metà modulo: ticket o prova scaduti, consumati o di un altro indirizzo.
	function restartsOtp(response, otpStep) {
		return otpStep !== null && otpStep !== undefined && !!response && response.ok === false && response.code === 'otp_expired';
	}

	// Dopo un esito non ok il widget del captcha si azzera: i globali mancanti non fanno nulla.
	function captchaReset(provider, win) {
		const w = win || {};
		if (provider === 'recaptcha' || provider === 'recaptcha-v3') {
			if (w.grecaptcha && typeof w.grecaptcha.reset === 'function') w.grecaptcha.reset();
		} else if (provider === 'hcaptcha') {
			if (w.hcaptcha && typeof w.hcaptcha.reset === 'function') w.hcaptcha.reset();
		} else if (provider === 'turnstile') {
			if (w.turnstile && typeof w.turnstile.reset === 'function') w.turnstile.reset();
		} else if (provider === 'friendly') {
			const fc = w.frcaptcha;
			if (fc && typeof fc.getAllWidgets === 'function') {
				for (const widget of fc.getAllWidgets() || []) {
					if (widget && typeof widget.reset === 'function') widget.reset();
				}
			}
		}
	}

	// Token reCAPTCHA v3 per l'invio; '' se il globale manca o la chiave è vuota. Un errore del provider vale come token vuoto,
	// così il server risponde con il proprio messaggio.
	async function captchaToken(provider, sitekey, win) {
		const g = (win || {}).grecaptcha;
		if (provider !== 'recaptcha-v3' || !sitekey || !g || typeof g.execute !== 'function') return '';
		try {
			if (typeof g.ready === 'function') await new Promise((resolve) => g.ready(resolve));
			return String(await g.execute(sitekey, { action: CAPTCHA_ACTION }));
		} catch (err) {
			return '';
		}
	}

	const api = { visibleSteps, shouldNovalidate, errorEntries, mergeNonce, messageFor, otpPhase, nextLabel, restartsOtp, captchaReset, captchaToken };

	function setup(form) {
		const nonceUrl = form.getAttribute('data-nonce-url');
		if (!nonceUrl) return;
		const action = form.getAttribute('action') || location.href;
		const count = parseInt(form.getAttribute('data-steps') || '1', 10) || 1;
		const multi = count > 1;
		const otpAttr = form.getAttribute('data-otp-step');
		const otpStep = otpAttr !== null && /^\d+$/.test(otpAttr) && Number(otpAttr) < count - 1 ? Number(otpAttr) : null;
		const otpField = form.getAttribute('data-otp-field') || '';
		const steps = Array.from(form.querySelectorAll('.nc-native-form__step'));
		const nav = form.querySelector('.nc-native-form__nav');
		const back = form.querySelector('[data-action="back"]');
		const next = form.querySelector('[data-action="next"]');
		const submit = form.querySelector('button[type="submit"]');
		const labels = {
			error: form.getAttribute('data-error-label') || '',
			sendCode: form.getAttribute('data-send-label') || '',
			verify: form.getAttribute('data-verify-label') || '',
			next: next ? next.textContent : '',
		};
		const otp = form.querySelector('.nc-native-form__otp');
		const otpInput = form.querySelector('input[name="_nc_code"]');
		const ticketInput = form.querySelector('input[name="_nc_ticket"]');
		const proofInput = form.querySelector('input[name="_nc_proof"]');
		const ctxInput = form.querySelector('input[name="_nc_ctx"]');
		const message = form.querySelector('.nc-native-form__message');
		const captcha = form.querySelector('.nc-native-form__captcha');
		const provider = captcha ? captcha.getAttribute('data-captcha') || '' : '';
		const sitekey = captcha ? captcha.getAttribute('data-sitekey') || '' : '';
		const fields = () => Array.from(form.querySelectorAll('.nc-native-form__field'));
		let current = 0;
		let busy = false;
		let otpMode = false;
		let codeShown = false;
		let verified = false;

		const say = (text) => {
			if (message) message.textContent = text;
		};
		const stepEl = (index) => steps.find((s) => Number(s.getAttribute('data-step')) === index) || null;
		const firstControl = (scope) => (scope ? scope.querySelector(CONTROL) : null);
		// Campo email della verifica: diventa di sola lettura dopo la verifica del codice.
		const otpControl = () => {
			const field = otpField ? fields().find((f) => f.getAttribute('data-field') === otpField) : null;
			return field ? firstControl(field) : null;
		};
		const phaseNow = () => api.otpPhase(otpStep, current, codeShown, verified);

		// Etichetta di Avanti secondo l'azione del passo corrente.
		function syncNext() {
			if (next) next.textContent = api.nextLabel(phaseNow(), labels);
		}

		function showStep(index) {
			current = index;
			for (const step of steps) step.hidden = Number(step.getAttribute('data-step')) !== index;
			const state = api.visibleSteps(count, index);
			if (back) back.hidden = !state.back;
			if (next) next.hidden = !state.next;
			if (submit) submit.hidden = !state.submit;
			syncNext();
		}

		// Valida i campi del passaggio corrente e mette a fuoco il primo errore.
		function stepValid() {
			const scope = stepEl(current);
			const controls = scope ? Array.from(scope.querySelectorAll(CONTROL)) : [];
			const bad = controls.find((c) => !c.checkValidity());
			if (!bad) return true;
			bad.reportValidity();
			bad.focus();
			return false;
		}

		// Avanti: valida solo i campi del passaggio corrente.
		function goNext() {
			if (current >= count - 1) return;
			if (!stepValid()) return;
			showStep(current + 1);
			const control = firstControl(stepEl(current));
			if (control) control.focus();
		}

		// Avanti con la verifica OTP a metà modulo: invia il codice o verifica, altrimenti passa oltre.
		function advance() {
			const phase = phaseNow();
			if (phase === 'send') sendCode();
			else if (phase === 'verify') verifyCode();
			else goNext();
		}

		function clearErrors() {
			for (const p of form.querySelectorAll('.nc-native-form__error')) {
				p.textContent = '';
				p.hidden = true;
			}
			for (const c of form.querySelectorAll('[aria-invalid]')) c.removeAttribute('aria-invalid');
		}

		// Mostra gli errori sui campi e torna al primo passaggio con errore.
		// Ritorna se un campo è stato trovato e gli errori senza campo.
		function showErrors(entries) {
			const named = new Set();
			const orphans = [];
			for (const { name, message: text } of entries) {
				const field = fields().find((f) => f.getAttribute('data-field') === name);
				if (!field) {
					orphans.push(text);
					continue;
				}
				named.add(name);
				const p = field.querySelector('.nc-native-form__error');
				if (p) {
					p.textContent = text;
					p.hidden = false;
				}
				const control = firstControl(field);
				if (control) control.setAttribute('aria-invalid', 'true');
			}
			const first = fields().find((f) => named.has(f.getAttribute('data-field'))) || null;
			if (first) {
				const step = first.closest('.nc-native-form__step');
				if (multi && step) showStep(Number(step.getAttribute('data-step')));
				const control = firstControl(first);
				if (control) control.focus();
			}
			return { first: !!first, orphans };
		}

		function setBusy(on) {
			busy = on;
			if (submit) submit.disabled = on;
			if (next) next.disabled = on;
		}

		// Passa al codice OTP: nasconde i passaggi e abilita il campo del codice.
		function enterOtp(ticket, text) {
			otpMode = true;
			if (ticketInput) ticketInput.value = ticket;
			for (const step of steps) step.hidden = true;
			// Il pulsante di invio resta: serve a inviare il codice. Spariscono solo Avanti e Indietro.
			if (nav) nav.hidden = false;
			if (back) back.hidden = true;
			if (next) next.hidden = true;
			if (submit) submit.hidden = false;
			if (otp) otp.hidden = false;
			if (otpInput) {
				otpInput.disabled = false;
				otpInput.focus();
			}
			if (text) say(text);
		}

		// Codice inviato con la verifica a metà modulo: nasconde il passo, abilita il codice, Avanti diventa Verifica.
		function enterCode(ticket, text) {
			codeShown = true;
			otpMode = true;
			if (ticketInput) ticketInput.value = ticket;
			for (const step of steps) step.hidden = true;
			if (nav) nav.hidden = false;
			if (back) back.hidden = false;
			if (next) next.hidden = false;
			if (submit) submit.hidden = true;
			if (otp) otp.hidden = false;
			if (otpInput) {
				otpInput.disabled = false;
				otpInput.focus();
			}
			syncNext();
			if (text) say(text);
		}

		// Indietro dal codice: torna al passo della verifica e azzera ticket e prova.
		function leaveCode() {
			codeShown = false;
			otpMode = false;
			if (ticketInput) ticketInput.value = '';
			if (proofInput) proofInput.value = '';
			if (otpInput) {
				otpInput.value = '';
				otpInput.disabled = true;
			}
			if (otp) otp.hidden = true;
			clearErrors();
			say('');
			showStep(otpStep);
		}

		// Codice verificato: il ticket diventa la prova, l'email si blocca e si passa al passo successivo.
		function markVerified() {
			codeShown = false;
			verified = true;
			otpMode = false;
			if (proofInput && ticketInput) proofInput.value = ticketInput.value;
			// L'invio finale porta solo la prova: con _nc_ticket il server userebbe il flusso classico.
			if (ticketInput) ticketInput.value = '';
			const email = otpControl();
			if (email) email.readOnly = true;
			if (otpInput) {
				otpInput.value = '';
				otpInput.disabled = true;
			}
			if (otp) otp.hidden = true;
			showStep(otpStep + 1);
			const control = firstControl(stepEl(current));
			if (control) control.focus();
		}

		// Prova scaduta o già usata: si torna al passo del codice con l'email di nuovo modificabile. I dati restano.
		function restartOtp(text) {
			verified = false;
			const email = otpControl();
			if (email) email.readOnly = false;
			leaveCode();
			say(text);
		}

		// Esito finale: resetta e nasconde i campi, lascia il messaggio.
		function finish(text) {
			form.reset();
			otpMode = false;
			codeShown = false;
			verified = false;
			const email = otpControl();
			if (email) email.readOnly = false;
			for (const step of steps) step.hidden = true;
			if (nav) nav.hidden = true;
			if (otp) otp.hidden = true;
			if (otpInput) otpInput.disabled = true;
			say(text);
		}

		function otpPayload() {
			const data = new FormData();
			data.append('_nc_ctx', ctxInput ? ctxInput.value : '');
			data.append('_nc_ticket', ticketInput ? ticketInput.value : '');
			data.append('_nc_code', otpInput ? otpInput.value : '');
			return data;
		}

		// Verifica del codice a metà modulo: ticket, codice, contesto e honeypot.
		function verifyPayload() {
			const data = otpPayload();
			data.append('_nc_otp', 'verify');
			const hp = form.querySelector('input[name="_nc_hp"]');
			data.append('_nc_hp', hp ? hp.value : '');
			return data;
		}

		async function fetchNonce() {
			const res = await fetch(nonceUrl, { headers: { Accept: 'application/json' } });
			const data = await res.json();
			if (!res.ok || !data) throw new Error('nonce not available');
			return data.nonce;
		}

		async function postForm(data) {
			const res = await fetch(action, {
				method: 'POST',
				body: data,
				headers: { Accept: 'application/json', 'X-Nucleo-Form': '1' },
			});
			return res.json();
		}

		// Token reCAPTCHA v3 nel FormData prima dell'invio; gli altri provider mettono il proprio campo da soli.
		async function withCaptcha(data) {
			if (provider === 'recaptcha-v3') data.set('g-recaptcha-response', await api.captchaToken(provider, sitekey, window));
			return data;
		}

		function handle(response) {
			const ok = !!response && response.ok === true;
			if (!ok && provider) api.captchaReset(provider, window);
			if (ok && response.status === 'code' && typeof response.ticket === 'string') {
				enterCode(response.ticket, api.messageFor(response, labels));
				return;
			}
			if (ok && response.status === 'verified') {
				markVerified();
				return;
			}
			if (ok && response.status === 'otp' && typeof response.ticket === 'string') {
				enterOtp(response.ticket, api.messageFor(response, labels));
				return;
			}
			if (ok && response.status === 'done') {
				if (typeof response.redirect === 'string' && response.redirect !== '') {
					window.location.assign(response.redirect);
					return;
				}
				finish(api.messageFor(response, labels));
				return;
			}
			if (api.restartsOtp(response, otpStep)) {
				restartOtp(api.messageFor(response, labels));
				return;
			}
			const shown = showErrors(api.errorEntries(response && response.ok === false ? response.errors : null));
			if (shown.orphans.length) say(shown.orphans.join(' '));
			else if (!shown.first) say(api.messageFor(response, labels));
		}

		// Invio: valida il passaggio corrente (o il codice OTP) con reportValidity, poi nonce fresco e POST.
		function validateCurrent() {
			const scope = otpMode ? otp : stepEl(current);
			const controls = scope ? Array.from(scope.querySelectorAll(CONTROL)) : [];
			const bad = controls.find((c) => !c.checkValidity());
			if (!bad) return true;
			bad.reportValidity();
			bad.focus();
			return false;
		}

		// Nonce fresco e POST: ritorna la risposta, null per errore di rete.
		async function request(data) {
			setBusy(true);
			let response = null;
			try {
				mergeNonce(data, await fetchNonce());
				response = await postForm(data);
			} catch (err) {
				response = null;
			}
			setBusy(false);
			return response;
		}

		// Invio: nonce fresco, poi POST con header X-Nucleo-Form. In OTP solo i dati del codice.
		async function send() {
			clearErrors();
			say('');
			const data = otpMode ? otpPayload() : await withCaptcha(new FormData(form));
			handle(await request(data));
		}

		// Avanti sul passo della verifica: valida il passo, poi chiede l'invio del codice.
		async function sendCode() {
			if (!stepValid()) return;
			clearErrors();
			say('');
			const data = new FormData(form);
			data.set('_nc_otp', 'send');
			handle(await request(await withCaptcha(data)));
		}

		// Avanti con il codice mostrato: valida il codice, poi lo verifica.
		async function verifyCode() {
			if (!validateCurrent()) return;
			clearErrors();
			say('');
			handle(await request(verifyPayload()));
		}

		form.classList.add('is-enhanced');
		if (back) back.type = 'button';
		if (next) next.type = 'button';
		if (multi) showStep(0);
		if (api.shouldNovalidate(count)) form.setAttribute('novalidate', '');
		if (otp && !otp.hidden) enterOtp(ticketInput ? ticketInput.value : '', '');
		else if (otpInput) otpInput.disabled = true;

		form.addEventListener('submit', (event) => {
			event.preventDefault();
			if (busy) return;
			if (codeShown) {
				verifyCode();
				return;
			}
			if (!otpMode && multi && current < count - 1) {
				advance();
				return;
			}
			if (!validateCurrent()) return;
			send();
		});
		if (back) {
			back.addEventListener('click', () => {
				if (busy) return;
				if (codeShown) leaveCode();
				else if (current > 0) showStep(current - 1);
			});
		}
		if (next) {
			next.addEventListener('click', () => {
				if (!busy) advance();
			});
		}
	}

	function init() {
		for (const form of document.querySelectorAll('form.nc-native-form')) setup(form);
	}

	if (typeof module !== 'undefined') module.exports = api;
	if (typeof document !== 'undefined') {
		if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
		else init();
	}
})();

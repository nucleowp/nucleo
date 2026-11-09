/**
 * Pagina Impostazioni di Nucleo (nucleo-settings), specifica 4.12.
 * Nessun build: wp.element, wp.i18n. Dipende da ui.js (window.nucleoUI).
 * Nei test (node --test) si esportano gli helper puri; nel browser è window.nucleoPages['nucleo-settings'].
 */
( function () {
	'use strict';

	const root = typeof window !== 'undefined' ? window : {};
	const TABS = [ 'editor', 'performance', 'service', 'captcha', 'integrations', 'agents' ];
	const CAPTCHA_PROVIDERS = [ '', 'recaptcha', 'recaptcha-v3', 'hcaptcha', 'turnstile', 'friendly' ];
	const DEFAULT_THRESHOLD = 0.5;

	/** Righe di testo in lista di selettori: senza spazi attorno e senza righe vuote. */
	function safelistFromText( text ) {
		return String( text || '' ).split( /\r?\n/ ).map( ( s ) => s.trim() ).filter( Boolean );
	}

	/** Sottoinsiemi di font separati da spazi o virgole. */
	function subsetsFromText( text ) {
		return String( text || '' ).split( /[\s,]+/ ).filter( Boolean );
	}

	/** Righe `tipo = parole` in mappa tipo → parole. Un valore non numerico resta NaN e il server lo rifiuta. */
	function thinWordsFromText( text ) {
		const out = {};
		String( text || '' ).split( /\r?\n/ ).forEach( ( line ) => {
			const [ type, value ] = line.split( '=' ).map( ( s ) => s.trim() );
			if ( type ) {
				out[ type ] = value === '' || value === undefined ? NaN : Number( value );
			}
		} );
		return out;
	}

	/** Testo `tipo = parole`, una riga per tipo di contenuto. */
	function thinWordsToText( map ) {
		const entries = map && typeof map === 'object' ? Object.entries( map ) : [];
		return entries.map( ( [ type, words ] ) => `${ type } = ${ words }` ).join( '\n' );
	}

	/** Ruoli con la vista solo testi, con `role` aggiunto o tolto. */
	function toggleRole( roles, role, on ) {
		const rest = roles.filter( ( r ) => r !== role );
		return on ? [ ...rest, role ] : rest;
	}

		/** Dati del servizio da inviare. La chiave vuota non si invia, così resta quella salvata. */
	function servicePayload( service ) {
		const out = { url: String( service.url || '' ).trim() };
		if ( service.key ) {
			out.key = service.key;
		}
		return out;
	}

	/** Dati del captcha da inviare. Il segreto vuoto non si invia, così resta quello salvato. */
	function captchaPayload( captcha ) {
		const threshold = String( captcha.threshold ).trim() === '' ? DEFAULT_THRESHOLD : Number( captcha.threshold );
		const out = {
			provider: String( captcha.provider || '' ),
			siteKey: String( captcha.siteKey || '' ).trim(),
			threshold,
		};
		if ( captcha.secret ) {
			out.secret = captcha.secret;
		}
		return out;
	}

	/** Dati della qualità da inviare: regole non forzabili e soglie per tipo di contenuto. */
	function qualityPayload( quality ) {
		return {
			nonForcible: safelistFromText( quality.nonForcibleText ),
			thinWords: thinWordsFromText( quality.thinWordsText ),
		};
	}

	/** Parte `settings` da inviare per una scheda; null per le schede senza salvataggio. */
	function groupPayload( tab, draft ) {
		switch ( tab ) {
			case 'editor':
				return { editor: { textOnlyRoles: draft.editor.textOnlyRoles }, quality: qualityPayload( draft.quality ) };
			case 'performance':
				return {
					performance: {
						wooFragments: draft.performance.wooFragments,
						purgeSafelist: safelistFromText( draft.performance.safelistText ),
					},
					assets: { purgeMode: draft.assets.purgeMode },
					fonts: { subsets: subsetsFromText( draft.fonts.subsetsText ) },
				};
			case 'service':
				return { service: servicePayload( draft.service ) };
			case 'captcha':
				return { captcha: captchaPayload( draft.captcha ) };
			default:
				return null;
		}
	}

	/** Bozza modificabile costruita dalle impostazioni ricevute dal server. */
	function draftFrom( settings ) {
		const s = settings || {};
		const editor = s.editor || {};
		const perf = s.performance || {};
		const assets = s.assets || {};
		const fonts = s.fonts || {};
		const quality = s.quality || {};
		const service = s.service || {};
		const captcha = s.captcha || {};
		return {
			editor: { textOnlyRoles: Array.isArray( editor.textOnlyRoles ) ? editor.textOnlyRoles : [] },
			performance: {
				wooFragments: perf.wooFragments === 'keep' ? 'keep' : 'off',
				safelistText: Array.isArray( perf.purgeSafelist ) ? perf.purgeSafelist.join( '\n' ) : '',
			},
			assets: { purgeMode: assets.purgeMode === 'strict' ? 'strict' : 'normal' },
			fonts: { subsetsText: Array.isArray( fonts.subsets ) ? fonts.subsets.join( ', ' ) : '' },
			quality: {
				nonForcibleText: Array.isArray( quality.nonForcible ) ? quality.nonForcible.join( '\n' ) : '',
				thinWordsText: thinWordsToText( quality.thinWords ),
			},
			service: { url: service.url || '', key: '' },
			keySet: Boolean( service.keySet ),
			// La soglia resta testo nella bozza, così il campo si può svuotare mentre si digita.
			captcha: {
				provider: CAPTCHA_PROVIDERS.includes( captcha.provider ) ? captcha.provider : '',
				siteKey: captcha.siteKey || '',
				threshold: String( typeof captcha.threshold === 'number' ? captcha.threshold : DEFAULT_THRESHOLD ),
				secret: '',
			},
			captchaSecretSet: Boolean( captcha.secretSet ),
		};
	}

	const api = { TABS, safelistFromText, subsetsFromText, thinWordsFromText, thinWordsToText, toggleRole, servicePayload, captchaPayload, qualityPayload, groupPayload, draftFrom };

	const el = root.wp && root.wp.element;
	if ( el ) {
		const { createElement: h, useState, useEffect } = el;
		const __ = ( s, d ) => root.wp.i18n.__( s, d || 'nucleo' );
		const UI = root.nucleoUI;

		/** Riga a due colonne: etichetta e spiegazione a sinistra, controllo a destra, con filetto sopra. */
		function FormRow( { label, help, htmlFor, children } ) {
			return h( 'div', { className: 'nc-grid nc-setting' },
				h( 'div', { className: 'nc-col-4 nc-field' },
					h( htmlFor ? 'label' : 'span', { className: 'nc-field__label', htmlFor }, label ),
					help && h( 'span', { className: 'nc-field__help' }, help ) ),
				h( 'div', { className: 'nc-col-8 nc-stack', style: { alignItems: 'stretch', justifyContent: 'flex-start' } }, children ) );
		}

		/** Pulsante di salvataggio della scheda, la nota sugli utenti e, sotto, l'errore del server. */
		function SaveBar( { busy, error, onSave } ) {
			return h( 'div', { className: 'nc-stack' },
				h( 'div', { className: 'nc-row' },
					h( 'button', { type: 'button', className: 'nc-btn nc-btn--primary', disabled: busy, onClick: onSave },
						busy ? __( 'Saving…', 'nucleo' ) : __( 'Save changes', 'nucleo' ) ),
					h( 'span', { className: 'nc-help' }, __( 'Changes apply to all users.', 'nucleo' ) ) ),
				error && h( UI.Notice, { tone: 'err' }, error ) );
		}

		function EditorPanel( { draft, setDraft, busy, error, onSave } ) {
			const roles = [
				[ 'editor', __( 'Editor', 'nucleo' ) ],
				[ 'author', __( 'Author', 'nucleo' ) ],
				[ 'contributor', __( 'Contributor', 'nucleo' ) ],
			];
			const views = [
				[ 'full', __( 'Full', 'nucleo' ) ],
				[ 'text', __( 'Text only', 'nucleo' ) ],
			];
			const current = draft.editor.textOnlyRoles;
			const setQuality = ( patch ) => setDraft( ( d ) => ( { ...d, quality: { ...d.quality, ...patch } } ) );
			return h( UI.Card, { title: __( 'Editor', 'nucleo' ) },
				h( FormRow, {
					label: __( 'Default view by role', 'nucleo' ),
					help: __( 'Anyone without a personal choice sees this view.', 'nucleo' ),
				},
					roles.map( ( [ slug, roleLabel ] ) => h( 'label', { key: slug, className: 'nc-row', style: { justifyContent: 'flex-start' } },
						h( 'span', { className: 'nc-field__help', style: { width: '90px' } }, roleLabel ),
						h( 'select', {
							className: 'nc-select',
							style: { width: '180px' },
							value: current.includes( slug ) ? 'text' : 'full',
							onChange: ( e ) => {
								const on = e.target.value === 'text';
								setDraft( ( d ) => ( { ...d, editor: { textOnlyRoles: toggleRole( d.editor.textOnlyRoles, slug, on ) } } ) );
							},
						}, views.map( ( [ value, viewLabel ] ) => h( 'option', { key: value, value }, viewLabel ) ) ) ) ) ),
				h( FormRow, {
					label: __( 'Rules that block publishing', 'nucleo' ),
					help: __( 'Quality rules that cannot be overridden to publish, one id per line.', 'nucleo' ),
					htmlFor: 'nucleo-quality-nonforcible',
				},
					h( 'textarea', {
						id: 'nucleo-quality-nonforcible',
						className: 'nc-textarea nc-textarea--code',
						rows: 4,
						value: draft.quality.nonForcibleText,
						onChange: ( e ) => setQuality( { nonForcibleText: e.target.value } ),
					} ) ),
				h( FormRow, {
					label: __( 'Word counts by post type', 'nucleo' ),
					help: __( 'One per line as post type = words, from 0 to 5000.', 'nucleo' ),
					htmlFor: 'nucleo-quality-thin-words',
				},
					h( 'textarea', {
						id: 'nucleo-quality-thin-words',
						className: 'nc-textarea nc-textarea--code',
						rows: 4,
						value: draft.quality.thinWordsText,
						onChange: ( e ) => setQuality( { thinWordsText: e.target.value } ),
					} ) ),
				h( SaveBar, { busy, error, onSave } ) );
		}

		function PerformancePanel( { draft, setDraft, busy, error, onSave } ) {
			const p = draft.performance;
			const setPerf = ( patch ) => setDraft( ( d ) => ( { ...d, performance: { ...d.performance, ...patch } } ) );
			const setAssets = ( patch ) => setDraft( ( d ) => ( { ...d, assets: { ...d.assets, ...patch } } ) );
			const setFonts = ( patch ) => setDraft( ( d ) => ( { ...d, fonts: { ...d.fonts, ...patch } } ) );
			const fragments = [
				[ 'off', __( 'Turn off on pages without a cart', 'nucleo' ) ],
				[ 'keep', __( 'Keep', 'nucleo' ) ],
			];
			return h( UI.Card, { title: __( 'Performance', 'nucleo' ) },
				h( FormRow, {
					label: __( 'WooCommerce cart fragments', 'nucleo' ),
					help: __( 'Turn them off on pages without a cart to lighten the page.', 'nucleo' ),
					htmlFor: 'nucleo-woo-fragments',
				},
					h( 'select', {
						id: 'nucleo-woo-fragments',
						className: 'nc-select',
						style: { width: '100%' },
						value: p.wooFragments,
						onChange: ( e ) => setPerf( { wooFragments: e.target.value } ),
					}, fragments.map( ( [ value, label ] ) => h( 'option', { key: value, value }, label ) ) ) ),
				h( FormRow, {
					label: __( 'Font subsets', 'nucleo' ),
					help: __( 'Separated by commas. Empty means latin and latin-ext.', 'nucleo' ),
					htmlFor: 'nucleo-font-subsets',
				},
					h( 'input', {
						id: 'nucleo-font-subsets',
						className: 'nc-input',
						type: 'text',
						autoComplete: 'off',
						value: draft.fonts.subsetsText,
						onChange: ( e ) => setFonts( { subsetsText: e.target.value } ),
					} ) ),
				h( SaveBar, { busy, error, onSave } ) );
		}

		function CaptchaPanel( { draft, setDraft, busy, error, onSave } ) {
			const [ shown, setShown ] = useState( false );
			const c = draft.captcha;
			const setCaptcha = ( patch ) => setDraft( ( d ) => ( { ...d, captcha: { ...d.captcha, ...patch } } ) );
			const providers = [
				[ '', __( 'None', 'nucleo' ) ],
				[ 'recaptcha', __( 'reCAPTCHA v2', 'nucleo' ) ],
				[ 'recaptcha-v3', __( 'reCAPTCHA v3', 'nucleo' ) ],
				[ 'hcaptcha', __( 'hCaptcha', 'nucleo' ) ],
				[ 'turnstile', __( 'Cloudflare Turnstile', 'nucleo' ) ],
				[ 'friendly', __( 'Friendly Captcha', 'nucleo' ) ],
			];
			return h( UI.Card, { title: __( 'Captcha', 'nucleo' ) },
				h( FormRow, { label: __( 'Provider', 'nucleo' ), htmlFor: 'nucleo-captcha-provider' },
					h( 'select', {
						id: 'nucleo-captcha-provider',
						className: 'nc-select',
						style: { width: '100%' },
						value: c.provider,
						onChange: ( e ) => setCaptcha( { provider: e.target.value } ),
					}, providers.map( ( [ value, label ] ) => h( 'option', { key: value || 'none', value }, label ) ) ) ),
				h( FormRow, { label: __( 'Site key', 'nucleo' ), htmlFor: 'nucleo-captcha-site-key' },
					h( 'input', {
						id: 'nucleo-captcha-site-key',
						className: 'nc-input',
						type: 'text',
						autoComplete: 'off',
						value: c.siteKey,
						onChange: ( e ) => setCaptcha( { siteKey: e.target.value } ),
					} ) ),
				h( FormRow, { label: __( 'Secret', 'nucleo' ), htmlFor: 'nucleo-captcha-secret' },
					h( 'div', { className: 'nc-row' },
						h( 'input', {
							id: 'nucleo-captcha-secret',
							className: 'nc-input',
							style: { flex: 1 },
							type: shown ? 'text' : 'password',
							autoComplete: 'off',
							placeholder: draft.captchaSecretSet ? __( 'Saved', 'nucleo' ) : '',
							value: c.secret,
							onChange: ( e ) => setCaptcha( { secret: e.target.value } ),
						} ),
						h( 'button', {
							type: 'button',
							className: 'nc-btn nc-btn--secondary nc-btn--small',
							onClick: () => setShown( ! shown ),
						}, shown ? __( 'Hide', 'nucleo' ) : __( 'Show', 'nucleo' ) ) ) ),
				c.provider === 'recaptcha-v3' && h( FormRow, { label: __( 'Threshold (0 to 1)', 'nucleo' ), htmlFor: 'nucleo-captcha-threshold' },
					h( 'input', {
						id: 'nucleo-captcha-threshold',
						className: 'nc-input',
						type: 'number',
						min: 0,
						max: 1,
						step: 0.1,
						value: c.threshold,
						onChange: ( e ) => setCaptcha( { threshold: e.target.value } ),
					} ) ),
				h( SaveBar, { busy, error, onSave } ) );
		}

		function IntegrationsPanel( { integrations } ) {
			const columns = [
				{ key: 'name', label: __( 'Integration', 'nucleo' ) },
				{
					key: 'active',
					label: __( 'Status', 'nucleo' ),
					render: ( v ) => h( UI.Pill, { tone: v ? 'ok' : 'neutral' }, v ? __( 'Detected', 'nucleo' ) : __( 'Not detected', 'nucleo' ) ),
				},
				{
					key: 'does',
					label: __( 'What Nucleo does', 'nucleo' ),
					render: ( v ) => h( 'span', { style: { fontFamily: 'var(--nc-font-sans)', color: 'var(--nc-text-muted)' } }, v ),
				},
			];
			return h( UI.Card, { title: __( 'Integrations', 'nucleo' ) },
				h( UI.Table, {
					columns,
					rows: integrations,
					rowKey: ( r ) => r.id,
					empty: __( 'No integrations found.', 'nucleo' ),
				} ) );
		}

		function AgentsPanel() {
			return h( UI.Card, { title: __( 'AI agents', 'nucleo' ) },
				h( 'p', null, __( 'Agents are registered on their own page.', 'nucleo' ) ),
				h( 'a', { href: 'admin.php?page=nucleo-agents' }, __( 'Open AI agents', 'nucleo' ) ) );
		}

			function SettingsPage() {
			const [ tab, setTab ] = useState( () => UI.tabFromSearch( root.location.search, TABS, 'editor' ) );
			const [ loaded, reload ] = UI.useLoad( () => UI.call( 'nucleo/settings-get', {} ), [] );
			const [ draft, setDraft ] = useState( null );
			const [ busy, setBusy ] = useState( false );
			const [ error, setError ] = useState( '' );
			const [ notice, setNotice ] = useState( '' );

			useEffect( () => {
				if ( loaded.data && draft === null ) {
					setDraft( draftFrom( loaded.data.settings ) );
				}
			}, [ loaded.data, draft ] );

			/** Salva solo la scheda attiva; le altre bozze restano nello stato. */
			function save() {
				const input = { settings: groupPayload( tab, draft ) };
				const sentKey = draft.service.key;
				const sentSecret = draft.captcha.secret;
				setBusy( true );
				setError( '' );
				setNotice( '' );
				UI.call( 'nucleo/settings-set', input ).then( ( res ) => {
					if ( res && res.ok === false ) {
						setError( ( res.error && res.error.message ) || __( 'The settings could not be saved.', 'nucleo' ) );
						return;
					}
					if ( tab === 'service' ) {
						setDraft( ( d ) => ( { ...d, keySet: d.keySet || sentKey !== '', service: { ...d.service, key: '' } } ) );
					}
					if ( tab === 'captcha' ) {
						setDraft( ( d ) => ( { ...d, captchaSecretSet: d.captchaSecretSet || sentSecret !== '', captcha: { ...d.captcha, secret: '' } } ) );
					}
					setNotice( __( 'Settings saved.', 'nucleo' ) );
				} ).finally( () => setBusy( false ) );
			}

			function body() {
				if ( tab === 'agents' ) {
					return h( AgentsPanel );
				}
				if ( loaded.loading ) {
					return h( UI.Skeleton, { lines: 3 } );
				}
				if ( loaded.error ) {
					return h( UI.ErrorBox, {
						message: loaded.error.message || __( 'The settings could not be loaded.', 'nucleo' ),
						onRetry: reload,
					} );
				}
				if ( tab === 'integrations' ) {
					return h( IntegrationsPanel, { integrations: loaded.data.integrations || [] } );
				}
				if ( draft === null ) {
					return h( UI.Skeleton, { lines: 3 } );
				}
				const shared = { draft, setDraft, busy, error, onSave: save };
				if ( tab === 'performance' ) {
					return h( PerformancePanel, shared );
				}
				if ( tab === 'captcha' ) {
					return h( CaptchaPanel, shared );
				}
				return h( EditorPanel, shared );
			}

			const tabs = [
				{ id: 'editor', label: __( 'Editor', 'nucleo' ) },
				{ id: 'performance', label: __( 'Performance', 'nucleo' ) },
				{ id: 'captcha', label: __( 'Captcha', 'nucleo' ) },
				{ id: 'integrations', label: __( 'Integrations', 'nucleo' ) },
				{ id: 'agents', label: __( 'AI agents', 'nucleo' ) },
			];

			return h( UI.Page, { title: __( 'Settings', 'nucleo' ), subtitle: __( 'Nucleo options in one place.', 'nucleo' ) },
				h( UI.Tabs, {
					tabs,
					active: tab,
					onChange: ( id ) => {
						setTab( id );
						setError( '' );
						setNotice( '' );
					},
				} ),
				notice && h( UI.Notice, { tone: 'ok', autoClose: true, onClose: () => setNotice( '' ) }, notice ),
				body() );
		}

		root.nucleoPages = root.nucleoPages || {};
		root.nucleoPages[ 'nucleo-settings' ] = SettingsPage;
	}

	if ( typeof module === 'object' && module.exports ) {
		module.exports = api;
	}
} )();

/**
 * Libreria UI dell'amministrazione di Nucleo. Nessun build: wp.element, wp.components, wp.i18n.
 * Funzioni pure e call esistono sempre; componenti e useLoad solo se window.wp.element esiste.
 * Nei test (node --test) si esporta con module.exports; nel browser è window.nucleoUI.
 */
( function () {
	'use strict';

	const root = typeof window !== 'undefined' ? window : {};
	// In node si usa tree.js direttamente; nel browser è window.nucleoTree, caricato prima di questo file.
	const nodeTree = typeof module === 'object' && module.exports ? require( '../editor/tree.js' ) : null;
	const cls = ( ...names ) => names.filter( Boolean ).join( ' ' );

	/* ---------- Funzioni pure ---------- */

	/** Tono di un punteggio 0-100: ok da 80, warn da 50 a 79, err sotto 50. */
	function scoreTone( n ) {
		if ( n >= 80 ) {
			return 'ok';
		}
		if ( n >= 50 ) {
			return 'warn';
		}
		return 'err';
	}

	/** Glifo del tono, usato da Pill e Notice. Altri toni: stringa vuota. */
	function glyph( tone ) {
		switch ( tone ) {
			case 'ok':
				return '✓';
			case 'warn':
				return '▲';
			case 'err':
				return '✕';
			case 'info':
				return 'i';
			default:
				return '';
		}
	}

	/** Da #rgb o #rrggbb a [r, g, b]; altrimenti null. */
	function hexToRgb( hex ) {
		const m = typeof hex === 'string' ? /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.exec( hex ) : null;
		if ( ! m ) {
			return null;
		}
		let digits = m[ 1 ];
		if ( digits.length === 3 ) {
			digits = digits.split( '' ).map( ( c ) => c + c ).join( '' );
		}
		const n = parseInt( digits, 16 );
		return [ ( n >> 16 ) & 255, ( n >> 8 ) & 255, n & 255 ];
	}

	/** Luminanza relativa WCAG di un colore [r, g, b]. */
	function luminance( rgb ) {
		const [ r, g, b ] = rgb.map( ( v ) => {
			const c = v / 255;
			return c <= 0.03928 ? c / 12.92 : Math.pow( ( c + 0.055 ) / 1.055, 2.4 );
		} );
		return 0.2126 * r + 0.7152 * g + 0.0722 * b;
	}

	/** Rapporto di contrasto WCAG arrotondato a 2 decimali. Null se un colore non è un esadecimale valido. */
	function contrast( hexA, hexB ) {
		const a = hexToRgb( hexA );
		const b = hexToRgb( hexB );
		if ( ! a || ! b ) {
			return null;
		}
		const [ hi, lo ] = [ luminance( a ), luminance( b ) ].sort( ( x, y ) => y - x );
		return Math.round( ( ( hi + 0.05 ) / ( lo + 0.05 ) ) * 100 ) / 100;
	}

	/** Valore di `tab` nella query se è in `allowed`, altrimenti `fallback`. */
	function tabFromSearch( search, allowed, fallback ) {
		const tab = new URLSearchParams( search || '' ).get( 'tab' );
		return tab !== null && allowed.includes( tab ) ? tab : fallback;
	}

	/** URL con `tab` impostato, gli altri parametri invariati. */
	function withTab( href, tab ) {
		const url = new URL( href );
		url.searchParams.set( 'tab', tab );
		return url.toString();
	}

	/** Dimensione leggibile in base 1024: '512 B', '9.1 KB', '1.4 MB'. */
	function bytes( n ) {
		if ( n < 1024 ) {
			return `${ n } B`;
		}
		if ( n < 1024 * 1024 ) {
			return `${ ( n / 1024 ).toFixed( 1 ) } KB`;
		}
		return `${ ( n / ( 1024 * 1024 ) ).toFixed( 1 ) } MB`;
	}

	/** Accesso di pagina, scheda od opzione da window.nucleoAdmin.nav. Senza nav o senza voce è 'ok'. */
	function access( page, kind, id ) {
		const nav = root.nucleoAdmin && root.nucleoAdmin.nav;
		const open = { access: 'ok', reason: null, plugins: [] };
		const entry = Array.isArray( nav ) ? nav.find( ( n ) => n && n.slug === page ) : undefined;
		if ( ! entry ) {
			return open;
		}
		let item = entry;
		if ( kind ) {
			const bucket = kind === 'tab' ? entry.tabs : entry.options;
			item = bucket && Object.prototype.hasOwnProperty.call( bucket, id ) ? bucket[ id ] : undefined;
			if ( ! item ) {
				return open;
			}
		}
		return {
			access: item.access || 'ok',
			reason: item.reason || null,
			plugins: Array.isArray( item.plugins ) ? item.plugins : [],
		};
	}

	/** Icona della pagina (simbolo dello sprite) da window.nucleoAdmin.nav; '' se manca. */
	function pageIcon( page ) {
		const nav = root.nucleoAdmin && root.nucleoAdmin.nav;
		const entry = Array.isArray( nav ) ? nav.find( ( n ) => n && n.slug === page ) : undefined;
		return ( entry && typeof entry.icon === 'string' && entry.icon ) || '';
	}

	/**
	 * Id del simbolo di un blocco nello sprite block-icons.svg: nc/heading -> nb-heading, woo/cart -> nb-woo-cart.
	 * has( id ) dice se il simbolo esiste; senza simbolo resta l'icona generica nb-block.
	 */
	function blockIconId( type, has ) {
		const [ vendor, name ] = String( type || '' ).split( '/' );
		const id = name ? 'nb-' + ( vendor === 'nc' ? name : vendor + '-' + name ) : '';
		return id && has( id ) ? id : 'nb-block';
	}

	/** Query string per le ability GET: ?input[a]=1. Vuota se l'input è vuoto. */
	function query( input ) {
		return ( nodeTree || root.nucleoTree ).query( input );
	}

	/* ---------- Chiamate alle ability ---------- */

	/** Esegue un'ability. Non rifiuta mai: gli errori tornano come { ok: false, error }. */
	function call( name, input ) {
		const readonly = ( root.nucleoAdmin && root.nucleoAdmin.readonly ) || [];
		const req = readonly.includes( name )
			? { path: `/wp-abilities/v1/abilities/${ name }/run${ query( input ) }`, method: 'GET' }
			: { path: `/wp-abilities/v1/abilities/${ name }/run`, method: 'POST', data: { input } };
		return new Promise( ( resolve ) => resolve( root.wp.apiFetch( req ) ) ).catch( ( e ) => ( {
			ok: false,
			error: { code: String( ( e && e.code ) || 'error' ), message: String( ( e && e.message ) || '' ) },
		} ) );
	}

	const api = { scoreTone, glyph, contrast, tabFromSearch, withTab, bytes, query, call, access, pageIcon, blockIconId };

	/* ---------- Componenti (solo con window.wp.element) ---------- */

	const el = root.wp && root.wp.element;
	if ( el ) {
		const { createElement: h, useState, useEffect, Fragment } = el;
		const { __ } = ( root.wp && root.wp.i18n ) || { __: ( text ) => text };

		/** Testata: icona della voce di menu, occhiello, titolo e sottotitolo. icon sostituisce quella del nav. */
		function Page( { title, subtitle, actions, notice, icon, children } ) {
			const name = icon || pageIcon( root.nucleoAdmin && root.nucleoAdmin.page );
			return h( 'div', { className: 'nc-page' },
				h( 'div', { className: 'nc-page__head' },
					h( 'div', { className: 'nc-page__intro' },
						name && h( 'span', { className: 'nc-page__icon', 'aria-hidden': 'true' },
							h( 'svg', { width: 32, height: 32, focusable: 'false' }, h( 'use', { href: '#' + name } ) ) ),
						h( 'div', null,
							h( 'span', { className: 'nc-page__kicker' }, 'Nucleo' ),
							h( 'h1', { className: 'nc-page__title' }, title ),
							subtitle && h( 'p', { className: 'nc-page__subtitle' }, subtitle ) ) ),
					actions && h( 'div', { className: 'nc-page__actions' }, actions ) ),
				notice && h( 'div', { className: 'nc-page__notices' }, notice ),
				children );
		}

		function Card( { title, actions, foot, tone, children } ) {
			return h( 'section', { className: cls( 'nc-card', tone === 'pro' && 'nc-card--pro', tone === 'warn' && 'nc-card--warn' ) },
				( title || actions ) && h( 'div', { className: 'nc-card__head' },
					title && h( 'h2', { className: 'nc-card__title' }, title ),
					actions ),
				h( 'div', { className: 'nc-card__body' }, children ),
				foot && h( 'div', { className: 'nc-card__foot' }, foot ) );
		}

		function Pill( { tone = 'neutral', children } ) {
			const g = glyph( tone );
			return h( 'span', { className: `nc-pill nc-pill--${ tone }` },
				g && h( 'span', { 'aria-hidden': 'true' }, g ),
				children );
		}

		function Badge( { count } ) {
			return h( 'span', { className: 'nc-badge' }, String( count ) );
		}

		function Notice( { tone, title, children, actions, onClose, autoClose } ) {
			// Il ciclo di chiusura automatica dura solo per i messaggi ok con autoClose.
			useEffect( () => {
				if ( tone !== 'ok' || ! autoClose || ! onClose ) {
					return undefined;
				}
				const timer = setTimeout( onClose, 4000 );
				return () => clearTimeout( timer );
			}, [ tone, autoClose, onClose ] );

			const role = tone === 'warn' || tone === 'err' ? 'alert' : 'status';
			return h( 'div', { className: `nc-notice nc-notice--${ tone }`, role },
				h( 'span', { className: 'nc-notice__glyph', 'aria-hidden': 'true' }, glyph( tone ) ),
				h( 'div', { className: 'nc-notice__text' },
					title && h( 'strong', null, title ),
					children ),
				actions && h( 'div', { className: 'nc-notice__actions' }, actions ),
				onClose && h( 'button', { type: 'button', className: 'nc-notice__close', 'aria-label': __( 'Close', 'nucleo' ), onClick: onClose }, '×' ) );
		}

		function Score( { value, label } ) {
			const tone = scoreTone( value );
			const width = Math.max( 0, Math.min( 100, value ) );
			return h( 'div', { className: `nc-score nc-score--${ tone }`, role: 'meter', 'aria-label': label, 'aria-valuemin': 0, 'aria-valuemax': 100, 'aria-valuenow': value },
				h( 'div', { className: 'nc-score__bar' },
					h( 'div', { className: 'nc-score__fill', style: { width: `${ width }%` } } ) ),
				h( 'span', { className: 'nc-score__value' }, String( value ) ) );
		}

		function Progress( { value, label } ) {
			const indeterminate = value === null || value === undefined;
			const props = { className: cls( 'nc-progress', indeterminate && 'nc-progress--indeterminate' ), role: 'progressbar', 'aria-label': label };
			if ( ! indeterminate ) {
				props[ 'aria-valuemin' ] = 0;
				props[ 'aria-valuemax' ] = 100;
				props[ 'aria-valuenow' ] = value;
			}
			return h( 'div', props,
				h( 'div', { className: 'nc-progress__fill', style: indeterminate ? undefined : { width: `${ Math.max( 0, Math.min( 100, value ) ) }%` } } ) );
		}

		function Tabs( { tabs, active, onChange } ) {
			const select = ( id ) => {
				if ( id === active ) {
					return;
				}
				root.history.replaceState( null, '', withTab( root.location.href, id ) );
				if ( onChange ) {
					onChange( id );
				}
			};
			return h( 'div', { className: 'nc-tabs', role: 'tablist' },
				tabs.map( ( t ) => h( 'button', {
					key: t.id,
					type: 'button',
					role: 'tab',
					className: 'nc-tab',
					'aria-selected': String( t.id === active ),
					onClick: () => select( t.id ),
				},
				t.label,
				t.count !== undefined && t.count !== null && h( 'span', { className: 'nc-tab__count' }, String( t.count ) ) ) ) );
		}

		function Table( { columns, rows, empty, loading, rowKey } ) {
			if ( loading ) {
				return h( Skeleton, { lines: 3 } );
			}
			if ( ! rows || rows.length === 0 ) {
				return h( Empty, { text: empty || __( 'Nothing to show yet.', 'nucleo' ) } );
			}
			return h( 'div', { className: 'nc-table-wrap' },
				h( 'table', { className: 'nc-table' },
					h( 'thead', null,
						h( 'tr', null, columns.map( ( c ) => h( 'th', { key: c.key, scope: 'col', style: c.align ? { textAlign: c.align } : undefined }, c.label ) ) ) ),
					h( 'tbody', null, rows.map( ( row, i ) => h( 'tr', { key: rowKey ? rowKey( row, i ) : i },
						columns.map( ( c ) => h( 'td', { key: c.key, style: c.align ? { textAlign: c.align } : undefined },
							c.render ? c.render( row[ c.key ], row ) : row[ c.key ] ) ) ) ) ) ) );
		}

		function Drawer( { title, onClose, foot, children } ) {
			return h( root.wp.components.Modal, { className: 'nc-drawer', overlayClassName: 'nucleo-admin-portal nc-overlay', onRequestClose: onClose },
				h( 'div', { className: 'nc-drawer__head' }, h( 'h2', null, title ) ),
				h( 'div', { className: 'nc-drawer__body' }, children ),
				foot && h( 'div', { className: 'nc-drawer__foot' }, foot ) );
		}

		function Modal( { title, onClose, foot, wide, children } ) {
			return h( root.wp.components.Modal, { className: wide ? 'nc-modal nc-modal--wide' : 'nc-modal', overlayClassName: 'nucleo-admin-portal nc-overlay', onRequestClose: onClose },
				h( 'div', { className: 'nc-modal__head' }, h( 'h2', null, title ) ),
				h( 'div', { className: 'nc-modal__body' }, children ),
				foot && h( 'div', { className: 'nc-modal__foot' }, foot ) );
		}

		/** Conferma con pulsante rosso per ultimo. */
		function Confirm( { title, message, confirmLabel, onConfirm, onCancel } ) {
			return h( root.wp.components.Modal, { className: 'nc-modal', overlayClassName: 'nucleo-admin-portal nc-overlay', role: 'alertdialog', onRequestClose: onCancel },
				h( 'div', { className: 'nc-modal__head' }, h( 'h2', null, title ) ),
				h( 'div', { className: 'nc-modal__body' }, h( 'p', null, message ) ),
				h( 'div', { className: 'nc-modal__foot' },
					h( 'button', { type: 'button', className: 'nc-btn nc-btn--secondary', onClick: onCancel }, __( 'Cancel', 'nucleo' ) ),
					h( 'button', { type: 'button', className: 'nc-btn nc-btn--danger', onClick: onConfirm }, confirmLabel ) ) );
		}

		function Skeleton( { lines } ) {
			const count = lines || 3;
			return h( 'div', { className: 'nc-stack', 'aria-hidden': 'true' },
				Array.from( { length: count }, ( _, i ) => h( 'div', { key: i, className: 'nc-skeleton' } ) ) );
		}

		function Empty( { text, action } ) {
			return h( 'div', { className: 'nc-empty' },
				h( 'p', null, text ),
				action || null );
		}

		function ErrorBox( { message, onRetry } ) {
			return h( 'div', { className: 'nc-error', role: 'alert' },
				h( 'p', null, message ),
				onRetry && h( 'button', { type: 'button', className: 'nc-btn nc-btn--secondary nc-btn--small', onClick: onRetry }, __( 'Try again', 'nucleo' ) ) );
		}

		/** Stato di accesso: readonly aggiunge una nota; ok ed explain mostrano il contenuto. */
		function Gate( { access, reason, title, text, ghost, children } ) {
			if ( access === 'readonly' ) {
				return h( 'div', { className: 'nc-stack' },
					reason && h( ReadonlyNote, null, reason ),
					h( Fragment, null, children ) );
			}
			return h( Fragment, null, children );
		}

		/** Spiegazione di un accesso limitato: testo di aiuto con link facoltativo. */
		function Explain( { reason, href, linkLabel } ) {
			return h( 'p', { className: 'nc-help' },
				reason,
				href && ' ',
				href && h( 'a', { href }, linkLabel || href ) );
		}

		/** Pagina non accessibile: card con la pillola di stato e il ritorno alla panoramica. */
		function Forbidden( { title } ) {
			return h( Card, {
				title: root.wp.i18n.sprintf( __( 'You cannot open %s', 'nucleo' ), title ),
				actions: h( Pill, { tone: 'neutral' }, __( '◌ Access not allowed', 'nucleo' ) ),
			},
			h( 'p', null, __( 'Ask the site administrator if you need it.', 'nucleo' ) ),
			h( 'a', { href: 'admin.php?page=nucleo' }, __( 'Back to Overview', 'nucleo' ) ) );
		}

		/** Nota di sola lettura, in tono informativo. */
		function ReadonlyNote( { children } ) {
			return h( Notice, { tone: 'info' }, children );
		}

		/** Menu di riga: pulsante "⋯" e voci; Escape chiude. */
		/* Menu di riga: pannello fisso sotto il pulsante, così le tabelle che scorrono non lo tagliano. */
		function RowMenu( { label, items } ) {
			const [ pos, setPos ] = useState( null );
			const button = el.useRef( null );
			const open = pos !== null;
			const place = () => {
				const r = button.current.getBoundingClientRect();
				setPos( { top: r.bottom + 4, right: root.innerWidth - r.right } );
			};
			useEffect( () => {
				if ( ! open ) {
					return undefined;
				}
				root.addEventListener( 'scroll', place, true );
				root.addEventListener( 'resize', place );
				return () => {
					root.removeEventListener( 'scroll', place, true );
					root.removeEventListener( 'resize', place );
				};
			}, [ open ] );
			const toggle = () => ( open ? setPos( null ) : place() );
			const setOpen = ( v ) => ! v && setPos( null );
			return h( 'div', { className: 'nc-table__actions' },
			h( 'button', {
				type: 'button',
				className: 'nc-btn nc-btn--icon nc-btn--tertiary nc-btn--small',
				'aria-label': label,
				'aria-haspopup': 'menu',
				'aria-expanded': String( open ),
				ref: button,
				onClick: toggle,
			}, '⋯' ),
			open && h( ContextMenu, { items, anchor: button, at: { top: pos.top, right: pos.right }, onClose: () => setOpen( false ) } ) );
		}

		/** Icona di un blocco dallo sprite stampato nella pagina (Icons::blockSprite). */
		function BlockIcon( { type, size } ) {
			const id = blockIconId( type, ( x ) => !!root.document.getElementById( x ) );
			const px = size || 18;
			return h( 'svg', { className: 'nc-block-icon', width: px, height: px, 'aria-hidden': 'true', focusable: 'false' }, h( 'use', { href: '#' + id } ) );
		}

		/**
		 * Suggerimento al passaggio del mouse o al focus: testo in nc-tooltip, in un portale a destra dell'elemento.
		 * Il testo resta solo visivo: chi usa lo screen reader lo trova già nell'elemento.
		 */
		function HoverTip( { text, children } ) {
			const [ at, setAt ] = useState( null );
			const show = ( e ) => {
				const r = e.currentTarget.getBoundingClientRect();
				setAt( { top: r.top, left: r.right + 8 } );
			};
			const hide = () => setAt( null );
			return h( 'div', { className: 'nc-hovertip', onMouseEnter: show, onMouseLeave: hide, onFocus: show, onBlur: hide },
				children,
				at && text && el.createPortal( h( 'div', { className: 'nucleo-admin-portal' },
					h( 'div', { className: 'nc-tooltip nc-tooltip--floating', 'aria-hidden': 'true', style: at }, text ) ), root.document.body ) );
		}

		/**
		 * Menu verticale sopra la pagina, in un portale nel body: voci { label, onClick | href, danger, disabled }.
		 * at: coordinate fisse (top con left o right). anchor: ref del pulsante che apre il menu, escluso dal clic fuori.
		 * Si chiude con Esc, clic fuori o scelta di una voce.
		 */
		function ContextMenu( { items, at, anchor, onClose } ) {
			const box = el.useRef( null );
			useEffect( () => {
				const away = ( e ) => {
					const inside = ( r ) => r && r.current && r.current.contains( e.target );
					if ( !inside( box ) && !inside( anchor ) ) {
						onClose();
					}
				};
				const key = ( e ) => e.key === 'Escape' && onClose();
				root.document.addEventListener( 'mousedown', away, true );
				root.document.addEventListener( 'keydown', key );
				if ( box.current ) {
					const first = box.current.querySelector( '[role=menuitem]:not([disabled])' );
					if ( first ) {
						first.focus();
					}
				}
				return () => {
					root.document.removeEventListener( 'mousedown', away, true );
					root.document.removeEventListener( 'keydown', key );
				};
			}, [] );
			return el.createPortal( h( 'div', { className: 'nucleo-admin-portal' }, h( 'div', { ref: box, className: 'nc-menu nc-menu--floating', role: 'menu', style: at },
				items.map( ( item, i ) => {
					const props = {
						key: i,
						role: 'menuitem',
						className: cls( 'nc-menu__item', item.danger && 'nc-menu__item--danger' ),
						onClick: () => {
							onClose();
							if ( item.onClick ) {
								item.onClick();
							}
						},
					};
					if ( item.href ) {
						props.href = item.href;
						return h( 'a', props, item.label );
					}
					props.type = 'button';
					props.disabled = !!item.disabled;
					return h( 'button', props, item.label );
				} ) ) ), root.document.body );
		}

		/** Lettura asincrona: { loading } poi data o error. Ricarica con reload(). */
		function useLoad( fn, deps ) {
			const [ tick, setTick ] = useState( 0 );
			const [ state, setState ] = useState( { loading: true } );
			useEffect( () => {
				let live = true;
				setState( { loading: true } );
				Promise.resolve()
					.then( () => fn() )
					.then( ( res ) => {
						if ( ! live ) {
							return;
						}
						if ( res && res.ok === false ) {
							setState( { loading: false, error: { message: ( res.error && res.error.message ) || '' } } );
						} else {
							setState( { loading: false, data: res } );
						}
					} )
					.catch( ( e ) => {
						if ( live ) {
							setState( { loading: false, error: { message: String( ( e && e.message ) || '' ) } } );
						}
					} );
				return () => {
					live = false;
				};
			}, [ ...deps, tick ] );
			return [ state, () => setTick( ( t ) => t + 1 ) ];
		}

		Object.assign( api, {
			Page, Card, Pill, Badge, Notice, Score, Progress, Tabs, Table, Drawer, Modal, Confirm,
			Skeleton, Empty, ErrorBox, RowMenu, ContextMenu, BlockIcon, HoverTip, useLoad,
			Gate, Explain, Forbidden, ReadonlyNote,
		} );
	}

	if ( typeof module === 'object' && module.exports ) {
		module.exports = api;
	} else {
		root.nucleoUI = api;
	}
} )();

/**
 * Schede laterali dell'editor di Nucleo. Nessun build: wp.element, wp.components, wp.i18n.
 * Ogni scheda si registra in window.nucleoPanels e viene mostrata da editor.js.
 * I dati arrivano da props.call (ability nucleo/* via REST).
 */
( function () {
	'use strict';

	const { createElement: h, useState, useEffect } = wp.element;
	const { Button, Notice, Spinner, TextControl } = wp.components;
	const { __ } = wp.i18n;

	window.nucleoPanels = window.nucleoPanels || [];

	/* ---------- Utilità ---------- */

	/** Esito di una chiamata: fallita se manca, se ok è false o se contiene error. */
	const failed = ( res ) => ! res || res.ok === false || !! res.error;
	const errText = ( res ) => ( res && res.error && res.error.message ) || __( 'Operation failed.', 'nucleo' );
	/** Ability assente nell'edizione Free: il codice di errore indica una rotta o un'ability non trovata. */
	const isMissing = ( res ) => !! res && !! res.error && /not_found|no_route|invalid_ability/.test( String( res.error.code || '' ) );
	/** Testo leggibile di un valore qualunque. */
	const txt = ( v ) => {
		if ( v === null || v === undefined ) {
			return '';
		}
		return typeof v === 'object' ? JSON.stringify( v ) : String( v );
	};

	/**
	 * Carica i dati al montaggio (e quando cambiano le deps).
	 * Restituisce [ { loading, res }, setSt ].
	 */
	function useLoad( load, deps ) {
		const [ st, setSt ] = useState( { loading: true, res: null } );
		useEffect( () => {
			let alive = true;
			setSt( { loading: true, res: null } );
			load().then( ( res ) => {
				if ( alive ) {
					setSt( { loading: false, res } );
				}
			} );
			return () => {
				alive = false;
			};
		}, deps );
		return [ st, setSt ];
	}

	/** Mostra il caricamento, poi l'errore restituito, poi il contenuto. */
	function Body( { st, children } ) {
		if ( st.loading ) {
			return h( Spinner );
		}
		if ( failed( st.res ) ) {
			return h( Notice, { status: 'error', isDismissible: false }, errText( st.res ) );
		}
		return children;
	}

	/* ---------- Layout ---------- */

	const AREA_LABELS = () => ( {
		header: __( 'Header', 'nucleo' ),
		footer: __( 'Footer', 'nucleo' ),
		single: __( 'Single', 'nucleo' ),
		archive: __( 'Archive', 'nucleo' ),
		popup: __( 'Popup', 'nucleo' ),
		'before-content': __( 'Before content', 'nucleo' ),
		'after-content': __( 'After content', 'nucleo' ),
	} );

	function ExplainView( { res } ) {
		const labels = AREA_LABELS();
		const rows = Object.keys( res || {} ).map( ( area ) => {
			const r = res[ area ];
			return h( 'tr', { key: area },
				h( 'td', null, labels[ area ] || area ),
				h( 'td', null, r && r.layout ? txt( r.layout ) : __( 'none', 'nucleo' ) ),
				h( 'td', null, r && r.matched ? txt( r.matched ) : ( r && r.excluded
					? __( 'excluded:', 'nucleo' ) + ' ' + r.excluded.map( ( e ) => e.layout + ' (' + e.by + ')' ).join( ', ' )
					: __( 'no match', 'nucleo' ) ) ) );
		} );
		return h( 'table', { className: 'nc-table nc-table--compact' },
			h( 'thead', null, h( 'tr', null,
				h( 'th', null, __( 'Area', 'nucleo' ) ),
				h( 'th', null, __( 'Layout', 'nucleo' ) ),
				h( 'th', null, __( 'Chosen by', 'nucleo' ) ) ) ),
			h( 'tbody', null, rows ) );
	}

	function ListView( { res } ) {
		const layouts = ( res && res.layouts ) || [];
		if ( layouts.length === 0 ) {
			return h( 'p', null, __( 'No layouts.', 'nucleo' ) );
		}
		return h( 'ul', null, layouts.map( ( l ) => h( 'li', { key: l.id },
			h( 'strong', null, l.title || String( l.id ) ),
			' · ',
			l.area,
			' · ',
			h( 'a', { href: ( window.nucleoEditor || {} ).editorBase + encodeURIComponent( l.id ) },
				__( 'Edit', 'nucleo' ) ) ) ) );
	}

	function LayoutPanel( props ) {
		// Senza permalink (window.nucleoEditor.permalink) la spiegazione non si può chiedere: resta solo l'elenco.
		const url = ( window.nucleoEditor || {} ).permalink || '';
		const [ explain ] = useLoad(
			() => ( url ? props.call( 'nucleo/layout-explain', { url } ) : Promise.resolve( { ok: true, skipped: true } ) ),
			[ url ]
		);
		const [ list ] = useLoad( () => props.call( 'nucleo/layout-list', {} ), [] );
		return h( 'div', { className: 'nucleo-panel nucleo-panel-layout' },
			url && h( 'h3', null, __( 'Layout of this page', 'nucleo' ) ),
			url && h( Body, { st: explain }, h( ExplainView, { res: explain.res } ) ),
			h( 'h3', null, __( 'All layouts', 'nucleo' ) ),
			h( Body, { st: list }, h( ListView, { res: list.res } ) ) );
	}

	/* ---------- Design system ---------- */

	function DesignPanel( props ) {
		const [ st, setSt ] = useLoad( () => props.call( 'nucleo/tokens-get', {} ), [] );
		const [ edits, setEdits ] = useState( {} ); // { gruppo: { chiave: valore } }, solo i campi toccati
		const [ saving, setSaving ] = useState( false );
		const [ notice, setNotice ] = useState( null );

		if ( st.loading || failed( st.res ) ) {
			return h( Body, { st }, null );
		}

		const tokens = st.res.tokens || {};
		const colors = tokens.color || {};
		const valueOf = ( group, key ) => {
			const e = edits[ group ] && edits[ group ][ key ];
			return e !== undefined ? e : txt( tokens[ group ][ key ] );
		};

		const setColor = ( key, value ) => {
			setEdits( ( prev ) => ( { ...prev, color: { ...( prev.color || {} ), [ key ]: value } } ) );
		};

		const save = () => {
			// Solo i token il cui valore è cambiato rispetto a quello attuale.
			const changed = {};
			Object.keys( edits ).forEach( ( group ) => {
				Object.keys( edits[ group ] ).forEach( ( key ) => {
					if ( edits[ group ][ key ] !== txt( tokens[ group ] && tokens[ group ][ key ] ) ) {
						changed[ group ] = changed[ group ] || {};
						changed[ group ][ key ] = edits[ group ][ key ];
					}
				} );
			} );
			if ( Object.keys( changed ).length === 0 ) {
				setNotice( { status: 'info', text: __( 'No changes to save.', 'nucleo' ) } );
				return;
			}
			setSaving( true );
			setNotice( null );
			props.call( 'nucleo/tokens-set', { tokens: changed } ).then( ( r ) => {
				setSaving( false );
				if ( failed( r ) ) {
					setNotice( { status: 'error', text: errText( r ) } );
					return;
				}
				setEdits( {} );
				setSt( { loading: false, res: r } );
				setNotice( { status: 'success', text: __( 'Tokens saved.', 'nucleo' ) } );
			} );
		};

		const colorRows = Object.keys( colors ).map( ( key ) => {
			const value = valueOf( 'color', key );
			return h( 'div', { key, className: 'nucleo-panel-color', style: { display: 'flex', alignItems: 'flex-end', gap: '8px', marginBottom: '8px' } },
				h( 'span', { style: { display: 'inline-block', width: '32px', height: '32px', border: '1px solid #ccc', background: value } } ),
				h( TextControl, {
					label: key,
					value,
					onChange: ( v ) => setColor( key, v ),
				} ) );
		} );

		const tableOf = ( group, title ) => {
			const entries = Object.keys( tokens[ group ] || {} );
			if ( entries.length === 0 ) {
				return null;
			}
			return h( 'div', null,
				h( 'h4', null, title ),
				h( 'table', { className: 'nc-table nc-table--compact' },
					h( 'tbody', null, entries.map( ( key ) => h( 'tr', { key },
						h( 'td', null, key ),
						h( 'td', null, txt( tokens[ group ][ key ] ) ) ) ) ) ) );
		};

		return h( 'div', { className: 'nucleo-panel nucleo-panel-design' },
			h( 'h3', null, __( 'Colors', 'nucleo' ) ),
			colorRows,
			tableOf( 'space', __( 'Spacing', 'nucleo' ) ),
			tableOf( 'text', __( 'Texts', 'nucleo' ) ),
			notice && h( Notice, { status: notice.status, isDismissible: false }, notice.text ),
			h( Button, { variant: 'primary', isBusy: saving, disabled: saving, onClick: save },
				__( 'Save', 'nucleo' ) ) );
	}

	/* ---------- Stato ---------- */

	function StatusPanel( props ) {
		const [ st ] = useLoad( () => props.call( 'nucleo/status', {} ), [] );
		if ( st.loading || failed( st.res ) ) {
			return h( Body, { st }, null );
		}
		const r = st.res;
		const conflicts = Array.isArray( r.conflicts ) ? r.conflicts : [];
		const info = [
			[ __( 'Nucleo version', 'nucleo' ), r.nucleoVersion ],
			[ 'PHP', r.php ],
			[ 'WordPress', r.wordpress ],
			[ __( 'Store', 'nucleo' ), r.store ],
			[ __( 'Documents', 'nucleo' ), r.documentCount ],
		];
		return h( 'div', { className: 'nucleo-panel nucleo-panel-status' },
			h( 'table', { className: 'nc-table nc-table--compact' },
				h( 'tbody', null, info.map( ( [ label, value ] ) => h( 'tr', { key: label },
					h( 'td', null, label ),
					h( 'td', null, txt( value ) ) ) ) ) ),
			conflicts.length > 0 && h( 'div', null,
				h( 'h3', null, __( 'Conflicts', 'nucleo' ) ),
				h( 'ul', null, conflicts.map( ( c, i ) => h( 'li', { key: i },
					h( 'strong', null, txt( c.plugin ) ),
					' · ',
					txt( c.issue ),
					' · ',
					txt( c.fix ) ) ) ) ) );
	}

	/* ---------- Asset ---------- */

	function AssetsPanel( props ) {
		const [ st ] = useLoad( () => props.call( 'nucleo/assets-report', {} ), [] );
		const [ busy, setBusy ] = useState( null );
		const [ notice, setNotice ] = useState( null );

		if ( ! st.loading && isMissing( st.res ) ) {
			return h( Notice, { status: 'info', isDismissible: false },
				__( 'The asset report is not available in the Free edition.', 'nucleo' ) );
		}
		if ( st.loading || failed( st.res ) ) {
			return h( Body, { st }, null );
		}

		const r = st.res;
		const assets = r.assets || {};
		const templates = r.templates || {};
		const suggestions = r.suggestions || [];

		// Regole attive dal report: la scrittura le sostituisce tutte, quindi si invia l'elenco completo.
		const current = Array.isArray( r.rules ) ? r.rules : [];

		// Applica il suggerimento: la regola è il suggerimento senza il campo descrittivo reason.
		const apply = ( s, i ) => {
			const { reason, ...rule } = s;
			const key = JSON.stringify( rule );
			if ( current.some( ( x ) => JSON.stringify( x ) === key ) ) {
				setNotice( { status: 'info', text: __( 'Rule already present.', 'nucleo' ) } );
				return;
			}
			setBusy( i );
			setNotice( null );
			props.call( 'nucleo/assets-rules-set', { rules: [ ...current, rule ] } ).then( ( res ) => {
				setBusy( null );
				setNotice( failed( res )
					? { status: 'error', text: errText( res ) }
					: { status: 'success', text: __( 'Rule applied.', 'nucleo' ) } );
			} );
		};

		const templateRows = Object.keys( templates ).map( ( name ) => {
			const handles = Array.isArray( templates[ name ] ) ? templates[ name ] : [];
			return h( 'div', { key: name },
				h( 'h4', null, name ),
				h( 'ul', null, handles.map( ( handle ) => {
					const a = assets[ handle ];
					const size = a && typeof a.size === 'number' ? ( a.size / 1024 ).toFixed( 1 ) + ' KB' : '';
					return h( 'li', { key: handle }, handle, ' ', size );
				} ) ) );
		} );

		// I suggerimenti "review" non sono regole applicabili: si mostrano solo come testo.
		const suggestionRows = suggestions.map( ( s, i ) => h( 'li', { key: i },
			h( 'strong', null, s.action + ' · ' + s.handle ),
			' ', txt( s.reason ), ' ',
			s.action !== 'review' && h( Button, { variant: 'secondary', isBusy: busy === i, disabled: busy !== null, onClick: () => apply( s, i ) },
				__( 'Apply', 'nucleo' ) ) ) );

		return h( 'div', { className: 'nucleo-panel nucleo-panel-assets' },
			h( 'h3', null, __( 'Template', 'nucleo' ) ),
			templateRows.length > 0 ? templateRows : h( 'p', null, __( 'No audited templates.', 'nucleo' ) ),
			h( 'h3', null, __( 'Suggestions', 'nucleo' ) ),
			suggestionRows.length > 0 ? h( 'ul', null, suggestionRows ) : h( 'p', null, __( 'No suggestions.', 'nucleo' ) ),
			notice && h( Notice, { status: notice.status, isDismissible: false }, notice.text ) );
	}

	/* ---------- Registrazione ---------- */

	window.nucleoPanels.push(
		{ id: 'layout', title: __( 'Layout', 'nucleo' ), render: ( props ) => h( LayoutPanel, props ) },
		{ id: 'design', title: __( 'Design system', 'nucleo' ), render: ( props ) => h( DesignPanel, props ) },
		{ id: 'stato', title: __( 'Status', 'nucleo' ), render: ( props ) => h( StatusPanel, props ) },
		{ id: 'asset', title: __( 'Asset', 'nucleo' ), render: ( props ) => h( AssetsPanel, props ) }
	);
} )();

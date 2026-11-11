/**
 * Pagina "Qualità e SEO" (slug nucleo-quality), specifica 4.7.
 * Nessun build: wp.element, wp.i18n. Usa window.nucleoUI per componenti e chiamate.
 * Nei test (node --test) esporta gli helper puri; nel browser registra window.nucleoPages['nucleo-quality'].
 */
( function () {
	'use strict';

	const root = typeof window !== 'undefined' ? window : {};
	// Nel browser wp.i18n traduce; in node (test) è identità.
	const { __ } = ( root.wp && root.wp.i18n ) || { __: ( text ) => text };

	/** Le 19 regole della tabella in 4.7. Gravità tipica dalla specifica; testi come in assets/editor/labels.js. */
	const RULES = [
		{ id: 'seo.h1.missing', severity: 'error', label: __( 'Missing H1 heading', 'nucleo' ) },
		{ id: 'seo.h1.multiple', severity: 'error', label: __( 'Multiple H1 headings', 'nucleo' ) },
		{ id: 'seo.h1.duplicate', severity: 'warning', label: __( 'Duplicate H1 heading', 'nucleo' ) },
		{ id: 'seo.heading.skip', severity: 'warning', label: __( 'Heading level skipped', 'nucleo' ) },
		{ id: 'seo.heading.empty', severity: 'error', label: __( 'Empty heading', 'nucleo' ) },
		{ id: 'seo.title.length', severity: 'warning', label: __( 'Title length not adequate', 'nucleo' ) },
		{ id: 'seo.title.duplicate', severity: 'warning', label: __( 'Duplicate title', 'nucleo' ) },
		{ id: 'seo.description.missing', severity: 'warning', label: __( 'Missing description', 'nucleo' ) },
		{ id: 'seo.description.length', severity: 'warning', label: __( 'Description length not adequate', 'nucleo' ) },
		{ id: 'seo.image.alt.missing', severity: 'error', label: __( 'Image without alt text', 'nucleo' ) },
		{ id: 'seo.links.internal.none', severity: 'warning', label: __( 'No internal links', 'nucleo' ) },
		{ id: 'seo.content.thin', severity: 'warning', label: __( 'Content too short', 'nucleo' ) },
		{ id: 'seo.noindex', severity: 'info', label: __( 'Page excluded from search engines', 'nucleo' ) },
		{ id: 'seo.canonical.external', severity: 'warning', label: __( 'Canonical pointing to another site', 'nucleo' ) },
		{ id: 'seo.hreflang.missing', severity: 'warning', label: __( 'Missing hreflang', 'nucleo' ) },
		{ id: 'a11y.contrast', severity: 'error', label: __( 'Insufficient contrast', 'nucleo' ) },
		{ id: 'a11y.landmark.missing', severity: 'warning', label: __( 'Missing landmark', 'nucleo' ) },
		{ id: 'a11y.link.empty', severity: 'error', label: __( 'Link without text', 'nucleo' ) },
		{ id: 'perf.lcp.lazy', severity: 'warning', label: __( 'Main image loaded late', 'nucleo' ) },
	];

	const TABS = [ 'issues', 'pages', 'overrides', 'rules' ];

	/**
	 * Scansione a lotti: chiama quality-scan da pagina 1 finché next è null.
	 * Un errore ferma la scansione e torna in `error` con il messaggio; i dati già letti restano.
	 */
	async function scanAll( call, onProgress ) {
		const res = { site: [], pages: [], error: null };
		let next = 1;
		while ( next ) {
			const out = await call( 'nucleo/quality-scan', { page: next } );
			if ( out && out.ok === false ) {
				res.error = ( out.error && out.error.message ) || '';
				break;
			}
			res.site.push( ...( out.site || [] ) );
			res.pages.push( ...( out.pages || [] ) );
			if ( onProgress ) {
				onProgress( next );
			}
			next = out.next;
		}
		return res;
	}

	/** Numero di errori bloccanti: somma dei posts dei problemi di gravità error. */
	function blockingCount( site ) {
		return site
			.filter( ( s ) => s.severity === 'error' )
			.reduce( ( n, s ) => n + ( s.posts || [] ).length, 0 );
	}

	/** Etichetta del titolo di un documento, o #id se non è nell'elenco. */
	function titleOf( labels, id ) {
		return labels[ id ] ? labels[ id ].title : `#${ id }`;
	}

	/** Problemi del sito in cui compare la pagina: il conteggio si ricava da site. */
	function issueCount( site, id ) {
		return site.filter( ( s ) => ( s.posts || [] ).includes( id ) ).length;
	}

	/**
	 * Regole della scheda: quelle che il server restituisce, con la sua gravità; l'etichetta è quella della tabella, o l'id.
	 * Senza res.rules resta la lista statica.
	 */
	function ruleRows( rules ) {
		if ( ! Array.isArray( rules ) ) {
			return RULES;
		}
		return rules.map( ( r ) => {
			const known = RULES.find( ( k ) => k.id === r.id );
			return { id: r.id, severity: r.severity, label: known ? known.label : r.id };
		} );
	}

	/** Righe della scheda Pagine: punteggi SEO, accessibilità e performance dal server; quelli assenti restano vuoti. */
	function pageRows( pages, labels, site ) {
		return pages.map( ( p ) => ( {
			id: p.postId,
			title: titleOf( labels, p.postId ),
			score: p.score,
			accessibility: p.a11y,
			performance: p.perf,
			issues: issueCount( site, p.postId ),
		} ) );
	}

	/** Voce di navigazione della pagina, o undefined se non c'è (in quel caso nulla è limitato). */
	function navEntry( nav, slug ) {
		return Array.isArray( nav ) ? nav.find( ( n ) => n && n.slug === slug ) : undefined;
	}

	/** Schede mostrate: con voce di navigazione solo quelle presenti in entry.tabs; senza voce, tutte. */
	function visibleTabs( tabIds, entry ) {
		if ( ! entry ) {
			return tabIds.slice();
		}
		const tabs = entry.tabs || {};
		return tabIds.filter( ( id ) => Object.prototype.hasOwnProperty.call( tabs, id ) );
	}

	/** Chi ha accesso in sola lettura non avvia scansioni né altre scritture. */
	function canRunScan( level ) {
		return level !== 'readonly';
	}

	/** Sito mai scansionato (env.state.scanned false): niente avvio automatico, si mostra la prima scansione. */
	function neverScanned( env ) {
		return Boolean( env && env.state && env.state.scanned === false );
	}

	/** Vero se la schermata deve mostrare "No scan yet": mai scansionato, nessun dato e nessuna scansione in corso. */
	function showFirstScanEmpty( env, hasData, running ) {
		return neverScanned( env ) && ! hasData && ! running;
	}

	const api = { scanAll, blockingCount, RULES, navEntry, visibleTabs, canRunScan, neverScanned, showFirstScanEmpty, ruleRows, pageRows };

	const el = root.wp && root.wp.element;
	if ( el ) {
		const { createElement: h, useState, useEffect, useRef } = el;
		const UI = root.nucleoUI;
		const sprintf = ( s, n ) => root.wp.i18n.sprintf( s, n );

		const SEVERITY = {
			error: { tone: 'err', label: __( 'Error', 'nucleo' ) },
			warning: { tone: 'warn', label: __( 'Warning', 'nucleo' ) },
			info: { tone: 'info', label: __( 'Info', 'nucleo' ) },
		};

		function severityPill( severity ) {
			const s = SEVERITY[ severity ] || SEVERITY.info;
			return h( UI.Pill, { tone: s.tone }, s.label );
		}

		/** Punteggio 0-100 come pill (glifo e tono da scoreTone); senza valore, trattino. */
		function scorePill( value ) {
			if ( typeof value !== 'number' ) {
				return '—';
			}
			return h( UI.Pill, { tone: UI.scoreTone( value ) }, String( value ) );
		}

		/** Tutte le voci di doc-list per un tipo: le pagine di risultati una dopo l'altra, finché arrivano al totale. */
		async function listAll( postType ) {
			const items = [];
			for ( let page = 1; ; page++ ) {
				const res = await UI.call( 'nucleo/doc-list', { postType, page } );
				const batch = ( res && res.items ) || [];
				items.push( ...batch );
				if ( batch.length === 0 || items.length >= ( ( res && res.total ) || 0 ) ) return items;
			}
		}

		/** Etichetta dei titoli: doc-list di pagine e articoli unite per id. */
		async function loadLabels() {
			const lists = await Promise.all( [ listAll( 'page' ), listAll( 'post' ) ] );
			const map = {};
			lists.flat().forEach( ( item ) => {
				map[ item.id ] = { title: item.title };
			} );
			return map;
		}

		/** Indirizzo dell'editor di un documento: urls.editor termina con '&post=', quindi basta aggiungere l'id. */
		function editorHref( id ) {
			const urls = ( root.nucleoAdmin && root.nucleoAdmin.urls ) || {};
			return urls.editor ? `${ urls.editor }${ id }` : '#';
		}

		/** Link all'editor con il titolo del documento. */
		function editorLink( labels, id ) {
			return h( 'a', { href: editorHref( id ) }, titleOf( labels, id ) );
		}

		/** Dettaglio di una pagina: quality-check e i suoi issues. */
		function IssuesDrawer( { postId, title, onClose } ) {
			const [ state, reload ] = UI.useLoad( () => UI.call( 'nucleo/quality-check', { postId } ), [ postId ] );
			let content;
			if ( state.loading ) {
				content = h( UI.Skeleton, { lines: 3 } );
			} else if ( state.error ) {
				content = h( UI.ErrorBox, { message: state.error.message, onRetry: reload } );
			} else {
				const issues = ( state.data && state.data.quality && state.data.quality.issues ) || [];
				content = issues.length === 0
					? h( UI.Empty, { text: __( 'No issues on this page.', 'nucleo' ) } )
					: h( 'ul', { className: 'nc-stack' }, issues.map( ( issue, i ) => h( 'li', { key: i },
						severityPill( issue.severity ), ' ',
						h( 'code', null, issue.rule ), ' ',
						issue.message,
						issue.nodes && issue.nodes.length > 0 && h( 'small', null, ` (${ issue.nodes.join( ', ' ) })` ) ) ) );
			}
			return h( UI.Drawer, { title, onClose }, content );
		}

		function Quality() {
			const nucleoAdmin = root.nucleoAdmin || {};
			const env = nucleoAdmin.env;
			const readonly = ! canRunScan( UI.access( 'nucleo-quality', null ).access );
			const shownTabs = visibleTabs( TABS, navEntry( nucleoAdmin.nav, 'nucleo-quality' ) );
			const [ asked, setTab ] = useState( () => UI.tabFromSearch( root.location.search, TABS, 'issues' ) );
			const tab = shownTabs.includes( asked ) ? asked : shownTabs[ 0 ];
			const [ scan, setScan ] = useState( { running: false, batch: 0, data: null, error: null } );
			const [ labelState ] = UI.useLoad( loadLabels, [] );
			const [ selected, setSelected ] = useState( null );
			const alive = useRef( true );

			const run = () => {
				setScan( { running: true, batch: 0, data: null, error: null } );
				scanAll( UI.call, ( n ) => {
					if ( alive.current ) {
						setScan( ( s ) => ( { ...s, batch: n } ) );
					}
				} ).then( ( res ) => {
					if ( alive.current ) {
						setScan( ( s ) => ( { ...s, running: false, data: res, error: res.error } ) );
					}
				}, ( e ) => {
					if ( alive.current ) {
						setScan( ( s ) => ( { ...s, running: false, error: String( ( e && e.message ) || '' ) } ) );
					}
				} );
			};

			useEffect( () => {
				alive.current = true;
				if ( ! neverScanned( env ) ) {
					run();
				}
				return () => {
					alive.current = false;
				};
			}, [] );

			const labels = ( labelState && labelState.data ) || {};
			const site = scan.data ? scan.data.site : [];
			// Le voci di pages portano solo i punteggi che il server restituisce: gli assenti mostrano '—'.
			const rows = pageRows( scan.data ? scan.data.pages : [], labels, site );
			const blocking = blockingCount( site );
			const announce = scan.data && ! scan.running
				? sprintf( __( 'Scan complete: %d blocking errors.', 'nucleo' ), blocking )
				: '';
			const firstScan = showFirstScanEmpty( env, Boolean( scan.data ), scan.running );

			const tabs = [
				{ id: 'issues', label: __( 'Site issues', 'nucleo' ), count: scan.data ? site.length : null },
				{ id: 'pages', label: __( 'Pages', 'nucleo' ) },
				{ id: 'overrides', label: __( 'Overrides', 'nucleo' ) },
				{ id: 'rules', label: __( 'Rules', 'nucleo' ) },
			].filter( ( t ) => shownTabs.includes( t.id ) );

			// Stato della scansione: in corso con lotto, oppure ultima scansione con le pagine lette.
			let scanText = '';
			if ( scan.running ) {
				scanText = scan.batch > 0 ? sprintf( __( 'batch %d', 'nucleo' ), scan.batch ) : __( 'Starting scan.', 'nucleo' );
			}
			const scanCard = ! firstScan && ( scan.running || Boolean( scan.data ) ) && h( UI.Card, null,
				h( 'div', { className: 'nc-stack' },
					h( 'div', { className: 'nc-row', style: { justifyContent: 'space-between' } },
						h( 'span', null,
							h( 'strong', null, scan.running ? __( 'Scan in progress', 'nucleo' ) : __( 'Last scan', 'nucleo' ) ),
							scanText && ` · ${ scanText }` ),
						! scan.running && h( 'span', { className: 'nc-help' }, sprintf( __( '%d pages checked', 'nucleo' ), rows.length ) ) ),
					h( UI.Progress, { value: scan.running ? null : 100, label: __( 'Scan progress', 'nucleo' ) } ),
					h( 'div', { 'aria-live': 'polite' }, announce ) ) );

			let body = null;
			if ( firstScan ) {
				body = h( UI.Empty, {
					text: h( 'span', null,
						h( 'strong', null, __( 'No scan yet', 'nucleo' ) ), ' ',
						__( 'The first scan checks titles, alt texts, links and contrast of all pages. It takes about a minute every 100 pages.', 'nucleo' ) ),
					action: readonly ? null : h( 'button', {
						type: 'button',
						className: 'nc-btn nc-btn--primary',
						onClick: run,
					}, __( 'Start the first scan', 'nucleo' ) ),
				} );
			} else if ( tab === 'issues' ) {
				body = h( UI.Table, {
					columns: [
						{ key: 'severity', label: __( 'Severity', 'nucleo' ), render: severityPill },
						{ key: 'message', label: __( 'Message', 'nucleo' ) },
						{ key: 'posts', label: __( 'Pages involved', 'nucleo' ), render: ( posts ) => ( posts || [] ).map( ( id ) => h( 'span', { key: id, style: { marginRight: '12px' } }, editorLink( labels, id ) ) ) },
						{ key: 'actions', label: '', render: ( value, row ) => {
							const posts = row.posts || [];
							return posts.length === 0 ? null : h( 'span', null,
								h( 'a', { href: editorHref( posts[ 0 ] ) }, __( 'Open in editor', 'nucleo' ) ),
								posts.length > 1 && ` +${ posts.length - 1 }` );
						} },
					],
					rows: site,
					rowKey: ( r, i ) => `${ r.rule }-${ i }`,
					loading: scan.running && ! scan.data,
					empty: __( 'No site issues found.', 'nucleo' ),
				} );
			} else if ( tab === 'pages' ) {
				body = h( UI.Table, {
					columns: [
						{ key: 'title', label: __( 'Title', 'nucleo' ), render: ( value, row ) => h( 'button', {
							type: 'button',
							className: 'nc-btn nc-btn--tertiary',
							onClick: () => setSelected( row.id ),
						}, value ) },
						{ key: 'score', label: __( 'SEO', 'nucleo' ), render: scorePill },
						{ key: 'accessibility', label: __( 'Accessibility', 'nucleo' ), render: scorePill },
						{ key: 'performance', label: __( 'Performance', 'nucleo' ), render: scorePill },
						{ key: 'issues', label: __( 'Issues', 'nucleo' ), render: ( value ) => String( value ) },
						{ key: 'edit', label: '', render: ( value, row ) => h( 'a', { href: editorHref( row.id ) }, __( 'Open in editor', 'nucleo' ) ) },
					],
					rows,
					rowKey: ( r ) => r.id,
					loading: scan.running && ! scan.data,
					empty: __( 'No pages scanned yet.', 'nucleo' ),
				} );
			} else if ( tab === 'overrides' ) {
				body = h( UI.Empty, { text: __( 'Not available yet', 'nucleo' ) } );
			} else if ( tab === 'rules' ) {
				// Regole e gravità dal server; la lista statica solo se la risposta non le contiene.
				body = h( UI.Table, {
					columns: [
						{ key: 'label', label: __( 'Rule', 'nucleo' ) },
						{ key: 'id', label: __( 'Code', 'nucleo' ), render: ( value ) => h( 'code', null, value ) },
						{ key: 'severity', label: __( 'Typical severity', 'nucleo' ), render: severityPill },
					],
					rows: ruleRows( scan.data ? scan.data.rules : undefined ),
					rowKey: ( r ) => r.id,
					empty: __( 'No rules.', 'nucleo' ),
				} );
			}

			return h( UI.Page, {
				title: __( 'Quality and SEO', 'nucleo' ),
				subtitle: __( 'Checks the quality of the whole site. Pages with blocking errors cannot be published without a reason.', 'nucleo' ),
				actions: readonly ? null : h( 'button', {
					type: 'button',
					className: 'nc-btn nc-btn--primary',
					disabled: scan.running,
					onClick: run,
				}, __( 'Start a new scan', 'nucleo' ) ),
				notice: scan.error ? h( UI.ErrorBox, { message: scan.error, onRetry: run } ) : null,
			},
			scanCard,
			readonly && h( UI.ReadonlyNote, null, __( 'You see the scores of your documents. An administrator starts the site scan.', 'nucleo' ) ),
			h( UI.Tabs, { tabs, active: tab, onChange: setTab } ),
			body,
			selected !== null && h( IssuesDrawer, {
				postId: selected,
				title: titleOf( labels, selected ),
				onClose: () => setSelected( null ),
			} ) );
		}

		root.nucleoPages = root.nucleoPages || {};
		root.nucleoPages[ 'nucleo-quality' ] = Quality;
	}

	if ( typeof module === 'object' && module.exports ) {
		module.exports = api;
	}
} )();

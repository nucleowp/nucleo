/**
 * Panoramica (nucleo): stato del sito, qualità, ultime modifiche, azioni rapide e primo avvio.
 * Nei test (node --test) si esportano i helper puri; nel browser è window.nucleoPages['nucleo'].
 */
( function () {
	'use strict';

	const root = typeof window !== 'undefined' ? window : {};

	/* ---------- Funzioni pure ---------- */

	/** Tono della pillola per gravità: error → err, warning → warn, info → info, altro → neutral. */
	function severityTone( severity ) {
		switch ( severity ) {
			case 'error':
				return 'err';
			case 'warning':
				return 'warn';
			case 'info':
				return 'info';
			default:
				return 'neutral';
		}
	}

	/** Problemi di sito raggruppati per regola, con il numero di pagine coinvolte; i più frequenti per primi. */
	/** useLoad restituisce [state, reload]: qui serve { state, reload }. */
	function asObject( pair ) {
		return { state: pair[ 0 ], reload: pair[ 1 ] };
	}

	function topIssues( site, n ) {
		const byRule = new Map();
		( site || [] ).forEach( ( issue ) => {
			const count = Array.isArray( issue.posts ) ? issue.posts.length : 0;
			const prev = byRule.get( issue.rule );
			if ( prev ) {
				prev.count += count;
			} else {
				byRule.set( issue.rule, { rule: issue.rule, severity: issue.severity, message: issue.message, count } );
			}
		} );
		return [ ...byRule.values() ]
			.sort( ( a, b ) => b.count - a.count )
			.slice( 0, n );
	}

	/** Media arrotondata dei punteggi SEO delle pagine; null se non ce ne sono. */
	function avgScore( pages ) {
		const scores = ( pages || [] )
			.map( ( p ) => p.score )
			.filter( ( s ) => typeof s === 'number' && Number.isFinite( s ) );
		if ( scores.length === 0 ) {
			return null;
		}
		return Math.round( scores.reduce( ( a, b ) => a + b, 0 ) / scores.length );
	}

	const helpers = { severityTone, topIssues, avgScore };

	if ( typeof module === 'object' && module.exports ) {
		module.exports = helpers;
		return;
	}

	/* ---------- Componente (solo nel browser, con wp.element) ---------- */

	const el = root.wp && root.wp.element;
	if ( ! el ) {
		return;
	}
	const { createElement: h, useState, Fragment } = el;
	const { __ } = ( root.wp && root.wp.i18n ) || { __: ( text ) => text };

	/** URL di una pagina Nucleo dal menu (nucleoAdmin.pages), con ripiego sul parametro page. */
	function pageUrl( admin, slug ) {
		const short = slug.replace( /^nucleo-/, '' );
		const found = ( admin.pages || [] ).find( ( p ) => p.slug === slug || p.slug === short );
		return found && found.url ? found.url : `admin.php?page=${ slug }`;
	}

	/** Link all'editor di un documento: nucleoAdmin.urls.editor seguito dall'id. */
	function editorUrl( admin, id ) {
		return admin.urls && admin.urls.editor ? `${ admin.urls.editor }${ id }` : '#';
	}

	/** Data come "24 ott · 16:47"; se non è una data valida torna il valore grezzo. */
	function formatTime( value ) {
		// audit-list usa secondi Unix.
		const d = new Date( typeof value === 'number' ? value * 1000 : value );
		if ( Number.isNaN( d.getTime() ) ) {
			return String( value ?? '' );
		}
		const day = d.toLocaleDateString( undefined, { day: 'numeric', month: 'short' } );
		const time = d.toLocaleTimeString( undefined, { hour: '2-digit', minute: '2-digit' } );
		return `${ day } · ${ time }`;
	}

	/** Nome leggibile di un'ability senza il prefisso nucleo/: trattini come spazi, prima lettera maiuscola. */
	function humanizeAbility( ability ) {
		const text = String( ability || '' ).replace( /^nucleo\//, '' ).replace( /-/g, ' ' );
		return text.charAt( 0 ).toUpperCase() + text.slice( 1 );
	}

	/** Valore per la card Stato: booleani come attiva/non attiva, vuoto come trattino. */
	function show( value ) {
		if ( typeof value === 'boolean' ) {
			return value ? __( 'active', 'nucleo' ) : __( 'inactive', 'nucleo' );
		}
		return value === null || value === undefined ? '—' : String( value );
	}

	/** Corpo di una card: scheletro, errore con Riprova, poi il contenuto. */
	function cardBody( ui, state, retry, render ) {
		if ( state.loading ) {
			return h( ui.Skeleton, { lines: 3 } );
		}
		if ( state.error ) {
			return h( ui.ErrorBox, {
				message: state.error.message || __( 'This card could not be loaded.', 'nucleo' ),
				onRetry: retry,
			} );
		}
		return render();
	}

	/** Testo di un errore di azione, con ripiego generico. */
	function failText( res ) {
		return ( res && res.error && res.error.message ) || __( 'The action failed.', 'nucleo' );
	}

	function Overview() {
		const ui = root.nucleoUI;
		const admin = root.nucleoAdmin || {};
		const can = admin.can || {};
		const links = admin.links || {};
		const version = admin.version || '';
		const NewDocumentModal = root.nucleoShared && root.nucleoShared.NewDocumentModal;

		const [ newOpen, setNewOpen ] = useState( false );
		const [ notice, setNotice ] = useState( null );
		const [ dismissedNow, setDismissedNow ] = useState( false );
		const [ savingDismiss, setSavingDismiss ] = useState( false );
		const [ rebuilding, setRebuilding ] = useState( false );

		// Letture indipendenti: una card che fallisce non blocca le altre.
		const site = asObject( ui.useLoad( () => ui.call( 'nucleo/site-describe', {} ), [] ) );
		// status richiede can.admin: senza permesso non si chiama e la card resta in sola lettura.
		const status = asObject( ui.useLoad( () => ( can.admin ? ui.call( 'nucleo/status', {} ) : null ), [] ) );
		const quality = asObject( ui.useLoad( () => ui.call( 'nucleo/quality-scan', { page: 1 } ), [] ) );
		const audit = asObject( ui.useLoad( () => ui.call( 'nucleo/audit-list', { limit: 5 } ), [] ) );
		const layouts = asObject( ui.useLoad( () => ui.call( 'nucleo/layout-list', {} ), [] ) );
		const settings = asObject( ui.useLoad( () => ( can.admin ? ui.call( 'nucleo/settings-get', {} ) : null ), [] ) );

		const siteData = site.state.data;
		const documentCount = siteData ? siteData.documentCount : undefined;
		const onboardingPref = settings.state.data && settings.state.data.settings
			? settings.state.data.settings.onboarding
			: null;
		const dismissed = dismissedNow || ( !! onboardingPref && onboardingPref.dismissed === true );
		const showOnboarding = documentCount === 0 && ! dismissed;
		const layoutList = layouts.state.data && Array.isArray( layouts.state.data.layouts )
			? layouts.state.data.layouts
			: [];
		const headerLayout = layoutList.find( ( l ) => l.area === 'header' );

		const NoticeNode = notice
			? h( ui.Notice, { tone: notice.tone, onClose: () => setNotice( null ), autoClose: notice.tone === 'ok' }, notice.text )
			: null;

		const openNew = () => {
			if ( ! NewDocumentModal ) {
				setNotice( { tone: 'err', text: __( 'The new document dialog is not available.', 'nucleo' ) } );
				return;
			}
			setNewOpen( true );
		};

		const dismiss = () => {
			setSavingDismiss( true );
			ui.call( 'nucleo/settings-set', { settings: { onboarding: { dismissed: true } } } ).then( ( res ) => {
				setSavingDismiss( false );
				if ( res && res.ok === false ) {
					setNotice( { tone: 'err', text: failText( res ) } );
				} else {
					setDismissedNow( true );
				}
			} );
		};

		const rebuild = () => {
			setRebuilding( true );
			setNotice( null );
			ui.call( 'nucleo/cache-rebuild', {} ).then( ( res ) => {
				setRebuilding( false );
				if ( res && res.ok === false ) {
					setNotice( { tone: 'err', text: failText( res ) } );
				} else {
					setNotice( { tone: 'ok', text: `${ __( 'Rebuilt', 'nucleo' ) }: ${ res.rebuilt } · ${ __( 'queued', 'nucleo' ) }: ${ res.queued }` } );
				}
			} );
		};

		// Riga chiave/valore con separatore tratteggiato; il valore è allineato a destra.
		const kv = ( label, value ) => h( 'div', { key: label, className: 'nc-overview__kv' },
			h( 'span', { className: 'nc-muted' }, label ),
			h( 'span', null, show( value ) ) );

		const statusCard = () => {
			const readOnly = ! can.admin;
			const st = status.state;
			const detail = readOnly ? null : st.data;
			const sd = siteData || {};
			const conflicts = detail && Array.isArray( detail.conflicts ) ? detail.conflicts : [];

			const rows = [ [ __( 'Nucleo', 'nucleo' ), ( detail && detail.nucleoVersion ) || sd.nucleoVersion ] ];
			if ( detail ) {
				rows.push( [ __( 'PHP', 'nucleo' ), detail.php ], [ __( 'WordPress', 'nucleo' ), detail.wordpress ], [ __( 'Storage', 'nucleo' ), detail.store ] );
				// OPcache e coda compaiono solo se il backend li restituisce.
				if ( detail.opcache !== undefined ) {
					rows.push( [ 'OPcache', detail.opcache ] );
				}
				if ( detail.queue !== undefined ) {
					rows.push( [ __( 'Background queue', 'nucleo' ), detail.queue ] );
				}
			}
			rows.push( [ __( 'Nucleo documents', 'nucleo' ), sd.documentCount ] );

			// I conflitti vanno in fondo alla card, come blocco di avviso.
			const conflictsShown = ! readOnly && ! st.loading && ! st.error && conflicts.length > 0;

			let statusPart = null;
			if ( readOnly ) {
				statusPart = h( ui.Notice, { tone: 'info' }, __( 'Versions, storage and conflicts are visible to administrators only.', 'nucleo' ) );
			} else if ( st.loading ) {
				statusPart = h( ui.Skeleton, { lines: 2 } );
			} else if ( st.error ) {
				statusPart = h( ui.ErrorBox, { message: st.error.message || __( 'Status could not be loaded.', 'nucleo' ), onRetry: status.reload } );
			}

			const foot = conflictsShown
				? h( 'div', { className: 'nc-overview__conflicts' }, conflicts.map( ( c, i ) => h( 'div', { key: i, className: 'nc-overview__conflict' },
					h( 'strong', { className: 'nc-overview__conflict-title' }, c.plugin ),
					h( 'p', { className: 'nc-overview__conflict-text' }, `${ c.issue }. ${ __( 'Fix', 'nucleo' ) }: ${ c.fix }` ) ) ) )
				: null;

			let headAction = null;
			if ( ! readOnly && detail ) {
				headAction = conflicts.length > 0
					? h( ui.Pill, { tone: 'warn' }, `${ conflicts.length } ${ conflicts.length === 1 ? __( 'conflict', 'nucleo' ) : __( 'conflicts', 'nucleo' ) }` )
					: h( ui.Pill, { tone: 'ok' }, __( 'No conflicts', 'nucleo' ) );
			}

			let content;
			if ( site.state.loading ) {
				content = h( ui.Skeleton, { lines: 4 } );
			} else if ( site.state.error ) {
				content = h( ui.ErrorBox, { message: site.state.error.message || __( 'Site could not be described.', 'nucleo' ), onRetry: site.reload } );
			} else {
				content = h( 'div', { className: 'nc-stack' },
					h( 'div', null, rows.map( ( [ label, value ] ) => kv( label, value ) ) ),
					statusPart );
			}

			return h( ui.Card, { title: __( 'Site status', 'nucleo' ), actions: headAction, foot }, content );
		};

		const qualityCard = () => {
			const q = quality.state;
			const data = q.data || {};
			const siteIssues = Array.isArray( data.site ) ? data.site : [];
			const pages = Array.isArray( data.pages ) ? data.pages : [];
			const avg = avgScore( pages );
			const count = siteIssues.length;
			// Pillola con glifo per gravità: il glifo lo aggiunge ui.Pill.
			const severityLabels = { err: __( 'error', 'nucleo' ), warn: __( 'warning', 'nucleo' ), info: __( 'info', 'nucleo' ) };

			return h( ui.Card, {
				title: __( 'Quality', 'nucleo' ),
				actions: h( 'a', { className: 'nc-btn nc-btn--tertiary nc-btn--small', href: pageUrl( admin, 'nucleo-quality' ) },
					__( 'Open Quality and SEO →', 'nucleo' ) ),
			},
			cardBody( ui, q, quality.reload, () => h( 'div', { className: 'nc-stack' },
				avg === null
					? h( ui.Empty, { text: __( 'No pages scanned yet.', 'nucleo' ) } )
					: h( 'div', { title: __( 'Average of the first page of the scan', 'nucleo' ) },
						h( 'span', { className: 'nc-label' }, __( 'Average SEO score', 'nucleo' ) ),
						h( 'div', { className: 'nc-overview__score' },
							String( avg ),
							h( 'span', { className: 'nc-overview__score-of' }, ' /100' ) ),
						h( ui.Progress, { value: avg, label: __( 'Average SEO score', 'nucleo' ) } ) ),
				count === 0
					? h( 'p', { className: 'nc-muted' }, __( 'No site issues found.', 'nucleo' ) )
					: h( 'span', { className: 'nc-label' },
						`${ count } ${ count === 1 ? __( 'site problem', 'nucleo' ) : __( 'site problems', 'nucleo' ) } · ${ __( 'most frequent', 'nucleo' ) }` ),
				count > 0 && h( 'div', { className: 'nc-overview__issues' }, topIssues( siteIssues, 3 ).map( ( issue ) => {
					const tone = severityTone( issue.severity );
					return h( 'div', { key: issue.rule, className: 'nc-overview__issue' },
						h( 'span', { className: 'nc-overview__issue-main' },
							severityLabels[ tone ] ? h( ui.Pill, { tone }, severityLabels[ tone ] ) : null,
							h( 'span', null, issue.message ) ),
						h( 'b', { title: __( 'Pages involved', 'nucleo' ) }, String( issue.count ) ) );
				} ) ) ) ) );
		};

		const quickCard = () => h( ui.Card, { title: __( 'Quick actions', 'nucleo' ) },
			h( 'div', { className: 'nc-overview__actions' },
				h( 'button', { type: 'button', className: 'nc-btn nc-btn--primary nc-overview__action', onClick: openNew }, `+ ${ __( 'New page', 'nucleo' ) }` ),
				headerLayout
					? h( 'a', { className: 'nc-btn nc-btn--secondary nc-overview__action', href: editorUrl( admin, headerLayout.id ) }, __( 'Open the header editor', 'nucleo' ) )
					: h( 'button', { type: 'button', className: 'nc-btn nc-btn--secondary nc-overview__action', disabled: true }, __( 'Open the header editor', 'nucleo' ) ),
				can.admin && h( 'button', {
					type: 'button',
					className: 'nc-btn nc-btn--secondary nc-overview__action',
					disabled: rebuilding,
					'aria-busy': String( rebuilding ),
					onClick: rebuild,
				}, __( 'Rebuild the cache', 'nucleo' ) ),
				can.admin && h( 'p', { className: 'nc-muted' },
					__( 'Rebuilding the cache recompiles every queued document. Visible to administrators only.', 'nucleo' ) ) ) );

		const auditCard = () => {
			const a = audit.state;
			const columns = [
				{ key: 'time', label: __( 'When', 'nucleo' ), render: ( v ) => h( 'span', { className: 'nc-muted' }, formatTime( v ) ) },
				{ key: 'user_id', label: __( 'Author', 'nucleo' ), render: ( v, row ) => h( 'span', { className: 'nc-overview__author' },
					row.user_name || ( v ? `#${ v }` : '—' ),
					row.client_name ? h( ui.Pill, { tone: 'neutral' }, row.client_name ) : null ) },
				{ key: 'ability', label: __( 'Action', 'nucleo' ), render: ( v ) => {
					const activity = root.nucleoActivity;
					return activity && typeof activity.actionLabel === 'function' ? activity.actionLabel( v ) : humanizeAbility( v );
				} },
				{ key: 'post_id', label: __( 'Document', 'nucleo' ), render: ( v, row ) => ( v
					? h( 'a', { className: 'nc-overview__doc', href: editorUrl( admin, v ) }, row.post_title || `#${ v }` )
					: '—' ) },
				{ key: 'ok', label: __( 'Outcome', 'nucleo' ), render: ( v ) => h( ui.Pill, { tone: v ? 'ok' : 'err' }, v ? __( 'Succeeded', 'nucleo' ) : __( 'Error', 'nucleo' ) ) },
			];
			const rows = a.data && Array.isArray( a.data.entries ) ? a.data.entries : [];
			return h( ui.Card, {
				title: __( 'Latest changes', 'nucleo' ),
				actions: h( 'a', { className: 'nc-btn nc-btn--tertiary nc-btn--small', href: pageUrl( admin, 'nucleo-activity' ) },
					__( 'All activity →', 'nucleo' ) ),
			},
			cardBody( ui, a, audit.reload, () => h( ui.Table, {
				columns,
				rows,
				empty: __( 'No changes yet.', 'nucleo' ),
				rowKey: ( r ) => r.id,
			} ) ) );
		};

		const startCard = ( tag, props, title, text, cta ) => h( tag, Object.assign( { className: 'nc-card nc-card--link nc-col-4' }, props ),
			h( 'div', { className: 'nc-card__body' },
				h( 'h2', { className: 'nc-card__title' }, title ),
				h( 'p', { className: 'nc-muted' }, text ),
				h( 'span', { className: 'nc-label' }, cta ) ) );

		const onboarding = () => h( 'section', { className: 'nc-stack', 'aria-label': __( 'Get started', 'nucleo' ) },
			h( 'div', { className: 'nc-row' },
				h( 'span', { className: 'nc-label' }, __( 'First start · three paths', 'nucleo' ) ),
				h( 'button', {
					type: 'button',
					className: 'nc-btn nc-btn--tertiary nc-btn--small',
					disabled: savingDismiss,
					'aria-busy': String( savingDismiss ),
					onClick: dismiss,
				}, __( "Don't show again", 'nucleo' ) ) ),
			h( 'div', { className: 'nc-grid' },
				startCard( 'a', { href: pageUrl( admin, 'nucleo-agents' ) },
					__( 'Connect an AI agent', 'nucleo' ),
					__( 'Connect Claude Desktop, Claude Code or another MCP client and let it build the pages.', 'nucleo' ),
					__( 'Connect an agent →', 'nucleo' ) ),
				startCard( 'button', { type: 'button', onClick: openNew },
					__( 'Create an empty page', 'nucleo' ),
					__( 'Open the editor with an empty section and build it by hand, block by block.', 'nucleo' ),
					__( 'New document →', 'nucleo' ) ) ) );

		return h( ui.Page, {
			title: __( 'Overview', 'nucleo' ),
			subtitle: showOnboarding
				? __( 'Welcome to Nucleo. The site has no documents yet: choose where to start.', 'nucleo' )
				: __( 'Site status and the most frequent actions.', 'nucleo' ),
			actions: h( Fragment, null,
				links.release && h( 'a', { className: 'nc-btn nc-btn--tertiary', href: links.release, rel: 'noopener' },
					root.wp.i18n.sprintf( __( "What's new in %s", 'nucleo' ), version ) ),
				h( 'button', { type: 'button', className: 'nc-btn nc-btn--primary', onClick: openNew }, __( 'New page', 'nucleo' ) ) ),
			notice: NoticeNode,
		},
		h( 'div', { className: 'nc-stack nc-overview' },
			showOnboarding && onboarding(),
			h( 'div', { className: 'nc-grid' },
				h( 'div', { className: 'nc-col-4' }, statusCard() ),
				h( 'div', { className: 'nc-col-4' }, qualityCard() ),
				h( 'div', { className: 'nc-col-4' }, quickCard() ) ),
			h( 'div', { className: 'nc-col-12' }, auditCard() ),
			( links.docs || links.changelog ) && h( 'p', { className: 'nc-row nc-muted' },
				links.docs && h( 'a', { href: links.docs, rel: 'noopener' }, __( 'Documentation', 'nucleo' ) ),
				links.changelog && h( 'a', { href: links.changelog, rel: 'noopener' }, __( 'Changelog', 'nucleo' ) ) ),
			newOpen && NewDocumentModal && h( NewDocumentModal, { postType: 'page', onClose: () => setNewOpen( false ) } ) ) );
	}

	root.nucleoPages = root.nucleoPages || {};
	root.nucleoPages[ 'nucleo' ] = Overview;
} )();

/**
 * Pagina 4.11 Attività AI (slug nucleo-activity): registro delle scritture eseguite dalle ability.
 * Nessun build: wp.element, wp.i18n. Dipende da ui.js (window.nucleoUI) e da doc-diff.js (window.nucleoDocDiff).
 * Nei test (node --test) si esporta con module.exports; nel browser è window.nucleoPages['nucleo-activity'].
 */
( function () {
	'use strict';

	const root = typeof window !== 'undefined' ? window : {};
	const PAGE_SIZE = 25;
	const ROLLBACK_ABILITIES = [ 'doc-patch', 'doc-texts-set', 'doc-publish', 'doc-rollback', 'doc-translate' ];
	// Differenze tra revisioni: in node da assets/shared/doc-diff.js, nel browser window.nucleoDocDiff.
	const DD = typeof module === 'object' && module.exports ? require( '../../shared/doc-diff.js' ) : root.nucleoDocDiff;
	const { revisionForEntry, diffDocs, restoreChanges, entryChanges, changeText } = DD;

	/* ---------- Funzioni pure ---------- */

	/** Nome dell'ability senza il prefisso nucleo/. */
	function normalizeAbility( ability ) {
		return String( ability || '' ).replace( /^nucleo\//, '' );
	}

	/** Vero per le modifiche riuscite a un documento: su di esse si può ripristinare la revisione precedente. */
	function canRollback( entry ) {
		return Boolean( entry && entry.ok === true && entry.post_id && ROLLBACK_ABILITIES.includes( normalizeAbility( entry.ability ) ) );
	}

	/** Ripristino consentito solo se la pagina non è in sola lettura. */
	function canRestore( entry, readonly ) {
		return ! readonly && canRollback( entry );
	}

	/** Link "Connect an agent": solo per chi può amministrare il sito; altrimenti null. */
	function connectAgentHref( can ) {
		return can && can.admin ? 'admin.php?page=nucleo-agents' : null;
	}

	/**
	 * Voci filtrate lato client: solo errori, un agente (client_id), un utente (user_id),
	 * un'ability e una parte del titolo del documento (post_title, senza maiuscole).
	 */
	function filterEntries( entries, { onlyErrors = false, client = '', user = '', ability = '', doc = '' } = {} ) {
		const needle = String( doc || '' ).trim().toLowerCase();
		return entries.filter( ( e ) => ( ! onlyErrors || ! e.ok )
			&& ( ! client || e.client_id === client )
			&& ( ! user || String( e.user_id ) === user )
			&& ( ! ability || normalizeAbility( e.ability ) === ability )
			&& ( ! needle || String( e.post_title || '' ).toLowerCase().includes( needle ) ) );
	}

	/** Data e ora, senza secondi, da un timestamp in secondi. */
	function formatTime( seconds, locale ) {
		return new Date( seconds * 1000 ).toLocaleString( locale ? String( locale ).replace( '_', '-' ) : undefined, {
			day: 'numeric',
			month: 'short',
			hour: '2-digit',
			minute: '2-digit',
		} );
	}

	const api = { PAGE_SIZE, normalizeAbility, canRollback, canRestore, connectAgentHref, filterEntries, revisionForEntry, diffDocs, restoreChanges, formatTime };

	/* ---------- Componente (solo con window.wp.element) ---------- */

	const el = root.wp && root.wp.element;
	if ( el ) {
		const { createElement: h, useEffect, useState } = el;
		const __ = ( s, d ) => root.wp.i18n.__( s, d || 'nucleo' );
		const msg = ( res, fallback ) => ( res && res.error && res.error.message ) || fallback;
		const SANS_FONT = "system-ui, -apple-system, 'Segoe UI', sans-serif";
		const WIDE_QUERY = '(min-width: 1100px)';

		/** Nome leggibile di un'ability; null per le sconosciute (si mostra il nome tecnico in mono). */
		function actionLabel( ability ) {
			const labels = {
				'assets-audit': __( 'Audit assets', 'nucleo' ),
				'assets-report': __( 'Assets report', 'nucleo' ),
				'assets-rules-set': __( 'Change asset rules', 'nucleo' ),
				'audit-list': __( 'List activity', 'nucleo' ),
				'bind-fields': __( 'Bind fields', 'nucleo' ),
				'cache-rebuild': __( 'Rebuild cache', 'nucleo' ),
				'catalog-get': __( 'Read catalog', 'nucleo' ),
				'catalog-list': __( 'List catalog', 'nucleo' ),
				'components-list': __( 'List components', 'nucleo' ),
				'content-get': __( 'Read content', 'nucleo' ),
				'content-list': __( 'List content', 'nucleo' ),
				'content-upsert': __( 'Save content', 'nucleo' ),
				'critical-set': __( 'Change critical CSS', 'nucleo' ),
				'css-optimize': __( 'Optimize CSS', 'nucleo' ),
				'css-optimize-apply': __( 'Apply CSS optimization', 'nucleo' ),
				'css-optimize-off': __( 'Disable CSS optimization', 'nucleo' ),
				'css-purge': __( 'Purge CSS', 'nucleo' ),
				'css-status': __( 'CSS status', 'nucleo' ),
				'doc-create': __( 'Create document', 'nucleo' ),
				'doc-get': __( 'Read document', 'nucleo' ),
				'doc-list': __( 'List documents', 'nucleo' ),
				'doc-patch': __( 'Edit document', 'nucleo' ),
				'doc-preview': __( 'Preview document', 'nucleo' ),
				'doc-publish': __( 'Publish document', 'nucleo' ),
				'doc-revisions': __( 'Read revisions', 'nucleo' ),
				'doc-rollback': __( 'Restore revision', 'nucleo' ),
				'doc-texts': __( 'Read document texts', 'nucleo' ),
				'doc-texts-set': __( 'Edit texts', 'nucleo' ),
				'doc-translate': __( 'Translate document', 'nucleo' ),
				'doc-validate': __( 'Validate document', 'nucleo' ),
				'fields-get': __( 'Read fields', 'nucleo' ),
				'fields-set': __( 'Change fields', 'nucleo' ),
				'fonts-import': __( 'Import fonts', 'nucleo' ),
				'gap-list': __( 'List gaps', 'nucleo' ),
				'gap-report': __( 'Report gaps', 'nucleo' ),
				'guide': __( 'Guide', 'nucleo' ),
				'icons-set': __( 'Change icons', 'nucleo' ),
				'kit-apply': __( 'Apply kit', 'nucleo' ),
				'kit-list': __( 'List kits', 'nucleo' ),
				'layout-conditions-set': __( 'Change layout conditions', 'nucleo' ),
				'layout-explain': __( 'Explain layout', 'nucleo' ),
				'layout-list': __( 'List layouts', 'nucleo' ),
				'mail-log': __( 'Mail log', 'nucleo' ),
				'media-formats': __( 'Change media formats', 'nucleo' ),
				'media-import': __( 'Import media', 'nucleo' ),
				'media-upload': __( 'Upload media', 'nucleo' ),
				'menu-list': __( 'List menus', 'nucleo' ),
				'menu-set': __( 'Change menu', 'nucleo' ),
				'model-get': __( 'Read model', 'nucleo' ),
				'model-set': __( 'Change model', 'nucleo' ),
				'model-validate': __( 'Validate model', 'nucleo' ),
				'quality-check': __( 'Check quality', 'nucleo' ),
				'quality-scan': __( 'Scan quality', 'nucleo' ),
				'section-list': __( 'List sections', 'nucleo' ),
				'settings-get': __( 'Read settings', 'nucleo' ),
				'settings-set': __( 'Change settings', 'nucleo' ),
				'site-describe': __( 'Describe site', 'nucleo' ),
				'site-export': __( 'Export site', 'nucleo' ),
				'site-import': __( 'Import site', 'nucleo' ),
				'site-options-set': __( 'Change site options', 'nucleo' ),
				'status': __( 'Status', 'nucleo' ),
				'terms-get': __( 'Read terms', 'nucleo' ),
				'terms-set': __( 'Change terms', 'nucleo' ),
				'tokens-get': __( 'Read tokens', 'nucleo' ),
				'tokens-set': __( 'Change design tokens', 'nucleo' ),
			};
			return labels[ normalizeAbility( ability ) ] || null;
		}

		/** Riepilogo leggibile: i codici noti in parole, la frase "N documents rebuilt, M queued" tradotta, il testo libero resta com'è. */
		function summaryText( summary ) {
			const rebuilt = /^(\d+) documents rebuilt, (\d+) queued$/.exec( String( summary || '' ) );
			if ( rebuilt ) {
				return root.wp.i18n.sprintf( __( '%1$d documents rebuilt, %2$d queued', 'nucleo' ), Number( rebuilt[ 1 ] ), Number( rebuilt[ 2 ] ) );
			}
			const codes = {
				ok: __( 'Completed', 'nucleo' ),
				publish: __( 'Published', 'nucleo' ),
				quality_blocking: __( 'Blocked by quality check', 'nucleo' ),
				invalid_document: __( 'Invalid document', 'nucleo' ),
			};
			return codes[ summary ] || summary;
		}

		function ActivityPage() {
			const UI = root.nucleoUI;
			const nucleoAdmin = root.nucleoAdmin || {};
			const locale = ( nucleoAdmin.i18n && nucleoAdmin.i18n.locale ) || undefined;
			const editorUrl = ( nucleoAdmin.urls && nucleoAdmin.urls.editor ) || '';
			const readonly = UI.access( 'nucleo-activity', null ).access === 'readonly';

			const [ state, reload ] = UI.useLoad( () => UI.call( 'nucleo/audit-list', { limit: 200 } ), [] );
			const [ agent, setAgent ] = useState( '' );
			const [ user, setUser ] = useState( '' );
			const [ ability, setAbility ] = useState( '' );
			const [ doc, setDoc ] = useState( '' );
			const [ onlyErrors, setOnlyErrors ] = useState( false );
			const [ page, setPage ] = useState( 0 );
			const [ busy, setBusy ] = useState( false );
			const [ pending, setPending ] = useState( null );
			const [ notice, setNotice ] = useState( null );
				const [ revsByPost, setRevsByPost ] = useState( {} );
				const [ openRow, setOpenRow ] = useState( null );
			const [ wide, setWide ] = useState( () => ( root.matchMedia ? root.matchMedia( WIDE_QUERY ).matches : true ) );

			/** Sopra 1100px i filtri restano su una riga; sotto possono andare a capo. */
			useEffect( () => {
				if ( ! root.matchMedia ) {
					return undefined;
				}
				const mq = root.matchMedia( WIDE_QUERY );
				const onChange = () => setWide( mq.matches );
				mq.addEventListener( 'change', onChange );
				return () => mq.removeEventListener( 'change', onChange );
			}, [] );

			const entries = ( state.data && state.data.entries ) || [];
			const agents = [ ...new Map( entries.filter( ( e ) => e.client_id ).map( ( e ) => [ e.client_id, e.client_name || e.client_id ] ) ) ]
				.sort( ( a, b ) => a[ 1 ].localeCompare( b[ 1 ] ) );
			const users = [ ...new Map( entries.filter( ( e ) => e.user_id ).map( ( e ) => [ String( e.user_id ), e.user_name || '—' ] ) ) ]
				.sort( ( a, b ) => a[ 1 ].localeCompare( b[ 1 ] ) );
			const abilities = [ ...new Set( entries.map( ( e ) => normalizeAbility( e.ability ) ).filter( Boolean ) ) ].sort();
			const rows = filterEntries( entries, { onlyErrors, client: agent, user, ability, doc } );
			const pages = Math.max( 1, Math.ceil( rows.length / PAGE_SIZE ) );
			const current = Math.min( page, pages - 1 );
			const start = current * PAGE_SIZE;
			const pageRows = rows.slice( start, start + PAGE_SIZE );
			const emptyLog = ! state.loading && ! state.error && entries.length === 0;
			const agentsHref = connectAgentHref( nucleoAdmin.can );

			/** Legge le revisioni e chiede conferma sulla precedente all'ultima. Nessuna scrittura qui. */
			const startRestore = ( row ) => {
				setBusy( true );
				UI.call( 'nucleo/doc-revisions', { postId: row.post_id, withDocs: true } ).then( ( res ) => {
					setBusy( false );
					if ( ! res || ! res.ok ) {
						setNotice( { tone: 'err', text: msg( res, __( 'Revisions not loaded.', 'nucleo' ) ) } );
						return;
					}
					const prev = revisionForEntry( res.revisions, row );
					if ( ! prev ) {
						setNotice( { tone: 'err', text: __( 'The revision of this entry was not found.', 'nucleo' ) } );
						return;
					}
					setPending( { postId: row.post_id, rev: prev.rev, time: prev.time, changes: restoreChanges( res.revisions, prev ) } );
				} );
			};

			const confirmRestore = () => {
				const { postId, rev } = pending;
				setBusy( true );
				UI.call( 'nucleo/doc-rollback', { postId, revision: rev } ).then( ( res ) => {
					setBusy( false );
					setPending( null );
					if ( res && res.ok ) {
						setNotice( { tone: 'ok', text: root.wp.i18n.sprintf( __( 'Revision %d restored.', 'nucleo' ), rev ) } );
						reload();
					} else {
						setNotice( { tone: 'err', text: msg( res, __( 'Restore failed.', 'nucleo' ) ) } );
					}
				} );
			};

			/** Revisioni con i documenti, lette una sola volta per documento: { loading }, { revisions } o { error }. */
				const loadChanges = ( row ) => {
					const known = revsByPost[ row.post_id ];
					if ( known && ! known.error ) {
						return;
					}
					setRevsByPost( ( cur ) => ( { ...cur, [ row.post_id ]: { loading: true } } ) );
					UI.call( 'nucleo/doc-revisions', { postId: row.post_id, withDocs: true } ).then( ( res ) => {
						setRevsByPost( ( cur ) => ( { ...cur, [ row.post_id ]: res && res.ok
							? { revisions: res.revisions || [] }
							: { error: msg( res, __( 'Revisions not loaded.', 'nucleo' ) ) } } ) );
					} );
				};

				/** Apre o chiude le modifiche di una voce; le revisioni si leggono alla prima apertura. */
				const toggleChanges = ( row ) => {
					if ( openRow === row.id ) {
						setOpenRow( null );
						return;
					}
					setOpenRow( row.id );
					loadChanges( row );
				};

				/** Elenco delle modifiche della voce, sotto la riga. */
				const changesView = ( row ) => {
					const known = revsByPost[ row.post_id ];
					if ( ! known || known.loading ) {
						return h( 'p', { className: 'nc-muted' }, __( 'Loading...', 'nucleo' ) );
					}
					if ( known.error ) {
						return h( 'p', { className: 'nc-muted' }, known.error );
					}
					const list = entryChanges( known.revisions, row );
					return list.length
						? h( 'ul', null, list.map( ( c, i ) => h( 'li', { key: i }, changeText( c ) ) ) )
						: h( 'p', { className: 'nc-muted' }, __( 'No changes in the document.', 'nucleo' ) );
				};

				const columns = [
				{ key: 'time', label: __( 'Date and time', 'nucleo' ), render: ( v, row ) => formatTime( row.time, locale ) },
				{ key: 'author', label: __( 'Author', 'nucleo' ), render: ( v, row ) => h( 'span', null,
					row.user_name || '—',
					row.client_name ? ' ' : null,
					row.client_name ? h( UI.Pill, { tone: 'neutral' }, row.client_name ) : null ) },
				{ key: 'action', label: __( 'Action', 'nucleo' ), render: ( v, row ) => actionLabel( row.ability )
					|| h( 'span', { className: 'nc-code' }, normalizeAbility( row.ability ) ) },
				{ key: 'document', label: __( 'Document', 'nucleo' ), render: ( v, row ) => row.post_id
					? h( 'a', { href: `${ editorUrl }${ encodeURIComponent( row.post_id ) }` },
						row.post_title || root.wp.i18n.sprintf( __( 'Document #%d', 'nucleo' ), row.post_id ) )
					: '—' },
				{ key: 'outcome', label: __( 'Outcome', 'nucleo' ), render: ( v, row ) => h( UI.Pill, { tone: row.ok ? 'ok' : 'err' }, row.ok ? __( 'Succeeded', 'nucleo' ) : __( 'Error', 'nucleo' ) ) },
				{ key: 'summary', label: __( 'Summary', 'nucleo' ), render: ( v, row ) => h( 'span', {
					title: row.summary,
					style: { display: '-webkit-box', WebkitLineClamp: 2, WebkitBoxOrient: 'vertical', overflow: 'hidden', fontFamily: SANS_FONT },
				}, summaryText( row.summary ) ) },
				{ key: 'actions', label: '', align: 'right', render: ( v, row ) => h( 'div', null,
						row.post_id > 0 && h( 'button', {
							type: 'button',
							className: 'nc-btn nc-btn--secondary nc-btn--small',
							'aria-expanded': String( openRow === row.id ),
							onClick: () => toggleChanges( row ),
						}, __( 'Changes', 'nucleo' ) ),
						canRestore( row, readonly ) && h( 'button', {
					type: 'button',
					className: 'nc-btn nc-btn--secondary nc-btn--small',
					disabled: busy,
					onClick: () => startRestore( row ),
				}, __( 'Restore', 'nucleo' ) ),
						openRow === row.id && h( 'div', { style: { textAlign: 'left' } }, changesView( row ) ) ) },
			];

			/** Cambio di un filtro: torna alla prima pagina. */
			const onFilter = ( set ) => ( ev ) => {
				set( ev.target.value );
				setPage( 0 );
			};

			const toolbar = h( 'div', { className: 'nc-toolbar', style: { flexWrap: wide ? 'nowrap' : 'wrap', marginBottom: 0 } },
				h( 'select', {
					className: 'nc-select',
					'aria-label': __( 'Agent', 'nucleo' ),
					value: agent,
					onChange: onFilter( setAgent ),
					style: { width: 'auto' },
				},
				h( 'option', { value: '' }, __( 'All agents', 'nucleo' ) ),
				agents.map( ( [ id, label ] ) => h( 'option', { key: id, value: id }, label ) ) ),
				h( 'select', {
					className: 'nc-select',
					'aria-label': __( 'User', 'nucleo' ),
					value: user,
					onChange: onFilter( setUser ),
					style: { width: 'auto' },
				},
				h( 'option', { value: '' }, __( 'All users', 'nucleo' ) ),
				users.map( ( [ id, label ] ) => h( 'option', { key: id, value: id }, label ) ) ),
				h( 'select', {
					className: 'nc-select',
					'aria-label': __( 'Action', 'nucleo' ),
					value: ability,
					onChange: onFilter( setAbility ),
					style: { width: 'auto' },
				},
				h( 'option', { value: '' }, __( 'All actions', 'nucleo' ) ),
				abilities.map( ( a ) => h( 'option', { key: a, value: a }, actionLabel( a ) || a ) ) ),
				h( 'input', {
					className: 'nc-input',
					type: 'search',
					placeholder: __( 'Document', 'nucleo' ),
					'aria-label': __( 'Filter by document', 'nucleo' ),
					value: doc,
					onChange: onFilter( setDoc ),
					style: { flex: '0 1 220px', maxWidth: '220px' },
				} ),
				h( 'label', { className: 'nc-check', style: { whiteSpace: 'nowrap' } },
					h( 'input', {
						type: 'checkbox',
						checked: onlyErrors,
						onChange: ( ev ) => {
							setOnlyErrors( ev.target.checked );
							setPage( 0 );
						},
					} ),
					__( 'Only errors', 'nucleo' ) ),
				h( 'span', { className: 'nc-muted', style: { whiteSpace: 'nowrap' } }, root.wp.i18n.sprintf( __( '%d entries', 'nucleo' ), rows.length ) ) );

			const table = h( UI.Table, {
				columns,
				rows: pageRows,
				loading: state.loading,
				rowKey: ( row ) => row.id,
				empty: __( 'No entries match these filters.', 'nucleo' ),
			} );

			const foot = h( 'div', { className: 'nc-row', style: { justifyContent: 'space-between' } },
				h( 'span', { className: 'nc-muted' }, root.wp.i18n.sprintf( __( '%1$d–%2$d of %3$d', 'nucleo' ), rows.length ? start + 1 : 0, start + pageRows.length, rows.length ) ),
				h( 'div', { className: 'nc-row' },
					h( 'button', {
						type: 'button',
						className: 'nc-btn nc-btn--secondary nc-btn--small',
						disabled: current === 0,
						onClick: () => setPage( current - 1 ),
					}, __( 'Previous', 'nucleo' ) ),
					h( 'button', {
						type: 'button',
						className: 'nc-btn nc-btn--secondary nc-btn--small',
						disabled: current >= pages - 1,
						onClick: () => setPage( current + 1 ),
					}, __( 'Next', 'nucleo' ) ) ) );

			const confirm = pending && h( UI.Confirm, {
				title: root.wp.i18n.sprintf( __( 'Restore revision %d?', 'nucleo' ), pending.rev ),
				message: h( 'span', null,
						root.wp.i18n.sprintf( __( 'Restore revision %1$d from %2$s? The current version stays in history.', 'nucleo' ), pending.rev, formatTime( pending.time, locale ) ),
						pending.changes.length
							? pending.changes.map( ( c, i ) => h( 'span', { key: i, style: { display: 'block' } }, changeText( c ) ) )
							: h( 'span', { style: { display: 'block' } }, __( 'No differences in the content.', 'nucleo' ) ) ),
				confirmLabel: __( 'Restore', 'nucleo' ),
				onConfirm: confirmRestore,
				onCancel: () => setPending( null ),
			} );

			let body;
			if ( state.error ) {
				body = h( UI.ErrorBox, { message: state.error.message || __( 'Activity not loaded.', 'nucleo' ), onRetry: reload } );
			} else if ( emptyLog ) {
				body = h( UI.Empty, {
					text: h( 'span', null,
						h( 'strong', null, __( 'No activity yet', 'nucleo' ) ), ' ',
						__( 'Changes made through Nucleo, by people and AI agents, appear here.', 'nucleo' ) ),
					action: agentsHref && h( 'a', { className: 'nc-btn nc-btn--secondary', href: agentsHref }, __( 'Connect an agent', 'nucleo' ) ),
				} );
			} else {
				body = h( 'div', { className: 'nc-stack' }, toolbar, table, confirm );
			}

			return h( UI.Page, {
				title: __( 'AI activity', 'nucleo' ),
				subtitle: __( 'The log of changes made by agents and people through Nucleo.', 'nucleo' ),
				notice: notice && h( UI.Notice, { tone: notice.tone, onClose: () => setNotice( null ) }, notice.text ),
			},
			h( UI.Card, { foot: state.error || emptyLog ? null : foot }, body ) );
		}

		root.nucleoPages = root.nucleoPages || {};
		root.nucleoPages[ 'nucleo-activity' ] = ActivityPage;
		/** Testi leggibili per altre pagine (es. Panoramica): nome di un'ability e riepilogo. */
		root.nucleoActivity = { actionLabel, summaryText };
	}

	if ( typeof module === 'object' && module.exports ) {
		module.exports = api;
	}
} )();

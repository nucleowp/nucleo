/**
 * Pagina Pagine (nucleo-documents): documenti Nucleo di tipo pagina e articolo.
 * Nessun build: wp.element, wp.i18n. Dipende da ui.js (window.nucleoUI) e da shared.js (window.nucleoShared).
 * In node si esportano le funzioni pure del test; nel browser è window.nucleoPages['nucleo-documents'].
 */
( function () {
	'use strict';

	const root = typeof window !== 'undefined' ? window : {};
	const PER_PAGE = 20;
	const TABS = [ 'page', 'post' ];
	const STATUSES = [ 'publish', 'draft', 'pending', 'private', 'future' ];
	const TONES = { publish: 'ok', draft: 'warn', pending: 'info', private: 'neutral', future: 'info' };

	/** Tono del Pill per lo stato del documento. Stati sconosciuti: neutral. */
	function statusTone( status ) {
		return Object.prototype.hasOwnProperty.call( TONES, status ) ? TONES[ status ] : 'neutral';
	}

	/** Numero di pagine per `total` elementi: almeno 1. */
	function pageCount( total, perPage ) {
		return Math.max( 1, Math.ceil( ( total || 0 ) / perPage ) );
	}

	/**
	 * Unisce i punteggi per postId. Con `scores` non array la scansione non è pronta:
	 * score resta undefined e la cella mostra lo scheletro. Un post assente dalla scansione ha score null.
	 */
	function mergeScores( items, scores ) {
		if ( ! Array.isArray( scores ) ) {
			return items.map( ( item ) => ( { ...item } ) );
		}
		const byId = new Map( scores.map( ( s ) => [ s.postId, s.score ] ) );
		return items.map( ( item ) => ( { ...item, score: byId.has( item.id ) ? byId.get( item.id ) : null } ) );
	}

	/** Data breve della modifica (ISO 8601), con l'anno solo se diverso dall'anno corrente. Vuota se non valida. */
	function shortDate( iso ) {
		const d = new Date( iso );
		if ( ! iso || Number.isNaN( d.getTime() ) ) {
			return '';
		}
		const sameYear = d.getFullYear() === new Date().getFullYear();
		return d.toLocaleDateString( undefined, { day: 'numeric', month: 'short', year: sameYear ? undefined : 'numeric' } );
	}

	/** Pagina dei documenti, con schede Pages/Posts, ricerca, filtri, paginazione e azioni per riga. */
	function DocumentsPage() {
		const { createElement: h, useState } = root.wp.element;
		const { __ } = ( root.wp && root.wp.i18n ) || { __: ( text ) => text };
		const UI = root.nucleoUI;
		const shared = root.nucleoShared || {};
		const admin = root.nucleoAdmin || {};
		const editorBase = ( admin.urls && admin.urls.editor ) || '';
		const langs = ( admin.i18n && admin.i18n.languages ) || [];
		const multilingual = langs.length >= 2;
		const hasLangs = langs.length > 0;

		const [ tab, setTab ] = useState( () => UI.tabFromSearch( root.location.search, TABS, 'page' ) );
		const [ searchInput, setSearchInput ] = useState( '' );
		const [ search, setSearch ] = useState( '' );
		const [ status, setStatus ] = useState( '' );
		const [ lang, setLang ] = useState( '' );
		const [ selected, setSelected ] = useState( () => new Set() );
		const [ page, setPage ] = useState( 1 );
		const [ creating, setCreating ] = useState( false );
		const [ translating, setTranslating ] = useState( null );

		const [ list, reloadList ] = UI.useLoad( () => UI.call( 'nucleo/doc-list', { postType: tab, search, page } ), [ tab, search, page ] );
		const [ scan, reloadScan ] = UI.useLoad( () => UI.call( 'nucleo/quality-scan', { page: 1 } ), [] );

		const editUrl = ( id ) => `${ editorBase }${ id }`;
		const scanScores = scan.loading ? null : scan.error ? [] : ( scan.data && scan.data.pages ) || [];
		const allRows = list.data ? mergeScores( list.data.items || [], scanScores ) : [];
		// Il filtro di stato e lingua vale sulla pagina caricata: doc-list non accetta questi parametri.
		const rows = allRows.filter( ( row ) => ( ! status || row.status === status ) && ( ! lang || row.lang === lang ) );
		const pages = pageCount( list.data && list.data.total, PER_PAGE );

		const statusLabel = ( value ) => {
			switch ( value ) {
				case 'publish':
					return __( 'Published', 'nucleo' );
				case 'draft':
					return __( 'Draft', 'nucleo' );
				case 'pending':
					return __( 'Pending', 'nucleo' );
				case 'private':
					return __( 'Private', 'nucleo' );
				case 'future':
					return __( 'Scheduled', 'nucleo' );
				default:
					return String( value || '' );
			}
		};

		const rowItems = ( row ) => {
			const items = [
				{ label: __( 'Edit', 'nucleo' ), href: editUrl( row.id ) },
				{ label: __( 'View', 'nucleo' ), href: row.link },
				{ label: __( 'Text only', 'nucleo' ), href: `${ editUrl( row.id ) }&view=texts` },
			];
			if ( multilingual ) {
				items.push( { label: __( 'Translate', 'nucleo' ), onClick: () => setTranslating( row ) } );
			}
			return items;
		};

		const toggleSelected = ( id ) => {
			setSelected( ( prev ) => {
				const next = new Set( prev );
				if ( next.has( id ) ) {
					next.delete( id );
				} else {
					next.add( id );
				}
				return next;
			} );
		};

		const rowActions = ( row ) => h( UI.RowMenu, { label: `${ __( 'Actions for', 'nucleo' ) } ${ row.title }`, items: rowItems( row ) } );

		const columns = [
			{ key: 'select', label: h( 'span', { className: 'screen-reader-text' }, __( 'Select', 'nucleo' ) ), width: '44px',
				render: ( row ) => h( 'input', {
					type: 'checkbox',
					checked: selected.has( row.id ),
					'aria-label': `${ __( 'Select', 'nucleo' ) } ${ row.title }`,
					onChange: () => toggleSelected( row.id ),
				} ) },
			{ key: 'title', label: __( 'Title', 'nucleo' ), render: ( row ) => h( 'a', { href: editUrl( row.id ) }, row.title ) },
			{ key: 'status', label: __( 'Status', 'nucleo' ), width: '150px', render: ( row ) => h( UI.Pill, { tone: statusTone( row.status ) }, statusLabel( row.status ) ) },
			...( hasLangs ? [ { key: 'lang', label: __( 'Language', 'nucleo' ), width: '90px', render: ( row ) => ( row.lang ? String( row.lang ).toUpperCase() : '—' ) } ] : [] ),
			{ key: 'author', label: __( 'Author', 'nucleo' ), width: '160px', render: ( row ) => row.author || '—' },
			{ key: 'modified', label: __( 'Last modified', 'nucleo' ), width: '170px', render: ( row ) => h( 'time', { dateTime: row.modified }, shortDate( row.modified ) || '—' ) },
			{
				key: 'score',
				label: 'SEO',
				width: '180px',
				render: ( row ) => {
					if ( row.score === undefined ) {
						return h( UI.Skeleton, { lines: 1 } );
					}
					if ( row.score === null ) {
						return '—';
					}
					return h( UI.Score, { value: row.score, label: __( 'SEO score', 'nucleo' ) } );
				},
			},
			{ key: 'revision', label: __( 'Revision', 'nucleo' ), width: '80px', align: 'right', render: ( row ) => row.revision },
			{ key: 'actions', label: '', width: '56px', render: ( row ) => rowActions( row ) },
		];

		const openNew = () => setCreating( true );
		const onTab = ( id ) => {
			setTab( id );
			setPage( 1 );
		};
		const onSearch = ( e ) => {
			e.preventDefault();
			setPage( 1 );
			setSearch( searchInput.trim() );
		};

		const tabs = h( UI.Tabs, {
			tabs: [ { id: 'page', label: __( 'Pages', 'nucleo' ) }, { id: 'post', label: __( 'Posts', 'nucleo' ) } ],
			active: tab,
			onChange: onTab,
		} );

		const filters = h( 'div', { className: 'nc-toolbar' },
			h( 'form', { role: 'search', onSubmit: onSearch },
				h( 'label', { htmlFor: 'nucleo-doc-search', className: 'screen-reader-text' }, __( 'Search', 'nucleo' ) ),
				h( 'input', {
					id: 'nucleo-doc-search',
					type: 'search',
					value: searchInput,
					placeholder: __( 'Search by title', 'nucleo' ),
					onChange: ( e ) => setSearchInput( e.target.value ),
				} ) ),
			h( 'label', { htmlFor: 'nucleo-doc-status', className: 'screen-reader-text' }, __( 'Status', 'nucleo' ) ),
			h( 'select', { id: 'nucleo-doc-status', value: status, onChange: ( e ) => setStatus( e.target.value ) },
				h( 'option', { value: '' }, __( 'All statuses', 'nucleo' ) ),
				STATUSES.map( ( s ) => h( 'option', { key: s, value: s }, statusLabel( s ) ) ) ),
			hasLangs && h( 'label', { htmlFor: 'nucleo-doc-lang', className: 'screen-reader-text' }, __( 'Language', 'nucleo' ) ),
			hasLangs && h( 'select', { id: 'nucleo-doc-lang', value: lang, onChange: ( e ) => setLang( e.target.value ) },
				h( 'option', { value: '' }, __( 'All languages', 'nucleo' ) ),
				langs.map( ( code ) => h( 'option', { key: code, value: code }, String( code ).toUpperCase() ) ) ),
			tabs );

		const count = list.data ? `${ list.data.total || 0 } ${ __( 'documents', 'nucleo' ) }` : '';
		const toolbar = h( 'div', { className: 'nc-toolbar nc-toolbar--split' },
			filters,
			h( 'span', { className: 'nc-muted' }, count ) );

		let content;
		if ( list.error ) {
			content = h( UI.ErrorBox, { message: list.error.message || __( 'Could not load documents.', 'nucleo' ), onRetry: reloadList } );
		} else if ( list.loading ) {
			content = h( UI.Skeleton, { lines: 3 } );
		} else if ( allRows.length === 0 ) {
			content = h( UI.Empty, {
				text: __( 'No documents', 'nucleo' ),
				action: h( 'button', { type: 'button', className: 'nc-btn nc-btn--secondary', onClick: openNew }, __( 'Create a page', 'nucleo' ) ),
			} );
		} else if ( rows.length === 0 ) {
			content = h( UI.Empty, { text: __( 'No documents match the filters.', 'nucleo' ) } );
		} else {
			content = h( 'div', { className: 'nc-table-wrap' },
				h( 'table', { className: 'nc-table nc-table--fixed' },
					h( 'thead', null,
						h( 'tr', null, columns.map( ( c ) => h( 'th', { key: c.key, scope: 'col', style: { width: c.width, textAlign: c.align } }, c.label ) ) ) ),
					h( 'tbody', null, rows.map( ( row ) => h( 'tr', { key: row.id, className: selected.has( row.id ) ? 'is-selected' : undefined },
						columns.map( ( c ) => h( 'td', { key: c.key, style: { textAlign: c.align } }, c.render( row ) ) ) ) ) ) ) );
		}

		const pager = ! list.loading && ! list.error && h( 'div', { className: 'nc-pager' },
			h( 'button', { type: 'button', className: 'nc-btn nc-btn--secondary', disabled: page <= 1, onClick: () => setPage( page - 1 ) }, __( 'Previous', 'nucleo' ) ),
			h( 'span', null, `${ page } / ${ pages }` ),
			h( 'button', { type: 'button', className: 'nc-btn nc-btn--secondary', disabled: page >= pages, onClick: () => setPage( page + 1 ) }, __( 'Next', 'nucleo' ) ) );

		const newButton = h( 'button', { type: 'button', className: 'nc-btn nc-btn--primary', onClick: openNew }, __( 'New document', 'nucleo' ) );

		const newModal = creating && shared.NewDocumentModal && h( shared.NewDocumentModal, {
			postType: tab,
			onClose: () => setCreating( false ),
		} );
		const translateModal = translating && shared.TranslateModal && h( shared.TranslateModal, {
			postId: translating.id,
			title: translating.title,
			onClose: () => setTranslating( null ),
		} );

		return h( UI.Page, { title: __( 'Pages', 'nucleo' ), subtitle: __( 'All Nucleo documents of type page and post. WordPress pages stay in their own screen.', 'nucleo' ), actions: newButton },
			h( UI.Card, { foot: pager || undefined }, toolbar, content ),
			newModal, translateModal );
	}

	const api = { statusTone, pageCount, mergeScores };

	if ( typeof module === 'object' && module.exports ) {
		module.exports = api;
	} else {
		root.nucleoPages = root.nucleoPages || {};
		root.nucleoPages[ 'nucleo-documents' ] = DocumentsPage;
	}
} )();

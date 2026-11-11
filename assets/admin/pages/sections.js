/**
 * Pagina Sezioni globali (slug nucleo-sections): elenco delle sezioni riutilizzabili e nuova sezione.
 * Nessun build: wp.element, wp.i18n. Dipende da ui.js (window.nucleoUI) e shared.js (window.nucleoShared).
 * Nei test (node --test) si esporta con module.exports; nel browser è window.nucleoPages['nucleo-sections'].
 */
( function () {
	'use strict';

	const root = typeof window !== 'undefined' ? window : {};
	const DATE_OPTS = { day: 'numeric', month: 'short' };

	/** Tono del badge di stato di un documento: publish ok, draft warn, altro neutro. */
	function statusTone( status ) {
		if ( status === 'publish' ) {
			return 'ok';
		}
		if ( status === 'draft' ) {
			return 'warn';
		}
		return 'neutral';
	}

	/** Sezioni il cui titolo contiene la ricerca, senza distinguere maiuscole. Ricerca vuota: tutte. */
	function filterSections( items, query ) {
		const q = String( query || '' ).trim().toLowerCase();
		if ( ! q ) {
			return items;
		}
		return items.filter( ( item ) => String( item.title || '' ).toLowerCase().includes( q ) );
	}

	/**
	 * Aggiunge `uses` (conteggio pagine) da nucleo/section-list, per id. Lista non pronta: nessun uses.
	 * Sezione assente dalla lista: uses null.
	 */
	function mergeUses( items, sections ) {
		if ( ! Array.isArray( sections ) ) {
			return items.map( ( item ) => ( { ...item } ) );
		}
		const byId = new Map( sections.map( ( s ) => [ s.id, s.uses ] ) );
		return items.map( ( item ) => ( { ...item, uses: byId.has( item.id ) ? byId.get( item.id ) : null } ) );
	}

	/** La colonna "Usata in" esiste solo se almeno una sezione ha un conteggio numerico (uses). */
	function showsUsage( items ) {
		return items.some( ( item ) => typeof item.uses === 'number' );
	}

	/** Data dell'ultima modifica e autore, es. "24 ott · marco". Senza data o non valida: trattino. */
	function modifiedLabel( modified, author ) {
		const date = modified ? new Date( modified ) : null;
		if ( ! date || Number.isNaN( date.getTime() ) ) {
			return '—';
		}
		const day = date.toLocaleDateString( undefined, DATE_OPTS );
		return author ? `${ day } · ${ author }` : day;
	}

	const el = root.wp && root.wp.element;
	if ( el ) {
		const { createElement: h, useState } = el;
		const { __ } = ( root.wp && root.wp.i18n ) || { __: ( text ) => text };

		/** Etichetta leggibile dello stato di un documento. */
		function statusLabel( status ) {
			switch ( status ) {
				case 'publish':
					return __( 'Published', 'nucleo' );
				case 'draft':
					return __( 'Draft', 'nucleo' );
				case 'pending':
					return __( 'Pending', 'nucleo' );
				case 'private':
					return __( 'Private', 'nucleo' );
				default:
					return status;
			}
		}

		/** Pagina Sezioni globali: ricerca, tabella delle sezioni e azione "New section". */
		function SectionsPage() {
			const UI = root.nucleoUI;
			const [ creating, setCreating ] = useState( false );
			const [ search, setSearch ] = useState( '' );
			const [ state, reload ] = UI.useLoad( () => UI.call( 'nucleo/doc-list', { postType: 'nucleo_section' } ), [] );
			const [ usageState ] = UI.useLoad( () => UI.call( 'nucleo/section-list', {} ), [] );

			const all = mergeUses( ( state.data && state.data.items ) || [], usageState.data && usageState.data.sections );
			const items = filterSections( all, search );
			const usage = showsUsage( all );
			const editUrl = ( id ) => root.nucleoAdmin.urls.editor + id;

			const usesText = ( n ) => `${ n } ${ n === 1 ? __( 'page', 'nucleo' ) : __( 'pages', 'nucleo' ) }`;
			const countText = ( n ) => `${ n } ${ n === 1 ? __( 'section', 'nucleo' ) : __( 'sections', 'nucleo' ) }`;

			const rowItems = ( row ) => [ { label: __( 'Edit', 'nucleo' ), href: editUrl( row.id ) } ];

			const columns = [
				{
					key: 'title',
					label: __( 'Title', 'nucleo' ),
					render: ( value, row ) => h( 'a', { href: editUrl( row.id ) }, value ),
				},
				{
					key: 'status',
					label: __( 'Status', 'nucleo' ),
					render: ( value ) => h( UI.Pill, { tone: statusTone( value ) }, statusLabel( value ) ),
				},
				...( usage ? [ {
					key: 'uses',
					label: __( 'Used in', 'nucleo' ),
					render: ( value ) => ( typeof value === 'number'
						? h( 'span', { className: value === 0 ? 'nc-muted' : undefined }, usesText( value ) )
						: '—' ),
				} ] : [] ),
				{
					key: 'modified',
					label: __( 'Last modified', 'nucleo' ),
					render: ( value, row ) => modifiedLabel( value, row.author ),
				},
				{
					key: 'actions',
					label: '',
					render: ( value, row ) => h( UI.RowMenu, { label: `${ __( 'Actions for', 'nucleo' ) } ${ row.title }`, items: rowItems( row ) } ),
				},
			];

			const toolbar = h( 'div', { className: 'nc-toolbar' },
				h( 'label', { htmlFor: 'nucleo-section-search', className: 'screen-reader-text' }, __( 'Search', 'nucleo' ) ),
				h( 'input', {
					id: 'nucleo-section-search',
					type: 'search',
					value: search,
					placeholder: __( 'Search sections', 'nucleo' ),
					style: { width: '240px' },
					onChange: ( e ) => setSearch( e.target.value ),
				} ),
				! state.loading && ! state.error && h( 'span', { className: 'nc-muted' }, countText( items.length ) ) );

			const body = state.error
				? h( UI.ErrorBox, { message: state.error.message || __( 'Could not load the sections.', 'nucleo' ), onRetry: reload } )
				: h( UI.Table, {
					columns,
					rows: items,
					loading: state.loading,
					empty: search ? __( 'No section matches the search.', 'nucleo' ) : __( 'No sections yet.', 'nucleo' ),
					rowKey: ( row ) => row.id,
				} );

			const actions = h( 'button', { type: 'button', className: 'nc-btn nc-btn--primary', onClick: () => setCreating( true ) }, __( 'New section', 'nucleo' ) );

			return h( UI.Page, {
				title: __( 'Global sections', 'nucleo' ),
				subtitle: __( 'Reusable blocks across several pages, such as a call to action. One edit updates all the pages that use it.', 'nucleo' ),
				actions,
			},
			h( UI.Card, null, toolbar, body ),
			creating && h( root.nucleoShared.NewDocumentModal, { postType: 'nucleo_section', onClose: () => setCreating( false ) } ) );
		}

		root.nucleoPages = root.nucleoPages || {};
		root.nucleoPages[ 'nucleo-sections' ] = SectionsPage;
	}

	if ( typeof module === 'object' && module.exports ) {
		module.exports = { statusTone, filterSections, mergeUses, showsUsage, modifiedLabel };
	}
} )();

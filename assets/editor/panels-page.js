/**
 * Scheda Pagina dell'editor Nucleo: titolo, slug, stato, data, estratto, immagine in evidenza, tassonomie,
 * attributi, commenti e tema della pagina. Nessun build: wp.element, wp.components, wp.i18n, wp.apiFetch, wp.media.
 * Il tema usa ThemeSettings di assets/admin/page-settings.js (window.nucleoPageSettings nel browser).
 * Registrata in window.nucleoPanels se window.nucleoEditor è presente. Helper puri esportati in CommonJS per i test.
 */
( function () {
	'use strict';

	const PS = typeof module === 'object' && module.exports ? require( '../admin/page-settings.js' ) : window.nucleoPageSettings;

	/* ---------- Helper puri ---------- */

	// Campi della pagina confrontati in modo semplice (uguaglianza).
	const SCALAR_KEYS = [ 'title', 'slug', 'status', 'date', 'excerpt', 'featured_media', 'parent', 'menu_order', 'comment_status' ];

	// Tassonomie della configurazione, se non passate a buildPayload.
	function defaultTaxBases() {
		const cfg = typeof window !== 'undefined' && window.nucleoEditor ? window.nucleoEditor : {};
		return ( Array.isArray( cfg.taxonomies ) ? cfg.taxonomies : [] ).map( ( t ) => t.restBase );
	}

	// Id come numeri, in ordine: due insiemi di id sono uguali se hanno gli stessi elementi.
	function sortedIds( list ) {
		return ( Array.isArray( list ) ? list : [] ).map( Number ).sort( ( a, b ) => a - b );
	}

	/**
	 * Solo i campi cambiati, nel formato della REST API. Le chiavi assenti in edited non si inviano.
	 * taxBases: restBase delle tassonomie; senza, quelle di window.nucleoEditor.
	 */
	function buildPayload( original, edited, taxBases ) {
		const before = original || {};
		const after = edited || {};
		const bases = Array.isArray( taxBases ) ? taxBases : defaultTaxBases();
		const out = {};

		SCALAR_KEYS.forEach( ( key ) => {
			if ( after[ key ] !== undefined && after[ key ] !== before[ key ] ) {
				out[ key ] = after[ key ];
			}
		} );

		// Il tema si confronta normalizzato: le chiavi mancanti valgono il default.
		if ( after.meta && after.meta._nucleo_page !== undefined ) {
			const now = PS.normalize( after.meta._nucleo_page );
			const was = PS.normalize( ( before.meta || {} )._nucleo_page );
			if ( JSON.stringify( now ) !== JSON.stringify( was ) ) {
				out.meta = { _nucleo_page: now };
			}
		}

		bases.forEach( ( base ) => {
			if ( Array.isArray( after[ base ] ) && sortedIds( after[ base ] ).join() !== sortedIds( before[ base ] ).join() ) {
				out[ base ] = sortedIds( after[ base ] );
			}
		} );

		return out;
	}

	/** Nomi di termini separati da virgole: senza spazi ai bordi, senza vuoti e senza doppioni. */
	function parseTerms( text ) {
		if ( typeof text !== 'string' ) {
			return [];
		}
		const out = [];
		text.split( ',' ).map( ( s ) => s.trim() ).forEach( ( name ) => {
			if ( name && ! out.includes( name ) ) {
				out.push( name );
			}
		} );
		return out;
	}

	// Esportazione per i test (node --test tests/js/). Nel browser il pannello si registra sotto.
	if ( typeof module === 'object' && module.exports ) {
		module.exports = { buildPayload, parseTerms };
		return;
	}

	/* ---------- Interfaccia ---------- */

	const { createElement: h, useState, useEffect } = wp.element;
	const { Button, CheckboxControl, Notice, SelectControl, Spinner, TextControl, TextareaControl, ToggleControl } = wp.components;
	const { __ } = wp.i18n;
	const { ThemeSettings } = PS;

	const fetchJson = ( path, opts ) => wp.apiFetch( Object.assign( { path }, opts || {} ) );
	const errText = ( e, fallback ) => ( e && e.message ) || fallback;
	// Campo REST in context=edit: { raw, rendered }. Senza raw, il valore stesso.
	const rawOf = ( field ) => ( field && typeof field === 'object' ? field.raw : field ) || '';
	// Data REST (aaaa-mm-ggThh:mm:ss) e campo datetime-local (aaaa-mm-ggThh:mm).
	const toLocal = ( date ) => ( typeof date === 'string' ? date.slice( 0, 16 ) : '' );
	const fromLocal = ( v ) => ( v.length === 16 ? v + ':00' : v );

	function statusOptions() {
		return [
			{ label: __( 'Draft', 'nucleo' ), value: 'draft' },
			{ label: __( 'Pending review', 'nucleo' ), value: 'pending' },
			{ label: __( 'Private', 'nucleo' ), value: 'private' },
			{ label: __( 'Published', 'nucleo' ), value: 'publish' },
		];
	}

	/** Id dei termini con questi nomi: cerca il nome esatto, altrimenti crea il termine. */
	async function resolveTerms( restBase, names ) {
		const ids = [];
		for ( const name of names ) {
			const found = await fetchJson( '/wp/v2/' + restBase + '?search=' + encodeURIComponent( name ) + '&per_page=100&context=edit' );
			const hit = ( Array.isArray( found ) ? found : [] ).find( ( t ) => t.name === name );
			const term = hit || await fetchJson( '/wp/v2/' + restBase, { method: 'POST', data: { name } } );
			ids.push( term.id );
		}
		return ids;
	}

	function PagePanel() {
		const cfg = window.nucleoEditor || {};
		const supports = cfg.supports || {};
		const taxes = Array.isArray( cfg.taxonomies ) ? cfg.taxonomies : [];
		const base = '/wp/v2/' + cfg.restBase + '/' + cfg.postId;

		const [ loading, setLoading ] = useState( true );
		const [ loadError, setLoadError ] = useState( '' );
		const [ saved, setSaved ] = useState( {} );
		const [ draft, setDraft ] = useState( {} );
		const [ tagText, setTagText ] = useState( {} ); // testo dei termini delle tassonomie piatte
		const [ savedTagText, setSavedTagText ] = useState( {} );
		const [ termOptions, setTermOptions ] = useState( {} ); // termini delle tassonomie gerarchiche
		const [ parents, setParents ] = useState( [] );
		const [ featuredUrl, setFeaturedUrl ] = useState( '' );
		const [ saving, setSaving ] = useState( false );
		const [ leaving, setLeaving ] = useState( false );
		const [ notice, setNotice ] = useState( null );

		const set = ( key, v ) => setDraft( ( d ) => ( { ...d, [ key ]: v } ) );

		/** Carica la pagina, i termini e le pagine padre. */
		const load = async () => {
			setLoading( true );
			setLoadError( '' );
			try {
				const post = await fetchJson( base + '?context=edit' );
				const values = {
					title: rawOf( post.title ),
					slug: post.slug || '',
					status: post.status || 'draft',
					date: post.date || null,
					excerpt: rawOf( post.excerpt ),
					featured_media: post.featured_media || 0,
					parent: post.parent || 0,
					menu_order: post.menu_order || 0,
					comment_status: post.comment_status === 'open' ? 'open' : 'closed',
					meta: { _nucleo_page: PS.normalize( ( post.meta || {} )._nucleo_page ) },
				};
				const texts = {};
				const options = {};
				for ( const tax of taxes ) {
					const rb = tax.restBase;
					const ids = Array.isArray( post[ rb ] ) ? post[ rb ].map( Number ) : [];
					values[ rb ] = ids;
					if ( tax.hierarchical ) {
						const list = await fetchJson( '/wp/v2/' + rb + '?per_page=100&context=edit' );
						options[ rb ] = ( Array.isArray( list ) ? list : [] ).map( ( t ) => ( { id: t.id, name: t.name } ) );
					} else {
						const names = ids.length ? await fetchJson( '/wp/v2/' + rb + '?include=' + ids.join( ',' ) + '&per_page=100&_fields=id,name' ) : [];
						texts[ rb ] = ( Array.isArray( names ) ? names : [] ).map( ( t ) => t.name ).join( ', ' );
					}
				}
				const parentList = supports.pageAttributes
					? await fetchJson( '/wp/v2/' + cfg.restBase + '?per_page=100&exclude=' + cfg.postId + '&_fields=id,title' )
					: [];
				setSaved( values );
				setDraft( values );
				setTagText( texts );
				setSavedTagText( texts );
				setTermOptions( options );
				setParents( ( Array.isArray( parentList ) ? parentList : [] ).map( ( p ) => ( {
					id: p.id,
					title: ( p.title && p.title.rendered ) || String( p.id ),
				} ) ) );
				setLoading( false );
			} catch ( e ) {
				setLoading( false );
				setLoadError( errText( e, __( 'The page could not be loaded.', 'nucleo' ) ) );
			}
		};

		useEffect( () => {
			load();
		}, [] );

		// Anteprima dell'immagine in evidenza: si richiede di nuovo quando cambia l'id.
		const featured = draft.featured_media || 0;
		useEffect( () => {
			let live = true;
			setFeaturedUrl( '' );
			if ( featured ) {
				fetchJson( '/wp/v2/media/' + featured ).then( ( m ) => {
					if ( live ) {
						setFeaturedUrl( m.source_url || '' );
					}
				} ).catch( () => {} );
			}
			return () => {
				live = false;
			};
		}, [ featured ] );

		const pick = () => {
			const frame = wp.media( {
				title: __( 'Featured image', 'nucleo' ),
				library: { type: 'image' },
				multiple: false,
				button: { text: __( 'Use as featured image', 'nucleo' ) },
			} );
			frame.on( 'select', () => {
				const att = frame.state().get( 'selection' ).first().toJSON();
				set( 'featured_media', att.id );
			} );
			frame.open();
		};

		const save = async () => {
			setSaving( true );
			setNotice( null );
			try {
				const edited = { ...draft };
				for ( const tax of taxes ) {
					const rb = tax.restBase;
					if ( ! tax.hierarchical ) {
						// Testo invariato: si tengono gli id già salvati, senza nuove ricerche.
						edited[ rb ] = tagText[ rb ] === savedTagText[ rb ]
							? saved[ rb ]
							: await resolveTerms( rb, parseTerms( tagText[ rb ] ) );
					}
				}
				const payload = buildPayload( saved, edited, taxes.map( ( t ) => t.restBase ) );
				if ( Object.keys( payload ).length === 0 ) {
					setNotice( { status: 'info', text: __( 'No changes to save.', 'nucleo' ) } );
				} else {
					await fetchJson( base, { method: 'POST', data: payload } );
					setNotice( { status: 'success', text: __( 'Page saved.', 'nucleo' ) } );
					await load();
				}
			} catch ( e ) {
				setNotice( { status: 'error', text: errText( e, __( 'The page could not be saved.', 'nucleo' ) ) } );
			}
			setSaving( false );
		};

		const back = () => {
			if ( ! window.confirm( __( 'The page goes back to the WordPress editor. The Nucleo document stays saved and you can restore it.', 'nucleo' ) ) ) {
				return;
			}
			setLeaving( true );
			setNotice( null );
			fetchJson( '/nucleo/v1/editor-mode', { method: 'POST', data: { postId: cfg.postId, mode: 'wp' } } )
				.then( ( res ) => {
					if ( ! res || ! res.editUrl ) {
						throw new Error( __( 'The page could not be returned to the WordPress editor.', 'nucleo' ) );
					}
					window.location.assign( res.editUrl );
				} )
				.catch( ( e ) => {
					setNotice( { status: 'error', text: errText( e, __( 'The page could not be returned to the WordPress editor.', 'nucleo' ) ) } );
					setLeaving( false );
				} );
		};

		const sections = [];

		sections.push( h( 'div', { key: 'general', className: 'nc-stack' },
			h( 'h3', null, __( 'General', 'nucleo' ) ),
			h( TextControl, { label: __( 'Title', 'nucleo' ), value: draft.title || '', onChange: ( v ) => set( 'title', v ) } ),
			h( TextControl, { label: __( 'Slug', 'nucleo' ), value: draft.slug || '', onChange: ( v ) => set( 'slug', v ) } ),
			cfg.permalink ? h( 'p', { className: 'nc-help' },
				h( 'a', { href: cfg.permalink, target: '_blank', rel: 'noopener noreferrer' }, cfg.permalink ) ) : null,
			h( SelectControl, { label: __( 'Status', 'nucleo' ), value: draft.status || 'draft', options: statusOptions(), onChange: ( v ) => set( 'status', v ) } ),
			h( TextControl, {
				label: __( 'Date', 'nucleo' ),
				type: 'datetime-local',
				value: toLocal( draft.date ),
				onChange: ( v ) => set( 'date', v ? fromLocal( v ) : draft.date ),
			} ) ) );

		if ( supports.excerpt ) {
			sections.push( h( 'div', { key: 'excerpt', className: 'nc-stack' },
				h( 'h3', null, __( 'Excerpt', 'nucleo' ) ),
				h( TextareaControl, { label: __( 'Excerpt', 'nucleo' ), value: draft.excerpt || '', onChange: ( v ) => set( 'excerpt', v ) } ) ) );
		}

		if ( supports.thumbnail ) {
			sections.push( h( 'div', { key: 'thumbnail', className: 'nc-stack' },
				h( 'h3', null, __( 'Featured image', 'nucleo' ) ),
				featuredUrl ? h( 'img', { src: featuredUrl, alt: '', className: 'nucleo-editor__preview' } ) : null,
				h( 'p', null, draft.featured_media ? 'ID ' + draft.featured_media : __( 'No image selected.', 'nucleo' ) ),
				h( Button, { variant: 'secondary', size: 'small', onClick: pick }, __( 'Choose image', 'nucleo' ) ),
				' ',
				draft.featured_media ? h( Button, { variant: 'link', isDestructive: true, onClick: () => set( 'featured_media', 0 ) }, __( 'Remove', 'nucleo' ) ) : null ) );
		}

		if ( taxes.length > 0 ) {
			sections.push( h( 'div', { key: 'taxonomies', className: 'nc-stack' },
				h( 'h3', null, __( 'Taxonomies', 'nucleo' ) ),
				taxes.map( ( tax ) => {
					const rb = tax.restBase;
					if ( ! tax.hierarchical ) {
						return h( TextControl, {
							key: rb,
							label: tax.label,
							help: __( 'Separate the names with commas.', 'nucleo' ),
							value: tagText[ rb ] || '',
							onChange: ( v ) => setTagText( ( prev ) => ( { ...prev, [ rb ]: v } ) ),
						} );
					}
					const chosen = Array.isArray( draft[ rb ] ) ? draft[ rb ] : [];
					return h( 'div', { key: rb, className: 'nc-stack' },
						h( 'h4', null, tax.label ),
						( termOptions[ rb ] || [] ).map( ( t ) => h( CheckboxControl, {
							key: t.id,
							label: t.name,
							checked: chosen.includes( t.id ),
							onChange: ( on ) => set( rb, on ? [ ...chosen, t.id ] : chosen.filter( ( x ) => x !== t.id ) ),
						} ) ) );
				} ) ) );
		}

		if ( supports.pageAttributes ) {
			sections.push( h( 'div', { key: 'attributes', className: 'nc-stack' },
				h( 'h3', null, __( 'Attributes', 'nucleo' ) ),
				h( SelectControl, {
					label: __( 'Parent', 'nucleo' ),
					value: String( draft.parent || 0 ),
					options: [ { label: __( 'None', 'nucleo' ), value: '0' } ].concat(
						parents.map( ( p ) => ( { label: p.title, value: String( p.id ) } ) ) ),
					onChange: ( v ) => set( 'parent', Number( v ) ),
				} ),
				h( TextControl, {
					label: __( 'Menu order', 'nucleo' ),
					type: 'number',
					value: String( draft.menu_order || 0 ),
					onChange: ( v ) => set( 'menu_order', parseInt( v, 10 ) || 0 ),
				} ) ) );
		}

		if ( supports.comments ) {
			sections.push( h( 'div', { key: 'discussion', className: 'nc-stack' },
				h( 'h3', null, __( 'Discussion', 'nucleo' ) ),
				h( ToggleControl, {
					label: __( 'Allow comments', 'nucleo' ),
					checked: draft.comment_status === 'open',
					onChange: ( on ) => set( 'comment_status', on ? 'open' : 'closed' ),
				} ) ) );
		}

		sections.push( h( 'div', { key: 'theme', className: 'nc-stack' },
			h( 'h3', null, __( 'Theme', 'nucleo' ) ),
			h( ThemeSettings, {
				value: draft.meta && draft.meta._nucleo_page,
				layouts: cfg.layouts,
				onChange: ( next ) => setDraft( ( d ) => ( { ...d, meta: { ...( d.meta || {} ), _nucleo_page: next } } ) ),
			} ) ) );

		let content = null;
		if ( loading ) {
			content = h( Spinner );
		} else if ( ! loadError ) {
			content = h( 'div', { className: 'nc-stack' },
				sections,
				h( 'div', null,
					h( Button, {
						variant: 'primary',
						className: 'nc-btn nc-btn--primary',
						isBusy: saving,
						disabled: saving,
						onClick: save,
					}, __( 'Save page', 'nucleo' ) ) ) );
		}

		return h( 'div', { className: 'nucleo-panel nucleo-panel-page' },
			loadError && h( Notice, { status: 'error', isDismissible: false }, loadError ),
			notice && h( Notice, { status: notice.status, isDismissible: false }, notice.text ),
			content,
			h( 'div', { className: 'nucleo-panel-footer' },
				h( Button, {
					variant: 'secondary',
					className: 'nc-btn nc-btn--secondary',
					disabled: leaving,
					onClick: back,
				}, __( 'Back to the WordPress editor', 'nucleo' ) ) ) );
	}

	if ( window.nucleoEditor ) {
		window.nucleoPanels = window.nucleoPanels || [];
		window.nucleoPanels.unshift( { // prima scheda della colonna Pagina
			id: 'page',
			title: __( 'Page settings', 'nucleo' ),
			render: ( props ) => h( PagePanel, props ),
		} );
	}
} )();

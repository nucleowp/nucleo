/**
 * Impostazioni di pagina di Nucleo: layout per area, titolo, larghezza, classi del body.
 * Nessun build: wp.element, wp.components, wp.data, wp.apiFetch, wp.i18n.
 * L'app dell'editor a blocchi parte solo se window.nucleoBlockEditor è impostato.
 * Nei test (node --test) si esporta con module.exports; nel browser è window.nucleoPageSettings.
 */
( function () {
	'use strict';

	const root = typeof window !== 'undefined' ? window : {};
	const __ = ( s, domain ) => ( root.wp && root.wp.i18n ? root.wp.i18n.__( s, domain ) : s );

	/* ---------- Funzioni pure ---------- */

	/** Aree di layout della pagina, nell'ordine dei campi. */
	const AREAS = [ 'header', 'before-content', 'single', 'after-content', 'footer' ];
	const WIDTHS = [ 'normal', 'wide', 'full' ];

	/** Valore di un'area: 'auto', 'none' o un id di layout intero positivo, anche come stringa. Altro: 'auto'. */
	function normalizeLayout( v ) {
		if ( v === 'auto' || v === 'none' ) {
			return v;
		}
		const n = typeof v === 'string' && /^\d+$/.test( v ) ? Number( v ) : v;
		return Number.isInteger( n ) && n > 0 ? n : 'auto';
	}

	/** Impostazioni di pagina complete, con la forma di @pagemeta: le chiavi mancanti prendono il default. */
	function normalize( raw ) {
		const src = raw && typeof raw === 'object' ? raw : {};
		const given = src.layouts && typeof src.layouts === 'object' ? src.layouts : {};
		const layouts = {};
		AREAS.forEach( ( area ) => {
			layouts[ area ] = normalizeLayout( given[ area ] );
		} );
		return {
			layouts,
			hideTitle: src.hideTitle === true,
			width: WIDTHS.includes( src.width ) ? src.width : 'normal',
			bodyClass: typeof src.bodyClass === 'string' ? src.bodyClass : '',
		};
	}

	/** Opzioni del campo di un'area: regole del sito, nessun layout, poi i layout di quell'area. */
	function layoutOptions( area, layouts ) {
		const own = ( Array.isArray( layouts ) ? layouts : [] )
			.filter( ( l ) => l && l.area === area )
			.map( ( l ) => ( { label: l.title, value: String( l.id ) } ) );
		return [
			{ label: __( 'Automatic (site rules)', 'nucleo' ), value: 'auto' },
			{ label: __( 'None', 'nucleo' ), value: 'none' },
			...own,
		];
	}

	const api = { AREAS, normalize, layoutOptions };

	/* ---------- Componenti (solo con window.wp.element) ---------- */

	const el = root.wp && root.wp.element;
	if ( el ) {
		const { createElement: h, useState } = el;

		/** Etichetta del campo di un'area. Il campo del template singolo si chiama «Template». */
		function areaLabel( area ) {
			const labels = {
				header: __( 'Header', 'nucleo' ),
				'before-content': __( 'Before content', 'nucleo' ),
				single: __( 'Template', 'nucleo' ),
				'after-content': __( 'After content', 'nucleo' ),
				footer: __( 'Footer', 'nucleo' ),
			};
			return labels[ area ];
		}

		/** Campi delle impostazioni di pagina. Ogni modifica passa a onChange l'oggetto intero normalizzato. */
		function ThemeSettings( { value, onChange, layouts } ) {
			const { SelectControl, ToggleControl, TextControl } = root.wp.components;
			const s = normalize( value );
			const update = ( patch ) => onChange( normalize( { ...s, ...patch } ) );
			const setArea = ( area, v ) => update( { layouts: { ...s.layouts, [ area ]: normalizeLayout( v ) } } );

			return h( 'div', { className: 'nc-stack' },
				AREAS.map( ( area ) => h( SelectControl, {
					key: area,
					label: areaLabel( area ),
					value: String( s.layouts[ area ] ),
					options: layoutOptions( area, layouts ),
					onChange: ( v ) => setArea( area, v ),
				} ) ),
				h( ToggleControl, {
					label: __( 'Hide page title', 'nucleo' ),
					checked: s.hideTitle,
					onChange: ( v ) => update( { hideTitle: v } ),
				} ),
				h( SelectControl, {
					label: __( 'Content width', 'nucleo' ),
					value: s.width,
					options: [
						{ label: __( 'Normal', 'nucleo' ), value: 'normal' },
						{ label: __( 'Wide', 'nucleo' ), value: 'wide' },
						{ label: __( 'Full width', 'nucleo' ), value: 'full' },
					],
					onChange: ( v ) => update( { width: v } ),
				} ),
				h( TextControl, {
					label: __( 'Body CSS classes', 'nucleo' ),
					value: s.bodyClass,
					onChange: ( v ) => update( { bodyClass: v } ),
				} ) );
		}

		/** Salva le modifiche in attesa, se ci sono, e aspetta la fine del salvataggio. */
		async function saveAndWait() {
			if ( root.wp.data.select( 'core/editor' ).isEditedPostDirty() ) {
				await root.wp.data.dispatch( 'core/editor' ).savePost();
			}
			await new Promise( ( resolve ) => {
				const idle = () => ! root.wp.data.select( 'core/editor' ).isSavingPost();
				if ( idle() ) {
					resolve();
					return;
				}
				const unsubscribe = root.wp.data.subscribe( () => {
					if ( idle() ) {
						unsubscribe();
						resolve();
					}
				} );
			} );
			if ( root.wp.data.select( 'core/editor' ).isEditedPostDirty() ) {
				throw new Error( __( 'The page could not be saved.', 'nucleo' ) );
			}
		}

		/** Modalità wp: pulsante che salva e passa a Nucleo. Modalità nucleo: link all'editor Nucleo. */
		function EditWithNucleo( { postId, mode, nucleoUrl } ) {
			const [ busy, setBusy ] = useState( false );
			const [ error, setError ] = useState( null );
			if ( mode === 'nucleo' ) {
				return h( 'a', { className: 'nc-btn nc-btn--primary', href: nucleoUrl }, __( 'Edit with Nucleo', 'nucleo' ) );
			}
			const start = () => {
				setBusy( true );
				setError( null );
				saveAndWait()
					.then( () => root.wp.apiFetch( { path: '/nucleo/v1/editor-mode', method: 'POST', data: { postId, mode: 'nucleo' } } ) )
					.then( ( res ) => {
						if ( ! res || ! res.editUrl ) {
							throw new Error( __( 'The page could not be opened in Nucleo.', 'nucleo' ) );
						}
						root.location.assign( res.editUrl );
					} )
					.catch( ( e ) => {
						setError( ( e && e.message ) || __( 'The page could not be opened in Nucleo.', 'nucleo' ) );
						setBusy( false );
					} );
			};
			return h( 'div', null,
				h( 'button', { type: 'button', className: 'nc-btn nc-btn--primary', disabled: busy, onClick: start }, __( 'Edit with Nucleo', 'nucleo' ) ),
				error && h( 'p', { className: 'nc-field-error', role: 'alert' }, error ) );
		}

		/** Impostazioni nel meta _nucleo_page della pagina: si salvano con il pulsante Aggiorna. */
		function MetaSettings( { layouts } ) {
			const meta = root.wp.data.useSelect( ( select ) => select( 'core/editor' ).getEditedPostAttribute( 'meta' ), [] ) || {};
			const { editPost } = root.wp.data.useDispatch( 'core/editor' );
			return h( ThemeSettings, {
				value: meta._nucleo_page,
				layouts,
				onChange: ( next ) => editPost( { meta: { ...meta, _nucleo_page: next } } ),
			} );
		}

		/** Modalità nucleo: avviso nella pagina, con ritorno all'editor WordPress. */
		function NucleoBanner( { postId, nucleoUrl } ) {
			const [ busy, setBusy ] = useState( false );
			const [ error, setError ] = useState( null );
			const back = () => {
				if ( ! root.confirm( __( 'Return this page to the WordPress editor?', 'nucleo' ) ) ) {
					return;
				}
				setBusy( true );
				setError( null );
				root.wp.apiFetch( { path: '/nucleo/v1/editor-mode', method: 'POST', data: { postId, mode: 'wp' } } )
					.then( () => root.location.reload() )
					.catch( ( e ) => {
						setError( ( e && e.message ) || __( 'The page could not be returned to the WordPress editor.', 'nucleo' ) );
						setBusy( false );
					} );
			};
			return h( 'div', { className: 'nucleo-admin' },
				h( 'div', { className: 'nc-card' }, h( 'div', { className: 'nc-card__body nc-stack' },
					h( 'h2', null, __( 'This page is built with Nucleo', 'nucleo' ) ),
					h( 'p', null, __( 'The content is edited in the Nucleo editor. Title, settings and fields stay here.', 'nucleo' ) ),
					h( 'div', { className: 'nc-row' },
						h( 'a', { className: 'nc-btn nc-btn--primary', href: nucleoUrl }, __( 'Edit with Nucleo', 'nucleo' ) ),
						h( 'button', { type: 'button', className: 'nc-btn nc-btn--secondary', disabled: busy, onClick: back },
							__( 'Back to the WordPress editor', 'nucleo' ) ) ),
					error && h( 'p', { className: 'nc-field-error', role: 'alert' }, error ) ) ) );
		}

		/** Crea il React root (o usa render sulle versioni vecchie di wp.element). */
		function mount( node, container ) {
			if ( el.createRoot ) {
				el.createRoot( container ).render( node );
			} else {
				el.render( node, container );
			}
		}

		/** Esegue cb con il nodo .editor-visual-editor, appena esiste. */
		function whenVisualEditor( cb ) {
			const find = () => root.document.querySelector( '.editor-visual-editor' );
			const node = find();
			if ( node ) {
				cb( node );
				return;
			}
			const unsubscribe = root.wp.data.subscribe( () => {
				const later = find();
				if ( later ) {
					unsubscribe();
					cb( later );
				}
			} );
		}

		function ready( fn ) {
			if ( root.document.readyState === 'loading' ) {
				root.document.addEventListener( 'DOMContentLoaded', fn );
			} else {
				fn();
			}
		}

		/** Avvio dell'app dell'editor a blocchi: campi nel meta box, avviso in modalità nucleo. */
		function startBlockEditor( cfg ) {
			const { postId, mode, nucleoUrl, layouts } = cfg;
			// Pannello "Nucleo" nella scheda Pagina della barra laterale: i riquadri sotto il contenuto lo schiaccerebbero.
			const Panel = ( root.wp.editor && root.wp.editor.PluginDocumentSettingPanel ) ||
				( root.wp.editPost && root.wp.editPost.PluginDocumentSettingPanel );
			if ( Panel && root.wp.plugins ) {
				root.wp.plugins.registerPlugin( 'nucleo-page-settings', {
					render: () => h( Panel, { name: 'nucleo-page-settings', title: __( 'Nucleo', 'nucleo' ), className: 'nucleo-document-panel' },
						h( 'div', { className: 'nucleo-admin' },
							h( 'div', { className: 'nc-stack' },
								h( 'div', { className: 'nc-row' }, h( EditWithNucleo, { postId, mode, nucleoUrl } ) ),
								h( MetaSettings, { layouts } ) ) ) ),
				} );
			}
			if ( mode === 'nucleo' ) {
				root.document.body.classList.add( 'nucleo-mode-nucleo' );
				whenVisualEditor( ( node ) => {
					const box = root.document.createElement( 'div' );
					box.className = 'nucleo-mode-banner';
					node.prepend( box );
					mount( h( NucleoBanner, { postId, nucleoUrl } ), box );
				} );
			}
		}

		Object.assign( api, { ThemeSettings } );

		if ( root.nucleoBlockEditor ) {
			ready( () => startBlockEditor( root.nucleoBlockEditor ) );
		}
	}

	if ( typeof module === 'object' && module.exports ) {
		module.exports = api;
	} else {
		root.nucleoPageSettings = api;
	}
} )();

/**
 * Schede AI dell'editor Nucleo: attività recenti e collegamenti dei campi.
 * Nessun build: wp.element, wp.components, wp.i18n. Registrate in window.nucleoPanels.
 */
( function () {
	'use strict';

	const { createElement: h, useState, useEffect } = wp.element;
	const { Button, Notice, SelectControl, TextControl } = wp.components;
	const { __ } = wp.i18n;
	// Diff tra revisioni (assets/shared/doc-diff.js, dipendenza di questo script).
	const { changeText, entryChanges } = window.nucleoDocDiff;

	const TEXT_TYPES = [ 'string', 'inline-rich-text' ];

	const msg = ( res, fallback ) => ( res && res.error && res.error.message ) || fallback;

	/* ---------- Attività AI ---------- */

	function AuditPanel( { call } ) {
		const [ entries, setEntries ] = useState( [] );
		const [ error, setError ] = useState( '' );
		const [ busy, setBusy ] = useState( false );
		// Revisioni del documento, lette alla prima apertura di "Changes"; openId è la voce aperta.
		const [ revisions, setRevisions ] = useState( null );
		const [ openId, setOpenId ] = useState( null );
		const postId = window.nucleoEditor && window.nucleoEditor.postId;

		/** Apre o chiude le modifiche di una voce del documento corrente. */
		const toggleChanges = ( e ) => {
			if ( openId === e.id ) {
				setOpenId( null );
				return;
			}
			setOpenId( e.id );
			if ( revisions ) {
				return;
			}
			call( 'nucleo/doc-revisions', { postId: e.post_id, withDocs: true } ).then( ( res ) => {
				if ( res.ok ) {
					setRevisions( res.revisions || [] );
				} else {
					setError( msg( res, __( 'Revisions not loaded.', 'nucleo' ) ) );
				}
			} );
		};

		/** Modifiche introdotte dalla voce, sotto la sua riga. */
		const changesOf = ( e ) => {
			if ( ! revisions ) {
				return error ? null : h( 'p', null, __( 'Loading...', 'nucleo' ) );
			}
			const list = entryChanges( revisions, e );
			return list.length
				? h( 'ul', null, list.map( ( c, i ) => h( 'li', { key: i }, changeText( c ) ) ) )
				: h( 'p', null, __( 'No changes in the document.', 'nucleo' ) );
		};

		const load = () => {
			setBusy( true );
			return call( 'nucleo/audit-list', { limit: 50 } ).then( ( res ) => {
				setBusy( false );
				if ( res.ok ) {
					setEntries( res.entries || [] );
					setError( '' );
				} else {
					setError( msg( res, __( 'Activity not loaded.', 'nucleo' ) ) );
				}
			} );
		};

		useEffect( () => {
			load();
		}, [] );

		return h( 'div', { className: 'nucleo-editor__audit' },
			h( Button, { variant: 'secondary', size: 'small', disabled: busy, isBusy: busy, onClick: () => load() }, __( 'Refresh', 'nucleo' ) ),
			error && h( Notice, { status: 'error', isDismissible: false }, error ),
			h( 'ul', null, entries.map( ( e ) => h( 'li', { key: e.id, className: e.ok ? '' : 'is-error' },
				h( 'span', null, new Date( e.time * 1000 ).toLocaleString() ), ' · ',
				h( 'strong', null, e.ability ), ' · ',
				h( 'span', null, e.ok ? __( 'Succeeded', 'nucleo' ) : __( 'Error', 'nucleo' ) ), ' · ',
				h( 'span', null, e.summary ),
				e.post_id && e.post_id === postId && h( Button, { variant: 'secondary', size: 'small', 'aria-expanded': String( openId === e.id ), onClick: () => toggleChanges( e ) }, __( 'Changes', 'nucleo' ) ),
				openId === e.id && h( 'div', null, changesOf( e ) )
			) ) ),
			! busy && ! error && entries.length === 0 && h( 'p', null, __( 'No activity recorded.', 'nucleo' ) )
		);
	}

	/* ---------- Collegamenti ---------- */

	function LinksPanel( { call, selected, manifest, setField } ) {
		const [ fields, setFields ] = useState( [] );
		const [ loaded, setLoaded ] = useState( false );
		const [ error, setError ] = useState( '' );
		// Tipo di contenuto dal config dell'editor (window.nucleoEditor.postType).
		const postType = ( window.nucleoEditor && window.nucleoEditor.postType ) || '';

		useEffect( () => {
			let live = true;
			if ( !postType ) {
				return undefined;
			}
			call( 'nucleo/bind-fields', { postType } ).then( ( res ) => {
				if ( !live ) {
					return;
				}
				setLoaded( true );
				if ( res.ok ) {
					setFields( res.fields || [] );
					setError( '' );
				} else {
					setError( msg( res, __( 'Fields not loaded.', 'nucleo' ) ) );
				}
			} );
			return () => {
				live = false;
			};
		}, [ postType ] );

		if ( !selected ) {
			return h( 'p', null, __( 'Select an element from the structure.', 'nucleo' ) );
		}
		if ( !manifest ) {
			return h( 'p', null, __( 'Loading block...', 'nucleo' ) );
		}
		const bindable = manifest.supports && manifest.supports.bind;
		if ( !bindable || Object.keys( bindable ).length === 0 ) {
			return h( 'p', null, __( 'This block has no bindable fields.', 'nucleo' ) );
		}

		const bind = selected.bind || {};
		const textual = Object.entries( manifest.content || {} ).filter( ( [ , def ] ) => def && TEXT_TYPES.includes( def.type ) );
		const options = [ { label: __( 'None', 'nucleo' ), value: '' } ].concat(
			fields.map( ( f ) => ( { label: f.source + ': ' + f.label, value: f.source + '|' + f.key } ) )
		);

		// node.bind è un oggetto con chiavi letterali ('content.text'): si scrive l'oggetto intero, non un percorso.
		const writeBind = ( key, entry ) => {
			const next = { ...bind };
			if ( entry === null ) {
				delete next[ key ];
			} else {
				next[ key ] = entry;
			}
			setField( 'bind', Object.keys( next ).length ? next : {} );
		};

		return h( 'div', { className: 'nucleo-editor__links' },
			error && h( Notice, { status: 'error', isDismissible: false }, error ),
			loaded && ! error && fields.length === 0 && h( 'p', null, __( 'No fields available for this content type.', 'nucleo' ) ),
			textual.map( ( [ key, def ] ) => {
				const current = bind[ key ] || null;
				const value = current ? current.source + '|' + current.field : '';
				return h( 'fieldset', { key, className: 'nucleo-editor__group' },
					h( 'legend', null, def.label || key ),
					h( SelectControl, {
						label: __( 'Bound field', 'nucleo' ),
						value,
						options,
						onChange: ( v ) => {
							if ( !v ) {
								writeBind( key, null );
								return;
							}
							const cut = v.indexOf( '|' );
							writeBind( key, {
								source: v.slice( 0, cut ),
								field: v.slice( cut + 1 ),
								fallback: current ? current.fallback || '' : '',
							} );
						},
					} ),
					h( TextControl, {
						label: __( 'Fallback text', 'nucleo' ),
						disabled: !current,
						value: current ? current.fallback || '' : '',
						onChange: ( v ) => writeBind( key, { source: current.source, field: current.field, fallback: v } ),
					} )
				);
			} )
		);
	}

	window.nucleoPanels = window.nucleoPanels || [];
	window.nucleoPanels.push( {
		id: 'attivita',
		title: __( 'AI activity', 'nucleo' ),
		render: ( props ) => h( AuditPanel, props ),
	} );
	window.nucleoPanels.push( {
		id: 'collegamenti',
		title: __( 'Links', 'nucleo' ),
		render: ( props ) => h( LinksPanel, props ),
	} );
} )();

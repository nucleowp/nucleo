/**
 * Scheda Fields dell'editor Nucleo: valori dei campi del modello del contenuto aperto.
 * Nessun build: wp.element, wp.components, wp.i18n, wp.apiFetch, wp.media. Registrata in window.nucleoPanels
 * solo se window.nucleoEditor.hasFields è vero. Helper puri esportati in CommonJS per i test.
 */
( function () {
	'use strict';

	/* ---------- Helper puri ---------- */

	const isPlainObject = ( v ) => v !== null && typeof v === 'object' && ! Array.isArray( v );

	function deepEqual( a, b ) {
		if ( a === b ) {
			return true;
		}
		if ( Array.isArray( a ) && Array.isArray( b ) ) {
			return a.length === b.length && a.every( ( v, i ) => deepEqual( v, b[ i ] ) );
		}
		if ( isPlainObject( a ) && isPlainObject( b ) ) {
			const ka = Object.keys( a );
			const kb = Object.keys( b );
			return ka.length === kb.length && ka.every( ( k ) => Object.prototype.hasOwnProperty.call( b, k ) && deepEqual( a[ k ], b[ k ] ) );
		}
		return false;
	}

	// Assente o stringa vuota equivalgono a null: il server svuota il campo con un valore vuoto.
	const blank = ( v ) => ( v === undefined || v === '' ? null : v );

	// Solo le chiavi cambiate; una chiave rimossa o svuotata va a null.
	function changedValues( original, current ) {
		const before = original || {};
		const after = current || {};
		const out = {};
		new Set( [ ...Object.keys( before ), ...Object.keys( after ) ] ).forEach( ( key ) => {
			const was = blank( before[ key ] );
			const now = blank( after[ key ] );
			if ( ! deepEqual( was, now ) ) {
				out[ key ] = now;
			}
		} );
		return out;
	}

	// Errori del server (path, message) come mappa path → messaggio; per path tiene il primo.
	function errorsByPath( errors ) {
		const out = {};
		( Array.isArray( errors ) ? errors : [] ).forEach( ( e ) => {
			if ( e && typeof e.path === 'string' && ! Object.prototype.hasOwnProperty.call( out, e.path ) ) {
				out[ e.path ] = e.message || '';
			}
		} );
		return out;
	}

	// Riga nuova di un repeater: ogni sottocampo a null.
	function emptyRow( field ) {
		const row = {};
		( field.fields || [] ).forEach( ( sub ) => {
			row[ sub.key ] = null;
		} );
		return row;
	}

	// Il repeater accetta una riga in più solo se non ha raggiunto max.
	function canAddRow( field, rows ) {
		if ( ! Number.isFinite( field.max ) ) {
			return true;
		}
		return ( Array.isArray( rows ) ? rows.length : 0 ) < field.max;
	}

	// Esportazione per i test (node --test tests/js/). Nel browser il pannello si registra sotto.
	if ( typeof module !== 'undefined' && module.exports ) {
		module.exports = { changedValues, errorsByPath, emptyRow, canAddRow };
		return;
	}

	/* ---------- Interfaccia ---------- */

	const { createElement: h, useState, useEffect } = wp.element;
	const { Button, Notice, SelectControl, TextControl, TextareaControl, ToggleControl } = wp.components;
	const { __ } = wp.i18n;

	const msg = ( res, fallback ) => ( res && res.error && res.error.message ) || fallback;
	const cloneValues = ( v ) => JSON.parse( JSON.stringify( v || {} ) );

	function ImageControl( { label, value, onChange } ) {
		const [ preview, setPreview ] = useState( { id: 0, url: '' } );

		useEffect( () => {
			let live = true;
			if ( ! value ) {
				return undefined;
			}
			wp.apiFetch( { path: '/wp/v2/media/' + value } ).then( ( m ) => {
				if ( live ) {
					setPreview( { id: value, url: m.source_url || '' } );
				}
			} ).catch( () => {} );
			return () => {
				live = false;
			};
		}, [ value ] );

		const url = preview.id === value ? preview.url : '';

		const pick = () => {
			const frame = wp.media( {
				title: label,
				library: { type: 'image' },
				multiple: false,
				button: { text: __( 'Use this image', 'nucleo' ) },
			} );
			frame.on( 'select', () => {
				const att = frame.state().get( 'selection' ).first().toJSON();
				onChange( att.id );
				setPreview( { id: att.id, url: att.url || '' } );
			} );
			frame.open();
		};

		return h( 'fieldset', { className: 'nucleo-editor__group' },
			h( 'legend', null, label ),
			url && h( 'img', { src: url, alt: '', className: 'nucleo-editor__preview' } ),
			h( 'p', null, value ? 'ID ' + value : __( 'No image selected.', 'nucleo' ) ),
			h( Button, { variant: 'secondary', size: 'small', onClick: pick }, value ? __( 'Replace image', 'nucleo' ) : __( 'Choose image', 'nucleo' ) ),
			' ',
			value && h( Button, { variant: 'link', isDestructive: true, onClick: () => onChange( null ) }, __( 'Remove', 'nucleo' ) )
		);
	}

	function RelationControl( { label, field, value, onChange } ) {
		const multiple = !! field.multiple;
		const chosen = multiple ? ( Array.isArray( value ) ? value : [] ) : ( value ? [ value ] : [] );
		const types = Array.isArray( field.postTypes ) ? field.postTypes : [];
		const [ query, setQuery ] = useState( '' );
		const [ results, setResults ] = useState( [] );
		const [ titles, setTitles ] = useState( {} );
		const [ error, setError ] = useState( '' );

		const search = () => {
			const subtype = types.length === 1 ? types[ 0 ] : 'any';
			wp.apiFetch( { path: '/wp/v2/search?search=' + encodeURIComponent( query ) + '&subtype=' + encodeURIComponent( subtype ) } ).then( ( list ) => {
				const items = Array.isArray( list ) ? list : [];
				setResults( items );
				setTitles( ( t ) => items.reduce( ( acc, item ) => {
					acc[ item.id ] = item.title;
					return acc;
				}, { ...t } ) );
				setError( '' );
			} ).catch( () => setError( __( 'Search failed.', 'nucleo' ) ) );
		};

		const pick = ( id ) => {
			if ( multiple ) {
				if ( ! chosen.includes( id ) ) {
					onChange( [ ...chosen, id ] );
				}
			} else {
				onChange( id );
			}
		};

		const drop = ( id ) => {
			if ( multiple ) {
				onChange( chosen.filter( ( x ) => x !== id ) );
			} else {
				onChange( null );
			}
		};

		return h( 'fieldset', { className: 'nucleo-editor__group' },
			h( 'legend', null, label ),
			h( 'ul', null, chosen.map( ( id ) => h( 'li', { key: id },
				h( 'span', null, titles[ id ] || '#' + id ), ' ',
				h( Button, { variant: 'link', isDestructive: true, onClick: () => drop( id ) }, __( 'Remove', 'nucleo' ) )
			) ) ),
			h( TextControl, { label: __( 'Search', 'nucleo' ), value: query, onChange: setQuery } ),
			h( Button, { variant: 'secondary', size: 'small', onClick: search }, __( 'Search', 'nucleo' ) ),
			error && h( Notice, { status: 'error', isDismissible: false }, error ),
			h( 'ul', null, results.map( ( item ) => h( 'li', { key: item.id },
				h( 'span', null, item.title ), ' ',
				h( Button, { variant: 'link', disabled: chosen.includes( item.id ), onClick: () => pick( item.id ) }, __( 'Choose', 'nucleo' ) )
			) ) )
		);
	}

	// Il repeater tiene le righe come lista; i sottocampi usano lo stesso controllo, con percorso /campo/i/sottocampo.
	function RepeaterControl( { label, field, value, onChange, path, errors } ) {
		const rows = Array.isArray( value ) ? value : [];

		const update = ( i, key, cell ) => {
			const next = rows.slice();
			next[ i ] = { ...rows[ i ], [ key ]: cell };
			onChange( next );
		};

		const move = ( i, d ) => {
			const j = i + d;
			if ( j < 0 || j >= rows.length ) {
				return;
			}
			const next = rows.slice();
			[ next[ i ], next[ j ] ] = [ next[ j ], next[ i ] ];
			onChange( next );
		};

		const remove = ( i ) => onChange( rows.filter( ( _row, k ) => k !== i ) );
		const add = () => onChange( [ ...rows, emptyRow( field ) ] );

		return h( 'fieldset', { className: 'nucleo-editor__group' },
			h( 'legend', null, label ),
			rows.map( ( row, i ) => h( 'div', { key: i, className: 'nucleo-editor__row' },
				( field.fields || [] ).map( ( sub ) => h( FieldControl, {
					key: sub.key,
					field: sub,
					value: ( row || {} )[ sub.key ],
					onChange: ( cell ) => update( i, sub.key, cell ),
					path: path + '/' + i + '/' + sub.key,
					errors,
				} ) ),
				h( Button, { variant: 'tertiary', size: 'small', disabled: i === 0, onClick: () => move( i, -1 ) }, __( 'Move up', 'nucleo' ) ),
				' ',
				h( Button, { variant: 'tertiary', size: 'small', disabled: i === rows.length - 1, onClick: () => move( i, 1 ) }, __( 'Move down', 'nucleo' ) ),
				' ',
				h( Button, { variant: 'link', isDestructive: true, onClick: () => remove( i ) }, __( 'Remove', 'nucleo' ) )
			) ),
			h( Button, { variant: 'secondary', size: 'small', disabled: ! canAddRow( field, rows ), onClick: add }, __( 'Add row', 'nucleo' ) )
		);
	}

	function renderControl( field, value, onChange, path, errors ) {
		const label = field.label || field.key;
		switch ( field.type ) {
			case 'textarea':
				return h( TextareaControl, { label, value: value || '', onChange } );
			case 'number':
				return h( TextControl, {
					label,
					type: 'number',
					value: value === null || value === undefined ? '' : String( value ),
					onChange: ( v ) => {
						const n = Number( v );
						onChange( v === '' || ! Number.isFinite( n ) ? null : n );
					},
				} );
			case 'date':
				return h( TextControl, { label, type: 'date', value: value || '', onChange: ( v ) => onChange( v || null ) } );
			case 'select': {
				const options = ( field.options || [] ).map( ( o ) => ( { label: o.label || String( o.value ), value: String( o.value ) } ) );
				if ( field.multiple ) {
					return h( SelectControl, {
						label,
						multiple: true,
						value: Array.isArray( value ) ? value.map( String ) : [],
						options,
						onChange: ( v ) => onChange( v.length ? v : null ),
					} );
				}
				return h( SelectControl, {
					label,
					value: value ? String( value ) : '',
					options: [ { label: __( 'None', 'nucleo' ), value: '' } ].concat( options ),
					onChange: ( v ) => onChange( v === '' ? null : v ),
				} );
			}
			case 'boolean':
				return h( ToggleControl, { label, checked: !! value, onChange } );
			case 'image':
				return h( ImageControl, { label, value, onChange } );
			case 'relation':
				return h( RelationControl, { label, field, value, onChange } );
			case 'repeater':
				return h( RepeaterControl, { label, field, value, onChange, path, errors } );
			default:
				return h( TextControl, {
					label,
					type: field.type === 'url' || field.type === 'email' ? field.type : 'text',
					value: value || '',
					onChange,
				} );
		}
	}

	function FieldControl( { field, value, onChange, path, errors } ) {
		const error = errors[ path ];
		return h( 'div', { className: 'nucleo-editor__field' + ( error ? ' is-error' : '' ) },
			renderControl( field, value, onChange, path, errors ),
			error && h( 'p', { className: 'nucleo-editor__field-error' }, error )
		);
	}

	function FieldsPanel( { call, postId } ) {
		const [ fields, setFields ] = useState( [] );
		const [ saved, setSaved ] = useState( {} );
		const [ draft, setDraft ] = useState( {} );
		const [ loading, setLoading ] = useState( true );
		const [ saving, setSaving ] = useState( false );
		const [ loadError, setLoadError ] = useState( '' );
		const [ notice, setNotice ] = useState( null );
		const [ errors, setErrors ] = useState( {} );

		const load = () => {
			setLoading( true );
			return call( 'nucleo/fields-get', { postId } ).then( ( res ) => {
				setLoading( false );
				if ( res.ok ) {
					setFields( res.fields || [] );
					setSaved( res.values || {} );
					setDraft( cloneValues( res.values ) );
					setLoadError( '' );
				} else {
					setLoadError( msg( res, __( 'Fields not loaded.', 'nucleo' ) ) );
				}
			}, () => {
				setLoading( false );
				setLoadError( __( 'Fields not loaded.', 'nucleo' ) );
			} );
		};

		useEffect( () => {
			setNotice( null );
			setErrors( {} );
			load();
		}, [ postId ] );

		const setValue = ( key, v ) => setDraft( ( d ) => ( { ...d, [ key ]: v } ) );

		const save = () => {
			const changes = changedValues( saved, draft );
			if ( Object.keys( changes ).length === 0 ) {
				setNotice( { status: 'info', text: __( 'No changes to save.', 'nucleo' ) } );
				return;
			}
			setSaving( true );
			setNotice( null );
			return call( 'nucleo/fields-set', { postId, values: changes } ).then( ( res ) => {
				setSaving( false );
				if ( res.ok ) {
					setErrors( {} );
					setNotice( { status: 'success', text: __( 'Values saved.', 'nucleo' ) } );
					return load();
				}
				setErrors( errorsByPath( res.errors ) );
				setNotice( {
					status: 'error',
					text: res.errors ? __( 'Values not saved. Check the highlighted fields.', 'nucleo' ) : msg( res, __( 'Values not saved.', 'nucleo' ) ),
				} );
			}, () => {
				setSaving( false );
				setNotice( { status: 'error', text: __( 'Values not saved.', 'nucleo' ) } );
			} );
		};

		return h( 'div', { className: 'nucleo-editor__fields' },
			loadError && h( Notice, { status: 'error', isDismissible: false }, loadError ),
			notice && h( Notice, { status: notice.status, isDismissible: false }, notice.text ),
			loading && fields.length === 0 && h( 'p', null, __( 'Loading fields...', 'nucleo' ) ),
			! loading && ! loadError && fields.length === 0 && h( 'p', null, __( 'This content has no fields.', 'nucleo' ) ),
			fields.map( ( field ) => h( FieldControl, {
				key: field.key,
				field,
				value: draft[ field.key ],
				onChange: ( v ) => setValue( field.key, v ),
				path: '/' + field.key,
				errors,
			} ) ),
			fields.length > 0 && h( Button, { variant: 'primary', isBusy: saving, disabled: saving || loading, onClick: save }, __( 'Save fields', 'nucleo' ) )
		);
	}

	if ( window.nucleoEditor && window.nucleoEditor.hasFields ) {
		window.nucleoPanels = window.nucleoPanels || [];
		window.nucleoPanels.push( {
			id: 'fields',
			title: __( 'Fields', 'nucleo' ),
			render: ( props ) => h( FieldsPanel, props ),
		} );
	}
} )();

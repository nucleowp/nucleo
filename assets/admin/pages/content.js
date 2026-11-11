/**
 * Pagina Contenuti di Nucleo (nucleo-content): modello di tipi, tassonomie e gruppi di campi.
 * Nessun build: wp.element, wp.i18n. Dipende da ui.js (window.nucleoUI).
 * Nei test (node --test) si esportano gli helper puri; nel browser è window.nucleoPages['nucleo-content'].
 */
( function () {
	'use strict';

	const root = typeof window !== 'undefined' ? window : {};
	const TABS = [ 'postTypes', 'taxonomies', 'fieldGroups', 'json' ];
	const SECTIONS = {
		postTypes: () => __( 'Post types', 'nucleo' ),
		taxonomies: () => __( 'Taxonomies', 'nucleo' ),
		fieldGroups: () => __( 'Field groups', 'nucleo' ),
	};
	const SUPPORTS = [ 'title', 'editor', 'thumbnail', 'excerpt' ];
	const SIMPLE_TYPES = [ 'text', 'textarea', 'number', 'url', 'email', 'image', 'date', 'select', 'boolean' ];

	const { __ } = ( root.wp && root.wp.i18n ) || { __: ( text ) => text };

	/** Modello vuoto. */
	function emptyModel() {
		return { schema: 1, postTypes: [], taxonomies: [], fieldGroups: [] };
	}

	/** Slug da un nome: accenti tolti, minuscolo, gruppi di caratteri non alfanumerici → '-', al massimo `max` caratteri (20 di default). */
	function slugify( text, max ) {
		const limit = max || 20;
		return String( text || '' )
			.normalize( 'NFD' )
			.replace( /[̀-ͯ]/g, '' )
			.toLowerCase()
			.replace( /[^a-z0-9]+/g, '-' )
			.replace( /^-+|-+$/g, '' )
			.slice( 0, limit )
			.replace( /-+$/, '' );
	}

	/** Nome leggibile di un nodo del modello. */
	function itemName( item, idx ) {
		if ( item && typeof item === 'object' ) {
			return item.title || item.singular || item.label || item.key || item.slug || '#' + ( idx + 1 );
		}
		if ( typeof item === 'string' || typeof item === 'number' ) {
			return String( item );
		}
		return '#' + ( idx + 1 );
	}

	/** Percorso JSON Pointer in testo leggibile: "/fieldGroups/0/fields/2/key" → "Field groups › Dettagli › Docente › key". */
	function pathLabel( model, path ) {
		const parts = String( path || '' ).split( '/' ).filter( ( s ) => s !== '' );
		const out = [];
		let node = model;
		parts.forEach( ( seg, i ) => {
			if ( i === 0 && SECTIONS[ seg ] ) {
				out.push( SECTIONS[ seg ]() );
				node = model ? model[ seg ] : undefined;
			} else if ( seg === 'fields' && node && ! Array.isArray( node ) ) {
				node = node.fields;
			} else if ( /^\d+$/.test( seg ) && Array.isArray( node ) ) {
				const idx = Number( seg );
				out.push( itemName( node[ idx ], idx ) );
				node = node[ idx ];
			} else {
				out.push( seg );
				node = node && typeof node === 'object' ? node[ seg ] : undefined;
			}
		} );
		return out.length ? out.join( ' › ' ) : __( 'Model', 'nucleo' );
	}

	/** Tipi di campo del modello, tutti disponibili. */
	function fieldTypes() {
		return [
			[ 'text', __( 'Text', 'nucleo' ) ],
			[ 'textarea', __( 'Textarea', 'nucleo' ) ],
			[ 'number', __( 'Number', 'nucleo' ) ],
			[ 'url', __( 'URL', 'nucleo' ) ],
			[ 'email', __( 'Email', 'nucleo' ) ],
			[ 'image', __( 'Image', 'nucleo' ) ],
			[ 'date', __( 'Date', 'nucleo' ) ],
			[ 'select', __( 'Select', 'nucleo' ) ],
			[ 'boolean', __( 'Boolean', 'nucleo' ) ],
			[ 'relation', __( 'Relation', 'nucleo' ) ],
			[ 'repeater', __( 'Repeater', 'nucleo' ) ],
		].map( ( [ value, label ] ) => ( { value, label } ) );
	}

	/** Serializzazione con chiavi ordinate: due modelli uguali nel contenuto danno lo stesso testo. */
	function stable( v ) {
		if ( Array.isArray( v ) ) {
			return '[' + v.map( stable ).join( ',' ) + ']';
		}
		if ( v && typeof v === 'object' ) {
			return '{' + Object.keys( v ).filter( ( k ) => v[ k ] !== undefined ).sort()
				.map( ( k ) => JSON.stringify( k ) + ':' + stable( v[ k ] ) ).join( ',' ) + '}';
		}
		return v === undefined ? 'null' : JSON.stringify( v );
	}

	/** True se la bozza `a` differisce dal modello `b`. */
	function isDirty( a, b ) {
		return stable( a ) !== stable( b );
	}

	/** Copia della lista con l'elemento in `from` spostato in `to`; indici fuori lista → copia invariata. */
	function moveItem( list, from, to ) {
		const out = list.slice();
		if ( from < 0 || to < 0 || from >= out.length || to >= out.length ) {
			return out;
		}
		const [ item ] = out.splice( from, 1 );
		out.splice( to, 0, item );
		return out;
	}

	function str( v ) {
		return v === undefined || v === null ? '' : String( v ).trim();
	}

	function numOrUndef( v ) {
		if ( v === '' || v === null || v === undefined ) {
			return undefined;
		}
		const n = Number( v );
		return Number.isFinite( n ) ? n : undefined;
	}

	function unique( list ) {
		return Array.from( new Set( list ) );
	}

	/** Destinazioni possibili per una relazione: post, page e i tipi del modello. */
	function relationTargets( postTypes, selected ) {
		return unique( [ 'post', 'page', ...postTypes.map( ( p ) => p.slug ), ...( selected || [] ) ] );
	}

	/** Modello normalizzato: array sempre presenti, il resto conservato. */
	function normalizeModel( obj ) {
		const m = obj && typeof obj === 'object' ? obj : {};
		return {
			...m,
			schema: m.schema || 1,
			postTypes: Array.isArray( m.postTypes ) ? m.postTypes : [],
			taxonomies: Array.isArray( m.taxonomies ) ? m.taxonomies : [],
			fieldGroups: Array.isArray( m.fieldGroups ) ? m.fieldGroups : [],
		};
	}

	/** Forma salvata di un campo: solo le proprietà del suo tipo. */
	function cleanField( f ) {
		const out = { key: str( f.key ), type: f.type, label: str( f.label ) };
		if ( f.required ) {
			out.required = true;
		}
		if ( str( f.help ) ) {
			out.help = str( f.help );
		}
		if ( f.type === 'number' ) {
			[ 'min', 'max', 'step' ].forEach( ( k ) => {
				if ( Number.isFinite( f[ k ] ) ) {
					out[ k ] = f[ k ];
				}
			} );
		}
		if ( f.type === 'select' ) {
			out.options = ( f.options || [] )
				.filter( ( o ) => str( o.value ) !== '' )
				.map( ( o ) => ( { value: str( o.value ), label: str( o.label ) || str( o.value ) } ) );
			if ( f.multiple ) {
				out.multiple = true;
			}
		}
		if ( f.type === 'relation' ) {
			out.postTypes = ( f.postTypes || [] ).slice();
			if ( f.multiple ) {
				out.multiple = true;
			}
		}
		if ( f.type === 'repeater' ) {
			out.fields = ( f.fields || [] ).map( cleanField );
			[ 'min', 'max' ].forEach( ( k ) => {
				if ( Number.isFinite( f[ k ] ) ) {
					out[ k ] = f[ k ];
				}
			} );
		}
		return out;
	}

	function cleanPostType( t ) {
		const out = {
			slug: str( t.slug ),
			singular: str( t.singular ),
			plural: str( t.plural ),
			public: Boolean( t.public ),
			hasArchive: Boolean( t.hasArchive ),
			supports: ( t.supports || [] ).slice(),
		};
		if ( str( t.rewrite ) ) {
			out.rewrite = str( t.rewrite );
		}
		if ( str( t.icon ) ) {
			out.icon = str( t.icon );
		}
		return out;
	}

	function cleanTaxonomy( t ) {
		return {
			slug: str( t.slug ),
			singular: str( t.singular ),
			plural: str( t.plural ),
			postTypes: ( t.postTypes || [] ).slice(),
			hierarchical: Boolean( t.hierarchical ),
			public: Boolean( t.public ),
		};
	}

	function cleanGroup( g ) {
		return {
			id: str( g.id ),
			title: str( g.title ),
			location: g.location,
			fields: ( g.fields || [] ).map( cleanField ),
		};
	}

	function emptyPostType() {
		return { slug: '', singular: '', plural: '', public: true, hasArchive: false, rewrite: '', supports: [ 'title', 'editor' ], icon: '' };
	}

	function emptyTaxonomy() {
		return { slug: '', singular: '', plural: '', postTypes: [], hierarchical: false, public: true };
	}

	function emptyGroup( draft ) {
		return { id: '', title: '', location: { postType: draft.postTypes.length ? draft.postTypes[ 0 ].slug : '' }, fields: [] };
	}

	function emptyField() {
		return { key: '', type: 'text', label: '' };
	}

	/** Errori del server con il percorso reso leggibile sul modello validato. */
	function toLabels( model, errors ) {
		return errors.map( ( e ) => ( { label: pathLabel( model, e.path ), message: e.message } ) );
	}

	function usageText( n ) {
		return __( 'In use:', 'nucleo' ) + ' ' + n;
	}

	function itemTitle( kind, item ) {
		if ( kind === 'fieldGroups' ) {
			return str( item.title ) || str( item.id );
		}
		return str( item.singular ) || str( item.slug );
	}

	function itemDescription( kind, item ) {
		if ( kind === 'postTypes' ) {
			return str( item.slug ) + ' · ' + ( item.supports || [] ).join( ', ' );
		}
		if ( kind === 'taxonomies' ) {
			return str( item.slug ) + ' · ' + ( item.postTypes || [] ).join( ', ' );
		}
		const loc = item.location || {};
		return str( item.id ) + ' · ' + ( loc.taxonomy || loc.postType || '' ) + ' · ' + ( item.fields || [] ).length + ' ' + __( 'fields', 'nucleo' );
	}

	const api = { emptyModel, slugify, pathLabel, fieldTypes, isDirty, moveItem };

	const el = root.wp && root.wp.element;
	if ( el ) {
		const { createElement: h, useState, useEffect } = el;
		const UI = root.nucleoUI;

		function TextField( { label, value, onChange, help } ) {
			return h( 'label', { className: 'nc-field' },
				h( 'span', null, label ),
				h( 'input', { className: 'nc-input', type: 'text', value: value || '', onChange: ( e ) => onChange( e.target.value ) } ),
				help && h( 'span', { className: 'nc-field__help' }, help ) );
		}

		function NumberField( { label, value, onChange } ) {
			return h( 'label', { className: 'nc-field' },
				h( 'span', null, label ),
				h( 'input', {
					className: 'nc-input',
					type: 'number',
					value: value === undefined ? '' : value,
					onChange: ( e ) => onChange( numOrUndef( e.target.value ) ),
				} ) );
		}

		function SelectField( { label, value, options, onChange } ) {
			return h( 'label', { className: 'nc-field' },
				h( 'span', null, label ),
				h( 'select', { className: 'nc-select', value, onChange: ( e ) => onChange( e.target.value ) },
					options.map( ( o ) => h( 'option', { key: o.value, value: o.value }, o.label ) ) ) );
		}

		function CheckField( { label, checked, onChange } ) {
			return h( 'label', { className: 'nc-check' },
				h( 'input', { type: 'checkbox', checked: Boolean( checked ), onChange: ( e ) => onChange( e.target.checked ) } ),
				' ',
				label );
		}

		/** Insieme di caselle: `selected` è la lista degli slug spuntati. */
		function CheckGroup( { label, options, selected, onChange } ) {
			return h( 'fieldset', { className: 'nc-fieldset' },
				h( 'legend', null, label ),
				options.map( ( o ) => h( CheckField, {
					key: o,
					label: o,
					checked: selected.includes( o ),
					onChange: ( on ) => onChange( on ? [ ...selected, o ] : selected.filter( ( x ) => x !== o ) ),
				} ) ) );
		}

		/** Opzioni di un campo select: valore ed etichetta per riga. */
		function OptionsEditor( { options, onChange } ) {
			return h( 'div', { className: 'nc-stack' },
				h( 'span', { className: 'nc-label' }, __( 'Options', 'nucleo' ) ),
				options.map( ( o, i ) => h( 'div', { key: i, className: 'nc-row' },
					h( TextField, {
						label: __( 'Value', 'nucleo' ),
						value: o.value,
						onChange: ( v ) => onChange( options.map( ( x, j ) => ( j === i ? { ...x, value: v } : x ) ) ),
					} ),
					h( TextField, {
						label: __( 'Label', 'nucleo' ),
						value: o.label,
						onChange: ( v ) => onChange( options.map( ( x, j ) => ( j === i ? { ...x, label: v } : x ) ) ),
					} ),
					h( 'button', {
						type: 'button',
						className: 'nc-btn nc-btn--tertiary nc-btn--small',
						onClick: () => onChange( options.filter( ( x, j ) => j !== i ) ),
					}, __( 'Remove', 'nucleo' ) ) ) ),
				h( 'div', null, h( 'button', {
					type: 'button',
					className: 'nc-btn nc-btn--secondary nc-btn--small',
					onClick: () => onChange( [ ...options, { value: '', label: '' } ] ),
				}, __( 'Add option', 'nucleo' ) ) ) );
		}

		/** Pulsanti in fondo a una modale. */
		function ModalFoot( { onClose, onDone, disabled, label, danger } ) {
			return h( 'div', { className: 'nc-row' },
				h( 'button', { type: 'button', className: 'nc-btn nc-btn--secondary', onClick: onClose }, __( 'Cancel', 'nucleo' ) ),
				h( 'button', {
					type: 'button',
					className: danger ? 'nc-btn nc-btn--danger' : 'nc-btn nc-btn--primary',
					disabled: Boolean( disabled ),
					onClick: onDone,
				}, label || __( 'Done', 'nucleo' ) ) );
		}

		function PostTypeModal( { initial, isNew, onDone, onClose } ) {
			const [ t, setT ] = useState( () => ( { ...initial, supports: initial.supports || [] } ) );
			const [ slugTouched, setSlugTouched ] = useState( ! isNew );
			const set = ( patch ) => setT( ( x ) => ( { ...x, ...patch } ) );
			return h( UI.Modal, {
				title: isNew ? __( 'Add post type', 'nucleo' ) : __( 'Edit post type', 'nucleo' ),
				onClose,
				foot: h( ModalFoot, {
					onClose,
					onDone: () => onDone( cleanPostType( t ) ),
					disabled: ! str( t.slug ) || ! str( t.singular ),
				} ),
			},
				h( TextField, {
					label: __( 'Singular name', 'nucleo' ),
					value: t.singular,
					onChange: ( v ) => set( slugTouched ? { singular: v } : { singular: v, slug: slugify( v, 20 ) } ),
				} ),
				h( TextField, { label: __( 'Plural name', 'nucleo' ), value: t.plural, onChange: ( v ) => set( { plural: v } ) } ),
				h( TextField, {
					label: __( 'Slug', 'nucleo' ),
					value: t.slug,
					help: __( 'Lowercase letters, digits and dashes, max 20 characters.', 'nucleo' ),
					onChange: ( v ) => {
						setSlugTouched( true );
						set( { slug: v } );
					},
				} ),
				h( CheckField, { label: __( 'Public', 'nucleo' ), checked: t.public, onChange: ( v ) => set( { public: v } ) } ),
				h( CheckField, { label: __( 'Has archive', 'nucleo' ), checked: t.hasArchive, onChange: ( v ) => set( { hasArchive: v } ) } ),
				h( TextField, { label: __( 'Rewrite', 'nucleo' ), value: t.rewrite, onChange: ( v ) => set( { rewrite: v } ) } ),
				h( CheckGroup, { label: __( 'Supports', 'nucleo' ), options: SUPPORTS, selected: t.supports, onChange: ( v ) => set( { supports: v } ) } ),
				h( TextField, {
					label: __( 'Icon', 'nucleo' ),
					value: t.icon,
					help: __( 'Dashicons class, e.g. dashicons-welcome-learn-more.', 'nucleo' ),
					onChange: ( v ) => set( { icon: v } ),
				} ) );
		}

		function TaxonomyModal( { initial, isNew, postTypes, onDone, onClose } ) {
			const [ t, setT ] = useState( () => ( { ...initial, postTypes: initial.postTypes || [] } ) );
			const [ slugTouched, setSlugTouched ] = useState( ! isNew );
			const set = ( patch ) => setT( ( x ) => ( { ...x, ...patch } ) );
			const options = unique( [ ...postTypes.map( ( p ) => p.slug ), ...t.postTypes ] );
			return h( UI.Modal, {
				title: isNew ? __( 'Add taxonomy', 'nucleo' ) : __( 'Edit taxonomy', 'nucleo' ),
				onClose,
				foot: h( ModalFoot, {
					onClose,
					onDone: () => onDone( cleanTaxonomy( t ) ),
					disabled: ! str( t.slug ) || ! str( t.singular ),
				} ),
			},
				h( TextField, {
					label: __( 'Singular name', 'nucleo' ),
					value: t.singular,
					onChange: ( v ) => set( slugTouched ? { singular: v } : { singular: v, slug: slugify( v, 32 ) } ),
				} ),
				h( TextField, { label: __( 'Plural name', 'nucleo' ), value: t.plural, onChange: ( v ) => set( { plural: v } ) } ),
				h( TextField, {
					label: __( 'Slug', 'nucleo' ),
					value: t.slug,
					help: __( 'Lowercase letters, digits and dashes, max 32 characters.', 'nucleo' ),
					onChange: ( v ) => {
						setSlugTouched( true );
						set( { slug: v } );
					},
				} ),
				h( CheckGroup, { label: __( 'Post types', 'nucleo' ), options, selected: t.postTypes, onChange: ( v ) => set( { postTypes: v } ) } ),
				h( CheckField, { label: __( 'Hierarchical', 'nucleo' ), checked: t.hierarchical, onChange: ( v ) => set( { hierarchical: v } ) } ),
				h( CheckField, { label: __( 'Public', 'nucleo' ), checked: t.public, onChange: ( v ) => set( { public: v } ) } ) );
		}

		/** Un campo del gruppo. Il ripetitore ospita sottocampi di tipo semplice (un livello solo). */
		function FieldCard( { field: f, index, count, postTypes, nested, groupId, usage, onChange, onRemove, onMove } ) {
			const types = fieldTypes().filter( ( t ) => ! nested || SIMPLE_TYPES.includes( t.value ) );
			const n = ! nested && groupId && usage && usage.fields ? usage.fields[ groupId + '/' + f.key ] : undefined;
			const type = f.type;
			return h( UI.Card, {
				title: str( f.label ) || str( f.key ) || __( 'New field', 'nucleo' ),
				actions: h( 'div', { className: 'nc-row' },
					h( 'button', {
						type: 'button',
						className: 'nc-btn nc-btn--tertiary nc-btn--small',
						disabled: index === 0,
						'aria-label': __( 'Move up', 'nucleo' ),
						onClick: () => onMove( index - 1 ),
					}, __( 'Up', 'nucleo' ) ),
					h( 'button', {
						type: 'button',
						className: 'nc-btn nc-btn--tertiary nc-btn--small',
						disabled: index === count - 1,
						'aria-label': __( 'Move down', 'nucleo' ),
						onClick: () => onMove( index + 1 ),
					}, __( 'Down', 'nucleo' ) ),
					h( 'button', { type: 'button', className: 'nc-btn nc-btn--danger nc-btn--small', onClick: onRemove }, __( 'Remove', 'nucleo' ) ) ),
				foot: n !== undefined ? h( UI.Pill, { tone: 'info' }, usageText( n ) ) : null,
			},
				h( TextField, {
					label: __( 'Key', 'nucleo' ),
					value: f.key,
					help: __( 'Letters, digits and underscores, starts with a letter, max 32.', 'nucleo' ),
					onChange: ( v ) => onChange( { ...f, key: v } ),
				} ),
				h( TextField, { label: __( 'Label', 'nucleo' ), value: f.label, onChange: ( v ) => onChange( { ...f, label: v } ) } ),
				h( SelectField, { label: __( 'Type', 'nucleo' ), value: type, options: types, onChange: ( v ) => onChange( { ...f, type: v } ) } ),
				h( CheckField, { label: __( 'Required', 'nucleo' ), checked: f.required, onChange: ( v ) => onChange( { ...f, required: v } ) } ),
				h( TextField, { label: __( 'Help', 'nucleo' ), value: f.help, onChange: ( v ) => onChange( { ...f, help: v } ) } ),
				type === 'number' && h( 'div', { className: 'nc-row' },
					h( NumberField, { label: __( 'Min', 'nucleo' ), value: f.min, onChange: ( v ) => onChange( { ...f, min: v } ) } ),
					h( NumberField, { label: __( 'Max', 'nucleo' ), value: f.max, onChange: ( v ) => onChange( { ...f, max: v } ) } ),
					h( NumberField, { label: __( 'Step', 'nucleo' ), value: f.step, onChange: ( v ) => onChange( { ...f, step: v } ) } ) ),
				type === 'select' && h( 'div', { className: 'nc-stack' },
					h( OptionsEditor, { options: f.options || [], onChange: ( v ) => onChange( { ...f, options: v } ) } ),
					h( CheckField, { label: __( 'Multiple', 'nucleo' ), checked: f.multiple, onChange: ( v ) => onChange( { ...f, multiple: v } ) } ) ),
				type === 'relation' && h( 'div', { className: 'nc-stack' },
					h( CheckGroup, {
						label: __( 'Post types', 'nucleo' ),
						options: relationTargets( postTypes, f.postTypes ),
						selected: f.postTypes || [],
						onChange: ( v ) => onChange( { ...f, postTypes: v } ),
					} ),
					h( CheckField, { label: __( 'Multiple', 'nucleo' ), checked: f.multiple, onChange: ( v ) => onChange( { ...f, multiple: v } ) } ) ),
				type === 'repeater' && h( 'div', { className: 'nc-stack' },
					h( 'div', { className: 'nc-row' },
						h( NumberField, { label: __( 'Min', 'nucleo' ), value: f.min, onChange: ( v ) => onChange( { ...f, min: v } ) } ),
						h( NumberField, { label: __( 'Max', 'nucleo' ), value: f.max, onChange: ( v ) => onChange( { ...f, max: v } ) } ) ),
					h( 'span', { className: 'nc-label' }, __( 'Subfields', 'nucleo' ) ),
					h( FieldsEditor, {
						fields: f.fields || [],
						onChange: ( v ) => onChange( { ...f, fields: v } ),
						postTypes,
						nested: true,
						groupId,
						usage,
					} ) ) );
		}

		/** Elenco dei campi di un gruppo (o dei sottocampi), con aggiunta. */
		function FieldsEditor( { fields, onChange, postTypes, nested, groupId, usage } ) {
			return h( 'div', { className: 'nc-stack' },
				fields.map( ( f, i ) => h( FieldCard, {
					key: i,
					field: f,
					index: i,
					count: fields.length,
					postTypes,
					nested,
					groupId,
					usage,
					onChange: ( nf ) => onChange( fields.map( ( x, j ) => ( j === i ? nf : x ) ) ),
					onRemove: () => onChange( fields.filter( ( x, j ) => j !== i ) ),
					onMove: ( to ) => onChange( moveItem( fields, i, to ) ),
				} ) ),
				h( 'div', null, h( 'button', {
					type: 'button',
					className: 'nc-btn nc-btn--secondary nc-btn--small',
					onClick: () => onChange( [ ...fields, emptyField() ] ),
				}, nested ? __( 'Add subfield', 'nucleo' ) : __( 'Add field', 'nucleo' ) ) ) );
		}

		function GroupModal( { initial, isNew, draftModel, usage, onDone, onClose } ) {
			const [ g, setG ] = useState( () => ( { ...initial, location: initial.location || {}, fields: initial.fields || [] } ) );
			const [ idTouched, setIdTouched ] = useState( ! isNew );
			const set = ( patch ) => setG( ( x ) => ( { ...x, ...patch } ) );
			const kind = g.location.taxonomy !== undefined ? 'taxonomy' : 'postType';
			const targets = ( kind === 'taxonomy' ? draftModel.taxonomies : draftModel.postTypes ).map( ( x ) => x.slug );
			const target = kind === 'taxonomy' ? g.location.taxonomy : g.location.postType;
			return h( UI.Modal, {
				title: isNew ? __( 'Add field group', 'nucleo' ) : __( 'Edit field group', 'nucleo' ),
				onClose,
				foot: h( ModalFoot, {
					onClose,
					onDone: () => onDone( cleanGroup( g ) ),
					disabled: ! str( g.id ) || ! str( g.title ),
				} ),
			},
				h( TextField, {
					label: __( 'Title', 'nucleo' ),
					value: g.title,
					onChange: ( v ) => set( idTouched ? { title: v } : { title: v, id: slugify( v, 32 ) } ),
				} ),
				h( TextField, {
					label: __( 'Id', 'nucleo' ),
					value: g.id,
					help: __( 'Lowercase letters, digits and dashes, max 32 characters.', 'nucleo' ),
					onChange: ( v ) => {
						setIdTouched( true );
						set( { id: v } );
					},
				} ),
				h( SelectField, {
					label: __( 'Location', 'nucleo' ),
					value: kind,
					options: [
						{ value: 'postType', label: __( 'Post type', 'nucleo' ) },
						{ value: 'taxonomy', label: __( 'Taxonomy', 'nucleo' ) },
					],
					onChange: ( k ) => {
						const list = k === 'taxonomy' ? draftModel.taxonomies : draftModel.postTypes;
						set( { location: { [ k ]: list.length ? list[ 0 ].slug : '' } } );
					},
				} ),
				h( SelectField, {
					label: kind === 'taxonomy' ? __( 'Taxonomy', 'nucleo' ) : __( 'Post type', 'nucleo' ),
					value: target || '',
					options: targets.map( ( s ) => ( { value: s, label: s } ) ),
					onChange: ( v ) => set( { location: { [ kind ]: v } } ),
				} ),
				h( 'span', { className: 'nc-label' }, __( 'Fields', 'nucleo' ) ),
				h( FieldsEditor, {
					fields: g.fields,
					onChange: ( fields ) => set( { fields } ),
					postTypes: draftModel.postTypes,
					nested: false,
					groupId: g.id,
					usage,
				} ) );
		}

		/** Elenco a schede di tipi, tassonomie o gruppi, con Edit e Remove. */
		function ListPanel( { kind, items, usage, onAdd, onEdit, onRemove } ) {
			const addLabel = { postTypes: __( 'Add post type', 'nucleo' ), taxonomies: __( 'Add taxonomy', 'nucleo' ), fieldGroups: __( 'Add field group', 'nucleo' ) }[ kind ];
			const emptyText = { postTypes: __( 'No post types yet.', 'nucleo' ), taxonomies: __( 'No taxonomies yet.', 'nucleo' ), fieldGroups: __( 'No field groups yet.', 'nucleo' ) }[ kind ];
			const bucket = usage[ kind ] || {};
			return h( 'div', { className: 'nc-stack' },
				h( 'div', null, h( 'button', { type: 'button', className: 'nc-btn nc-btn--primary', onClick: onAdd }, addLabel ) ),
				items.length === 0 && h( UI.Empty, { text: emptyText } ),
				items.map( ( item, i ) => h( UI.Card, {
					key: i,
					title: itemTitle( kind, item ),
					foot: kind === 'fieldGroups' ? null : h( UI.Pill, { tone: bucket[ str( item.slug ) ] ? 'info' : 'neutral' }, usageText( bucket[ str( item.slug ) ] || 0 ) ),
					actions: h( 'div', { className: 'nc-row' },
						h( 'button', { type: 'button', className: 'nc-btn nc-btn--secondary nc-btn--small', onClick: () => onEdit( i ) }, __( 'Edit', 'nucleo' ) ),
						h( 'button', { type: 'button', className: 'nc-btn nc-btn--danger nc-btn--small', onClick: () => onRemove( i ) }, __( 'Remove', 'nucleo' ) ) ),
				},
					h( 'p', { className: 'nc-muted' }, itemDescription( kind, item ) ) ) ) );
		}

		/** Scheda JSON: textarea con il modello e Apply. */
		function JsonPanel( { text, onText, error, onApply } ) {
			return h( UI.Card, { title: __( 'Model JSON', 'nucleo' ) },
				h( 'textarea', { className: 'nc-textarea nc-code', rows: 24, value: text, onChange: ( e ) => onText( e.target.value ) } ),
				error && h( UI.Notice, { tone: 'err' }, error ),
				h( 'div', { className: 'nc-row' },
					h( 'button', { type: 'button', className: 'nc-btn nc-btn--secondary', onClick: onApply }, __( 'Apply', 'nucleo' ) ) ) );
		}

		/** Anteprima delle modifiche; le distruttive richiedono una spunta ciascuna. */
		function ReviewModal( { review, busy, onToggle, onConfirm, onClose } ) {
			const diff = review.diff;
			const destructive = diff.destructive || [];
			const allChecked = destructive.every( ( d ) => review.checked.includes( d.id ) );
			const sections = [
				[ __( 'Added', 'nucleo' ), diff.added || [] ],
				[ __( 'Removed', 'nucleo' ), diff.removed || [] ],
				[ __( 'Changed', 'nucleo' ), diff.changed || [] ],
			];
			const none = destructive.length === 0 && sections.every( ( [ , list ] ) => list.length === 0 );
			return h( UI.Modal, {
				title: __( 'Review changes', 'nucleo' ),
				onClose,
				foot: h( ModalFoot, {
					onClose,
					onDone: onConfirm,
					disabled: busy || ! allChecked,
					label: __( 'Confirm', 'nucleo' ),
					danger: destructive.length > 0,
				} ),
			},
				none && h( UI.Empty, { text: __( 'No changes to save.', 'nucleo' ) } ),
				sections.map( ( [ title, list ] ) => list.length > 0 && h( 'div', { key: title, className: 'nc-stack' },
					h( 'span', { className: 'nc-label' }, title ),
					h( 'ul', null, list.map( ( id ) => h( 'li', { key: id }, h( 'code', { className: 'nc-code' }, id ) ) ) ) ) ),
				destructive.length > 0 && h( 'div', { className: 'nc-stack' },
					h( 'span', { className: 'nc-label' }, __( 'Destructive changes: confirm each one', 'nucleo' ) ),
					destructive.map( ( d ) => h( CheckField, {
						key: d.id,
						label: d.id + ' — ' + d.reason,
						checked: review.checked.includes( d.id ),
						onChange: ( on ) => onToggle( d.id, on ),
					} ) ) ) );
		}

		function ContentPage() {
			const [ tab, setTab ] = useState( () => UI.tabFromSearch( root.location.search, TABS, 'postTypes' ) );
			const [ loaded, reload ] = UI.useLoad( () => UI.call( 'nucleo/model-get', {} ), [] );
			const [ draft, setDraft ] = useState( null );
			const [ saved, setSaved ] = useState( null );
			const [ editing, setEditing ] = useState( null );
			const [ review, setReview ] = useState( null );
			const [ jsonText, setJsonText ] = useState( '' );
			const [ jsonError, setJsonError ] = useState( '' );
			const [ busy, setBusy ] = useState( false );
			const [ errors, setErrors ] = useState( [] );
			const [ error, setError ] = useState( '' );
			const [ notice, setNotice ] = useState( '' );

			useEffect( () => {
				if ( loaded.data && draft === null ) {
					const model = normalizeModel( loaded.data.model );
					setDraft( model );
					setSaved( model );
				}
			}, [ loaded.data, draft ] );

			/** Il testo JSON segue la bozza ogni volta che si apre la scheda. */
			useEffect( () => {
				if ( tab === 'json' && draft ) {
					setJsonText( JSON.stringify( draft, null, 2 ) );
					setJsonError( '' );
				}
			}, [ tab, draft === null ] );

			const dirty = draft !== null && saved !== null && isDirty( draft, saved );

			useEffect( () => {
				if ( ! dirty ) {
					return undefined;
				}
				const warn = ( e ) => {
					e.preventDefault();
					e.returnValue = '';
				};
				window.addEventListener( 'beforeunload', warn );
				return () => window.removeEventListener( 'beforeunload', warn );
			}, [ dirty ] );

			const usage = ( loaded.data && loaded.data.usage ) || {};

			function commit( kind, index, item ) {
				setDraft( ( d ) => ( {
					...d,
					[ kind ]: index < 0 ? [ ...d[ kind ], item ] : d[ kind ].map( ( x, i ) => ( i === index ? item : x ) ),
				} ) );
				setEditing( null );
			}

			function removeAt( kind, index ) {
				setDraft( ( d ) => ( { ...d, [ kind ]: d[ kind ].filter( ( x, i ) => i !== index ) } ) );
			}

			function applyJson() {
				let parsed;
				try {
					parsed = JSON.parse( jsonText );
				} catch ( e ) {
					setJsonError( __( 'Invalid JSON: the draft was not changed.', 'nucleo' ) );
					return;
				}
				if ( ! parsed || typeof parsed !== 'object' || Array.isArray( parsed ) ) {
					setJsonError( __( 'The model must be a JSON object: the draft was not changed.', 'nucleo' ) );
					return;
				}
				setJsonError( '' );
				setDraft( normalizeModel( parsed ) );
				setNotice( __( 'JSON applied to the draft. Save to store it.', 'nucleo' ) );
			}

			/** Valida la bozza; senza errori apre l'anteprima delle differenze. */
			function validateDraft() {
				const model = draft;
				setBusy( true );
				setError( '' );
				setErrors( [] );
				setNotice( '' );
				UI.call( 'nucleo/model-validate', { model } ).then( ( res ) => {
					if ( res && Array.isArray( res.errors ) && res.errors.length ) {
						setErrors( toLabels( model, res.errors ) );
						return;
					}
					if ( ! res || res.ok === false ) {
						setError( ( res && res.error && res.error.message ) || __( 'The model could not be validated.', 'nucleo' ) );
						return;
					}
					setReview( { model, diff: res.diff || {}, checked: [] } );
				} ).finally( () => setBusy( false ) );
			}

			function toggleReview( id, on ) {
				setReview( ( r ) => ( { ...r, checked: on ? [ ...r.checked, id ] : r.checked.filter( ( x ) => x !== id ) } ) );
			}

			function confirmSave() {
				const { model, checked } = review;
				setBusy( true );
				UI.call( 'nucleo/model-set', { model, confirm: checked } ).then( ( res ) => {
					setReview( null );
					if ( res && res.ok ) {
						setSaved( model );
						setNotice( __( 'Content model saved.', 'nucleo' ) );
						reload();
						return;
					}
					if ( res && Array.isArray( res.errors ) && res.errors.length ) {
						setErrors( toLabels( model, res.errors ) );
						return;
					}
					setError( ( res && res.error && res.error.message ) || __( 'The content model could not be saved.', 'nucleo' ) );
				} ).finally( () => setBusy( false ) );
			}

			function renderModal() {
				const { kind, index } = editing;
				const isNew = index < 0;
				const close = () => setEditing( null );
				const done = ( item ) => commit( kind, index, item );
				const key = kind + ':' + index;
				if ( kind === 'postTypes' ) {
					return h( PostTypeModal, { key, initial: isNew ? emptyPostType() : draft.postTypes[ index ], isNew, onDone: done, onClose: close } );
				}
				if ( kind === 'taxonomies' ) {
					return h( TaxonomyModal, {
						key,
						initial: isNew ? emptyTaxonomy() : draft.taxonomies[ index ],
						isNew,
						postTypes: draft.postTypes,
						onDone: done,
						onClose: close,
					} );
				}
				return h( GroupModal, {
					key,
					initial: isNew ? emptyGroup( draft ) : draft.fieldGroups[ index ],
					isNew,
					draftModel: draft,
					usage,
					onDone: done,
					onClose: close,
				} );
			}

			function body() {
				if ( loaded.loading ) {
					return h( UI.Skeleton, { lines: 3 } );
				}
				if ( loaded.error ) {
					return h( UI.ErrorBox, {
						message: loaded.error.message || __( 'The content model could not be loaded.', 'nucleo' ),
						onRetry: reload,
					} );
				}
				if ( draft === null ) {
					return h( UI.Skeleton, { lines: 3 } );
				}
				if ( tab === 'json' ) {
					return h( JsonPanel, { text: jsonText, onText: setJsonText, error: jsonError, onApply: applyJson } );
				}
				return h( ListPanel, {
					kind: tab,
					items: draft[ tab ],
					usage,
					onAdd: () => setEditing( { kind: tab, index: -1 } ),
					onEdit: ( i ) => setEditing( { kind: tab, index: i } ),
					onRemove: ( i ) => removeAt( tab, i ),
				} );
			}

			const tabs = [
				{ id: 'postTypes', label: __( 'Post types', 'nucleo' ), count: draft ? draft.postTypes.length : undefined },
				{ id: 'taxonomies', label: __( 'Taxonomies', 'nucleo' ), count: draft ? draft.taxonomies.length : undefined },
				{ id: 'fieldGroups', label: __( 'Field groups', 'nucleo' ), count: draft ? draft.fieldGroups.length : undefined },
				{ id: 'json', label: __( 'JSON', 'nucleo' ) },
			];

			return h( UI.Page, {
				title: __( 'Content model', 'nucleo' ),
				subtitle: __( 'Post types, taxonomies and field groups, without writing JSON.', 'nucleo' ),
				actions: h( 'button', {
					type: 'button',
					className: 'nc-btn nc-btn--primary',
					disabled: busy || draft === null,
					'aria-busy': busy ? 'true' : undefined,
					onClick: validateDraft,
				}, busy ? __( 'Saving…', 'nucleo' ) : __( 'Save', 'nucleo' ) ),
			},
				h( UI.Tabs, {
					tabs,
					active: tab,
					onChange: ( id ) => {
						setTab( id );
						setErrors( [] );
						setError( '' );
						setNotice( '' );
					},
				} ),
				dirty && h( UI.Notice, { tone: 'warn' }, __( 'You have unsaved changes.', 'nucleo' ) ),
				notice && h( UI.Notice, { tone: 'ok', autoClose: true, onClose: () => setNotice( '' ) }, notice ),
				error && h( UI.Notice, { tone: 'err' }, error ),
				errors.length > 0 && h( UI.Notice, { tone: 'err', title: __( 'The model has errors', 'nucleo' ) },
					h( 'ul', null, errors.map( ( e, i ) => h( 'li', { key: i }, h( 'strong', null, e.label ), ': ', e.message ) ) ) ),
				body(),
				editing && draft && renderModal(),
				review && h( ReviewModal, {
					review,
					busy,
					onToggle: toggleReview,
					onConfirm: confirmSave,
					onClose: () => setReview( null ),
				} ) );
		}

		root.nucleoPages = root.nucleoPages || {};
		root.nucleoPages[ 'nucleo-content' ] = ContentPage;
	}

	if ( typeof module === 'object' && module.exports ) {
		module.exports = api;
	}
} )();

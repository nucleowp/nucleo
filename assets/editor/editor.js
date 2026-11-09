/**
 * Editor backend di Nucleo. Nessun build: wp.element, wp.components, wp.apiFetch, wp.i18n.
 * Ogni operazione passa dalle ability nucleo/* via REST.
 */
( function () {
	'use strict';

	const { createElement: h, useState, useEffect, useRef, render } = wp.element;
	const { Button, CheckboxControl, Notice, SelectControl, Spinner, TabPanel, TextControl, TextareaControl, ToggleControl } = wp.components;
	const { __ } = wp.i18n;

	const CFG = window.nucleoEditor || {};

	/* Colonna Struttura: limiti della larghezza trascinabile, in px. */
	const TREE_MIN = 200;
	const TREE_MAX = 520;
	const TREE_DEFAULT = 260;
	const clampTree = ( w ) => Math.round( Math.min( TREE_MAX, Math.max( TREE_MIN, w ) ) );
	// localStorage può mancare (navigazione privata, criteri del browser): si torna al valore predefinito.
	const readStored = ( key ) => {
		try {
			return window.localStorage.getItem( key );
		} catch ( e ) {
			return null;
		}
	};
	const writeStored = ( key, value ) => {
		try {
			window.localStorage.setItem( key, value );
		} catch ( e ) {}
	};
	const nucleoLabels = window.nucleoLabels;
	const nucleoInlineEdit = window.nucleoInlineEdit;
	const tree = window.nucleoTree;
	const BPS = [ 'base', 'md', 'lg', 'xl' ];
	const SIZE = window.nucleoPreviewSize;
	// Componente creato una volta: ricrearlo a ogni render rimonterebbe il frame dell'anteprima.
	const MockFrame = window.nucleoMockup ? window.nucleoMockup.createFrame( h ) : null;
	const DEVICE_LABELS = { mobile: __( 'Mobile', 'nucleo' ), tablet: __( 'Tablet', 'nucleo' ), desktop: __( 'Desktop', 'nucleo' ), ultra: __( 'Ultra', 'nucleo' ) };
	// Sotto questa larghezza l'editor completo non entra: resta la vista testi.
	const NARROW_PX = 1024;
	// Glifo per tipo di nodo, dal nome del blocco senza namespace (nc/heading -> heading).
	// Letture: l'API delle ability le accetta solo in GET. Elenco dal server, letto dalle annotazioni.
	const READONLY = CFG.readonly || [ 'nucleo/doc-get', 'nucleo/catalog-list', 'nucleo/catalog-get', 'nucleo/doc-texts' ];
	// Tipi di campo testuale: si aggiornano subito nell'anteprima.
	const TEXT_TYPES = [ 'string', 'inline-rich-text' ];
	// Vista iniziale: &view=texts nell'URL apre la vista testi per chiunque.
	const initialView = () => ( new URLSearchParams( window.location.search ).get( 'view' ) === 'texts' ? 'texts' : 'structure' );

	/** Vero se tutti i ruoli dell'utente sono in editor.textOnlyRoles. Senza ruoli, no. */
	function onlyTextRoles( roles, textOnlyRoles ) {
		const list = Array.isArray( roles ) ? roles : [];
		const textOnly = Array.isArray( textOnlyRoles ) ? textOnlyRoles : [];
		return list.length > 0 && list.every( ( r ) => textOnly.includes( r ) );
	}

	/**
	 * Accoda le op di una modifica. Un solo set sullo stesso campo dell'ultima op si fonde con essa,
	 * ma solo se quell'op non è già in volo (le prime `inFlight` op sono state inviate e non si toccano).
	 */
	function queueEdit( ops, list, inFlight ) {
		const last = ops[ ops.length - 1 ];
		const first = list[ 0 ];
		const merge = ops.length > inFlight && list.length === 1 && first.op === 'set' && last && last.op === 'set' && last.id === first.id && last.path === first.path;
		return { merge, ops: merge ? [ ...ops.slice( 0, -1 ), first ] : [ ...ops, ...list ] };
	}

	/** Documento da mostrare dopo un salvataggio: quello del server con le op non ancora inviate riapplicate. */
	function rebase( serverDoc, pending, apply ) {
		return pending.reduce( ( d, op ) => apply( d, op ), serverDoc );
	}

	/** Testi da inviare: per ogni testo del server, la modifica locale se diversa. `toText` converte il valore scritto. */
	function changedTexts( texts, edits, toText ) {
		const changed = [];
		texts.forEach( ( t ) => {
			const v = edits[ t.id + '|' + t.path ];
			if ( v === undefined ) {
				return;
			}
			const next = toText( t, v );
			if ( next !== t.text ) {
				changed.push( { id: t.id, path: t.path, text: next } );
			}
		} );
		return changed;
	}

	/* ---------- Chiamate alle ability ---------- */

	/** Esegue un'ability. Non rifiuta mai: gli errori tornano come { ok: false, error }. */
	function call( name, input ) {
		const req = READONLY.includes( name )
			? { path: `/wp-abilities/v1/abilities/${ name }/run${ tree.query( input ) }`, method: 'GET' }
			: { path: `/wp-abilities/v1/abilities/${ name }/run`, method: 'POST', data: { input } };
		return wp.apiFetch( req ).catch( ( e ) => ( {
			ok: false,
			error: { code: String( ( e && e.code ) || 'error' ), message: String( ( e && e.message ) || '' ) },
		} ) );
	}

	/** Anteprima del documento in memoria: route POST nucleo/v1/preview. */
	function previewDoc( doc, postId ) {
		return wp.apiFetch( { path: '/nucleo/v1/preview', method: 'POST', data: { document: doc, postId: postId || 0 } } ).catch( ( e ) => ( {
			ok: false,
			error: { code: String( ( e && e.code ) || 'error' ), message: String( ( e && e.message ) || '' ) },
		} ) );
	}

	const msg = ( res, fallback ) => ( res && res.error && res.error.message ) || fallback;
// Punteggio mostrato nella barra: il peggiore tra SEO, accessibilità e prestazioni, se il server lo ha restituito.
const scoreOf = ( res ) => {
	const s = res && res.quality && res.quality.score;
	return s ? Math.min( ...Object.values( s ) ) : null;
};
	const conflictText = () => __( 'The document was changed elsewhere. Reload the page to see the updated version.', 'nucleo' );
	const bpLabel = ( b ) => ( b === 'base' ? __( 'Base', 'nucleo' ) : b.toUpperCase() + ' ' + __( 'from', 'nucleo' ) + ' ' + CFG.breakpoints[ b ] + ' px' );
	const clock = ( d ) => String( d.getHours() ).padStart( 2, '0' ) + ':' + String( d.getMinutes() ).padStart( 2, '0' );

	/* ---------- Documento in memoria ---------- */

	const clone = ( v ) => JSON.parse( JSON.stringify( v ) );
	const newId = () => 'n' + Math.random().toString( 36 ).slice( 2, 9 );

	function eachNode( node, fn, parent, index ) {
		fn( node, parent, index );
		( node.children || [] ).forEach( ( c, i ) => eachNode( c, fn, node, i ) );
	}

	function locate( doc, id ) {
		let loc = null;
		eachNode( doc.root, ( n, parent, index ) => {
			if ( n.id === id ) {
				loc = { node: n, parent, index };
			}
		} );
		return loc;
	}

	function findNode( doc, id ) {
		const loc = locate( doc, id );
		return loc ? loc.node : null;
	}

	function reId( n ) {
		n.id = newId();
		( n.children || [] ).forEach( reId );
		return n;
	}

	function setPath( node, path, value ) {
		const seg = path.split( '.' );
		const last = seg.pop();
		let o = node;
		seg.forEach( ( k ) => {
			if ( !o[ k ] || typeof o[ k ] !== 'object' ) {
				o[ k ] = {};
			}
			o = o[ k ];
		} );
		o[ last ] = value;
	}

	/** Applica una op del PatchEngine al documento in memoria (copia). */
	function applyLocal( doc, op ) {
		const d = clone( doc );
		if ( op.op === 'set' ) {
			const n = findNode( d, op.id );
			if ( n ) {
				setPath( n, op.path, op.value );
			}
		} else if ( op.op === 'move' ) {
			const loc = locate( d, op.id );
			loc.parent.children.splice( loc.index, 1 );
			const target = findNode( d, op.parent );
			target.children = target.children || [];
			target.children.splice( op.index, 0, loc.node );
		} else if ( op.op === 'remove' ) {
			const loc = locate( d, op.id );
			loc.parent.children.splice( loc.index, 1 );
		} else if ( op.op === 'duplicate' ) {
			const loc = locate( d, op.id );
			loc.parent.children.splice( loc.index + 1, 0, reId( clone( loc.node ) ) );
		} else if ( op.op === 'insert' ) {
			const target = findNode( d, op.parent );
			target.children = target.children || [];
			target.children.splice( op.index, 0, clone( op.node ) );
		}
		return d;
	}

	/** Op "move" di un passo su/giù tra fratelli. Null se non si può spostare. */
	function moveOp( doc, id, delta ) {
		const loc = locate( doc, id );
		if ( !loc || !loc.parent ) {
			return null;
		}
		const index = loc.index + delta;
		if ( index < 0 || index >= loc.parent.children.length ) {
			return null;
		}
		return { op: 'move', id, parent: loc.parent.id, index };
	}

	function typesIn( doc ) {
		const types = new Set();
		eachNode( doc.root, ( n ) => types.add( n.type ) );
		return [ ...types ];
	}

	const snippet = ( node ) => {
		const c = node.content || {};
		return String( c.text || c.html || c.title || c.label || '' ).replace( /<[^>]+>/g, '' ).trim().slice( 0, 160 );
	};

	/* ---------- Etichette e nodi ---------- */

	/** Campo del manifest content per l'ultimo segmento del path ("content.title" -> title). */
	function fieldDef( manifest, path ) {
		const key = String( path || '' ).split( '.' ).pop();
		return ( manifest && manifest.content && manifest.content[ key ] ) || null;
	}

	/** Testo semplice per i campi inline-rich-text che lo consentono, altrimenti null. */
	function plainOf( def, value ) {
		return def && def.type === 'inline-rich-text' ? nucleoLabels.toPlain( value || '' ) : null;
	}

	/** Etichetta di un testo nella vista "Solo testi": titolo del blocco, campo, livello dei titoli. */
	function textLabel( node, manifest, path ) {
		const key = String( path || '' ).split( '.' ).pop();
		const title = ( manifest && manifest.title ) || ( node ? node.type : '' );
		let label = title + ': ' + nucleoLabels.field( key, fieldDef( manifest, path ) || {} );
		if ( node && node.type === 'nc/heading' ) {
			const def = ( manifest && manifest.settings && manifest.settings.level ) || {};
			const level = ( node.settings || {} ).level ?? ( node.content || {} ).level ?? def.default;
			if ( level !== undefined && level !== null ) {
				label += ' (h' + level + ')';
			}
		}
		return label;
	}

	/** Glifo della riga dell'albero per tipo di nodo. */
	/** Id del nodo di un'issue: "doc|id" oppure { doc, id }. */
	function issueNodeId( n ) {
		if ( n && typeof n === 'object' ) {
			return String( n.id || '' );
		}
		const s = String( n || '' );
		return s.slice( s.indexOf( '|' ) + 1 );
	}

	/** Chiave di confronto del nodo di un'issue, in entrambi i formati. */
	function nodeKey( n ) {
		if ( n && typeof n === 'object' ) {
			return String( n.doc || '' ) + '|' + String( n.id || '' );
		}
		return String( n || '' );
	}

	function manifestFor( doc, manifests, id ) {
		const n = findNode( doc, id );
		return n ? manifests[ n.type ] || null : null;
	}

	/** Nome di un nodo nella lista dei problemi: titolo del blocco e testo. */
	function describeNode( doc, manifests, id ) {
		const n = findNode( doc, id );
		if ( !n ) {
			return id;
		}
		const name = ( manifests[ n.type ] || {} ).title || n.type;
		const text = snippet( n );
		return text ? name + ': ' + text : name;
	}

	/* ---------- Albero della struttura ---------- */

	/** Contorno del nodo selezionato dentro l'iframe dell'anteprima. */
	/** Testo del nodo nell'anteprima, subito: solo nodi senza figli; gli altri li ricompone il server. */
	function patchText( frame, id, text ) {
		const d = frame && frame.contentDocument;
		if ( !d || text === null ) {
			return;
		}
		d.querySelectorAll( '[data-nc-id]' ).forEach( ( el ) => {
			if ( el.getAttribute( 'data-nc-id' ) === id && el.children.length === 0 ) {
				el.textContent = text;
			}
		} );
	}

	function markSelected( frame, id ) {
		const d = frame && frame.contentDocument;
		if ( !d ) {
			return;
		}
		d.querySelectorAll( '[data-nc-id]' ).forEach( ( el ) => {
			const on = id !== null && el.getAttribute( 'data-nc-id' ) === id;
			el.style.outline = on ? '2px solid #0f7a3c' : ''; // --nc-accent: l'iframe non ha i token dell'admin.
			el.style.outlineOffset = on ? '-2px' : '';
		} );
	}

	/**
	 * Indicatore di rilascio nell'anteprima: linea sopra o sotto il blocco, riquadro tratteggiato per "dentro".
	 * place null lo nasconde. Colore come markSelected: l'iframe non ha i token dell'admin.
	 */
	function showDrop( d, el, pos ) {
		let bar = d.getElementById( 'nc-drop-indicator' );
		if ( !el ) {
			if ( bar ) {
				bar.remove();
			}
			return;
		}
		if ( !bar ) {
			bar = d.createElement( 'div' );
			bar.id = 'nc-drop-indicator';
			bar.style.cssText = 'position:absolute;z-index:2147483647;pointer-events:none;box-sizing:border-box;border-radius:3px';
			d.body.appendChild( bar );
		}
		const r = el.getBoundingClientRect();
		const win = d.defaultView;
		const top = r.top + win.scrollY;
		const inside = pos === 'inside';
		bar.style.left = ( r.left + win.scrollX ) + 'px';
		bar.style.width = r.width + 'px';
		bar.style.top = ( inside ? top : ( pos === 'before' ? top - 2 : top + r.height - 2 ) ) + 'px';
		bar.style.height = inside ? r.height + 'px' : '4px';
		bar.style.background = inside ? 'rgba(15,122,60,.08)' : '#0f7a3c';
		bar.style.border = inside ? '2px dashed #0f7a3c' : '0';
	}

	/** Porta in vista nell'anteprima il nodo selezionato, solo se è fuori dallo schermo dell'iframe. */
	function revealInFrame( frame, id ) {
		const d = frame && frame.contentDocument;
		const win = frame && frame.contentWindow;
		const el = d && id ? d.querySelector( '[data-nc-id="' + CSS.escape( id ) + '"]' ) : null;
		if ( !el || !win ) {
			return;
		}
		const r = el.getBoundingClientRect();
		if ( r.top < 0 || r.bottom > win.innerHeight ) {
			win.scrollTo( { top: win.scrollY + r.top - win.innerHeight / 4, behavior: 'smooth' } );
		}
	}

	/** Porta in vista nello schema la riga del nodo, scorrendo solo la colonna dello schema. */
	function revealRow( id ) {
		const row = id ? document.querySelector( '.nucleo-editor__tree [data-tree-id="' + CSS.escape( id ) + '"]' ) : null;
		const box = row && row.closest( '.nucleo-editor__tree' );
		if ( !box ) {
			return;
		}
		const r = row.getBoundingClientRect();
		const b = box.getBoundingClientRect();
		if ( r.top < b.top || r.bottom > b.bottom ) {
			box.scrollTo( { top: box.scrollTop + r.top - b.top - box.clientHeight / 3, behavior: 'smooth' } );
		}
	}

	/** Posizione del rilascio dall'altezza della riga: bordo alto, bordo basso o centro. */
	function dropPosition( e ) {
		const r = e.currentTarget.getBoundingClientRect();
		const y = ( e.clientY - r.top ) / ( r.height || 1 );
		if ( y < 0.25 ) {
			return 'before';
		}
		return y > 0.75 ? 'after' : 'inside';
	}

	function TreeItem( { node, parent, index, depth, ctx } ) {
		const info = ctx.manifests[ node.type ] || ctx.catalog[ node.type ] || {};
		const text = snippet( node );
		const name = ( info.title || node.type ) + ( text ? ': ' + text : '' );
		const sel = ctx.selected === node.id;
		const drop = ctx.hint && ctx.hint.id === node.id ? ' is-drop-' + ctx.hint.pos : '';
		const dnd = {
			draggable: !!parent,
			onDragStart: ( e ) => {
				ctx.dragRef.current = node.id;
				e.dataTransfer.effectAllowed = 'move';
				e.dataTransfer.setData( 'text/plain', node.id );
			},
			onDragEnd: () => {
				ctx.dragRef.current = null;
				ctx.setHint( null );
			},
			onDragOver: ( e ) => {
				const from = ctx.dragRef.current;
				const pos = from ? dropPosition( e ) : null;
				if ( !pos || !ctx.canDrop( from, node.id, pos ) ) {
					if ( ctx.hint ) {
						ctx.setHint( null );
					}
					return;
				}
				e.preventDefault();
				e.dataTransfer.dropEffect = 'move';
				if ( !ctx.hint || ctx.hint.id !== node.id || ctx.hint.pos !== pos ) {
					ctx.setHint( { id: node.id, pos } );
				}
			},
			onDrop: ( e ) => {
				e.preventDefault();
				const from = ctx.dragRef.current;
				ctx.dragRef.current = null;
				ctx.setHint( null );
				if ( from ) {
					ctx.drop( from, node.id, dropPosition( e ) );
				}
			},
		};
		// Schema chiuso come un navigatore: la radice è sempre aperta, gli altri nodi con figli si aprono con la freccia.
		const hasKids = ( node.children || [] ).length > 0;
		const isOpen = depth === 0 || ctx.open.has( node.id );
		// Funzioni del nodo nel menu contestuale: clic destro sulla riga, oppure tasto Menu o Maiusc+F10 sull'etichetta.
		const menuItems = parent && [
			{ label: __( 'Move up', 'nucleo' ), disabled: index === 0, onClick: () => ctx.move( node.id, -1 ) },
			{ label: __( 'Move down', 'nucleo' ), disabled: index === parent.children.length - 1, onClick: () => ctx.move( node.id, 1 ) },
			{ label: __( 'Duplicate', 'nucleo' ), onClick: () => ctx.duplicate( node.id ) },
			{ label: __( 'Delete', 'nucleo' ), danger: true, onClick: () => ctx.remove( node.id ) },
		];
		const openMenu = ( top, left ) => {
			ctx.select( node.id );
			ctx.openMenu( { items: menuItems, at: { top, left } } );
		};
		const menuEvents = menuItems ? {
			onContextMenu: ( e ) => {
				e.preventDefault();
				openMenu( e.clientY, e.clientX );
			},
		} : {};
		const menuKey = ( e ) => {
			if ( menuItems && ( e.key === 'ContextMenu' || ( e.shiftKey && e.key === 'F10' ) ) ) {
				e.preventDefault();
				const r = e.currentTarget.getBoundingClientRect();
				openMenu( r.bottom + 4, r.left );
			}
		};
		const row = h( 'div', { className: 'nucleo-editor__row' + ( sel ? ' is-selected' : '' ) + drop, 'data-tree-id': node.id, style: { paddingLeft: depth * 12 + 'px' }, ...dnd, ...menuEvents },
			depth > 0 && hasKids
				? h( 'button', { type: 'button', className: 'nucleo-editor__caret', 'aria-expanded': String( isOpen ), 'aria-label': ( isOpen ? __( 'Collapse', 'nucleo' ) : __( 'Expand', 'nucleo' ) ) + ': ' + name, onClick: () => ctx.toggle( node.id ) } )
				: h( 'span', { className: 'nucleo-editor__caret', 'aria-hidden': 'true' } ),
			h( 'span', { className: 'nucleo-editor__glyph' }, h( window.nucleoUI.BlockIcon, { type: node.type, size: 16 } ) ),
			h( Button, { variant: 'tertiary', 'aria-current': sel ? 'true' : undefined, title: name, 'aria-haspopup': menuItems ? 'menu' : undefined, onClick: () => ctx.select( node.id ), onKeyDown: menuKey }, h( 'span', { className: 'nucleo-editor__row-label' }, name ) )
		);
		const kids = isOpen ? ( node.children || [] ).map( ( c, i ) => h( TreeItem, { key: c.id, node: c, parent: node, index: i, depth: depth + 1, ctx } ) ) : [];
		return h( 'div', { className: 'nucleo-editor__group' }, row, ...kids );
	}

	/* ---------- Proprietà del nodo, generate dal manifest ---------- */

	function pickImage( setFields ) {
		const frame = wp.media( { title: __( 'Choose image', 'nucleo' ), library: { type: 'image' }, multiple: false } );
		frame.on( 'select', () => {
			const a = frame.state().get( 'selection' ).first().toJSON();
			const pairs = [ [ 'content.src', a.url ], [ 'content.alt', a.alt || '' ] ];
			if ( a.width ) {
				pairs.push( [ 'content.width', a.width ] );
			}
			if ( a.height ) {
				pairs.push( [ 'content.height', a.height ] );
			}
			setFields( pairs );
		} );
		frame.open();
	}

	/**
	 * Voci con campi propri (manifest content.<key>.item, es. tabs e accordion: title e html).
	 * Ogni voce ha i suoi campi e i comandi Su, Giù, Rimuovi; Aggiungi crea una voce vuota.
	 */
	function ItemsField( { label, def, value, onChange } ) {
		const items = Array.isArray( value ) ? value.filter( ( it ) => it && typeof it === 'object' ) : [];
		const keys = Object.keys( def.item );
		const blank = () => Object.fromEntries( keys.map( ( k ) => [ k, '' ] ) );
		const put = ( i, k, v ) => onChange( items.map( ( it, j ) => ( j === i ? { ...it, [ k ]: v } : it ) ) );
		const swap = ( i, j ) => {
			const next = [ ...items ];
			[ next[ i ], next[ j ] ] = [ next[ j ], next[ i ] ];
			onChange( next );
		};
		const btn = ( text, onClick, extra ) => h( Button, { variant: 'tertiary', size: 'small', onClick, ...extra }, text );
		return h( 'fieldset', { className: 'nucleo-editor__items' },
			h( 'legend', { className: 'components-base-control__label' }, label ),
			items.map( ( it, i ) => h( 'div', { key: i, className: 'nucleo-editor__item' },
				keys.map( ( k ) => {
					const sub = nucleoLabels.field( k, def.item[ k ] );
					const v = typeof it[ k ] === 'string' ? it[ k ] : '';
					return def.item[ k ].type === 'rich-text' || def.item[ k ].type === 'inline-rich-text'
						? h( RichTextField, { key: k, label: sub, value: v, onChange: ( x ) => put( i, k, x ) } )
						: h( TextControl, { key: k, label: sub, value: v, onChange: ( x ) => put( i, k, x ) } );
				} ),
				h( 'div', { className: 'nucleo-editor__item-actions' },
					btn( __( 'Up', 'nucleo' ), () => swap( i, i - 1 ), { disabled: i === 0 } ),
					btn( __( 'Down', 'nucleo' ), () => swap( i, i + 1 ), { disabled: i === items.length - 1 } ),
					btn( __( 'Remove', 'nucleo' ), () => onChange( items.filter( ( _, j ) => j !== i ) ), { isDestructive: true } ) ) ) ),
			h( Button, { variant: 'secondary', size: 'small', onClick: () => onChange( [ ...items, blank() ] ) }, __( 'Add item', 'nucleo' ) ) );
	}

	/** Area di codice HTML: carattere a larghezza fissa, nessun controllo ortografico. */
	function CodeField( { label, value, onChange, rows } ) {
		return h( TextareaControl, { className: 'nc-textarea--code', label, value: value || '', rows: rows || 8, spellCheck: false, onChange } );
	}

	/**
	 * Campo inline-rich-text con due modalità: Testo (solo se il valore è fatto di paragrafi e a capo) e HTML.
	 * Con formattazione diversa la modalità Testo non è disponibile.
	 */
	function RichTextField( { label, value, onChange } ) {
		const stored = value || '';
		const plain = nucleoLabels.toPlain( stored );
		const [ mode, setMode ] = useState( plain === null ? 'html' : 'text' );
		// Il draft conserva ciò che l'utente sta scrivendo (es. a capo finali) finché corrisponde al valore salvato.
		const [ draft, setDraft ] = useState( plain === null ? stored : plain );
		const asHtml = mode === 'html' || plain === null;
		const shown = nucleoLabels.fromPlain( draft ) === stored ? draft : plain;
		const modeBtn = ( m, text ) => h( 'button', {
			type: 'button',
			className: 'nc-segmented__item',
			'aria-pressed': String( ( m === 'html' ) === asHtml ),
			disabled: m === 'text' && plain === null,
			onClick: () => setMode( m ),
		}, text );
		return h( 'div', { className: 'nucleo-editor__rich' },
			h( 'div', { className: 'nc-segmented', role: 'group', 'aria-label': label },
				modeBtn( 'text', __( 'Text', 'nucleo' ) ),
				modeBtn( 'html', 'HTML' ) ),
			asHtml
				? h( CodeField, { label, value: stored, onChange } )
				: h( TextareaControl, {
					label,
					value: shown,
					onChange: ( v ) => {
						setDraft( v );
						onChange( nucleoLabels.fromPlain( v ) );
					},
				} ) );
	}

	/** Oggetti e liste di oggetti senza editor dedicato: JSON in un'area di codice, salvato solo se valido e del tipo giusto. */
	function JsonField( { label, value, isArray, onChange } ) {
		const shown = JSON.stringify( value === undefined ? ( isArray ? [] : {} ) : value, null, 2 );
		const [ draft, setDraft ] = useState( shown );
		const [ error, setError ] = useState( '' );
		return h( TextareaControl, {
			className: 'nc-textarea--code',
			label,
			rows: 6,
			spellCheck: false,
			value: error ? draft : shown,
			help: error || null,
			onChange: ( v ) => {
				setDraft( v );
				let parsed;
				try {
					parsed = JSON.parse( v );
				} catch ( e ) {
					setError( __( 'Invalid JSON: the change is not applied.', 'nucleo' ) );
					return;
				}
				if ( Array.isArray( parsed ) !== isArray || parsed === null || typeof parsed !== 'object' ) {
					setError( isArray ? __( 'A list [ ... ] is expected.', 'nucleo' ) : __( 'An object { ... } is expected.', 'nucleo' ) );
					return;
				}
				setError( '' );
				onChange( parsed );
			},
		} );
	}

	/**
	 * Campo per i tipi che il testo semplice non rappresenta: scelta da elenco, liste e oggetti.
	 * Restituisce undefined per i tipi semplici (testo, numero, interruttore), gestiti dal chiamante.
	 */
	function complexField( key, label, def, value, onChange ) {
		const isNum = def.type === 'integer' || def.type === 'number';
		if ( Array.isArray( def.enum ) ) {
			// Voce vuota quando il campo non ha un valore predefinito: niente scelta mostrata ma non salvata.
			const empty = def.default === undefined ? [ { label: '—', value: '' } ] : [];
			return h( SelectControl, {
				key,
				label,
				value: value === undefined || value === null ? '' : String( value ),
				options: [ ...empty, ...def.enum.map( ( v ) => ( { label: String( v ), value: String( v ) } ) ) ],
				onChange: ( v ) => onChange( v === '' ? null : ( isNum ? Number( v ) : v ) ),
			} );
		}
		if ( def.type === 'array' && def.item ) {
			return h( ItemsField, { key, label, def, value, onChange } );
		}
		if ( def.type === 'array' ) {
			const items = def.items || {};
			const list = Array.isArray( value ) ? value : [];
			if ( Array.isArray( items.enum ) ) {
				return h( 'fieldset', { key, className: 'nucleo-editor__items' },
					h( 'legend', { className: 'components-base-control__label' }, label ),
					items.enum.map( ( opt ) => h( CheckboxControl, {
						key: opt,
						label: String( opt ),
						checked: list.includes( opt ),
						onChange: ( on ) => onChange( on ? [ ...list, opt ] : list.filter( ( x ) => x !== opt ) ),
					} ) ) );
			}
			if ( items.type === 'string' ) {
				return h( TextareaControl, { key, label: label + ' ' + __( '(one item per line)', 'nucleo' ), value: list.join( '\n' ), onChange: ( v ) => onChange( v.split( '\n' ) ) } );
			}
			return h( JsonField, { key, label, value, isArray: true, onChange } );
		}
		if ( def.type === 'object' ) {
			return h( JsonField, { key, label, value, isArray: false, onChange } );
		}
		return undefined;
	}

	function ContentFields( { node, manifest, setField, setFields } ) {
		const content = node.content || {};
		return Object.entries( manifest.content || {} ).map( ( [ key, def ] ) => {
			const path = 'content.' + key;
			const value = content[ key ];
			const label = nucleoLabels.field( key, def );
			// sources di image: la scrive la libreria media, nessun campo.
			if ( node.type === 'nc/image' && key === 'sources' ) {
				return null;
			}
			const complex = complexField( key, label, def, value, ( v ) => setField( path, v ) );
			if ( complex !== undefined ) {
				return complex;
			}
			if ( def.type === 'inline-rich-text' ) {
				return h( RichTextField, { key: node.id + '.' + key, label, value, onChange: ( v ) => setField( path, v ) } );
			}
			if ( key === 'html' && def.type === 'string' ) {
				return h( CodeField, { key, label, value, rows: 12, onChange: ( v ) => setField( path, v ) } );
			}
			if ( def.type === 'integer' || def.type === 'number' ) {
				return h( TextControl, { key, type: 'number', label, value: value ?? '', onChange: ( v ) => setField( path, v === '' ? null : Number( v ) ) } );
			}
			// Testo con valore predefinito (es. etichette di search e slider): il default compare come segnaposto.
			const control = h( TextControl, { key, label, value: value ?? '', placeholder: typeof def.default === 'string' ? def.default : undefined, onChange: ( v ) => setField( path, v ) } );
			if ( node.type === 'nc/image' && key === 'src' ) {
				return h( 'div', { key, className: 'nucleo-editor__field' }, control,
					h( Button, { variant: 'secondary', size: 'small', onClick: () => pickImage( setFields ) }, __( 'Choose image...', 'nucleo' ) ) );
			}
			return control;
		} );
	}

	function SettingFields( { node, manifest, setField } ) {
		const settings = node.settings || {};
		return Object.entries( manifest.settings || {} ).map( ( [ key, def ] ) => {
			const path = 'settings.' + key;
			const value = settings[ key ] !== undefined ? settings[ key ] : def.default;
			const isNum = def.type === 'integer' || def.type === 'number';
			const label = nucleoLabels.field( key, def );
			const complex = complexField( key, label, def, value, ( v ) => setField( path, v ) );
			if ( complex !== undefined ) {
				return complex;
			}
			if ( def.type === 'boolean' ) {
				return h( ToggleControl, { key, label, checked: value === true, onChange: ( v ) => setField( path, v ) } );
			}
			return h( TextControl, {
				key,
				label,
				type: isNum ? 'number' : 'text',
				value: value === undefined || value === null ? '' : String( value ),
				onChange: ( v ) => setField( path, isNum ? ( v === '' ? null : Number( v ) ) : v ),
			} );
		} );
	}

	/** Campo di una proprietà CSS: valore stringa o {base,md,lg,xl} per il breakpoint scelto. */
	function styleField( node, prop, bp, setField ) {
		const raw = ( node.style || {} )[ prop ];
		const obj = typeof raw === 'string' ? { base: raw } : ( raw && typeof raw === 'object' ? raw : {} );
		const own = obj[ bp ];
		const from = own === undefined ? BPS.slice( 0, BPS.indexOf( bp ) ).reverse().find( ( b ) => obj[ b ] !== undefined ) : undefined;
		const help = from
			? __( 'Inherited from', 'nucleo' ) + ' ' + bpLabel( from ) + ': ' + obj[ from ]
			: __( 'CSS value or token, e.g. $space.4', 'nucleo' );
		return h( TextControl, {
			key: prop,
			label: nucleoLabels.style( prop ),
			help,
			value: own === undefined ? '' : String( own ),
			onChange: ( v ) => {
				const next = { ...obj };
				if ( v === '' ) {
					delete next[ bp ];
				} else {
					next[ bp ] = v;
				}
				setField( 'style.' + prop, Object.keys( next ).length ? next : '' );
			},
		} );
	}

	function StyleFields( { node, manifest, bp, onBp, setField } ) {
		const groups = ( manifest.supports && manifest.supports.style ) || [];
		const keys = CFG.styleKeys || {};
		const bar = h( 'div', { className: 'nucleo-editor__bps', role: 'group', 'aria-label': __( 'Breakpoint', 'nucleo' ) },
			BPS.map( ( b ) => h( Button, { key: b, size: 'small', variant: b === bp ? 'primary' : 'secondary', 'aria-pressed': b === bp, onClick: () => onBp( b ) }, bpLabel( b ) ) )
		);
		const sections = groups.map( ( group ) => h( 'fieldset', { key: group, className: 'nucleo-editor__group' },
			h( 'legend', null, group ),
			( keys[ group ] || [] ).map( ( prop ) => styleField( node, prop, bp, setField ) )
		) );
		return h( 'div', null, bar, ...sections );
	}

	function Inspector( { node, manifest, bp, onBp, setField, setFields } ) {
		if ( !node ) {
			return h( 'p', null, __( 'Select an element from the structure.', 'nucleo' ) );
		}
		if ( !manifest ) {
			return h( 'p', null, __( 'Loading block...', 'nucleo' ) );
		}
		const tabs = [
			{ name: 'content', title: __( 'Content', 'nucleo' ) },
			{ name: 'settings', title: __( 'Settings', 'nucleo' ) },
			{ name: 'style', title: __( 'Style', 'nucleo' ) },
		];
		return h( TabPanel, { tabs }, ( tab ) => {
			if ( tab.name === 'content' ) {
				return h( ContentFields, { node, manifest, setField, setFields } );
			}
			if ( tab.name === 'settings' ) {
				return h( SettingFields, { node, manifest, setField } );
			}
			return h( StyleFields, { node, manifest, bp, onBp, setField } );
		} );
	}

	/* ---------- Schede dei pannelli (window.nucleoPanels) ---------- */

	function PanelTabs( { panels, props } ) {
		const tabs = panels.map( ( p ) => ( { name: String( p.id ), title: String( p.title || p.id ) } ) );
		return h( TabPanel, { tabs }, ( tab ) => {
			const panel = panels.find( ( p ) => String( p.id ) === tab.name );
			try {
				return panel ? panel.render( props ) : null;
			} catch ( e ) {
				return h( 'p', null, __( 'Panel not available.', 'nucleo' ) );
			}
		} );
	}

	/* ---------- Qualità ---------- */

	function IssueRow( { issue, acked, onAck, select, describe } ) {
		const [ reason, setReason ] = useState( '' );
		const blocking = issue.severity === 'error';
		const node = ( issue.nodes || [] )[ 0 ] || '';
		const nodeId = issueNodeId( node );
		return h( 'li', { className: 'nucleo-editor__issue' + ( blocking ? ' is-error' : '' ) },
			h( 'strong', { title: issue.rule }, nucleoLabels.rule( issue.rule ) ), ' ', issue.message,
			nodeId && h( 'div', null, h( Button, { variant: 'link', size: 'small', onClick: () => select( nodeId ) }, describe( nodeId ) ) ),
			blocking && !acked && h( TextareaControl, { label: __( 'Reason', 'nucleo' ), value: reason, onChange: setReason } ),
			blocking && !acked && h( Button, { variant: 'secondary', size: 'small', disabled: reason.trim() === '', onClick: () => onAck( { rule: issue.rule, node, reason: reason.trim() } ) }, __( 'Acknowledge', 'nucleo' ) ),
			acked && h( 'span', null, ' ' + __( 'Acknowledged', 'nucleo' ) )
		);
	}

	function QualityPanel( { issues, errors, acks, onAck, select, describe } ) {
		return h( 'section', { className: 'nucleo-editor__quality', 'aria-label': __( 'Quality', 'nucleo' ) },
			h( 'h2', null, __( 'Quality', 'nucleo' ) + ' (' + issues.length + ')' ),
			errors.length > 0 && h( 'ul', null, errors.map( ( e, i ) => h( 'li', { key: i, className: 'is-error' }, String( e.message || e.path || '' ) ) ) ),
			h( 'ul', null, issues.map( ( issue, i ) => h( IssueRow, {
				key: i,
				issue,
				acked: acks.some( ( a ) => a.rule === issue.rule && nodeKey( a.node ) === nodeKey( ( issue.nodes || [] )[ 0 ] ) ),
				onAck,
				select,
				describe,
			} ) ) )
		);
	}

	/** Titolo e descrizione del blocco nella lingua dell'utente (CFG.blockTexts, tradotti da PHP). */
	function localized( block ) {
		const t = ( CFG.blockTexts || {} )[ block.name ] || {};
		return { ...block, title: t.title || block.title, description: t.description || block.description };
	}

	/* ---------- App ---------- */

	function App() {
		const [ doc, setDoc ] = useState( null );
		// Contenuto senza documento Nucleo: l'editor propone di iniziarne uno invece di restare in caricamento.
		const [ missing, setMissing ] = useState( false );
		const [ starting, setStarting ] = useState( false );
		const [ revision, setRevision ] = useState( 0 );
		const [ status, setStatus ] = useState( '' );
		const [ ops, setOps ] = useState( [] );
		const [ acks, setAcks ] = useState( [] );
		const [ past, setPast ] = useState( [] );
		const [ future, setFuture ] = useState( [] );
		const [ selected, setSelected ] = useState( null );
		const [ open, setOpen ] = useState( () => new Set() );
		const [ rowMenu, setRowMenu ] = useState( null );
		const [ manifests, setManifests ] = useState( {} );
		const [ catalog, setCatalog ] = useState( {} );
		const [ issues, setIssues ] = useState( [] );
			const [ score, setScore ] = useState( null );
		const [ errors, setErrors ] = useState( [] );
		const [ notice, setNotice ] = useState( null );
		const [ conflict, setConflict ] = useState( false );
		const [ savedAt, setSavedAt ] = useState( null );
		const [ view, setView ] = useState( initialView );
		const [ leftTab, setLeftTab ] = useState( 'structure' );
		// Colonna destra: elemento selezionato oppure pannelli della pagina (layout, design system, stato...).
		const [ rightTab, setRightTab ] = useState( 'element' );
		// Larghezza della colonna Struttura, trascinabile dal bordo e ricordata nel browser.
		const [ treeWidth, setTreeWidth ] = useState( () => clampTree( Number( readStored( 'nucleo-tree-width' ) ) || TREE_DEFAULT ) );
		const resizeTree = ( width ) => {
			const next = clampTree( width );
			setTreeWidth( next );
			writeStored( 'nucleo-tree-width', String( next ) );
		};
		const startResize = ( e ) => {
			e.preventDefault();
			const startX = e.clientX;
			const startW = treeWidth;
			const onMove = ( ev ) => resizeTree( startW + ev.clientX - startX );
			const onUp = () => {
				window.removeEventListener( 'pointermove', onMove );
				window.removeEventListener( 'pointerup', onUp );
				document.body.classList.remove( 'nucleo-is-resizing' );
			};
			document.body.classList.add( 'nucleo-is-resizing' );
			window.addEventListener( 'pointermove', onMove );
			window.addEventListener( 'pointerup', onUp );
		};
		const keyResize = ( e ) => {
			const step = e.shiftKey ? 40 : 10;
			if ( e.key === 'ArrowLeft' || e.key === 'ArrowRight' ) {
				e.preventDefault();
				resizeTree( treeWidth + ( e.key === 'ArrowRight' ? step : -step ) );
			} else if ( e.key === 'Home' || e.key === 'End' ) {
				e.preventDefault();
				resizeTree( e.key === 'Home' ? TREE_MIN : TREE_MAX );
			}
		};
		const [ showRevisions, setShowRevisions ] = useState( false );
		const [ narrow, setNarrow ] = useState( () => window.innerWidth < NARROW_PX );
		const [ bp, setBp ] = useState( 'base' );
		// Anteprima per dispositivo: misura reale ridotta in scala nello spazio libero della colonna centrale.
		const [ device, setDevice ] = useState( 'desktop' );
		const [ orientation, setOrientation ] = useState( 'portrait' );
		// Cornice del dispositivo con le barre di sistema: solo per telefono e tablet.
		const [ mockup, setMockup ] = useState( false );
		const [ os, setOs ] = useState( 'ios' );
		// Barra dell'indirizzo del browser nel mockup: si riduce scorrendo in giù, torna scorrendo in su.
		const [ urlCollapsed, setUrlCollapsed ] = useState( false );
		const [ avail, setAvail ] = useState( { w: 0, h: 0 } );
		const previewRef = useRef( null );
		const [ preview, setPreview ] = useState( { html: '', css: '', head: '' } );
		const [ busy, setBusy ] = useState( false );
		const [ texts, setTexts ] = useState( [] );
		const [ textRev, setTextRev ] = useState( 0 );
		const [ textEdits, setTextEdits ] = useState( {} );
		const requested = useRef( new Set() );
		const frameRef = useRef( null );
		useEffect( () => {
			const el = previewRef.current;
			if ( !el || typeof ResizeObserver === 'undefined' ) {
				return undefined;
			}
			const measure = () => {
				const cs = window.getComputedStyle( el );
				const label = el.querySelector( '.nucleo-editor__preview-label' );
				const gap = parseFloat( cs.rowGap ) || 0;
				setAvail( {
					w: el.clientWidth - parseFloat( cs.paddingLeft ) - parseFloat( cs.paddingRight ),
					h: el.clientHeight - parseFloat( cs.paddingTop ) - parseFloat( cs.paddingBottom ) - ( label ? label.offsetHeight + gap : 0 ),
				} );
			};
			const ro = new ResizeObserver( measure );
			ro.observe( el );
			measure();
			return () => ro.disconnect();
			// Cambiando vista l'anteprima si rimonta: l'osservatore va riattaccato al nuovo elemento.
		}, [ !!doc, view, narrow ] );
		const dragRef = useRef( null );
		const selRef = useRef( null );
		const conflictRef = useRef( null );
		// Op già inviate con il salvataggio in corso, e copie sempre aggiornate di ops e textEdits per le promise.
		const inFlight = useRef( 0 );
		const opsRef = useRef( ops );
		opsRef.current = ops;
		const textEditsRef = useRef( textEdits );
		textEditsRef.current = textEdits;
		const actRef = useRef( null );
		const inlineRef = useRef( null );
		const insertDragRef = useRef( null );
		const dropRef = useRef( null );
		const [ hint, setHint ] = useState( null );
		selRef.current = selected;
		const seq = useRef( 0 );
		const postId = CFG.postId;
		// Sotto 1024 px, o per i ruoli "solo testi", la vista è sempre quella dei testi.
		const textOnly = onlyTextRoles( CFG.roles, CFG.textOnlyRoles );
		const shownView = narrow || textOnly ? 'texts' : view;

		// pending: op locali non ancora salvate da riapplicare sul documento del server.
		const reload = ( pending = [] ) => call( 'nucleo/doc-get', { postId, depth: 50 } ).then( ( res ) => {
			if ( res.ok ) {
				setMissing( false );
				setDoc( rebase( res.document, pending, applyLocal ) );
				setRevision( res.revision );
				setStatus( typeof res.status === 'string' ? res.status : '' );
			} else if ( res.error && res.error.code === 'not_found' ) {
				setMissing( true );
			} else {
				setNotice( { status: 'error', text: msg( res, __( 'Document not loaded.', 'nucleo' ) ) } );
			}
		} );

		const startDocument = () => {
			setStarting( true );
			const blank = { schema: 1, kind: 'page', root: { id: 'r', type: 'nc/section', children: [] } };
			call( 'nucleo/doc-create', { postId, document: blank } ).then( ( res ) => {
				setStarting( false );
				if ( res.ok ) {
					reload();
				} else {
					setNotice( { status: 'error', text: msg( res, __( 'Nucleo document not created.', 'nucleo' ) ) } );
				}
			} );
		};

		// Documento messo da parte tornando all'editor di WordPress: lo si rimette in uso invece di crearne uno nuovo.
		const restoreDocument = () => {
			setStarting( true );
			wp.apiFetch( { path: '/nucleo/v1/editor-mode', method: 'POST', data: { postId, mode: 'nucleo' } } ).then( () => {
				setStarting( false );
				reload();
			}, ( err ) => {
				setStarting( false );
				setNotice( { status: 'error', text: ( err && err.message ) || __( 'Nucleo document not restored.', 'nucleo' ) } );
			} );
		};

		// sent: valori appena salvati. Si tolgono solo quelli, le modifiche scritte durante il salvataggio restano.
		const loadTexts = ( sent ) => call( 'nucleo/doc-texts', { postId } ).then( ( res ) => {
			if ( res.ok ) {
				setTexts( res.texts );
				setTextRev( res.revision );
				setTextEdits( sent ? ( cur ) => Object.fromEntries( Object.entries( cur ).filter( ( [ k, v ] ) => sent[ k ] !== v ) ) : {} );
			} else {
				setNotice( { status: 'error', text: msg( res, __( 'Texts not loaded.', 'nucleo' ) ) } );
			}
		} );

		// Caricamento iniziale: documento e catalogo.
		useEffect( () => {
			reload().then( () => {} );
			call( 'nucleo/catalog-list', {} ).then( ( res ) => {
				if ( res.ok ) {
					setCatalog( Object.fromEntries( res.blocks.map( ( b ) => [ b.name, localized( b ) ] ) ) );
				}
			} );
		}, [] );

		// Manifest dei tipi usati nel documento, richiesti una sola volta.
		useEffect( () => {
			if ( !doc ) {
				return;
			}
			// Con la scheda Inserisci aperta servono i manifest di tutto il catalogo.
			const names = leftTab === 'insert' ? [ ...typesIn( doc ), ...Object.keys( catalog ) ] : typesIn( doc );
			names.forEach( ( name ) => {
				if ( requested.current.has( name ) ) {
					return;
				}
				requested.current.add( name );
				call( 'nucleo/catalog-get', { name } ).then( ( res ) => {
					if ( res.ok && res.block ) {
						setManifests( ( m ) => ( { ...m, [ name ]: localized( res.block ) } ) );
					}
				} );
			} );
		}, [ doc, leftTab, catalog ] );

		// Anteprima: dopo 300 ms senza modifiche. Risposte vecchie vengono scartate.
		useEffect( () => {
			if ( !doc ) {
				return undefined;
			}
			const t = setTimeout( () => {
				const my = ++seq.current;
				previewDoc( doc, postId ).then( ( res ) => {
					if ( res.ok && my === seq.current ) {
						// Nodi non validi: segnaposto nell'anteprima e motivo nel pannello Quality, senza aspettare il salvataggio.
						setErrors( res.errors || [] );
						setPreview( {
							html: res.html || '',
							css: res.css || '',
							head: ( res.styles || [] ).map( ( u ) => '<link rel="stylesheet" href="' + encodeURI( u ) + '">' ).join( '' ) + '<style>' + ( res.tokensCss || '' ) + '</style>',
						} );
					}
				} );
			}, 300 );
			return () => clearTimeout( t );
		}, [ doc ] );

		// Contorno del nodo selezionato, dopo ogni selezione e ogni nuovo render dell'iframe.
		useEffect( () => {
			markSelected( frameRef.current, selected );
		}, [ selected, preview ] );

		// Una nuova selezione riporta la colonna destra sull'elemento.
		useEffect( () => {
			if ( selected ) {
				setRightTab( 'element' );
			}
		}, [ selected ] );

		// Selezione da schema o da anteprima: apre gli antenati nello schema e porta il nodo in vista da entrambe le parti.
		useEffect( () => {
			if ( !selected || !doc ) {
				return;
			}
			const up = tree.ancestors( doc, selected );
			setOpen( ( prev ) => ( up.every( ( id ) => prev.has( id ) ) ? prev : new Set( [ ...prev, ...up ] ) ) );
			revealInFrame( frameRef.current, selected );
			requestAnimationFrame( () => requestAnimationFrame( () => revealRow( selected ) ) );
		}, [ selected ] );

		useEffect( () => {
			// Testi non salvati: tornando alla vista testi (anche per la larghezza) restano quelli scritti.
			if ( shownView === 'texts' && Object.keys( textEdits ).length === 0 ) {
				loadTexts();
			}
			// La vista testi legge il documento salvato: le modifiche di struttura non salvate non compaiono.
			if ( shownView === 'texts' && ops.length > 0 ) {
				setNotice( { status: 'warning', text: __( 'The texts show the saved version: save the structure changes to see them here.', 'nucleo' ) } );
			}
		}, [ shownView ] );

		// Modifiche non salvate: il browser chiede conferma prima di lasciare la pagina.
		const dirty = ops.length > 0 || Object.keys( textEdits ).length > 0;
		useEffect( () => {
			if ( !dirty ) {
				return undefined;
			}
			const guard = ( event ) => {
				event.preventDefault();
				event.returnValue = '';
			};
			window.addEventListener( 'beforeunload', guard );
			return () => window.removeEventListener( 'beforeunload', guard );
		}, [ dirty ] );

		// Sotto 1024 px si passa alla vista testi; sopra si torna alla larghezza scelta.
		useEffect( () => {
			const onResize = () => setNarrow( window.innerWidth < NARROW_PX );
			window.addEventListener( 'resize', onResize );
			return () => window.removeEventListener( 'resize', onResize );
		}, [] );

		// Conflitto: il focus va al primo pulsante della notice, così chi usa la tastiera lo trova subito.
		useEffect( () => {
			if ( conflict && conflictRef.current ) {
				conflictRef.current.focus();
			}
		}, [ conflict ] );

		// Scorciatoie: il mapping da evento ad azione sta in shortcuts.js, le azioni in actRef (ultimo render).
		useEffect( () => {
			const onKey = ( e ) => {
				const action = window.nucleoShortcuts ? window.nucleoShortcuts.keyAction( e ) : null;
				if ( action && actRef.current ) {
					e.preventDefault();
					actRef.current( action );
				}
			};
			document.addEventListener( 'keydown', onKey );
			return () => document.removeEventListener( 'keydown', onKey );
		}, [] );

		// Modifiche locali. Un solo passo di set sullo stesso campo si fonde con il precedente.
		const editMany = ( list ) => {
			const next = queueEdit( ops, list, inFlight.current );
			if ( !next.merge ) {
				setPast( ( p ) => [ ...p.slice( -99 ), { doc, ops } ] );
				setFuture( [] );
			}
			setOps( next.ops );
			setDoc( list.reduce( ( d, op ) => applyLocal( d, op ), doc ) );
		};

		const undo = () => {
			const prev = past[ past.length - 1 ];
			// Durante un salvataggio la storia contiene op già in volo: si annulla dopo la risposta.
			if ( !prev || inFlight.current > 0 ) {
				return;
			}
			setFuture( ( f ) => [ ...f, { doc, ops } ] );
			setPast( past.slice( 0, -1 ) );
			setDoc( prev.doc );
			setOps( prev.ops );
		};

		const redo = () => {
			const next = future[ future.length - 1 ];
			if ( !next || inFlight.current > 0 ) {
				return;
			}
			setPast( ( p ) => [ ...p, { doc, ops } ] );
			setFuture( future.slice( 0, -1 ) );
			setDoc( next.doc );
			setOps( next.ops );
		};

		const setFields = ( pairs ) => editMany( pairs.map( ( [ path, value ] ) => ( { op: 'set', id: selected, path, value } ) ) );
		// Campo testuale: l'anteprima cambia subito, il server ricompila dopo 300 ms (effetto su doc).
		const setField = ( path, value ) => {
			const def = fieldDef( manifestFor( doc, manifests, selected ), path );
			if ( selected && path.indexOf( 'content.' ) === 0 && def && TEXT_TYPES.includes( def.type ) ) {
				patchText( frameRef.current, selected, def.type === 'inline-rich-text' ? nucleoLabels.toPlain( value || '' ) : String( value ?? '' ) );
			}
			setFields( [ [ path, value ] ] );
		};

		// Doppio clic nell'anteprima: testo modificabile sul posto, oppure il primo campo nella colonna destra.
		// La ref si aggiorna a ogni render: l'ascoltatore dell'iframe legge sempre documento e manifest correnti.
		inlineRef.current = {
			fieldFor: ( id ) => {
				const m = manifestFor( doc, manifests, id );
				return m ? nucleoInlineEdit.editableField( m ) : null;
			},
			onCommit: ( id, key, value ) => {
				setSelected( id );
				editMany( [ { op: 'set', id, path: 'content.' + key, value } ] );
			},
			onOpen: ( id ) => {
				setSelected( id );
				setTimeout( () => {
					const field = document.querySelector( '.nucleo-editor__inspector .components-tab-panel__tab-content :is(textarea, input:not([type=checkbox]))' );
					if ( field ) {
						field.focus();
					}
				}, 60 );
			},
		};

		// Trascinamento di un blocco dall'inserter nell'anteprima. Dal blocco sotto il puntatore si risale
		// agli antenati finché una posizione (prima, dentro, dopo) è ammessa. La ref legge sempre il render corrente.
		dropRef.current = ( ev, d, drop ) => {
			const type = insertDragRef.current;
			const manifest = type && manifests[ type ];
			if ( !manifest ) {
				return;
			}
			const child = { type };
			let found = null;
			for ( let el = ev.target.closest ? ev.target.closest( '[data-nc-id]' ) : null; el && !found; el = el.parentElement && el.parentElement.closest( '[data-nc-id]' ) ) {
				const id = el.getAttribute( 'data-nc-id' );
				const node = findNode( doc, id );
				const r = el.getBoundingClientRect();
				const first = tree.dropPos( r.top, r.bottom, ev.clientY, !!node && canContain( node, child ) );
				for ( const pos of [ first, ...[ 'inside', 'before', 'after' ].filter( ( p ) => p !== first && p !== 'inside' ) ] ) {
					const place = tree.insertTarget( doc, id, pos, child, canContain );
					if ( place ) {
						found = { el, pos, place };
						break;
					}
				}
			}
			if ( !found ) {
				showDrop( d, null );
				return;
			}
			ev.preventDefault();
			if ( !drop ) {
				ev.dataTransfer.dropEffect = 'copy';
				showDrop( d, found.el, found.pos );
				return;
			}
			showDrop( d, null );
			insertDragRef.current = null;
			const ins = window.nucleoInserter;
			onInsert( ins.defaultNode( manifest, ins.newId( doc ) ), found.place );
		};
		const onBlockDrag = ( name ) => {
			insertDragRef.current = name;
			const d = !name && frameRef.current && frameRef.current.contentDocument;
			if ( d ) {
				showDrop( d, null );
			}
		};

		// Clic nell'anteprima: seleziona il nodo marcato più vicino al bersaglio.
		const onFrameLoad = ( e ) => {
			const frame = e.target;
			const d = frame.contentDocument;
			if ( !d ) {
				return;
			}
			d.addEventListener( 'click', ( ev ) => {
				ev.preventDefault();
				const id = tree.nodeIdAt( ev.target );
				if ( id ) {
					setSelected( id );
				}
			} );
			nucleoInlineEdit.bind( d, {
				fieldFor: ( id ) => inlineRef.current.fieldFor( id ),
				onCommit: ( id, key, value ) => inlineRef.current.onCommit( id, key, value ),
				onOpen: ( id ) => inlineRef.current.onOpen( id ),
			} );
			d.addEventListener( 'dragover', ( ev ) => dropRef.current( ev, d, false ) );
			d.addEventListener( 'drop', ( ev ) => dropRef.current( ev, d, true ) );
			d.addEventListener( 'dragleave', ( ev ) => {
				if ( !ev.relatedTarget ) {
					showDrop( d, null );
				}
			} );
			markSelected( frame, selRef.current );
			const win = frame.contentWindow;
			if ( win ) {
				let lastY = win.scrollY;
				win.addEventListener( 'scroll', () => {
					const y = win.scrollY;
					if ( y <= 0 || y < lastY - 4 ) {
						setUrlCollapsed( false );
					} else if ( y > lastY + 4 && y > 24 ) {
						setUrlCollapsed( true );
					}
					lastY = y;
				}, { passive: true } );
			}
		};

		const onAck = ( ack ) => {
			setAcks( ( a ) => [ ...a.filter( ( x ) => x.rule !== ack.rule || x.node !== ack.node ), ack ] );
			setNotice( { status: 'info', text: __( 'Reason recorded: save to apply it.', 'nucleo' ) } );
		};

		// Salvataggio: accumula le op e le invia con la revisione letta.
		const save = ( list = ops ) => {
			if ( list.length === 0 ) {
				return Promise.resolve( true );
			}
			setBusy( true );
			inFlight.current = list.length;
			return call( 'nucleo/doc-patch', { postId, baseRevision: revision, ops: list, acknowledge: acks } ).then( ( res ) => {
				setBusy( false );
				inFlight.current = 0;
				setIssues( ( res.quality && res.quality.issues ) || [] );
				setScore( scoreOf( res ) );
				setErrors( res.errors || [] );
				if ( res.ok ) {
					// Le op arrivate durante la richiesta restano: si tolgono solo quelle inviate.
					// Le op scritte durante la richiesta restano in coda e si riapplicano sul documento salvato.
					const pending = opsRef.current.slice( list.length );
					setOps( ( current ) => current.slice( list.length ) );
					setAcks( [] );
					setPast( [] );
					setFuture( [] );
					setRevision( res.revision );
					setConflict( false );
					setSavedAt( new Date() );
					setNotice( { status: 'success', text: __( 'Changes saved.', 'nucleo' ) } );
					return reload( pending ).then( () => true );
				}
				if ( res.error && res.error.code === 'conflict' ) {
					setConflict( true );
				} else {
					setNotice( { status: 'error', text: msg( res, __( 'Save failed. Check the quality.', 'nucleo' ) ) } );
				}
				return false;
			} );
		};

		// Ricarica dopo un conflitto: la copia locale parte da una revisione superata, quindi si scarta.
		const reloadAfterConflict = () => {
			setConflict( false );
			setOps( [] );
			setAcks( [] );
			setPast( [] );
			setFuture( [] );
			setNotice( null );
			reload();
		};

		// Ripristino da revisioni: il documento sul server cambia, la copia locale non è più valida.
		const onRestored = () => {
			setShowRevisions( false );
			setOps( [] );
			setAcks( [] );
			setPast( [] );
			setFuture( [] );
			setConflict( false );
			setNotice( { status: 'success', text: __( 'Revision restored.', 'nucleo' ) } );
			reload();
		};

		// Pubblica esattamente quello che si vede: prima la struttura, poi i testi modificati nella vista testi.
		// Dopo la struttura i testi si rileggono: la revisione è cambiata e i nodi possono essere diversi.
		const publish = () => {
			save().then( ( saved ) => {
				if ( !saved || !Object.keys( textEditsRef.current ).length ) {
					return saved;
				}
				return call( 'nucleo/doc-texts', { postId } ).then( ( res ) => ( res.ok ? saveTexts( res ) : false ) );
			} ).then( ( saved ) => {
				if ( !saved ) {
					return;
				}
				setBusy( true );
				call( 'nucleo/doc-publish', { postId, acknowledge: acks } ).then( ( res ) => {
					setBusy( false );
					setIssues( ( res.quality && res.quality.issues ) || [] );
					setScore( scoreOf( res ) );
					setErrors( res.errors || [] );
					if ( res.ok ) {
						setNotice( { status: 'success', text: __( 'Page published.', 'nucleo' ) } );
						reload();
					} else {
						setNotice( { status: 'error', text: msg( res, __( 'Publish failed.', 'nucleo' ) ) } );
					}
				} );
			} );
		};

		// fresh: testi e revisione appena riletti (Publish dopo la struttura); senza, quelli caricati nella vista.
		const saveTexts = ( fresh ) => {
			if ( !fresh && opsRef.current.length ) {
				setNotice( { status: 'warning', text: __( 'Save the structure changes first.', 'nucleo' ) } );
				return Promise.resolve( false );
			}
			// Un campo mostrato come testo semplice si salva come HTML di paragrafi.
			const changed = changedTexts( fresh ? fresh.texts : texts, textEditsRef.current, ( t, v ) => {
				const plain = plainOf( fieldDef( manifestFor( doc, manifests, t.id ), t.path ), t.text );
				return plain !== null ? nucleoLabels.fromPlain( v ) : v;
			} );
			if ( !changed.length ) {
				return Promise.resolve( true );
			}
			const sent = Object.fromEntries( changed.map( ( c ) => [ c.id + '|' + c.path, textEditsRef.current[ c.id + '|' + c.path ] ] ) );
			setBusy( true );
			return call( 'nucleo/doc-texts-set', { postId, baseRevision: fresh ? fresh.revision : textRev, texts: changed } ).then( ( res ) => {
				setBusy( false );
				if ( res.ok ) {
					setConflict( false );
					setSavedAt( new Date() );
					setNotice( { status: 'success', text: __( 'Texts saved.', 'nucleo' ) } );
					return reload( opsRef.current ).then( () => loadTexts( sent ) ).then( () => true );
				}
				if ( res.error && res.error.code === 'conflict' ) {
					setConflict( true );
				} else {
					setNotice( { status: 'error', text: msg( res, __( 'Text save failed.', 'nucleo' ) ) } );
				}
				return false;
			} );
		};

		if ( !doc ) {
			const loading = notice && h( Notice, { status: notice.status, isDismissible: true, onRemove: () => setNotice( null ) }, notice.text );
			if ( missing ) {
				return h( 'div', { className: 'nucleo-editor nucleo-editor__start' },
					loading,
					h( 'div', { className: 'nc-card' },
						h( 'h1', null, __( 'This content has no Nucleo document yet', 'nucleo' ) ),
						h( 'p', null, __( 'Start a Nucleo document to build this content with Nucleo blocks. Type, title and status stay as they are.', 'nucleo' ) ),
						CFG.hasContent && h( Notice, { status: 'warning', isDismissible: false },
							__( 'The current text becomes Nucleo text blocks: paragraphs, headings and list items keep bold, italic and links. Images and other elements are not carried over. Nucleo keeps a copy of the original text.', 'nucleo' ) ),
						h( 'div', { className: 'nucleo-editor__start-actions' },
							CFG.hasInactive && h( Button, { variant: 'primary', isBusy: starting, disabled: starting, onClick: restoreDocument }, __( 'Restore the Nucleo document', 'nucleo' ) ),
							h( Button, { variant: CFG.hasInactive ? 'secondary' : 'primary', isBusy: starting, disabled: starting, onClick: startDocument }, CFG.hasInactive ? __( 'Start a new Nucleo document', 'nucleo' ) : __( 'Start with Nucleo', 'nucleo' ) ),
							CFG.wpEditUrl && h( Button, { variant: 'secondary', href: CFG.wpEditUrl }, __( 'Open in the WordPress editor', 'nucleo' ) )
						)
					)
				);
			}
			return h( 'div', { className: 'nucleo-editor' }, loading || h( Spinner, null ) );
		}

		// Ogni azione sulla struttura è un'op. Duplica salva subito: il server riassegna gli id.
		// Figli ammessi: manifest.children del genitore (true = qualsiasi tipo, lista = tipi ammessi).
		const canContain = ( parent, child ) => {
			const allowed = ( manifests[ parent.type ] || {} ).children;
			return allowed === true || ( Array.isArray( allowed ) && allowed.includes( child.type ) );
		};
		const moveFor = ( from, to, pos ) => tree.moveTarget( doc, from, to, pos, canContain );

		const ctx = {
			selected,
			open,
			openMenu: setRowMenu,
			toggle: ( id ) => setOpen( ( prev ) => {
				const next = new Set( prev );
				if ( !next.delete( id ) ) {
					next.add( id );
				}
				return next;
			} ),
			hint,
			setHint,
			dragRef,
			canDrop: ( from, to, pos ) => moveFor( from, to, pos ) !== null,
			drop: ( from, to, pos ) => {
				const t = moveFor( from, to, pos );
				if ( t ) {
					editMany( [ { op: 'move', id: from, parent: t.parent, index: t.index } ] );
				}
			},
			manifests,
			catalog,
			select: setSelected,
			move: ( id, delta ) => {
				const op = moveOp( doc, id, delta );
				if ( op ) {
					editMany( [ op ] );
				}
			},
			duplicate: ( id ) => {
				const op = { op: 'duplicate', id };
				const list = [ ...ops, op ];
				editMany( [ op ] );
				save( list );
			},
			remove: ( id ) => {
				if ( selected === id ) {
					setSelected( null );
				}
				editMany( [ { op: 'remove', id } ] );
			},
		};

		// Inserimento dal pannello: l'op insert come le altre op locali. Il nuovo nodo viene selezionato.
		const onInsert = ( node, place ) => {
			if ( place.parent === undefined ) {
				return;
			}
			editMany( [ { op: 'insert', parent: place.parent, index: place.index, node } ] );
			setSelected( node.id );
			setLeftTab( 'structure' );
		};

		// Azioni delle scorciatoie (vedi shortcuts.js). Il listener le chiama con l'ultimo render.
		actRef.current = ( action ) => {
			if ( action === 'undo' ) {
				return undo();
			}
			if ( action === 'redo' ) {
				return redo();
			}
			if ( action === 'save' ) {
				save();
				return undefined;
			}
			if ( action === 'insert' ) {
				if ( shownView === 'structure' ) {
					setLeftTab( 'insert' );
				}
				return undefined;
			}
			if ( action === 'deselect' ) {
				setSelected( null );
				return undefined;
			}
			if ( !selected || selected === doc.root.id ) {
				return undefined;
			}
			if ( action === 'duplicate' ) {
				return ctx.duplicate( selected );
			}
			if ( action === 'remove' ) {
				if ( window.confirm( __( 'Delete this element?', 'nucleo' ) ) ) {
					ctx.remove( selected );
				}
				return undefined;
			}
			if ( action === 'moveUp' ) {
				return ctx.move( selected, -1 );
			}
			if ( action === 'moveDown' ) {
				return ctx.move( selected, 1 );
			}
			return undefined;
		};

		const node = selected ? findNode( doc, selected ) : null;
		const panels = ( window.nucleoPanels || [] ).filter( ( p ) => p && typeof p.render === 'function' );
		const panelProps = { call, postId, doc, selected: node, manifest: node ? manifests[ node.type ] || null : null, setField };
		const noticeEl = notice && h( Notice, { className: 'nucleo-editor__notice', status: notice.status, isDismissible: true, onRemove: () => setNotice( null ) }, notice.text );
		const isStructure = shownView === 'structure';
		const Inserter = window.nucleoInserter && window.nucleoInserter.InserterPanel;
		const Revisions = window.nucleoRevisions && window.nucleoRevisions.RevisionsDrawer;

		/* Barra superiore */

		let statusLabel = __( 'Revision', 'nucleo' ) + ' ' + revision;
		let statusClass = 'nc-pill nc-pill--neutral';
		if ( status === 'published' ) {
			statusLabel = '✓ ' + __( 'Published', 'nucleo' );
			statusClass = 'nc-pill nc-pill--ok';
		} else if ( status === 'draft' ) {
			statusLabel = '▲ ' + __( 'Draft', 'nucleo' );
			statusClass = 'nc-pill nc-pill--warn';
		}

		const blocking = errors.length > 0 || issues.some( ( i ) => i.severity === 'error' );
		const qualityTone = blocking ? 'err' : issues.length > 0 ? 'warn' : 'ok';
		const qualityGlyph = { ok: '✓', warn: '▲', err: '✕' }[ qualityTone ];
		const qualityClass = 'nc-pill nc-pill--' + qualityTone;

		const viewBtn = ( name, label ) => h( Button, {
			size: 'small',
			variant: shownView === name ? 'primary' : 'secondary',
			'aria-pressed': shownView === name,
			disabled: ( narrow || textOnly ) && name === 'structure',
			onClick: () => setView( name ),
		}, label );

		// Icona del telefono con le frecce curve: interruttore Verticale/Orizzontale accanto al dispositivo attivo che ruota.
		const rotateIcon = h( 'svg', { width: 20, height: 20, viewBox: '0 0 24 24', 'aria-hidden': 'true', focusable: 'false' },
			h( 'rect', { x: 8, y: 5, width: 8, height: 14, rx: 1.5, fill: 'none', stroke: 'currentColor', strokeWidth: 1.6 } ),
			h( 'path', { d: 'M3.5 10a8.5 8.5 0 0 1 3-5.6M6.5 2.6v2.2H4.3', fill: 'none', stroke: 'currentColor', strokeWidth: 1.6, strokeLinecap: 'round', strokeLinejoin: 'round' } ),
			h( 'path', { d: 'M20.5 14a8.5 8.5 0 0 1-3 5.6M17.5 21.4v-2.2h2.2', fill: 'none', stroke: 'currentColor', strokeWidth: 1.6, strokeLinecap: 'round', strokeLinejoin: 'round' } )
		);
		const landscape = orientation === 'landscape';
		const widths = h( 'div', { className: 'nucleo-editor__group-btns', role: 'group', 'aria-label': __( 'Preview device', 'nucleo' ) },
			SIZE.DEVICES.map( ( d ) => [
				h( Button, { key: d.id, size: 'small', variant: device === d.id ? 'primary' : 'secondary', 'aria-pressed': device === d.id, onClick: () => setDevice( d.id ) }, DEVICE_LABELS[ d.id ] ),
				device === d.id && d.rotates && h( Button, {
					key: d.id + '-rotate',
					size: 'small',
					className: 'nucleo-editor__rotate',
					variant: landscape ? 'primary' : 'secondary',
					icon: rotateIcon,
					label: __( 'Landscape', 'nucleo' ),
					showTooltip: true,
					'aria-pressed': landscape,
					onClick: () => setOrientation( landscape ? 'portrait' : 'landscape' ),
				} ),
			] )
		);

		const topbar = h( 'div', { className: 'nucleo-editor__topbar' },
			h( 'div', { className: 'nucleo-editor__topbar-start' },
				h( 'a', { className: 'nucleo-editor__back', href: 'admin.php?page=nucleo-documents', 'aria-label': __( 'Back to pages', 'nucleo' ) }, '←' ),
				h( 'strong', { className: 'nucleo-editor__title' }, CFG.title || '' ),
				h( 'span', { className: 'nucleo-editor__status ' + statusClass }, statusLabel ),
				dirty
					? h( 'span', { className: 'nucleo-editor__saved is-dirty' }, __( 'Unsaved changes', 'nucleo' ) )
					: h( 'span', { className: 'nucleo-editor__saved' }, __( 'Saved', 'nucleo' ) + ( savedAt ? ' ' + clock( savedAt ) : '' ) )
			),
			h( 'div', { className: 'nucleo-editor__group-btns', role: 'group', 'aria-label': __( 'View', 'nucleo' ) },
				viewBtn( 'structure', __( 'Complete', 'nucleo' ) ),
				viewBtn( 'texts', __( 'Text only', 'nucleo' ) )
			),
			widths,
			h( 'div', { className: 'nucleo-editor__group-btns' },
				h( Button, { variant: 'tertiary', size: 'small', disabled: !past.length, onClick: undo }, __( 'Undo', 'nucleo' ) ),
				h( Button, { variant: 'tertiary', size: 'small', disabled: !future.length, onClick: redo }, __( 'Redo', 'nucleo' ) )
			),
			h( Button, { variant: 'tertiary', size: 'small', 'aria-expanded': showRevisions, onClick: () => setShowRevisions( !showRevisions ) }, __( 'Revisions', 'nucleo' ) ),
			h( 'span', { className: 'nucleo-editor__quality-pill ' + qualityClass }, qualityGlyph + ' ' + __( 'Quality', 'nucleo' ) + ' ' + ( score === null ? '—' : score ) ),
			h( Button, { variant: 'secondary', disabled: busy || !ops.length, isBusy: busy, onClick: () => save() }, __( 'Save', 'nucleo' ) ),
			h( Button, { variant: 'primary', disabled: busy, onClick: () => publish() }, __( 'Publish', 'nucleo' ) )
		);

		const narrowEl = narrow && h( 'p', { className: 'nucleo-editor__narrow', role: 'note' },
			__( 'The full editor needs at least 1024 px. The Text only view is available.', 'nucleo' ) );

		const conflictEl = conflict && h( 'div', { className: 'nucleo-editor__conflict', role: 'alert' },
			h( 'p', null, conflictText() ),
			h( 'div', { className: 'nucleo-editor__conflict-actions' },
				h( Button, { ref: conflictRef, variant: 'secondary', size: 'small', onClick: reloadAfterConflict }, __( 'Reload', 'nucleo' ) ),
				h( Button, { variant: 'tertiary', size: 'small', onClick: () => setShowRevisions( true ) }, __( 'Compare', 'nucleo' ) )
			)
		);

		/* Vista struttura */

		const MOCK = window.nucleoMockup;
		const canMock = !!MockFrame && SIZE.DEVICES.some( ( d ) => d.id === device && d.rotates );
		const mk = canMock && mockup ? MOCK.layout( device, orientation, os ) : null;
		const urlH = mk && mk.url ? ( urlCollapsed ? mk.url.collapsed : mk.url.expanded ) : 0;
		const real = mk ? { width: mk.viewport.width, height: mk.viewport.height - urlH } : SIZE.size( device, orientation );
		const box = SIZE.fit( mk ? mk.outer : real, avail.w, avail.h );
		const previewHost = ( () => {
			try {
				return new URL( CFG.previewBase || window.location.href ).host;
			} catch ( e ) {
				return '';
			}
		} )();
		// Frame unico per entrambe le viste: stessa anteprima, stessi clic e stesso contorno.
		const frameEl = h( 'iframe', {
			className: 'nucleo-editor__frame',
			title: __( 'Preview', 'nucleo' ),
			sandbox: 'allow-same-origin',
			ref: frameRef,
			onLoad: onFrameLoad,
			// Margine predefinito del body a zero, prima degli stili del tema: la pagina riempie la cornice fino ai bordi.
			// Contenitori vuoti visibili come area tratteggiata: si vedono, si selezionano e accolgono il rilascio.
			srcDoc: '<style>body{margin:0}:is(div,section,header,footer,main,aside,nav,ul,ol,p,span,h1,h2,h3,h4,h5,h6)[data-nc-id]:empty{min-height:48px;outline:1px dashed rgba(13,25,18,.3);outline-offset:-1px}</style>' + preview.head + preview.html + '<style>' + preview.css + '</style>',
			style: mk
				? { width: real.width + 'px', height: real.height + 'px' }
				: { width: real.width + 'px', height: real.height + 'px', transform: 'scale(' + box.scale + ')' },
		} );
		const windowEl = mk
			? h( 'div', { className: 'nucleo-editor__window is-mockup', style: { width: box.width + 'px', height: box.height + 'px' } },
				h( 'div', { className: 'nucleo-editor__mockup', style: { width: mk.outer.width + 'px', height: mk.outer.height + 'px', transform: 'scale(' + box.scale + ')' } },
					h( MockFrame, { layout: mk, os, device, orientation, browser: { host: previewHost, collapsed: urlCollapsed } }, frameEl ) ) )
			: h( 'div', { className: 'nucleo-editor__window', style: { width: box.width + 'px', height: box.height + 'px' } }, frameEl );
		const previewBar = h( 'div', { className: 'nucleo-editor__preview-label', style: { width: box.width + 'px' } },
			h( 'span', null, DEVICE_LABELS[ device ] + ' · ' + real.width + ' × ' + real.height + ' · ' + Math.round( box.scale * 100 ) + '%' ),
			canMock && h( 'span', { className: 'nucleo-editor__preview-tools' },
				mockup && h( 'span', { className: 'nucleo-editor__group-btns', role: 'group', 'aria-label': __( 'Operating system', 'nucleo' ) },
					[ [ 'ios', 'iOS' ], [ 'android', 'Android' ] ].map( ( [ id, label ] ) =>
						h( Button, { key: id, size: 'small', variant: os === id ? 'primary' : 'secondary', 'aria-pressed': os === id, onClick: () => setOs( id ) }, label ) ) ),
				h( Button, { size: 'small', variant: mockup ? 'primary' : 'secondary', 'aria-pressed': mockup, onClick: () => setMockup( !mockup ) }, __( 'Mockup', 'nucleo' ) )
			)
		);
		const previewEl = h( 'div', { className: 'nucleo-editor__preview', ref: previewRef }, previewBar, windowEl );

		const switchBtn = ( current, set ) => ( name, label ) => h( Button, {
			size: 'small',
			variant: current === name ? 'primary' : 'secondary',
			'aria-pressed': current === name,
			onClick: () => set( name ),
		}, label );
		const tabBtn = switchBtn( leftTab, setLeftTab );
		const sideBtn = switchBtn( rightTab, setRightTab );

		const leftBody = leftTab === 'insert'
			? ( Inserter
				? h( Inserter, { catalog: Object.values( catalog ), manifests, doc, selected, onInsert, onDrag: onBlockDrag, onClose: () => setLeftTab( 'structure' ) } )
				: h( 'p', null, __( 'Insert not available.', 'nucleo' ) ) )
			: h( TreeItem, { node: doc.root, parent: null, index: 0, depth: 0, ctx } );

		const revisionsEl = !!showRevisions && !!Revisions && h( Revisions, {
			postId,
			baseRevision: revision,
			call,
			onRestored,
			onClose: () => setShowRevisions( false ),
		} );

		const structure = h( 'div', { className: 'nucleo-editor__body nucleo-editor__layout', style: { '--nucleo-tree-w': treeWidth + 'px' } },
			h( 'div', { className: 'nucleo-editor__tree nucleo-editor__left', role: 'region', 'aria-label': __( 'Structure', 'nucleo' ) },
				h( 'div', { className: 'nucleo-editor__tabs' }, tabBtn( 'structure', __( 'Structure', 'nucleo' ) ), tabBtn( 'insert', __( 'Insert', 'nucleo' ) ) ),
				leftBody
			),
			h( 'div', {
				className: 'nucleo-editor__resizer',
				role: 'separator',
				tabIndex: 0,
				'aria-orientation': 'vertical',
				'aria-label': __( 'Resize the structure column', 'nucleo' ),
				'aria-valuemin': TREE_MIN,
				'aria-valuemax': TREE_MAX,
				'aria-valuenow': treeWidth,
				onPointerDown: startResize,
				onKeyDown: keyResize,
				onDoubleClick: () => resizeTree( TREE_DEFAULT ),
			} ),
			previewEl,
			h( 'aside', { className: 'nucleo-editor__side nucleo-editor__inspector' },
				revisionsEl,
				h( QualityPanel, { issues, errors, acks, onAck, select: setSelected, describe: ( id ) => describeNode( doc, manifests, id ) } ),
				panels.length > 0 && h( 'div', { className: 'nucleo-editor__tabs' }, sideBtn( 'element', __( 'Element', 'nucleo' ) ), sideBtn( 'page', __( 'Page', 'nucleo' ) ) ),
				rightTab === 'page' && panels.length > 0
					? h( 'section', { className: 'nucleo-editor__panels', 'aria-label': __( 'Panels', 'nucleo' ) }, h( PanelTabs, { panels, props: panelProps } ) )
					: h( Inspector, { node, manifest: node ? manifests[ node.type ] : null, bp, onBp: setBp, setField, setFields } )
			)
		);

		/* Vista "Solo testi": elenco dei testi a sinistra, anteprima a destra. Il focus su un campo evidenzia il nodo. */

		const textsRows = texts.map( ( t ) => {
			const key = t.id + '|' + t.path;
			const manifest = manifestFor( doc, manifests, t.id );
			const plain = plainOf( fieldDef( manifest, t.path ), t.text );
			return h( TextareaControl, {
				key,
				label: textLabel( findNode( doc, t.id ), manifest, t.path ),
				value: textEdits[ key ] !== undefined ? textEdits[ key ] : ( plain !== null ? plain : t.text ),
				onFocus: () => setSelected( t.id ),
				onChange: ( v ) => setTextEdits( { ...textEdits, [ key ]: v } ),
			} );
		} );

		const textsEl = h( 'div', { className: 'nucleo-editor__body nucleo-editor__body--texts' },
			h( 'div', { className: 'nucleo-editor__tree', role: 'region', 'aria-label': __( 'Texts', 'nucleo' ) },
				// Barra fissa in cima: con decine di testi il pulsante in fondo resta fuori vista.
				h( 'div', { className: 'nucleo-editor__texts-bar' },
					h( Button, { variant: 'primary', disabled: busy, onClick: () => saveTexts() }, __( 'Save texts', 'nucleo' ) ) ),
				textsRows
			),
			previewEl,
			h( 'aside', { className: 'nucleo-editor__side nucleo-editor__inspector' },
				revisionsEl,
				h( QualityPanel, { issues, errors, acks, onAck, select: setSelected, describe: ( id ) => describeNode( doc, manifests, id ) } )
			)
		);

		const UI = window.nucleoUI;
		const rowMenuEl = rowMenu && UI && UI.ContextMenu && h( UI.ContextMenu, { ...rowMenu, onClose: () => setRowMenu( null ) } );
		return h( 'div', { className: 'nucleo-editor' },
			rowMenuEl,
			topbar,
			narrowEl,
			conflictEl,
			noticeEl,
			isStructure ? structure : textsEl
		);
	}

	const root = document.getElementById( 'nucleo-editor' );
	if ( root ) {
		render( h( App, null ), root );
	}
	if ( typeof module === 'object' && module.exports ) {
		module.exports = { onlyTextRoles, queueEdit, rebase, changedTexts };
	}
} )();

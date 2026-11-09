/**
 * Inserimento dei blocchi nell'editor di Nucleo: regole di accettazione, posizione, nodo di default,
 * filtro e raggruppamento del catalogo, pannello InserterPanel. Nessun build.
 * Nei test (node --test) si esporta con module.exports; nel browser è window.nucleoInserter.
 * InserterPanel usa wp.element e wp.i18n solo quando viene montato nel browser.
 */
( function () {
	'use strict';

	const TEXT_TYPES = [ 'string', 'inline-rich-text', 'rich-text' ];
	const ID_RE = /^[a-z][a-z0-9]{3,}$/;

	/** Il manifest del genitore accetta il tipo figlio? Con childManifest.parents il genitore deve essere in lista. */
	function accepts( parentManifest, childType, childManifest ) {
		if ( !parentManifest ) {
			return false;
		}
		const allowed = parentManifest.children;
		const byType = allowed === true || ( Array.isArray( allowed ) && allowed.includes( childType ) );
		if ( !byType ) {
			return false;
		}
		if ( childManifest && Array.isArray( childManifest.parents ) ) {
			return childManifest.parents.includes( parentManifest.name );
		}
		return true;
	}

	/** Nodo con genitore e indice nel genitore, o null. La radice ha genitore null. */
	function findNode( root, id ) {
		let found = null;
		const walk = ( node, parent, index ) => {
			if ( found ) {
				return;
			}
			if ( node.id === id ) {
				found = { node, parent, index };
				return;
			}
			( node.children || [] ).forEach( ( c, i ) => walk( c, node, i ) );
		};
		walk( root, null, 0 );
		return found;
	}

	/** Tipi dei genitori ammessi per il figlio, per il messaggio di errore. */
	function allowedParents( childType, manifests ) {
		const child = manifests[ childType ];
		if ( child && Array.isArray( child.parents ) ) {
			return child.parents;
		}
		return Object.keys( manifests ).filter( ( name ) => accepts( manifests[ name ], childType, child ) );
	}

	/**
	 * Dove inserire un blocco di tipo type: { parent, index }. Dentro il selezionato se lo accetta (index 0),
	 * altrimenti dopo il selezionato nel suo genitore; senza selezione in fondo alla radice.
	 * Se nessuna posizione va bene restituisce { reason } in inglese.
	 */
	function placement( doc, selectedId, type, manifests ) {
		const childManifest = manifests[ type ];
		const sel = selectedId ? findNode( doc.root, selectedId ) : null;
		if ( sel && accepts( manifests[ sel.node.type ], type, childManifest ) ) {
			return { parent: sel.node.id, index: 0 };
		}
		if ( sel && sel.parent && accepts( manifests[ sel.parent.type ], type, childManifest ) ) {
			return { parent: sel.parent.id, index: sel.index + 1 };
		}
		if ( !sel && accepts( manifests[ doc.root.type ], type, childManifest ) ) {
			return { parent: doc.root.id, index: ( doc.root.children || [] ).length };
		}
		const names = allowedParents( type, manifests );
		return {
			reason: names.length
				? `${ type } cannot be inserted here: it must be inside ${ names.join( ', ' ) }.`
				: `${ type } cannot be inserted here: no block accepts it.`,
		};
	}

	// Traduzione dei testi di partenza: wp.i18n nel browser, testo invariato nei test. Si chiama __ perché make-pot la trovi.
	const __ = ( s, domain ) => ( typeof window !== 'undefined' && window.wp && window.wp.i18n ? window.wp.i18n.__( s, domain ) : s );

	/**
	 * Valori di partenza dei blocchi che con i soli default non sarebbero validi o non si vedrebbero.
	 * Image, Embed, Video, Form e Reusable section restano incompleti: servono dati veri (immagine, URL, id),
	 * e l'anteprima li mostra come segnaposto con il campo mancante.
	 */
	const STARTERS = {
		'nc/tabs': () => ( { content: { items: [
			{ title: __( 'First tab', 'nucleo' ), html: '<p>' + __( 'Content of the first tab.', 'nucleo' ) + '</p>' },
			{ title: __( 'Second tab', 'nucleo' ), html: '<p>' + __( 'Content of the second tab.', 'nucleo' ) + '</p>' },
		] } } ),
		'nc/accordion': () => ( { content: { items: [
			{ title: __( 'First question', 'nucleo' ), html: '<p>' + __( 'Answer to the first question.', 'nucleo' ) + '</p>' },
			{ title: __( 'Second question', 'nucleo' ), html: '<p>' + __( 'Answer to the second question.', 'nucleo' ) + '</p>' },
		] } } ),
		'nc/list': () => ( { content: { items: [ __( 'First item', 'nucleo' ), __( 'Second item', 'nucleo' ) ] } } ),
		'nc/heading': () => ( { content: { text: __( 'New heading', 'nucleo' ) } } ),
		'nc/text': () => ( { content: { html: __( 'New text.', 'nucleo' ) } } ),
		'nc/button': () => ( { content: { text: __( 'Button', 'nucleo' ) } } ),
		'nc/offcanvas': () => ( { settings: { label: __( 'Menu', 'nucleo' ) } } ),
		// La collection mostra i risultati con esattamente una card.
		'nc/collection': ( id ) => ( { settings: { postType: 'post' }, children: [ { id: id + 'card', type: 'nc/collection-card', children: [] } ] } ),
		// Il repeater ripete esattamente un figlio: la riga.
		'nc/repeater': ( id ) => ( { children: [ { id: id + 'row', type: 'nc/container', settings: { layout: 'flex' }, children: [] } ] } ),
	};

	/**
	 * Nodo nuovo: default del manifest ('' per i testi required, prima scelta per gli elenchi required senza default,
	 * default delle impostazioni, children se accetta figli), poi i valori di partenza di STARTERS.
	 */
	function defaultNode( manifest, id ) {
		const node = { id, type: manifest.name };
		const content = {};
		Object.keys( manifest.content || {} ).forEach( ( key ) => {
			const def = manifest.content[ key ];
			if ( def.required && Array.isArray( def.enum ) ) {
				content[ key ] = def.enum[ 0 ];
			} else if ( def.required && TEXT_TYPES.includes( def.type ) ) {
				content[ key ] = '';
			}
		} );
		const settings = {};
		Object.keys( manifest.settings || {} ).forEach( ( key ) => {
			const def = manifest.settings[ key ];
			if ( def.default !== undefined ) {
				settings[ key ] = def.default;
			} else if ( def.required && Array.isArray( def.enum ) ) {
				settings[ key ] = def.enum[ 0 ];
			}
		} );
		if ( Object.keys( content ).length ) {
			node.content = content;
		}
		if ( Object.keys( settings ).length ) {
			node.settings = settings;
		}
		if ( manifest.children ) {
			node.children = [];
		}
		const start = STARTERS[ manifest.name ] ? STARTERS[ manifest.name ]( id ) : {};
		[ 'content', 'settings' ].forEach( ( part ) => {
			if ( start[ part ] ) {
				node[ part ] = { ...( node[ part ] || {} ), ...start[ part ] };
			}
		} );
		if ( start.children ) {
			node.children = start.children;
		}
		return node;
	}

	/** Id nuovo, nel formato di newId di editor.js, che non compare nel documento. */
	function newId( doc ) {
		const ids = new Set();
		const collect = ( node ) => {
			ids.add( node.id );
			( node.children || [] ).forEach( collect );
		};
		collect( doc.root );
		let id;
		do {
			id = 'n' + Math.random().toString( 36 ).slice( 2, 9 );
		} while ( ids.has( id ) || !ID_RE.test( id ) );
		return id;
	}

	/** Blocchi del catalogo con nome, titolo o descrizione che contengono la ricerca (senza distinzione di maiuscole). */
	function filterCatalog( blocks, query ) {
		const q = String( query || '' ).trim().toLowerCase();
		if ( q === '' ) {
			return blocks.slice();
		}
		return blocks.filter( ( b ) => [ b.name, b.title, b.description ]
			.some( ( v ) => String( v || '' ).toLowerCase().includes( q ) ) );
	}

	/** Gruppi { category, blocks } nell'ordine in cui compaiono le categorie. */
	function groupByCategory( blocks ) {
		const groups = [];
		blocks.forEach( ( b ) => {
			let group = groups.find( ( g ) => g.category === b.category );
			if ( !group ) {
				group = { category: b.category, blocks: [] };
				groups.push( group );
			}
			group.blocks.push( b );
		} );
		return groups;
	}

	/**
	 * Pannello di inserimento (solo con window.wp.element). Props: catalog, manifests, doc, selected,
	 * onInsert( node, place ), onClose(), onDrag( name | null ) all'inizio e alla fine del trascinamento di una card.
	 * Le voci non inseribili accanto alla selezione restano trascinabili; il motivo è nel suggerimento.
	 */
	function InserterPanel( props ) {
		const { createElement: h, useState } = window.wp.element;
		const { __ } = window.wp.i18n;
		const { catalog, manifests, doc, selected, onInsert, onClose, onDrag } = props;
		const UI = window.nucleoUI;
		const [ query, setQuery ] = useState( '' );
		const groups = groupByCategory( filterCatalog( catalog, query ) );

		const item = ( block ) => {
			const manifest = manifests[ block.name ];
			const place = placement( doc, selected, block.name, manifests );
			const loading = !manifest;
			const blocked = place.parent === undefined;
			const reason = loading ? null : place.reason || null;
			// Card: icona e titolo; la descrizione è nel suggerimento al passaggio e, per lo screen reader, nel pulsante.
			const card = h( 'button', {
				type: 'button',
				className: 'nucleo-editor__inserter-item',
				disabled: loading,
				// Non inseribile accanto alla selezione: il clic non fa niente, ma il blocco si può trascinare nell'anteprima.
				'aria-disabled': blocked ? 'true' : undefined,
				draggable: !loading && !!onDrag,
				onDragStart: ( e ) => {
					e.dataTransfer.effectAllowed = 'copy';
					e.dataTransfer.setData( 'text/plain', block.name );
					onDrag( block.name );
				},
				onDragEnd: () => onDrag( null ),
				onClick: () => blocked || onInsert( defaultNode( manifest, newId( doc ) ), place ),
			},
			UI && UI.BlockIcon ? h( UI.BlockIcon, { type: block.name, size: 20 } ) : null,
			h( 'strong', null, block.title ),
			block.description && h( 'span', { className: 'screen-reader-text' }, block.description ),
			reason && h( 'span', { className: 'screen-reader-text' }, reason ) );
			// Il motivo di un blocco non inseribile sta nel suggerimento, sotto la descrizione.
			const tip = h( 'span', null, block.description, reason && h( 'span', { className: 'nc-tooltip__note' }, reason ) );
			return UI && UI.HoverTip ? h( UI.HoverTip, { key: block.name, text: tip }, card ) : h( 'div', { key: block.name }, card );
		};

		return h( 'div', { className: 'nucleo-editor__inserter' },
			h( 'button', { type: 'button', className: 'nc-btn nc-btn--secondary nc-btn--small nucleo-editor__inserter-close', onClick: onClose }, __( 'Close', 'nucleo' ) ),
			h( 'input', {
				type: 'search',
				className: 'nucleo-editor__inserter-search',
				placeholder: __( 'Search blocks', 'nucleo' ),
				value: query,
				onChange: ( e ) => setQuery( e.target.value ),
			} ),
			groups.length === 0
				? h( 'p', null, __( 'No blocks match the search.', 'nucleo' ) )
				: groups.map( ( g ) => h( 'div', { key: g.category, className: 'nucleo-editor__inserter-group' },
					h( 'h3', null, g.category ),
					g.blocks.map( item ) ) )
		);
	}

	const api = { accepts, placement, defaultNode, newId, filterCatalog, groupByCategory, InserterPanel };

	if ( typeof module === 'object' && module.exports ) {
		module.exports = api;
	} else {
		window.nucleoInserter = api;
	}
} )();

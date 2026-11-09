/**
 * Operazioni pure sull'albero del documento dell'editor. Nessuna funzione modifica l'input:
 * ognuna lavora su una copia e restituisce il documento nuovo, oppure null se l'operazione non è valida.
 * Nei test (node --test) si esporta con module.exports; nel browser è window.nucleoTree.
 */
( function () {
	'use strict';

	const clone = ( v ) => JSON.parse( JSON.stringify( v ) );
	// Stesso formato di newId in editor.js.
	const newId = () => 'n' + Math.random().toString( 36 ).slice( 2, 9 );

	/** Nodo con id, genitore e indice nel genitore. Null se l'id non esiste. */
	function locate( doc, id ) {
		let loc = null;
		const walk = ( node, parent, index ) => {
			if ( loc ) {
				return;
			}
			if ( node.id === id ) {
				loc = { node, parent, index };
				return;
			}
			( node.children || [] ).forEach( ( c, i ) => walk( c, node, i ) );
		};
		walk( doc.root, null, 0 );
		return loc;
	}

	/** Id degli antenati del nodo, dalla radice al genitore. Vuoto per la radice o un id inesistente. */
	function ancestors( doc, id ) {
		const path = [];
		const walk = ( node, trail ) => {
			if ( node.id === id ) {
				path.push( ...trail );
				return true;
			}
			return ( node.children || [] ).some( ( c ) => walk( c, trail.concat( node.id ) ) );
		};
		walk( doc.root, [] );
		return path;
	}

	function reId( n ) {
		n.id = newId();
		( n.children || [] ).forEach( reId );
		return n;
	}

	/** Toglie il nodo e il suo sottoalbero. La radice non si toglie. */
	function remove( doc, id ) {
		const d = clone( doc );
		const loc = locate( d, id );
		if ( !loc || !loc.parent ) {
			return null;
		}
		loc.parent.children.splice( loc.index, 1 );
		return d;
	}

	/** Inserisce una copia subito dopo il nodo, con id nuovi anche nei figli. */
	function duplicate( doc, id ) {
		const d = clone( doc );
		const loc = locate( d, id );
		if ( !loc || !loc.parent ) {
			return null;
		}
		loc.parent.children.splice( loc.index + 1, 0, reId( clone( loc.node ) ) );
		return d;
	}

	/**
	 * Dove finirebbe il nodo spostato: { parent, index } con index calcolato dopo aver tolto il nodo
	 * (stessa convenzione dell'op "move" di editor.js). Null se la destinazione non è valida.
	 * canContain( parent, child ) dice se il blocco parent accetta il blocco child come figlio.
	 */
	function moveTarget( doc, id, targetId, pos, canContain ) {
		const d = clone( doc );
		const moving = locate( d, id );
		if ( !moving || !moving.parent ) {
			return null;
		}
		moving.parent.children.splice( moving.index, 1 );
		// Un bersaglio uguale al nodo o dentro il suo sottoalbero non si trova più: rifiutato.
		return insertTarget( d, targetId, pos, moving.node, canContain );
	}

	/** Dove finirebbe child messo prima, dopo o dentro il bersaglio: { parent, index }, o null se non ammesso. */
	function insertTarget( doc, targetId, pos, child, canContain ) {
		const target = locate( doc, targetId );
		if ( !target ) {
			return null;
		}
		if ( pos === 'inside' ) {
			if ( !canContain( target.node, child ) ) {
				return null;
			}
			return { parent: target.node.id, index: ( target.node.children || [] ).length };
		}
		if ( pos !== 'before' && pos !== 'after' ) {
			return null;
		}
		if ( !target.parent || !canContain( target.parent, child ) ) {
			return null;
		}
		return { parent: target.parent.id, index: pos === 'before' ? target.index : target.index + 1 };
	}

	/**
	 * Posizione di rilascio sopra un elemento alto da top a bottom, con il puntatore in y.
	 * Se il blocco accetta figli, la fascia centrale (metà dell'altezza) vale dentro; sopra o sotto il centro, prima o dopo.
	 */
	function dropPos( top, bottom, y, accepts ) {
		const h = bottom - top;
		if ( accepts && y > top + h / 4 && y < bottom - h / 4 ) {
			return 'inside';
		}
		return y < top + h / 2 ? 'before' : 'after';
	}

	/** Sposta il nodo prima, dopo o dentro il bersaglio. */
	function move( doc, id, targetId, pos, canContain ) {
		const t = moveTarget( doc, id, targetId, pos, canContain );
		if ( !t ) {
			return null;
		}
		const d = clone( doc );
		const moving = locate( d, id );
		moving.parent.children.splice( moving.index, 1 );
		const dest = locate( d, t.parent ).node;
		dest.children = dest.children || [];
		dest.children.splice( t.index, 0, moving.node );
		return d;
	}

	/** Id del nodo più vicino al bersaglio di un clic nell'anteprima (marcatore data-nc-id), o null. */
	function nodeIdAt( el ) {
		for ( let n = el; n; n = n.parentElement ) {
			const id = typeof n.getAttribute === 'function' ? n.getAttribute( 'data-nc-id' ) : null;
			if ( id ) {
				return id;
			}
		}
		return null;
	}

	/** Query string di una lettura GET: input[chiave]=valore, vuota se l'input è vuoto. */
	function query( input ) {
		const params = new URLSearchParams();
		const add = ( prefix, value ) => {
			if ( value !== null && typeof value === 'object' ) {
				Object.keys( value ).forEach( ( key ) => add( `${ prefix }[${ key }]`, value[ key ] ) );
			} else if ( value !== undefined ) {
				params.append( prefix, String( value ) );
			}
		};
		add( 'input', input );
		const text = params.toString();
		return text === '' ? '' : `?${ text }`;
	}

	const api = { remove, duplicate, move, moveTarget, insertTarget, dropPos, nodeIdAt, query, ancestors };

	if ( typeof module === 'object' && module.exports ) {
		module.exports = api;
	} else {
		window.nucleoTree = api;
	}
} )();

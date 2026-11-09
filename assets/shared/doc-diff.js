/**
 * Differenze tra revisioni dei documenti Nucleo: nodi aggiunti, rimossi e campi cambiati.
 * Nessun build: funzioni pure; i testi usano wp.i18n se presente, altrimenti l'inglese.
 * Usato da Attività AI (pagina e pannello dell'editor). Nei test (node --test) si esporta con module.exports; nel browser è window.nucleoDocDiff.
 */
( function () {
	'use strict';

	const root = typeof window !== 'undefined' ? window : {};
	const i18n = root.wp && root.wp.i18n;

	// Scarto in secondi tra l'ora della voce di attività e quella della revisione salvata.
	const TIME_SLACK = 2;

	/** Testo tradotto con wp.i18n quando c'è, altrimenti la stringa inglese. */
	const __ = ( s, domain ) => ( i18n ? i18n.__( s, domain ) : s );

	/** Come wp.i18n.sprintf con segnaposto posizionali (%1$s, %2$s). */
	const sprintf = ( tpl, ...args ) => ( i18n
		? i18n.sprintf( tpl, ...args )
		: tpl.replace( /%(\d+)\$[sd]/g, ( m, n ) => String( args[ Number( n ) - 1 ] ) ) );

	/** Revisione prodotta dalla voce: la più recente non successiva all'ora della voce, o null. */
	function revisionForEntry( revisions, entry ) {
		let found = null;
		( Array.isArray( revisions ) ? revisions : [] ).forEach( ( r ) => {
			if ( r.time <= entry.time + TIME_SLACK && ( ! found || r.rev > found.rev ) ) {
				found = r;
			}
		} );
		return found;
	}

	/** Nodi del documento per id, con il percorso di indici dalla radice. */
	function indexNodes( doc ) {
		const out = new Map();
		const walk = ( node, path ) => {
			out.set( node.id, { node, path } );
			( node.children || [] ).forEach( ( c, i ) => walk( c, path.concat( i ) ) );
		};
		if ( doc && doc.root ) {
			walk( doc.root, [] );
		}
		return out;
	}

	/**
	 * Differenze tra due versioni del documento: nodi aggiunti e rimossi, campi cambiati (from → to).
	 * Il percorso è quello del nodo nella versione più recente ("0.2", "root" per la radice).
	 */
	function diffDocs( prev, next ) {
		const before = indexNodes( prev );
		const after = indexNodes( next );
		const changes = [];
		after.forEach( ( { node, path }, id ) => {
			const at = path.join( '.' ) || 'root';
			if ( !before.has( id ) ) {
				changes.push( { kind: 'added', id, type: node.type, path: at } );
				return;
			}
			const old = before.get( id ).node;
			if ( old.type !== node.type ) {
				changes.push( { kind: 'changed', id, path: at, field: 'type', from: old.type, to: node.type } );
			}
			[ 'content', 'settings', 'style', 'bind' ].forEach( ( group ) => {
				const a = old[ group ] || {};
				const b = node[ group ] || {};
				[ ...new Set( [ ...Object.keys( a ), ...Object.keys( b ) ] ) ].forEach( ( key ) => {
					if ( JSON.stringify( a[ key ] ) !== JSON.stringify( b[ key ] ) ) {
						changes.push( { kind: 'changed', id, path: at, field: group + '.' + key, from: a[ key ] ?? null, to: b[ key ] ?? null } );
					}
				} );
			} );
		} );
		before.forEach( ( { node, path }, id ) => {
			if ( !after.has( id ) ) {
				changes.push( { kind: 'removed', id, type: node.type, path: path.join( '.' ) || 'root' } );
			}
		} );
		return changes;
	}

	/** Differenze mostrate prima del ripristino: dalla versione attuale (l'ultima revisione) a quella ripristinata. Vuoto se sono la stessa. */
	function restoreChanges( revisions, prev ) {
		const list = Array.isArray( revisions ) ? revisions : [];
		const latest = list[ list.length - 1 ];
		return latest && prev.doc && latest.doc ? diffDocs( latest.doc, prev.doc ) : [];
	}

	/**
	 * Modifiche introdotte da una voce: confronta la sua revisione con la precedente (la più alta sotto la sua).
	 * Per la prima revisione tutto il documento risulta aggiunto. [] se mancano la revisione o il suo documento.
	 */
	function entryChanges( revisions, entry ) {
		const r = revisionForEntry( revisions, entry );
		if ( !r || !r.doc ) {
			return [];
		}
		const list = Array.isArray( revisions ) ? revisions : [];
		const prev = list
			.filter( ( x ) => x.rev < r.rev )
			.reduce( ( best, x ) => ( ! best || x.rev > best.rev ? x : best ), null );
		if ( !prev ) {
			return diffDocs( null, r.doc );
		}
		return prev.doc ? diffDocs( prev.doc, r.doc ) : [];
	}

	/** Riga di una differenza tra revisioni, in linguaggio semplice. */
	function changeText( c ) {
		const shown = ( v ) => ( typeof v === 'string' ? v : JSON.stringify( v ) );
		if ( c.kind === 'added' ) {
			/* translators: 1: block type, 2: position in the document */
			return sprintf( __( 'Added %1$s at %2$s', 'nucleo' ), c.type, c.path );
		}
		if ( c.kind === 'removed' ) {
			/* translators: 1: block type, 2: position in the document */
			return sprintf( __( 'Removed %1$s at %2$s', 'nucleo' ), c.type, c.path );
		}
		/* translators: 1: field name, 2: position in the document, 3: old value, 4: new value */
		return sprintf( __( 'Changed %1$s at %2$s: %3$s → %4$s', 'nucleo' ), c.field, c.path, shown( c.from ), shown( c.to ) );
	}

	const api = { indexNodes, diffDocs, revisionForEntry, restoreChanges, entryChanges, changeText };

	root.nucleoDocDiff = api;

	if ( typeof module === 'object' && module.exports ) {
		module.exports = api;
	}
} )();

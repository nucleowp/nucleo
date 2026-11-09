/**
 * Modifica inline dei campi di un blocco nell'anteprima dell'editor. Le funzioni di lettura e pulizia
 * sono pure; bind collega il doppio clic di un blocco alla modifica del suo campo principale.
 * Nei test (node --test) si esporta con module.exports; nel browser è window.nucleoInlineEdit.
 */
( function () {
	'use strict';

	// Chiavi di tipo string che non sono testo modificabile in linea (media, link, codice).
	const NOT_INLINE = [ 'html', 'src', 'href', 'url', 'code', 'shortcode', 'embed' ];
	// Attributi da togliere dai tag: stile e data-*. Si applica solo dentro i tag, mai al testo.
	const ATTR = /\s(?:style|data-[\w-]+)(?:\s*=\s*(?:"[^"]*"|'[^']*'|[^\s"'>]+))?/gi;

	/**
	 * Primo campo modificabile in linea del manifest del blocco: { key, kind } con kind text, rich o list.
	 * Le chiavi non modificabili in linea vengono saltate. Null se il blocco non ha campi modificabili.
	 */
	function editableField( manifest ) {
		const content = ( manifest && manifest.content ) || {};
		for ( const key of Object.keys( content ) ) {
			const spec = content[ key ] || {};
			if ( spec.type === 'inline-rich-text' ) {
				return { key, kind: 'rich' };
			}
			if ( spec.type === 'string' && ! NOT_INLINE.includes( key ) ) {
				return { key, kind: 'text' };
			}
			if ( spec.type === 'array' && spec.items && spec.items.type === 'string' ) {
				return { key, kind: 'list' };
			}
		}
		return null;
	}

	/** HTML del campo rich ripulito: &nbsp; diventa spazio, via style e data-*, via <br> finale, trim. */
	function cleanRich( html ) {
		return String( html )
			.replace( /&nbsp;/g, ' ' )
			.replace( /<[^>]*>/g, ( tag ) => tag.replace( ATTR, '' ) )
			.replace( /(?:\s*<br\s*\/?>)+\s*$/i, '' )
			.trim();
	}

	/** Valore del campo letto dall'elemento, secondo il tipo. Liste: elementi li non vuoti. */
	function readValue( kind, el ) {
		if ( kind === 'text' ) {
			return el.textContent.trim();
		}
		if ( kind === 'rich' ) {
			return cleanRich( el.innerHTML );
		}
		if ( kind === 'list' ) {
			return Array.from( el.querySelectorAll( 'li' ), ( li ) => li.textContent.trim() ).filter( Boolean );
		}
		return null;
	}

	/**
	 * Doppio clic su un blocco (marcatore data-nc-id): se fieldFor(id) dà un campo, lo si modifica in linea;
	 * altrimenti si chiama onOpen(id). Alla fine, onCommit(id, key, value) solo se il contenuto è cambiato.
	 */
	function bind( doc, { fieldFor, onCommit, onOpen } ) {
		doc.addEventListener( 'dblclick', ( e ) => {
			const el = e.target.closest( '[data-nc-id]' );
			// Un doppio clic dentro un campo già in modifica non riapre la modifica.
			if ( ! el || el.contentEditable === 'true' ) {
				return;
			}
			const id = el.getAttribute( 'data-nc-id' );
			const field = fieldFor( id );
			if ( ! field ) {
				onOpen( id );
				return;
			}

			const original = el.innerHTML;
			let done = false;

			const end = () => {
				if ( done ) {
					return;
				}
				done = true;
				el.removeEventListener( 'keydown', onKey );
				el.removeEventListener( 'blur', end );
				el.removeAttribute( 'contenteditable' );
				if ( el.innerHTML !== original ) {
					onCommit( id, field.key, readValue( field.kind, el ) );
				}
			};

			const onKey = ( k ) => {
				if ( done ) {
					return;
				}
				if ( k.key === 'Escape' ) {
					el.innerHTML = original;
					end();
				} else if ( k.key === 'Enter' && ! k.shiftKey && field.kind !== 'list' ) {
					k.preventDefault();
					end();
				}
			};

			el.contentEditable = 'true';
			el.addEventListener( 'keydown', onKey );
			el.addEventListener( 'blur', end );
			el.focus();

			const range = doc.createRange();
			range.selectNodeContents( el );
			const sel = doc.defaultView.getSelection();
			sel.removeAllRanges();
			sel.addRange( range );
		} );
	}

	const api = { editableField, cleanRich, readValue, bind };

	if ( typeof module === 'object' && module.exports ) {
		module.exports = api;
	} else {
		window.nucleoInlineEdit = api;
	}
} )();

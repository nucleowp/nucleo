/**
 * Scorciatoie da tastiera dell'editor Nucleo. keyAction(event) dice quale azione eseguire, o null.
 * Nei campi di testo (input, textarea, select, contentEditable) restano solo salva, annulla e ripeti.
 * Nei test (node --test) si esporta con module.exports; nel browser è window.nucleoShortcuts.
 */
( function () {
	'use strict';

	const TEXT_TAGS = [ 'INPUT', 'TEXTAREA', 'SELECT' ];

	/** Azione associata all'evento di tastiera: 'undo', 'redo', 'save', ... oppure null. */
	function keyAction( event ) {
		const key = event.key;
		const letter = String( key || '' ).toLowerCase();
		const mod = !! ( event.metaKey || event.ctrlKey );
		const target = event.target || {};
		const inText = TEXT_TAGS.includes( String( target.tagName || '' ).toUpperCase() ) || target.isContentEditable === true;

		if ( mod && letter === 'z' ) {
			return event.shiftKey ? 'redo' : 'undo';
		}
		if ( mod && letter === 's' ) {
			return 'save';
		}
		if ( inText ) {
			return null;
		}
		if ( mod && letter === 'd' ) {
			return 'duplicate';
		}
		if ( ! mod && ! event.altKey ) {
			if ( key === 'Delete' || key === 'Backspace' ) {
				return 'remove';
			}
			if ( key === 'Escape' ) {
				return 'deselect';
			}
			if ( key === '/' ) {
				return 'insert';
			}
		}
		if ( event.altKey && ! mod ) {
			if ( key === 'ArrowUp' ) {
				return 'moveUp';
			}
			if ( key === 'ArrowDown' ) {
				return 'moveDown';
			}
		}
		return null;
	}

	const api = { keyAction };

	if ( typeof module === 'object' && module.exports ) {
		module.exports = api;
	} else {
		window.nucleoShortcuts = api;
	}
} )();

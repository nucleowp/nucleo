/**
 * Copia negli appunti: delega il click su [data-nc-copy] e annuncia l'esito nell'aria-live più vicino.
 * Il valore è l'attributo data-nc-copy, oppure il testo (o il valore di un campo) del selettore in data-nc-copy-target.
 */
( function () {
	'use strict';

	const { __ } = wp.i18n;

	function valueOf( button ) {
		const selector = button.getAttribute( 'data-nc-copy-target' );
		if ( selector ) {
			const target = document.querySelector( selector );
			if ( ! target ) {
				return '';
			}
			return 'value' in target ? target.value : target.textContent;
		}
		return button.getAttribute( 'data-nc-copy' ) || '';
	}

	function liveRegionOf( button ) {
		for ( let el = button.parentElement; el; el = el.parentElement ) {
			const region = el.querySelector( '[aria-live]' );
			if ( region ) {
				return region;
			}
		}
		return null;
	}

	function announce( button, text ) {
		const region = liveRegionOf( button );
		if ( region ) {
			region.textContent = text;
		}
	}

	document.addEventListener( 'click', async function ( event ) {
		const button = event.target.closest( '[data-nc-copy]' );
		if ( ! button ) {
			return;
		}

		try {
			await navigator.clipboard.writeText( valueOf( button ) );
			announce( button, __( 'Copied', 'nucleo' ) );
		} catch ( error ) {
			announce( button, __( 'Copy failed', 'nucleo' ) );
		}
	} );
}() );

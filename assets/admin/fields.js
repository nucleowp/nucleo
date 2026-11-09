/**
 * Campi dei contenuti e dei termini: righe del ripetitore, immagine (wp.media), relazione (/wp/v2/search).
 * Nessuna dipendenza oltre wp.media. Configurazione da wp_localize_script: nucleoFields.
 */
( function () {
	'use strict';

	const cfg = window.nucleoFields || {};
	const i18n = cfg.i18n || {};

	/* Numera le righe in ordine (0, 1, 2…): PHP legge il ripetitore come lista. */
	function renumber( repeater ) {
		const rowsEl = repeater.querySelector( '.nc-repeater__rows' );
		const rows = Array.from( rowsEl.children );
		rows.forEach( ( row, i ) => {
			row.querySelectorAll( '[name]' ).forEach( ( el ) => {
				el.name = el.name.replace( /\[(\d+|__i__)\]/, '[' + i + ']' );
			} );
			row.querySelector( '[data-action="up"]' ).disabled = i === 0;
			row.querySelector( '[data-action="down"]' ).disabled = i === rows.length - 1;
		} );

		const max = parseInt( repeater.dataset.max, 10 );
		repeater.querySelector( '[data-action="add"]' ).disabled = ! isNaN( max ) && rows.length >= max;
	}

	function addRow( repeater ) {
		const rowsEl = repeater.querySelector( '.nc-repeater__rows' );
		const max = parseInt( repeater.dataset.max, 10 );
		if ( ! isNaN( max ) && rowsEl.children.length >= max ) {
			return;
		}
		const template = repeater.querySelector( 'template.nc-repeater__template' );
		rowsEl.append( template.content.cloneNode( true ) );
		renumber( repeater );
		const row = rowsEl.lastElementChild;
		const first = row.querySelector( 'input, select, textarea' );
		if ( first ) {
			first.focus();
		}
	}

	function chooseImage( box ) {
		if ( ! window.wp || ! wp.media ) {
			return;
		}
		const frame = wp.media( {
			title: i18n.mediaTitle || '',
			library: { type: 'image' },
			button: { text: i18n.mediaButton || '' },
			multiple: false,
		} );
		frame.on( 'select', () => {
			const attachment = frame.state().get( 'selection' ).first().toJSON();
			setImage( box, attachment );
		} );
		frame.open();
	}

	function setImage( box, attachment ) {
		box.querySelector( '.nc-image__input' ).value = String( attachment.id );
		const thumb = attachment.sizes && attachment.sizes.thumbnail ? attachment.sizes.thumbnail.url : attachment.url;
		const preview = box.querySelector( '.nc-image__preview' );
		const img = document.createElement( 'img' );
		img.src = thumb;
		img.alt = '';
		preview.replaceChildren( img );
		box.querySelector( '[data-action="remove"]' ).hidden = false;
	}

	function clearImage( box ) {
		box.querySelector( '.nc-image__input' ).value = '';
		box.querySelector( '.nc-image__preview' ).replaceChildren();
		box.querySelector( '[data-action="remove"]' ).hidden = true;
	}

	function pick( relation, id, title ) {
		const items = relation.querySelector( '.nc-relation__items' );
		if ( relation.dataset.multiple !== '1' ) {
			items.replaceChildren();
		} else if ( items.querySelector( '[data-id="' + String( id ) + '"]' ) ) {
			return;
		}

		const li = document.createElement( 'li' );
		li.className = 'nc-relation__item';
		li.dataset.id = String( id );

		const hidden = document.createElement( 'input' );
		hidden.type = 'hidden';
		hidden.name = relation.dataset.name;
		hidden.value = String( id );

		const label = document.createElement( 'span' );
		label.textContent = title;

		const remove = document.createElement( 'button' );
		remove.type = 'button';
		remove.className = 'nc-btn nc-btn--danger nc-btn--small';
		remove.dataset.action = 'remove';
		remove.textContent = relation.dataset.removeLabel || 'Remove';

		li.append( hidden, document.createTextNode( ' ' ), label, document.createTextNode( ' ' ), remove );
		items.append( li );

		relation.querySelector( '.nc-relation__search' ).value = '';
		const results = relation.querySelector( '.nc-relation__results' );
		results.replaceChildren();
		results.hidden = true;
	}

	async function search( relation, term ) {
		const results = relation.querySelector( '.nc-relation__results' );
		results.replaceChildren();
		if ( term.trim() === '' ) {
			results.hidden = true;
			return;
		}

		const url = new URL( 'wp/v2/search', cfg.restUrl );
		url.searchParams.set( 'search', term );
		url.searchParams.set( 'per_page', '10' );
		( relation.dataset.postTypes || '' ).split( ',' ).filter( Boolean ).forEach( ( type ) => {
			url.searchParams.append( 'subtype[]', type );
		} );

		const response = await fetch( url, {
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': cfg.nonce || '' },
		} );
		if ( ! response.ok ) {
			return;
		}
		const items = await response.json();

		if ( items.length === 0 ) {
			const empty = document.createElement( 'li' );
			empty.textContent = i18n.empty || '';
			results.append( empty );
		}
		items.forEach( ( item ) => {
			const li = document.createElement( 'li' );
			const button = document.createElement( 'button' );
			button.type = 'button';
			button.className = 'nc-btn nc-btn--tertiary nc-btn--small';
			button.dataset.action = 'pick';
			button.dataset.id = String( item.id );
			button.dataset.title = item.title;
			button.textContent = item.title;
			li.append( button );
			results.append( li );
		} );
		results.hidden = false;
	}

	document.addEventListener( 'click', ( event ) => {
		const button = event.target.closest( 'button[data-action]' );
		if ( ! button ) {
			return;
		}
		const action = button.dataset.action;

		const image = button.closest( '.nc-image' );
		if ( image ) {
			event.preventDefault();
			if ( action === 'choose' ) {
				chooseImage( image );
			} else if ( action === 'remove' ) {
				clearImage( image );
			}
			return;
		}

		const relation = button.closest( '.nc-relation' );
		if ( relation ) {
			event.preventDefault();
			if ( action === 'remove' ) {
				button.closest( '.nc-relation__item' ).remove();
			} else if ( action === 'pick' ) {
				pick( relation, button.dataset.id, button.dataset.title );
			}
			return;
		}

		const repeater = button.closest( '.nc-repeater' );
		if ( ! repeater ) {
			return;
		}
		event.preventDefault();
		const row = button.closest( '.nc-repeater__row' );
		if ( action === 'add' ) {
			addRow( repeater );
		} else if ( action === 'remove' && row ) {
			row.remove();
			renumber( repeater );
		} else if ( action === 'up' && row && row.previousElementSibling ) {
			row.parentNode.insertBefore( row, row.previousElementSibling );
			renumber( repeater );
		} else if ( action === 'down' && row && row.nextElementSibling ) {
			row.parentNode.insertBefore( row.nextElementSibling, row );
			renumber( repeater );
		}
	} );

	let timer = 0;
	document.addEventListener( 'input', ( event ) => {
		const target = event.target;
		if ( ! target.matches || ! target.matches( '.nc-relation__search' ) ) {
			return;
		}
		clearTimeout( timer );
		timer = setTimeout( () => {
			search( target.closest( '.nc-relation' ), target.value ).catch( () => {} );
		}, 250 );
	} );

	document.addEventListener( 'keydown', ( event ) => {
		if ( event.key === 'Enter' && event.target.matches && event.target.matches( '.nc-relation__search' ) ) {
			event.preventDefault();
		}
	} );

	document.querySelectorAll( '.nc-repeater' ).forEach( renumber );
}() );

/**
 * Montaggio dell'amministrazione di Nucleo: monta window.nucleoPages[page] in #nucleo-admin-app.
 * Nessun build: wp.element, wp.i18n. Dipende da ui.js (window.nucleoUI).
 */
( function () {
	'use strict';

	const { createElement: h, createRoot, render } = wp.element;
	const { __ } = wp.i18n;

	/** Pagina di ripiego per uno slug senza componente. */
	function fallbackPage( page, pages ) {
		const UI = window.nucleoUI;
		const entry = pages.find( ( p ) => p.slug === page );
		const title = entry ? entry.title : page;
		return function Missing() {
			return h( UI.Page, { title },
				h( UI.Empty, { text: __( 'This page is not available yet.', 'nucleo' ) } ) );
		};
	}

	function mount() {
		const container = document.getElementById( 'nucleo-admin-app' );
		if ( ! container ) {
			return;
		}
		const page = container.dataset.page || '';
		const admin = window.nucleoAdmin || {};
		const registered = window.nucleoPages || {};
		const Component = registered[ page ] || fallbackPage( page, admin.pages || [] );
		const element = h( Component );

		if ( createRoot ) {
			createRoot( container ).render( element );
		} else {
			render( element, container );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', mount );
	} else {
		mount();
	}
} )();

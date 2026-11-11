/**
 * Pagina Assistenza di Nucleo (nucleo-support), in Free e in Pro.
 * Porta alla pagina feedback del sito di vendita e prepara i dati del sito da incollare nella richiesta.
 * Nessun build: wp.element, wp.i18n. Dipende da ui.js (window.nucleoUI).
 * Nei test (node --test) si esportano le funzioni pure; nel browser è window.nucleoPages['nucleo-support'].
 */
( function () {
	'use strict';

	const root = typeof window !== 'undefined' ? window : {};

	/** Righe "Chiave: valore" con i dati del sito; i valori mancanti restano fuori. */
	function systemReport( cfg ) {
		const sys = cfg.system || {};
		const rows = [
			[ 'Nucleo', cfg.version ? cfg.version + ( cfg.edition === 'pro' ? ' Pro' : ' Free' ) : '' ],
			[ 'WordPress', sys.wordpress ],
			[ 'PHP', sys.php ],
			[ 'Theme', sys.theme ],
			[ 'Locale', sys.locale ],
		];
		return rows.filter( ( r ) => r[ 1 ] ).map( ( r ) => r[ 0 ] + ': ' + r[ 1 ] ).join( '\n' );
	}

	const api = { systemReport };

	const el = root.wp && root.wp.element;
	if ( el ) {
		const { createElement: h, useState } = el;
		const { __ } = ( root.wp && root.wp.i18n ) || { __: ( text ) => text };
		const UI = root.nucleoUI;

		function SupportPage() {
			const cfg = root.nucleoAdmin;
			const links = cfg.links || {};
			const report = systemReport( cfg );
			const [ copied, setCopied ] = useState( false );
			const copy = () => {
				root.navigator.clipboard.writeText( report ).then( () => {
					setCopied( true );
					root.setTimeout( () => setCopied( false ), 2000 );
				} );
			};

			return h( UI.Page, {
				title: __( 'Support', 'nucleo' ),
				subtitle: __( 'Report a problem, ask a question or suggest an improvement.', 'nucleo' ),
			},
			h( 'div', { className: 'nc-grid' },
				h( 'div', { className: 'nc-col-8' }, h( UI.Card, { title: __( 'Write to us', 'nucleo' ) },
					h( 'div', { className: 'nc-stack' },
						h( 'p', { style: { margin: 0 } }, __( 'The feedback form on nucleowp.dev reaches the Nucleo team. Describe what you did, what you expected and what happened.', 'nucleo' ) ),
						h( 'p', { style: { margin: 0 } }, __( 'Paste the site details below into the message: they help us reproduce the problem.', 'nucleo' ) ),
						h( 'div', { className: 'nc-row' },
							h( 'a', { className: 'nc-btn nc-btn--primary', href: links.feedback, target: '_blank', rel: 'noopener' },
								__( 'Open the feedback form', 'nucleo' ) ) ) ) ) ),
				h( 'div', { className: 'nc-col-4' }, h( UI.Card, { title: __( 'Before writing', 'nucleo' ) },
					h( 'ul', { className: 'nc-stack' },
						h( 'li', null, h( 'a', { href: links.docs, target: '_blank', rel: 'noopener' }, __( 'Documentation', 'nucleo' ) ) ),
						h( 'li', null, h( 'a', { href: links.changelog, target: '_blank', rel: 'noopener' }, __( 'Release notes', 'nucleo' ) ) ) ) ) ),
				h( 'div', { className: 'nc-col-12' }, h( UI.Card, {
					title: __( 'Site details', 'nucleo' ),
					actions: h( 'button', { type: 'button', className: 'nc-btn nc-btn--secondary nc-btn--small', onClick: copy },
						copied ? __( 'Copied', 'nucleo' ) : __( 'Copy', 'nucleo' ) ),
				},
				h( 'div', { className: 'nc-snippet' }, h( 'pre', null, h( 'code', null, report ) ) ),
				h( 'p', { className: 'nc-muted' }, __( 'No personal data: only versions and language. Nothing is sent until you paste it.', 'nucleo' ) ) ) ) ) );
		}

		root.nucleoPages = root.nucleoPages || {};
		root.nucleoPages[ 'nucleo-support' ] = SupportPage;
	}

	if ( typeof module === 'object' && module.exports ) {
		module.exports = api;
	}
} )();

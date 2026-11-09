/**
 * Cornice del mockup dell'anteprima: telefono o tablet con barra di stato in alto, barra del browser e barra in basso,
 * iOS o Android. Misure delle barre da @barre; dimensioni dello schermo da preview-size.js, passato da chi chiama (di default
 * window.nucleoPreviewSize). createFrame(h) riceve createElement da wp.element. window.nucleoMockup; export CommonJS per i test.
 */
( function ( root ) {
	'use strict';

	const KIND = { mobile: 'phone', tablet: 'tablet' };

	/* Misure per tipo e sistema. Il riquadro landscape vale solo dove cambia (iPhone: barra in alto più bassa). */
	const SPEC = {
		'phone-ios': { bezel: 12, radius: 54, top: 47, bottom: 34, landscape: { top: 24, bottom: 21 } },
		'phone-android': { bezel: 10, radius: 36, top: 24, bottom: 48 },
		'tablet-ios': { bezel: 20, radius: 32, top: 24, bottom: 20 },
		'tablet-android': { bezel: 18, radius: 24, top: 24, bottom: 48 },
	};

	/* Barra del browser in px dispositivo: espansa e ridotta (Android ridotta non si vede). */
	const URL = {
		ios: { expanded: 56, collapsed: 28 },
		android: { expanded: 56, collapsed: 0 },
	};

	/** Geometria del mockup: cornice esterna, barre, barra del browser e riquadro utile. Null per dispositivi o sistemi non previsti. */
	function layout( deviceId, orientation, os, sizes = root.nucleoPreviewSize ) {
		const kind = Object.prototype.hasOwnProperty.call( KIND, deviceId ) ? KIND[ deviceId ] : null;
		const spec = kind ? SPEC[ kind + '-' + os ] : undefined;
		if ( ! spec ) {
			return null;
		}
		const bars = ( orientation === 'landscape' && spec.landscape ) || spec;
		const s = sizes.size( deviceId, orientation );
		return {
			outer: { width: s.width + 2 * spec.bezel, height: s.height + 2 * spec.bezel },
			bezel: spec.bezel,
			radius: spec.radius,
			top: bars.top,
			bottom: bars.bottom,
			url: { expanded: URL[ os ].expanded, collapsed: URL[ os ].collapsed },
			viewport: { width: s.width, height: s.height - bars.top - bars.bottom },
		};
	}

	/** Componente React del mockup, con createElement passato da fuori (wp.element). */
	function createFrame( h ) {
		function icon( cls, shapes ) {
			return h( 'svg', { className: 'nc-mockup__icon ' + cls, viewBox: '0 0 24 24', 'aria-hidden': 'true', focusable: 'false' }, ...shapes );
		}

		function statusIcons() {
			return [
				icon( 'nc-mockup__signal', [
					h( 'path', { d: 'M2 18h3v4H2zM7 14h3v8H7zM12 9h3v13h-3zM17 4h3v18h-3z' } ),
				] ),
				icon( 'nc-mockup__wifi', [
					h( 'path', { d: 'M2.5 9.5a14 14 0 0 1 19 0l-2 2a11 11 0 0 0-15 0z' } ),
					h( 'path', { d: 'M12 20.5 8.5 17a5 5 0 0 1 7 0z' } ),
				] ),
				icon( 'nc-mockup__battery', [
					h( 'path', { d: 'M3 7h16v10H3z' } ),
					h( 'path', { d: 'M20 10h1.5v4H20z' } ),
				] ),
			];
		}

		function androidKeys() {
			return [
				icon( 'nc-mockup__keys', [
					h( 'path', { className: 'nc-mockup__key', d: 'M15 5 8 12l7 7z' } ),
				] ),
				icon( 'nc-mockup__keys', [
					h( 'circle', { className: 'nc-mockup__key', cx: 12, cy: 12, r: 7 } ),
				] ),
				icon( 'nc-mockup__keys', [
					h( 'rect', { className: 'nc-mockup__key', x: 6, y: 6, width: 12, height: 12, rx: 2 } ),
				] ),
			];
		}

		function lockIcon() {
			return icon( 'nc-mockup__lock', [
				h( 'path', { d: 'M7 10V8a5 5 0 0 1 10 0v2h1v11H6V10zm2 0h6V8a3 3 0 0 0-6 0z' } ),
			] );
		}

		/** Contenuto della barra del browser: iOS espansa con aA, pillola e ricarica; ridotta solo l'host. Android ridotta è vuota. */
		function urlContent( os, host, collapsed ) {
			if ( os === 'ios' ) {
				if ( collapsed ) {
					return [ h( 'span', { className: 'nc-mockup__url-host' }, host ) ];
				}
				return [ h( 'div', { className: 'nc-mockup__url-row' },
					h( 'span', { className: 'nc-mockup__aa' }, 'aA' ),
					h( 'div', { className: 'nc-mockup__pill' }, lockIcon(), h( 'span', { className: 'nc-mockup__host' }, host ) ),
					icon( 'nc-mockup__reload', [
						h( 'path', { d: 'M12 5V2L8 6l4 4V7a5 5 0 1 1-5 5H5a7 7 0 1 0 7-7z' } ),
					] )
				) ];
			}
			if ( collapsed ) {
				return [];
			}
			return [ h( 'div', { className: 'nc-mockup__url-row' },
				icon( 'nc-mockup__home-icon', [
					h( 'path', { d: 'M12 3 3 11h2v9h5v-6h4v6h5v-9h2z' } ),
				] ),
				h( 'div', { className: 'nc-mockup__pill' }, lockIcon(), h( 'span', { className: 'nc-mockup__host' }, host ) ),
				h( 'span', { className: 'nc-mockup__tabs' }, '1' ),
				icon( 'nc-mockup__dots', [
					h( 'circle', { cx: 12, cy: 5, r: 2 } ),
					h( 'circle', { cx: 12, cy: 12, r: 2 } ),
					h( 'circle', { cx: 12, cy: 19, r: 2 } ),
				] )
			) ];
		}

		function urlBar( os, geo, browser ) {
			const collapsed = Boolean( browser.collapsed );
			const className = 'nc-mockup__url' + ( collapsed ? ' nc-mockup__url--collapsed' : '' );
			return h( 'div', {
				className,
				style: { height: collapsed ? geo.url.collapsed : geo.url.expanded },
				'aria-hidden': 'true',
			}, ...urlContent( os, browser.host, collapsed ) );
		}

		function bar( edge, height, content ) {
			if ( ! height ) {
				return null;
			}
			return h( 'div', { className: 'nc-mockup__bar nc-mockup__bar--' + edge, style: { height }, 'aria-hidden': 'true' }, ...content );
		}

		function Frame( props ) {
			const { layout: geo, os, device, orientation, children, browser } = props;
			if ( ! geo ) {
				return null;
			}
			const kind = KIND[ device ];
			const notch = os === 'ios' && kind === 'phone' && orientation !== 'landscape';
			const topContent = [
				h( 'span', { className: 'nc-mockup__time' }, '9:41' ),
				notch ? h( 'span', { className: 'nc-mockup__notch' } ) : null,
				h( 'span', { className: 'nc-mockup__status' }, ...statusIcons() ),
			];
			const bottomContent = os === 'ios'
				? [ h( 'span', { className: 'nc-mockup__home' } ) ]
				: [ h( 'span', { className: 'nc-mockup__keys-row' }, ...androidKeys() ) ];

			return h( 'div', {
				className: `nc-mockup nc-mockup--${ os } nc-mockup--${ kind } nc-mockup--${ orientation === 'landscape' ? 'landscape' : 'portrait' }`,
				style: {
					width: geo.outer.width,
					height: geo.outer.height,
					padding: geo.bezel,
					borderRadius: geo.radius,
				},
			},
				h( 'div', {
					className: 'nc-mockup__screen',
					style: {
						width: geo.outer.width - 2 * geo.bezel,
						height: geo.outer.height - 2 * geo.bezel,
						borderRadius: Math.max( 0, geo.radius - geo.bezel ),
					},
				},
					bar( 'top', geo.top, topContent ),
					browser ? urlBar( os, geo, browser ) : null,
					h( 'div', {
						className: 'nc-mockup__content',
						style: { width: geo.viewport.width },
					}, children ),
					bar( 'bottom', geo.bottom, bottomContent )
				)
			);
		}

		return Frame;
	}

	const api = { layout, createFrame };
	if ( typeof module === 'object' && module.exports ) {
		module.exports = api;
	} else {
		root.nucleoMockup = api;
	}
} )( typeof window !== 'undefined' ? window : globalThis );

/**
 * Dimensioni dell'anteprima dell'editor: ogni dispositivo ha larghezza e altezza reali, la pagina si disegna a
 * quella misura e si riduce in scala per entrare nello spazio libero. Le misure seguono i breakpoint del tema
 * (md 768, lg 1024, xl 1920). window.nucleoPreviewSize; export CommonJS per i test.
 */
( function ( root ) {
	'use strict';

	const DEVICES = [
		{ id: 'mobile', width: 390, height: 844, rotates: true },
		{ id: 'tablet', width: 820, height: 1180, rotates: true },
		{ id: 'desktop', width: 1440, height: 900, rotates: false },
		{ id: 'ultra', width: 2560, height: 1080, rotates: false },
	];

	/** Misura del dispositivo nell'orientamento chiesto; un id sconosciuto vale desktop. */
	function size( id, orientation ) {
		const d = DEVICES.find( ( x ) => x.id === id ) || DEVICES[ 2 ];
		return d.rotates && orientation === 'landscape' ? { width: d.height, height: d.width } : { width: d.width, height: d.height };
	}

	/** Riquadro in scala dentro availW × availH: scala tra 0,1 e 1, arrotondata a due decimali. */
	function fit( box, availW, availH ) {
		const raw = Math.min( 1, availW / box.width, availH / box.height );
		const scale = Math.max( 0.1, Math.floor( ( Number.isFinite( raw ) ? raw : 0 ) * 100 ) / 100 );
		return { width: Math.round( box.width * scale ), height: Math.round( box.height * scale ), scale };
	}

	const api = { DEVICES, size, fit };
	if ( typeof module === 'object' && module.exports ) {
		module.exports = api;
	} else {
		root.nucleoPreviewSize = api;
	}
} )( typeof window !== 'undefined' ? window : globalThis );

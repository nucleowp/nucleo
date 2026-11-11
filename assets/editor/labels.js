/**
 * Etichette dell'editor per il cliente: campi dei manifest, regole di qualità, proprietà CSS,
 * e conversione tra HTML di soli paragrafi e testo semplice.
 * Nei test (node --test) si esporta con module.exports; nel browser è window.nucleoLabels.
 */
( function () {
	'use strict';

	/** Traduzione con wp.i18n nel browser; nei test (node) resta l'inglese della sorgente. */
	const { __ } = ( typeof wp !== 'undefined' && wp.i18n ) || { __: ( text ) => text };

	const FIELDS = {
		alt: __( 'Alt text', 'nucleo' ), anchor: __( 'Anchor', 'nucleo' ), arrows: __( 'Arrows', 'nucleo' ), category: __( 'Category', 'nucleo' ), consent: __( 'Consent', 'nucleo' ),
		decorative: __( 'Decorative', 'nucleo' ), empty: __( 'Text if empty', 'nucleo' ), faq: __( 'FAQ', 'nucleo' ), fields: __( 'Fields', 'nucleo' ),
		height: __( 'Height', 'nucleo' ), href: __( 'Link (URL)', 'nucleo' ), html: __( 'Content', 'nucleo' ), icon: __( 'Icon', 'nucleo' ), id: __( 'Identifier', 'nucleo' ), item: __( 'Item', 'nucleo' ),
		items: __( 'Items', 'nucleo' ), label: __( 'Label', 'nucleo' ), layout: __( 'Arrangement', 'nucleo' ), level: __( 'Level', 'nucleo' ), limit: __( 'Limit', 'nucleo' ),
		link: __( 'Link', 'nucleo' ), menu: __( 'Menu', 'nucleo' ), mobile: __( 'Mobile', 'nucleo' ), mode: __( 'Mode', 'nucleo' ), name: __( 'Name', 'nucleo' ),
		newTab: __( 'Open in a new tab', 'nucleo' ), openFirst: __( 'Open first', 'nucleo' ), orderby: __( 'Order by', 'nucleo' ),
		orientation: __( 'Orientation', 'nucleo' ), perPage: __( 'Items per page', 'nucleo' ), perView: __( 'Visible items', 'nucleo' ), plugin: __( 'Plugin', 'nucleo' ),
		postType: __( 'Content type', 'nucleo' ), poster: __( 'Poster image', 'nucleo' ), priority: __( 'Priority', 'nucleo' ), provider: __( 'Provider', 'nucleo' ),
		responsiveMode: __( 'Responsive mode', 'nucleo' ), size: __( 'Size', 'nucleo' ), sizes: __( 'Sizes attribute', 'nucleo' ),
		src: __( 'Image (URL)', 'nucleo' ), srcset: __( 'Image set (srcset)', 'nucleo' ), tag: __( 'HTML tag', 'nucleo' ), taxonomy: __( 'Taxonomy', 'nucleo' ),
		term: __( 'Term', 'nucleo' ), text: __( 'Text', 'nucleo' ), title: __( 'Title', 'nucleo' ), variant: __( 'Variant', 'nucleo' ), videoId: __( 'Video ID', 'nucleo' ), width: __( 'Width', 'nucleo' ),
		// Testi e impostazioni dei blocchi interattivi.
		allLabel: __( '"All" text', 'nucleo' ),
		applyLabel: __( '"Apply" text', 'nucleo' ),
		buttonLabel: __( 'Button text', 'nucleo' ),
		closeLabel: __( '"Close" text', 'nucleo' ),
		controlsPosition: __( 'Controls position', 'nucleo' ),
		filtersLabel: __( '"Filters" text', 'nucleo' ),
		fromLabel: __( '"From" text', 'nucleo' ),
		gridLabel: __( '"Grid" text', 'nucleo' ),
		hideLabel: __( 'Hide the label', 'nucleo' ),
		monthLabel: __( '"This month" text', 'nucleo' ),
		moreLabel: __( '"Load more" text', 'nucleo' ),
		nextLabel: __( '"Next slide" text', 'nucleo' ),
		pastLabel: __( '"Past" text', 'nucleo' ),
		pauseLabel: __( '"Pause" text', 'nucleo' ),
		placeholder: __( 'Placeholder', 'nucleo' ),
		playLabel: __( '"Play" text', 'nucleo' ),
		prevLabel: __( '"Previous slide" text', 'nucleo' ),
		resultsLabel: __( 'Results text', 'nucleo' ),
		rowsLabel: __( '"Rows" text', 'nucleo' ),
		sortLabel: __( '"Sort by" text', 'nucleo' ),
		toLabel: __( '"To" text', 'nucleo' ),
		upcomingLabel: __( '"Upcoming" text', 'nucleo' ),
	};

	const RULES = {
		'a11y.contrast': __( 'Insufficient contrast', 'nucleo' ),
		'a11y.landmark.missing': __( 'Missing landmark', 'nucleo' ),
		'a11y.link.empty': __( 'Link without text', 'nucleo' ),
		'perf.lcp.lazy': __( 'Main image loaded late', 'nucleo' ),
		'seo.canonical.external': __( 'Canonical pointing to another site', 'nucleo' ),
		'seo.content.thin': __( 'Content too short', 'nucleo' ),
		'seo.description.length': __( 'Description length not adequate', 'nucleo' ),
		'seo.description.missing': __( 'Missing description', 'nucleo' ),
		'seo.h1.duplicate': __( 'Duplicate H1 heading', 'nucleo' ),
		'seo.h1.missing': __( 'Missing H1 heading', 'nucleo' ),
		'seo.h1.multiple': __( 'Multiple H1 headings', 'nucleo' ),
		'seo.heading.empty': __( 'Empty heading', 'nucleo' ),
		'seo.heading.skip': __( 'Heading level skipped', 'nucleo' ),
		'seo.hreflang.missing': __( 'Missing hreflang', 'nucleo' ),
		'seo.image.alt.missing': __( 'Image without alt text', 'nucleo' ),
		'seo.links.internal.none': __( 'No internal links', 'nucleo' ),
		'seo.noindex': __( 'Page excluded from search engines', 'nucleo' ),
		'seo.title.duplicate': __( 'Duplicate title', 'nucleo' ),
		'seo.title.length': __( 'Title length not adequate', 'nucleo' ),
	};

	const STYLES = {
		padding: __( 'Padding', 'nucleo' ),
		margin: __( 'Margin', 'nucleo' ),
		background: __( 'Background', 'nucleo' ),
		color: __( 'Text color', 'nucleo' ),
		fontSize: __( 'Font size', 'nucleo' ),
		fontWeight: __( 'Font weight', 'nucleo' ),
		lineHeight: __( 'Line height', 'nucleo' ),
		textAlign: __( 'Text alignment', 'nucleo' ),
		gap: __( 'Gap', 'nucleo' ),
		width: __( 'Width', 'nucleo' ),
		maxWidth: __( 'Max width', 'nucleo' ),
		height: __( 'Height', 'nucleo' ),
		display: __( 'Display', 'nucleo' ),
		borderRadius: __( 'Border radius', 'nucleo' ),
		border: __( 'Border', 'nucleo' ),
		// Gruppi controls, buttons, choices, carousel, link e results.
		controlBackground: __( 'Control background', 'nucleo' ),
		controlColor: __( 'Control text color', 'nucleo' ),
		controlBorder: __( 'Control border', 'nucleo' ),
		controlRadius: __( 'Control radius', 'nucleo' ),
		controlPadding: __( 'Control padding', 'nucleo' ),
		controlFocus: __( 'Focus color', 'nucleo' ),
		buttonBackground: __( 'Button background', 'nucleo' ),
		buttonColor: __( 'Button text color', 'nucleo' ),
		buttonBorder: __( 'Button border', 'nucleo' ),
		buttonRadius: __( 'Button radius', 'nucleo' ),
		buttonPadding: __( 'Button padding', 'nucleo' ),
		controlBackgroundActive: __( 'Active control background', 'nucleo' ),
		controlColorActive: __( 'Active control text color', 'nucleo' ),
		controlBorderActive: __( 'Active control border', 'nucleo' ),
		popoverBackground: __( 'Panel background', 'nucleo' ),
		controlSize: __( 'Control size', 'nucleo' ),
		dotColor: __( 'Dot color', 'nucleo' ),
		dotColorActive: __( 'Active dot color', 'nucleo' ),
		controlsInset: __( 'Controls distance from the edge', 'nucleo' ),
		linkColor: __( 'Link color', 'nucleo' ),
		linkColorHover: __( 'Link color on hover', 'nucleo' ),
		linkDecoration: __( 'Link underline', 'nucleo' ),
		linkDecorationHover: __( 'Link underline on hover', 'nucleo' ),
		resultsColumns: __( 'Result columns', 'nucleo' ),
		resultsGap: __( 'Gap between results', 'nucleo' ),
	};

	/** Chiave sconosciuta: camelCase e separatori diventano parole, con la prima maiuscola. */
	function humanize( key ) {
		const words = String( key || '' ).replace( /([a-z0-9])([A-Z])/g, '$1 $2' ).replace( /[_-]+/g, ' ' ).toLowerCase().trim();
		return words.charAt( 0 ).toUpperCase() + words.slice( 1 );
	}

	/** Etichetta di un campo: def.label se c'è, altrimenti il nome della chiave. */
	function field( key, def ) {
		if ( def && typeof def.label === 'string' && def.label !== '' ) {
			return def.label;
		}
		return Object.prototype.hasOwnProperty.call( FIELDS, key ) ? FIELDS[ key ] : humanize( key );
	}

	/** Titolo leggibile di una regola di qualità. Regola sconosciuta: resta il codice. */
	function rule( code ) {
		return Object.prototype.hasOwnProperty.call( RULES, code ) ? RULES[ code ] : String( code || '' );
	}

	/** Nome di una proprietà CSS. Sconosciuta: leggibile dalla chiave. */
	function style( prop ) {
		return Object.prototype.hasOwnProperty.call( STYLES, prop ) ? STYLES[ prop ] : humanize( prop );
	}

	const ENTITIES = { '&lt;': '<', '&gt;': '>', '&quot;': '"', '&#39;': "'", '&amp;': '&' };
	const decode = ( s ) => s.replace( /&(?:lt|gt|quot|#39|amp);/g, ( e ) => ENTITIES[ e ] );
	const escape = ( s ) => s.replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' ).replace( /"/g, '&quot;' );

	/**
	 * HTML di soli paragrafi <p> con testo e <br> al loro interno, oppure testo senza tag.
	 * Restituisce il testo semplice (paragrafi separati da riga vuota, a capo come \n). Null se c'è altra formattazione.
	 */
	function toPlain( html ) {
		if ( typeof html !== 'string' ) {
			return null;
		}
		if ( ! html.includes( '<' ) ) {
			return decode( html );
		}
		if ( ! /^(?:<p>[^<]*(?:<br\s*\/?>[^<]*)*<\/p>)+$/.test( html ) ) {
			return null;
		}
		const paras = html.match( /<p>[^<]*(?:<br\s*\/?>[^<]*)*<\/p>/g ) || [];
		return paras
			.map( ( p ) => decode( p.slice( 3, -4 ).split( /<br\s*\/?>/ ).join( '\n' ) ) )
			.join( '\n\n' );
	}

	/** Testo semplice in HTML di paragrafi: righe vuote separano i paragrafi, a capo diventa <br>. */
	function fromPlain( text ) {
		if ( typeof text !== 'string' || text === '' ) {
			return '';
		}
		return text.replace( /\r\n?/g, '\n' )
			.split( /\n{2,}/ )
			.filter( ( p ) => p !== '' )
			.map( ( p ) => '<p>' + escape( p ).split( '\n' ).join( '<br>' ) + '</p>' )
			.join( '' );
	}

	const api = { field, rule, style, toPlain, fromPlain };

	if ( typeof module === 'object' && module.exports ) {
		module.exports = api;
	} else {
		window.nucleoLabels = api;
	}
} )();

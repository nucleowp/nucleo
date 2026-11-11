/**
 * Pagina Design system (slug nucleo-design): colori, tipografia, spazi, forme, contenitori.
 * Nei test (node --test) si esportano le funzioni pure; nel browser è window.nucleoPages['nucleo-design'].
 */
( function () {
	'use strict';

	const root = typeof window !== 'undefined' ? window : {};
	// In node si usa ui.js direttamente; nel browser è window.nucleoUI, caricato prima di questa pagina.
	const nodeUI = typeof module === 'object' && module.exports ? require( '../ui.js' ) : null;

	const MIN_CONTRAST = 4.5;
	const MAX_WEIGHTS = 6;
	const WEIGHTS = [ 100, 200, 300, 400, 500, 600, 700, 800, 900 ];
	const TABS = [ 'colors', 'typography', 'spacing', 'shapes', 'containers' ];
	const PAIRS = [ [ 'text', 'background' ], [ 'text', 'surface' ], [ 'muted', 'background' ], [ 'muted', 'surface' ], [ 'background', 'primary' ] ];
	const SYSTEM_FONTS = [ 'system-ui, -apple-system, "Segoe UI", sans-serif', 'ui-serif, Georgia, serif' ];
	const SANS = 'system-ui, -apple-system, "Segoe UI", sans-serif';

	/** Esadecimale #rgb o #rrggbb. */
	function isHex( v ) {
		return typeof v === 'string' && /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.test( v );
	}

	/** Righe di contrasto per le coppie note; omesse se manca uno dei due colori. ok se almeno 4,5:1. */
	function contrastRows( colors ) {
		const ui = nodeUI || root.nucleoUI;
		return PAIRS
			.filter( ( [ fg, bg ] ) => colors[ fg ] !== undefined && colors[ bg ] !== undefined )
			.map( ( [ fg, bg ] ) => {
				const ratio = ui.contrast( colors[ fg ], colors[ bg ] );
				return { fg, bg, ratio, ok: ratio !== null && ratio >= MIN_CONTRAST };
			} );
	}

	const isObj = ( v ) => v !== null && typeof v === 'object' && ! Array.isArray( v );

	/** Solo i valori di `after` diversi da `before`, con la stessa struttura; gli array si confrontano per intero. */
	function changedTokens( before, after ) {
		const out = {};
		Object.keys( after ).forEach( ( key ) => {
			const prev = isObj( before ) ? before[ key ] : undefined;
			const next = after[ key ];
			if ( isObj( next ) ) {
				const sub = changedTokens( prev, next );
				if ( Object.keys( sub ).length > 0 ) {
					out[ key ] = sub;
				}
			} else if ( JSON.stringify( prev ) !== JSON.stringify( next ) ) {
				out[ key ] = next;
			}
		} );
		return out;
	}

	/** Scala fluida [minimo, massimo] in rem, convertita in px (1 rem = 16 px). */
	function fluidPx( pair ) {
		return { min: pair[ 0 ] * 16, max: pair[ 1 ] * 16 };
	}

	/** Errore di un intervallo [minimo, massimo]: 'number', 'order' o null. */
	function rangeError( pair ) {
		const [ min, max ] = pair;
		if ( ! ( Number.isFinite( min ) && min > 0 && Number.isFinite( max ) && max > 0 ) ) {
			return 'number';
		}
		return min <= max ? null : 'order';
	}

	/** Numero con due decimali e virgola, come in italiano: 14,66. */
	const formatNumber = ( n ) => n.toFixed( 2 ).replace( '.', ',' );

	const api = { contrastRows, changedTokens, isHex, fluidPx };

	/* ---------- Componenti (solo con window.wp.element) ---------- */

	const el = root.wp && root.wp.element;
	if ( el ) {
		const { createElement: h, useState, useEffect } = el;
		const { __ } = ( root.wp && root.wp.i18n ) || { __: ( text ) => text };
		const { Card, Pill, Notice, Tabs, Table, Skeleton, ErrorBox, Empty, Page } = root.nucleoUI;
		const clone = ( v ) => JSON.parse( JSON.stringify( v ) );
		const GRID_COLORS = { display: 'grid', gridTemplateColumns: 'minmax(0, 1fr) 400px', gap: 16, alignItems: 'start' };
		const GRID_TYPE = { display: 'grid', gridTemplateColumns: '420px minmax(0, 1fr)', gap: 16, alignItems: 'start' };
		const STACK = { display: 'flex', flexDirection: 'column', gap: 16 };

		function ratioPill( r ) {
			if ( r.ratio === null ) {
				return h( Pill, { tone: 'err' }, __( 'invalid value', 'nucleo' ) );
			}
			return h( Pill, { tone: r.ok ? 'ok' : 'warn' }, `${ formatNumber( r.ratio ) }:1 · ${ r.ok ? 'AA' : __( 'below AA', 'nucleo' ) }` );
		}

		function ContrastCard( { colors } ) {
			const rows = contrastRows( colors );
			const below = rows.filter( ( r ) => ! r.ok ).length;
			const limit = String( MIN_CONTRAST ).replace( '.', ',' );
			return h( Card, {
				title: __( 'Contrast', 'nucleo' ),
				actions: h( Pill, { tone: below > 0 ? 'warn' : 'ok' }, below > 0 ? `${ below } ${ __( 'below', 'nucleo' ) } ${ limit }:1` : __( 'All pairs pass AA', 'nucleo' ) ),
			},
			h( 'div', { style: { padding: '6px 20px 12px' } },
				rows.map( ( r, i ) => h( 'div', {
					key: `${ r.fg }-${ r.bg }`,
					style: {
						display: 'flex',
						justifyContent: 'space-between',
						alignItems: 'center',
						gap: 8,
						padding: '8px 0',
						fontSize: 12,
						borderBottom: i < rows.length - 1 ? '1px dashed var(--nc-border)' : undefined,
					},
				},
				// Il collegamento tra i nomi dei token (text, background) si traduce: "text su background".
				h( 'span', null, `${ r.fg } ${ __( 'on', 'nucleo' ) } ${ r.bg }` ),
				ratioPill( r ) ) ) ) );
		}

		function Preview( { c } ) {
			const btn = { padding: '8px 14px', borderRadius: 8, fontSize: 13, fontFamily: SANS };
			return h( Card, {
				title: __( 'Preview', 'nucleo' ),
				actions: h( 'span', { className: 'nc-field__help' }, __( 'Live preview', 'nucleo' ) ),
			},
			h( 'div', { style: { background: c.background, padding: 20, display: 'flex', flexDirection: 'column', gap: 12 } },
				h( 'div', { style: { fontFamily: 'Georgia, serif', fontSize: 24, lineHeight: '28px', color: c.text } }, __( 'Pottery classes', 'nucleo' ) ),
				h( 'p', { style: { margin: 0, fontFamily: SANS, fontSize: 14, lineHeight: '22px', color: c.muted } }, __( 'Secondary text in the muted color.', 'nucleo' ) ),
				h( 'div', { style: { display: 'flex', gap: 8 } },
					h( 'span', { style: { ...btn, background: c.primary, color: c.background } }, __( 'Primary', 'nucleo' ) ),
					h( 'span', { style: { ...btn, background: c.secondary, color: c.background } }, __( 'Secondary', 'nucleo' ) ) ),
				h( 'div', { style: { padding: 12, background: c.surface, border: `1px solid ${ c.border }`, borderRadius: 12, fontSize: 13, fontFamily: SANS, color: c.text } }, __( 'Card on surface', 'nucleo' ) ) ) );
		}

		function ColorRow( { name, value, error, onChange } ) {
			const [ open, setOpen ] = useState( false );
			const { Popover, ColorPicker } = root.wp.components;
			return h( 'tr', null,
				h( 'td', null, h( 'span', { 'aria-hidden': 'true', style: { display: 'block', width: 32, height: 32, borderRadius: 4, border: '1px solid currentColor', background: isHex( value ) ? value : 'transparent' } } ) ),
				h( 'td', null, `$color.${ name }` ),
				h( 'td', null,
					h( 'div', { className: error ? 'nc-field nc-field--error' : 'nc-field' },
						h( 'input', {
							type: 'text',
							className: 'nc-input',
							'aria-label': `${ __( 'Value of', 'nucleo' ) } ${ name }`,
							'aria-invalid': error ? 'true' : undefined,
							value,
							onChange: ( e ) => onChange( e.target.value ),
						} ),
						error && h( 'p', { className: 'nc-field__error', role: 'alert' }, error ) ) ),
				h( 'td', null,
					h( 'button', { type: 'button', className: 'nc-btn nc-btn--secondary nc-btn--small', 'aria-expanded': String( open ), onClick: () => setOpen( ! open ) }, __( 'Pick', 'nucleo' ) ),
					open && h( Popover, { onClose: () => setOpen( false ) },
						h( ColorPicker, { color: isHex( value ) ? value : '#000000', onChangeComplete: ( c ) => onChange( c.hex ) } ) ) ) );
		}

		function ColorsTab( { draft, update } ) {
			const colors = draft.color || {};
			return h( 'div', { style: GRID_COLORS },
				h( 'div', null,
					h( 'div', { className: 'nc-table-wrap' },
						h( 'table', { className: 'nc-table' },
							h( 'thead', null,
								h( 'tr', null, [ __( 'Sample', 'nucleo' ), __( 'Token', 'nucleo' ), __( 'Value', 'nucleo' ), __( 'Picker', 'nucleo' ) ].map( ( l ) => h( 'th', { key: l, scope: 'col' }, l ) ) ) ),
							h( 'tbody', null, Object.keys( colors ).map( ( k ) => h( ColorRow, {
								key: k,
								name: k,
								value: colors[ k ],
								error: isHex( colors[ k ] ) ? null : __( 'Enter a hex value, for example #a4462a.', 'nucleo' ),
								onChange: ( v ) => update( 'color', k, v ),
							} ) ) ) ) ),
					h( 'div', { style: { padding: '12px 20px' } },
						h( 'button', { type: 'button', className: 'nc-btn nc-btn--tertiary', disabled: true, title: __( 'Custom tokens are not available yet.', 'nucleo' ) }, __( 'Add custom token', 'nucleo' ) ) ) ),
				h( 'div', { style: STACK },
					h( ContrastCard, { colors } ),
					h( Preview, { c: colors } ) ) );
		}

		function FontImport( { onImported } ) {
			const [ family, setFamily ] = useState( '' );
			const [ weights, setWeights ] = useState( [ 400, 700 ] );
			const [ italic, setItalic ] = useState( false );
			const [ busy, setBusy ] = useState( false );
			const [ notice, setNotice ] = useState( null );
			const name = family.trim();

			const toggle = ( w ) => setWeights( ( ws ) => ( ws.includes( w ) ? ws.filter( ( x ) => x !== w ) : [ ...ws, w ].sort( ( a, b ) => a - b ) ) );

			async function run() {
				setBusy( true );
				setNotice( null );
				const res = await root.nucleoUI.call( 'nucleo/fonts-import', { family: name, weights, italic } );
				setBusy( false );
				if ( res && res.ok === false ) {
					setNotice( { tone: 'err', text: ( res.error && res.error.message ) || __( 'Import failed.', 'nucleo' ) } );
					return;
				}
				onImported( name );
				setNotice( { tone: 'ok', text: __( 'Font imported into the site.', 'nucleo' ) } );
			}

			return h( 'div', { style: STACK },
				h( 'div', { className: 'nc-field' },
					h( 'label', { className: 'nc-field__label', htmlFor: 'nc-font-family' }, __( 'Family', 'nucleo' ) ),
					h( 'input', { id: 'nc-font-family', type: 'text', className: 'nc-input', value: family, onChange: ( e ) => setFamily( e.target.value ) } ) ),
				h( 'div', { className: 'nc-field' },
					h( 'span', { className: 'nc-field__label' }, `${ __( 'Weights', 'nucleo' ) } · ${ __( 'maximum', 'nucleo' ) } ${ MAX_WEIGHTS }` ),
					// Tutti i pesi su una riga: il pulsante si restringe invece di andare a capo.
					h( 'div', { style: { display: 'flex', gap: 4 } },
						WEIGHTS.map( ( w ) => h( 'button', {
							key: w,
							type: 'button',
							className: 'nc-btn nc-btn--small nc-btn--secondary',
							style: { flex: '1 1 0', minWidth: 0, padding: 0 },
							'aria-pressed': String( weights.includes( w ) ),
							disabled: ! weights.includes( w ) && weights.length >= MAX_WEIGHTS,
							onClick: () => toggle( w ),
						}, String( w ) ) ) ) ),
				h( 'label', { className: 'nc-check' },
					h( 'input', { type: 'checkbox', checked: italic, onChange: ( e ) => setItalic( e.target.checked ) } ),
					` ${ __( 'Include italic', 'nucleo' ) }` ),
				h( 'button', {
					type: 'button',
					className: 'nc-btn nc-btn--primary',
					disabled: busy || name === '' || weights.length === 0,
					onClick: run,
				}, busy && h( root.wp.components.Spinner ), busy ? __( 'Importing…', 'nucleo' ) : __( 'Import into site', 'nucleo' ) ),
				notice && h( Notice, { tone: notice.tone, onClose: () => setNotice( null ) }, notice.text ),
				h( 'p', { className: 'nc-field__help' }, __( 'Files are saved on your site: visitors do not contact Google.', 'nucleo' ) ) );
		}

		const CUSTOM_FONT = '__custom__';

		// Il select è il controllo principale; "Personalizzato…" mostra il campo di testo per lo stack CSS.
		function FontSelect( { id, label, value, options, importedFonts, onChange } ) {
			const [ custom, setCustom ] = useState( false );
			const labelOf = ( o ) => ( importedFonts.includes( o ) ? `${ o } (${ __( 'imported', 'nucleo' ) })` : o );
			return h( 'div', { className: 'nc-field' },
				h( 'label', { className: 'nc-field__label', htmlFor: id }, label ),
				h( 'select', {
					id,
					className: 'nc-select',
					value: custom ? CUSTOM_FONT : value,
					onChange: ( e ) => {
						if ( e.target.value === CUSTOM_FONT ) {
							setCustom( true );
							return;
						}
						setCustom( false );
						onChange( e.target.value );
					},
				},
				options.map( ( o ) => h( 'option', { key: o, value: o }, labelOf( o ) ) ),
				h( 'option', { key: CUSTOM_FONT, value: CUSTOM_FONT }, __( 'Custom…', 'nucleo' ) ) ),
				custom && h( 'input', {
					type: 'text',
					className: 'nc-input',
					'aria-label': `${ label } · ${ __( 'Custom…', 'nucleo' ) }`,
					value,
					onChange: ( e ) => onChange( e.target.value ),
				} ) );
		}

		function TypographyTab( { draft, update, rev, importedFonts, onImported } ) {
			const font = draft.font || {};
			const text = draft.text || {};
			const color = draft.color || {};
			const options = [ ...new Set( [ ...SYSTEM_FONTS, font.body, font.heading, ...importedFonts ].filter( Boolean ) ) ];

			const numberCell = ( which ) => ( _, row ) => {
				const error = rangeError( row.pair );
				const msg = error === 'number' ? __( 'Enter positive numbers.', 'nucleo' ) : error === 'order' ? __( 'Minimum must not exceed maximum.', 'nucleo' ) : null;
				return h( 'div', { className: msg ? 'nc-field nc-field--error' : 'nc-field' },
					h( 'input', {
						type: 'number',
						min: '0',
						step: '0.125',
						className: 'nc-input',
						style: { width: 72 },
						'aria-label': `${ which === 0 ? __( 'Minimum', 'nucleo' ) : __( 'Maximum', 'nucleo' ) } ${ row.name }`,
						'aria-invalid': msg ? 'true' : undefined,
						defaultValue: row.pair[ which ],
						onChange: ( e ) => {
							const v = Number( e.target.value );
							update( 'text', row.name, which === 0 ? [ v, row.pair[ 1 ] ] : [ row.pair[ 0 ], v ] );
						},
					} ),
					msg && h( 'p', { className: 'nc-field__error', role: 'alert' }, msg ) );
			};

			// Anteprima dentro un riquadro con il colore di sfondo del sito; la parola non si spezza e, se è più larga del riquadro, si tronca con ellissi.
			const sample = ( pair, which ) => {
				const px = fluidPx( pair )[ which ];
				const ok = Number.isFinite( px );
				return h( 'div', { style: {
					display: 'block',
					minWidth: 0,
					maxWidth: '100%',
					padding: '10px 12px',
					borderRadius: 8,
					border: '1px solid var(--nc-border)',
					overflow: 'hidden',
					whiteSpace: 'nowrap',
					textOverflow: 'ellipsis',
					overflowWrap: 'normal',
					wordBreak: 'keep-all',
					background: color.background,
					color: color.text,
					fontFamily: font.body,
					fontSize: ok ? `${ px }px` : undefined,
					lineHeight: 1.2,
				} }, ok ? __( 'Ceramics', 'nucleo' ) : '—' );
			};

			const rows = Object.keys( text ).map( ( name ) => ( { key: name, name, pair: text[ name ] } ) );
			const columns = [
				{ key: 'name', label: __( 'Name', 'nucleo' ), width: 84 },
				{ key: 'min', label: __( 'Minimum', 'nucleo' ), width: 112, render: numberCell( 0 ) },
				{ key: 'max', label: __( 'Maximum', 'nucleo' ), width: 112, render: numberCell( 1 ) },
				{ key: 'small', label: __( 'At 375 px', 'nucleo' ), render: ( _, row ) => sample( row.pair, 'min' ) },
				{ key: 'large', label: __( 'At 1920 px', 'nucleo' ), render: ( _, row ) => sample( row.pair, 'max' ) },
			];

			// Tabella a larghezze fisse: le colonne dei valori hanno spazio, quelle delle anteprime si dividono il resto.
			const scaleTable = rows.length === 0
				? h( Empty, { text: __( 'Nothing to show yet.', 'nucleo' ) } )
				: h( 'div', { className: 'nc-table-wrap' },
					h( 'table', { className: 'nc-table', style: { tableLayout: 'fixed', width: '100%' } },
						h( 'thead', null,
							h( 'tr', null, columns.map( ( c ) => h( 'th', { key: c.key, scope: 'col', style: c.width ? { width: c.width } : undefined }, c.label ) ) ) ),
						h( 'tbody', null, rows.map( ( row ) => h( 'tr', { key: `${ row.key }-${ rev }` },
							columns.map( ( c ) => h( 'td', { key: c.key }, c.render ? c.render( row[ c.key ], row ) : row[ c.key ] ) ) ) ) ) ) );

			return h( 'div', { style: GRID_TYPE },
				h( 'div', { style: STACK },
					h( Card, { title: __( 'Fonts', 'nucleo' ) },
						h( 'div', { style: STACK },
							h( FontSelect, { id: 'nc-font-body', label: __( 'Body font', 'nucleo' ), value: font.body, options, importedFonts, onChange: ( v ) => update( 'font', 'body', v ) } ),
							h( FontSelect, { id: 'nc-font-heading', label: __( 'Heading font', 'nucleo' ), value: font.heading, options, importedFonts, onChange: ( v ) => update( 'font', 'heading', v ) } ) ) ),
					h( Card, { title: __( 'Import from Google Fonts', 'nucleo' ) },
						h( FontImport, { onImported } ) ) ),
				h( 'div', { style: { minWidth: 0 } },
					h( Card, {
						title: __( 'Text scale', 'nucleo' ),
						actions: h( 'span', { className: 'nc-field__help' }, __( 'Fluid between 375 and 1920 px', 'nucleo' ) ),
						foot: __( 'Values in rem.', 'nucleo' ),
					},
					scaleTable ) ) );
		}

		function Bar( { value, max } ) {
			const pct = max > 0 ? Math.max( 0, Math.min( 100, ( value / max ) * 100 ) ) : 0;
			return h( 'div', { style: { width: '100%', minWidth: 120 } },
				h( 'div', { style: { width: `${ pct }%`, height: 8, borderRadius: 4, background: 'currentColor', opacity: 0.5 } } ) );
		}

		function SpacingTab( { draft } ) {
			const space = draft.space || {};
			const max = Math.max( 0, ...Object.values( space ) );
			const rows = Object.keys( space ).map( ( name ) => ( { key: name, name, value: space[ name ] } ) );
			return h( Card, { title: __( 'Spacing scale', 'nucleo' ) },
				h( Table, {
					rows,
					rowKey: ( r ) => r.key,
					columns: [
						{ key: 'name', label: __( 'Token', 'nucleo' ), render: ( v ) => `$space.${ v }` },
						{ key: 'value', label: __( 'rem', 'nucleo' ) },
						{ key: 'bar', label: __( 'Scale', 'nucleo' ), render: ( _, r ) => h( Bar, { value: r.value, max } ) },
					],
				} ) );
		}

		function ShapesTab( { draft } ) {
			const c = draft.color || {};
			const rowsOf = ( group ) => Object.keys( group || {} ).map( ( name ) => ( { key: name, name, value: group[ name ] } ) );
			return h( 'div', { style: STACK },
				h( Card, { title: __( 'Radius', 'nucleo' ) },
					h( Table, {
						rows: rowsOf( draft.radius ),
						rowKey: ( r ) => r.key,
						columns: [
							{ key: 'name', label: __( 'Token', 'nucleo' ), render: ( v ) => `$radius.${ v }` },
							{ key: 'value', label: __( 'Value', 'nucleo' ), render: ( v ) => h( 'code', null, v ) },
							{ key: 'preview', label: __( 'Preview', 'nucleo' ), render: ( _, r ) => h( 'span', { style: { display: 'inline-block', width: 48, height: 48, background: c.primary, borderRadius: r.value } } ) },
						],
					} ) ),
				h( Card, { title: __( 'Shadow', 'nucleo' ) },
					h( Table, {
						rows: rowsOf( draft.shadow ),
						rowKey: ( r ) => r.key,
						columns: [
							{ key: 'name', label: __( 'Token', 'nucleo' ), render: ( v ) => `$shadow.${ v }` },
							{ key: 'value', label: __( 'Value', 'nucleo' ), render: ( v ) => h( 'code', null, v ) },
							{ key: 'preview', label: __( 'Preview', 'nucleo' ), render: ( _, r ) => h( 'span', { style: { display: 'inline-block', width: 96, height: 48, margin: 8, background: c.surface, borderRadius: 8, boxShadow: r.value } } ) },
						],
					} ) ) );
		}

		function ContainersTab( { draft } ) {
			const container = draft.container || {};
			const max = Math.max( 0, ...Object.values( container ) );
			const rows = Object.keys( container ).map( ( name ) => ( { key: name, name, value: container[ name ] } ) );
			return h( Card, { title: __( 'Containers', 'nucleo' ) },
				h( Table, {
					rows,
					rowKey: ( r ) => r.key,
					columns: [
						{ key: 'name', label: __( 'Token', 'nucleo' ), render: ( v ) => `$container.${ v }` },
						{ key: 'value', label: __( 'Maximum width', 'nucleo' ), render: ( v ) => `${ v } px` },
						{ key: 'bar', label: __( 'Scale', 'nucleo' ), render: ( _, r ) => h( Bar, { value: r.value, max } ) },
					],
				} ) );
		}

		function Component() {
			const [ state, reload ] = root.nucleoUI.useLoad( () => root.nucleoUI.call( 'nucleo/tokens-get' ), [] );
			const [ saved, setSaved ] = useState( null );
			const [ draft, setDraft ] = useState( null );
			const [ rev, setRev ] = useState( 0 );
			const [ tab, setTab ] = useState( () => root.nucleoUI.tabFromSearch( root.location.search, TABS, 'colors' ) );
			const [ saving, setSaving ] = useState( false );
			const [ notice, setNotice ] = useState( null );
			const [ importedFonts, setImportedFonts ] = useState( [] );

			useEffect( () => {
				if ( state.data && state.data.tokens ) {
					setSaved( clone( state.data.tokens ) );
					setDraft( clone( state.data.tokens ) );
					setRev( ( r ) => r + 1 );
				}
			}, [ state.data ] );

			const dirty = draft !== null && Object.keys( changedTokens( saved, draft ) ).length > 0;
			const hasErrors = draft !== null && (
				Object.values( draft.color || {} ).some( ( v ) => ! isHex( v ) ) ||
				Object.values( draft.text || {} ).some( ( pair ) => rangeError( pair ) !== null ) );

			// Conferma del browser se ci sono modifiche non salvate.
			useEffect( () => {
				if ( ! dirty ) {
					return undefined;
				}
				const warn = ( e ) => {
					e.preventDefault();
					e.returnValue = '';
				};
				root.addEventListener( 'beforeunload', warn );
				return () => root.removeEventListener( 'beforeunload', warn );
			}, [ dirty ] );

			function update( group, key, value ) {
				setDraft( ( d ) => ( { ...d, [ group ]: { ...d[ group ], [ key ]: value } } ) );
			}

			async function save() {
				setSaving( true );
				setNotice( null );
				const res = await root.nucleoUI.call( 'nucleo/tokens-set', { tokens: changedTokens( saved, draft ) } );
				setSaving( false );
				if ( res && res.ok === false ) {
					setNotice( { tone: 'err', text: ( res.error && res.error.message ) || __( 'Save failed.', 'nucleo' ) } );
					return;
				}
				setSaved( clone( draft ) );
				setNotice( { tone: 'ok', text: __( 'Saved. Documents are rebuilding in the background.', 'nucleo' ) } );
			}

			function discard() {
				setDraft( clone( saved ) );
				setRev( ( r ) => r + 1 );
				setNotice( null );
			}

			const tabItems = [
				{ id: 'colors', label: __( 'Colors', 'nucleo' ) },
				{ id: 'typography', label: __( 'Typography', 'nucleo' ) },
				{ id: 'spacing', label: __( 'Spaces', 'nucleo' ) },
				{ id: 'shapes', label: __( 'Shapes', 'nucleo' ) },
				{ id: 'containers', label: __( 'Containers', 'nucleo' ) },
			];

			function tabBody() {
				switch ( tab ) {
					case 'typography':
						return h( TypographyTab, {
							draft,
							update,
							rev,
							importedFonts,
							onImported: ( f ) => setImportedFonts( ( list ) => ( list.includes( f ) ? list : [ ...list, f ] ) ),
						} );
					case 'spacing':
						return h( SpacingTab, { draft } );
					case 'shapes':
						return h( ShapesTab, { draft } );
					case 'containers':
						return h( ContainersTab, { draft } );
					default:
						return h( ColorsTab, { draft, update } );
				}
			}

			let body;
			if ( state.error ) {
				body = h( ErrorBox, { message: state.error.message || __( 'Could not load the design tokens.', 'nucleo' ), onRetry: reload } );
			} else if ( draft !== null ) {
				body = tabBody();
			} else if ( state.data && ! state.data.tokens ) {
				body = h( Empty, { text: __( 'No design tokens found.', 'nucleo' ) } );
			} else {
				body = h( Skeleton, { lines: 4 } );
			}

			return h( Page, {
				title: __( 'Design system', 'nucleo' ),
				subtitle: __( 'Colors, typography, spacing and shapes of the site. Every block uses these tokens; the theme and Gutenberg receive them through theme.json.', 'nucleo' ),
				actions: [
					h( 'button', { key: 'discard', type: 'button', className: 'nc-btn nc-btn--tertiary', disabled: ! dirty || saving, onClick: discard }, __( 'Revert changes', 'nucleo' ) ),
					h( 'button', { key: 'save', type: 'button', className: 'nc-btn nc-btn--primary', disabled: ! dirty || hasErrors || saving, onClick: save }, saving ? __( 'Saving…', 'nucleo' ) : __( 'Save', 'nucleo' ) ),
				],
				notice: notice && h( Notice, { tone: notice.tone, onClose: () => setNotice( null ) }, notice.text ),
			},
			dirty && h( Notice, { tone: 'info', title: __( 'Unsaved changes.', 'nucleo' ) }, __( 'Saving rebuilds all documents in the background.', 'nucleo' ) ),
			h( Tabs, { tabs: tabItems, active: tab, onChange: setTab } ),
			body );
		}

		root.nucleoPages = root.nucleoPages || {};
		root.nucleoPages[ 'nucleo-design' ] = Component;
	}

	if ( typeof module === 'object' && module.exports ) {
		module.exports = api;
	}
} )();

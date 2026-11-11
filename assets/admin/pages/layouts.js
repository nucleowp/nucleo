/**
 * Pagina Layout (slug nucleo-layouts): schede per area, card, drawer Condizioni, strumento "Quale layout si applica?".
 * Nei test (node --test) si esportano le funzioni pure; nel browser la pagina è window.nucleoPages['nucleo-layouts'].
 */
( function () {
	'use strict';

	const root = typeof window !== 'undefined' ? window : {};
	const { __ } = ( root.wp && root.wp.i18n ) || { __: ( text ) => text };
	const AREAS = [
		'header', 'footer', 'single', 'archive',
	];

	/** I 14 tipi di condizione. value: 'none' nessun valore, 'post' id, 'term' tassonomia + id, 'user' id, 'type' slug, 'lang' codice lingua. */
	const CONDITION_TYPES = [
		{ type: 'entire_site', label: () => __( 'Entire site', 'nucleo' ), value: 'none' },
		{ type: 'front', label: () => __( 'Front page', 'nucleo' ), value: 'none' },
		{ type: 'blog', label: () => __( 'Blog page', 'nucleo' ), value: 'none' },
		{ type: 'search', label: () => __( 'Search results', 'nucleo' ), value: 'none' },
		{ type: '404', label: () => __( 'Not found page', 'nucleo' ), value: 'none' },
		{ type: 'post', label: () => __( 'Specific content', 'nucleo' ), value: 'post' },
		{ type: 'parent', label: () => __( 'Children of a page', 'nucleo' ), value: 'post' },
		{ type: 'post_type', label: () => __( 'All content of a type', 'nucleo' ), value: 'type' },
		{ type: 'term', label: () => __( 'Content with a term', 'nucleo' ), value: 'term' },
		{ type: 'author', label: () => __( 'Content by an author', 'nucleo' ), value: 'user' },
		{ type: 'term_archive', label: () => __( 'Archive of a term', 'nucleo' ), value: 'term' },
		{ type: 'archive', label: () => __( 'Archive of a type', 'nucleo' ), value: 'type' },
		{ type: 'author_archive', label: () => __( 'Archive of an author', 'nucleo' ), value: 'user' },
	];

	/** Frase di un singolo elemento di include o exclude. */
	function describe( c ) {
		const children = c.children ? ` ${ __( '(with children)', 'nucleo' ) }` : '';
		switch ( c.type ) {
			case 'entire_site':
				return __( 'Entire site', 'nucleo' );
			case 'front':
				return __( 'Front page', 'nucleo' );
			case 'blog':
				return __( 'Blog page', 'nucleo' );
			case 'search':
				return __( 'Search results', 'nucleo' );
			case '404':
				return __( 'Not found page', 'nucleo' );
			case 'post':
				return `${ __( 'Specific content', 'nucleo' ) } #${ c.value }`;
			case 'parent':
				return `${ __( 'Children of page', 'nucleo' ) } #${ c.value }`;
			case 'post_type':
				return `${ __( 'All content of a type', 'nucleo' ) }: ${ c.value }`;
			case 'term':
				return `${ __( 'Content with a term', 'nucleo' ) }: ${ c.taxonomy } #${ c.value }${ children }`;
			case 'author':
				return `${ __( 'Content by author', 'nucleo' ) } #${ c.value }`;
			case 'term_archive':
				return `${ __( 'Archive of a term', 'nucleo' ) }: ${ c.taxonomy } #${ c.value }${ children }`;
			case 'archive':
				return `${ __( 'Archive of a type', 'nucleo' ) }: ${ c.value }`;
			case 'author_archive':
				return `${ __( 'Archive of author', 'nucleo' ) } #${ c.value }`;
			case 'language':
				return `${ __( 'Only in language', 'nucleo' ) }: ${ c.value }`;
			default:
				return String( c.type );
		}
	}

	/** Riassunto leggibile delle condizioni: "Entire site, except: Specific content #12". Senza include: "Nowhere". */
	function conditionSummary( conditions ) {
		const include = conditions && Array.isArray( conditions.include ) ? conditions.include : [];
		const exclude = conditions && Array.isArray( conditions.exclude ) ? conditions.exclude : [];
		if ( include.length === 0 ) {
			return __( 'Nowhere', 'nucleo' );
		}
		const text = include.map( ( c ) => describe( c ) ).join( ', ' );
		return exclude.length > 0 ? `${ text }, ${ __( 'except', 'nucleo' ) }: ${ exclude.map( ( c ) => describe( c ) ).join( ', ' ) }` : text;
	}

	/** Conteggio dei layout per area; le aree sconosciute non contano. */
	function countByArea( layouts ) {
		const counts = Object.fromEntries( AREAS.map( ( a ) => [ a, 0 ] ) );
		for ( const layout of layouts || [] ) {
			if ( layout && Object.prototype.hasOwnProperty.call( counts, layout.area ) ) {
				counts[ layout.area ] += 1;
			}
		}
		return counts;
	}

	/** Righe della tabella "Quale layout si applica?", una per area presente nella risposta di layout-explain. */
	function explainRows( result ) {
		return Object.keys( result || {} ).map( ( area ) => {
			const r = result[ area ];
			if ( ! r ) {
				return { area, layout: null, matched: '', excluded: false };
			}
			return {
				area,
				layout: r.layout ?? null,
				matched: r.excluded ? ( r.by || '' ) : ( r.matched || '' ),
				excluded: !! r.excluded,
			};
		} );
	}

	/** Motivo di layout-explain in forma leggibile: 'site' diventa Entire site, 'post:4' diventa Specific content #4. */
	function readableMatch( m ) {
		if ( m === 'site' ) {
			return __( 'Entire site', 'nucleo' );
		}
		const post = /^post:(\d+)$/.exec( String( m ) );
		return post ? `${ __( 'Specific content', 'nucleo' ) } #${ post[ 1 ] }` : String( m );
	}

	/** Id dei layout di un conflitto. Forma di conflicts[] non documentata: si accetta layouts[] di id o di oggetti con id. */
	function conflictLayoutIds( conflict ) {
		const list = conflict && Array.isArray( conflict.layouts ) ? conflict.layouts : [];
		return list.map( ( x ) => ( x && typeof x === 'object' ? x.id : x ) );
	}

	/** Tipo di valore richiesto da un tipo di condizione. */
	function kindOf( type ) {
		const found = CONDITION_TYPES.find( ( t ) => t.type === type );
		return found ? found.value : 'none';
	}

	/** Riga completa: ha il valore richiesto dal suo tipo (e la tassonomia per i termini). */
	function isComplete( row ) {
		if ( kindOf( row.type ) === 'none' ) {
			return true;
		}
		if ( row.value === undefined || row.value === null || row.value === '' ) {
			return false;
		}
		if ( row.type === 'term' || row.type === 'term_archive' ) {
			return !! row.taxonomy;
		}
		return true;
	}

	/** Riga dell'editor → condizione da salvare (senza campi di sola interfaccia). */
	function toCondition( row ) {
		const out = { type: row.type };
		if ( kindOf( row.type ) !== 'none' ) {
			out.value = row.value;
		}
		if ( row.type === 'term' || row.type === 'term_archive' ) {
			out.taxonomy = row.taxonomy;
			out.children = !! row.children;
		}
		return out;
	}

	/** Opzione con accesso limitato (explain o locked): la condizione che ne dipende non si sceglie. */
	function isLimited( access ) {
		return !! access && ( access.access === 'explain' || access.access === 'locked' );
	}

	const api = { conditionSummary, CONDITION_TYPES, countByArea, explainRows, isLimited };


	/* ---------- Componente (solo con window.wp.element) ---------- */

	const el = root.wp && root.wp.element;
	if ( el ) {
		const { createElement: h, useState } = el;
		const { __ } = ( root.wp && root.wp.i18n ) || { __: ( text ) => text };

		const STATUS = {
			publish: [ 'ok', __( 'Published', 'nucleo' ) ],
			draft: [ 'warn', __( 'Draft', 'nucleo' ) ],
		};

		/** Nomi delle aree nelle schede e nella tabella (tradotti). */
		const AREA_NAMES = {
			header: __( 'Header', 'nucleo' ),
			footer: __( 'Footer', 'nucleo' ),
			single: __( 'Single', 'nucleo' ),
			archive: __( 'Archive', 'nucleo' ),
			search: __( 'Search', 'nucleo' ),
			notfound: __( 'Not found', 'nucleo' ),
			'before-content': __( 'Before content', 'nucleo' ),
			'after-content': __( 'After content', 'nucleo' ),
		};

		/** Font monospazio del board per i valori numerici. */
		const MONO = { fontFamily: 'ui-monospace, SFMono-Regular, Menlo, Consolas, monospace' };

		/** Testo di un risultato di ricerca (post, termine, utente). */
		function textOf( item ) {
			if ( typeof item.title === 'string' ) {
				return item.title;
			}
			if ( item.name ) {
				return item.name;
			}
			if ( item.title && typeof item.title.rendered === 'string' ) {
				return item.title.rendered;
			}
			return String( item.id );
		}

		/** Valore di una condizione: ricerca per post, termini e utenti; slug per i tipi; menu per le lingue. */
		function ValueField( { row, onChange } ) {
			const kind = kindOf( row.type );
			const [ query, setQuery ] = useState( '' );
			const [ hits, setHits ] = useState( [] );
			const [ failed, setFailed ] = useState( false );

			if ( kind === 'none' ) {
				return h( 'span', { className: 'nc-muted' }, __( 'No value', 'nucleo' ) );
			}
			if ( kind === 'lang' ) {
				const langs = ( root.nucleoAdmin && root.nucleoAdmin.i18n && root.nucleoAdmin.i18n.languages ) || [];
				return h( 'select', { className: 'nc-select', 'aria-label': __( 'Language', 'nucleo' ), value: row.value || '', onChange: ( e ) => onChange( { value: e.target.value } ) },
					h( 'option', { value: '' }, __( 'Choose a language', 'nucleo' ) ),
					langs.map( ( code ) => h( 'option', { key: code, value: code }, code ) ) );
			}
			if ( kind === 'type' ) {
				return h( 'input', {
					className: 'nc-input',
					type: 'text',
					'aria-label': row.type === 'archive' ? __( 'Type or taxonomy slug', 'nucleo' ) : __( 'Content type slug', 'nucleo' ),
					value: row.value || '',
					onChange: ( e ) => onChange( { value: e.target.value.trim() } ),
				} );
			}

			const isTerm = kind === 'term';
			const ready = ! isTerm || !! row.taxonomy;

			const search = ( text ) => {
				setQuery( text );
				const q = encodeURIComponent( text.trim() );
				if ( q === '' || ! ready ) {
					setHits( [] );
					return;
				}
				let path;
				if ( kind === 'user' ) {
					path = `/wp/v2/users?search=${ q }&per_page=8`;
				} else if ( kind === 'post' ) {
					path = `/wp/v2/search?search=${ q }&per_page=8`;
				} else {
					path = `/wp/v2/${ encodeURIComponent( row.taxonomy ) }?search=${ q }&per_page=8`;
				}
				root.wp.apiFetch( { path } )
					.then( ( list ) => {
						setFailed( false );
						setHits( Array.isArray( list ) ? list : [] );
					} )
					.catch( () => setFailed( true ) );
			};

			const taxonomy = isTerm ? h( 'input', {
				className: 'nc-input',
				type: 'text',
				'aria-label': __( 'Taxonomy', 'nucleo' ),
				placeholder: 'category',
				value: row.taxonomy || '',
				onChange: ( e ) => onChange( { taxonomy: e.target.value.trim(), value: undefined, text: undefined } ),
			} ) : null;
			const children = isTerm ? h( 'label', { className: 'nc-check' },
				h( 'input', { type: 'checkbox', checked: !! row.children, onChange: ( e ) => onChange( { children: e.target.checked } ) } ),
				__( 'Include children', 'nucleo' ) ) : null;
			const searchInput = ready ? h( 'input', {
				className: 'nc-input',
				type: 'search',
				'aria-label': __( 'Search', 'nucleo' ),
				placeholder: __( 'Search', 'nucleo' ),
				value: query,
				onChange: ( e ) => search( e.target.value ),
			} ) : h( 'span', { className: 'nc-muted' }, __( 'Enter a taxonomy first.', 'nucleo' ) );
			const results = hits.length > 0 ? h( 'div', { className: 'nc-menu' },
				hits.map( ( hit ) => h( 'button', {
					key: hit.id,
					type: 'button',
					className: 'nc-menu__item',
					onClick: () => {
						onChange( { value: hit.id, text: textOf( hit ) } );
						setHits( [] );
						setQuery( '' );
					},
				}, textOf( hit ) ) ) ) : null;
			const picked = row.value !== undefined && row.value !== null && row.value !== '';
			const current = picked ? h( 'span', { className: 'nc-muted' }, `${ __( 'Selected', 'nucleo' ) }: ${ row.text || `#${ row.value }` }` ) : null;
			const note = failed ? h( 'span', { className: 'nc-muted' }, __( 'Search failed. Try again.', 'nucleo' ) ) : null;
			return h( 'div', { className: 'nc-stack' }, taxonomy, children, searchInput, results, current, note );
		}

		/** Una riga: selettore del tipo, valore, rimozione. Cambiare tipo azzera il valore. */
		function ConditionRow( { row, onChange, onRemove, langLimited } ) {
			return h( 'div', { className: 'nc-row' },
				h( 'select', {
					className: 'nc-select',
					'aria-label': __( 'Condition type', 'nucleo' ),
					value: row.type,
					onChange: ( e ) => onChange( { type: e.target.value, value: undefined, taxonomy: undefined, children: undefined, text: undefined } ),
				}, CONDITION_TYPES.map( ( t ) => h( 'option', { key: t.type, value: t.type, disabled: t.type === 'language' && !! langLimited }, t.label() ) ) ),
				h( ValueField, { row, onChange } ),
				h( 'button', { type: 'button', className: 'nc-btn nc-btn--icon nc-btn--tertiary', 'aria-label': __( 'Remove condition', 'nucleo' ), onClick: onRemove }, '✕' ) );
		}

		/** Elenco "Mostra su" o "Nascondi su". */
		function RuleList( { title, rows, onChange, langLimited } ) {
			const update = ( i, patch ) => onChange( rows.map( ( r, j ) => ( j === i ? { ...r, ...patch } : r ) ) );
			const remove = ( i ) => onChange( rows.filter( ( _, j ) => j !== i ) );
			return h( 'div', { className: 'nc-stack' },
				h( 'span', { className: 'nc-label' }, title ),
				rows.map( ( row, i ) => h( ConditionRow, {
					key: i,
					langLimited,
					row,
					onChange: ( patch ) => update( i, patch ),
					onRemove: () => remove( i ),
				} ) ),
				h( 'div', null, h( 'button', {
					type: 'button',
					className: 'nc-btn nc-btn--tertiary nc-btn--small',
					onClick: () => onChange( [ ...rows, { type: 'entire_site' } ] ),
				}, __( '+ Add condition', 'nucleo' ) ) ) );
		}


		/** Drawer "Condizioni": salva con nucleo/layout-conditions-set. */
		function ConditionsDrawer( { layout, onClose, onSaved } ) {
			const UI = root.nucleoUI;
			const cond = layout.conditions || {};
			const [ include, setInclude ] = useState( () => ( cond.include || [] ).map( ( c ) => ( { ...c } ) ) );
			const [ exclude, setExclude ] = useState( () => ( cond.exclude || [] ).map( ( c ) => ( { ...c } ) ) );
			const [ priority, setPriority ] = useState( String( cond.priority ?? 0 ) );
			const [ saving, setSaving ] = useState( false );
			const [ error, setError ] = useState( null );

			const save = () => {
				if ( ! [ ...include, ...exclude ].every( isComplete ) ) {
					setError( { message: __( 'Complete every condition or remove it.', 'nucleo' ) } );
					return;
				}
				const n = Number( priority );
				if ( priority.trim() === '' || ! Number.isInteger( n ) ) {
					setError( { message: __( 'Priority must be a whole number.', 'nucleo' ) } );
					return;
				}
				setSaving( true );
				setError( null );
				const input = {
					postId: layout.id,
					conditions: { include: include.map( toCondition ), exclude: exclude.map( toCondition ), priority: n },
				};
				UI.call( 'nucleo/layout-conditions-set', input ).then( ( res ) => {
					setSaving( false );
					if ( res && res.ok === false ) {
						setError( { code: res.error && res.error.code, message: res.error && res.error.message } );
						return;
					}
					onSaved();
				} );
			};

			const summary = conditionSummary( { include: include.filter( isComplete ), exclude: exclude.filter( isComplete ) } );
				const language = UI.access( 'nucleo-layouts', 'option', 'language' );
				const langLimited = isLimited( language );

			let errorNotice = null;
			if ( error && error.code === 'conflict' ) {
				errorNotice = h( UI.Notice, {
					tone: 'warn',
					title: __( 'This layout was changed elsewhere.', 'nucleo' ),
					actions: h( 'button', { type: 'button', className: 'nc-btn nc-btn--secondary nc-btn--small', onClick: () => root.location.reload() }, __( 'Reload', 'nucleo' ) ),
				}, __( 'Reload to see the latest version. Your changes were not saved.', 'nucleo' ) );
			} else if ( error ) {
				errorNotice = h( UI.Notice, { tone: 'err' }, error.message || __( 'The conditions could not be saved.', 'nucleo' ) );
			}

			return h( UI.Drawer, {
				title: `${ __( 'Conditions', 'nucleo' ) } · ${ layout.title }`,
				onClose,
				foot: h( 'div', { className: 'nc-row' },
					h( 'button', { type: 'button', className: 'nc-btn nc-btn--tertiary', onClick: onClose }, __( 'Cancel', 'nucleo' ) ),
					h( 'button', {
						type: 'button',
						className: 'nc-btn nc-btn--primary',
						disabled: saving,
						'aria-busy': String( saving ),
						onClick: save,
					}, __( 'Save conditions', 'nucleo' ) ) ),
			},
			h( 'div', { className: 'nc-stack' },
				errorNotice,
				h( UI.Notice, { tone: 'info', title: __( 'In short:', 'nucleo' ) }, summary ),
				h( RuleList, { title: __( 'Show on', 'nucleo' ), rows: include, onChange: setInclude, langLimited } ),
				h( RuleList, { title: __( 'Hide on', 'nucleo' ), rows: exclude, onChange: setExclude, langLimited } ),
				h( 'div', { className: 'nc-field' },
					h( 'label', { className: 'nc-field__label', htmlFor: 'nucleo-layout-priority' }, __( 'Priority', 'nucleo' ) ),
					h( 'input', {
						id: 'nucleo-layout-priority',
						className: 'nc-input',
						type: 'number',
						step: 1,
						value: priority,
						onChange: ( e ) => setPriority( e.target.value ),
					} ),
					h( 'span', { className: 'nc-field__help' }, __( 'When conditions are equal, the higher priority wins.', 'nucleo' ) ) ),
			) );
		}

		/** Card di un layout: titolo e stato in testata; riassunto, priorità, Modifica e Condizioni nel corpo. */
		function LayoutCard( { layout, conflicted, onConditions } ) {
			const UI = root.nucleoUI;
			const admin = root.nucleoAdmin || {};
			const status = STATUS[ layout.status ] || [ 'neutral', String( layout.status || '' ) ];
			return h( UI.Card, {
				title: layout.title,
				tone: conflicted ? 'warn' : undefined,
				actions: h( UI.Pill, { tone: status[ 0 ] }, status[ 1 ] ),
			},
			h( 'div', { className: 'nc-stack' },
				h( 'p', { className: 'nc-muted' }, conditionSummary( layout.conditions ) ),
				h( 'div', { className: 'nc-row' },
					h( 'span', { className: 'nc-muted' }, `${ __( 'Priority', 'nucleo' ) } `, h( 'span', { style: MONO }, String( ( layout.conditions && layout.conditions.priority ) ?? 0 ) ) ),
					conflicted ? h( UI.Pill, { tone: 'warn' }, __( 'Conflict', 'nucleo' ) ) : null ),
				h( 'div', { className: 'nc-row' },
					h( 'a', { className: 'nc-btn nc-btn--primary nc-btn--small', href: `${ admin.urls.editor }${ layout.id }` }, __( 'Edit', 'nucleo' ) ),
					h( 'button', { type: 'button', className: 'nc-btn nc-btn--secondary nc-btn--small', onClick: onConditions }, __( 'Conditions', 'nucleo' ) ) ) ) );
		}

		/** Pagina Layout. */
		function LayoutsPage() {
			const UI = root.nucleoUI;
			const admin = root.nucleoAdmin || {};
			const [ area, setArea ] = useState( () => UI.tabFromSearch( root.location.search, AREAS, 'header' ) );
			const [ list, reload ] = UI.useLoad( () => UI.call( 'nucleo/layout-list', {} ), [] );
			const [ notice, setNotice ] = useState( null );
			const [ editing, setEditing ] = useState( null );
			const [ creating, setCreating ] = useState( false );
			const [ url, setUrl ] = useState( '' );
			const [ probe, setProbe ] = useState( { busy: false, result: null, error: null } );

			const data = list.data || {};
			const layouts = data.layouts || [];
			const conflicts = data.conflicts || [];
			const counts = countByArea( layouts );
			const titles = Object.fromEntries( layouts.map( ( l ) => [ l.id, l.title ] ) );
			const conflictIds = new Set( conflicts.flatMap( conflictLayoutIds ) );
			const shown = layouts.filter( ( l ) => l.area === area );

			const createLayout = () => {
				setCreating( true );
				setNotice( null );
				UI.call( 'nucleo/doc-create', {
					title: __( 'New layout', 'nucleo' ),
					postType: 'nucleo_layout',
					document: root.nucleoShared.blankDocument( 'layout', area ),
				} ).then( ( res ) => {
					if ( res && res.ok === false ) {
						setCreating( false );
						setNotice( { tone: 'err', text: ( res.error && res.error.message ) || __( 'The layout could not be created.', 'nucleo' ) } );
						return;
					}
					root.location.assign( `${ admin.urls.editor }${ res.postId }` );
				} );
			};

			const verify = ( e ) => {
				e.preventDefault();
				const address = url.trim();
				if ( address === '' ) {
					return;
				}
				setProbe( { busy: true, result: null, error: null } );
				UI.call( 'nucleo/layout-explain', { url: address } ).then( ( res ) => {
					if ( res && res.ok === false ) {
						setProbe( { busy: false, result: null, error: ( res.error && res.error.message ) || __( 'The address could not be checked.', 'nucleo' ) } );
						return;
					}
					setProbe( { busy: false, result: res, error: null } );
				} );
			};

			const firstConflictId = conflicts.length > 0 ? conflictLayoutIds( conflicts[ 0 ] )[ 0 ] : undefined;
			const firstConflictLayout = layouts.find( ( l ) => l.id === firstConflictId );

			let banner = null;
			if ( notice || conflicts.length > 0 ) {
				banner = h( 'div', null,
					notice && h( UI.Notice, { tone: notice.tone, onClose: () => setNotice( null ) }, notice.text ),
					conflicts.length > 0 && h( UI.Notice, {
						tone: 'warn',
						title: __( 'Two layouts share the same area, condition and priority.', 'nucleo' ),
						actions: firstConflictLayout ? h( 'button', {
							type: 'button',
							className: 'nc-btn nc-btn--secondary nc-btn--small',
							onClick: () => setEditing( firstConflictLayout ),
						}, __( 'Change priority', 'nucleo' ) ) : null,
					}, `${ conflicts.map( ( c ) => conflictLayoutIds( c ).map( ( id ) => titles[ id ] || `#${ id }` ).join( ' / ' ) ).join( '; ' ) }. ${ __( 'Change the priority of one of them.', 'nucleo' ) }` ) );
			}

			const newButton = h( 'button', {
				type: 'button',
				className: 'nc-btn nc-btn--primary',
				disabled: creating,
				'aria-busy': String( creating ),
				onClick: createLayout,
			}, __( 'New layout', 'nucleo' ) );

			let body;
			if ( list.loading ) {
				body = h( UI.Skeleton, { lines: 3 } );
			} else if ( list.error ) {
				body = h( UI.ErrorBox, { message: list.error.message || __( 'The layouts could not be loaded.', 'nucleo' ), onRetry: reload } );
			} else if ( shown.length === 0 ) {
				body = h( UI.Empty, { text: __( 'No layouts in this area yet.', 'nucleo' ), action: newButton } );
			} else {
				body = h( 'div', { className: 'nc-grid' },
					shown.map( ( l ) => h( 'div', { key: l.id, className: 'nc-col-4' },
						h( LayoutCard, {
							layout: l,
							conflicted: conflictIds.has( l.id ),
							onConditions: () => setEditing( l ),
						} ) ) ) );
			}

			let probeBody = null;
			if ( probe.error ) {
				probeBody = h( UI.Notice, { tone: 'warn' }, probe.error );
			} else if ( probe.result ) {
				const rows = explainRows( probe.result ).map( ( r ) => ( {
					key: r.area,
					area: AREA_NAMES[ r.area ] || r.area,
					layout: r.layout,
					excluded: r.excluded,
					reason: r.excluded ? `${ __( 'Excluded by', 'nucleo' ) } ${ readableMatch( r.matched ) }` : ( r.matched ? `${ __( 'Condition', 'nucleo' ) }: ${ readableMatch( r.matched ) }` : '' ),
				} ) );
				probeBody = h( UI.Table, {
					columns: [
						{ key: 'area', label: __( 'Area', 'nucleo' ) },
						{
							key: 'layout',
							label: __( 'Chosen layout', 'nucleo' ),
							render: ( v, row ) => {
								if ( v === null || v === undefined ) {
									return h( 'span', { className: 'nc-muted' }, __( 'None', 'nucleo' ) );
								}
								return h( 'span', null,
									row.excluded ? h( UI.Pill, { tone: 'err' }, __( 'Excluded', 'nucleo' ) ) : null,
									' ',
									titles[ v ] || `#${ v }` );
							},
						},
						{ key: 'reason', label: __( 'Reason', 'nucleo' ) },
					],
					rows,
					empty: __( 'No area matched.', 'nucleo' ),
					rowKey: ( r ) => r.key,
				} );
			}

			return h( UI.Page, {
				title: __( 'Layouts', 'nucleo' ),
				subtitle: __( 'Header, footer, single post templates and archives, and where they apply.', 'nucleo' ),
				actions: newButton,
				notice: banner,
			},
			h( UI.Tabs, {
				tabs: AREAS.map( ( a ) => ( { id: a, label: AREA_NAMES[ a ], count: counts[ a ] } ) ),
				active: area,
				onChange: setArea,
			} ),
			body,
			h( UI.Card, {
				title: __( 'Which layout applies?', 'nucleo' ),
				actions: h( 'span', { className: 'nc-muted' }, __( 'Verify an address of the site.', 'nucleo' ) ),
			},
			h( 'form', { className: 'nc-stack', onSubmit: verify },
				h( 'div', { className: 'nc-row', style: { flexWrap: 'nowrap', alignItems: 'center' } },
					h( 'input', {
						id: 'nucleo-probe-url',
						className: 'nc-input',
						type: 'url',
						placeholder: 'https://',
						'aria-label': __( 'Site address', 'nucleo' ),
						value: url,
						style: { flex: 1, minWidth: 0 },
						onChange: ( e ) => setUrl( e.target.value ),
					} ),
					h( 'button', {
						type: 'submit',
						className: 'nc-btn nc-btn--primary',
						style: { flex: 'none', whiteSpace: 'nowrap' },
						disabled: probe.busy,
						'aria-busy': String( probe.busy ),
					}, __( 'Verify', 'nucleo' ) ) ),
				probeBody ) ),
			(
				editing ? h( ConditionsDrawer, {
					layout: editing,
					onClose: () => setEditing( null ),
					onSaved: () => {
						setEditing( null );
						setNotice( { tone: 'ok', text: __( 'Conditions saved.', 'nucleo' ) } );
						reload();
					},
				} ) : null
			) );
		}

		/* Esiste solo per l'estrazione delle stringhe (wp i18n make-pot legge solo __( 'X', 'nucleo' ) letterali): non viene mai chiamata. */
		function i18nStrings() {
			return [
				__( 'Entire site', 'nucleo' ),
				__( 'Front page', 'nucleo' ),
				__( 'Blog page', 'nucleo' ),
				__( 'Search results', 'nucleo' ),
				__( 'Not found page', 'nucleo' ),
				__( 'Specific content', 'nucleo' ),
				__( 'Children of page', 'nucleo' ),
				__( 'All content of a type', 'nucleo' ),
				__( 'Content with a term', 'nucleo' ),
				__( 'Content by author', 'nucleo' ),
				__( 'Archive of a term', 'nucleo' ),
				__( 'Archive of a type', 'nucleo' ),
				__( 'Archive of author', 'nucleo' ),
				__( 'Only in language', 'nucleo' ),
				__( 'Nowhere', 'nucleo' ),
				__( 'except', 'nucleo' ),
				__( '(with children)', 'nucleo' ),
			];
		}

		root.nucleoPages = root.nucleoPages || {};
		root.nucleoPages[ 'nucleo-layouts' ] = LayoutsPage;
	}

	if ( typeof module === 'object' && module.exports ) {
		module.exports = api;
	}
} )();

/**
 * Strumenti (4.13, slug nucleo-tools): esporta e importa il sito come zip, rigenera la cache, stato del sistema.
 * Nei test (node --test) si esportano le funzioni pure dello zip; nel browser la pagina è window.nucleoPages['nucleo-tools'].
 */
( function () {
	'use strict';

	const root = typeof window !== 'undefined' ? window : {};

	const SIG_LOCAL = 0x04034b50;
	const SIG_CENTRAL = 0x02014b50;
	const SIG_END = 0x06054b50;
	const FLAG_UTF8 = 0x0800;

	const CRC_TABLE = ( () => {
		const t = new Uint32Array( 256 );
		for ( let n = 0; n < 256; n++ ) {
			let c = n;
			for ( let k = 0; k < 8; k++ ) {
				c = c & 1 ? 0xedb88320 ^ ( c >>> 1 ) : c >>> 1;
			}
			t[ n ] = c;
		}
		return t;
	} )();

	/* ---------- Funzioni pure: zip senza compressione ---------- */

	/** CRC-32 (IEEE) di una sequenza di byte. */
	function crc32( bytes ) {
		let crc = 0xffffffff;
		for ( const b of bytes ) {
			crc = CRC_TABLE[ ( crc ^ b ) & 0xff ] ^ ( crc >>> 8 );
		}
		return ( crc ^ 0xffffffff ) >>> 0;
	}

	/** Data e ora nel formato MS-DOS usato dallo zip. */
	function dosStamp( d ) {
		return {
			time: ( d.getHours() << 11 ) | ( d.getMinutes() << 5 ) | ( d.getSeconds() >> 1 ),
			date: ( Math.max( 0, d.getFullYear() - 1980 ) << 9 ) | ( ( d.getMonth() + 1 ) << 5 ) | d.getDate(),
		};
	}

	/** Zip senza compressione (metodo 0), nomi e contenuti in UTF-8. `files` è percorso → testo. */
	function zipStore( files ) {
		const enc = new TextEncoder();
		const { time, date } = dosStamp( new Date() );
		const entries = Object.keys( files ).map( ( name ) => {
			const nameBytes = enc.encode( name );
			const data = enc.encode( files[ name ] );
			return { nameBytes, data, crc: crc32( data ), offset: 0 };
		} );

		let localSize = 0;
		let centralSize = 0;
		for ( const e of entries ) {
			e.offset = localSize;
			localSize += 30 + e.nameBytes.length + e.data.length;
			centralSize += 46 + e.nameBytes.length;
		}

		const out = new Uint8Array( localSize + centralSize + 22 );
		const view = new DataView( out.buffer );

		let p = 0;
		for ( const e of entries ) {
			view.setUint32( p, SIG_LOCAL, true );
			view.setUint16( p + 4, 20, true );
			view.setUint16( p + 6, FLAG_UTF8, true );
			view.setUint16( p + 8, 0, true );
			view.setUint16( p + 10, time, true );
			view.setUint16( p + 12, date, true );
			view.setUint32( p + 14, e.crc, true );
			view.setUint32( p + 18, e.data.length, true );
			view.setUint32( p + 22, e.data.length, true );
			view.setUint16( p + 26, e.nameBytes.length, true );
			view.setUint16( p + 28, 0, true );
			out.set( e.nameBytes, p + 30 );
			out.set( e.data, p + 30 + e.nameBytes.length );
			p += 30 + e.nameBytes.length + e.data.length;
		}

		const centralStart = p;
		for ( const e of entries ) {
			view.setUint32( p, SIG_CENTRAL, true );
			view.setUint16( p + 4, 20, true );
			view.setUint16( p + 6, 20, true );
			view.setUint16( p + 8, FLAG_UTF8, true );
			view.setUint16( p + 10, 0, true );
			view.setUint16( p + 12, time, true );
			view.setUint16( p + 14, date, true );
			view.setUint32( p + 16, e.crc, true );
			view.setUint32( p + 20, e.data.length, true );
			view.setUint32( p + 24, e.data.length, true );
			view.setUint16( p + 28, e.nameBytes.length, true );
			view.setUint16( p + 30, 0, true );
			view.setUint16( p + 32, 0, true );
			view.setUint16( p + 34, 0, true );
			view.setUint16( p + 36, 0, true );
			view.setUint32( p + 38, 0, true );
			view.setUint32( p + 42, e.offset, true );
			out.set( e.nameBytes, p + 46 );
			p += 46 + e.nameBytes.length;
		}

		view.setUint32( p, SIG_END, true );
		view.setUint16( p + 4, 0, true );
		view.setUint16( p + 6, 0, true );
		view.setUint16( p + 8, entries.length, true );
		view.setUint16( p + 10, entries.length, true );
		view.setUint32( p + 12, p - centralStart, true );
		view.setUint32( p + 16, centralStart, true );
		view.setUint16( p + 20, 0, true );
		return out;
	}

	/** Percorsi assoluti, con '..' o con '\' non si accettano. */
	function checkName( name ) {
		if ( name.startsWith( '/' ) || name.includes( '\\' ) || name.split( '/' ).includes( '..' ) ) {
			throw new Error( `zip: unsafe path ${ name }` );
		}
	}

	/** Legge uno zip senza compressione: mappa percorso → testo. Lancia Error se il contenuto non è valido. */
	function unzipStore( bytes ) {
		const len = bytes.length;
		const view = new DataView( bytes.buffer, bytes.byteOffset, len );
		const utf8 = new TextDecoder( 'utf-8', { fatal: true } );

		let end = -1;
		for ( let i = len - 22; i >= Math.max( 0, len - 22 - 0xffff ); i-- ) {
			if ( view.getUint32( i, true ) === SIG_END ) {
				end = i;
				break;
			}
		}
		if ( end < 0 ) {
			throw new Error( 'zip: end of central directory not found' );
		}

		const count = view.getUint16( end + 10, true );
		const cdSize = view.getUint32( end + 12, true );
		const cdStart = view.getUint32( end + 16, true );
		if ( cdStart + cdSize > end ) {
			throw new Error( 'zip: central directory out of bounds' );
		}

		const files = {};
		let p = cdStart;
		for ( let n = 0; n < count; n++ ) {
			if ( p + 46 > end || view.getUint32( p, true ) !== SIG_CENTRAL ) {
				throw new Error( 'zip: invalid central directory entry' );
			}
			const flags = view.getUint16( p + 8, true );
			const method = view.getUint16( p + 10, true );
			const crc = view.getUint32( p + 16, true );
			const size = view.getUint32( p + 24, true );
			const nameLen = view.getUint16( p + 28, true );
			const extraLen = view.getUint16( p + 30, true );
			const commentLen = view.getUint16( p + 32, true );
			const localOffset = view.getUint32( p + 42, true );
			const name = utf8.decode( bytes.subarray( p + 46, p + 46 + nameLen ) );
			p += 46 + nameLen + extraLen + commentLen;

			if ( flags & 1 ) {
				throw new Error( 'zip: encrypted entries are not supported' );
			}
			if ( method !== 0 ) {
				throw new Error( 'zip: compressed entries are not supported' );
			}
			if ( name.endsWith( '/' ) ) {
				continue;
			}
			checkName( name );

			if ( localOffset + 30 > len || view.getUint32( localOffset, true ) !== SIG_LOCAL ) {
				throw new Error( `zip: invalid local header for ${ name }` );
			}
			const start = localOffset + 30 + view.getUint16( localOffset + 26, true ) + view.getUint16( localOffset + 28, true );
			if ( start + size > len ) {
				throw new Error( `zip: data out of bounds for ${ name }` );
			}
			const data = bytes.subarray( start, start + size );
			if ( crc32( data ) !== crc ) {
				throw new Error( `zip: CRC mismatch for ${ name }` );
			}
			Object.defineProperty( files, name, { value: utf8.decode( data ), enumerable: true, writable: true, configurable: true } );
		}
		return files;
	}

	/* ---------- Funzioni pure: esito di site-import ---------- */

	/** Conteggio di created o updated di site-import: un intero, mai un elenco. */
	function countOf( value ) {
		return Number.isInteger( value ) && value > 0 ? value : 0;
	}

	/** Documenti creati o aggiornati da site-import (somma dei due conteggi). */
	function changedTotal( res ) {
		return countOf( res.created ) + countOf( res.updated );
	}

	/** Percorsi in conflitto nell'anteprima: quelli che si sovrascrivono con force. */
	function conflictFiles( res ) {
		return ( res.errors || [] ).filter( ( e ) => e.code === 'conflict' ).map( ( e ) => e.file );
	}

	const api = { crc32, zipStore, unzipStore, countOf, changedTotal, conflictFiles };

	/* ---------- Pagina (solo con window.wp.element e nucleoUI) ---------- */

	if ( root.wp && root.wp.element && root.nucleoUI ) {
		const { createElement: h, useState } = root.wp.element;
		const { Page, Card, Notice, Table, Confirm, Skeleton, Empty, ErrorBox, useLoad, call } = root.nucleoUI;
		const { __ } = ( root.wp && root.wp.i18n ) || { __: ( text ) => text };
		const fmt = ( s, ...args ) => root.wp.i18n.sprintf( s, ...args );

		const failed = ( res ) => ! res || res.ok === false;
		const errorText = ( res ) => ( res && res.error && res.error.message ) || __( 'The operation failed.', 'nucleo' );
		const errNote = ( res ) => ( { tone: 'err', text: errorText( res ) } );

		/** Nome dello zip esportato: nucleo-export-AAAA-MM-GG.zip (data locale). */
		function exportName( d ) {
			const two = ( n ) => String( n ).padStart( 2, '0' );
			return `nucleo-export-${ d.getFullYear() }-${ two( d.getMonth() + 1 ) }-${ two( d.getDate() ) }.zip`;
		}

		/** Scarica i byte come file zip. */
		function downloadZip( bytes, name ) {
			const url = root.URL.createObjectURL( new root.Blob( [ bytes ], { type: 'application/zip' } ) );
			const a = root.document.createElement( 'a' );
			a.href = url;
			a.download = name;
			root.document.body.appendChild( a );
			a.click();
			a.remove();
			setTimeout( () => root.URL.revokeObjectURL( url ), 1000 );
		}

		/** Testo semplice dello stato, per l'assistenza. */
		function statusReport( st ) {
			const lines = [
				`Nucleo: ${ st.nucleoVersion }`,
				`PHP: ${ st.php }`,
				`WordPress: ${ st.wordpress }`,
				`Store: ${ st.store }`,
				`Documents: ${ st.documentCount }`,
				'Conflicts:',
			];
			const conflicts = st.conflicts || [];
			if ( conflicts.length === 0 ) {
				lines.push( '- none' );
			}
			conflicts.forEach( ( c ) => lines.push( `- ${ c.plugin }: ${ c.issue } (${ c.fix })` ) );
			return lines.join( '\n' );
		}

		/** Riga chiave/valore della scheda Stato: etichetta attenuata a sinistra, valore a destra. */
		const statusRow = ( label, value, key ) => h( 'div', { key, className: 'nc-row', style: { justifyContent: 'space-between' } },
			h( 'span', { className: 'nc-muted' }, label ),
			h( 'span', null, value ) );

		const exportColumns = [
			{ key: 'file', label: __( 'File', 'nucleo' ), render: ( v ) => h( 'span', { className: 'nc-code' }, v ) },
			{ key: 'result', label: __( 'Result', 'nucleo' ) },
			{ key: 'detail', label: __( 'Detail', 'nucleo' ) },
		];

		function ToolsPage() {
			const [ busy, setBusy ] = useState( '' );
			const [ exportNote, setExportNote ] = useState( null );
			const [ cacheNote, setCacheNote ] = useState( null );
			const [ importNote, setImportNote ] = useState( null );
			const [ copyNote, setCopyNote ] = useState( null );
			const [ picked, setPicked ] = useState( null );
			const [ preview, setPreview ] = useState( null );
			const [ force, setForce ] = useState( false );
			const [ askForce, setAskForce ] = useState( false );
			const [ dragging, setDragging ] = useState( false );
			const [ status, reloadStatus ] = useLoad( () => call( 'nucleo/status', {} ), [] );

			const onExport = () => {
				setBusy( 'export' );
				setExportNote( null );
				call( 'nucleo/site-export', {} )
					.then( ( res ) => {
						if ( failed( res ) ) {
							setExportNote( errNote( res ) );
							return;
						}
						const files = {};
						Object.keys( res.files || {} ).forEach( ( name ) => {
							const value = res.files[ name ];
							files[ name ] = typeof value === 'string' ? value : JSON.stringify( value, null, 2 );
						} );
						downloadZip( zipStore( files ), exportName( new Date() ) );
					} )
					.catch( () => setExportNote( { tone: 'err', text: __( 'The export could not be created.', 'nucleo' ) } ) )
					.finally( () => setBusy( '' ) );
			};

			/** Legge i file scelti (zip o json) e ne mostra l'anteprima: usato dall'input e dal trascinamento. */
			const pickFiles = ( list ) => {
				setImportNote( null );
				setPreview( null );
				setPicked( null );
				setForce( false );
				if ( list.length === 0 ) {
					return;
				}
				const zip = list.find( ( f ) => /\.zip$/i.test( f.name ) );
				const jsons = list.filter( ( f ) => /\.json$/i.test( f.name ) );
				const valid = zip ? list.length === 1 : jsons.length === list.length;
				if ( ! valid ) {
					setImportNote( { tone: 'err', text: __( 'Choose one .zip file or one or more .json files.', 'nucleo' ) } );
					return;
				}
				setBusy( 'import' );
				const read = zip
					? zip.arrayBuffer().then( ( buf ) => unzipStore( new Uint8Array( buf ) ) )
					: Promise.all( jsons.map( ( f ) => f.text().then( ( text ) => [ f.name, text ] ) ) ).then( ( pairs ) => {
						const files = {};
						pairs.forEach( ( [ name, text ] ) => {
							files[ name ] = text;
						} );
						return files;
					} );
				read
					.then( ( files ) => {
						setPicked( files );
						return call( 'nucleo/site-import', { files, dryRun: true, force: false } ).then( ( res ) => {
							if ( failed( res ) ) {
								setImportNote( errNote( res ) );
							} else {
								setPreview( res );
							}
						} );
					} )
					.catch( () => setImportNote( { tone: 'err', text: __( 'The file could not be read.', 'nucleo' ) } ) )
					.finally( () => setBusy( '' ) );
			};

			const onPick = ( e ) => {
				const list = Array.from( e.target.files || [] );
				e.target.value = '';
				pickFiles( list );
			};

			const onDragOver = ( e ) => {
				e.preventDefault();
				setDragging( busy === '' );
			};

			const onDragLeave = () => setDragging( false );

			const onDrop = ( e ) => {
				e.preventDefault();
				setDragging( false );
				if ( busy === '' ) {
					pickFiles( Array.from( e.dataTransfer.files || [] ) );
				}
			};

			const onImport = () => {
				setBusy( 'import' );
				setImportNote( null );
				call( 'nucleo/site-import', { files: picked, dryRun: false, force } )
					.then( ( res ) => {
						if ( failed( res ) ) {
							setImportNote( errNote( res ) );
							return;
						}
						setImportNote( { tone: 'ok', text: fmt( __( 'Imported: %d created, %d updated, %d errors.', 'nucleo' ), countOf( res.created ), countOf( res.updated ), ( res.errors || [] ).length ) } );
						setPreview( res );
						setPicked( null );
						setForce( false );
					} )
					.catch( () => setImportNote( { tone: 'err', text: __( 'The import failed.', 'nucleo' ) } ) )
					.finally( () => setBusy( '' ) );
			};

			const onRebuild = () => {
				setBusy( 'cache' );
				setCacheNote( null );
				call( 'nucleo/cache-rebuild', {} )
					.then( ( res ) => {
						if ( failed( res ) ) {
							setCacheNote( errNote( res ) );
							return;
						}
						setCacheNote( { tone: 'ok', text: fmt( __( 'rebuilt %d, queued %d', 'nucleo' ), res.rebuilt, res.queued ) } );
					} )
					.catch( () => setCacheNote( { tone: 'err', text: __( 'The cache could not be rebuilt.', 'nucleo' ) } ) )
					.finally( () => setBusy( '' ) );
			};

			const onCopy = () => {
				Promise.resolve()
					.then( () => root.navigator.clipboard.writeText( statusReport( status.data ) ) )
					.then( () => setCopyNote( { tone: 'ok', text: __( 'Copied', 'nucleo' ) } ) )
					.catch( () => setCopyNote( { tone: 'err', text: __( 'The report could not be copied.', 'nucleo' ) } ) );
			};

			// created e updated sono conteggi (interi): si mostrano come numeri, mai come elenco.
			const previewRows = preview ? [
				{ file: '—', result: __( 'Created', 'nucleo' ), detail: String( countOf( preview.created ) ) },
				{ file: '—', result: __( 'Updated', 'nucleo' ), detail: String( countOf( preview.updated ) ) },
				...( preview.errors || [] ).map( ( e ) => ( { file: e.file, result: e.code, detail: e.message } ) ),
			] : [];
			const changedCount = preview ? changedTotal( preview ) : 0;
			const forceFiles = preview ? conflictFiles( preview ) : [];

			const exportCard = h( Card, { title: __( 'Export the site', 'nucleo' ) },
				h( 'div', { className: 'nc-stack' },
					h( 'p', { className: 'nc-muted' }, __( 'Download pages, layouts, sections and rules as a .zip file.', 'nucleo' ) ),
					h( 'div', { className: 'nc-row' },
						h( 'button', { type: 'button', className: 'nc-btn nc-btn--primary', disabled: busy !== '', 'aria-busy': String( busy === 'export' ), onClick: onExport }, __( 'Download zip', 'nucleo' ) ) ),
					exportNote && h( Notice, { tone: exportNote.tone, onClose: () => setExportNote( null ) }, exportNote.text ) ) );

			const cacheCard = h( Card, { title: __( 'Rebuild the cache', 'nucleo' ) },
				h( 'div', { className: 'nc-stack' },
					h( 'p', { className: 'nc-muted' }, __( 'Rebuild the styles. Without a selection everything is rebuilt, in the background queue.', 'nucleo' ) ),
					h( 'div', { className: 'nc-row' },
						h( 'button', { type: 'button', className: 'nc-btn nc-btn--primary', disabled: busy !== '', 'aria-busy': String( busy === 'cache' ), onClick: onRebuild }, __( 'Rebuild all', 'nucleo' ) ) ),
					h( 'div', { 'aria-live': 'polite' },
						cacheNote && h( Notice, { tone: cacheNote.tone, onClose: () => setCacheNote( null ), autoClose: true }, cacheNote.text ) ) ) );

			const importCard = h( Card, { title: __( 'Import the site', 'nucleo' ) },
				h( 'div', { className: 'nc-stack' },
					h( 'label', {
						className: dragging ? 'nc-dropzone nc-dropzone--over' : 'nc-dropzone',
						onDragOver,
						onDragLeave,
						onDrop,
					},
					h( 'span', null, __( 'Drop a .zip file or one or more .json files here', 'nucleo' ) ),
					h( 'span', { className: 'nc-btn nc-btn--secondary nc-btn--small' }, __( 'Choose file', 'nucleo' ) ),
					h( 'input', { className: 'nc-dropzone__input', type: 'file', accept: '.zip,.json,application/zip,application/json', multiple: true, disabled: busy !== '', onChange: onPick } ) ),
					importNote && h( Notice, { tone: importNote.tone, onClose: () => setImportNote( null ) }, importNote.text ),
					picked && preview && h( Notice, { tone: 'info' }, __( 'Preview: nothing has been changed yet.', 'nucleo' ) ),
					preview && h( Table, { columns: exportColumns, rows: previewRows, empty: __( 'No file to show.', 'nucleo' ), rowKey: ( r, i ) => i } ),
					picked && preview && h( 'div', { className: 'nc-row' },
						h( 'button', { type: 'button', className: 'nc-btn nc-btn--primary', disabled: busy !== '' || changedCount === 0, 'aria-busy': String( busy === 'import' ), onClick: onImport }, __( 'Import', 'nucleo' ) ),
						h( 'label', { className: 'nc-check' },
							h( 'input', { type: 'checkbox', checked: force, onChange: ( e ) => ( e.target.checked ? setAskForce( true ) : setForce( false ) ) } ),
							__( 'Overwrite changes made on the site', 'nucleo' ) ) ) ) );

			let statusBody;
			if ( status.loading ) {
				statusBody = h( Skeleton, { lines: 4 } );
			} else if ( status.error ) {
				statusBody = h( ErrorBox, { message: status.error.message || __( 'The status could not be loaded.', 'nucleo' ), onRetry: () => reloadStatus() } );
			} else if ( ! status.data ) {
				statusBody = h( Empty, { text: __( 'No status available.', 'nucleo' ) } );
			} else {
				const st = status.data;
				const conflicts = st.conflicts || [];
				statusBody = h( 'div', { className: 'nc-stack' },
					statusRow( __( 'Nucleo version', 'nucleo' ), st.nucleoVersion ),
					statusRow( 'PHP', st.php ),
					statusRow( 'WordPress', st.wordpress ),
					statusRow( __( 'Storage driver', 'nucleo' ), st.store ),
					statusRow( __( 'Documents', 'nucleo' ), st.documentCount ),
					h( 'p', { className: 'nc-label' }, __( 'Conflicts', 'nucleo' ) ),
					conflicts.length === 0
						? h( 'p', { className: 'nc-muted' }, __( 'No conflicts detected.', 'nucleo' ) )
						: conflicts.map( ( c, i ) => statusRow( c.plugin, `${ c.issue } (${ c.fix })`, i ) ) );
			}

			const statusCard = h( Card, {
				title: __( 'System status', 'nucleo' ),
				actions: h( 'button', { type: 'button', className: 'nc-btn nc-btn--secondary nc-btn--small', disabled: ! status.data, onClick: onCopy }, __( 'Copy report', 'nucleo' ) ),
			},
			statusBody,
			copyNote && h( Notice, { tone: copyNote.tone, autoClose: true, onClose: () => setCopyNote( null ) }, copyNote.text ) );

			const urls = ( root.nucleoAdmin && root.nucleoAdmin.urls ) || {};
			const links = ( root.nucleoAdmin && root.nucleoAdmin.links ) || {};
			const docsCard = h( Card, { title: __( 'Documentation for agents', 'nucleo' ) },
				h( 'div', { className: 'nc-stack' },
					h( 'p', { className: 'nc-muted' }, fmt( __( 'The command %s generates the guide for agents.', 'nucleo' ), 'wp nucleo docs' ) ),
					( links.agent || links.mcp ) && h( 'p', { className: 'nc-row' },
						links.agent && h( 'a', { href: links.agent, rel: 'noopener' }, __( 'Connect an AI agent', 'nucleo' ) ),
						links.mcp && h( 'a', { href: links.mcp, rel: 'noopener' }, __( 'MCP and OAuth', 'nucleo' ) ) ),
					urls.mcp && h( 'div', { className: 'nc-stack' },
						h( 'span', { className: 'nc-label' }, __( 'MCP address', 'nucleo' ) ),
						h( 'div', { className: 'nc-row' },
							h( 'code', { className: 'nc-code' }, urls.mcp ),
							h( 'button', { type: 'button', className: 'nc-btn nc-btn--secondary nc-btn--small', 'data-nc-copy': urls.mcp }, __( 'Copy', 'nucleo' ) ) ) ) ) );

			const col = ( card ) => h( 'div', { className: 'nc-col-6' }, card );

			return h( Page, { title: __( 'Tools', 'nucleo' ), subtitle: __( 'Export, import and maintenance of the site.', 'nucleo' ) },
				h( 'div', { className: 'nc-stack' },
					h( 'div', { className: 'nc-grid' }, col( exportCard ), col( cacheCard ) ),
					importCard,
					h( 'div', { className: 'nc-grid' }, col( statusCard ), col( docsCard ) ) ),
				askForce && h( Confirm, {
					title: __( 'Overwrite changes made on the site?', 'nucleo' ),
					message: forceFiles.length > 0
						? `${ __( 'These documents were changed on the site after the export and will be overwritten:', 'nucleo' ) } ${ forceFiles.join( ', ' ) }`
						: __( 'No document in the preview was changed on the site.', 'nucleo' ),
					confirmLabel: __( 'Overwrite', 'nucleo' ),
					onConfirm: () => {
						setForce( true );
						setAskForce( false );
					},
					onCancel: () => setAskForce( false ),
				} ) );
		}

		root.nucleoPages = root.nucleoPages || {};
		root.nucleoPages[ 'nucleo-tools' ] = ToolsPage;
	}

	if ( typeof module === 'object' && module.exports ) {
		module.exports = api;
	}
} )();

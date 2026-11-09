/**
 * Revisioni del documento nell'editor Nucleo: righe della lista e cassetto di ripristino.
 * revisionRows è pura e si testa in node. RevisionsDrawer usa wp.element, wp.components e wp.i18n
 * e viene letto solo nel browser (nessun build).
 * Nei test (node --test) si esporta con module.exports; nel browser è window.nucleoRevisions.
 */
( function () {
	'use strict';

	const msg = ( res, fallback ) => ( res && res.error && res.error.message ) || fallback;

	/**
	 * Righe per la lista: agente = client_id della voce di audit con stesso post_id e time;
	 * isCurrent per la revisione più alta; isBase per la revisione su cui l'utente sta lavorando.
	 */
	function revisionRows( revisions, auditEntries, postId, baseRevision ) {
		const list = revisions || [];
		const audit = auditEntries || [];
		const top = list.reduce( ( m, r ) => Math.max( m, r.rev ), 0 );
		return list.map( ( r ) => {
			const entry = audit.find( ( a ) => a.post_id === postId && a.time === r.time );
			return {
				rev: r.rev,
				time: r.time,
				user: r.user,
				agent: entry && entry.client_id ? entry.client_id : null,
				isBase: r.rev === baseRevision,
				isCurrent: r.rev === top,
			};
		} );
	}

	/** Cassetto con l'elenco delle revisioni e il ripristino di una di esse. */
	function RevisionsDrawer( { postId, baseRevision, call, onRestored, onClose } ) {
		const { createElement: h, useState, useEffect } = wp.element;
		const { Button, Modal, Notice, Spinner } = wp.components;
		const { __, sprintf } = wp.i18n;

		const [ rows, setRows ] = useState( [] );
		const [ loading, setLoading ] = useState( true );
		const [ error, setError ] = useState( '' );
		const [ busy, setBusy ] = useState( false );

		useEffect( () => {
			let alive = true;
			Promise.all( [
				call( 'nucleo/doc-revisions', { postId } ),
				call( 'nucleo/audit-list', { limit: 100 } ),
			] ).then( ( [ revs, audit ] ) => {
				if ( ! alive ) {
					return;
				}
				setLoading( false );
				if ( ! revs.ok ) {
					setError( msg( revs, __( 'Revisions not loaded.', 'nucleo' ) ) );
					return;
				}
				setRows( revisionRows( revs.revisions, audit.ok ? audit.entries : [], postId, baseRevision ) );
				setError( audit.ok ? '' : msg( audit, __( 'Activity not loaded.', 'nucleo' ) ) );
			} ).catch( () => {
				if ( alive ) {
					setLoading( false );
					setError( __( 'Revisions not loaded.', 'nucleo' ) );
				}
			} );
			return () => {
				alive = false;
			};
		}, [ postId, baseRevision ] );

		const restore = ( row ) => {
			const when = new Date( row.time * 1000 ).toLocaleString();
			const question = sprintf( __( 'Restore revision %1$d of %2$s? The current document will be replaced.', 'nucleo' ), row.rev, when );
			if ( ! window.confirm( question ) ) {
				return;
			}
			setBusy( true );
			setError( '' );
			call( 'nucleo/doc-rollback', { postId, revision: row.rev } ).then( ( res ) => {
				setBusy( false );
				if ( res.ok ) {
					onRestored( res );
				} else {
					setError( msg( res, __( 'Restore failed.', 'nucleo' ) ) );
				}
			} ).catch( () => {
				setBusy( false );
				setError( __( 'Restore failed.', 'nucleo' ) );
			} );
		};

		return h( Modal, {
			title: __( 'Revisions', 'nucleo' ),
			className: 'nucleo-editor__revisions',
			overlayClassName: 'nucleo-admin-portal',
			onRequestClose: onClose,
		},
			error && h( Notice, { status: 'error', isDismissible: false }, error ),
			loading && h( Spinner, null ),
			! loading && h( 'ul', null, rows.map( ( r ) => h( 'li', { key: r.rev, className: r.isBase ? 'is-base' : '' },
				h( 'strong', null, '#' + r.rev ), ' · ',
				h( 'span', null, new Date( r.time * 1000 ).toLocaleString() ), ' · ',
				h( 'span', null, r.user ? '#' + r.user : '—' ), ' ',
				r.agent && h( 'code', null, r.agent ), ' ',
				r.isBase && h( 'em', null, __( 'Your copy', 'nucleo' ) ), ' ',
				! r.isCurrent && h( Button, { variant: 'secondary', size: 'small', disabled: busy, onClick: () => restore( r ) }, __( 'Restore', 'nucleo' ) )
			) ) ),
			! loading && ! error && rows.length === 0 && h( 'p', null, __( 'No revisions.', 'nucleo' ) )
		);
	}

	const api = { revisionRows, RevisionsDrawer };

	if ( typeof module === 'object' && module.exports ) {
		module.exports = api;
	} else {
		window.nucleoRevisions = api;
	}
} )();

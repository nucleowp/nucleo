/**
 * Dialoghi condivisi dei documenti di Nucleo: nuovo documento e traduzione.
 * Nessun build: wp.element, wp.i18n. Dipende da ui.js (window.nucleoUI), da caricare prima.
 * Nei test (node --test) si esporta con module.exports; nel browser è window.nucleoShared.
 */
( function () {
	'use strict';

	const root = typeof window !== 'undefined' ? window : {};

	/* ---------- Funzioni pure ---------- */

	/** Area del layout → tag della sezione radice. Header e footer hanno il loro tag, le altre aree main. */
	function tagFor( area ) {
		return area === 'header' || area === 'footer' ? area : 'main';
	}

	const LAYOUT_AREAS = [
		'header', 'footer', 'single', 'archive',
	];

	/**
	 * Documento minimo. Per i layout (kind 'layout') l'area va nei meta; il popup ha kind 'popup'.
	 * Gli altri kind (page, section) non hanno meta.
	 */
	function blankDocument( kind, area ) {
		const section = { id: 'r', type: 'nc/section', settings: { tag: tagFor( area ) }, children: [] };
		if ( kind !== 'layout' ) {
			return { schema: 1, kind, root: section };
		}
		if ( ! LAYOUT_AREAS.includes( area ) ) {
			throw new Error( `Invalid layout area: ${ area }` );
		}
		return {
			schema: 1,
			kind: area === 'popup' ? 'popup' : 'layout',
			meta: { area, conditions: { include: [ { type: 'entire_site' } ], exclude: [], priority: 0 } },
			root: section,
		};
	}

	/** Lingue di destinazione: quelle del sito meno quella del documento. */
	function targetLanguages( langs, current ) {
		return Array.isArray( langs ) ? langs.filter( ( l ) => l !== current ) : [];
	}

	/** Solo i testi con una traduzione non vuota, nell'ordine di `texts`. Chiave dei valori: `${id}|${path}`. */
	function filledTranslations( texts, values ) {
		return texts
			.filter( ( t ) => typeof values[ `${ t.id }|${ t.path }` ] === 'string' && values[ `${ t.id }|${ t.path }` ].trim() !== '' )
			.map( ( t ) => ( { id: t.id, path: t.path, text: values[ `${ t.id }|${ t.path }` ] } ) );
	}

	const api = { blankDocument, targetLanguages, filledTranslations };

	/* ---------- Componenti (solo con window.wp.element) ---------- */

	const el = root.wp && root.wp.element;
	if ( el ) {
		const { createElement: h, useState } = el;
		const { __ } = ( root.wp && root.wp.i18n ) || { __: ( text ) => text };

		/** Link all'editor di un documento. urls.editor è la pagina dell'editor senza il parametro post. */
		function editorUrl( postId ) {
			const admin = root.nucleoAdmin || {};
			return `${ admin.urls.editor }${ encodeURIComponent( String( postId ) ) }`;
		}

		function errorText( res, fallback ) {
			return ( res && res.error && res.error.message ) || fallback;
		}

		/** Nuovo documento: titolo, tipo (se non dato), punto di partenza, pubblicazione (con can.publish). */
		function NewDocumentModal( { postType, onClose } ) {
			const UI = root.nucleoUI;
			const admin = root.nucleoAdmin || {};
			const canPublish = !! ( admin.can && admin.can.publish );
			const [ title, setTitle ] = useState( '' );
			const [ type, setType ] = useState( postType || 'page' );
			const [ publish, setPublish ] = useState( false );
			const [ busy, setBusy ] = useState( false );
			const [ titleMissing, setTitleMissing ] = useState( false );
			const [ error, setError ] = useState( null );

			const submit = () => {
				if ( title.trim() === '' ) {
					setTitleMissing( true );
					return;
				}
				setTitleMissing( false );
				setBusy( true );
				setError( null );
				UI.call( 'nucleo/doc-create', {
					title: title.trim(),
					postType: type,
					document: blankDocument( 'page' ),
					publish: canPublish && publish,
				} ).then( ( res ) => {
					if ( ! res || res.ok === false || ! res.postId ) {
						setError( errorText( res, __( 'The document could not be created.', 'nucleo' ) ) );
						setBusy( false );
						return;
					}
					root.location.assign( editorUrl( res.postId ) );
				} );
			};

			const typeOptions = [ [ 'page', __( 'Page', 'nucleo' ) ], [ 'post', __( 'Article', 'nucleo' ) ] ];

			return h( UI.Modal, {
				title: __( 'New document', 'nucleo' ),
				onClose,
				foot: [
					h( 'button', { key: 'cancel', type: 'button', className: 'nc-btn nc-btn--secondary', onClick: onClose }, __( 'Cancel', 'nucleo' ) ),
					h( 'button', { key: 'submit', type: 'button', className: 'nc-btn nc-btn--primary', disabled: busy, onClick: submit },
						__( 'Create and open the editor', 'nucleo' ) ),
				],
			},
			h( 'div', { className: 'nc-stack' },
				h( 'div', null,
					h( 'label', { htmlFor: 'nc-new-title' }, __( 'Title', 'nucleo' ), ' *' ),
					h( 'input', {
						id: 'nc-new-title',
						type: 'text',
						className: 'nc-input',
						value: title,
						required: true,
						'aria-required': 'true',
						'aria-invalid': String( titleMissing ),
						onChange: ( e ) => setTitle( e.target.value ),
					} ),
					titleMissing && h( 'p', { className: 'nc-field-error', role: 'alert' }, __( 'The title is required.', 'nucleo' ) ) ),
				! postType && h( 'div', null,
					h( 'span', { id: 'nc-new-type-label' }, __( 'Type', 'nucleo' ) ),
					h( 'div', { role: 'group', 'aria-labelledby': 'nc-new-type-label' },
						typeOptions.map( ( [ value, label ] ) => h( 'button', {
							key: value,
							type: 'button',
							className: 'nc-btn nc-btn--secondary',
							'aria-pressed': String( type === value ),
							onClick: () => setType( value ),
						}, label ) ) ) ),
				h( 'fieldset', null,
					h( 'legend', null, __( 'Starting point', 'nucleo' ) ),
					h( 'label', null,
						h( 'input', { type: 'radio', name: 'nc-new-start', value: 'blank', checked: true, onChange: () => {} } ),
						' ', __( 'Blank', 'nucleo' ), ' ',
						h( 'span', { className: 'nc-hint' }, __( 'One main section with no content.', 'nucleo' ) ) ),
					h( 'label', null,
						h( 'input', { type: 'radio', name: 'nc-new-start', value: 'kit', disabled: true, 'aria-describedby': 'nc-new-kit-reason' } ),
						' ', __( 'From kit template', 'nucleo' ) ),
					h( 'p', { id: 'nc-new-kit-reason', className: 'nc-hint' },
						__( 'Not available yet: the list of kit templates is still to come.', 'nucleo' ) ) ),
				canPublish && h( 'label', null,
					h( 'input', { type: 'checkbox', checked: publish, onChange: ( e ) => setPublish( e.target.checked ) } ),
					' ', __( 'Publish now', 'nucleo' ) ),
				error && h( UI.Notice, { tone: 'err' }, error ) ) );
		}

		/** Traduzione: lingua di destinazione, titolo tradotto, testi originali a sinistra e traduzione a destra. */
		function TranslateModal( { postId, title, lang, onClose } ) {
			const UI = root.nucleoUI;
			const admin = root.nucleoAdmin || {};
			const languages = targetLanguages( ( admin.i18n && admin.i18n.languages ) || [], lang );
			const [ state, reload ] = UI.useLoad( () => UI.call( 'nucleo/doc-texts', { postId } ), [ postId ] );
			const [ target, setTarget ] = useState( languages[ 0 ] || '' );
			const [ translatedTitle, setTranslatedTitle ] = useState( '' );
			const [ values, setValues ] = useState( {} );
			const [ busy, setBusy ] = useState( false );
			const [ error, setError ] = useState( null );
			const [ doneId, setDoneId ] = useState( null );

			const texts = ( state.data && Array.isArray( state.data.texts ) ) ? state.data.texts : [];

			const submit = () => {
				setBusy( true );
				setError( null );
				UI.call( 'nucleo/doc-translate', {
					postId,
					lang: target,
					title: translatedTitle.trim() || undefined,
					texts: filledTranslations( texts, values ),
					publish: false,
				} ).then( ( res ) => {
					setBusy( false );
					if ( ! res || res.ok === false || ! res.postId ) {
						setError( errorText( res, __( 'The translation could not be sent.', 'nucleo' ) ) );
						return;
					}
					setDoneId( res.postId );
				} );
			};

			const body = () => {
				if ( state.loading ) {
					return h( UI.Skeleton, { lines: 4 } );
				}
				if ( state.error ) {
					return h( UI.ErrorBox, { message: state.error.message || __( 'The texts could not be loaded.', 'nucleo' ), onRetry: reload } );
				}
				if ( texts.length === 0 ) {
					return h( UI.Empty, { text: __( 'No texts to translate.', 'nucleo' ) } );
				}
				return h( 'div', { className: 'nc-stack' },
					texts.map( ( t ) => {
						const key = `${ t.id }|${ t.path }`;
						return h( 'div', { key, style: { display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '16px' } },
							h( 'div', { className: 'nc-readonly', 'aria-readonly': 'true' }, t.text ),
							h( 'textarea', {
								className: 'nc-textarea',
								'aria-label': `${ __( 'Translation', 'nucleo' ) } · ${ t.path }`,
								value: values[ key ] || '',
								onChange: ( e ) => setValues( { ...values, [ key ]: e.target.value } ),
								onInput: ( e ) => {
									e.target.style.height = 'auto';
									e.target.style.height = `${ e.target.scrollHeight }px`;
								},
								style: { minHeight: '2.5em', overflow: 'hidden', resize: 'none' },
							} ) );
					} ) );
			};

			return h( UI.Modal, {
				title: `${ __( 'Translate', 'nucleo' ) } · ${ title }`,
				onClose,
				foot: [
					h( 'button', { key: 'close', type: 'button', className: 'nc-btn nc-btn--secondary', onClick: onClose }, __( 'Close', 'nucleo' ) ),
					! doneId && h( 'button', {
						key: 'submit',
						type: 'button',
						className: 'nc-btn nc-btn--primary',
						disabled: busy || state.loading || ! target,
						onClick: submit,
					}, __( 'Send translation', 'nucleo' ) ),
				],
			},
			h( 'div', { className: 'nc-stack' },
				h( 'div', { style: { display: 'grid', gridTemplateColumns: '220px 1fr', gap: '16px' } },
					h( 'div', null,
						h( 'label', { htmlFor: 'nc-tr-lang' }, __( 'Target language', 'nucleo' ) ),
						languages.length > 0
							? h( 'select', { id: 'nc-tr-lang', className: 'nc-select', value: target, onChange: ( e ) => setTarget( e.target.value ) },
								languages.map( ( l ) => h( 'option', { key: l, value: l }, l ) ) )
							: h( 'p', { className: 'nc-hint' }, __( 'No other language is set for this site.', 'nucleo' ) ) ),
					h( 'div', null,
						h( 'label', { htmlFor: 'nc-tr-title' }, __( 'Translated title', 'nucleo' ) ),
						h( 'input', {
							id: 'nc-tr-title',
							type: 'text',
							className: 'nc-input',
							value: translatedTitle,
							onChange: ( e ) => setTranslatedTitle( e.target.value ),
						} ) ) ),
				h( 'div', { style: { display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '16px' } },
					h( 'span', { className: 'nc-label' }, __( 'Original', 'nucleo' ) ),
					h( 'span', { className: 'nc-label' }, __( 'Translation', 'nucleo' ) ) ),
				body(),
				error && h( UI.Notice, { tone: 'err' }, error ),
				doneId && h( UI.Notice, { tone: 'ok' },
					h( 'a', { href: editorUrl( doneId ) }, __( 'Open the translation in the editor', 'nucleo' ) ) ) ) );
		}

		Object.assign( api, { NewDocumentModal, TranslateModal } );
	}

	if ( typeof module === 'object' && module.exports ) {
		module.exports = api;
	} else {
		root.nucleoShared = api;
	}
} )();

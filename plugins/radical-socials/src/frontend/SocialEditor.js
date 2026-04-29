import { useState, useCallback, useMemo, useEffect, useRef } from '@wordpress/element';
import { Icon, image, video, audio, link } from '@wordpress/icons';
import { useSelect, useDispatch } from '@wordpress/data';
import {
	BlockEditorProvider,
	BlockList,
	BlockTools,
	WritingFlow,
	ObserveTyping,
} from '@wordpress/block-editor';
import { createBlock, serialize } from '@wordpress/blocks';
import { registerEditorBlocks, getEditorSettings } from './editor-settings';

registerEditorBlocks();

const MEDIA_BLOCKS = [
	{ label: 'Photo', name: 'core/image', color: '#F25C05', icon: image },
	{ label: 'Video', name: 'core/video', color: '#E0407B', icon: video },
	{ label: 'Audio', name: 'core/audio', color: '#825AD1', icon: audio },
	{ label: 'Link',  name: 'core/embed', color: '#0085FF', icon: link  },
];

function EditorFocusManager( { children } ) {
	const containerRef    = useRef( null );
	const hasAutoFocused  = useRef( false );

	const firstClientId = useSelect(
		( select ) => select( 'core/block-editor' ).getBlockOrder()[ 0 ],
		[]
	);
	const selectedClientId = useSelect(
		( select ) => select( 'core/block-editor' ).getSelectedBlockClientId(),
		[]
	);
	const { selectBlock } = useDispatch( 'core/block-editor' );

	function focusEditable() {
		requestAnimationFrame( () => {
			const el = containerRef.current?.querySelector( '[contenteditable="true"]' );
			if ( el && el !== document.activeElement ) el.focus();
		} );
	}

	// Auto-focus first block on mount.
	useEffect( () => {
		if ( firstClientId && ! hasAutoFocused.current ) {
			hasAutoFocused.current = true;
			selectBlock( firstClientId );
			focusEditable();
		}
	}, [ firstClientId, selectBlock ] );

	function handleMouseDown() {
		if ( ! selectedClientId && firstClientId ) {
			selectBlock( firstClientId );
		}
		focusEditable();
	}

	return <div ref={ containerRef } className="rs-editor-writing-area" onMouseDown={ handleMouseDown }>{ children }</div>;
}

function MediaBar() {
	const { insertBlocks } = useDispatch( 'core/block-editor' );
	return (
		<div className="rs-media-bar">
			{ MEDIA_BLOCKS.map( ( { label, name, color, icon } ) => (
				<button
					key={ name }
					type="button"
					className="rs-media-btn"
					style={ { '--rs-media-color': color } }
					onClick={ () => insertBlocks( createBlock( name ) ) }
				>
					<span className="rs-media-btn__icon"><Icon icon={ icon } size={ 24 } /></span>
					<span className="rs-media-btn__label">{ label }</span>
				</button>
			) ) }
		</div>
	);
}

export default function SocialEditor( { onSuccess, onCancel } ) {
	const [ blocks, setBlocks ] = useState( () => [ createBlock( 'core/paragraph', { placeholder: "What's on your mind?" } ) ] );
	const [ hashtags, setHashtags ]         = useState( '' );
	const [ isSubmitting, setIsSubmitting ] = useState( false );
	const [ error, setError ]             = useState( null );

	const mediaUpload = useCallback( ( { filesList, onFileChange, onError } ) => {
		const file = filesList[ 0 ];
		const body = new FormData();
		body.append( 'file', file );
		fetch( `${ window.radicalSocials.restUrl }wp/v2/media`, {
			method:  'POST',
			headers: { 'X-WP-Nonce': window.radicalSocials.nonce },
			body,
		} )
			.then( ( r ) => r.json() )
			.then( ( attachment ) =>
				onFileChange( [ { id: attachment.id, url: attachment.source_url } ] )
			)
			.catch( ( err ) => onError( err instanceof Error ? err : new Error( err.message ) ) );
	}, [] );

	const editorSettings = useMemo( () => getEditorSettings( mediaUpload ), [ mediaUpload ] );

	const resolveTagIds = useCallback( async ( raw ) => {
		const names = raw
			.split( /[\s,]+/ )
			.map( ( t ) => t.replace( /^#/, '' ).trim() )
			.filter( Boolean );

		const ids = [];
		for ( const name of names ) {
			const slug   = name.toLowerCase();
			const search = await fetch(
				`${ window.radicalSocials.restUrl }wp/v2/social-tags?slug=${ encodeURIComponent( slug ) }`,
				{ headers: { 'X-WP-Nonce': window.radicalSocials.nonce } }
			);
			if ( ! search.ok ) throw new Error( 'Tag lookup failed.' );
			const found = await search.json();
			if ( found.length ) {
				ids.push( found[ 0 ].id );
				continue;
			}

			const create = await fetch(
				`${ window.radicalSocials.restUrl }wp/v2/social-tags`,
				{
					method:  'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.radicalSocials.nonce },
					body:    JSON.stringify( { name, slug } ),
				}
			);
			if ( ! create.ok ) throw new Error( 'Tag creation failed.' );
			ids.push( ( await create.json() ).id );
		}
		return ids;
	}, [] );

	const handleSubmit = useCallback( async ( e ) => {
		e.preventDefault();
		setIsSubmitting( true );
		setError( null );
		try {
			const content       = serialize( blocks );
			const firstImage    = blocks.find( ( b ) => b.name === 'core/image' );
			const featuredMedia = firstImage?.attributes?.id;
			const tagIds        = await resolveTagIds( hashtags );

			const response = await fetch(
				`${ window.radicalSocials.restUrl }wp/v2/social-posts`,
				{
					method:  'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce':  window.radicalSocials.nonce,
					},
					body: JSON.stringify( {
						status:  'publish',
						content,
						...( featuredMedia          && { featured_media: featuredMedia } ),
						...( tagIds.length          && { 'social-tags': tagIds } ),
					} ),
				}
			);
			if ( ! response.ok ) throw new Error( 'Post creation failed.' );
			const post = await response.json();
			setBlocks( [ createBlock( 'core/paragraph', { placeholder: "What's on your mind?" } ) ] );
			setHashtags( '' );
			await onSuccess?.( post );
		} catch ( err ) {
			setError( err.message );
		} finally {
			setIsSubmitting( false );
		}
	}, [ blocks, hashtags, resolveTagIds, onSuccess ] );

	const isEmpty = blocks.every(
		( b ) => b.name === 'core/paragraph' && ! b.attributes?.content
	);

	return (
		<form className="rs-social-editor" onSubmit={ handleSubmit }>
			<BlockEditorProvider
				value={ blocks }
				onInput={ setBlocks }
				onChange={ setBlocks }
				settings={ editorSettings }
				useSubRegistry={ true }
			>
				<EditorFocusManager>
					<BlockTools>
						<WritingFlow>
							<ObserveTyping>
								<BlockList renderAppender={ false } />
							</ObserveTyping>
						</WritingFlow>
					</BlockTools>
				</EditorFocusManager>
				<MediaBar />
			</BlockEditorProvider>

			<div className="rs-editor-meta">
				<input
					type="text"
					placeholder="#tags"
					value={ hashtags }
					onChange={ ( e ) => setHashtags( e.target.value ) }
				/>
			</div>

			{ error && <p className="rs-editor-error">{ error }</p> }

			<div className="rs-editor-actions">
				{ onCancel && (
					<button type="button" onClick={ onCancel }>
						Cancel
					</button>
				) }
				<button type="submit" disabled={ isSubmitting || isEmpty }>
					{ isSubmitting ? 'Posting\u2026' : 'Post' }
				</button>
			</div>
		</form>
	);
}

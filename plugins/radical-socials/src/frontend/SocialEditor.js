import { useState, useCallback, useMemo, useEffect, useRef } from '@wordpress/element';
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
	{ label: 'Photo', name: 'core/image' },
	{ label: 'Video', name: 'core/video' },
	{ label: 'Link',  name: 'core/embed' },
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

	return <div ref={ containerRef } onMouseDown={ handleMouseDown }>{ children }</div>;
}

function MediaBar() {
	const { insertBlocks } = useDispatch( 'core/block-editor' );
	return (
		<div className="rs-media-bar">
			{ MEDIA_BLOCKS.map( ( { label, name } ) => (
				<button
					key={ name }
					type="button"
					className="rs-media-btn"
					onClick={ () => insertBlocks( createBlock( name ) ) }
				>
					{ label }
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

	const handleSubmit = useCallback( async ( e ) => {
		e.preventDefault();
		setIsSubmitting( true );
		setError( null );
		try {
			const content       = serialize( blocks );
			const firstImage    = blocks.find( ( b ) => b.name === 'core/image' );
			const featuredMedia = firstImage?.attributes?.id;

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
						...( featuredMedia && { featured_media: featuredMedia } ),
						meta: {
							_social_tags: hashtags,
						},
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
	}, [ blocks, hashtags, onSuccess ] );

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

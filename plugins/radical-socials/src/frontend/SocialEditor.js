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
	{ label: 'Quote', name: 'core/quote' },
	{ label: 'Link',  name: 'core/embed' },
];

function EditorAutoFocus() {
	const firstClientId = useSelect(
		( select ) => select( 'core/block-editor' ).getBlockOrder()[ 0 ],
		[]
	);
	const { selectBlock } = useDispatch( 'core/block-editor' );
	const hasFocused = useRef( false );
	useEffect( () => {
		if ( firstClientId && ! hasFocused.current ) {
			hasFocused.current = true;
			selectBlock( firstClientId );
		}
	}, [ firstClientId, selectBlock ] );
	return null;
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
	const [ hashtags, setHashtags ]       = useState( '' );
	const [ location, setLocation ]       = useState( '' );
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
				`${ window.radicalSocials.restUrl }wp/v2/instagram-posts`,
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
							_instagram_tags:     hashtags,
							_instagram_location: location,
						},
					} ),
				}
			);
			if ( ! response.ok ) throw new Error( 'Post creation failed.' );
			const post = await response.json();
			setBlocks( [ createBlock( 'core/paragraph', { placeholder: "What's on your mind?" } ) ] );
			setHashtags( '' );
			setLocation( '' );
			onSuccess?.( post );
		} catch ( err ) {
			setError( err.message );
		} finally {
			setIsSubmitting( false );
		}
	}, [ blocks, hashtags, location, onSuccess ] );

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
				<BlockTools>
					<WritingFlow>
						<ObserveTyping>
							<BlockList />
						</ObserveTyping>
					</WritingFlow>
				</BlockTools>
				<EditorAutoFocus />
				<MediaBar />
			</BlockEditorProvider>

			<div className="rs-editor-meta">
				<input
					type="text"
					placeholder="#tags"
					value={ hashtags }
					onChange={ ( e ) => setHashtags( e.target.value ) }
				/>
				<input
					type="text"
					placeholder="Location"
					value={ location }
					onChange={ ( e ) => setLocation( e.target.value ) }
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

import { useState, useCallback } from '@wordpress/element';
import {
	BlockEditorProvider,
	BlockList,
	BlockToolbar,
	BlockTools,
	WritingFlow,
	ObserveTyping,
} from '@wordpress/block-editor';
import { createBlock, serialize } from '@wordpress/blocks';
import { registerEditorBlocks, getEditorSettings } from './editor-settings';

export default function SocialEditor( { onSuccess, onCancel } ) {
	const [ blocks, setBlocks ] = useState( () => {
		registerEditorBlocks();
		return [ createBlock( 'core/paragraph' ) ];
	} );
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
			.catch( ( err ) => onError( err.message ) );
	}, [] );

	const editorSettings = getEditorSettings( mediaUpload );

	async function handleSubmit( e ) {
		e.preventDefault();
		setIsSubmitting( true );
		setError( null );
		try {
			const content  = serialize( blocks );
			const response = await fetch(
				`${ window.radicalSocials.restUrl }wp/v2/instagram-posts`,
				{
					method:  'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce':  window.radicalSocials.nonce,
					},
					body: JSON.stringify( {
						status: 'publish',
						content,
						meta: {
							_instagram_tags:     hashtags,
							_instagram_location: location,
						},
					} ),
				}
			);
			if ( ! response.ok ) throw new Error( 'Post creation failed.' );
			const post = await response.json();
			setBlocks( [ createBlock( 'core/paragraph' ) ] );
			setHashtags( '' );
			setLocation( '' );
			onSuccess?.( post );
		} catch ( err ) {
			setError( err.message );
		} finally {
			setIsSubmitting( false );
		}
	}

	return (
		<form className="rs-social-editor" onSubmit={ handleSubmit }>
			<BlockEditorProvider
				value={ blocks }
				onInput={ setBlocks }
				onChange={ setBlocks }
				settings={ editorSettings }
				useSubRegistry={ true }
			>
				<BlockToolbar />
				<BlockTools>
					<WritingFlow>
						<ObserveTyping>
							<BlockList />
						</ObserveTyping>
					</WritingFlow>
				</BlockTools>
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
				<button type="submit" disabled={ isSubmitting }>
					{ isSubmitting ? 'Posting\u2026' : 'Post' }
				</button>
			</div>
		</form>
	);
}

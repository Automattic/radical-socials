import { useState, useCallback, useMemo, useEffect, useRef } from '@wordpress/element';
import { Icon, image, video, audio, link } from '@wordpress/icons';
import { useDispatch } from '@wordpress/data';
import {
	BlockEditorProvider,
	BlockList,
	BlockTools,
	WritingFlow,
	ObserveTyping,
} from '@wordpress/block-editor';
import { SlotFillProvider, Popover } from '@wordpress/components';
import { ShortcutProvider } from '@wordpress/keyboard-shortcuts';
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
	const containerRef = useRef( null );

	// One-shot focus on mount: drop the cursor into the first contenteditable
	// so the user can start typing without a click. Block *selection* is
	// intentionally NOT managed here — BlockList already runs
	// `useBlockSelectionClearer` via `useBlockProps`, and any competing
	// mousedown handler on this wrapper steals focus away from media-block
	// placeholders (which then trip MediaPlaceholder's `has-illustration`
	// state and hide their Upload / Insert-from-URL buttons).
	useEffect( () => {
		const id = requestAnimationFrame( () => {
			const el = containerRef.current?.querySelector( '[contenteditable="true"]' );
			if ( el && el !== document.activeElement ) el.focus();
		} );
		return () => cancelAnimationFrame( id );
	}, [] );

	return <div ref={ containerRef } className="heckl-editor-writing-area">{ children }</div>;
}

function MediaBar() {
	const { insertBlocks } = useDispatch( 'core/block-editor' );
	return (
		<div className="heckl-media-bar">
			{ MEDIA_BLOCKS.map( ( { label, name, color, icon } ) => (
				<button
					key={ name }
					type="button"
					className="heckl-media-btn"
					style={ { '--heckl-media-color': color } }
					onClick={ () => insertBlocks( createBlock( name ) ) }
				>
					<span className="heckl-media-btn__icon"><Icon icon={ icon } size={ 24 } /></span>
					<span className="heckl-media-btn__label">{ label }</span>
				</button>
			) ) }
		</div>
	);
}

export default function SocialEditor( { onSuccess, onCancel } ) {
	const [ blocks, setBlocks ] = useState( () => [ createBlock( 'core/paragraph', { placeholder: "What's on your mind?" } ) ] );
	const [ hashtags, setHashtags ]         = useState( '' );
	const [ isSubmitting, setIsSubmitting ] = useState( false );
	const [ error, setError ]               = useState( null );

	// Documented MediaUpload contract: ({ additionalData, filesList, onError, onFileChange }) => void.
	// Upload all files in parallel so drag-dropping multi-file selections
	// don't silently drop everything past index 0, and return each
	// attachment in the documented media-object shape (id/url/alt/caption/
	// title/mime) so blocks have valid attrs and don't re-parse as
	// "invalid block" on next edit.
	const mediaUpload = useCallback(
		( { filesList, onFileChange, onError, additionalData = {} } ) => {
			const uploads = Array.from( filesList ).map( ( file ) => {
				const body = new FormData();
				body.append( 'file', file );
				Object.entries( additionalData ).forEach( ( [ k, v ] ) => body.append( k, v ) );

				return fetch( `${ window.radicalSocials.restUrl }wp/v2/media`, {
					method:  'POST',
					headers: { 'X-WP-Nonce': window.radicalSocials.nonce },
					body,
				} )
					.then( ( r ) => {
						if ( ! r.ok ) throw new Error( `Upload failed (${ r.status })` );
						return r.json();
					} )
					.then( ( a ) => ( {
						id:      a.id,
						url:     a.source_url,
						alt:     a.alt_text ?? '',
						caption: a.caption?.rendered ?? '',
						title:   a.title?.rendered ?? '',
						mime:    a.mime_type,
					} ) )
					.catch( ( err ) => {
						// Documented error shape: { code, message, file }.
						// Keeps the file ref intact so the block's error UI
						// can render a "Retry" affordance.
						onError( {
							code:    'UPLOAD_FAILED',
							message: err?.message ?? String( err ),
							file,
						} );
						return null;
					} );
			} );

			Promise.all( uploads ).then( ( results ) => {
				const ok = results.filter( Boolean );
				if ( ok.length ) onFileChange( ok );
			} );
		},
		[]
	);

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
				`${ window.radicalSocials.restUrl }wp/v2/tags?slug=${ encodeURIComponent( slug ) }`,
				{ headers: { 'X-WP-Nonce': window.radicalSocials.nonce } }
			);
			if ( ! search.ok ) throw new Error( 'Tag lookup failed.' );
			const found = await search.json();
			if ( found.length ) {
				ids.push( found[ 0 ].id );
				continue;
			}

			const create = await fetch(
				`${ window.radicalSocials.restUrl }wp/v2/tags`,
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
			// `parse(serialize(blocks))` is only round-trip-safe when every
			// block is valid. Refuse to POST junk that re-parses as the
			// yellow "this block contains unexpected or invalid content"
			// recovery UI.
			if ( blocks.some( ( b ) => b.isValid === false ) ) {
				throw new Error( 'One of the blocks has invalid content. Fix it before posting.' );
			}

			// Auto-promote the first image to featured_media so single-post
			// templates don't render it twice (featured + inline). Skip
			// promotion when the image carries inline content that would be
			// lost on the featured-image surface: caption text, a custom
			// link destination, or a non-default alignment.
			const firstImageIdx = blocks.findIndex( ( b ) => b.name === 'core/image' );
			const firstImage    = firstImageIdx >= 0 ? blocks[ firstImageIdx ] : null;
			const imgAttrs      = firstImage?.attributes ?? {};
			const isBareImage   = !! firstImage
				&& ! imgAttrs.caption
				&& ! imgAttrs.linkDestination
				&& ( ! imgAttrs.align || imgAttrs.align === 'center' );
			const featuredMedia = isBareImage ? imgAttrs.id : undefined;
			const contentBlocks = featuredMedia
				? blocks.filter( ( _b, i ) => i !== firstImageIdx )
				: blocks;
			const content       = serialize( contentBlocks );
			const tagIds        = await resolveTagIds( hashtags );

			const response = await fetch(
				`${ window.radicalSocials.restUrl }wp/v2/posts`,
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
						...( tagIds.length && { tags: tagIds } ),
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

	// ShortcutProvider wires Cmd-Z / Cmd-Shift-Z and block keyboard
	// shortcuts (BlockTools uses __unstableUseShortcutEventMatch which
	// silently no-ops outside this provider). SlotFillProvider + the
	// trailing Popover.Slot give BlockControls / InspectorControls / the
	// RichText format toolbar a sink — without it those popovers either
	// render in an ancestor slot (theme/site-wide) or vanish.
	return (
		<ShortcutProvider>
			<SlotFillProvider>
				<form className="heckl-social-editor" onSubmit={ handleSubmit }>
					<BlockEditorProvider
						value={ blocks }
						onInput={ setBlocks }
						onChange={ setBlocks }
						settings={ editorSettings }
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

					<div className="heckl-editor-meta">
						<input
							type="text"
							placeholder="#tags"
							value={ hashtags }
							onChange={ ( e ) => setHashtags( e.target.value ) }
						/>
					</div>

					{ error && <p className="heckl-editor-error">{ error }</p> }

					<div className="heckl-editor-actions">
						{ onCancel && (
							<button type="button" onClick={ onCancel }>
								Cancel
							</button>
						) }
						<button type="submit" disabled={ isSubmitting || isEmpty }>
							{ isSubmitting ? 'Posting…' : 'Post' }
						</button>
					</div>

					<Popover.Slot />
				</form>
			</SlotFillProvider>
		</ShortcutProvider>
	);
}

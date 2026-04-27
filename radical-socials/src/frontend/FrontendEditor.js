import { useState } from '@wordpress/element';
import './frontend.css';

const TYPES = [ 'text', 'photo', 'video' ];

export default function FrontendEditor() {
	const [ expanded,   setExpanded   ] = useState( false );
	const [ mediaType,  setMediaType  ] = useState( null );
	const [ caption,    setCaption    ] = useState( '' );
	const [ tags,       setTags       ] = useState( '' );
	const [ location,   setLocation   ] = useState( '' );
	const [ file,       setFile       ] = useState( null );
	const [ submitting, setSubmitting ] = useState( false );
	const [ error,      setError      ] = useState( null );
	const [ newPosts,   setNewPosts   ] = useState( [] );

	const { nonce, restUrl } = window.radicalSocials;

	function collapse() {
		setExpanded( false );
		setMediaType( null );
		setCaption( '' );
		setTags( '' );
		setLocation( '' );
		setFile( null );
		setError( null );
	}

	async function handleSubmit( e ) {
		e.preventDefault();
		setSubmitting( true );
		setError( null );

		try {
			let featuredMedia = null;

			if ( file ) {
				const mediaRes = await fetch( `${ restUrl }wp/v2/media`, {
					method:  'POST',
					headers: {
						'X-WP-Nonce':          nonce,
						'Content-Disposition': `attachment; filename="${ file.name }"`,
						'Content-Type':        file.type,
					},
					body: file,
				} );
				if ( ! mediaRes.ok ) throw new Error( 'Media upload failed' );
				const mediaData = await mediaRes.json();
				featuredMedia = mediaData.id;
			}

			const body = {
				status:  'publish',
				content: caption,
				meta: {
					_instagram_location:   location,
					_instagram_media_type: mediaType,
					_instagram_tags:       tags,
				},
			};
			if ( featuredMedia ) body.featured_media = featuredMedia;

			const postRes = await fetch( `${ restUrl }wp/v2/instagram-posts`, {
				method:  'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce':  nonce,
				},
				body: JSON.stringify( body ),
			} );

			if ( ! postRes.ok ) throw new Error( 'Post creation failed' );
			const post = await postRes.json();

			setNewPosts( ( prev ) => [ post, ...prev ] );
			collapse();
		} catch ( err ) {
			setError( err.message );
		} finally {
			setSubmitting( false );
		}
	}

	return (
		<div className="rs-frontend-editor">
			{ newPosts.map( ( post ) => (
				<div
					key={ post.id }
					className="rs-new-post"
					dangerouslySetInnerHTML={ { __html: post.content.rendered } }
				/>
			) ) }

			{ ! expanded && (
				<button className="rs-editor-prompt" onClick={ () => setExpanded( true ) }>
					What&apos;s on your mind?
				</button>
			) }

			{ expanded && (
				<form className="rs-editor-form" onSubmit={ handleSubmit }>
					<div className="rs-editor-types">
						{ TYPES.map( ( type ) => (
							<button
								key={ type }
								type="button"
								className={ `rs-type-btn${ mediaType === type ? ' is-active' : '' }` }
								onClick={ () => setMediaType( type ) }
							>
								{ type.charAt( 0 ).toUpperCase() + type.slice( 1 ) }
							</button>
						) ) }
					</div>

					{ mediaType && (
						<>
							{ ( mediaType === 'photo' || mediaType === 'video' ) && (
								<label className="rs-upload-label">
									{ `Upload ${ mediaType.charAt( 0 ).toUpperCase() + mediaType.slice( 1 ) }` }
									<input
										type="file"
										accept={ mediaType === 'photo' ? 'image/*' : 'video/*' }
										onChange={ ( e ) => setFile( e.target.files[ 0 ] ?? null ) }
									/>
								</label>
							) }

							<textarea
								className="rs-caption"
								placeholder="Write a caption…"
								value={ caption }
								onChange={ ( e ) => setCaption( e.target.value ) }
							/>

							<input
								className="rs-tags"
								type="text"
								placeholder="#tags"
								value={ tags }
								onChange={ ( e ) => setTags( e.target.value ) }
							/>

							<input
								className="rs-location"
								type="text"
								placeholder="Location"
								value={ location }
								onChange={ ( e ) => setLocation( e.target.value ) }
							/>

							{ error && <p className="rs-error">{ error }</p> }

							<div className="rs-editor-actions">
								<button
									type="button"
									onClick={ collapse }
									disabled={ submitting }
								>
									Cancel
								</button>
								<button
									type="submit"
									disabled={ submitting || ! caption.trim() }
								>
									{ submitting ? 'Posting…' : 'Post' }
								</button>
							</div>
						</>
					) }

					{ ! mediaType && (
						<div className="rs-editor-actions">
							<button type="button" onClick={ collapse }>Cancel</button>
						</div>
					) }
				</form>
			) }
		</div>
	);
}

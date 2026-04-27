import { useState } from '@wordpress/element';
import './frontend.css';

const TYPES = [ 'text', 'photo', 'video' ];

export default function FrontendEditor() {
	const [ expanded,  setExpanded  ] = useState( false );
	const [ mediaType, setMediaType ] = useState( null );
	const [ caption,   setCaption   ] = useState( '' );
	const [ tags,      setTags      ] = useState( '' );
	const [ location,  setLocation  ] = useState( '' );
	const [ file,      setFile      ] = useState( null );

	function collapse() {
		setExpanded( false );
		setMediaType( null );
		setCaption( '' );
		setTags( '' );
		setLocation( '' );
		setFile( null );
	}

	if ( ! expanded ) {
		return (
			<div className="rs-frontend-editor">
				<button className="rs-editor-prompt" onClick={ () => setExpanded( true ) }>
					What&apos;s on your mind?
				</button>
			</div>
		);
	}

	return (
		<div className="rs-frontend-editor">
			<div className="rs-editor-form">
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
					</>
				) }

				<div className="rs-editor-actions">
					<button type="button" onClick={ collapse }>Cancel</button>
				</div>
			</div>
		</div>
	);
}

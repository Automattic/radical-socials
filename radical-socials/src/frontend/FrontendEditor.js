import { useState } from '@wordpress/element';
import './frontend.css';

const TYPES = [ 'text', 'photo', 'video' ];

export default function FrontendEditor() {
	const [ expanded,  setExpanded  ] = useState( false );
	const [ mediaType, setMediaType ] = useState( null );

	function collapse() {
		setExpanded( false );
		setMediaType( null );
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
				<div className="rs-editor-actions">
					<button type="button" onClick={ collapse }>Cancel</button>
				</div>
			</div>
		</div>
	);
}

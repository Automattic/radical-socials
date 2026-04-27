import { createRoot } from '@wordpress/element';
import EditorModal    from './frontend/EditorModal';
import FrontendEditor from './frontend/FrontendEditor';

// Always mount modal into a plugin-owned root div
const modalRoot = document.createElement( 'div' );
modalRoot.id = 'rs-modal-root';
document.body.appendChild( modalRoot );
createRoot( modalRoot ).render( <EditorModal /> );

// Mount inline editor only if the theme provides the mount point
const inlineRoot = document.getElementById( 'radical-socials-editor' );
if ( inlineRoot ) {
	createRoot( inlineRoot ).render( <FrontendEditor /> );
}

// Wire custom bar Create button (and any future [data-rs-action="open-editor"] elements)
document.addEventListener( 'click', ( e ) => {
	const trigger = e.target.closest( '[data-rs-action="open-editor"]' );
	if ( trigger ) {
		e.preventDefault();
		document.dispatchEvent( new CustomEvent( 'rs:open-editor' ) );
	}
} );

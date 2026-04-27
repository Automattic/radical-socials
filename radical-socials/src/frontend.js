import { createRoot } from '@wordpress/element';
import FrontendEditor from './frontend/FrontendEditor';

const root = document.getElementById( 'radical-socials-editor' );

if ( root ) {
	createRoot( root ).render( <FrontendEditor /> );
}

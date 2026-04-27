import { createRoot } from '@wordpress/element';
import Dashboard from './dashboard';

const root = document.getElementById( 'radical-socials-app' );

if ( root ) {
	createRoot( root ).render( <Dashboard /> );
}

import { createRoot } from '@wordpress/element';
import Dashboard from './dashboard';

const root = document.getElementById( 'heckl-app' );

if ( root ) {
	createRoot( root ).render( <Dashboard /> );
}

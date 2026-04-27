import { render, screen, fireEvent } from '@testing-library/react';
import FrontendEditor from './FrontendEditor';

beforeEach( () => {
	global.window.radicalSocials = {
		nonce:   'test-nonce',
		restUrl: 'http://localhost/wp-json/',
	};
} );

describe( 'idle state', () => {
	it( 'renders the prompt text', () => {
		render( <FrontendEditor /> );
		expect( screen.getByText( /what's on your mind/i ) ).toBeInTheDocument();
	} );

	it( 'does not show the type buttons while idle', () => {
		render( <FrontendEditor /> );
		expect( screen.queryByRole( 'button', { name: /^text$/i } ) ).not.toBeInTheDocument();
	} );
} );

describe( 'expand on click', () => {
	it( 'shows type buttons after clicking the prompt', () => {
		render( <FrontendEditor /> );
		fireEvent.click( screen.getByText( /what's on your mind/i ) );
		expect( screen.getByRole( 'button', { name: /^text$/i } ) ).toBeInTheDocument();
		expect( screen.getByRole( 'button', { name: /^photo$/i } ) ).toBeInTheDocument();
		expect( screen.getByRole( 'button', { name: /^video$/i } ) ).toBeInTheDocument();
	} );

	it( 'hides the prompt after expanding', () => {
		render( <FrontendEditor /> );
		fireEvent.click( screen.getByText( /what's on your mind/i ) );
		expect( screen.queryByText( /what's on your mind/i ) ).not.toBeInTheDocument();
	} );

	it( 'collapses back to idle on Cancel', () => {
		render( <FrontendEditor /> );
		fireEvent.click( screen.getByText( /what's on your mind/i ) );
		fireEvent.click( screen.getByRole( 'button', { name: /cancel/i } ) );
		expect( screen.getByText( /what's on your mind/i ) ).toBeInTheDocument();
		expect( screen.queryByRole( 'button', { name: /^text$/i } ) ).not.toBeInTheDocument();
	} );
} );

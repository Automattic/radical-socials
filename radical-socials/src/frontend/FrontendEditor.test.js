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

describe( 'form fields by media type', () => {
	function expandAndSelect( type ) {
		render( <FrontendEditor /> );
		fireEvent.click( screen.getByText( /what's on your mind/i ) );
		fireEvent.click( screen.getByRole( 'button', { name: new RegExp( `^${ type }$`, 'i' ) } ) );
	}

	it( 'shows caption, tags, and location after selecting Text', () => {
		expandAndSelect( 'text' );
		expect( screen.getByPlaceholderText( /write a caption/i ) ).toBeInTheDocument();
		expect( screen.getByPlaceholderText( /#tags/i ) ).toBeInTheDocument();
		expect( screen.getByPlaceholderText( /location/i ) ).toBeInTheDocument();
	} );

	it( 'shows file upload + caption + tags + location after selecting Photo', () => {
		expandAndSelect( 'photo' );
		expect( screen.getByLabelText( /upload photo/i ) ).toBeInTheDocument();
		expect( screen.getByPlaceholderText( /write a caption/i ) ).toBeInTheDocument();
		expect( screen.getByPlaceholderText( /#tags/i ) ).toBeInTheDocument();
		expect( screen.getByPlaceholderText( /location/i ) ).toBeInTheDocument();
	} );

	it( 'shows file upload + caption + tags + location after selecting Video', () => {
		expandAndSelect( 'video' );
		expect( screen.getByLabelText( /upload video/i ) ).toBeInTheDocument();
		expect( screen.getByPlaceholderText( /write a caption/i ) ).toBeInTheDocument();
	} );

	it( 'does not show file upload for Text type', () => {
		expandAndSelect( 'text' );
		expect( screen.queryByLabelText( /upload photo/i ) ).not.toBeInTheDocument();
		expect( screen.queryByLabelText( /upload video/i ) ).not.toBeInTheDocument();
	} );

	it( 'marks the selected type button as active', () => {
		expandAndSelect( 'photo' );
		expect( screen.getByRole( 'button', { name: /^photo$/i } ) ).toHaveClass( 'is-active' );
		expect( screen.getByRole( 'button', { name: /^text$/i } ) ).not.toHaveClass( 'is-active' );
	} );
} );

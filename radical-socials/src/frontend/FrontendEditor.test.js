import { render, screen, fireEvent } from '@testing-library/react';
import FrontendEditor from './FrontendEditor';

jest.mock( './EditorForm', () => {
	return function MockEditorForm( { onSuccess, onCancel } ) {
		return (
			<div data-testid="editor-form">
				<button onClick={ onCancel }>Cancel</button>
				<button
					onClick={ () => onSuccess( { id: 1, content: { rendered: '<p>New post</p>' } } ) }
				>
					Simulate Success
				</button>
			</div>
		);
	};
} );

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

	it( 'does not show the form while idle', () => {
		render( <FrontendEditor /> );
		expect( screen.queryByTestId( 'editor-form' ) ).not.toBeInTheDocument();
	} );
} );

describe( 'expand on click', () => {
	it( 'shows EditorForm after clicking the prompt', () => {
		render( <FrontendEditor /> );
		fireEvent.click( screen.getByText( /what's on your mind/i ) );
		expect( screen.getByTestId( 'editor-form' ) ).toBeInTheDocument();
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
		expect( screen.queryByTestId( 'editor-form' ) ).not.toBeInTheDocument();
	} );
} );

describe( 'new post display', () => {
	it( 'shows a new post above the fold after success', () => {
		render( <FrontendEditor /> );
		fireEvent.click( screen.getByText( /what's on your mind/i ) );
		fireEvent.click( screen.getByRole( 'button', { name: /simulate success/i } ) );
		expect( screen.getByText( 'New post' ) ).toBeInTheDocument();
	} );

	it( 'collapses back to idle after success', () => {
		render( <FrontendEditor /> );
		fireEvent.click( screen.getByText( /what's on your mind/i ) );
		fireEvent.click( screen.getByRole( 'button', { name: /simulate success/i } ) );
		expect( screen.getByText( /what's on your mind/i ) ).toBeInTheDocument();
	} );
} );

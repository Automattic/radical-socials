import { render, screen, fireEvent, act, waitFor } from '@testing-library/react';

jest.mock( './SocialEditor', () => {
	return {
		__esModule: true,
		default: function MockSocialEditor( { onSuccess, onCancel } ) {
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
		},
	};
} );

import FrontendEditor from './FrontendEditor';

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
	it( 'shows EditorForm after clicking the prompt', async () => {
		render( <FrontendEditor /> );
		act( () => {
			fireEvent.click( screen.getByText( /what's on your mind/i ) );
		} );
		await waitFor( () => {
			expect( screen.getByTestId( 'editor-form' ) ).toBeInTheDocument();
		} );
	} );

	it( 'hides the prompt after expanding', async () => {
		render( <FrontendEditor /> );
		act( () => {
			fireEvent.click( screen.getByText( /what's on your mind/i ) );
		} );
		await waitFor( () => {
			expect( screen.queryByText( /what's on your mind/i ) ).not.toBeInTheDocument();
		} );
	} );

	it( 'collapses back to idle on Cancel', async () => {
		render( <FrontendEditor /> );
		act( () => {
			fireEvent.click( screen.getByText( /what's on your mind/i ) );
		} );
		await waitFor( () => {
			expect( screen.getByTestId( 'editor-form' ) ).toBeInTheDocument();
		} );
		act( () => {
			fireEvent.click( screen.getByRole( 'button', { name: /cancel/i } ) );
		} );
		expect( screen.getByText( /what's on your mind/i ) ).toBeInTheDocument();
		expect( screen.queryByTestId( 'editor-form' ) ).not.toBeInTheDocument();
	} );
} );

describe( 'new post display', () => {
	it( 'shows a new post above the fold after success', async () => {
		render( <FrontendEditor /> );
		act( () => {
			fireEvent.click( screen.getByText( /what's on your mind/i ) );
		} );
		await waitFor( () => {
			expect( screen.getByTestId( 'editor-form' ) ).toBeInTheDocument();
		} );
		act( () => {
			fireEvent.click( screen.getByRole( 'button', { name: /simulate success/i } ) );
		} );
		expect( screen.getByText( 'New post' ) ).toBeInTheDocument();
	} );

	it( 'collapses back to idle after success', async () => {
		render( <FrontendEditor /> );
		act( () => {
			fireEvent.click( screen.getByText( /what's on your mind/i ) );
		} );
		await waitFor( () => {
			expect( screen.getByTestId( 'editor-form' ) ).toBeInTheDocument();
		} );
		act( () => {
			fireEvent.click( screen.getByRole( 'button', { name: /simulate success/i } ) );
		} );
		expect( screen.getByText( /what's on your mind/i ) ).toBeInTheDocument();
	} );
} );

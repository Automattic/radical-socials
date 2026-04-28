import { render, screen, fireEvent, act, waitFor } from '@testing-library/react';

jest.mock( './SocialEditor', () => {
	return {
		__esModule: true,
		default: function MockSocialEditor( { onCancel } ) {
			return (
				<div data-testid="editor-form">
					<button onClick={ onCancel }>Cancel</button>
				</div>
			);
		},
	};
} );

import EditorModal from './EditorModal';

beforeEach( () => {
	global.window.radicalSocials = {
		nonce:   'test-nonce',
		restUrl: 'http://localhost/wp-json/',
	};
} );

afterEach( () => {
	document.body.style.overflow = '';
} );

it( 'is hidden by default', () => {
	render( <EditorModal /> );
	expect( screen.queryByRole( 'dialog' ) ).not.toBeInTheDocument();
} );

it( 'opens when rs:open-editor is dispatched', async () => {
	render( <EditorModal /> );
	act( () => {
		document.dispatchEvent( new CustomEvent( 'rs:open-editor' ) );
	} );
	await waitFor( () => {
		expect( screen.getByRole( 'dialog' ) ).toBeInTheDocument();
	} );
} );

it( 'closes when the overlay is clicked', async () => {
	render( <EditorModal /> );
	act( () => {
		document.dispatchEvent( new CustomEvent( 'rs:open-editor' ) );
	} );
	await waitFor( () => {
		expect( screen.getByTestId( 'modal-overlay' ) ).toBeInTheDocument();
	} );
	fireEvent.click( screen.getByTestId( 'modal-overlay' ) );
	expect( screen.queryByRole( 'dialog' ) ).not.toBeInTheDocument();
} );

it( 'does not close when the dialog inner area is clicked', async () => {
	render( <EditorModal /> );
	act( () => {
		document.dispatchEvent( new CustomEvent( 'rs:open-editor' ) );
	} );
	await waitFor( () => {
		expect( screen.getByTestId( 'editor-form' ) ).toBeInTheDocument();
	} );
	fireEvent.click( screen.getByTestId( 'editor-form' ) );
	expect( screen.getByRole( 'dialog' ) ).toBeInTheDocument();
} );

it( 'closes when Escape is pressed', async () => {
	render( <EditorModal /> );
	act( () => {
		document.dispatchEvent( new CustomEvent( 'rs:open-editor' ) );
	} );
	await waitFor( () => {
		expect( screen.getByRole( 'dialog' ) ).toBeInTheDocument();
	} );
	fireEvent.keyDown( document, { key: 'Escape' } );
	expect( screen.queryByRole( 'dialog' ) ).not.toBeInTheDocument();
} );

it( 'closes when EditorForm calls onCancel', async () => {
	render( <EditorModal /> );
	act( () => {
		document.dispatchEvent( new CustomEvent( 'rs:open-editor' ) );
	} );
	await waitFor( () => {
		expect( screen.getByRole( 'button', { name: /cancel/i } ) ).toBeInTheDocument();
	} );
	fireEvent.click( screen.getByRole( 'button', { name: /cancel/i } ) );
	expect( screen.queryByRole( 'dialog' ) ).not.toBeInTheDocument();
} );

it( 'sets body overflow to hidden when open', async () => {
	render( <EditorModal /> );
	act( () => {
		document.dispatchEvent( new CustomEvent( 'rs:open-editor' ) );
	} );
	await waitFor( () => {
		expect( document.body.style.overflow ).toBe( 'hidden' );
	} );
} );

it( 'restores body overflow when closed', async () => {
	render( <EditorModal /> );
	act( () => {
		document.dispatchEvent( new CustomEvent( 'rs:open-editor' ) );
	} );
	await waitFor( () => {
		expect( screen.getByTestId( 'modal-overlay' ) ).toBeInTheDocument();
	} );
	fireEvent.click( screen.getByTestId( 'modal-overlay' ) );
	expect( document.body.style.overflow ).toBe( '' );
} );

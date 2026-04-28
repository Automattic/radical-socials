import { render, screen, fireEvent, act } from '@testing-library/react';
import EditorModal from './EditorModal';

jest.mock( './SocialEditor', () => {
	return function MockSocialEditor( { onCancel } ) {
		return (
			<div data-testid="editor-form">
				<button onClick={ onCancel }>Cancel</button>
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

afterEach( () => {
	document.body.style.overflow = '';
} );

it( 'is hidden by default', () => {
	render( <EditorModal /> );
	expect( screen.queryByRole( 'dialog' ) ).not.toBeInTheDocument();
} );

it( 'opens when rs:open-editor is dispatched', () => {
	render( <EditorModal /> );
	act( () => {
		document.dispatchEvent( new CustomEvent( 'rs:open-editor' ) );
	} );
	expect( screen.getByRole( 'dialog' ) ).toBeInTheDocument();
} );

it( 'closes when the overlay is clicked', () => {
	render( <EditorModal /> );
	act( () => {
		document.dispatchEvent( new CustomEvent( 'rs:open-editor' ) );
	} );
	fireEvent.click( screen.getByTestId( 'modal-overlay' ) );
	expect( screen.queryByRole( 'dialog' ) ).not.toBeInTheDocument();
} );

it( 'does not close when the dialog inner area is clicked', () => {
	render( <EditorModal /> );
	act( () => {
		document.dispatchEvent( new CustomEvent( 'rs:open-editor' ) );
	} );
	fireEvent.click( screen.getByTestId( 'editor-form' ) );
	expect( screen.getByRole( 'dialog' ) ).toBeInTheDocument();
} );

it( 'closes when Escape is pressed', () => {
	render( <EditorModal /> );
	act( () => {
		document.dispatchEvent( new CustomEvent( 'rs:open-editor' ) );
	} );
	fireEvent.keyDown( document, { key: 'Escape' } );
	expect( screen.queryByRole( 'dialog' ) ).not.toBeInTheDocument();
} );

it( 'closes when EditorForm calls onCancel', () => {
	render( <EditorModal /> );
	act( () => {
		document.dispatchEvent( new CustomEvent( 'rs:open-editor' ) );
	} );
	fireEvent.click( screen.getByRole( 'button', { name: /cancel/i } ) );
	expect( screen.queryByRole( 'dialog' ) ).not.toBeInTheDocument();
} );

it( 'sets body overflow to hidden when open', () => {
	render( <EditorModal /> );
	act( () => {
		document.dispatchEvent( new CustomEvent( 'rs:open-editor' ) );
	} );
	expect( document.body.style.overflow ).toBe( 'hidden' );
} );

it( 'restores body overflow when closed', () => {
	render( <EditorModal /> );
	act( () => {
		document.dispatchEvent( new CustomEvent( 'rs:open-editor' ) );
	} );
	fireEvent.click( screen.getByTestId( 'modal-overlay' ) );
	expect( document.body.style.overflow ).toBe( '' );
} );

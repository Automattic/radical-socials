import { render, screen, fireEvent, act, waitFor } from '@testing-library/react';

jest.mock( './refreshFeed', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

jest.mock( '@wordpress/components', () => ( {
	Modal: ( { onRequestClose, children, className } ) => {
		function handleKeyDown( e ) {
			if ( e.key === 'Escape' ) onRequestClose();
		}
		return (
			// eslint-disable-next-line jsx-a11y/no-noninteractive-element-interactions
			<div
				role="dialog"
				aria-modal="true"
				className={ className }
				data-testid="modal-overlay"
				onKeyDown={ handleKeyDown }
			>
				<button aria-label="Close" onClick={ onRequestClose }>×</button>
				{ children }
			</div>
		);
	},
} ), { virtual: true } );

jest.mock( '@wordpress/i18n', () => ( {
	__: ( str ) => str,
} ), { virtual: true } );

jest.mock( './SocialEditor', () => {
	return {
		__esModule: true,
		default: function MockSocialEditor( { onSuccess, onCancel } ) {
			return (
				<div data-testid="editor-form">
					<button onClick={ onCancel }>Cancel</button>
					<button onClick={ () => onSuccess( { id: 1 } ) }>Simulate Success</button>
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

it( 'closes when the close button is clicked', async () => {
	render( <EditorModal /> );
	act( () => {
		document.dispatchEvent( new CustomEvent( 'rs:open-editor' ) );
	} );
	await waitFor( () => {
		expect( screen.getByRole( 'dialog' ) ).toBeInTheDocument();
	} );
	fireEvent.click( screen.getByRole( 'button', { name: /close/i } ) );
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
	fireEvent.keyDown( screen.getByRole( 'dialog' ), { key: 'Escape' } );
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

it( 'closes when EditorForm calls onSuccess', async () => {
	render( <EditorModal /> );
	act( () => {
		document.dispatchEvent( new CustomEvent( 'rs:open-editor' ) );
	} );
	await waitFor( () => {
		expect( screen.getByRole( 'button', { name: /simulate success/i } ) ).toBeInTheDocument();
	} );
	fireEvent.click( screen.getByRole( 'button', { name: /simulate success/i } ) );
	expect( screen.queryByRole( 'dialog' ) ).not.toBeInTheDocument();
} );

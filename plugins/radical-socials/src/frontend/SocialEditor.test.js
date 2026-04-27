import { render, screen, fireEvent } from '@testing-library/react';
import SocialEditor from './SocialEditor';

jest.mock( '@wordpress/block-editor', () => ( {
	BlockEditorProvider: ( { children } ) => (
		<div data-testid="block-editor">{ children }</div>
	),
	BlockList:     () => <div data-testid="block-list" />,
	BlockToolbar:  () => <div data-testid="block-toolbar" />,
	BlockTools:    ( { children } ) => <>{ children }</>,
	WritingFlow:   ( { children } ) => <>{ children }</>,
	ObserveTyping: ( { children } ) => <>{ children }</>,
} ) );

jest.mock( '@wordpress/blocks', () => ( {
	createBlock: ( name ) => ( {
		clientId:    'test-id',
		name,
		attributes:  { content: 'test content' },  // non-empty so Post button is enabled
		innerBlocks: [],
	} ),
	serialize: () =>
		'<!-- wp:paragraph --><p>Hello world</p><!-- /wp:paragraph -->',
} ) );

jest.mock( './editor-settings', () => ( {
	registerEditorBlocks: jest.fn(),
	getEditorSettings:    jest.fn( () => ( {} ) ),
} ) );

const onSuccess = jest.fn();
const onCancel  = jest.fn();

beforeEach( () => {
	global.window.radicalSocials = {
		nonce:   'test-nonce',
		restUrl: 'http://localhost/wp-json/',
	};
	onSuccess.mockClear();
	onCancel.mockClear();
} );

it( 'renders the block editor', () => {
	render( <SocialEditor onSuccess={ onSuccess } onCancel={ onCancel } /> );
	expect( screen.getByTestId( 'block-editor' ) ).toBeInTheDocument();
} );

it( 'renders hashtags and location inputs', () => {
	render( <SocialEditor onSuccess={ onSuccess } onCancel={ onCancel } /> );
	expect( screen.getByPlaceholderText( /#tags/i    ) ).toBeInTheDocument();
	expect( screen.getByPlaceholderText( /location/i ) ).toBeInTheDocument();
} );

it( 'renders a Post button', () => {
	render( <SocialEditor onSuccess={ onSuccess } onCancel={ onCancel } /> );
	expect( screen.getByRole( 'button', { name: /^post$/i } ) ).toBeInTheDocument();
} );

it( 'calls onCancel when Cancel is clicked', () => {
	render( <SocialEditor onSuccess={ onSuccess } onCancel={ onCancel } /> );
	fireEvent.click( screen.getByRole( 'button', { name: /cancel/i } ) );
	expect( onCancel ).toHaveBeenCalledTimes( 1 );
} );

describe( 'REST submission', () => {
	beforeEach( () => {
		global.fetch = jest.fn();
	} );
	afterEach( () => {
		jest.restoreAllMocks();
	} );

	it( 'POSTs to /wp/v2/instagram-posts with serialized content and metadata', async () => {
		const post = { id: 42, content: { rendered: '<p>Hello world</p>' } };
		fetch.mockResolvedValueOnce( { ok: true, json: async () => post } );

		render( <SocialEditor onSuccess={ onSuccess } onCancel={ onCancel } /> );
		fireEvent.change( screen.getByPlaceholderText( /#tags/i    ), {
			target: { value: 'cats dogs' },
		} );
		fireEvent.change( screen.getByPlaceholderText( /location/i ), {
			target: { value: 'London' },
		} );
		fireEvent.click( screen.getByRole( 'button', { name: /^post$/i } ) );

		await screen.findByRole( 'button', { name: /^post$/i } );

		expect( fetch ).toHaveBeenCalledWith(
			'http://localhost/wp-json/wp/v2/instagram-posts',
			expect.objectContaining( {
				method:  'POST',
				headers: expect.objectContaining( {
					'Content-Type': 'application/json',
					'X-WP-Nonce':  'test-nonce',
				} ),
				body: JSON.stringify( {
					status:  'publish',
					content: '<!-- wp:paragraph --><p>Hello world</p><!-- /wp:paragraph -->',
					meta: {
						_instagram_tags:     'cats dogs',
						_instagram_location: 'London',
					},
				} ),
			} )
		);
	} );

	it( 'calls onSuccess with the returned post', async () => {
		const post = { id: 42, content: { rendered: '<p>Hello world</p>' } };
		fetch.mockResolvedValueOnce( { ok: true, json: async () => post } );

		render( <SocialEditor onSuccess={ onSuccess } onCancel={ onCancel } /> );
		fireEvent.click( screen.getByRole( 'button', { name: /^post$/i } ) );

		await screen.findByRole( 'button', { name: /^post$/i } );

		expect( onSuccess ).toHaveBeenCalledWith( post );
	} );

	it( 'resets hashtags and location after a successful post', async () => {
		const post = { id: 42, content: { rendered: '<p>Hello</p>' } };
		fetch.mockResolvedValueOnce( { ok: true, json: async () => post } );

		render( <SocialEditor onSuccess={ onSuccess } onCancel={ onCancel } /> );
		fireEvent.change( screen.getByPlaceholderText( /#tags/i    ), {
			target: { value: 'cats' },
		} );
		fireEvent.change( screen.getByPlaceholderText( /location/i ), {
			target: { value: 'London' },
		} );
		fireEvent.click( screen.getByRole( 'button', { name: /^post$/i } ) );

		await screen.findByRole( 'button', { name: /^post$/i } );

		expect( screen.getByPlaceholderText( /#tags/i    ).value ).toBe( '' );
		expect( screen.getByPlaceholderText( /location/i ).value ).toBe( '' );
	} );

	it( 'shows an error message when the request fails', async () => {
		fetch.mockResolvedValueOnce( { ok: false } );

		render( <SocialEditor onSuccess={ onSuccess } onCancel={ onCancel } /> );
		fireEvent.click( screen.getByRole( 'button', { name: /^post$/i } ) );

		expect( await screen.findByText( /post creation failed/i ) ).toBeInTheDocument();
	} );

	it( 'disables the Post button while submitting', async () => {
		let resolveRequest;
		fetch.mockReturnValueOnce(
			new Promise( ( res ) => {
				resolveRequest = res;
			} )
		);

		render( <SocialEditor onSuccess={ onSuccess } onCancel={ onCancel } /> );
		fireEvent.click( screen.getByRole( 'button', { name: /^post$/i } ) );

		expect( screen.getByRole( 'button', { name: /posting/i } ) ).toBeDisabled();

		resolveRequest( { ok: true, json: async () => ( { id: 1 } ) } );
		await screen.findByRole( 'button', { name: /^post$/i } );
	} );
} );

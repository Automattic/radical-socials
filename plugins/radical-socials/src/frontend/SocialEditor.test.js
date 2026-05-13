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

jest.mock( '@wordpress/data', () => ( {
	useSelect: jest.fn( ( mapSelect ) => mapSelect( ( storeName ) => {
		if ( storeName === 'core/block-editor' ) {
			return {
				getBlockOrder:            () => [ 'test-client-id' ],
				getSelectedBlockClientId: () => null,
			};
		}
		return {};
	} ) ),
	useDispatch: jest.fn( () => ( {
		selectBlock:  jest.fn(),
		insertBlocks: jest.fn(),
	} ) ),
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

it( 'renders hashtags input', () => {
	render( <SocialEditor onSuccess={ onSuccess } onCancel={ onCancel } /> );
	expect( screen.getByPlaceholderText( /#tags/i ) ).toBeInTheDocument();
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

	it( 'POSTs to /wp/v2/posts with serialized content', async () => {
		const post = { id: 42, content: { rendered: '<p>Hello world</p>' } };
		fetch.mockResolvedValueOnce( { ok: true, json: async () => post } );

		render( <SocialEditor onSuccess={ onSuccess } onCancel={ onCancel } /> );
		fireEvent.click( screen.getByRole( 'button', { name: /^post$/i } ) );

		await screen.findByRole( 'button', { name: /^post$/i } );

		expect( fetch ).toHaveBeenCalledWith(
			'http://localhost/wp-json/wp/v2/posts',
			expect.objectContaining( {
				method:  'POST',
				headers: expect.objectContaining( {
					'Content-Type': 'application/json',
					'X-WP-Nonce':  'test-nonce',
				} ),
				body: JSON.stringify( {
					status:  'publish',
					content: '<!-- wp:paragraph --><p>Hello world</p><!-- /wp:paragraph -->',
				} ),
			} )
		);
	} );

	it( 'resolves hashtags to term IDs and includes them in the post', async () => {
		fetch
			.mockResolvedValueOnce( { ok: true, json: async () => [] } )                          // GET tags?slug=cats → not found
			.mockResolvedValueOnce( { ok: true, json: async () => ( { id: 1, slug: 'cats' } ) } ) // POST tags → created
			.mockResolvedValueOnce( { ok: true, json: async () => [ { id: 2, slug: 'dogs' } ] } ) // GET tags?slug=dogs → found
			.mockResolvedValueOnce( { ok: true, json: async () => ( { id: 99 } ) } );              // POST posts

		render( <SocialEditor onSuccess={ onSuccess } onCancel={ onCancel } /> );
		fireEvent.change( screen.getByPlaceholderText( /#tags/i ), {
			target: { value: '#cats #dogs' },
		} );
		fireEvent.click( screen.getByRole( 'button', { name: /^post$/i } ) );

		await screen.findByRole( 'button', { name: /^post$/i } );

		const postCall = fetch.mock.calls.find(
			( [ url ] ) => url.includes( '/wp/v2/posts' ) && ! url.includes( 'tags' )
		);
		expect( JSON.parse( postCall[ 1 ].body ) ).toMatchObject( {
			'tags': [ 1, 2 ],
		} );
	} );

	it( 'calls onSuccess with the returned post', async () => {
		const post = { id: 42, content: { rendered: '<p>Hello world</p>' } };
		fetch.mockResolvedValueOnce( { ok: true, json: async () => post } );

		render( <SocialEditor onSuccess={ onSuccess } onCancel={ onCancel } /> );
		fireEvent.click( screen.getByRole( 'button', { name: /^post$/i } ) );

		await screen.findByRole( 'button', { name: /^post$/i } );

		expect( onSuccess ).toHaveBeenCalledWith( post );
	} );

	it( 'resets hashtags after a successful post', async () => {
		const post = { id: 42, content: { rendered: '<p>Hello</p>' } };
		fetch
			.mockResolvedValueOnce( { ok: true, json: async () => [ { id: 1 } ] } ) // GET tags?slug=cats
			.mockResolvedValueOnce( { ok: true, json: async () => post } );           // POST posts

		render( <SocialEditor onSuccess={ onSuccess } onCancel={ onCancel } /> );
		fireEvent.change( screen.getByPlaceholderText( /#tags/i ), {
			target: { value: 'cats' },
		} );
		fireEvent.click( screen.getByRole( 'button', { name: /^post$/i } ) );

		await screen.findByRole( 'button', { name: /^post$/i } );

		expect( screen.getByPlaceholderText( /#tags/i ).value ).toBe( '' );
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

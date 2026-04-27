import { render, screen, fireEvent } from '@testing-library/react';
import EditorForm from './EditorForm';

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

describe( 'type picker', () => {
	it( 'renders all three type buttons', () => {
		render( <EditorForm onSuccess={ onSuccess } onCancel={ onCancel } /> );
		expect( screen.getByRole( 'button', { name: /^text$/i   } ) ).toBeInTheDocument();
		expect( screen.getByRole( 'button', { name: /^photo$/i  } ) ).toBeInTheDocument();
		expect( screen.getByRole( 'button', { name: /^video$/i  } ) ).toBeInTheDocument();
	} );

	it( 'marks the selected type button as active', () => {
		render( <EditorForm onSuccess={ onSuccess } onCancel={ onCancel } /> );
		fireEvent.click( screen.getByRole( 'button', { name: /^photo$/i } ) );
		expect( screen.getByRole( 'button', { name: /^photo$/i } ) ).toHaveClass( 'is-active' );
		expect( screen.getByRole( 'button', { name: /^text$/i  } ) ).not.toHaveClass( 'is-active' );
	} );
} );

describe( 'form fields by media type', () => {
	function selectType( type ) {
		render( <EditorForm onSuccess={ onSuccess } onCancel={ onCancel } /> );
		fireEvent.click( screen.getByRole( 'button', { name: new RegExp( `^${ type }$`, 'i' ) } ) );
	}

	it( 'shows caption, tags, and location after selecting Text', () => {
		selectType( 'text' );
		expect( screen.getByPlaceholderText( /write a caption/i ) ).toBeInTheDocument();
		expect( screen.getByPlaceholderText( /#tags/i            ) ).toBeInTheDocument();
		expect( screen.getByPlaceholderText( /location/i         ) ).toBeInTheDocument();
	} );

	it( 'shows file upload + caption + tags + location after selecting Photo', () => {
		selectType( 'photo' );
		expect( screen.getByLabelText(       /upload photo/i     ) ).toBeInTheDocument();
		expect( screen.getByPlaceholderText( /write a caption/i  ) ).toBeInTheDocument();
		expect( screen.getByPlaceholderText( /#tags/i            ) ).toBeInTheDocument();
		expect( screen.getByPlaceholderText( /location/i         ) ).toBeInTheDocument();
	} );

	it( 'shows file upload after selecting Video', () => {
		selectType( 'video' );
		expect( screen.getByLabelText( /upload video/i ) ).toBeInTheDocument();
	} );

	it( 'does not show file upload for Text type', () => {
		selectType( 'text' );
		expect( screen.queryByLabelText( /upload photo/i ) ).not.toBeInTheDocument();
		expect( screen.queryByLabelText( /upload video/i ) ).not.toBeInTheDocument();
	} );
} );

describe( 'cancel', () => {
	it( 'calls onCancel when Cancel is clicked', () => {
		render( <EditorForm onSuccess={ onSuccess } onCancel={ onCancel } /> );
		fireEvent.click( screen.getByRole( 'button', { name: /cancel/i } ) );
		expect( onCancel ).toHaveBeenCalledTimes( 1 );
	} );
} );

describe( 'REST submission', () => {
	beforeEach( () => {
		global.fetch = jest.fn();
	} );
	afterEach( () => {
		jest.restoreAllMocks();
	} );

	it( 'disables Post button when caption is empty', () => {
		render( <EditorForm onSuccess={ onSuccess } onCancel={ onCancel } /> );
		fireEvent.click( screen.getByRole( 'button', { name: /^text$/i } ) );
		expect( screen.getByRole( 'button', { name: /^post$/i } ) ).toBeDisabled();
	} );

	it( 'enables Post button when caption has content', () => {
		render( <EditorForm onSuccess={ onSuccess } onCancel={ onCancel } /> );
		fireEvent.click( screen.getByRole( 'button', { name: /^text$/i } ) );
		fireEvent.change( screen.getByPlaceholderText( /write a caption/i ), {
			target: { value: 'Hello world' },
		} );
		expect( screen.getByRole( 'button', { name: /^post$/i } ) ).not.toBeDisabled();
	} );

	it( 'POSTs to /wp/v2/instagram-posts and calls onSuccess with the returned post', async () => {
		const post = {
			id:      42,
			content: { rendered: '<p>Hello world</p>' },
			date:    '2026-04-27T00:00:00',
			meta:    { _instagram_location: '', _instagram_media_type: 'text', _instagram_tags: '' },
		};
		fetch.mockResolvedValueOnce( { ok: true, json: async () => post } );

		render( <EditorForm onSuccess={ onSuccess } onCancel={ onCancel } /> );
		fireEvent.click( screen.getByRole( 'button', { name: /^text$/i } ) );
		fireEvent.change( screen.getByPlaceholderText( /write a caption/i ), {
			target: { value: 'Hello world' },
		} );
		fireEvent.click( screen.getByRole( 'button', { name: /^post$/i } ) );

		// Wait for async submission to complete (form resets mediaType to null — Cancel reappears)
		await screen.findByRole( 'button', { name: /cancel/i } );

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
					content: 'Hello world',
					meta: {
						_instagram_location:   '',
						_instagram_media_type: 'text',
						_instagram_tags:       '',
					},
				} ),
			} )
		);
		expect( onSuccess ).toHaveBeenCalledWith( post );
	} );

	it( 'shows an error message when the request fails', async () => {
		fetch.mockResolvedValueOnce( { ok: false } );

		render( <EditorForm onSuccess={ onSuccess } onCancel={ onCancel } /> );
		fireEvent.click( screen.getByRole( 'button', { name: /^text$/i } ) );
		fireEvent.change( screen.getByPlaceholderText( /write a caption/i ), {
			target: { value: 'Hello world' },
		} );
		fireEvent.click( screen.getByRole( 'button', { name: /^post$/i } ) );

		expect( await screen.findByText( /post creation failed/i ) ).toBeInTheDocument();
	} );
} );

# Modal Editor Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a Tumblr-style centred modal composer triggered by the custom bar's Create button, while keeping the inline editor available for themes that opt in via `#radical-socials-editor`.

**Architecture:** Extract `EditorForm` (shared form logic) from `FrontendEditor`, then build `EditorModal` that listens for the `rs:open-editor` DOM event. `frontend.js` always mounts `EditorModal` into a plugin-owned div and conditionally mounts `FrontendEditor` into `#radical-socials-editor` if the theme provides it. The custom bar's Create link dispatches `rs:open-editor` on click.

**Tech Stack:** React via `@wordpress/element`, `@testing-library/react`, Jest, WordPress REST API, PHP.

---

## File Map

| File | Action | Responsibility |
|------|--------|----------------|
| `radical-socials/src/frontend/EditorForm.js` | Create | Type picker, form fields, REST submission; calls `onSuccess(post)` or `onCancel()` |
| `radical-socials/src/frontend/EditorForm.test.js` | Create | Unit tests for form logic |
| `radical-socials/src/frontend/EditorModal.js` | Create | Centred dialog; listens for `rs:open-editor`; wraps `EditorForm` |
| `radical-socials/src/frontend/EditorModal.test.js` | Create | Open/close/overlay/Escape tests |
| `radical-socials/src/frontend/FrontendEditor.js` | Modify | Remove form logic; render `EditorForm` when expanded |
| `radical-socials/src/frontend/FrontendEditor.test.js` | Modify | Mock `EditorForm`; test only idle/expand/collapse chrome + new-post display |
| `radical-socials/src/frontend.js` | Modify | Mount `EditorModal` always; mount `FrontendEditor` conditionally; wire click listener |
| `radical-socials/src/frontend/frontend.css` | Modify | Add modal overlay and dialog styles |
| `radical-socials/modules/custom-bar/class-custom-bar.php` | Modify | Add `data-rs-action="open-editor"` to the Create `<a>` |

---

### Task 1: Extract EditorForm

**Files:**
- Create: `radical-socials/src/frontend/EditorForm.js`
- Create: `radical-socials/src/frontend/EditorForm.test.js`

- [ ] **Step 1: Write the failing tests**

Create `radical-socials/src/frontend/EditorForm.test.js`:

```jsx
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
```

- [ ] **Step 2: Run tests to confirm they fail**

```bash
cd /Users/sarahnorris/Source/Automattic/wp-dev/plugins/radical-socials
npm test -- --testPathPattern=EditorForm --watchAll=false
```

Expected: FAIL — `Cannot find module './EditorForm'`

- [ ] **Step 3: Create EditorForm.js**

Create `radical-socials/src/frontend/EditorForm.js`:

```jsx
import { useState } from '@wordpress/element';

const TYPES = [ 'text', 'photo', 'video' ];

export default function EditorForm( { onSuccess, onCancel } ) {
	const [ mediaType,  setMediaType  ] = useState( null );
	const [ caption,    setCaption    ] = useState( '' );
	const [ tags,       setTags       ] = useState( '' );
	const [ location,   setLocation   ] = useState( '' );
	const [ file,       setFile       ] = useState( null );
	const [ submitting, setSubmitting ] = useState( false );
	const [ error,      setError      ] = useState( null );

	const { nonce, restUrl } = window.radicalSocials;

	function reset() {
		setMediaType( null );
		setCaption( '' );
		setTags( '' );
		setLocation( '' );
		setFile( null );
		setError( null );
	}

	function handleCancel() {
		reset();
		onCancel();
	}

	async function handleSubmit( e ) {
		e.preventDefault();
		setSubmitting( true );
		setError( null );

		try {
			let featuredMedia = null;

			if ( file ) {
				const mediaRes = await fetch( `${ restUrl }wp/v2/media`, {
					method:  'POST',
					headers: {
						'X-WP-Nonce':          nonce,
						'Content-Disposition': `attachment; filename="${ file.name }"`,
						'Content-Type':        file.type,
					},
					body: file,
				} );
				if ( ! mediaRes.ok ) throw new Error( 'Media upload failed' );
				const mediaData = await mediaRes.json();
				featuredMedia = mediaData.id;
			}

			const body = {
				status:  'publish',
				content: caption,
				meta: {
					_instagram_location:   location,
					_instagram_media_type: mediaType,
					_instagram_tags:       tags,
				},
			};
			if ( featuredMedia ) body.featured_media = featuredMedia;

			const postRes = await fetch( `${ restUrl }wp/v2/instagram-posts`, {
				method:  'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce':  nonce,
				},
				body: JSON.stringify( body ),
			} );

			if ( ! postRes.ok ) throw new Error( 'Post creation failed' );
			const post = await postRes.json();
			reset();
			onSuccess( post );
		} catch ( err ) {
			setError( err.message );
		} finally {
			setSubmitting( false );
		}
	}

	return (
		<form className="rs-editor-form" onSubmit={ handleSubmit }>
			<div className="rs-editor-types">
				{ TYPES.map( ( type ) => (
					<button
						key={ type }
						type="button"
						className={ `rs-type-btn${ mediaType === type ? ' is-active' : '' }` }
						onClick={ () => setMediaType( type ) }
					>
						{ type.charAt( 0 ).toUpperCase() + type.slice( 1 ) }
					</button>
				) ) }
			</div>

			{ mediaType && (
				<>
					{ ( mediaType === 'photo' || mediaType === 'video' ) && (
						<label className="rs-upload-label">
							{ `Upload ${ mediaType.charAt( 0 ).toUpperCase() + mediaType.slice( 1 ) }` }
							<input
								type="file"
								accept={ mediaType === 'photo' ? 'image/*' : 'video/*' }
								onChange={ ( e ) => setFile( e.target.files[ 0 ] ?? null ) }
							/>
						</label>
					) }

					<textarea
						className="rs-caption"
						placeholder="Write a caption…"
						value={ caption }
						onChange={ ( e ) => setCaption( e.target.value ) }
					/>

					<input
						className="rs-tags"
						type="text"
						placeholder="#tags"
						value={ tags }
						onChange={ ( e ) => setTags( e.target.value ) }
					/>

					<input
						className="rs-location"
						type="text"
						placeholder="Location"
						value={ location }
						onChange={ ( e ) => setLocation( e.target.value ) }
					/>

					{ error && <p className="rs-error">{ error }</p> }

					<div className="rs-editor-actions">
						<button type="button" onClick={ handleCancel } disabled={ submitting }>
							Cancel
						</button>
						<button type="submit" disabled={ submitting || ! caption.trim() }>
							{ submitting ? 'Posting…' : 'Post' }
						</button>
					</div>
				</>
			) }

			{ ! mediaType && (
				<div className="rs-editor-actions">
					<button type="button" onClick={ handleCancel }>Cancel</button>
				</div>
			) }
		</form>
	);
}
```

- [ ] **Step 4: Run tests to confirm they pass**

```bash
npm test -- --testPathPattern=EditorForm --watchAll=false
```

Expected: PASS — all EditorForm tests green

- [ ] **Step 5: Commit**

```bash
cd /Users/sarahnorris/Source/Automattic/wp-dev/plugins/radical-socials
git add radical-socials/src/frontend/EditorForm.js radical-socials/src/frontend/EditorForm.test.js
git commit -m "feat: extract EditorForm component with tests"
```

---

### Task 2: Refactor FrontendEditor to use EditorForm

**Files:**
- Modify: `radical-socials/src/frontend/FrontendEditor.js`
- Modify: `radical-socials/src/frontend/FrontendEditor.test.js`

- [ ] **Step 1: Replace FrontendEditor.test.js**

Replace the entire contents of `radical-socials/src/frontend/FrontendEditor.test.js` with:

```jsx
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
```

- [ ] **Step 2: Run tests to confirm some fail**

```bash
npm test -- --testPathPattern=FrontendEditor --watchAll=false
```

Expected: some tests FAIL because `FrontendEditor` still renders its own form internals (not yet using `EditorForm`)

- [ ] **Step 3: Replace FrontendEditor.js**

Replace the entire contents of `radical-socials/src/frontend/FrontendEditor.js` with:

```jsx
import { useState } from '@wordpress/element';
import EditorForm from './EditorForm';
import './frontend.css';

export default function FrontendEditor() {
	const [ expanded, setExpanded ] = useState( false );
	const [ newPosts, setNewPosts ] = useState( [] );

	function collapse() {
		setExpanded( false );
	}

	function handleSuccess( post ) {
		setNewPosts( ( prev ) => [ post, ...prev ] );
		collapse();
	}

	return (
		<div className="rs-frontend-editor">
			{ newPosts.map( ( post ) => (
				<div
					key={ post.id }
					className="rs-new-post"
					dangerouslySetInnerHTML={ { __html: post.content.rendered } }
				/>
			) ) }

			{ ! expanded && (
				<button className="rs-editor-prompt" onClick={ () => setExpanded( true ) }>
					What&apos;s on your mind?
				</button>
			) }

			{ expanded && (
				<EditorForm onSuccess={ handleSuccess } onCancel={ collapse } />
			) }
		</div>
	);
}
```

- [ ] **Step 4: Run tests to confirm they pass**

```bash
npm test -- --testPathPattern=FrontendEditor --watchAll=false
```

Expected: PASS — all 6 FrontendEditor tests green

- [ ] **Step 5: Run all tests to confirm nothing broke**

```bash
npm test -- --watchAll=false
```

Expected: PASS — all tests (EditorForm + FrontendEditor) green

- [ ] **Step 6: Commit**

```bash
git add radical-socials/src/frontend/FrontendEditor.js radical-socials/src/frontend/FrontendEditor.test.js
git commit -m "refactor: FrontendEditor delegates form logic to EditorForm"
```

---

### Task 3: Create EditorModal

**Files:**
- Create: `radical-socials/src/frontend/EditorModal.js`
- Create: `radical-socials/src/frontend/EditorModal.test.js`

- [ ] **Step 1: Write the failing tests**

Create `radical-socials/src/frontend/EditorModal.test.js`:

```jsx
import { render, screen, fireEvent, act } from '@testing-library/react';
import EditorModal from './EditorModal';

jest.mock( './EditorForm', () => {
	return function MockEditorForm( { onCancel } ) {
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
	fireEvent.click( screen.getByRole( 'dialog' ) );
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
	fireEvent.click( screen.getByRole( 'dialog' ) );
	expect( document.body.style.overflow ).toBe( '' );
} );
```

- [ ] **Step 2: Run tests to confirm they fail**

```bash
npm test -- --testPathPattern=EditorModal --watchAll=false
```

Expected: FAIL — `Cannot find module './EditorModal'`

- [ ] **Step 3: Create EditorModal.js**

Create `radical-socials/src/frontend/EditorModal.js`:

```jsx
import { useState, useEffect, useCallback } from '@wordpress/element';
import EditorForm from './EditorForm';

export default function EditorModal() {
	const [ open, setOpen ] = useState( false );

	const openModal  = useCallback( () => setOpen( true  ), [] );
	const closeModal = useCallback( () => setOpen( false ), [] );

	useEffect( () => {
		document.addEventListener( 'rs:open-editor', openModal );
		return () => document.removeEventListener( 'rs:open-editor', openModal );
	}, [ openModal ] );

	useEffect( () => {
		if ( ! open ) return;
		function onKeyDown( e ) {
			if ( e.key === 'Escape' ) closeModal();
		}
		document.addEventListener( 'keydown', onKeyDown );
		return () => document.removeEventListener( 'keydown', onKeyDown );
	}, [ open, closeModal ] );

	useEffect( () => {
		document.body.style.overflow = open ? 'hidden' : '';
		return () => {
			document.body.style.overflow = '';
		};
	}, [ open ] );

	if ( ! open ) return null;

	return (
		<div
			className="rs-modal-overlay"
			role="dialog"
			aria-modal="true"
			onClick={ closeModal }
		>
			<div
				className="rs-modal-dialog"
				onClick={ ( e ) => e.stopPropagation() }
			>
				<EditorForm onSuccess={ closeModal } onCancel={ closeModal } />
			</div>
		</div>
	);
}
```

- [ ] **Step 4: Run tests to confirm they pass**

```bash
npm test -- --testPathPattern=EditorModal --watchAll=false
```

Expected: PASS — all 8 EditorModal tests green

- [ ] **Step 5: Commit**

```bash
git add radical-socials/src/frontend/EditorModal.js radical-socials/src/frontend/EditorModal.test.js
git commit -m "feat: add EditorModal component with tests"
```

---

### Task 4: Add modal CSS

**Files:**
- Modify: `radical-socials/src/frontend/frontend.css`

- [ ] **Step 1: Append modal styles to frontend.css**

Open `radical-socials/src/frontend/frontend.css` and append at the end:

```css
/* Modal overlay */
.rs-modal-overlay {
	position: fixed;
	inset: 0;
	background: rgba( 0, 0, 0, 0.55 );
	z-index: 9999;
	display: flex;
	align-items: center;
	justify-content: center;
}

/* Modal dialog */
.rs-modal-dialog {
	background: #fff;
	border-radius: 10px;
	padding: 1.5rem;
	width: min( 480px, 92vw );
	box-shadow: 0 8px 32px rgba( 0, 0, 0, 0.18 );
	max-height: 90vh;
	overflow-y: auto;
}
```

- [ ] **Step 2: Run all tests to confirm nothing broke**

```bash
npm test -- --watchAll=false
```

Expected: PASS — all tests green

- [ ] **Step 3: Commit**

```bash
git add radical-socials/src/frontend/frontend.css
git commit -m "style: add modal overlay and dialog styles"
```

---

### Task 5: Update frontend.js bootstrap

**Files:**
- Modify: `radical-socials/src/frontend.js`

- [ ] **Step 1: Replace frontend.js**

Replace the entire contents of `radical-socials/src/frontend.js` with:

```jsx
import { createRoot } from '@wordpress/element';
import EditorModal    from './frontend/EditorModal';
import FrontendEditor from './frontend/FrontendEditor';

// Always mount modal into a plugin-owned root div
const modalRoot = document.createElement( 'div' );
modalRoot.id = 'rs-modal-root';
document.body.appendChild( modalRoot );
createRoot( modalRoot ).render( <EditorModal /> );

// Mount inline editor only if the theme provides the mount point
const inlineRoot = document.getElementById( 'radical-socials-editor' );
if ( inlineRoot ) {
	createRoot( inlineRoot ).render( <FrontendEditor /> );
}

// Wire custom bar Create button (and any future [data-rs-action="open-editor"] elements)
document.addEventListener( 'click', ( e ) => {
	const trigger = e.target.closest( '[data-rs-action="open-editor"]' );
	if ( trigger ) {
		e.preventDefault();
		document.dispatchEvent( new CustomEvent( 'rs:open-editor' ) );
	}
} );
```

- [ ] **Step 2: Build the frontend bundle**

```bash
npm run build
```

Expected: build completes with no errors, `radical-socials/build/frontend.js` and `radical-socials/build/frontend.css` are updated

- [ ] **Step 3: Commit**

```bash
git add radical-socials/src/frontend.js
git commit -m "feat: update frontend bootstrap to mount EditorModal always and inline editor conditionally"
```

---

### Task 6: Wire custom bar Create button

**Files:**
- Modify: `radical-socials/modules/custom-bar/class-custom-bar.php`

- [ ] **Step 1: Add data-rs-action attribute to the Create link**

In `radical-socials/modules/custom-bar/class-custom-bar.php`, find line 109 and change:

```php
					<a href="#" class="rs-bar-link" aria-label="<?php esc_attr_e( 'Create', 'radical-socials' ); ?>">
```

to:

```php
					<a href="#" class="rs-bar-link" data-rs-action="open-editor" aria-label="<?php esc_attr_e( 'Create', 'radical-socials' ); ?>">
```

- [ ] **Step 2: Run all tests to confirm nothing broke**

```bash
npm test -- --watchAll=false
```

Expected: PASS — all tests green (PHP change has no JS test impact)

- [ ] **Step 3: Build**

```bash
npm run build
```

Expected: clean build

- [ ] **Step 4: Manual smoke test**

Start the playground environment:

```bash
npm run playground:start
```

Open `http://localhost:8890` while logged in. Confirm:
1. Clicking the Create (plus) button in the custom bar opens the centred modal dialog
2. The modal has a dark overlay behind it
3. Clicking the overlay closes the modal
4. Pressing Escape closes the modal
5. Selecting a post type, filling in a caption, and hitting Post submits successfully and closes the modal
6. If the theme template has `<div id="radical-socials-editor">`, the inline editor still renders there

- [ ] **Step 5: Commit**

```bash
git add radical-socials/modules/custom-bar/class-custom-bar.php
git commit -m "feat: wire custom bar Create button to open EditorModal"
```

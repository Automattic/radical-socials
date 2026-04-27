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
		if ( ! open ) return;
		document.body.style.overflow = 'hidden';
		return () => {
			document.body.style.overflow = '';
		};
	}, [ open ] );

	if ( ! open ) return null;

	return (
		<div
			className="rs-modal-overlay"
			data-testid="modal-overlay"
			onClick={ closeModal }
		>
			<div
				className="rs-modal-dialog"
				role="dialog"
				aria-modal="true"
				aria-label="Create post"
				onClick={ ( e ) => e.stopPropagation() }
			>
				<EditorForm onSuccess={ closeModal } onCancel={ closeModal } />
			</div>
		</div>
	);
}

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

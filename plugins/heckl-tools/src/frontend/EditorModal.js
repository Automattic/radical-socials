import { useState, useEffect, useCallback, lazy, Suspense } from '@wordpress/element';
import { Modal } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import refreshFeed from './refreshFeed';

const SocialEditor = lazy( () => import( './SocialEditor' ) );

export default function EditorModal() {
	const [ open, setOpen ] = useState( false );

	const openModal  = useCallback( () => setOpen( true  ), [] );
	const closeModal = useCallback( () => setOpen( false ), [] );

	useEffect( () => {
		document.addEventListener( 'rs:open-editor', openModal );
		return () => document.removeEventListener( 'rs:open-editor', openModal );
	}, [ openModal ] );

	if ( ! open ) return null;

	return (
		<Modal
			title={ __( 'Create post', 'heckl-tools' ) }
			onRequestClose={ closeModal }
			className="rs-modal-dialog"
		>
			<Suspense fallback={ <div className="rs-editor-loading" /> }>
				<SocialEditor
					onSuccess={ () => { closeModal(); refreshFeed(); } }
					onCancel={ closeModal }
				/>
			</Suspense>
		</Modal>
	);
}

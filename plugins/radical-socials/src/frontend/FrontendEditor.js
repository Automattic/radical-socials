import { useState, lazy, Suspense } from '@wordpress/element';
import './frontend.css';

const SocialEditor = lazy( () => import( './SocialEditor' ) );

export default function FrontendEditor() {
	const [ expanded, setExpanded ] = useState( false );

	function collapse() {
		setExpanded( false );
	}

	function handleSuccess() {
		window.location.reload();
	}

	return (
		<div className="rs-frontend-editor">
			{ ! expanded && (
				<button className="rs-editor-prompt" onClick={ () => setExpanded( true ) }>
					What&apos;s on your mind?
				</button>
			) }

			{ expanded && (
				<Suspense fallback={ <div className="rs-editor-loading" /> }>
					<SocialEditor onSuccess={ handleSuccess } onCancel={ collapse } />
				</Suspense>
			) }
		</div>
	);
}

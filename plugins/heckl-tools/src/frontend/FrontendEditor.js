import { useState, lazy, Suspense } from '@wordpress/element';
import refreshFeed from './refreshFeed';
import './frontend.css';

const SocialEditor = lazy( () => import( './SocialEditor' ) );

export default function FrontendEditor() {
	const [ expanded, setExpanded ] = useState( false );

	function collapse() {
		setExpanded( false );
	}

	function handleSuccess() {
		collapse();
		refreshFeed();
	}

	return (
		<div className="heckl-frontend-editor">
			{ ! expanded && (
				<button className="heckl-editor-prompt" onClick={ () => setExpanded( true ) }>
					What&apos;s on your mind?
				</button>
			) }

			{ expanded && (
				<Suspense fallback={ <div className="heckl-editor-loading" /> }>
					<SocialEditor onSuccess={ handleSuccess } onCancel={ collapse } />
				</Suspense>
			) }
		</div>
	);
}

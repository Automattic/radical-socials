import { useState, lazy, Suspense } from '@wordpress/element';
import refreshFeed from './refreshFeed';
import './frontend.css';

const SocialEditor = lazy( () => import( './SocialEditor' ) );

export default function FrontendEditor() {
	const [ expanded, setExpanded ] = useState( false );

	function collapse() {
		setExpanded( false );
	}

	async function handleSuccess() {
		collapse();
		await refreshFeed();
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

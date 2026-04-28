import { useState, lazy, Suspense } from '@wordpress/element';
import './frontend.css';

const SocialEditor = lazy( () => import( './SocialEditor' ) );

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
				<Suspense fallback={ <div className="rs-editor-loading" /> }>
					<SocialEditor onSuccess={ handleSuccess } onCancel={ collapse } />
				</Suspense>
			) }
		</div>
	);
}

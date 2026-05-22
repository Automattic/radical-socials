import { store, getContext } from '@wordpress/interactivity';

const { state } = store( 'radical-socials/like-button', {
	actions: {
		*toggle() {
			const ctx    = getContext();
			const before = ctx.favorited;
			ctx.favorited = ! before; // optimistic update

			try {
				const res = yield fetch( state.toggleUrl, {
					method:  'POST',
					headers: {
						'X-WP-Nonce':   state.nonce,
						'Content-Type': 'application/json',
					},
					body: JSON.stringify( { post_id: ctx.postId } ),
				} );
				if ( ! res.ok ) {
					ctx.favorited = before; // revert on server error
				}
			} catch {
				ctx.favorited = before; // revert on network error
			}
		},
	},
} );

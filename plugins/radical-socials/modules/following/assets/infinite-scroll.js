import { store, getContext, getElement } from '@wordpress/interactivity';

const PULL_THRESHOLD = 80; // px of overscroll needed to trigger a refresh

const { state, actions } = store( 'radical-socials/following', {
	actions: {
		*refresh() {
			if ( state.refreshing ) return;
			state.refreshing = true;
			state.pulling    = false;

			// Ask the server to schedule a background fetch. The endpoint returns
			// 202 immediately — the actual feed fetching happens in a separate
			// wp-cron worker process so we never block here.
			try {
				yield fetch( state.refreshUrl, {
					method:  'POST',
					headers: { 'X-WP-Nonce': state.nonce },
				} );
			} catch {
				// Continue to reload even on network error.
			}

			// Brief pause so the spinner is visible, then reload to pick up
			// any items that arrived since the last fetch.
			yield new Promise( ( resolve ) => setTimeout( resolve, 1500 ) );
			window.location.reload();
		},
	},

	callbacks: {
		initPullToRefresh() {
			const { ref } = getElement();
			let startY       = 0;
			let trackingPull = false;

			// Mobile pull-to-refresh gesture.
			ref.addEventListener( 'touchstart', ( e ) => {
				if ( window.scrollY !== 0 ) return;
				startY       = e.touches[ 0 ].clientY;
				trackingPull = true;
			}, { passive: true } );

			ref.addEventListener( 'touchmove', ( e ) => {
				if ( ! trackingPull ) return;
				const distance = e.touches[ 0 ].clientY - startY;
				if ( distance > 0 && window.scrollY === 0 ) {
					state.pulling = distance >= PULL_THRESHOLD;
				} else {
					trackingPull  = false;
					state.pulling = false;
				}
			}, { passive: true } );

			ref.addEventListener( 'touchend', () => {
				if ( state.pulling ) {
					actions.refresh();
				}
				trackingPull  = false;
				state.pulling = false;
			}, { passive: true } );
		},

		observeSentinel() {
			const context = getContext();
			const { ref } = getElement();

			const observer = new IntersectionObserver(
				async ( [ entry ] ) => {
					if ( ! entry.isIntersecting ) return;
					if ( context.loading || context.page >= context.maxPages ) {
						observer.disconnect();
						return;
					}

					context.loading = true;
					const nextPage = context.page + 1;
					const url = new URL( window.location.href );
					url.searchParams.set( `query-${ context.queryId }-page`, nextPage );

					try {
						const res = await fetch( url.toString() );
						const text = await res.text();
						const doc = new DOMParser().parseFromString( text, 'text/html' );
						const newItems = doc.querySelectorAll( '.rs-following-feed .wp-block-post' );
						const list = document.querySelector( '.rs-following-feed .wp-block-post-template' );

						if ( newItems.length && list ) {
							newItems.forEach( ( item ) => list.appendChild( item.cloneNode( true ) ) );
							context.page = nextPage;
						} else {
							observer.disconnect();
						}
					} catch {
						// Network error — leave page unchanged so the observer can retry.
					}

					context.loading = false;
				},
				{ rootMargin: '400px' }
			);

			observer.observe( ref );
		},
	},
} );

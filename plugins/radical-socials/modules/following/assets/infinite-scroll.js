import { store, getContext, getElement } from '@wordpress/interactivity';

const PULL_THRESHOLD = 80; // px of overscroll needed to trigger a refresh

const { state, actions } = store( 'radical-socials/following', {
	actions: {
		*refresh() {
			if ( state.refreshing ) return;
			state.refreshing = true;
			state.pulling    = false;

			try {
				const res = yield fetch( state.refreshUrl, {
					method:  'POST',
					headers: { 'X-WP-Nonce': state.nonce },
				} );
				if ( ! res.ok ) {
					console.error( '[radical-socials] refresh failed:', res.status, res.statusText, { url: state.refreshUrl } );
				}
			} catch ( err ) {
				console.error( '[radical-socials] refresh network error:', err, { url: state.refreshUrl, nonce: !! state.nonce } );
			}

			// Hold the spinner long enough to be perceptible.
			yield new Promise( ( r ) => setTimeout( r, 400 ) );
			state.refreshing = false;
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

import { store, getContext, getElement } from '@wordpress/interactivity';

const PULL_THRESHOLD = 80; // px of overscroll needed to trigger a refresh
const REFRESH_LABEL_RESET_DELAY = 1800;
const REFRESH_POLL_ATTEMPTS = 6;
const REFRESH_POLL_DELAY = 1000;

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
				} else {
					const count = yield waitForLatestItems();
					updateRefreshLabel( count );
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
			// Skip in the site editor canvas (loaded in an iframe).
			if ( window !== window.top ) {
				return;
			}

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
			// Skip in the site editor canvas (loaded in an iframe).
			if ( window !== window.top ) {
				return;
			}

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

async function prependLatestItems() {
	const list = document.querySelector( '.rs-following-feed .wp-block-post-template' );
	if ( ! list ) return 0;

	const existingIds = new Set(
		[ ...list.querySelectorAll( '.wp-block-post' ) ]
			.map( getPostId )
			.filter( Boolean )
	);

	const url = new URL( window.location.href );
	for ( const key of [ ...url.searchParams.keys() ] ) {
		if ( /^query-\d+-page$/.test( key ) ) {
			url.searchParams.delete( key );
		}
	}
	url.searchParams.set( 'rs_refresh', Date.now().toString() );

	const res = await fetch( url.toString() );
	if ( ! res.ok ) return 0;

	const text = await res.text();
	const doc = new DOMParser().parseFromString( text, 'text/html' );
	updateLastRefreshedFromDocument( doc );

	const latestItems = [ ...doc.querySelectorAll( '.rs-following-feed .wp-block-post' ) ];
	const newItems = latestItems.filter( ( item ) => {
		const id = getPostId( item );
		return id && ! existingIds.has( id );
	} );

	if ( ! newItems.length ) return 0;

	newItems.reverse().forEach( ( item ) => {
		list.insertBefore( item.cloneNode( true ), list.firstElementChild );
	} );

	return newItems.length;
}

function updateLastRefreshedFromDocument( doc ) {
	const current = document.querySelector( '.rs-last-refreshed' );
	const next = doc.querySelector( '.rs-last-refreshed' );
	if ( ! current || ! next ) return;

	current.textContent = next.textContent;
	current.title = next.title;
	current.dataset.rsLastFetched = next.dataset.rsLastFetched || '';
}

async function waitForLatestItems() {
	for ( let attempt = 0; attempt < REFRESH_POLL_ATTEMPTS; attempt++ ) {
		if ( attempt > 0 ) {
			await new Promise( ( resolve ) => setTimeout( resolve, REFRESH_POLL_DELAY ) );
		}

		const count = await prependLatestItems();
		if ( count > 0 ) {
			return count;
		}
	}

	return 0;
}

function getPostId( item ) {
	const match = [ ...item.classList ].find( ( className ) => /^post-\d+$/.test( className ) );
	return match || '';
}

function updateRefreshLabel( count ) {
	const label = document.querySelector( '.rs-refresh-label' );
	if ( ! label ) return;

	if ( count > 0 ) {
		label.textContent = count === 1
			? state.newPostLabel
			: state.newPostsLabel.replace( '%d', count );
	} else {
		label.textContent = state.noNewPostsLabel;
	}

	window.setTimeout( () => {
		if ( ! state.refreshing ) {
			label.textContent = state.refreshLabel;
		}
	}, REFRESH_LABEL_RESET_DELAY );
}

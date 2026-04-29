import { store, getContext, getElement } from '@wordpress/interactivity';

store( 'radical-socials/following', {
	callbacks: {
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

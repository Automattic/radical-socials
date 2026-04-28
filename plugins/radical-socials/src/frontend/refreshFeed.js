export default async function refreshFeed() {
	try {
		const response = await fetch( window.location.href );
		const html     = await response.text();
		const doc      = new DOMParser().parseFromString( html, 'text/html' );
		const fresh    = doc.querySelector( '.wp-block-query' );
		const current  = document.querySelector( '.wp-block-query' );
		if ( fresh && current ) {
			current.innerHTML = fresh.innerHTML;
		}
	} catch {
		window.location.reload();
	}
}

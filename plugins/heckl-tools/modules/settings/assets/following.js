/* global hecklFollowing */
( function () {
	'use strict';

	const api      = hecklFollowing.apiUrl;   // .../wp-json/heckl/v1/following
	const favApi   = api + '/favorite';
	const nonce    = hecklFollowing.nonce;
	const BATCH    = 5;

	const wrap        = document.getElementById( 'heckl-following-table-wrap' );
	const feedHeading = document.getElementById( 'heckl-feeds-heading' );
	const addInput    = document.getElementById( 'heckl-add-input' );
	const addBtn      = document.getElementById( 'heckl-add-btn' );
	const progress    = document.getElementById( 'heckl-add-progress' );
	const progBar     = document.getElementById( 'heckl-add-progress-bar' );
	const progText    = document.getElementById( 'heckl-add-progress-text' );
	const failureList = document.getElementById( 'heckl-add-failures' );

	const TYPE_LABELS = {
		rss:         'RSS',
		activitypub: 'ActivityPub',
		wpcom:       'WP.com',
	};

	// ── Load & render table ────────────────────────────────────────────────

	async function loadTable() {
		wrap.innerHTML = '<p>' + hecklFollowing.i18n.loading + '</p>';
		try {
			const res   = await apiFetch( 'GET', api );
			const items = await res.json();
			renderTable( items );
		} catch ( error ) {
			showMessage( wrap, loadErrorMessage( error ), 'heckl-error' );
		}
	}

	function renderTable( items ) {
		if ( feedHeading ) {
			feedHeading.textContent = hecklFollowing.i18n.feedsHeading.replace( '{count}', items.length );
		}

		if ( ! items.length ) {
			wrap.innerHTML = '<p>' + hecklFollowing.i18n.empty + '</p>';
			return;
		}

		const table = document.createElement( 'table' );
		table.className = 'widefat heckl-following-table';
		table.innerHTML = `
			<thead>
				<tr>
					<th class="heckl-col-fav">${ hecklFollowing.i18n.colFav }</th>
					<th class="heckl-col-health">${ hecklFollowing.i18n.colHealth }</th>
					<th class="heckl-col-name">${ hecklFollowing.i18n.colName }</th>
					<th class="heckl-col-type">${ hecklFollowing.i18n.colType }</th>
					<th class="heckl-col-categories">${ hecklFollowing.i18n.colCategories }</th>
					<th class="heckl-col-actions"></th>
				</tr>
			</thead>
			<tbody></tbody>`;

		const tbody = table.querySelector( 'tbody' );
		items.forEach( item => tbody.appendChild( buildRow( item ) ) );
		wrap.innerHTML = '';
		wrap.appendChild( table );
	}

	function buildRow( item ) {
		const tr        = document.createElement( 'tr' );
		tr.dataset.id   = item.id;
		tr.dataset.type = item.type;
		tr.dataset.url  = item.url;

		// ★ Star cell
		const tdStar  = document.createElement( 'td' );
		tdStar.className = 'heckl-col-fav';
		const starBtn = document.createElement( 'button' );
		starBtn.type      = 'button';
		starBtn.className = 'button-link heckl-star-btn' + ( item.starred ? ' heckl-starred' : '' );
		starBtn.setAttribute( 'aria-label', item.starred ? hecklFollowing.i18n.unstarLabel : hecklFollowing.i18n.starLabel );
		starBtn.setAttribute( 'aria-pressed', item.starred ? 'true' : 'false' );
		starBtn.textContent = item.starred ? '★' : '☆';
		starBtn.addEventListener( 'click', () => toggleStar( item, starBtn ) );
		tdStar.appendChild( starBtn );

		// Name cell — title links to homepage, feed URL shown below
		const tdName = document.createElement( 'td' );
		tdName.className = 'heckl-col-name';
		if ( item.title ) {
			const nameLink       = document.createElement( 'a' );
			nameLink.href        = item.source_url || item.url;
			nameLink.textContent = item.title;
			nameLink.target      = '_blank';
			nameLink.rel         = 'noopener';
			tdName.appendChild( nameLink );
			tdName.appendChild( document.createElement( 'br' ) );
		}
		const feedLink       = document.createElement( 'a' );
		feedLink.href        = item.url;
		feedLink.textContent = item.url;
		feedLink.target      = '_blank';
		feedLink.rel         = 'noopener';
		feedLink.style.cssText = 'font-size:0.85em;opacity:0.7';
		tdName.appendChild( feedLink );

		// Health (signal strength) cell
		const tdHealth = document.createElement( 'td' );
		tdHealth.className = 'heckl-col-health';
		tdHealth.appendChild( renderHealthIcon( item.health ) );

		// Type cell
		const tdType = document.createElement( 'td' );
		tdType.className = 'heckl-col-type';
		const badge  = document.createElement( 'span' );
		badge.className   = 'heckl-type-badge heckl-type-' + item.type;
		badge.textContent = TYPE_LABELS[ item.type ] || item.type;
		tdType.appendChild( badge );

		// Remove cell
		const tdDel = document.createElement( 'td' );
		tdDel.className = 'heckl-col-actions';
		const delBtn = document.createElement( 'button' );
		delBtn.type      = 'button';
		delBtn.className = 'button button-small heckl-delete-btn';
		delBtn.textContent = hecklFollowing.i18n.remove;
		delBtn.addEventListener( 'click', () => deleteItem( item, tr ) );
		tdDel.appendChild( delBtn );

		tr.appendChild( tdStar );
		tr.appendChild( tdHealth );
		tr.appendChild( tdName );
		tr.appendChild( tdType );
		tr.appendChild( buildCategoriesCell( item ) );
		tr.appendChild( tdDel );
		return tr;
	}

	function buildCategoriesCell( item ) {
		const tdCats = document.createElement( 'td' );
		tdCats.className = 'heckl-col-categories';
		const cats = item.categories || [];
		cats.forEach( cat => {
			const tag = document.createElement( 'span' );
			tag.className   = 'heckl-category-tag';
			tag.textContent = cat;
			tdCats.appendChild( tag );
		} );

		return tdCats;
	}

	// ── Health (signal-strength) indicator ────────────────────────────────

	function renderHealthIcon( health ) {
		const wrap = document.createElement( 'span' );
		wrap.className = 'heckl-health heckl-health-' + ( health?.status || 'untested' );

		// Build the SVG: three bars of increasing height. `data-level` controls
		// which of them are coloured via CSS.
		wrap.innerHTML = `
			<svg viewBox="0 0 14 14" width="16" height="16" aria-hidden="true">
				<rect class="heckl-health-bar heckl-health-bar-1" x="0"  y="9" width="3" height="5"  rx="0.5"/>
				<rect class="heckl-health-bar heckl-health-bar-2" x="5"  y="5" width="3" height="9"  rx="0.5"/>
				<rect class="heckl-health-bar heckl-health-bar-3" x="10" y="0" width="3" height="14" rx="0.5"/>
			</svg>`;

		// Accessible label / hover tooltip — different copy per state.
		wrap.title = healthTooltip( health );
		wrap.setAttribute( 'role', 'img' );
		wrap.setAttribute( 'aria-label', wrap.title );

		return wrap;
	}

	function healthTooltip( health ) {
		const i18n = hecklFollowing.i18n;
		if ( ! health || ! health.status ) {
			return i18n.healthUntested;
		}

		const checkedAgo = health.last_checked ? relTime( health.last_checked ) : '';
		switch ( health.status ) {
			case 'ok':
				return i18n.healthOk
					.replace( '{ms}', String( health.response_ms || 0 ) )
					.replace( '{ago}', checkedAgo );
			case 'slow':
				return i18n.healthSlow
					.replace( '{ms}', String( health.response_ms || 0 ) )
					.replace( '{ago}', checkedAgo );
			case 'failed':
				return i18n.healthFailed
					.replace( '{error}', health.last_error || i18n.healthUnknownError )
					.replace( '{ago}', checkedAgo );
			default:
				return i18n.healthUntested;
		}
	}

	function relTime( unixSeconds ) {
		const secs = Math.max( 1, Math.floor( Date.now() / 1000 ) - unixSeconds );
		if ( secs < 60 )    return secs + 's';
		if ( secs < 3600 )  return Math.floor( secs / 60 ) + 'm';
		if ( secs < 86400 ) return Math.floor( secs / 3600 ) + 'h';
		return Math.floor( secs / 86400 ) + 'd';
	}

	// ── Star / favourite ───────────────────────────────────────────────────

	async function toggleStar( item, btn ) {
		const nowStarred = btn.getAttribute( 'aria-pressed' ) !== 'true';

		// Optimistic update.
		btn.textContent = nowStarred ? '★' : '☆';
		btn.setAttribute( 'aria-pressed', nowStarred ? 'true' : 'false' );
		btn.setAttribute( 'aria-label', nowStarred ? hecklFollowing.i18n.unstarLabel : hecklFollowing.i18n.starLabel );
		btn.classList.toggle( 'heckl-starred', nowStarred );

		try {
			await apiFetch( 'POST', favApi, { type: item.type, id: item.id, starred: nowStarred } );
			item.starred = nowStarred;
		} catch {
			// Revert on failure.
			btn.textContent = nowStarred ? '☆' : '★';
			btn.setAttribute( 'aria-pressed', nowStarred ? 'false' : 'true' );
			btn.setAttribute( 'aria-label', nowStarred ? hecklFollowing.i18n.starLabel : hecklFollowing.i18n.unstarLabel );
			btn.classList.toggle( 'heckl-starred', ! nowStarred );
		}
	}

	// ── Delete ─────────────────────────────────────────────────────────────

	async function deleteItem( item, tr ) {
		const name    = item.title || item.url;
		const message = hecklFollowing.i18n.deleteConfirm
			.replace( '{name}', name )
			.replace( '{url}', item.url );
		if ( ! window.confirm( message ) ) {
			return;
		}

		tr.style.opacity = '0.4';
		try {
			await apiFetch( 'DELETE', api, { type: item.type, id: item.id, url: item.url } );
			tr.remove();
			if ( ! wrap.querySelector( 'tbody tr' ) ) {
				wrap.innerHTML = '<p>' + hecklFollowing.i18n.empty + '</p>';
			}
		} catch {
			tr.style.opacity = '';
			alert( hecklFollowing.i18n.deleteError );
		}
	}

	// ── Batch add ──────────────────────────────────────────────────────────

	addBtn.addEventListener( 'click', async () => {
		const lines = addInput.value
			.split( '\n' )
			.map( l => l.trim() )
			.filter( Boolean );

		if ( ! lines.length ) return;

		const total    = lines.length;
		let done       = 0;
		let added      = 0;
		let skipped    = 0;
		let failed     = 0;
		const failures = [];

		setProgress( 0, total );
		progress.hidden      = false;
		failureList.hidden   = true;
		failureList.innerHTML = '';
		addBtn.disabled      = true;

		for ( let i = 0; i < lines.length; i += BATCH ) {
			const batch = lines.slice( i, i + BATCH );
			await Promise.all( batch.map( async input => {
				try {
					const res  = await fetch( api, {
						method:  'POST',
						headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
						body:    JSON.stringify( { input } ),
					} );
					const data = await res.json().catch( () => ( {} ) );
					if ( res.status === 201 ) {
						added++;
					} else if ( res.status === 409 ) {
						skipped++;
					} else {
						failed++;
						failures.push( { input, reason: errorLabel( data.error ) } );
					}
				} catch {
					failed++;
					failures.push( { input, reason: hecklFollowing.i18n.errorNetwork } );
				}
				done++;
				setProgress( done, total );
			} ) );
		}

		addBtn.disabled = false;
		const summary = hecklFollowing.i18n.addSummary
			.replace( '{added}',   added )
			.replace( '{skipped}', skipped )
			.replace( '{failed}',  failed );
		progText.textContent = summary;

		if ( failures.length ) {
			const label = document.createElement( 'p' );
			label.style.cssText  = 'margin:4px 0 2px;font-weight:600';
			label.textContent    = hecklFollowing.i18n.failuresLabel;
			const ul = document.createElement( 'ul' );
			ul.style.cssText = 'margin:0;padding-left:1.4em';
			failures.forEach( ( { input: inp, reason } ) => {
				const li   = document.createElement( 'li' );
				const code = document.createElement( 'code' );
				code.textContent = inp;
				li.appendChild( code );
				li.appendChild( document.createTextNode( ' — ' + reason ) );
				ul.appendChild( li );
			} );
			failureList.appendChild( label );
			failureList.appendChild( ul );
			failureList.hidden = false;
		}

		if ( added > 0 ) {
			addInput.value = '';
			await loadTable();
		}
	} );

	function setProgress( done, total ) {
		progBar.value        = done;
		progBar.max          = total;
		progText.textContent = done + ' / ' + total;
	}

	// ── Error helpers ──────────────────────────────────────────────────────

	function errorLabel( code ) {
		return ( code && hecklFollowing.i18n.errors[ code ] ) || hecklFollowing.i18n.errorUnknown;
	}

	function loadErrorMessage( error ) {
		if ( ! error || ! error.status ) {
			return hecklFollowing.i18n.loadErrorNetwork;
		}

		if ( 401 === error.status || 403 === error.status ) {
			return hecklFollowing.i18n.loadErrorAuth;
		}

		if ( 404 === error.status ) {
			return hecklFollowing.i18n.loadErrorNotFound;
		}

		if ( error.status >= 500 ) {
			return hecklFollowing.i18n.loadErrorServer;
		}

		if ( error.message ) {
			return hecklFollowing.i18n.loadErrorWithMessage.replace( '{message}', error.message );
		}

		return hecklFollowing.i18n.loadErrorWithStatus.replace( '{status}', error.status );
	}

	function showMessage( target, message, className ) {
		const p = document.createElement( 'p' );
		if ( className ) {
			p.className = className;
		}
		p.textContent = message;
		target.innerHTML = '';
		target.appendChild( p );
	}

	// ── Fetch helper ───────────────────────────────────────────────────────

	function apiFetch( method, url, body ) {
		const opts = {
			method,
			headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
		};
		if ( body && method !== 'GET' ) {
			opts.body = JSON.stringify( body );
		}
		return fetch( url, opts ).then( async res => {
			if ( ! res.ok && res.status !== 409 ) {
				const error = new Error( res.statusText || String( res.status ) );
				error.status = res.status;

				try {
					const data = await res.json();
					error.code = data && data.code;
					if ( data && data.message ) {
						error.message = data.message;
					}
				} catch ( parseError ) {
					// Non-JSON failures still carry the HTTP status above.
				}

				throw error;
			}
			return res;
		} );
	}

	// ── Import from account ───────────────────────────────────────────────────

	const importAccountInput = document.getElementById( 'heckl-import-account-input' );
	const importAccountBtn   = document.getElementById( 'heckl-import-account-btn' );
	const importAccountProg  = document.getElementById( 'heckl-import-account-progress' );
	const importAccountBar   = document.getElementById( 'heckl-import-account-bar' );
	const importAccountText  = document.getElementById( 'heckl-import-account-text' );

	importAccountBtn.addEventListener( 'click', async () => {
		const handle = importAccountInput.value.trim();
		if ( ! handle ) return;

		importAccountBtn.disabled  = true;
		importAccountProg.hidden   = false;
		importAccountBar.value     = 0;
		importAccountBar.max       = 1;
		importAccountText.textContent = hecklFollowing.i18n.importAccountFetching;

		// Step 1: fetch the following list.
		let actors;
		try {
			const res  = await fetch( hecklFollowing.importFromAccountUrl, {
				method:  'POST',
				headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
				body:    JSON.stringify( { handle } ),
			} );
			const data = await res.json();
			if ( ! res.ok ) {
				const msg = {
					following_list_private: hecklFollowing.i18n.importAccountPrivate,
					account_not_found:      hecklFollowing.i18n.importAccountNotFound,
				}[ data.error ] || hecklFollowing.i18n.importAccountError;
				importAccountText.textContent = msg;
				importAccountBtn.disabled = false;
				return;
			}
			actors = data.actors || [];
		} catch {
			importAccountText.textContent = hecklFollowing.i18n.importAccountError;
			importAccountBtn.disabled = false;
			return;
		}

		if ( ! actors.length ) {
			importAccountText.textContent = hecklFollowing.i18n.importAccountDone
				.replace( '{added}', 0 ).replace( '{skipped}', 0 ).replace( '{failed}', 0 );
			importAccountBtn.disabled = false;
			return;
		}

		// Step 2: add each actor using the existing endpoint with type=activitypub.
		let done = 0, added = 0, skipped = 0, failed = 0;
		importAccountBar.max = actors.length;

		for ( let i = 0; i < actors.length; i += BATCH ) {
			const batch = actors.slice( i, i + BATCH );
			await Promise.all( batch.map( async actorUrl => {
				try {
					const res = await fetch( hecklFollowing.apiUrl, {
						method:  'POST',
						headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
						body:    JSON.stringify( { input: actorUrl, type: 'activitypub' } ),
					} );
					if ( res.status === 201 )      added++;
					else if ( res.status === 409 ) skipped++;
					else                           failed++;
				} catch {
					failed++;
				}
				done++;
				importAccountBar.value        = done;
				importAccountText.textContent = hecklFollowing.i18n.importAccountAdding
					.replace( '{done}', done ).replace( '{total}', actors.length );
			} ) );
		}

		importAccountText.textContent = hecklFollowing.i18n.importAccountDone
			.replace( '{added}',   added )
			.replace( '{skipped}', skipped )
			.replace( '{failed}',  failed );
		importAccountBtn.disabled = false;

		if ( added > 0 ) {
			await loadTable();
		}
	} );

	// ── OPML import ───────────────────────────────────────────────────────────

	const opmlFile      = document.getElementById( 'heckl-opml-file' );
	const opmlImportBtn = document.getElementById( 'heckl-opml-import-btn' );

	opmlImportBtn.addEventListener( 'click', async () => {
		if ( ! opmlFile.files.length ) {
			progText.textContent = hecklFollowing.i18n.importNoFile;
			progress.hidden      = false;
			return;
		}

		opmlImportBtn.disabled = true;
		progress.hidden        = false;
		setProgress( 0, 1 );
		progText.textContent = hecklFollowing.i18n.importing;

		// Step 1: parse file → get feed list (no DB writes).
		const formData = new FormData();
		formData.append( 'file', opmlFile.files[ 0 ] );

		let feeds;
		try {
			const res  = await fetch( hecklFollowing.opmlParseUrl, {
				method:  'POST',
				headers: { 'X-WP-Nonce': nonce },
				body:    formData,
			} );
			const data = await res.json();
			if ( ! res.ok ) {
				progText.textContent = data.message || hecklFollowing.i18n.importError;
				opmlImportBtn.disabled = false;
				return;
			}
			feeds = data.feeds || [];
		} catch {
			progText.textContent   = hecklFollowing.i18n.importError;
			opmlImportBtn.disabled = false;
			return;
		}

		// Step 2: upsert all feeds in a single request. Per-feed POSTs raced on
		// the heckl_rss_subscriptions option (each call read, mutated, and wrote
		// the option, so parallel writes overwrote each other and entries got
		// silently lost). One batched request = one read + one write.
		const total = feeds.length;
		setProgress( 0, 1 );

		let added = 0, updated = 0, unchanged = 0, failed = 0;
		try {
			const res = await fetch( hecklFollowing.opmlImportUrl, {
				method:  'POST',
				headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
				body:    JSON.stringify( { feeds } ),
			} );
			const data = await res.json().catch( () => ( {} ) );
			if ( ! res.ok ) {
				progText.textContent   = data.message || hecklFollowing.i18n.importError;
				opmlImportBtn.disabled = false;
				return;
			}
			added     = data.added     || 0;
			updated   = data.updated   || 0;
			unchanged = data.skipped   || 0; // no-op duplicates
			failed    = data.failed    || ( total - added - updated - unchanged );
		} catch {
			progText.textContent   = hecklFollowing.i18n.importError;
			opmlImportBtn.disabled = false;
			return;
		}
		setProgress( 1, 1 );

		opmlImportBtn.disabled = false;
		progText.textContent   = hecklFollowing.i18n.importResult
			.replace( '{added}',   added )
			.replace( '{updated}', updated )
			.replace( '{skipped}', unchanged )
			.replace( '{failed}',  failed );
		opmlFile.value = '';

		if ( added > 0 || updated > 0 ) {
			await loadTable();
		}
	} );

	// ── OPML export ───────────────────────────────────────────────────────────

	document.getElementById( 'heckl-opml-export-link' ).href = hecklFollowing.opmlExportUrl;

	// ── Boot ───────────────────────────────────────────────────────────────

	loadTable();
} )();

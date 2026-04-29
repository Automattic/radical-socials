/* global rsFollowing */
( function () {
	'use strict';

	const api      = rsFollowing.apiUrl;   // .../wp-json/radical-socials/v1/following
	const favApi   = api + '/favorite';
	const nonce    = rsFollowing.nonce;
	const BATCH    = 5;

	const wrap     = document.getElementById( 'rs-following-table-wrap' );
	const addInput = document.getElementById( 'rs-add-input' );
	const addBtn   = document.getElementById( 'rs-add-btn' );
	const progress = document.getElementById( 'rs-add-progress' );
	const progBar  = document.getElementById( 'rs-add-progress-bar' );
	const progText = document.getElementById( 'rs-add-progress-text' );

	const TYPE_LABELS = {
		rss:         'RSS',
		activitypub: 'ActivityPub',
		wpcom:       'WP.com',
	};

	// ── Load & render table ────────────────────────────────────────────────

	async function loadTable() {
		wrap.innerHTML = '<p>' + rsFollowing.i18n.loading + '</p>';
		try {
			const res   = await apiFetch( 'GET', api );
			const items = await res.json();
			renderTable( items );
		} catch {
			wrap.innerHTML = '<p class="rs-error">' + rsFollowing.i18n.loadError + '</p>';
		}
	}

	function renderTable( items ) {
		if ( ! items.length ) {
			wrap.innerHTML = '<p>' + rsFollowing.i18n.empty + '</p>';
			return;
		}

		const table = document.createElement( 'table' );
		table.className = 'widefat rs-following-table';
		table.innerHTML = `
			<thead>
				<tr>
					<th>${ rsFollowing.i18n.colFav }</th>
					<th>${ rsFollowing.i18n.colName }</th>
					<th>${ rsFollowing.i18n.colType }</th>
					<th></th>
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
		const starBtn = document.createElement( 'button' );
		starBtn.type      = 'button';
		starBtn.className = 'button-link rs-star-btn' + ( item.starred ? ' rs-starred' : '' );
		starBtn.setAttribute( 'aria-label', item.starred ? rsFollowing.i18n.unstarLabel : rsFollowing.i18n.starLabel );
		starBtn.setAttribute( 'aria-pressed', item.starred ? 'true' : 'false' );
		starBtn.textContent = item.starred ? '★' : '☆';
		starBtn.addEventListener( 'click', () => toggleStar( item, starBtn ) );
		tdStar.appendChild( starBtn );

		// Name cell — title links to homepage, feed URL shown below
		const tdName = document.createElement( 'td' );
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

		// Type cell
		const tdType = document.createElement( 'td' );
		const badge  = document.createElement( 'span' );
		badge.className   = 'rs-type-badge rs-type-' + item.type;
		badge.textContent = TYPE_LABELS[ item.type ] || item.type;
		tdType.appendChild( badge );

		// Remove cell
		const tdDel = document.createElement( 'td' );
		const delBtn = document.createElement( 'button' );
		delBtn.type      = 'button';
		delBtn.className = 'button button-small rs-delete-btn';
		delBtn.textContent = rsFollowing.i18n.remove;
		delBtn.addEventListener( 'click', () => deleteItem( item, tr ) );
		tdDel.appendChild( delBtn );

		tr.appendChild( tdStar );
		tr.appendChild( tdName );
		tr.appendChild( tdType );
		tr.appendChild( tdDel );
		return tr;
	}

	// ── Star / favourite ───────────────────────────────────────────────────

	async function toggleStar( item, btn ) {
		const nowStarred = btn.getAttribute( 'aria-pressed' ) !== 'true';

		// Optimistic update.
		btn.textContent = nowStarred ? '★' : '☆';
		btn.setAttribute( 'aria-pressed', nowStarred ? 'true' : 'false' );
		btn.setAttribute( 'aria-label', nowStarred ? rsFollowing.i18n.unstarLabel : rsFollowing.i18n.starLabel );
		btn.classList.toggle( 'rs-starred', nowStarred );

		try {
			await apiFetch( 'POST', favApi, { type: item.type, id: item.id, starred: nowStarred } );
			item.starred = nowStarred;
		} catch {
			// Revert on failure.
			btn.textContent = nowStarred ? '☆' : '★';
			btn.setAttribute( 'aria-pressed', nowStarred ? 'false' : 'true' );
			btn.setAttribute( 'aria-label', nowStarred ? rsFollowing.i18n.starLabel : rsFollowing.i18n.unstarLabel );
			btn.classList.toggle( 'rs-starred', ! nowStarred );
		}
	}

	// ── Delete ─────────────────────────────────────────────────────────────

	async function deleteItem( item, tr ) {
		tr.style.opacity = '0.4';
		try {
			await apiFetch( 'DELETE', api, { type: item.type, id: item.id, url: item.url } );
			tr.remove();
			if ( ! wrap.querySelector( 'tbody tr' ) ) {
				wrap.innerHTML = '<p>' + rsFollowing.i18n.empty + '</p>';
			}
		} catch {
			tr.style.opacity = '';
			alert( rsFollowing.i18n.deleteError );
		}
	}

	// ── Batch add ──────────────────────────────────────────────────────────

	addBtn.addEventListener( 'click', async () => {
		const lines = addInput.value
			.split( '\n' )
			.map( l => l.trim() )
			.filter( Boolean );

		if ( ! lines.length ) return;

		const total = lines.length;
		let done    = 0;
		let added   = 0;
		let skipped = 0;
		let failed  = 0;

		setProgress( 0, total );
		progress.hidden = false;
		addBtn.disabled = true;

		for ( let i = 0; i < lines.length; i += BATCH ) {
			const batch = lines.slice( i, i + BATCH );
			await Promise.all( batch.map( async input => {
				try {
					const res = await apiFetch( 'POST', api, { input } );
					if ( res.status === 201 )      { added++; }
					else if ( res.status === 409 ) { skipped++; }
					else                           { failed++; }
				} catch {
					failed++;
				}
				done++;
				setProgress( done, total );
			} ) );
		}

		addBtn.disabled = false;
		const summary = rsFollowing.i18n.addSummary
			.replace( '%added%',   added )
			.replace( '%skipped%', skipped )
			.replace( '%failed%',  failed );
		progText.textContent = summary;

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

	// ── Fetch helper ───────────────────────────────────────────────────────

	function apiFetch( method, url, body ) {
		const opts = {
			method,
			headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
		};
		if ( body && method !== 'GET' ) {
			opts.body = JSON.stringify( body );
		}
		return fetch( url, opts ).then( res => {
			if ( ! res.ok && res.status !== 409 ) throw new Error( res.status );
			return res;
		} );
	}

	// ── Boot ───────────────────────────────────────────────────────────────

	loadTable();
} )();

( function () {
	const doc = document;

	function setPreviewImage( preview, url ) {
		preview.innerHTML = '';
		preview.classList.add( 'has-image' );

		const image = doc.createElement( 'img' );
		image.src = url;
		image.alt = '';
		preview.appendChild( image );
	}

	function resetPreview( preview, label ) {
		preview.innerHTML = '';
		preview.classList.remove( 'has-image' );

		const empty = doc.createElement( 'span' );
		empty.textContent = label;
		preview.appendChild( empty );
	}

	function getAttachmentUrl( attachment, target ) {
		const sizes = attachment.sizes || {};

		if ( target === 'cover' ) {
			return ( sizes.large && sizes.large.url ) || attachment.url;
		}

		return (
			( sizes.thumbnail && sizes.thumbnail.url ) ||
			( sizes.medium && sizes.medium.url ) ||
			attachment.url
		);
	}

	function updateProfilePreview( target, url, emptyLabel ) {
		if ( target === 'cover' ) {
			updateProfileCover( url, emptyLabel );
			return;
		}

		updateProfileAvatar( url );
	}

	function updateProfileAvatar( url ) {
		const avatar = doc.querySelector( '[data-heckl-profile-avatar]' );

		if ( ! avatar ) {
			return;
		}

		avatar.innerHTML = '';

		if ( url ) {
			const image = doc.createElement( 'img' );
			image.src = url;
			image.alt = '';
			avatar.appendChild( image );
			return;
		}

		const initial = doc.createElement( 'span' );
		initial.textContent = avatar.dataset.emptyInitial || '';
		avatar.appendChild( initial );
	}

	function updateProfileCover( url, emptyLabel ) {
		const cover = doc.querySelector( '[data-heckl-profile-cover]' );

		if ( ! cover ) {
			return;
		}

		if ( url ) {
			cover.style.backgroundImage = `url("${ url }")`;
			cover.classList.add( 'has-image' );
			cover.textContent = '';
			return;
		}

		cover.style.backgroundImage = '';
		cover.classList.remove( 'has-image' );
		cover.textContent = emptyLabel;
	}

	function initMediaControl( control ) {
		const input = control.querySelector( '[data-heckl-media-input]' );
		const preview = control.querySelector( '[data-heckl-media-preview]' );
		const open = control.querySelector( '[data-heckl-media-open]' );
		const remove = control.querySelector( '[data-heckl-media-remove]' );
		const target = control.dataset.hecklMediaTarget;
		const emptyLabel =
			control.dataset.emptyLabel ||
			( target === 'cover' ? 'Cover photo' : 'Profile photo' );
		const addText =
			control.dataset.addText || ( target === 'cover' ? 'Add cover' : 'Add photo' );
		const changeText =
			control.dataset.changeText ||
			( target === 'cover' ? 'Change cover' : 'Change photo' );
		let frame;

		if ( ! input || ! preview || ! open || ! window.wp || ! window.wp.media ) {
			return;
		}

		open.addEventListener( 'click', function () {
			if ( frame ) {
				frame.open();
				return;
			}

			frame = window.wp.media( {
				title: open.dataset.title || 'Select image',
				button: {
					text: open.dataset.button || 'Use image',
				},
				library: {
					type: 'image',
				},
				multiple: false,
			} );

			frame.on( 'select', function () {
				const attachment = frame.state().get( 'selection' ).first().toJSON();
				const url = getAttachmentUrl( attachment, target );

				input.value = attachment.id || '';
				setPreviewImage( preview, url );
				open.textContent = changeText;

				if ( remove ) {
					remove.classList.remove( 'hidden' );
				}

				updateProfilePreview( target, url, emptyLabel );
			} );

			frame.open();
		} );

		if ( remove ) {
			remove.addEventListener( 'click', function () {
				input.value = '';
				resetPreview( preview, emptyLabel );
				open.textContent = addText;
				remove.classList.add( 'hidden' );

				updateProfilePreview( target, '', emptyLabel );
			} );
		}
	}

	doc
		.querySelectorAll( '[data-heckl-media-control]' )
		.forEach( initMediaControl );
}() );

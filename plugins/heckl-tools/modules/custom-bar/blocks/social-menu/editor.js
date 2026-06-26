( function () {
	var registerBlockType = wp.blocks.registerBlockType;
	var useBlockProps     = wp.blockEditor.useBlockProps;
	var el                = wp.element.createElement;

	// Matches @wordpress/icons "navigation" — same icon core/navigation uses.
	var icon = el( 'svg',
		{ xmlns: 'http://www.w3.org/2000/svg', viewBox: '0 0 24 24' },
		el( 'path', { d: 'M12 4c-4.4 0-8 3.6-8 8s3.6 8 8 8 8-3.6 8-8-3.6-8-8-8zm0 14.5c-3.6 0-6.5-2.9-6.5-6.5S8.4 5.5 12 5.5s6.5 2.9 6.5 6.5-2.9 6.5-6.5 6.5zM9 16l4.5-3L15 8.4l-4.5 3L9 16z' } )
	);

	registerBlockType( 'heckl/social-menu', {
		icon: icon,
		edit: function () {
			// Spread useBlockProps directly onto the <ul> so the layout system
			// attaches to the actual flex container — orientation/blockGap then
			// "just work" in the editor without static CSS workarounds.
			var blockProps = useBlockProps();
			var links      = ( window.radicalSocialsSocialMenu && window.radicalSocialsSocialMenu.links ) || [];

			return el( 'ul', blockProps,
				links.map( function ( link ) {
					return el( 'li', {
						key:       link.id,
						className: 'wp-block-heckl-social-menu__item is-heckl-link-' + link.id,
					},
						el( 'a', {
							href:    link.url,
							onClick: function ( e ) { e.preventDefault(); },
						},
							el( 'span', {
								className:     'dashicons ' + link.icon,
								'aria-hidden': 'true',
							} ),
							el( 'span', {
								className: 'wp-block-heckl-social-menu__label',
							}, link.label )
						)
					);
				} )
			);
		},
		save: function () {
			return null; // server-side rendered on the frontend
		},
	} );
} )();

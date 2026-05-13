( function () {
	var registerBlockType = wp.blocks.registerBlockType;
	var useBlockProps     = wp.blockEditor.useBlockProps;
	var el                = wp.element.createElement;
	var ServerSideRender  = wp.serverSideRender.default || wp.serverSideRender;

	// Same path as @wordpress/icons "navigation" — the icon core/navigation uses in the inserter.
	var icon = el( 'svg',
		{ xmlns: 'http://www.w3.org/2000/svg', viewBox: '0 0 24 24' },
		el( 'path', { d: 'M12 4c-4.4 0-8 3.6-8 8s3.6 8 8 8 8-3.6 8-8-3.6-8-8-8zm0 14.5c-3.6 0-6.5-2.9-6.5-6.5S8.4 5.5 12 5.5s6.5 2.9 6.5 6.5-2.9 6.5-6.5 6.5zM9 16l4.5-3L15 8.4l-4.5 3L9 16z' } )
	);

	registerBlockType( 'radical-socials/social-menu', {
		icon: icon,
		edit: function () {
			var blockProps = useBlockProps( {
				onClick: function ( e ) { e.preventDefault(); },
			} );
			return el( 'div', blockProps,
				el( ServerSideRender, { block: 'radical-socials/social-menu' } )
			);
		},
		save: function () {
			return null;
		},
	} );
} )();

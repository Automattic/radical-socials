( function () {
	var registerBlockType = wp.blocks.registerBlockType;
	var el                = wp.element.createElement;
	var useBlockProps     = wp.blockEditor.useBlockProps;

	registerBlockType( 'radical-socials/favorites-link', {
		edit: function () {
			var blockProps = useBlockProps( {
				className: 'wp-block-navigation-item__content',
				href: '#',
				onClick: function ( e ) { e.preventDefault(); },
			} );
			return el( 'a', blockProps, 'Favorites' );
		},
		save: function () {
			return null; // server-side rendered
		},
	} );
} )();

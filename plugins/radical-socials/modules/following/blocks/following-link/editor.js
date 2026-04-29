( function () {
	var registerBlockType = wp.blocks.registerBlockType;
	var el                = wp.element.createElement;
	var useBlockProps     = wp.blockEditor.useBlockProps;

	registerBlockType( 'radical-socials/following-link', {
		edit: function () {
			var blockProps = useBlockProps( {
				className: 'wp-block-navigation-item__content',
				href: '#',
				onClick: function ( e ) { e.preventDefault(); },
			} );
			return el( 'a', blockProps, 'Following' );
		},
		save: function () {
			return null; // server-side rendered
		},
	} );
} )();

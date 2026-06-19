( function () {
	var registerBlockType  = wp.blocks.registerBlockType;
	var el                 = wp.element.createElement;
	var useBlockProps      = wp.blockEditor.useBlockProps;
	var ServerSideRender   = wp.serverSideRender.default || wp.serverSideRender;

	registerBlockType( 'heckl/favorite-feeds', {
		edit: function () {
			var blockProps = useBlockProps( {
				onClick: function ( e ) { e.preventDefault(); },
			} );
			return el(
				'div',
				blockProps,
				el( ServerSideRender, { block: 'heckl/favorite-feeds' } )
			);
		},
		save: function () {
			return null; // server-side rendered
		},
	} );
} )();

( function () {
	var registerBlockType = wp.blocks.registerBlockType;
	var el                = wp.element.createElement;
	var useBlockProps     = wp.blockEditor.useBlockProps;

	registerBlockType( 'heckl/like-button', {
		edit: function () {
			var blockProps = useBlockProps( { className: 'heckl-like-button-wrap' } );
			return el( 'div', blockProps,
				el( 'button', { type: 'button', className: 'heckl-like-btn', disabled: true }, '♡' )
			);
		},
		save: function () {
			return null; // server-side rendered
		},
	} );
} )();

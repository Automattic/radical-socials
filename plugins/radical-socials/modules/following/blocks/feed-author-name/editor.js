( function () {
	var registerBlockType = wp.blocks.registerBlockType;
	var useBlockProps     = wp.blockEditor.useBlockProps;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody         = wp.components.PanelBody;
	var ToggleControl     = wp.components.ToggleControl;
	var el                = wp.element.createElement;
	var Fragment          = wp.element.Fragment;
	var __                = wp.i18n.__;

	registerBlockType( 'radical-socials/feed-author-name', {
		edit: function ( props ) {
			var blockProps = useBlockProps();
			var isLink     = !! props.attributes.isLink;

			return el( Fragment, null,
				el( InspectorControls, null,
					el( PanelBody, { title: __( 'Settings', 'radical-socials' ) },
						el( ToggleControl, {
							label:    __( 'Link to author profile', 'radical-socials' ),
							checked:  isLink,
							onChange: function ( v ) { props.setAttributes( { isLink: v } ); },
						} )
					)
				),
				el( 'span', blockProps,
					isLink
						? el( 'a', { href: '#', onClick: function ( e ) { e.preventDefault(); } }, __( 'Author Name', 'radical-socials' ) )
						: __( 'Author Name', 'radical-socials' )
				)
			);
		},
		save: function () {
			return null;
		},
	} );
} )();

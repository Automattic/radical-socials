( function () {
	var registerBlockType = wp.blocks.registerBlockType;
	var useBlockProps     = wp.blockEditor.useBlockProps;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody         = wp.components.PanelBody;
	var ToggleControl     = wp.components.ToggleControl;
	var __experimentalUnitControl =
		( wp.blockEditor && wp.blockEditor.__experimentalUnitControl ) ||
		( wp.components && wp.components.__experimentalUnitControl );
	var el                = wp.element.createElement;
	var Fragment          = wp.element.Fragment;
	var __                = wp.i18n.__;

	registerBlockType( 'radical-socials/feed-author-avatar', {
		edit: function ( props ) {
			var isLink     = !! props.attributes.isLink;
			var rounded    = !! props.attributes.rounded;
			var width      = props.attributes.width || '48px';
			var blockProps = useBlockProps( { className: rounded ? 'is-rounded' : '' } );

			var inspector = el( InspectorControls, null,
				el( PanelBody, { title: __( 'Avatar', 'radical-socials' ) },
					__experimentalUnitControl && el( __experimentalUnitControl, {
						label: __( 'Size', 'radical-socials' ),
						value: width,
						onChange: function ( v ) { props.setAttributes( { width: v } ); },
						units: [ { value: 'px', label: 'px' }, { value: 'em', label: 'em' }, { value: 'rem', label: 'rem' } ],
					} ),
					el( ToggleControl, {
						label:    __( 'Rounded', 'radical-socials' ),
						checked:  rounded,
						onChange: function ( v ) { props.setAttributes( { rounded: v } ); },
					} ),
					el( ToggleControl, {
						label:    __( 'Link to author profile', 'radical-socials' ),
						checked:  isLink,
						onChange: function ( v ) { props.setAttributes( { isLink: v } ); },
					} )
				)
			);

			var placeholder = el( 'span', { style: {
				display: 'inline-block',
				width:  width,
				height: width,
				background: 'currentColor',
				opacity: 0.15,
				borderRadius: rounded ? '50%' : 'inherit',
			} } );

			return el( Fragment, null,
				inspector,
				el( 'span', blockProps, placeholder )
			);
		},
		save: function () {
			return null;
		},
	} );
} )();

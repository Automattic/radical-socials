import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps } from '@wordpress/block-editor';
import { __ } from '@wordpress/i18n';

registerBlockType( 'heckl/frontend-editor', {
	edit() {
		return (
			<div
				{ ...useBlockProps() }
				style={ {
					padding:    '1em',
					border:     '1px dashed #ccc',
					textAlign:  'center',
					color:      '#888',
					background: 'transparent',
				} }
			>
				{ __( 'Placeholder for Social Editor', 'heckl-tools' ) }
			</div>
		);
	},
	save: () => null,
} );

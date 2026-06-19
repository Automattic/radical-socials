import { registerCoreBlocks } from '@wordpress/block-library';
import { getBlockTypes, unregisterBlockType } from '@wordpress/blocks';

export const ALLOWED_BLOCKS = [
	'core/paragraph',
	'core/image',
	'core/video',
	'core/audio',
	'core/embed',
];

let blocksRegistered = false;

export function registerEditorBlocks() {
	if ( blocksRegistered ) return;
	blocksRegistered = true;
	registerCoreBlocks();

	// `allowedBlockTypes` only constrains the inserter — every other
	// block (≈90 of them) stays in the type registry and pulls in
	// embed variations, block patterns, deprecation handlers, and the
	// occasional duplicate-registration warning we don't need on the
	// front end. Drop everything that isn't in our composer palette.
	// Safe to do here because the frontend page only ever hosts our
	// editor; no other block-editor instance shares the type registry.
	getBlockTypes().forEach( ( { name } ) => {
		if ( ! ALLOWED_BLOCKS.includes( name ) ) {
			unregisterBlockType( name );
		}
	} );
}

export function getEditorSettings( mediaUpload ) {
	return {
		bodyPlaceholder: "What's on your mind?",
		codeEditingEnabled: false,
		canLockBlocks: false,
		supportsLayout: false,
		__experimentalBlockPatterns: [],
		allowedBlockTypes: ALLOWED_BLOCKS,
		mediaUpload,
	};
}

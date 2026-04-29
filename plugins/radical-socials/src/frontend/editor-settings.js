import { registerCoreBlocks } from '@wordpress/block-library';

let blocksRegistered = false;

export function registerEditorBlocks() {
	if ( blocksRegistered ) return;
	blocksRegistered = true;
	registerCoreBlocks();
}

export function getEditorSettings( mediaUpload ) {
	return {
		bodyPlaceholder: "What's on your mind?",
		codeEditingEnabled: false,
		canLockBlocks: false,
		supportsLayout: false,
		__experimentalBlockPatterns: [],
		allowedBlockTypes: [
			'core/paragraph',
			'core/image',
			'core/video',
			'core/audio',
			'core/embed',
		],
		mediaUpload,
	};
}

<?php
/**
 * Module-deps manifest for the like-button block's view script.
 *
 * WP reads this file alongside the matching `like-button.js` (registered
 * via `viewScriptModule` in block.json) to discover which other script
 * modules the view depends on. Without it, the browser loads our file
 * as `<script type="module">` but no import map is printed for
 * `@wordpress/interactivity`, and the bare specifier in the file's
 * `import { store, getContext } from '@wordpress/interactivity'` line
 * fails to resolve.
 *
 * This file is the hand-written equivalent of what
 * `@wordpress/scripts build` emits next to each compiled bundle. We
 * don't need a bundle (the source already ships as a module), but we
 * do need the dep declaration so the import map gets emitted.
 *
 * @package Heckl
 */

return [
	'dependencies' => [
		'@wordpress/interactivity',
	],
	'version' => '1.0.0',
];

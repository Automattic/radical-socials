const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );

module.exports = {
	...defaultConfig,
	entry: {
		...( typeof defaultConfig.entry === 'function' ? defaultConfig.entry() : defaultConfig.entry ),
		frontend:              path.resolve( __dirname, 'plugins/radical-socials-tools/src/frontend.js' ),
		'frontend-editor-block': path.resolve( __dirname, 'plugins/radical-socials-tools/src/frontend-editor-block.js' ),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'plugins/radical-socials-tools/build' ),
	},
};

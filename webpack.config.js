const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );

module.exports = {
	...defaultConfig,
	entry: {
		...( typeof defaultConfig.entry === 'function' ? defaultConfig.entry() : defaultConfig.entry ),
		frontend: path.resolve( __dirname, 'plugins/radical-socials/src/frontend.js' ),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'plugins/radical-socials/build' ),
	},
};

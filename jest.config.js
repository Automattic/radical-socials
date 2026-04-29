const defaultConfig = require( '@wordpress/scripts/config/jest-unit.config' );

module.exports = {
	...defaultConfig,
	setupFilesAfterEnv: [
		...( defaultConfig.setupFilesAfterEnv ?? [] ),
		'@testing-library/jest-dom',
	],
};

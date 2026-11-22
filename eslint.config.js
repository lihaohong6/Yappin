'use strict';

const wikimedia = require( 'eslint-config-wikimedia/flat' );

module.exports = [
	{ ignores: [ 'vendor/**' ] },
	...wikimedia( 'client', 'jquery', 'mediawiki' ),
	{
		languageOptions: {
			ecmaVersion: 2024
		}
	},
	{
		files: [ 'resources/ext.yappin.main/**' ],
		languageOptions: {
			globals: { moment: 'readonly' }
		}
	},
	{
		files: [ 'resources/ext.yappin.ve/**' ],
		languageOptions: {
			globals: { ve: 'readonly' }
		}
	}
];

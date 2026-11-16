// A174: lint all own JavaScript (browser scripts, service worker, block editor scripts, test scripts).
import js from '@eslint/js';
import globals from 'globals';

export default [
	{ ignores: [ 'vendor/**', 'assets/vendor/**', 'node_modules/**', 'tests/a11y/node_modules/**', 'build/**', '.claude/**', 'tests/upgrade/.wordpress/**', 'tests/upgrade/.cache/**' ] },
	js.configs.recommended,
	{
		files: [ 'assets/**/*.js', 'src/blocks/**/*.js' ],
		languageOptions: {
			ecmaVersion: 2020,
			sourceType: 'script',
			globals: {
				...globals.browser,
				wp: 'readonly', jQuery: 'readonly', jsQR: 'readonly', nacl: 'readonly',
				// Data handed over by wp_localize_script() / inline scripts (src/).
				WPCAP_ADMISSION: 'readonly', WPCAP_CHECKOUT: 'readonly', WPCAP_SCANNER: 'readonly', WPCAP_SMS: 'readonly',
				WPCAP_BLOCK_EVENTS: 'readonly', WPCAP_SCANNER_OCCURRENCES: 'readonly', WPCAP_SCANNER_PRIVACY: 'readonly',
			},
		},
		rules: {
			'no-unused-vars': [ 'error', { args: 'none', caughtErrors: 'none' } ],
			'no-empty': [ 'error', { allowEmptyCatch: true } ],
		},
	},
	{ files: [ 'assets/offline-scanner/sw.js' ], languageOptions: { globals: { ...globals.serviceworker } } },
	{
		files: [ 'tests/**/*.js' ],
		languageOptions: { ecmaVersion: 2022, sourceType: 'commonjs', globals: { ...globals.node, ...globals.browser } },
		rules: { 'no-unused-vars': [ 'error', { args: 'none', caughtErrors: 'none' } ], 'no-empty': [ 'error', { allowEmptyCatch: true } ] },
	},
];

import js from '@eslint/js';
import globals from 'globals';

export default [
	js.configs.recommended,
	{
		languageOptions: {
			ecmaVersion: 'latest',
			sourceType: 'script',
			globals: {
				...globals.browser,
				gf_user_journey: 'readonly',
			},
		},
		rules: {
			'no-unused-vars': [ 'error', { varsIgnorePattern: '^GfUserJourney$' } ],
		},
	},
];

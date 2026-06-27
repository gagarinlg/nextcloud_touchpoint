// SPDX-FileCopyrightText: 2026 Touchpoint Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

module.exports = {
	root: true,
	env: {
		browser: true,
		es2022: true,
		node: true,
	},
	parser: 'vue-eslint-parser',
	parserOptions: {
		ecmaVersion: 2022,
		sourceType: 'module',
	},
	extends: [
		'eslint:recommended',
		'plugin:vue/vue3-recommended',
	],
	globals: {
		OC: 'readonly',
		OCA: 'readonly',
		t: 'readonly',
		n: 'readonly',
	},
	rules: {
		// Project uses tabs for indentation across JS and Vue files.
		indent: ['error', 'tab', { SwitchCase: 0 }],
		'no-tabs': 'off',
		semi: ['error', 'never'],
		// Vue component file names use PascalCase single-word names (App, etc.).
		'vue/multi-word-component-names': 'off',
		'vue/html-indent': ['error', 'tab'],
		'vue/max-attributes-per-line': 'off',
		'vue/singleline-html-element-content-newline': 'off',
		'vue/html-self-closing': 'off',
		'vue/attributes-order': 'off',
		'vue/first-attribute-linebreak': 'off',
	},
	ignorePatterns: [
		'node_modules/',
		'js/',
		'vendor/',
		'e2e/',
		'*.spec.js',
	],
}

// SPDX-FileCopyrightText: 2026 Touchpoint Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { createAppConfig } from '@nextcloud/vite-config'
import path from 'path'
import { fileURLToPath } from 'url'
import { defineConfig } from 'vite'

const __dirname = path.dirname(fileURLToPath(import.meta.url))

export default createAppConfig({
	'main': path.join(__dirname, 'src', 'main.js'),
	'contacts-integration': path.join(__dirname, 'src', 'contacts-integration.js'),
	'adminSettings': path.join(__dirname, 'src', 'adminSettings.js'),
}, {
	config: defineConfig(({ mode }) => ({
		define: {
			'process.env.NODE_ENV': JSON.stringify(mode),
		},
	})),
	// createAppConfig's build.outDir resolves to the project root (js/+css/ are
	// outside Vite's own root-relative emptyOutDir safety check), so Vite never
	// auto-clears them itself. @nextcloud/vite-config's built-in
	// nextcloud-empty-js plugin already clears `js/` before each build by
	// default; it does NOT clear `css/` unless told to, so content-hashed
	// chunk CSS files (e.g. adminSettings-*.chunk.css) from every prior build
	// accumulate there indefinitely. Extend the same plugin to also clear
	// `css/`.
	emptyOutputDirectory: {
		additionalDirectories: ['css'],
	},
})

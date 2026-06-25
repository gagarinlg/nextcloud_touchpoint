// SPDX-FileCopyrightText: 2026 CRM Notes Contributors
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
})

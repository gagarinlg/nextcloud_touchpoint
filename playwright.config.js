// SPDX-FileCopyrightText: 2026 Touchpoint Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

const { defineConfig } = require('@playwright/test');

module.exports = defineConfig({
    testDir: './e2e',
    timeout: 30000,
    use: {
        baseURL: 'http://localhost',
        httpCredentials: { username: 'admin', password: 'admin' },
        screenshot: 'on',
        locale: 'de',
    },
    projects: [
        { name: 'chromium', use: { browserName: 'chromium' } },
    ],
});

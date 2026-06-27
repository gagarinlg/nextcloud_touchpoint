/*
 * SPDX-FileCopyrightText: 2026 Touchpoint Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
const { test } = require('@playwright/test');
test.setTimeout(30000);

test('debug toggle structure', async ({ page }) => {
    await page.goto('http://localhost/index.php/login');
    await page.fill('#user', 'admin');
    await page.fill('#password', 'admin');
    await page.click('button[type=submit]');
    await page.waitForURL('**/apps/**');

    await page.goto('http://localhost/index.php/settings/admin/touchpoint');
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(2000);

    const mount = page.locator('#crm-notes-admin-settings');
    const html = await mount.innerHTML().catch(() => 'not found');
    console.log('Mount innerHTML:', html.substring(0, 1000));
});

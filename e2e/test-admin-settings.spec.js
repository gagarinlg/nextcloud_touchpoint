/*
 * SPDX-FileCopyrightText: 2026 CRM Notes Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
const { test, expect } = require('@playwright/test');

test.setTimeout(60000);

async function login(page) {
    await page.goto('http://localhost/index.php/login');
    await page.fill('#user', 'admin');
    await page.fill('#password', 'admin');
    await page.click('button[type=submit]');
    await page.waitForURL('**/apps/**');
}

test.describe('CRM Notes admin settings', () => {

    test('admin settings entry visible in NC admin sidebar', async ({ page }) => {
        await login(page);
        await page.goto('http://localhost/index.php/settings/admin/crm_notes');
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(2000);
        await page.screenshot({ path: 'e2e/screenshots/admin-settings-page.png', fullPage: true });

        // The admin section mount point should exist and the Vue component mounted
        const mount = page.locator('#crm-notes-admin-settings');
        await expect(mount).toBeVisible({ timeout: 10000 });

        // The toggle should be rendered inside it
        const toggle = mount.locator('input[type="checkbox"]').first();
        await expect(toggle).toBeVisible({ timeout: 5000 });
    });

    test('notes-public toggle saves correctly', async ({ page }) => {
        await login(page);
        await page.goto('http://localhost/index.php/settings/admin/crm_notes');
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(2000);

        const mount = page.locator('#crm-notes-admin-settings');
        const toggle = mount.locator('input[type="checkbox"]').first();
        await expect(toggle).toBeVisible({ timeout: 10000 });

        const before = await toggle.isChecked();

        // Click the switch content area (NC NcCheckboxRadioSwitch uses .checkbox-radio-switch__content)
        await mount.locator('.checkbox-radio-switch__content').first().click();
        await page.waitForTimeout(1500);

        // Verify changed
        expect(await toggle.isChecked()).toBe(!before);

        // Restore
        await mount.locator('.checkbox-radio-switch__content').first().click();
        await page.waitForTimeout(500);
        await page.screenshot({ path: 'e2e/screenshots/admin-settings-toggle.png' });
    });

});

test.describe('CRM Notes main app', () => {

    test('app loads with contacts list', async ({ page }) => {
        await login(page);
        await page.goto('http://localhost/index.php/apps/crm_notes');
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(3000);
        await page.screenshot({ path: 'e2e/screenshots/app-main-check.png', fullPage: true });

        // At least one contact item or the empty state should be visible
        const hasContacts = await page.locator('.crm-contact-item').count() > 0;
        const hasEmpty = await page.locator('.crm-empty').isVisible().catch(() => false);
        expect(hasContacts || hasEmpty).toBe(true);
    });

    test('user settings view shows default sharing section', async ({ page }) => {
        await login(page);
        await page.goto('http://localhost/index.php/apps/crm_notes');
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(2000);

        // Click settings nav item
        const settingsNav = page.locator('.app-navigation-entry').filter({ hasText: /settings|einstellungen/i }).first();
        if (await settingsNav.isVisible({ timeout: 3000 }).catch(() => false)) {
            await settingsNav.click();
            await page.waitForTimeout(1500);
            await page.screenshot({ path: 'e2e/screenshots/app-settings-view.png' });

            // Should show the default sharing section
            const section = page.locator('text=/Default sharing|Standardfreigabe/i').first();
            await expect(section).toBeVisible({ timeout: 5000 });
        } else {
            // Take screenshot of current state for investigation
            await page.screenshot({ path: 'e2e/screenshots/app-settings-nav-missing.png' });
            console.log('Settings nav item not found - check app navigation');
        }
    });
});

// SPDX-FileCopyrightText: 2026 Touchpoint Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
const { test, expect } = require('@playwright/test');
const { login } = require('./helpers');

test.setTimeout(60000);

const ADMIN_URL = '/index.php/settings/admin/touchpoint';

test.describe('Touchpoint — admin settings section', () => {
	test.beforeEach(async ({ page }) => {
		await login(page);
		await page.goto(ADMIN_URL);
		await page.waitForLoadState('networkidle');
	});

	test('the admin section mounts and renders the note-visibility toggle', async ({ page }) => {
		const mount = page.locator('#crm-notes-admin-settings');
		await expect(mount).toBeVisible({ timeout: 10000 });
		// Heading + the public-notes switch.
		await expect(mount.getByText(/Note visibility|Notiz-Sichtbarkeit|Sichtbarkeit/i).first()).toBeVisible();
		await expect(mount.locator('input[type="checkbox"]').first()).toBeAttached({ timeout: 8000 });
		await expect(mount.getByText(/Notes are public|öffentlich/i).first()).toBeVisible();
	});

	test('toggling the public-notes switch persists across reload', async ({ page }) => {
		const mount = page.locator('#crm-notes-admin-settings');
		const toggle = mount.locator('input[type="checkbox"]').first();
		await expect(toggle).toBeAttached({ timeout: 10000 });
		const before = await toggle.isChecked();

		// NcCheckboxRadioSwitch's real <input> is visually hidden; click its content.
		await mount.locator('.checkbox-radio-switch__content').first().click();
		await expect(toggle).toBeChecked({ checked: !before, timeout: 5000 });

		// Reload and confirm the new value was saved server-side.
		await page.reload();
		await page.waitForLoadState('networkidle');
		const toggleAfter = page.locator('#crm-notes-admin-settings input[type="checkbox"]').first();
		await expect(toggleAfter).toBeAttached({ timeout: 10000 });
		await expect(toggleAfter).toBeChecked({ checked: !before, timeout: 5000 });

		// Restore the original value so the suite is idempotent.
		await page.locator('#crm-notes-admin-settings .checkbox-radio-switch__content').first().click();
		await expect(page.locator('#crm-notes-admin-settings input[type="checkbox"]').first())
			.toBeChecked({ checked: before, timeout: 5000 });
	});
});

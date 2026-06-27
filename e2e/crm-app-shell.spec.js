// SPDX-FileCopyrightText: 2026 Touchpoint Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
const { test, expect } = require('@playwright/test');
const { login, openApp, gotoSection, RX } = require('./helpers');

test.setTimeout(60000);

test.describe('Touchpoint — app page shell', () => {
	test.beforeEach(async ({ page }) => {
		await login(page);
		await openApp(page);
	});

	test('app page loads with the three navigation sections', async ({ page }) => {
		const nav = page.locator('#app-navigation, .app-navigation').first();
		await expect(nav).toBeVisible();
		await expect(nav.getByText(RX.contacts).first()).toBeVisible();
		await expect(nav.getByText(RX.noteTypes).first()).toBeVisible();
		await expect(nav.getByText(RX.settings).first()).toBeVisible();
	});

	test('Contacts section renders the contact list and search', async ({ page }) => {
		await gotoSection(page, RX.contacts);
		// Search field for contacts.
		await expect(page.getByPlaceholder(/Search contacts|Kontakte suchen/i)).toBeVisible({ timeout: 10000 });
		// At least one contact item (the instance has thousands), or a documented empty state.
		const items = page.locator('.crm-contacts-list li');
		const hasItems = (await items.count()) > 0;
		const emptyState = page.locator('.crm-contacts-no-results, .empty-content');
		expect(hasItems || (await emptyState.count()) > 0).toBeTruthy();
	});

	test('All-notes view is shown when no contact is selected', async ({ page }) => {
		await gotoSection(page, RX.contacts);
		// With no contact selected the detail pane shows the All-notes view: either a
		// list of note cards (.crm-note-item) or the "No notes yet" empty content.
		const allNotes = page.locator('.crm-all-notes-view');
		const emptyOrNotes = page.locator('.crm-all-notes-view .crm-note-item, .crm-all-notes-view .empty-content, .empty-content');
		// The All-notes view (or its empty state) must be reachable.
		await expect(
			allNotes.or(page.getByRole('heading', { name: /All notes|Alle Notizen/i })).first(),
		).toBeVisible({ timeout: 10000 }).catch(async () => {
			await expect(emptyOrNotes.first()).toBeVisible({ timeout: 10000 });
		});
	});

	test('Note types section renders the types manager', async ({ page }) => {
		await gotoSection(page, RX.noteTypes);
		await expect(page.locator('.crm-note-types-view')).toBeVisible();
		await expect(page.getByRole('button', { name: RX.addType }).first()).toBeVisible();
		await expect(page.locator('.crm-type-item').first()).toBeVisible();
	});

	test('Settings section renders the per-user default-sharing form', async ({ page }) => {
		await gotoSection(page, RX.settings);
		await expect(page.locator('.crm-settings-view')).toBeVisible({ timeout: 10000 });
		await expect(page.getByText(/Default sharing|Standardfreigabe/i).first()).toBeVisible();
		// Search-users-or-groups combobox is present.
		await expect(page.getByText(/Search users or groups|Benutzer oder Gruppen/i).first()).toBeVisible();
	});
});

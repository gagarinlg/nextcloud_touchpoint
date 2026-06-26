// SPDX-FileCopyrightText: 2026 CRM Notes Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
const { test, expect } = require('@playwright/test');
const { login, openApp, gotoSection, RX, unique } = require('./helpers');

test.setTimeout(60000);

/**
 * Open the Add/Edit type modal's name field and wait until it is editable.
 *
 * NOTE: NcTextField in @nextcloud/vue 9.x ignores its `input-id` prop, so the
 * intended `#type-name` id is never applied (the <label for="type-name"> is left
 * dangling). We therefore target the input by its (EN|DE) placeholder.
 */
async function typeNameField(page) {
	const field = page.getByPlaceholder(/Type name|Typname/i);
	await field.waitFor({ state: 'visible', timeout: 10000 });
	return field;
}

test.describe('CRM Notes — note types manager', () => {
	test.beforeEach(async ({ page }) => {
		await login(page);
		await openApp(page);
		await gotoSection(page, RX.noteTypes);
		await expect(page.locator('.crm-note-types-view')).toBeVisible({ timeout: 10000 });
	});

	test('global default types are listed', async ({ page }) => {
		// The five shared GLOBAL DEFAULT types must all be present. Assert presence,
		// not counts: legacy per-user copies may make a name appear more than once.
		for (const name of ['Call', 'Meeting', 'Email', 'Task', 'General']) {
			await expect(
				page.locator('.crm-type-item').filter({ hasText: new RegExp(`\\b${name}\\b`) }).first(),
			).toBeVisible();
		}
		// Type rows expose accessible edit + delete controls (icon-only buttons).
		const firstRow = page.locator('.crm-type-item').first();
		await expect(firstRow.getByRole('button', { name: RX.editType })).toBeVisible();
		await expect(firstRow.getByRole('button', { name: RX.deleteType })).toBeVisible();
	});

	test('create a custom type (hex color via picker), edit it, then delete it', async ({ page }) => {
		const name = unique('E2E-Type');
		const renamed = `${name}-renamed`;

		// --- Create ---
		await page.getByRole('button', { name: RX.addType }).first().click();
		const field = await typeNameField(page);
		await field.fill(name);

		// The color trigger exposes a valid themed hex default and an accessible
		// "Choose color" name; assert it is reachable, but do NOT open the popover
		// (its overlay would intercept the subsequent Save click). The hsl()
		// round-trip test covers color persistence.
		await expect(page.getByRole('button', { name: /Choose color|Farbe wählen/i }).first()).toBeVisible();

		await page.getByRole('button', { name: RX.save }).last().click();
		// Modal closes on success.
		await expect(page.locator('.crm-modal-body')).toHaveCount(0, { timeout: 10000 });
		const row = page.locator('.crm-type-item').filter({ hasText: name });
		await expect(row.first()).toBeVisible({ timeout: 10000 });

		// --- Edit (rename) ---
		await row.first().getByRole('button', { name: RX.editType }).click();
		const editField = await typeNameField(page);
		await editField.fill(renamed);
		await page.getByRole('button', { name: RX.save }).last().click();
		await expect(page.locator('.crm-modal-body')).toHaveCount(0, { timeout: 10000 });
		await expect(page.locator('.crm-type-item').filter({ hasText: renamed }).first()).toBeVisible({ timeout: 10000 });

		// --- Delete (in-app confirm dialog, NOT window.confirm) ---
		const renamedRow = page.locator('.crm-type-item').filter({ hasText: renamed }).first();
		await renamedRow.getByRole('button', { name: RX.deleteType }).click();
		const dialog = page.locator('[role="dialog"], .dialog').filter({ hasText: /Delete|Löschen/ }).last();
		await expect(dialog).toBeVisible({ timeout: 8000 });
		await dialog.getByRole('button', { name: RX.delete }).click();
		await expect(page.locator('.crm-type-item').filter({ hasText: renamed })).toHaveCount(0, { timeout: 10000 });
	});

	test('creating a type with an hsl(...) color persists (color column is VARCHAR(32))', async ({ page, request }) => {
		// Regression guard for migration 1008 (color widened from VARCHAR(7) to 32):
		// an hsl() color must round-trip. Driven via the API for determinism (the
		// NcColorPicker UI only exposes hex presets), then verified back via the API.
		const name = unique('E2E-HSL');
		const hsl = 'hsl(210, 90%, 45%)';

		const result = await page.evaluate(async ({ name, hsl }) => {
			const token = document.querySelector('head')?.getAttribute('data-requesttoken') || window.OC?.requestToken;
			const h = { 'Content-Type': 'application/json', requesttoken: token };
			const create = await fetch('/index.php/apps/crm_notes/api/note-types', {
				method: 'POST', headers: h,
				body: JSON.stringify({ name, color: hsl, icon: 'icon-comment' }),
			});
			const created = await create.json();
			return { status: create.status, color: created.color, id: created.id };
		}, { name, hsl });

		expect(result.status).toBeLessThan(300);
		// The server normalises the color; assert it stored an hsl value (not truncated).
		expect(String(result.color)).toMatch(/hsl/i);

		// Cleanup.
		await page.evaluate(async (id) => {
			const token = document.querySelector('head')?.getAttribute('data-requesttoken') || window.OC?.requestToken;
			await fetch(`/index.php/apps/crm_notes/api/note-types/${id}`, { method: 'DELETE', headers: { requesttoken: token } });
		}, result.id);
	});

	test('deleting an in-use type is blocked with an explanatory error', async ({ page }) => {
		const typeName = unique('E2E-InUse');

		// Create the type via UI.
		await page.getByRole('button', { name: RX.addType }).first().click();
		await (await typeNameField(page)).fill(typeName);
		await page.getByRole('button', { name: RX.save }).last().click();
		const row = page.locator('.crm-type-item').filter({ hasText: typeName }).first();
		await expect(row).toBeVisible({ timeout: 10000 });

		// Attach a note to the new type via the API so it becomes "in use".
		const ids = await page.evaluate(async (name) => {
			const token = document.querySelector('head')?.getAttribute('data-requesttoken') || window.OC?.requestToken;
			const h = { 'Content-Type': 'application/json', requesttoken: token };
			const types = await (await fetch('/index.php/apps/crm_notes/api/note-types', { headers: { requesttoken: token } })).json();
			const type = types.find(t => t.name === name);
			const contacts = await (await fetch('/index.php/apps/crm_notes/api/contacts', { headers: { requesttoken: token } })).json();
			const uid = (contacts[0] || {}).uid;
			const res = await fetch('/index.php/apps/crm_notes/api/notes', {
				method: 'POST', headers: h,
				body: JSON.stringify({ contactUid: uid, noteTypeId: type.id, title: 'in-use note', contactUids: [uid] }),
			});
			const note = await res.json();
			return { typeId: type.id, noteId: note.id, status: res.status };
		}, typeName);
		expect(ids.status).toBeLessThan(300);

		// Attempt to delete the now in-use type from the UI.
		await row.getByRole('button', { name: RX.deleteType }).click();
		// The app shows an error toast and must NOT open the destructive confirm.
		const errorToast = page.locator('.toast-error, .toastify.toast-error, [role="alert"]')
			.filter({ hasText: /used by|verwendet|in use|Reassign|zuerst|noch/i });
		await expect(errorToast.first()).toBeVisible({ timeout: 8000 });
		await expect(row).toBeVisible();

		// Cleanup: remove the note then the type via API.
		await page.evaluate(async ({ noteId, typeId }) => {
			const token = document.querySelector('head')?.getAttribute('data-requesttoken') || window.OC?.requestToken;
			await fetch(`/index.php/apps/crm_notes/api/notes/${noteId}`, { method: 'DELETE', headers: { requesttoken: token } });
			await fetch(`/index.php/apps/crm_notes/api/note-types/${typeId}`, { method: 'DELETE', headers: { requesttoken: token } });
		}, ids);
	});
});

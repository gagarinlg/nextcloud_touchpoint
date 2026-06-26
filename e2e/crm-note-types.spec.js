// SPDX-FileCopyrightText: 2026 CRM Notes Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
const { test, expect } = require('@playwright/test');
const { login, openApp, gotoSection, RX, unique } = require('./helpers');

test.setTimeout(60000);

test.describe('CRM Notes — note types manager', () => {
	test.beforeEach(async ({ page }) => {
		await login(page);
		await openApp(page);
		await gotoSection(page, RX.noteTypes);
		// The view header should be present once the section is active.
		await expect(page.locator('.crm-note-types-view')).toBeVisible({ timeout: 10000 });
	});

	test('global default types are listed', async ({ page }) => {
		// The five shared GLOBAL DEFAULT types must all be present. We assert
		// presence (not counts): the instance may also carry legacy per-user copies,
		// so each name can legitimately appear more than once.
		for (const name of ['Call', 'Meeting', 'Email', 'Task', 'General']) {
			await expect(
				page.locator('.crm-type-item').filter({ hasText: new RegExp(`\\b${name}\\b`) }).first(),
			).toBeVisible();
		}
		// Every type row exposes accessible edit + delete controls.
		const firstRow = page.locator('.crm-type-item').first();
		await expect(firstRow.getByRole('button', { name: RX.editType })).toBeVisible();
		await expect(firstRow.getByRole('button', { name: RX.deleteType })).toBeVisible();
	});

	test('create a custom type (with color), edit it, then delete it', async ({ page }) => {
		const name = unique('E2E-Type');
		const renamed = `${name}-renamed`;

		// --- Create ---
		await page.getByRole('button', { name: RX.addType }).first().click();
		const modal = page.locator('.modal-container, .modal-mask').filter({ has: page.locator('.crm-modal-body') }).first();
		await expect(modal).toBeVisible();
		await page.locator('#type-name').fill(name);

		// Color picker: open it and pick the first preset swatch. The trigger button
		// carries the (EN|DE) "Choose color" accessible name.
		const colorTrigger = page.getByRole('button', { name: /Choose color|Farbe wählen/i }).first();
		if (await colorTrigger.isVisible().catch(() => false)) {
			await colorTrigger.click();
			// NcColorPicker renders a grid of preset swatches in a popover.
			const swatch = page.locator('.color-picker__simple-color-circle, .color-picker__simple button, [class*="color-picker"] button').first();
			if (await swatch.isVisible().catch(() => false)) {
				await swatch.click();
			}
			// Close the popover by pressing Escape if it stayed open.
			await page.keyboard.press('Escape').catch(() => {});
		}

		await page.getByRole('button', { name: RX.save }).last().click();
		// New row appears in the list.
		const row = page.locator('.crm-type-item').filter({ hasText: name });
		await expect(row.first()).toBeVisible({ timeout: 10000 });

		// --- Edit ---
		await row.first().getByRole('button', { name: RX.editType }).click();
		await expect(page.locator('#type-name')).toBeVisible();
		await page.locator('#type-name').fill(renamed);
		await page.getByRole('button', { name: RX.save }).last().click();
		await expect(page.locator('.crm-type-item').filter({ hasText: renamed }).first()).toBeVisible({ timeout: 10000 });

		// --- Delete (confirm via in-app dialog) ---
		const renamedRow = page.locator('.crm-type-item').filter({ hasText: renamed }).first();
		await renamedRow.getByRole('button', { name: RX.deleteType }).click();
		// In-app confirmation dialog (not window.confirm).
		const dialog = page.locator('.dialog, [role="dialog"]').filter({ hasText: /Delete|Löschen/ }).last();
		await expect(dialog).toBeVisible({ timeout: 8000 });
		await dialog.getByRole('button', { name: RX.delete }).click();
		await expect(page.locator('.crm-type-item').filter({ hasText: renamed })).toHaveCount(0, { timeout: 10000 });
	});

	test('deleting an in-use type is blocked with an explanatory error', async ({ page, request }) => {
		// Deleting a default type that has notes attached must be refused. The five
		// global defaults are typically in use (the app/listeners seed activity), but
		// to be deterministic we create a type, attach a note to it via the API, then
		// attempt the UI delete and assert the in-app error toast — no destructive
		// confirm dialog should even appear.
		const typeName = unique('E2E-InUse');

		// Create the type via UI.
		await page.getByRole('button', { name: RX.addType }).first().click();
		await page.locator('#type-name').fill(typeName);
		await page.getByRole('button', { name: RX.save }).last().click();
		const row = page.locator('.crm-type-item').filter({ hasText: typeName }).first();
		await expect(row).toBeVisible({ timeout: 10000 });

		// Resolve the new type id + a contact uid, then attach a note via the API
		// using the page's CSRF token so the type becomes "in use".
		const ids = await page.evaluate(async (name) => {
			const token = document.querySelector('head')?.getAttribute('data-requesttoken')
				|| window.OC?.requestToken;
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
		// The app shows an error toast and does NOT open the destructive confirm.
		const errorToast = page.locator('.toast-error, .toastify.toast-error, [role="alert"]')
			.filter({ hasText: /used by|verwendet|in use|Reassign|zuerst/i });
		await expect(errorToast.first()).toBeVisible({ timeout: 8000 });
		// Type row is still there (not deleted).
		await expect(row).toBeVisible();

		// Cleanup: remove the note then the type via API.
		await page.evaluate(async ({ noteId, typeId }) => {
			const token = document.querySelector('head')?.getAttribute('data-requesttoken') || window.OC?.requestToken;
			await fetch(`/index.php/apps/crm_notes/api/notes/${noteId}`, { method: 'DELETE', headers: { requesttoken: token } });
			await fetch(`/index.php/apps/crm_notes/api/note-types/${typeId}`, { method: 'DELETE', headers: { requesttoken: token } });
		}, ids);
	});
});

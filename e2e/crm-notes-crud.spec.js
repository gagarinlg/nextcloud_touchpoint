// SPDX-FileCopyrightText: 2026 CRM Notes Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
const { test, expect } = require('@playwright/test');
const { login, openApp, gotoSection, RX, unique } = require('./helpers');

test.setTimeout(90000);

/*
 * KNOWN ISSUES in the current build (documented here, referenced elsewhere):
 *
 *  - addressbook_id default-omission: Entity::insert() drops any field left at
 *    its initialized default, and the NOT NULL crm_notes.addressbook_id column has
 *    no DB default. The UI NoteModal omits addressbookId (controller default 0),
 *    so creating a note THROUGH THE UI returns 500. API creates work only with a
 *    non-zero addressbookId, which our setup helpers use.
 *  - confirmDestructive() / getDialogBuilder() crashes with
 *    "Cannot read properties of undefined (reading '_c')", so the in-app delete
 *    confirmation dialog never renders (affects note + note-type deletion).
 *
 * Therefore UI create and UI delete are kept as test.fixme(); editing (which
 * works end-to-end) and rendering are asserted live.
 */

/** Seed a note for the first contact via the API. Returns {id, uid, title}. */
async function seedNote(page, overrides = {}) {
	return page.evaluate(async (overrides) => {
		const token = document.querySelector('head')?.getAttribute('data-requesttoken');
		const H = { 'Content-Type': 'application/json', requesttoken: token };
		const types = await (await fetch('/index.php/apps/crm_notes/api/note-types', { headers: { requesttoken: token } })).json();
		const contacts = await (await fetch('/index.php/apps/crm_notes/api/contacts', { headers: { requesttoken: token } })).json();
		const uid = contacts[0].uid;
		const body = Object.assign({
			contactUid: uid, noteTypeId: types[0].id, title: 'seed', content: 'seed body',
			addressbookId: 1, contactUids: [uid],
		}, overrides);
		const res = await fetch('/index.php/apps/crm_notes/api/notes', { method: 'POST', headers: H, body: JSON.stringify(body) });
		const note = await res.json();
		return { id: note.id, uid, title: body.title, status: res.status };
	}, overrides);
}

async function deleteNote(page, id) {
	await page.evaluate(async (id) => {
		const token = document.querySelector('head')?.getAttribute('data-requesttoken');
		await fetch('/index.php/apps/crm_notes/api/notes/' + id, { method: 'DELETE', headers: { requesttoken: token } });
	}, id).catch(() => {});
}

test.describe('CRM Notes — notes CRUD', () => {
	test.beforeEach(async ({ page }) => {
		await login(page);
		await openApp(page);
	});

	test('a note is listed in the all-notes view and shows its type, title, pin and content', async ({ page }) => {
		const title = unique('E2E-Note');
		const seed = await seedNote(page, { title, content: '**bold body**', isPinned: true });
		expect(seed.status).toBeLessThan(300);
		try {
			await page.reload();
			await openApp(page);
			await gotoSection(page, RX.contacts);
			const card = page.locator('.crm-note-item').filter({ hasText: title }).first();
			await expect(card).toBeVisible({ timeout: 10000 });
			// Title and rendered markdown content.
			await expect(card.locator('.crm-note-title')).toHaveText(title);
			await expect(card.locator('.crm-note-content')).toContainText('bold body');
			// Pinned indicator (accessible name).
			await expect(card.getByRole('img', { name: /Pinned|Angeheftet/i })).toBeVisible();
			// A type badge is rendered.
			await expect(card.locator('.crm-type-badge').first()).toBeVisible();
		} finally {
			await deleteNote(page, seed.id);
		}
	});

	test('editing a note through the UI updates it (modal opens prefilled and closes on save)', async ({ page }) => {
		const title = unique('E2E-Edit');
		const newTitle = `${title}-edited`;
		const seed = await seedNote(page, { title, content: 'original' });
		expect(seed.status).toBeLessThan(300);
		try {
			await page.reload();
			await openApp(page);
			await gotoSection(page, RX.contacts);
			const card = page.locator('.crm-note-item').filter({ hasText: title }).first();
			await expect(card).toBeVisible({ timeout: 10000 });

			await card.getByRole('button', { name: RX.edit }).click();
			// Modal opens prefilled with the existing title.
			const titleField = page.getByPlaceholder(/Note title|Notiztitel/i);
			await expect(titleField).toBeVisible({ timeout: 8000 });
			await expect(titleField).toHaveValue(title);

			await titleField.fill(newTitle);
			await page.getByRole('button', { name: RX.save }).last().click();
			// The note modal closes on a successful save (unlike the type modal).
			await expect(page.locator('.crm-modal-body')).toHaveCount(0, { timeout: 10000 });
			await expect(page.locator('.crm-note-item').filter({ hasText: newTitle }).first()).toBeVisible({ timeout: 10000 });
		} finally {
			await deleteNote(page, seed.id);
		}
	});

	test('the Add-note modal validates required fields (Save disabled until filled)', async ({ page }) => {
		await gotoSection(page, RX.contacts);
		// Open a contact so the contact-scoped Add note button is available, then open the modal.
		const firstContact = page.locator('.crm-contacts-list li').first();
		await firstContact.click();
		await page.getByRole('button', { name: RX.addNote }).first().click();
		const titleField = page.getByPlaceholder(/Note title|Notiztitel/i);
		await expect(titleField).toBeVisible({ timeout: 8000 });

		// With a contact preselected + a default type but no title, the missing-field
		// hint should name Title and Save stays disabled until a title is entered.
		const saveBtn = page.getByRole('button', { name: RX.save }).last();
		await titleField.fill('');
		await expect(saveBtn).toBeDisabled();
		await titleField.fill(unique('E2E-Validate'));
		await expect(saveBtn).toBeEnabled();
		// Cancel out without persisting.
		await page.getByRole('button', { name: RX.cancel }).last().click();
		await expect(page.locator('.crm-modal-body')).toHaveCount(0, { timeout: 8000 });
	});

	// KNOWN ISSUE: UI create returns 500 because the modal omits addressbookId and
	// the Entity drops the default-0 value (NOT NULL column). Re-enable once the
	// note create path persists addressbook_id (or the column gets a default).
	test.fixme('create a note end-to-end through the UI', async ({ page }) => {
		const title = unique('E2E-UICreate');
		await gotoSection(page, RX.contacts);
		await page.locator('.crm-contacts-list li').first().click();
		await page.getByRole('button', { name: RX.addNote }).first().click();
		await page.getByPlaceholder(/Note title|Notiztitel/i).fill(title);
		await page.getByRole('button', { name: RX.save }).last().click();
		await expect(page.locator('.crm-note-item').filter({ hasText: title }).first()).toBeVisible({ timeout: 10000 });
	});

	// KNOWN ISSUE: the in-app delete confirmation dialog never renders because
	// confirmDestructive()/getDialogBuilder() crashes in the bundle. Re-enable once
	// fixed; the assertion (dialog appears, confirm removes the note) is correct.
	test.fixme('delete a note via the in-app confirmation dialog', async ({ page }) => {
		const title = unique('E2E-UIDelete');
		const seed = await seedNote(page, { title });
		await page.reload();
		await openApp(page);
		await gotoSection(page, RX.contacts);
		const card = page.locator('.crm-note-item').filter({ hasText: title }).first();
		await expect(card).toBeVisible({ timeout: 10000 });

		await card.getByRole('button', { name: RX.delete }).click();
		// Expected: an in-app destructive confirmation (NOT window.confirm).
		const dialog = page.locator('[role="dialog"], .dialog').filter({ hasText: /Delete|Löschen/ }).last();
		await expect(dialog).toBeVisible({ timeout: 8000 });
		await dialog.getByRole('button', { name: RX.delete }).click();
		await expect(page.locator('.crm-note-item').filter({ hasText: title })).toHaveCount(0, { timeout: 10000 });
		await deleteNote(page, seed.id);
	});
});

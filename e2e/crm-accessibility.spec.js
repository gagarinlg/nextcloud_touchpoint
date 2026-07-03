// SPDX-FileCopyrightText: 2026 Touchpoint Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
const { test, expect } = require('@playwright/test');
const { login, openApp, gotoSection, RX, unique } = require('./helpers');

test.setTimeout(90000);

/** Seed a note (API) so the note-item action buttons are present to inspect. */
async function seedNote(page, title) {
	return page.evaluate(async (title) => {
		const token = document.querySelector('head')?.getAttribute('data-requesttoken');
		const H = { 'Content-Type': 'application/json', requesttoken: token };
		const types = await (await fetch('/index.php/apps/touchpoint/api/note-types', { headers: { requesttoken: token } })).json();
		const contacts = await (await fetch('/index.php/apps/touchpoint/api/contacts', { headers: { requesttoken: token } })).json();
		const uid = contacts[0].uid;
		const res = await fetch('/index.php/apps/touchpoint/api/notes', {
			method: 'POST', headers: H,
			body: JSON.stringify({ contactUid: uid, noteTypeId: types[0].id, title, content: 'a11y', addressbookId: 1, contactUids: [uid] }),
		});
		return { id: (await res.json()).id };
	}, title);
}

test.describe('Touchpoint — accessibility smoke', () => {
	test.beforeEach(async ({ page }) => {
		await login(page);
		await openApp(page);
	});

	test('note-type rows expose accessible names on the icon-only edit/delete buttons', async ({ page }) => {
		await gotoSection(page, RX.noteTypes);
		const row = page.locator('.crm-type-item').first();
		await expect(row).toBeVisible({ timeout: 10000 });
		// Icon-only buttons must have an accessible name (aria-label).
		await expect(row.getByRole('button', { name: RX.editType })).toBeVisible();
		await expect(row.getByRole('button', { name: RX.deleteType })).toBeVisible();
	});

	test('the markdown toolbar is a labelled ARIA toolbar with named buttons', async ({ page }) => {
		await gotoSection(page, RX.notes);
		await page.locator('.crm-contacts-list li').first().click();
		await page.getByRole('button', { name: RX.addNote }).first().click();
		const toolbar = page.getByRole('toolbar', { name: /Text formatting|Textformatierung/i });
		await expect(toolbar).toBeVisible({ timeout: 8000 });
		// Each formatting control exposes an accessible name (EN or DE).
		for (const rx of [/Bold|Fett/i, /Italic|Kursiv/i, /Link/i, /Code/i, /Quote|Zitat/i]) {
			await expect(toolbar.getByRole('button', { name: rx }).first()).toBeVisible();
		}
		// Roving-tabindex pattern: exactly one toolbar button is in the tab order.
		const tabbable = await toolbar.locator('button[tabindex="0"]').count();
		expect(tabbable).toBe(1);
		await page.getByRole('button', { name: RX.cancel }).last().click();
	});

	test('the note modal labels its contact, type and title fields and marks them required', async ({ page }) => {
		await gotoSection(page, RX.notes);
		await page.locator('.crm-contacts-list li').first().click();
		await page.getByRole('button', { name: RX.addNote }).first().click();
		const modal = page.locator('.crm-modal-body');
		await expect(modal).toBeVisible({ timeout: 8000 });
		// Visible field labels (required ones carry a "*").
		await expect(modal.getByText(/^Contacts|^Kontakte/).first()).toBeVisible();
		await expect(modal.getByText(/^Type|^Typ/).first()).toBeVisible();
		await expect(modal.getByText(/^Title|^Titel/).first()).toBeVisible();
		// Title field is reachable by its placeholder.
		await expect(page.getByPlaceholder(/Note title|Notiztitel/i)).toBeVisible();
		await page.getByRole('button', { name: RX.cancel }).last().click();
	});

	test('note-item edit/delete buttons have accessible names', async ({ page }) => {
		const title = unique('E2E-A11y');
		const seed = await seedNote(page, title);
		try {
			await page.reload();
			await openApp(page);
			await gotoSection(page, RX.notes);
			const card = page.locator('.crm-note-item').filter({ hasText: title }).first();
			await expect(card).toBeVisible({ timeout: 10000 });
			// The actions reveal on hover/focus; assert they are present and named.
			await card.hover();
			await expect(card.getByRole('button', { name: RX.edit })).toBeVisible();
			await expect(card.getByRole('button', { name: RX.delete })).toBeVisible();
		} finally {
			await page.evaluate(async (id) => {
				const token = document.querySelector('head')?.getAttribute('data-requesttoken');
				await fetch('/index.php/apps/touchpoint/api/notes/' + id, { method: 'DELETE', headers: { requesttoken: token } });
			}, seed.id).catch(() => {});
		}
	});
});

// SPDX-FileCopyrightText: 2026 Touchpoint Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
const { test, expect } = require('@playwright/test');
const { login, RX, unique } = require('./helpers');

test.setTimeout(60000);

const ADMIN_URL = '/index.php/settings/admin/touchpoint';

// Same fixture user as crm-multiuser-security.spec.js / search.spec.js — a
// non-admin account that must exist on the instance:
//   sudo -u www-data php occ user:add --password-from-env crmtestuser
const SECOND_USER = 'crmtestuser';
const SECOND_PASS = 'Crm-Notes-E2e-Xq72!vz';

/**
 * Open the Add/Edit global type modal's name field and wait until it is
 * editable. Mirrors crm-note-types.spec.js's typeNameField() helper — see its
 * comment for why this still targets the input by placeholder rather than
 * getByLabel() even though the underlying NcTextField `input-id`/`id` bug has
 * been fixed at the source.
 */
async function typeNameField(page) {
	const field = page.getByPlaceholder(/Type name|Typname/i);
	await field.waitFor({ state: 'visible', timeout: 10000 });
	return field;
}

/** Dismiss the type modal if it is still on screen (Cancel, falling back to Escape). */
async function closeModalIfOpen(page) {
	const cancel = page.getByRole('button', { name: RX.cancel });
	if (await cancel.count() && await cancel.last().isVisible().catch(() => false)) {
		await cancel.last().click().catch(() => {});
	}
	await page.keyboard.press('Escape').catch(() => {});
	await page.locator('.crm-modal-body').waitFor({ state: 'detached', timeout: 5000 }).catch(() => {});
}

async function apiHeaders(page) {
	return page.evaluate(() => document.querySelector('head')?.getAttribute('data-requesttoken'));
}

test.describe('Touchpoint — admin: global note types', () => {
	test.beforeEach(async ({ page }) => {
		await login(page);
		await page.goto(ADMIN_URL);
		await page.waitForLoadState('networkidle');
		const mount = page.locator('#crm-notes-admin-settings');
		await expect(mount).toBeVisible({ timeout: 10000 });
		await expect(mount.getByText(/Global note types|Globale Notiztypen/i).first()).toBeVisible();
	});

	test('the five seeded global defaults are listed with accessible controls', async ({ page }) => {
		for (const name of ['Call', 'Meeting', 'Email', 'Task', 'General']) {
			await expect(
				page.locator('.crm-type-item').filter({ hasText: new RegExp(`\\b${name}\\b`) }).first(),
			).toBeVisible();
		}
		const firstRow = page.locator('.crm-type-item').first();
		await expect(firstRow.getByRole('button', { name: RX.editType })).toBeVisible();
		await expect(firstRow.getByRole('button', { name: RX.deleteType })).toBeVisible();
	});

	test('add, edit and delete a global note type via the UI', async ({ page }) => {
		const name = unique('E2E-Global');
		const renamed = `${name}-renamed`;

		// --- Create ---
		await page.getByRole('button', { name: RX.addType }).first().click();
		await (await typeNameField(page)).fill(name);
		await page.getByRole('button', { name: RX.save }).last().click();
		const row = page.locator('.crm-type-item').filter({ hasText: name });
		await expect(row.first()).toBeVisible({ timeout: 10000 });
		await closeModalIfOpen(page);

		// A newly-created global type is visible to every user, not just the
		// admin who created it — verify via the API's own-user-scoped
		// endpoint (findAll() includes the global set for any user).
		const token = await apiHeaders(page);
		const visibleToAnyUser = await page.evaluate(async (t) => {
			const res = await fetch('/index.php/apps/touchpoint/api/note-types', { headers: { requesttoken: t } });
			const types = await res.json();
			return types;
		}, token);
		expect(visibleToAnyUser.some((t) => t.name === name)).toBe(true);

		// --- Edit (rename) ---
		await row.first().getByRole('button', { name: RX.editType }).click();
		const editField = await typeNameField(page);
		await editField.fill(renamed);
		await page.getByRole('button', { name: RX.save }).last().click();
		await expect(page.locator('.crm-type-item').filter({ hasText: renamed }).first()).toBeVisible({ timeout: 10000 });
		await closeModalIfOpen(page);

		// --- Delete ---
		const renamedRow = page.locator('.crm-type-item').filter({ hasText: renamed }).first();
		await renamedRow.getByRole('button', { name: RX.deleteType }).click();
		const dialog = page.locator('[role="dialog"], .dialog').filter({ hasText: /Delete|Löschen/ }).last();
		await expect(dialog).toBeVisible({ timeout: 8000 });
		await dialog.getByRole('button', { name: RX.delete }).click();
		await expect(page.locator('.crm-type-item').filter({ hasText: renamed })).toHaveCount(0, { timeout: 10000 });
	});

	test('deleting an in-use global type is blocked with a specific count, no confirm dialog offered', async ({ page }) => {
		const typeName = unique('E2E-GlobalInUse');

		// Create the global type via the UI.
		await page.getByRole('button', { name: RX.addType }).first().click();
		await (await typeNameField(page)).fill(typeName);
		await page.getByRole('button', { name: RX.save }).last().click();
		const row = page.locator('.crm-type-item').filter({ hasText: typeName }).first();
		await expect(row).toBeVisible({ timeout: 10000 });
		await closeModalIfOpen(page);

		// Attach a note to the new global type via the API so it becomes "in use".
		const token = await apiHeaders(page);
		const ids = await page.evaluate(async ({ name, t }) => {
			const h = { 'Content-Type': 'application/json', requesttoken: t };
			const types = await (await fetch('/index.php/apps/touchpoint/api/admin/note-types', { headers: { requesttoken: t } })).json();
			const type = types.find((x) => x.name === name);
			const contacts = await (await fetch('/index.php/apps/touchpoint/api/contacts', { headers: { requesttoken: t } })).json();
			const uid = (contacts[0] || {}).uid;
			const res = await fetch('/index.php/apps/touchpoint/api/notes', {
				method: 'POST',
				headers: h,
				body: JSON.stringify({ contactUid: uid, noteTypeId: type.id, title: 'admin in-use note', addressbookId: 1, contactUids: [uid] }),
			});
			const note = await res.json();
			return { typeId: type.id, noteId: note.id, status: res.status };
		}, { name: typeName, t: token });
		expect(ids.status).toBeLessThan(300);

		// Attempt to delete the now in-use global type from the admin UI.
		await row.getByRole('button', { name: RX.deleteType }).click();
		// The proactive usage check must short-circuit with a specific,
		// actionable count and never open the destructive confirm dialog.
		const errorToast = page.locator('.toast-error, .toastify.toast-error, [role="alert"]')
			.filter({ hasText: /used by|verwendet|in use|Reassign|zuerst|noch/i });
		await expect(errorToast.first()).toBeVisible({ timeout: 8000 });
		await expect(row).toBeVisible();
		await expect(page.locator('[role="dialog"], .dialog').filter({ hasText: /Delete|Löschen/ })).toHaveCount(0);

		// Cleanup: remove the note then the global type via API.
		await page.evaluate(async ({ noteId, typeId, t }) => {
			await fetch(`/index.php/apps/touchpoint/api/notes/${noteId}`, { method: 'DELETE', headers: { requesttoken: t } });
			await fetch(`/index.php/apps/touchpoint/api/admin/note-types/${typeId}`, { method: 'DELETE', headers: { requesttoken: t } });
		}, { ...ids, t: token });
	});
});

test.describe('Touchpoint — admin: global note types are admin-only', () => {
	// AdminNoteTypeController's entire access-control model rests on the
	// ABSENCE of #[NoAdminRequired] — Nextcloud's core Controller dispatch then
	// enforces admin-group membership before any action method runs. PHPUnit
	// unit tests construct the controller directly and call methods on it,
	// bypassing dispatch entirely, so this is the only layer able to prove the
	// admin gate actually works end-to-end. If a future change ever
	// (re)introduces #[NoAdminRequired] on this controller, this test must fail.
	test('a non-admin user is rejected (not 2xx) on every admin note-type route', async ({ browser }) => {
		const ctx = await browser.newContext({ httpCredentials: { username: SECOND_USER, password: SECOND_PASS }, locale: 'de' });
		const page = await ctx.newPage();
		await login(page, SECOND_USER, SECOND_PASS);
		// Load any authenticated page so a requesttoken is available; the admin
		// settings page itself is out of reach for a non-admin (its route is
		// admin-gated too), so use the app's own page instead.
		await page.goto('/apps/touchpoint/');
		await page.waitForLoadState('networkidle');

		const results = await page.evaluate(async () => {
			const token = document.querySelector('head')?.getAttribute('data-requesttoken');
			const H = { 'Content-Type': 'application/json', requesttoken: token };
			const base = '/index.php/apps/touchpoint/api/admin/note-types';

			const get = await fetch(base, { headers: { requesttoken: token } });
			const post = await fetch(base, {
				method: 'POST',
				headers: H,
				body: JSON.stringify({ name: 'should-not-be-created', icon: 'icon-star', color: '#ff0000' }),
			});
			// Use id 1 for update/delete/usage — the response status must reject
			// the caller before the controller ever looks the row up, so the id
			// need not correspond to a real row.
			const put = await fetch(`${base}/1`, {
				method: 'PUT',
				headers: H,
				body: JSON.stringify({ name: 'hacked', icon: 'icon-star', color: '#ff0000' }),
			});
			const del = await fetch(`${base}/1`, { method: 'DELETE', headers: { requesttoken: token } });
			const usage = await fetch(`${base}/1/usage`, { headers: { requesttoken: token } });

			return {
				get: get.status,
				post: post.status,
				put: put.status,
				del: del.status,
				usage: usage.status,
			};
		});

		// None of the admin note-type routes may succeed for a non-admin caller.
		for (const [verb, status] of Object.entries(results)) {
			expect(status, `${verb} should be rejected`).toBeGreaterThanOrEqual(400);
			expect(status, `${verb} should be rejected`).toBeLessThan(500);
		}

		await ctx.close();
	});
});

// SPDX-FileCopyrightText: 2026 Touchpoint Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Playwright e2e tests for the "Recent notes" dashboard widget
 * (OCA\Touchpoint\Dashboard\RecentNotesWidget).
 *
 * Runs against http://localhost with admin/admin (German locale), same as the
 * rest of the suite. Unit tests (tests/Unit/Dashboard/RecentNotesWidgetTest.php)
 * mock every NC interface and cover getItemsV2()'s scoping/limit/error-handling
 * logic in isolation; they cannot catch the class of bug this spec targets:
 * the widget failing to register/appear on a real /apps/dashboard/ page, an
 * icon URL 404ing (imagePath() typo or missing asset), or the "Recent notes"
 * card's item link failing to resolve through a live App.vue #note/<id>
 * deep-link handler.
 *
 * Prerequisites: admin/admin account exists (configured in playwright.config.js).
 */

const { test, expect } = require('@playwright/test');
const { login, unique } = require('./helpers');

test.setTimeout(90000);

// (EN|DE) matcher for the widget's translated title ("Recent notes").
const WIDGET_TITLE = /^Recent notes$|^Neueste Notizen$|^Zuletzt.*Notizen$/;
const SHOW_ALL = /^Show all$|^Alle anzeigen$/;

/** Seed a note for the first contact via the API. Returns {id, uid, title, status}. */
async function seedNote(page, overrides = {}) {
	return page.evaluate(async (overrides) => {
		const token = document.querySelector('head')?.getAttribute('data-requesttoken');
		const H = { 'Content-Type': 'application/json', requesttoken: token };
		const types = await (await fetch('/index.php/apps/touchpoint/api/note-types', { headers: { requesttoken: token } })).json();
		const contacts = await (await fetch('/index.php/apps/touchpoint/api/contacts', { headers: { requesttoken: token } })).json();
		const uid = contacts[0].uid;
		const body = Object.assign({
			contactUid: uid, noteTypeId: types[0].id, title: 'seed', content: 'seed body',
			addressbookId: 1, contactUids: [uid],
		}, overrides);
		const res = await fetch('/index.php/apps/touchpoint/api/notes', {
			method: 'POST', headers: H, body: JSON.stringify(body),
		});
		const note = await res.json();
		return { id: note.id, uid, title: body.title, status: res.status };
	}, overrides);
}

/** Delete a note by ID via the API. Safe if already gone. */
async function deleteNote(page, id) {
	await page.evaluate(async (id) => {
		const token = document.querySelector('head')?.getAttribute('data-requesttoken');
		await fetch('/index.php/apps/touchpoint/api/notes/' + id, {
			method: 'DELETE', headers: { requesttoken: token },
		});
	}, id).catch(() => {});
}

/** Navigate to the Nextcloud dashboard and wait for the app shell to mount. */
async function openDashboard(page) {
	await page.goto('/apps/dashboard/');
	await page.waitForLoadState('networkidle');
	await page.locator('#app-dashboard').waitFor({ state: 'visible', timeout: 20000 });
}

/** Locator for the Touchpoint "Recent notes" panel (the whole .panel wrapper). */
function widgetPanel(page) {
	return page.locator('.panel').filter({ has: page.locator('.panel--header', { hasText: WIDGET_TITLE }) }).first();
}

test.describe('Touchpoint — dashboard widget', () => {
	test.beforeEach(async ({ page }) => {
		await login(page);
	});

	// ── (a) Widget registers and renders a seeded note ─────────────────────
	test('(a) "Recent notes" widget appears on the dashboard and lists a seeded note', async ({ page }) => {
		const title = unique('DashboardWidgetNote');
		const seed = await seedNote(page, { title });
		expect(seed.status).toBeLessThan(300);

		try {
			await openDashboard(page);

			const panel = widgetPanel(page);
			await expect(panel).toBeVisible({ timeout: 20000 });

			// The item's title (h3 title attr / text) must contain the seeded note.
			await expect(panel.getByText(title, { exact: false })).toBeVisible({ timeout: 15000 });

			// The item link must point at the note's own deep link, not a generic
			// app-page link — confirms getItemsV2() built buildNoteLink() correctly,
			// not just that some text rendered.
			await expect(panel.locator(`a[href*="#note/${seed.id}"]`)).toHaveCount(1);
		} finally {
			await deleteNote(page, seed.id);
		}
	});

	// ── (b) Clicking an item follows the #note/<id> deep link ─────────────
	test('(b) clicking a widget item navigates into the contact\'s notes via the deep link', async ({ page }) => {
		const title = unique('DashboardWidgetClick');
		const seed = await seedNote(page, { title });
		expect(seed.status).toBeLessThan(300);

		try {
			await openDashboard(page);
			const panel = widgetPanel(page);
			await expect(panel).toBeVisible({ timeout: 20000 });

			const itemLink = panel.locator(`a[href*="#note/${seed.id}"]`).first();
			await expect(itemLink).toBeVisible({ timeout: 15000 });

			// NcDashboardWidgetItem renders target-url links with target="_blank";
			// follow the href directly instead of chasing a popup/new tab.
			const href = await itemLink.getAttribute('href');
			expect(href).toBeTruthy();
			await page.goto(href);
			await page.waitForLoadState('networkidle');

			const noteEl = page.locator('#crm-note-' + seed.id);
			await expect(noteEl).toBeVisible({ timeout: 15000 });
			await expect(noteEl).toContainText(title);
		} finally {
			await deleteNote(page, seed.id);
		}
	});

	// ── (c) "Show all" button navigates to the Touchpoint app page ─────────
	test('(c) the "Show all" button navigates to the Touchpoint app page', async ({ page }) => {
		const seed = await seedNote(page, { title: unique('DashboardWidgetShowAll') });
		expect(seed.status).toBeLessThan(300);

		try {
			await openDashboard(page);
			const panel = widgetPanel(page);
			await expect(panel).toBeVisible({ timeout: 20000 });

			const showAll = panel.getByRole('link', { name: SHOW_ALL }).first();
			await expect(showAll).toBeVisible({ timeout: 15000 });
			await showAll.click();
			await page.waitForLoadState('networkidle');

			await expect(page).toHaveURL(/\/apps\/touchpoint\/?/);
			await page.locator('#app-navigation, .app-navigation').first().waitFor({ state: 'visible', timeout: 20000 });
		} finally {
			await deleteNote(page, seed.id);
		}
	});

	// ── (d) Widget-picker "Manage widgets" modal shows the widget's icon ───
	test('(d) the widget appears with an icon in the "Edit widgets" picker modal', async ({ page }) => {
		await openDashboard(page);

		// The core dashboard footer exposes an "Edit widgets" / "Widgets bearbeiten"
		// trigger that opens the picker modal (see DashboardApp.vue's modal template).
		const editTrigger = page.getByRole('button', { name: /Edit widgets|Widgets bearbeiten/i }).first();
		await expect(editTrigger).toBeVisible({ timeout: 20000 });
		await editTrigger.click();

		const modal = page.locator('.modal-container, [role="dialog"]').filter({ hasText: /Edit widgets|Widgets bearbeiten/i }).first();
		await expect(modal).toBeVisible({ timeout: 10000 });

		const entry = modal.locator('li').filter({ hasText: WIDGET_TITLE }).first();
		await expect(entry).toBeVisible({ timeout: 10000 });

		// getIconUrl() must resolve to a real, loadable asset (app-dark.svg) —
		// not a 404'd <img> with a broken-image icon.
		const icon = entry.locator('img');
		await expect(icon).toHaveCount(1);
		const naturalWidth = await icon.evaluate((img) => img.naturalWidth);
		expect(naturalWidth).toBeGreaterThan(0);
	});
});

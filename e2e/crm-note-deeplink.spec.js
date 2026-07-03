// SPDX-FileCopyrightText: 2026 Touchpoint Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
const { test, expect } = require('@playwright/test');
const { login, openApp, unique } = require('./helpers');

test.setTimeout(90000);

/*
 * Notifications for "note shared with you" and "@mention in note" deep-link to
 * /apps/touchpoint#note/<id>. App.vue's hash handler must resolve that id to
 * the note's contact, land on the contact notes view, highlight/scroll to the
 * note, and normalise the URL to #contact/<uid> — see App.vue applyNoteDeepLink().
 */

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

/**
 * Seed `count` notes for the same contact via the API, sequentially (so their
 * created_at/id ordering is deterministic). Returns {ids, uid}. The default
 * contact-notes sort is 'newest' (created_at DESC, id DESC tiebreaker — see
 * NoteMapper::findAll()), so the FIRST note seeded here ends up LAST in the
 * list, i.e. beyond the first page once more than PAGE_SIZE (50) notes exist.
 */
async function seedManyNotes(page, count, overrides = {}) {
	return page.evaluate(async ({ count, overrides }) => {
		const token = document.querySelector('head')?.getAttribute('data-requesttoken');
		const H = { 'Content-Type': 'application/json', requesttoken: token };
		const types = await (await fetch('/index.php/apps/touchpoint/api/note-types', { headers: { requesttoken: token } })).json();
		const contacts = await (await fetch('/index.php/apps/touchpoint/api/contacts', { headers: { requesttoken: token } })).json();
		const uid = contacts[0].uid;
		const ids = [];
		for (let i = 0; i < count; i++) {
			const body = Object.assign({
				contactUid: uid, noteTypeId: types[0].id, title: 'bulk-seed-' + i, content: 'bulk seed body',
				addressbookId: 1, contactUids: [uid],
			}, i === 0 ? overrides : {});
			const res = await fetch('/index.php/apps/touchpoint/api/notes', {
				method: 'POST', headers: H, body: JSON.stringify(body),
			});
			const note = await res.json();
			ids.push(note.id);
		}
		return { ids, uid };
	}, { count, overrides });
}

test.describe('Touchpoint — #note/{id} deep link', () => {
	// ── (a) Happy path: fetch, navigate, highlight, normalise the hash ─────
	test('(a) #note/<id> loads the note\'s contact, highlights the note, and normalises the hash', async ({ page }) => {
		await login(page);
		await openApp(page);
		const title = unique('DeepLinkNote');
		const seed = await seedNote(page, { title });
		expect(seed.status).toBeLessThan(300);

		try {
			await page.goto('/apps/touchpoint/#note/' + seed.id);
			await page.waitForLoadState('networkidle');

			// The contact notes view is reached and shows the seeded note.
			const noteEl = page.locator('#crm-note-' + seed.id);
			await expect(noteEl).toBeVisible({ timeout: 15000 });
			await expect(noteEl).toContainText(title);

			// It carries the highlight class immediately after navigation.
			await expect(noteEl).toHaveClass(/is-highlighted/);

			// The URL is normalised to #contact/<uid> (not left on #note/<id>),
			// so a reload or a shared link lands on the contact view directly.
			await expect(page).toHaveURL(new RegExp('#contact/' + encodeURIComponent(seed.uid).replace(/[.*+?^${}()|[\]\\]/g, '\\$&')));
		} finally {
			await deleteNote(page, seed.id);
		}
	});

	// ── (b) hashchange while already on the app (not just initial load) ────
	test('(b) navigating to #note/<id> via hashchange (already-open app) highlights the note', async ({ page }) => {
		await login(page);
		await openApp(page);
		const title = unique('DeepLinkHashchange');
		const seed = await seedNote(page, { title });
		expect(seed.status).toBeLessThan(300);

		try {
			// App is already open and mounted; changing the hash must trigger the
			// same handling as a fresh navigation (App.vue's hashchange listener).
			await page.evaluate((id) => { window.location.hash = '#note/' + id; }, seed.id);

			const noteEl = page.locator('#crm-note-' + seed.id);
			await expect(noteEl).toBeVisible({ timeout: 15000 });
			await expect(noteEl).toContainText(title);
		} finally {
			await deleteNote(page, seed.id);
		}
	});

	// ── (c) Note not found — toast error, app shell stays intact ────────────
	test('(c) #note/<id> for a non-existent id shows a toast error', async ({ page }) => {
		await login(page);
		await openApp(page);

		// A very large id that (almost certainly) does not exist.
		await page.goto('/apps/touchpoint/#note/999999999');
		await page.waitForLoadState('networkidle');

		// NcNoteHandler / @nextcloud/dialogs renders toasts as .toastify elements.
		await expect(page.locator('.toastify').filter({ hasText: /not.*found|no.*access|nicht gefunden|keinen Zugriff/i }).first())
			.toBeVisible({ timeout: 15000 });

		// The app shell must remain mounted (no crash).
		await expect(page.locator('#app-navigation, .app-navigation').first()).toBeVisible({ timeout: 15000 });

		// The failed '#note/<id>' hash is stripped from the URL bar (not left
		// dangling) so a reload of this tab lands on the plain app page instead
		// of re-attempting and re-failing the same fetch/toast indefinitely.
		await expect.poll(() => page.evaluate(() => window.location.hash)).toBe('');
	});

	// ── (d) Cross-user access denial — toast error, no data leak ───────────
	//
	// Only meaningful when the instance's "notes are public" admin setting is
	// off; when it's on, NoteService::find() intentionally allows any
	// authenticated user to read any note (see SettingsService::isNotesPublic()),
	// so there is nothing to deny and the test is skipped with an annotation
	// rather than asserting a false premise.
	test('(d) #note/<id> for a note the caller cannot access shows a toast error', async ({ page, browser }) => {
		await login(page);
		const settings = await page.evaluate(async () => {
			const token = document.querySelector('head')?.getAttribute('data-requesttoken');
			const res = await fetch('/index.php/apps/touchpoint/api/settings', { headers: { requesttoken: token } });
			return res.ok ? res.json() : null;
		});
		if (settings?.notesPublic) {
			test.info().annotations.push({ type: 'note', description: 'Skipped: this instance has the "notes are public" admin setting enabled, so cross-user access is allowed by design and there is nothing to deny.' });
			test.skip(true, 'notesPublic is enabled on this instance');
			return;
		}

		const SECOND_USER = 'crmtestuser';
		const SECOND_PASS = 'Crm-Notes-E2e-Xq72!vz';

		const userCtx = await browser.newContext({
			httpCredentials: { username: SECOND_USER, password: SECOND_PASS }, locale: 'de',
		});
		const userPage = await userCtx.newPage();
		await login(userPage, SECOND_USER, SECOND_PASS);
		await openApp(userPage);
		const secretTitle = unique('DeepLinkForbidden');
		const secret = await seedNote(userPage, { title: secretTitle, content: 'private to crmtestuser' });

		const adminCtx = await browser.newContext({
			httpCredentials: { username: 'admin', password: 'admin' }, locale: 'de',
		});
		const adminPage = await adminCtx.newPage();

		try {
			expect(secret.status).toBeLessThan(300);

			await login(adminPage, 'admin', 'admin');
			await openApp(adminPage);
			await adminPage.goto('/apps/touchpoint/#note/' + secret.id);
			await adminPage.waitForLoadState('networkidle');

			await expect(adminPage.locator('.toastify').filter({ hasText: /not.*found|no.*access|nicht gefunden|keinen Zugriff/i }).first())
				.toBeVisible({ timeout: 15000 });

			// The forbidden note's title must never have reached the page.
			await expect(adminPage.locator('body')).not.toContainText(secretTitle);
		} finally {
			await deleteNote(userPage, secret.id);
			await adminCtx.close();
			await userCtx.close();
		}
	});

	// ── (e) Deep-linked note buried beyond the first page — pagination hunt ──
	//
	// ContactNotesView's applyHighlight() keeps calling loadMoreContactNotes()
	// (bounded by MAX_HIGHLIGHT_LOAD_PAGES=20) when a #note/{id} target isn't on
	// the first loaded page. The contact-notes default sort is 'newest', so the
	// FIRST of 51 sequentially-seeded notes for one contact ends up last in the
	// list — i.e. on page 2 (page size 50) — exercising that pagination branch.
	test('(e) #note/<id> for a note beyond the first page pages through and highlights it', async ({ page }) => {
		test.setTimeout(120000);
		await login(page);
		await openApp(page);
		const title = unique('DeepLinkBuried');
		const seed = await seedManyNotes(page, 51, { title });
		const buriedId = seed.ids[0];

		try {
			await page.goto('/apps/touchpoint/#note/' + buriedId);
			await page.waitForLoadState('networkidle');

			// Not on the first page: applyHighlight() must page through to find it.
			const noteEl = page.locator('#crm-note-' + buriedId);
			await expect(noteEl).toBeVisible({ timeout: 30000 });
			await expect(noteEl).toContainText(title);
			await expect(noteEl).toHaveClass(/is-highlighted/);

			// The live region confirms the note was located, not just rendered.
			await expect(page.getByRole('status').filter({ hasText: title })).toBeVisible({ timeout: 5000 });

			await expect(page).toHaveURL(new RegExp('#contact/' + encodeURIComponent(seed.uid).replace(/[.*+?^${}()|[\]\\]/g, '\\$&')));
		} finally {
			await Promise.all(seed.ids.map((id) => deleteNote(page, id)));
		}
	});

	// ── (f) Deep-linked note removed between App.vue's initial fetch and
	//        ContactNotesView's pagination hunt locating it — give-up path ───
	//
	// Distinct from case (c) (fetch itself 404s) and case (e) (target is
	// eventually found by paging): here App.vue's GET /api/notes/{id} succeeds
	// (so applyNoteDeepLink() proceeds, selects the contact, and sets the
	// highlight flag) but the note is deleted before applyHighlight()'s
	// pagination loop reaches the page it was on, so the target is absent from
	// every page ContactNotesView loads. contactNotesHasMore then exhausts to
	// false (the contact's remaining, undeleted notes run out) — the loop's
	// natural exit, not the MAX_HIGHLIGHT_LOAD_PAGES cap, but the same
	// "not found" announcement path. Forced deterministically by delaying the
	// first contact-notes page response until after the note is deleted.
	test('(f) a note deleted mid pagination-hunt announces "not found" instead of looping forever', async ({ page }) => {
		test.setTimeout(120000);
		await login(page);
		await openApp(page);
		const title = unique('DeepLinkGoneMidHunt');
		// 51 notes total: seed[0] (oldest, target of the deep link) plus 50 newer
		// ones, so with the default 'newest' sort the target sits on page 2 and
		// the first page's fetch is what we delay/intercept below.
		const seed = await seedManyNotes(page, 51, { title });
		const targetId = seed.ids[0];

		let firstPageRequested = false;
		await page.route('**/api/notes/contact/**', async (route) => {
			if (!firstPageRequested) {
				firstPageRequested = true;
				// Delete the target only once the contact-notes fetch has actually
				// started, guaranteeing App.vue's earlier GET /api/notes/{id} (which
				// resolves before ContactNotesView even mounts) already succeeded.
				await deleteNote(page, targetId);
			}
			await route.continue();
		});

		try {
			await page.goto('/apps/touchpoint/#note/' + targetId);
			await page.waitForLoadState('networkidle');

			await expect(page.getByRole('status').filter({ hasText: /could not be found|konnte nicht gefunden/i }))
				.toBeVisible({ timeout: 30000 });

			// No note element for the (now-deleted) target ever renders.
			await expect(page.locator('#crm-note-' + targetId)).toHaveCount(0);
		} finally {
			await page.unroute('**/api/notes/contact/**');
			await Promise.all(seed.ids.slice(1).map((id) => deleteNote(page, id)));
		}
	});
});

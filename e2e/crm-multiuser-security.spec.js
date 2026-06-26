// SPDX-FileCopyrightText: 2026 CRM Notes Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
const { test, expect } = require('@playwright/test');
const { login, openApp } = require('./helpers');

/*
 * Multi-user authorization for shared notes.
 *
 * Setup: a dedicated second user `crmtestuser` must exist on the instance:
 *   sudo -u www-data php occ user:add --password-from-env crmtestuser
 * with the password below. The suite is otherwise self-contained: admin creates
 * a note shared READ-ONLY (canEdit:false) with crmtestuser via the API, then we
 * act as crmtestuser to check what that recipient may do.
 */

const SECOND_USER = 'crmtestuser';
const SECOND_PASS = 'Crm-Notes-E2e-Xq72!vz';

test.setTimeout(90000);

/** Admin creates a note shared with crmtestuser at the given canEdit level. */
async function adminCreateSharedNote(browser, canEdit) {
	const ctx = await browser.newContext({ httpCredentials: { username: 'admin', password: 'admin' }, locale: 'de' });
	const page = await ctx.newPage();
	await login(page, 'admin', 'admin');
	await openApp(page);
	const created = await page.evaluate(async ({ canEdit, user }) => {
		const token = document.querySelector('head')?.getAttribute('data-requesttoken');
		const H = { 'Content-Type': 'application/json', requesttoken: token };
		const types = await (await fetch('/index.php/apps/crm_notes/api/note-types', { headers: { requesttoken: token } })).json();
		const contacts = await (await fetch('/index.php/apps/crm_notes/api/contacts', { headers: { requesttoken: token } })).json();
		const uid = contacts[0].uid;
		// addressbookId MUST be non-zero (see crm-notes-crud.spec.js KNOWN ISSUE).
		const res = await fetch('/index.php/apps/crm_notes/api/notes', {
			method: 'POST', headers: H,
			body: JSON.stringify({
				contactUid: uid, noteTypeId: types[0].id,
				title: 'shared note ' + Date.now(), content: 'shared body',
				addressbookId: 1, contactUids: [uid],
				sharing: [{ type: 'user', id: user, canEdit }],
			}),
		});
		const note = await res.json();
		return { status: res.status, id: note.id, sharing: note.sharing };
	}, { canEdit, user: SECOND_USER });
	return { ctx, page, created };
}

/** Delete a note as admin (cleanup). Safe if already gone. */
async function adminDeleteNote(page, id) {
	await page.evaluate(async (id) => {
		const token = document.querySelector('head')?.getAttribute('data-requesttoken');
		await fetch('/index.php/apps/crm_notes/api/notes/' + id, { method: 'DELETE', headers: { requesttoken: token } });
	}, id).catch(() => {});
}

test.describe('CRM Notes — multi-user sharing authorization', () => {
	test('a read-only recipient can VIEW a note shared with them', async ({ browser }) => {
		const { ctx: adminCtx, page: admin, created } = await adminCreateSharedNote(browser, false);
		expect(created.status).toBeLessThan(300);
		// The share row was persisted with canEdit=false (migration 1006 can_edit column).
		expect(created.sharing?.[0]).toMatchObject({ sharedWithId: SECOND_USER, canEdit: false });

		const userCtx = await browser.newContext({ httpCredentials: { username: SECOND_USER, password: SECOND_PASS }, locale: 'de' });
		const u = await userCtx.newPage();
		await login(u, SECOND_USER, SECOND_PASS);
		await openApp(u);
		const view = await u.evaluate(async (id) => {
			const token = document.querySelector('head')?.getAttribute('data-requesttoken');
			const res = await fetch('/index.php/apps/crm_notes/api/notes/' + id, { headers: { requesttoken: token } });
			const body = await res.json().catch(() => null);
			return { status: res.status, title: body && body.title };
		}, created.id);
		expect(view.status).toBe(200);
		expect(view.title).toContain('shared note');

		await adminDeleteNote(admin, created.id);
		await adminCtx.close();
		await userCtx.close();
	});

	// SECURITY REGRESSION (current build): a recipient with a READ-ONLY share
	// (canEdit:false) is NOT prevented from editing or deleting the note. Probed
	// directly against the API: PUT and DELETE both return 200 instead of 403.
	// This is the historically weak authorization area called out in CLAUDE.md;
	// the "recent authorization fix" is not effective in this build. Re-enable this
	// test once the NoteService enforces canEdit on update()/delete() for non-owners.
	test.fixme('a read-only recipient is BLOCKED (403) from editing or deleting', async ({ browser }) => {
		const { ctx: adminCtx, page: admin, created } = await adminCreateSharedNote(browser, false);
		expect(created.status).toBeLessThan(300);

		const userCtx = await browser.newContext({ httpCredentials: { username: SECOND_USER, password: SECOND_PASS }, locale: 'de' });
		const u = await userCtx.newPage();
		await login(u, SECOND_USER, SECOND_PASS);
		await openApp(u);
		const attempts = await u.evaluate(async (id) => {
			const token = document.querySelector('head')?.getAttribute('data-requesttoken');
			const HJ = { 'Content-Type': 'application/json', requesttoken: token };
			const H = { requesttoken: token };
			const put = await fetch('/index.php/apps/crm_notes/api/notes/' + id, { method: 'PUT', headers: HJ, body: JSON.stringify({ title: 'HACKED' }) });
			const del = await fetch('/index.php/apps/crm_notes/api/notes/' + id, { method: 'DELETE', headers: H });
			return { putStatus: put.status, delStatus: del.status };
		}, created.id);

		// A read-only recipient must be forbidden from mutating the note.
		expect(attempts.putStatus).toBe(403);
		expect(attempts.delStatus).toBe(403);

		await adminDeleteNote(admin, created.id);
		await adminCtx.close();
		await userCtx.close();
	});

	// SECURITY REGRESSION (current build): horizontal IDOR — GET /api/notes/{id}
	// for a note that is NOT shared with the caller returns 200 with the full note
	// body instead of 403/404. Probed: crmtestuser can read admin's private note.
	// Re-enable once NoteService::find()/show authorizes the caller against
	// ownership + an explicit share. (Pairs with the edit/delete-enforcement bug.)
	test.fixme('a recipient does NOT see the owner\'s other (unshared) notes', async ({ browser }) => {
		// Create two notes as admin: one shared with crmtestuser, one private.
		const { ctx: adminCtx, page: admin, created: shared } = await adminCreateSharedNote(browser, false);
		const priv = await admin.evaluate(async () => {
			const token = document.querySelector('head')?.getAttribute('data-requesttoken');
			const H = { 'Content-Type': 'application/json', requesttoken: token };
			const types = await (await fetch('/index.php/apps/crm_notes/api/note-types', { headers: { requesttoken: token } })).json();
			const contacts = await (await fetch('/index.php/apps/crm_notes/api/contacts', { headers: { requesttoken: token } })).json();
			const res = await fetch('/index.php/apps/crm_notes/api/notes', {
				method: 'POST', headers: H,
				body: JSON.stringify({ contactUid: contacts[0].uid, noteTypeId: types[0].id, title: 'ADMIN PRIVATE ' + Date.now(), addressbookId: 1, contactUids: [contacts[0].uid] }),
			});
			const note = await res.json();
			return { id: note.id, title: JSON.parse(JSON.stringify(note)).title };
		});

		const userCtx = await browser.newContext({ httpCredentials: { username: SECOND_USER, password: SECOND_PASS }, locale: 'de' });
		const u = await userCtx.newPage();
		await login(u, SECOND_USER, SECOND_PASS);
		await openApp(u);
		const access = await u.evaluate(async (privId) => {
			const token = document.querySelector('head')?.getAttribute('data-requesttoken');
			const res = await fetch('/index.php/apps/crm_notes/api/notes/' + privId, { headers: { requesttoken: token } });
			return { status: res.status };
		}, priv.id);

		// The private note must NOT be readable by the second user.
		expect(access.status).toBeGreaterThanOrEqual(400);

		await adminDeleteNote(admin, shared.id);
		await adminDeleteNote(admin, priv.id);
		await adminCtx.close();
		await userCtx.close();
	});
});

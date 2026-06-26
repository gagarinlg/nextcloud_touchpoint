// SPDX-FileCopyrightText: 2026 CRM Notes Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

/*
 * Shared helpers for the CRM Notes e2e suite.
 *
 * The live instance is German-locale but the app is only partially translated,
 * so visible strings are a mix of German and English. Every text matcher here is
 * an English|German regex so the selectors survive either rendering.
 */

const APP_URL = '/apps/crm_notes/';

/**
 * Form-login against the running Nextcloud. Idempotent: a no-op when a session
 * already exists (the login form is absent).
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} user
 * @param {string} pass
 */
async function login(page, user = 'admin', pass = 'admin') {
	await page.goto('/login');
	await page.waitForLoadState('networkidle');
	const userField = page.locator('#user');
	if (await userField.isVisible().catch(() => false)) {
		await userField.fill(user);
		await page.locator('#password').fill(pass);
		await page.locator('#submit-form, button[type="submit"]').click();
		await page.waitForLoadState('networkidle');
	}
}

/** Open the CRM Notes app page and wait for the Vue app to mount. */
async function openApp(page) {
	await page.goto(APP_URL);
	await page.waitForLoadState('networkidle');
	// The app navigation list ('Kontakte' / 'Note types' / 'Einstellungen') is the
	// first thing the mounted Vue app renders.
	await page.locator('#app-navigation, .app-navigation').first().waitFor({ state: 'visible', timeout: 20000 });
}

/** Click an app-navigation entry by its (EN|DE) label regex. */
async function gotoSection(page, labelRegex) {
	await page.locator('.app-navigation-entry-link, .app-navigation-entry a, nav a')
		.filter({ hasText: labelRegex }).first().click();
	await page.waitForTimeout(800);
}

// Reusable (EN|DE) label matchers.
const RX = {
	contacts: /^Kontakte$|^Contacts$/,
	noteTypes: /Note types|Notiztypen/,
	settings: /^Einstellungen$|^Settings$/,
	addNote: /Add note|Notiz hinzufügen/,
	addType: /Add type|Typ hinzufügen/,
	save: /^Save$|^Speichern$/,
	cancel: /^Cancel$|^Abbrechen$/,
	edit: /^Edit$|^Bearbeiten$/,
	delete: /^Delete$|^Löschen$/,
	editType: /Edit type|Typ bearbeiten/,
	deleteType: /Delete type|Typ löschen/,
};

/** A unique suffix so created rows never collide across runs. */
function unique(prefix) {
	return `${prefix}-${Date.now().toString(36)}-${Math.floor(Math.random() * 1e4)}`;
}

module.exports = { login, openApp, gotoSection, RX, unique, APP_URL };

const { test } = require('@playwright/test');

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

test('explore app page', async ({ page }) => {
	await login(page);
	await page.goto('/apps/crm_notes/');
	await page.waitForLoadState('networkidle');
	await page.waitForTimeout(3000);
	await page.screenshot({ path: 'e2e/screenshots/explore-app.png', fullPage: true });

	const nav = await page.evaluate(() => {
		const items = Array.from(document.querySelectorAll('.app-navigation-entry, nav li, [class*=navigation] a'));
		return items.map(i => i.textContent.trim().slice(0, 40)).filter(Boolean).slice(0, 20);
	});
	console.log('NAV ITEMS:', JSON.stringify(nav));

	// Note types view
	const ntLink = page.getByRole('link', { name: /Note types|Notiztypen|Typen/i }).first();
	const found = await ntLink.isVisible().catch(() => false);
	console.log('note-types link visible:', found);
});

test('explore note types view', async ({ page }) => {
	await login(page);
	await page.goto('/apps/crm_notes/');
	await page.waitForLoadState('networkidle');
	await page.waitForTimeout(2500);
	// click the 2nd nav item (Note types)
	const entries = page.locator('.app-navigation-entry-link, .app-navigation-entry a, nav a');
	const n = await entries.count();
	console.log('nav entry count', n);
	for (let i = 0; i < n; i++) {
		console.log('entry', i, JSON.stringify((await entries.nth(i).textContent() || '').trim()));
	}
	// Try clicking by text
	await page.getByText(/^Note types$|^Notiztypen$/).first().click().catch(e => console.log('click err', e.message));
	await page.waitForTimeout(1500);
	await page.screenshot({ path: 'e2e/screenshots/explore-notetypes.png', fullPage: true });
	const badges = await page.evaluate(() => Array.from(document.querySelectorAll('.crm-type-item')).map(e => e.textContent.trim().replace(/\s+/g, ' ').slice(0, 60)));
	console.log('TYPE ITEMS:', JSON.stringify(badges));
	const h2 = await page.evaluate(() => Array.from(document.querySelectorAll('h2')).map(h => h.textContent.trim()));
	console.log('H2s:', JSON.stringify(h2));
});

test('explore contact + note modal', async ({ page }) => {
	await login(page);
	await page.goto('/apps/crm_notes/');
	await page.waitForLoadState('networkidle');
	await page.waitForTimeout(3000);
	const items = page.locator('.crm-contacts-list li, .crm-contacts-list .list-item, [class*=list-item]');
	console.log('contact list count', await items.count());
	const first = items.first();
	if (await first.isVisible().catch(() => false)) {
		await first.click();
		await page.waitForTimeout(1500);
		await page.screenshot({ path: 'e2e/screenshots/explore-contact.png', fullPage: true });
		const addBtns = await page.evaluate(() => Array.from(document.querySelectorAll('button')).map(b => (b.textContent || '').trim()).filter(Boolean).slice(0, 30));
		console.log('BUTTONS:', JSON.stringify(addBtns));
	}
});

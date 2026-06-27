/*
 * SPDX-FileCopyrightText: 2026 Touchpoint Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
const { test } = require('@playwright/test');
test.setTimeout(60000);

test('Check avatar rendering', async ({ page }) => {
    await page.goto('http://localhost/index.php/login');
    await page.fill('#user', 'admin');
    await page.fill('#password', 'admin');
    await page.click('button[type=submit]');
    await page.waitForURL('**/apps/**');
    
    await page.goto('http://localhost/index.php/apps/touchpoint');
    await page.waitForTimeout(3000);
    
    // Check avatar circle HTML for each contact  
    const items = await page.locator('.crm-contact-item').all();
    for (const item of items) {
        const name = await item.locator('.crm-contact-name').textContent().catch(() => 'N/A');
        const iconHtml = await item.locator('.crm-contact-icon').innerHTML();
        const iconText = await item.locator('.crm-contact-icon').textContent();
        const bgColor = await item.locator('.crm-contact-icon').evaluate(el => el.style.backgroundColor);
        console.log(`Contact: ${name}`);
        console.log(`  Icon HTML: ${iconHtml.substring(0, 200)}`);
        console.log(`  Icon text: "${iconText}"`);
        console.log(`  BG color: ${bgColor}`);
    }
    
    await page.screenshot({ path: '/tmp/avatar-check.png' });
    
    // Also check the contacts app avatar for comparison
    await page.goto('http://localhost/index.php/apps/contacts');
    await page.waitForTimeout(3000);
    await page.screenshot({ path: '/tmp/contacts-app.png' });
    
    // Get contact list item HTML from contacts app
    const contactsAppItems = await page.locator('.app-content-list-item, .contact-list-item, [class*="contact-item"]').count();
    console.log('Contacts app items found:', contactsAppItems);
    
    if (contactsAppItems > 0) {
        const firstItem = page.locator('.app-content-list-item, .contact-list-item, [class*="contact-item"]').first();
        const avatarEl = firstItem.locator('[class*="avatar"], img, .icon-people');
        const avatarHtml = await avatarEl.innerHTML().catch(() => 'N/A');
        console.log('Contacts app avatar HTML:', avatarHtml.substring(0, 300));
    }
});

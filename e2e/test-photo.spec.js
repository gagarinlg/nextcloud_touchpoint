/*
 * SPDX-FileCopyrightText: 2026 CRM Notes Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
const { test } = require('@playwright/test');
test.setTimeout(60000);

test('Verify Leon Green photo loads', async ({ page }) => {
    await page.goto('http://localhost/index.php/login');
    await page.fill('#user', 'admin');
    await page.fill('#password', 'admin');
    await page.click('button[type=submit]');
    await page.waitForURL('**/apps/**');
    
    await page.goto('http://localhost/index.php/apps/crm_notes');
    await page.waitForTimeout(2000);
    
    // Check contacts API for photo
    const contacts = await page.evaluate(async () => {
        const resp = await fetch(OC.generateUrl('/apps/crm_notes') + '/api/contacts', {
            headers: { requesttoken: OC.requestToken }
        });
        const data = await resp.json();
        return data.map(c => ({
            name: c.name,
            photoLength: (c.photo || '').length,
            photoPrefix: (c.photo || '').substring(0, 30)
        }));
    });
    
    console.log('Contacts:', JSON.stringify(contacts, null, 2));
    
    await page.screenshot({ path: '/tmp/photo-test.png' });
    
    // Check avatar circle for Leon Green
    const leonIcon = page.locator('.crm-contact-item[data-name="Leon Green"] .crm-contact-icon');
    const iconHtml = await leonIcon.innerHTML();
    console.log('Leon icon HTML:', iconHtml.substring(0, 200));
});

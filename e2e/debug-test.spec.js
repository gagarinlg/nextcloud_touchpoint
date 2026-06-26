/*
 * SPDX-FileCopyrightText: 2026 CRM Notes Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
const { test, expect } = require('@playwright/test');

test('Debug current issues', async ({ page }) => {
    await page.goto('http://localhost/index.php/login');
    await page.fill('#user', 'admin');
    await page.fill('#password', 'admin');
    await page.click('button[type=submit]');
    await page.waitForURL('**/apps/**');
    
    await page.goto('http://localhost/index.php/apps/crm_notes');
    await page.waitForTimeout(2000);
    
    await page.screenshot({ path: '/tmp/crm-current-full.png', fullPage: true });
    
    // Check all notes
    const allNotesContent = await page.locator('#crm-all-notes-list').innerHTML();
    console.log('ALL NOTES HTML (first 300):', allNotesContent.substring(0, 300));
    
    // Check contacts
    const contactItems = await page.locator('.crm-contact-item').all();
    console.log('Contact count:', contactItems.length);
    for (const item of contactItems) {
        const name = await item.locator('.crm-contact-name').textContent().catch(() => 'N/A');
        const email = await item.locator('.crm-contact-email').textContent().catch(() => 'N/A');
        console.log('Contact:', name, '|', email);
    }
    
    // Click Leon Green
    const leon = page.locator('.crm-contact-item[data-name="Leon Green"]');
    if (await leon.count() > 0) {
        await leon.click();
        await page.waitForTimeout(1000);
        await page.screenshot({ path: '/tmp/crm-leon.png' });
        
        const editBtn = page.locator('.crm-btn-edit').first();
        if (await editBtn.count() > 0) {
            await editBtn.click();
            await page.waitForTimeout(500);
            await page.screenshot({ path: '/tmp/crm-edit.png' });
            const filesHtml = await page.locator('#crm-note-files-list').innerHTML();
            console.log('FILES IN MODAL:', filesHtml);
        }
    }
    
    // Test API
    const r = await page.request.get('http://localhost/index.php/apps/crm_notes/api/notes');
    const notes = await r.json();
    console.log('NOTES COUNT:', notes.length);
    if (notes.length > 0) {
        console.log('FIRST NOTE FILES:', JSON.stringify(notes[0].files));
        console.log('FIRST NOTE CONTACTS:', JSON.stringify(notes[0].contacts));
    }
});

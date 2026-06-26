/*
 * SPDX-FileCopyrightText: 2026 CRM Notes Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
const { test } = require('@playwright/test');
test.setTimeout(60000);

test('Check translations in modal', async ({ page }) => {
    await page.goto('http://localhost/index.php/login');
    await page.fill('#user', 'admin');
    await page.fill('#password', 'admin');
    await page.click('button[type=submit]');
    await page.waitForURL('**/apps/**');

    await page.goto('http://localhost/index.php/apps/crm_notes');
    await page.waitForTimeout(2000);

    // Click first contact
    await page.locator('.crm-contact-item').first().click();
    await page.waitForTimeout(1000);

    // Open add note modal
    await page.locator('#crm-add-note').click();
    await page.waitForTimeout(500);

    await page.screenshot({ path: '/tmp/translations-modal.png' });

    // Check translations
    const labels = await page.locator('.crm-modal-content label').allTextContents();
    const attachBtn = await page.locator('#crm-attach-file').textContent();
    const selectOpt = await page.locator('#crm-note-contacts-select option[value=""]').textContent();
    const modalTitle = await page.locator('#crm-modal-title').textContent();

    console.log('Modal title:', modalTitle);
    console.log('Labels:', labels);
    console.log('Attach btn:', attachBtn.trim());
    console.log('Contact select placeholder:', selectOpt);

    // Check for English text that should be translated
    const allText = labels.join(' ') + ' ' + attachBtn + ' ' + selectOpt;
    const englishStrings = ['Linked files', 'Attach file', 'Add contact'];
    englishStrings.forEach(s => {
        if (allText.includes(s)) {
            console.log('UNTRANSLATED:', s);
        }
    });

    // Close modal
    await page.locator('.crm-modal-close').first().click();
    await page.waitForTimeout(300);

    // Trigger edit via JS on first note with files
    await page.evaluate(() => {
        const editBtn = document.querySelector('.crm-note-item .crm-btn-edit');
        if (editBtn) editBtn.click();
    });
    await page.waitForTimeout(1000);

    const fileNames = await page.locator('#crm-note-files-list .crm-file-name').allTextContents();
    console.log('Files in edit modal:', fileNames);

    await page.screenshot({ path: '/tmp/translations-with-file.png' });
});

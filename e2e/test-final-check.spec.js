const { test, expect } = require('@playwright/test');

test.setTimeout(90000);

test('Final verification of all fixed issues', async ({ page }) => {
    await page.goto('http://localhost/index.php/login');
    await page.fill('#user', 'admin');
    await page.fill('#password', 'admin');
    await page.click('button[type=submit]');
    await page.waitForURL('**/apps/**');
    
    await page.goto('http://localhost/index.php/apps/crm_notes');
    await page.waitForTimeout(3000);
    
    // Screenshot 1: Full app with all notes
    await page.screenshot({ path: '/tmp/final-full.png', fullPage: true });
    
    // Check contacts show name and email
    const contacts = await page.locator('.crm-contact-item').all();
    console.log('=== CONTACTS ===');
    for (const c of contacts) {
        const name = await c.locator('.crm-contact-name').textContent().catch(() => 'N/A');
        const email = await c.locator('.crm-contact-email').textContent().catch(() => 'N/A');
        const hasAvatar = await c.locator('.crm-contact-icon').isVisible();
        console.log(`  ${name} | ${email} | hasAvatar: ${hasAvatar}`);
    }
    
    // Check all notes are shown
    const allNotes = await page.locator('#crm-all-notes-list .crm-note-item').all();
    console.log(`\n=== ALL NOTES: ${allNotes.length} notes shown ===`);
    
    // Check translations - open a note modal
    await page.locator('.crm-contact-item').first().click();
    await page.waitForTimeout(1000);
    await page.locator('#crm-add-note').click();
    await page.waitForTimeout(500);
    
    // Check modal translations
    const modalLabels = await page.locator('.crm-modal-content label').allTextContents();
    console.log('\n=== MODAL LABELS ===');
    modalLabels.forEach(l => console.log('  ' + l.trim()));
    
    const attachBtn = await page.locator('#crm-attach-file').textContent();
    console.log('Attach button text:', attachBtn.trim());
    
    const addContactPlaceholder = await page.locator('#crm-note-contacts-select option').first().textContent();
    console.log('Add contact placeholder:', addContactPlaceholder);
    
    await page.screenshot({ path: '/tmp/final-modal.png' });
    
    // Close modal
    await page.locator('.crm-modal-close').first().click();
    await page.waitForTimeout(300);
    
    // Test file attachment: open note with existing file
    await page.locator('.crm-contact-item[data-name="Leon Green"]').click();
    await page.waitForTimeout(1000);
    
    // Find note with file
    const editBtns = await page.locator('.crm-btn-edit').all();
    let foundFile = false;
    for (const btn of editBtns) {
        await btn.click();
        await page.waitForTimeout(500);
        const fileItems = await page.locator('#crm-note-files-list .crm-file-item').count();
        if (fileItems > 0) {
            console.log(`\n=== FILE ATTACHMENT: ${fileItems} file(s) shown when reopening note ===`);
            const fileName = await page.locator('#crm-note-files-list .crm-file-name').first().textContent();
            console.log('  File name:', fileName);
            await page.screenshot({ path: '/tmp/final-file-attachment.png' });
            foundFile = true;
        }
        await page.locator('.crm-modal-close').first().click();
        await page.waitForTimeout(300);
        if (foundFile) break;
    }
    
    if (!foundFile) {
        console.log('\n=== FILE ATTACHMENT: No notes with files found ===');
    }
    
    // Check avatar rendering
    const avatarImgs = await page.locator('.crm-contact-avatar[src]').count();
    const avatarCircles = await page.locator('.crm-contact-icon').count();
    console.log(`\n=== AVATARS: ${avatarCircles} circles, ${avatarImgs} with img src ===`);
});

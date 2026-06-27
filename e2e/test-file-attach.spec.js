/*
 * SPDX-FileCopyrightText: 2026 Touchpoint Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
const { test, expect } = require('@playwright/test');

test.setTimeout(60000);

test('Test file attachment', async ({ page }) => {
    // Login
    await page.goto('http://localhost/index.php/login');
    await page.fill('#user', 'admin');
    await page.fill('#password', 'admin');
    await page.click('button[type=submit]');
    await page.waitForURL('**/apps/**');
    
    // Go to CRM notes
    await page.goto('http://localhost/index.php/apps/touchpoint');
    await page.waitForTimeout(2000);
    
    // Click Leon Green contact
    await page.locator('.crm-contact-item[data-name="Leon Green"]').click();
    await page.waitForTimeout(1000);
    
    // Click "Add note"
    await page.locator('#crm-add-note').click();
    await page.waitForTimeout(500);
    
    // Fill form
    await page.fill('#crm-note-title', 'Test Note With File');
    await page.fill('#crm-note-content', 'This note has a file attached');
    
    await page.screenshot({ path: '/tmp/before-attach.png' });
    
    // Click Attach file - this will open a dialog
    // We'll intercept the filepicker call instead
    // Instead, let's test by calling the API directly after save
    
    // Save the note first without file
    await page.click('button[type=submit]');
    await page.waitForTimeout(1500);
    
    await page.screenshot({ path: '/tmp/after-save-note.png' });
    
    // Check notes in API
    const notesResp = await page.request.get('http://localhost/index.php/apps/touchpoint/api/notes');
    const notes = await notesResp.json();
    console.log('Notes after save:', notes.map(n => ({id: n.id, title: n.title, files: n.files})));
    
    // Find the note we just created
    const newNote = notes.find(n => n.title === 'Test Note With File');
    if (newNote) {
        console.log('Found new note:', newNote.id);
        
        // Attach file via API
        const csrfToken = await page.evaluate(() => OC.requestToken);
        
        const attachResp = await page.request.post(
            `http://localhost/index.php/apps/touchpoint/api/notes/${newNote.id}/files`,
            {
                headers: { 
                    'Content-Type': 'application/json',
                    'requesttoken': csrfToken
                },
                data: JSON.stringify({ fileId: 0, filePath: '/Readme.md' })
            }
        );
        const attachResult = await attachResp.json();
        console.log('Attach result:', JSON.stringify(attachResult));
        
        // Now reload notes and check
        const notesResp2 = await page.request.get('http://localhost/index.php/apps/touchpoint/api/notes');
        const notes2 = await notesResp2.json();
        const noteWithFile = notes2.find(n => n.id === newNote.id);
        console.log('Note after file attach:', JSON.stringify(noteWithFile));
        
        // Reload the page and open the note
        await page.goto('http://localhost/index.php/apps/touchpoint');
        await page.waitForTimeout(2000);
        await page.locator('.crm-contact-item[data-name="Leon Green"]').click();
        await page.waitForTimeout(1000);
        
        // Find and edit the test note
        const editBtns = await page.locator('.crm-btn-edit').all();
        for (const btn of editBtns) {
            const noteId = await btn.getAttribute('data-id');
            if (parseInt(noteId) === newNote.id) {
                await btn.click();
                await page.waitForTimeout(500);
                await page.screenshot({ path: '/tmp/reopen-note.png' });
                const filesHtml = await page.locator('#crm-note-files-list').innerHTML();
                console.log('FILES HTML when reopened:', filesHtml);
                break;
            }
        }
    } else {
        console.log('Could not find new note');
        console.log('All notes:', JSON.stringify(notes));
    }
});

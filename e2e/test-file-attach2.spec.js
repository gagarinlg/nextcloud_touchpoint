const { test, expect } = require('@playwright/test');

test.setTimeout(90000);

test('Test file attachment API', async ({ page }) => {
    await page.goto('http://localhost/index.php/login');
    await page.fill('#user', 'admin');
    await page.fill('#password', 'admin');
    await page.click('button[type=submit]');
    await page.waitForURL('**/apps/**');
    
    await page.goto('http://localhost/index.php/apps/crm_notes');
    await page.waitForTimeout(3000);
    
    const result = await page.evaluate(async () => {
        const baseUrl = OC.generateUrl('/apps/crm_notes');
        const token = OC.requestToken;
        
        const notesResp = await fetch(baseUrl + '/api/notes', {
            headers: { 'requesttoken': token }
        });
        const notes = await notesResp.json();
        
        if (!Array.isArray(notes) || notes.length === 0) {
            return { error: 'Notes API issue', status: notesResp.status, raw: JSON.stringify(notes).substring(0,200) };
        }
        
        const noteId = notes[0].id;
        console.log('Testing with note:', noteId, notes[0].title);
        
        // Attach a file
        const attachResp = await fetch(baseUrl + '/api/notes/' + noteId + '/files', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'requesttoken': token
            },
            body: JSON.stringify({ fileId: 0, filePath: '/Readme.md' })
        });
        
        const attachData = await attachResp.json().catch(() => 'parse error');
        
        // Show individual note  
        const noteResp = await fetch(baseUrl + '/api/notes/' + noteId, {
            headers: { 'requesttoken': token }
        });
        const noteAfter = await noteResp.json();
        
        return {
            notesCount: notes.length,
            noteId,
            noteTitle: notes[0].title,
            attachStatus: attachResp.status,
            attachData,
            noteFilesAfter: noteAfter.files
        };
    });
    
    console.log('RESULT:', JSON.stringify(result, null, 2));
    
    // Now test the UI rendering
    await page.reload();
    await page.waitForTimeout(3000);
    
    // Navigate to find the note we modified (first note, which is for some contact)
    const allNotesItems = await page.locator('#crm-all-notes-list .crm-note-item').all();
    console.log('All notes items count:', allNotesItems.length);
    
    if (allNotesItems.length > 0) {
        // Click edit on first note
        await page.locator('#crm-all-notes-list .crm-btn-edit').first().click();
        await page.waitForTimeout(500);
        await page.screenshot({ path: '/tmp/note-edit-modal-with-file.png' });
        
        const filesHtml = await page.locator('#crm-note-files-list').innerHTML();
        console.log('FILES HTML in modal:', filesHtml || '(empty)');
    }
});

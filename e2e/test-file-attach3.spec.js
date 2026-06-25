const { test, expect } = require('@playwright/test');

test.setTimeout(90000);

test('Test file attachment saves and shows on reopen', async ({ page }) => {
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
        const headers = { 'requesttoken': token, 'Content-Type': 'application/json' };
        
        // Get all notes
        const notesResp = await fetch(baseUrl + '/api/notes', { headers });
        const notes = await notesResp.json();
        
        if (!Array.isArray(notes) || notes.length === 0) {
            return { error: 'No notes', raw: JSON.stringify(notes) };
        }
        
        // Use first note
        const noteId = notes[0].id;
        
        // Attach a file
        const attachResp = await fetch(baseUrl + '/api/notes/' + noteId + '/files', {
            method: 'POST',
            headers,
            body: JSON.stringify({ fileId: 0, filePath: '/Readme.md' })
        });
        
        const attachStatus = attachResp.status;
        const attachData = await attachResp.json().catch(e => ({ parseError: e.message }));
        
        return {
            noteId,
            noteTitle: notes[0].title,
            attachStatus,
            attachData,
            noteFilesAfter: attachData.files || []
        };
    });
    
    console.log('FILE ATTACH RESULT:', JSON.stringify(result, null, 2));
    
    // Check DB via another API call
    await page.reload();
    await page.waitForTimeout(3000);
    
    const check = await page.evaluate(async () => {
        const baseUrl = OC.generateUrl('/apps/crm_notes');
        const token = OC.requestToken;
        const notesResp = await fetch(baseUrl + '/api/notes', { headers: { requesttoken: token } });
        const notes = await notesResp.json();
        return notes.map(n => ({ id: n.id, title: n.title, files: n.files }));
    });
    console.log('NOTES AFTER RELOAD:', JSON.stringify(check, null, 2));
    
    // Open a note with a file via UI
    const noteWithFile = check.find(n => n.files && n.files.length > 0);
    if (noteWithFile) {
        console.log('Found note with files:', noteWithFile.id, noteWithFile.title);
        
        // Click edit button for this note
        await page.locator(`.crm-btn-edit[data-id="${noteWithFile.id}"]`).click();
        await page.waitForTimeout(500);
        await page.screenshot({ path: '/tmp/note-with-file.png' });
        
        const filesHtml = await page.locator('#crm-note-files-list').innerHTML();
        console.log('FILES HTML:', filesHtml);
        
        const fileCount = await page.locator('#crm-note-files-list .crm-file-item').count();
        console.log('File items count:', fileCount);
    } else {
        console.log('No notes with files found');
    }
});

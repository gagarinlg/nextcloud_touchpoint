const { test } = require('@playwright/test');
test.setTimeout(30000);

test('Debug photo value', async ({ page }) => {
    await page.goto('http://localhost/index.php/login');
    await page.fill('#user', 'admin');
    await page.fill('#password', 'admin');
    await page.click('button[type=submit]');
    await page.waitForURL('**/apps/**');
    
    await page.goto('http://localhost/index.php/apps/crm_notes');
    await page.waitForTimeout(2000);
    
    const result = await page.evaluate(async () => {
        const resp = await fetch(OC.generateUrl('/apps/crm_notes') + '/api/contacts', {
            headers: { requesttoken: OC.requestToken }
        });
        const data = await resp.json();
        return data.map(c => {
            // Try to decode photo as text to see what it is
            if (c.photo && c.photo.startsWith('data:')) {
                const base64 = c.photo.split(',')[1];
                const decoded = atob(base64.substring(0, 100));
                return { name: c.name, decodedPhotoStart: decoded };
            }
            return { name: c.name, photo: c.photo.substring(0, 50) };
        });
    });
    console.log('DECODED PHOTO:', JSON.stringify(result, null, 2));
    
    // Check what the contacts API returns for photo (directly from Nextcloud contacts)
    const contactsCheck = await page.evaluate(async () => {
        // Use DAV to get contact
        const resp = await fetch('/remote.php/dav/addressbooks/users/admin/contacts/?method=REPORT', {
            method: 'REPORT',
            headers: { 
                'Content-Type': 'application/xml; charset=utf-8',
                'Depth': '1'
            },
            body: `<?xml version="1.0" encoding="utf-8"?>
<c:addressbook-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:carddav">
    <d:prop>
        <d:getetag />
        <c:address-data />
    </d:prop>
    <c:filter>
        <c:prop-filter name="FN">
            <c:text-match>Leon</c:text-match>
        </c:prop-filter>
    </c:filter>
</c:addressbook-query>`
        });
        return { status: resp.status };
    });
    console.log('DAV check:', contactsCheck);
});

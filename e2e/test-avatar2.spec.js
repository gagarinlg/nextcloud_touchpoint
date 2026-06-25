const { test } = require('@playwright/test');
test.setTimeout(60000);

test('Check avatar API data', async ({ page }) => {
    await page.goto('http://localhost/index.php/login');
    await page.fill('#user', 'admin');
    await page.fill('#password', 'admin');
    await page.click('button[type=submit]');
    await page.waitForURL('**/apps/**');
    
    await page.goto('http://localhost/index.php/apps/crm_notes');
    await page.waitForTimeout(2000);
    
    // Call contacts API
    const result = await page.evaluate(async () => {
        const token = OC.requestToken;
        const resp = await fetch(OC.generateUrl('/apps/crm_notes') + '/api/contacts', {
            headers: { requesttoken: token }
        });
        const contacts = await resp.json();
        return contacts.map(c => ({
            name: c.name,
            uid: c.uid,
            photoType: typeof c.photo,
            photoLength: (c.photo || '').length,
            photoPrefix: (c.photo || '').substring(0, 50)
        }));
    });
    
    console.log('CONTACTS API:', JSON.stringify(result, null, 2));
    
    // Check what the avatar URL returns in browser for Leon Green uid
    const leonUid = result.find(c => c.name === 'Leon Green')?.uid;
    if (leonUid) {
        const avatarResult = await page.evaluate(async (uid) => {
            const url = OC.generateUrl('/avatar/' + encodeURIComponent(uid) + '/40');
            const resp = await fetch(url);
            return {
                status: resp.status,
                contentType: resp.headers.get('content-type'),
                url
            };
        }, leonUid);
        console.log('Leon Green avatar URL result:', avatarResult);
    }
    
    // Check contacts app contact list HTML for avatar approach
    await page.goto('http://localhost/index.php/apps/contacts');
    await page.waitForTimeout(3000);
    
    const contactListHtml = await page.locator('.contact-list-item, [class*="item"]:has(img)').first().innerHTML().catch(() => 'N/A');
    console.log('Contacts app list item HTML:', contactListHtml.substring(0, 500));
    
    // Get the avatar for Leon Green in contacts app
    const leonAvatar = await page.evaluate(() => {
        const items = document.querySelectorAll('.contact-list-item, [class*="NcListItem"]');
        for (const item of items) {
            if (item.textContent.includes('Leon Green')) {
                const img = item.querySelector('img');
                return img ? { src: img.src, alt: img.alt, class: img.className } : 'no img';
            }
        }
        return 'not found';
    });
    console.log('Leon Green in contacts app:', leonAvatar);
});

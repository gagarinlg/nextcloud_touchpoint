const { test } = require('@playwright/test');
test.setTimeout(60000);

test('Verify avatar circles render correctly', async ({ page }) => {
    await page.goto('http://localhost/index.php/login');
    await page.fill('#user', 'admin');
    await page.fill('#password', 'admin');
    await page.click('button[type=submit]');
    await page.waitForURL('**/apps/**');
    
    await page.goto('http://localhost/index.php/apps/crm_notes');
    await page.waitForTimeout(2000);
    
    await page.screenshot({ path: '/tmp/avatar3-full.png' });
    
    // Check each contact's avatar rendering
    const contacts = await page.locator('.crm-contact-item').all();
    for (const c of contacts) {
        const name = await c.locator('.crm-contact-name').textContent().catch(() => '?');
        const initials = await c.locator('.crm-contact-initials').textContent().catch(() => 'missing');
        const hasImg = await c.locator('.crm-contact-avatar').count();
        const bgColor = await c.locator('.crm-contact-icon').evaluate(el => el.style.backgroundColor);
        console.log(`${name}: initials="${initials}", hasImg=${hasImg}, bg=${bgColor}`);
    }
    
    // Click Leon Green and take screenshot of detail view
    await page.locator('.crm-contact-item[data-name="Leon Green"]').click();
    await page.waitForTimeout(500);
    await page.screenshot({ path: '/tmp/avatar3-detail.png' });
});

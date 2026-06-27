/*
 * SPDX-FileCopyrightText: 2026 Touchpoint Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
const { test, expect } = require('@playwright/test');

async function login(page) {
    await page.goto('/login');
    await page.waitForLoadState('networkidle');
    const userField = page.locator('#user');
    if (await userField.isVisible()) {
        await userField.fill('admin');
        await page.locator('#password').fill('admin');
        await page.locator('#submit-form, button[type="submit"]').click();
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(2000);
    }
}

test.describe('App Analysis', () => {
    test.beforeEach(async ({ page }) => {
        await login(page);
    });

    test('capture contacts app', async ({ page }) => {
        await page.goto('/apps/contacts/');
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(3000);
        await page.screenshot({ path: 'e2e/screenshots/contacts-full.png', fullPage: true });

        const navInfo = await page.evaluate(() => {
            const nav = document.querySelector('#app-navigation');
            if (!nav) return { found: false, ids: Array.from(document.querySelectorAll('[id]')).slice(0, 20).map(e => e.id) };
            const cs = window.getComputedStyle(nav);
            return { found: true, html: nav.innerHTML.substring(0, 4000), styles: { width: cs.width, bg: cs.backgroundColor } };
        });
        console.log('=== CONTACTS NAV ===');
        console.log(JSON.stringify(navInfo, null, 2));

        const nav = page.locator('#app-navigation');
        if (await nav.isVisible()) {
            await nav.screenshot({ path: 'e2e/screenshots/contacts-nav.png' });
        }
    });

    test('capture crm notes app', async ({ page }) => {
        await page.goto('/apps/touchpoint/');
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(3000);
        await page.screenshot({ path: 'e2e/screenshots/crm-full.png', fullPage: true });

        const navInfo = await page.evaluate(() => {
            const nav = document.querySelector('#app-navigation');
            if (!nav) return { found: false, ids: Array.from(document.querySelectorAll('[id]')).slice(0, 30).map(e => e.id) };
            const cs = window.getComputedStyle(nav);
            return { found: true, html: nav.innerHTML.substring(0, 4000), styles: { width: cs.width, bg: cs.backgroundColor } };
        });
        console.log('=== CRM NAV ===');
        console.log(JSON.stringify(navInfo, null, 2));

        const listInfo = await page.evaluate(() => {
            const items = document.querySelectorAll('.crm-contact-item');
            if (items.length === 0) return { count: 0, listContent: document.querySelector('#crm-contacts-list')?.innerHTML?.substring(0, 500) };
            return { count: items.length, firstHtml: items[0].outerHTML.substring(0, 1500) };
        });
        console.log('=== CRM CONTACTS ===');
        console.log(JSON.stringify(listInfo, null, 2));

        const pageText = await page.evaluate(() => document.body.innerText);
        const eng = ['Add note','Add type','Select a contact','Note Types','Search contacts','Choose a contact','Contacts','No notes yet','Cancel','Save','Pin this note','New Note','Title','Content','Type'];
        console.log('=== UNTRANSLATED ===');
        console.log(JSON.stringify(eng.filter(s => pageText.includes(s))));

        const nav = page.locator('#app-navigation');
        if (await nav.isVisible()) await nav.screenshot({ path: 'e2e/screenshots/crm-nav.png' });
        const content = page.locator('#app-content');
        if (await content.isVisible()) await content.screenshot({ path: 'e2e/screenshots/crm-content.png' });
    });

    test('capture crm detail and modal', async ({ page }) => {
        await page.goto('/apps/touchpoint/');
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(3000);

        const firstContact = page.locator('.crm-contact-item').first();
        if (await firstContact.isVisible()) {
            await firstContact.click();
            await page.waitForTimeout(1500);
            await page.screenshot({ path: 'e2e/screenshots/crm-detail.png', fullPage: true });

            const addBtnText = await page.locator('#crm-add-note').innerText();
            console.log('=== ADD BTN TEXT: ' + addBtnText + ' ===');

            await page.locator('#crm-add-note').click();
            await page.waitForTimeout(500);
            await page.screenshot({ path: 'e2e/screenshots/crm-modal.png', fullPage: true });

            const modalInfo = await page.evaluate(() => {
                const m = document.querySelector('.crm-modal-content');
                if (!m) return { found: false };
                const cb = m.querySelector('.crm-modal-close');
                const cbCs = cb ? window.getComputedStyle(cb) : null;
                return {
                    found: true,
                    html: m.innerHTML.substring(0, 3000),
                    closeBtnStyles: cbCs ? { w: cbCs.width, h: cbCs.height, bg: cbCs.background, bgImage: cbCs.backgroundImage, border: cbCs.border } : null
                };
            });
            console.log('=== MODAL ===');
            console.log(JSON.stringify(modalInfo, null, 2));

            const modal = page.locator('.crm-modal-content');
            if (await modal.isVisible()) await modal.screenshot({ path: 'e2e/screenshots/crm-modal-detail.png' });
        } else {
            console.log('No contacts found!');
        }
    });
});

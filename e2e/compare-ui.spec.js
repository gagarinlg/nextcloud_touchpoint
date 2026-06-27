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

test.describe('UI Comparison: Contacts vs Touchpoint', () => {
    test.beforeEach(async ({ page }) => {
        await login(page);
    });

    test('Capture Contacts app full UI structure', async ({ page }) => {
        await page.goto('/apps/contacts');
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(3000);

        await page.screenshot({ path: 'e2e/screenshots/contacts-full.png', fullPage: true });

        // Contacts is Vue-based, wait for it to render
        // Get the full app layout structure
        const appContent = await page.evaluate(() => {
            const result = {};

            // Check for app-navigation
            const nav = document.querySelector('#app-navigation') ||
                         document.querySelector('#app-navigation-vue') ||
                         document.querySelector('.app-navigation');
            result.navFound = !!nav;
            result.navTag = nav ? nav.tagName : null;
            result.navId = nav ? nav.id : null;
            result.navWidth = nav ? nav.getBoundingClientRect().width : null;
            result.navHTML = nav ? nav.innerHTML.substring(0, 3000) : null;

            // Check for content area
            const content = document.querySelector('#app-content') ||
                            document.querySelector('#app-content-vue') ||
                            document.querySelector('.app-content');
            result.contentFound = !!content;

            // Check for contact list
            const contactList = document.querySelector('.contacts-list') ||
                                document.querySelector('.app-content-list') ||
                                document.querySelector('[class*="contact-list"]') ||
                                document.querySelector('[class*="contactsList"]');
            result.contactListFound = !!contactList;
            result.contactListTag = contactList ? contactList.tagName : null;
            result.contactListClasses = contactList ? contactList.className : null;

            // Get ALL class names that contain 'contact' or 'list'
            const allEls = document.querySelectorAll('[class*="contact"], [class*="list"], [class*="Contact"], [class*="List"]');
            result.contactRelatedClasses = Array.from(allEls).map(el => ({
                tag: el.tagName,
                classes: el.className,
                id: el.id || '',
                rect: el.getBoundingClientRect()
            })).filter(e => e.rect.width > 50).slice(0, 30);

            // Get the search input
            const search = document.querySelector('input[type="search"], input[placeholder*="uch"], input[placeholder*="earch"]');
            result.searchFound = !!search;
            result.searchPlaceholder = search ? search.placeholder : null;
            result.searchParentClasses = search ? search.parentElement.className : null;
            result.searchRect = search ? search.getBoundingClientRect() : null;

            // Get first contact item structure
            const contactItem = document.querySelector('.list-item, .contacts-list__item, [class*="list-item"], [class*="ListItem"]');
            result.contactItemFound = !!contactItem;
            if (contactItem) {
                result.contactItemClasses = contactItem.className;
                result.contactItemHTML = contactItem.outerHTML.substring(0, 2000);
                result.contactItemRect = contactItem.getBoundingClientRect();
                result.contactItemStyles = window.getComputedStyle(contactItem);
                result.contactItemHeight = result.contactItemStyles.height;
                result.contactItemPadding = result.contactItemStyles.padding;
            }

            // Get avatar structure
            const avatar = contactItem ? contactItem.querySelector('[class*="avatar"], [class*="Avatar"], .list-item-icon') : null;
            if (avatar) {
                result.avatarClasses = avatar.className;
                result.avatarRect = avatar.getBoundingClientRect();
                result.avatarStyles = {
                    width: window.getComputedStyle(avatar).width,
                    height: window.getComputedStyle(avatar).height,
                    borderRadius: window.getComputedStyle(avatar).borderRadius,
                };
            }

            // Get contact name/subtitle structure
            const nameEl = contactItem ? contactItem.querySelector('.list-item-content__name, [class*="name"], [class*="Name"]') : null;
            if (nameEl) {
                result.nameFontSize = window.getComputedStyle(nameEl).fontSize;
                result.nameFontWeight = window.getComputedStyle(nameEl).fontWeight;
                result.nameColor = window.getComputedStyle(nameEl).color;
            }

            const subtitleEl = contactItem ? contactItem.querySelector('.list-item-content__subname, [class*="subtitle"], [class*="subname"]') : null;
            result.subtitleFound = !!subtitleEl;
            if (subtitleEl) {
                result.subtitleText = subtitleEl.textContent;
                result.subtitleFontSize = window.getComputedStyle(subtitleEl).fontSize;
                result.subtitleColor = window.getComputedStyle(subtitleEl).color;
            }

            // Overall layout widths
            const allSections = document.querySelectorAll('#content > *, #content-vue > *');
            result.topLevelSections = Array.from(allSections).map(el => ({
                tag: el.tagName,
                id: el.id,
                classes: el.className.toString().substring(0, 100),
                width: el.getBoundingClientRect().width,
                height: el.getBoundingClientRect().height,
            }));

            return result;
        });

        console.log('=== CONTACTS APP STRUCTURE ===');
        console.log(JSON.stringify(appContent, null, 2));

        // Take a screenshot of just the contact list area
        const listArea = page.locator('.contacts-list, .app-content-list, [class*="contact-list"]').first();
        if (await listArea.isVisible().catch(() => false)) {
            await listArea.screenshot({ path: 'e2e/screenshots/contacts-list-area.png' });
        }
    });

    test('Capture Touchpoint app full UI structure', async ({ page }) => {
        await page.goto('/apps/touchpoint');
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(2000);

        // Screenshot of all-notes view (default, no contact selected)
        await page.screenshot({ path: 'e2e/screenshots/crm-notes-all-notes.png', fullPage: true });

        // Check all-notes view
        const allNotesInfo = await page.evaluate(() => {
            const allNotesDetail = document.querySelector('#crm-all-notes-detail');
            const allNotesList = document.querySelector('#crm-all-notes-list');
            const allNoteItems = allNotesList ? allNotesList.querySelectorAll('.crm-note-item') : [];
            const contactNames = allNotesList ? allNotesList.querySelectorAll('.crm-note-contact-name') : [];
            return {
                allNotesVisible: allNotesDetail ? allNotesDetail.style.display !== 'none' : false,
                allNotesCount: allNoteItems.length,
                contactNamesCount: contactNames.length,
                contactNamesText: Array.from(contactNames).map(el => el.textContent.trim()),
                headerText: allNotesDetail ? (allNotesDetail.querySelector('h2') || {}).textContent : null,
            };
        });
        console.log('=== ALL NOTES VIEW ===');
        console.log(JSON.stringify(allNotesInfo, null, 2));

        // Click on a contact to see the notes view
        const contactItem = page.locator('.crm-contact-item').first();
        if (await contactItem.isVisible().catch(() => false)) {
            await contactItem.click();
            await page.waitForTimeout(1000);
            await page.screenshot({ path: 'e2e/screenshots/crm-notes-with-contact.png', fullPage: true });
        }

        const crmStructure = await page.evaluate(() => {
            const result = {};

            // Navigation
            const nav = document.querySelector('#app-navigation');
            result.navWidth = nav ? nav.getBoundingClientRect().width : null;
            result.navHTML = nav ? nav.innerHTML.substring(0, 2000) : null;

            // Contact list
            const list = document.querySelector('#crm-contacts-panel, .app-content-list');
            result.listWidth = list ? list.getBoundingClientRect().width : null;
            result.listHTML = list ? list.innerHTML.substring(0, 3000) : null;

            // Search bar
            const search = document.querySelector('#crm-contact-search');
            result.searchFound = !!search;
            result.searchRect = search ? search.getBoundingClientRect() : null;
            result.searchInNav = search ? !!search.closest('#app-navigation') : false;
            result.searchInList = search ? !!search.closest('.app-content-list, #crm-contacts-panel') : false;

            // Contact items
            const items = document.querySelectorAll('.crm-contact-item, .app-content-list-item');
            result.contactItemCount = items.length;
            if (items.length > 0) {
                const item = items[0];
                result.itemHeight = item.getBoundingClientRect().height;
                result.itemHTML = item.outerHTML.substring(0, 1000);

                const avatar = item.querySelector('.app-content-list-item-icon');
                result.avatarSize = avatar ? {
                    width: window.getComputedStyle(avatar).width,
                    height: window.getComputedStyle(avatar).height,
                } : null;

                const name = item.querySelector('.app-content-list-item-line-one');
                result.nameStyles = name ? {
                    fontSize: window.getComputedStyle(name).fontSize,
                    fontWeight: window.getComputedStyle(name).fontWeight,
                } : null;

                const email = item.querySelector('.app-content-list-item-line-two');
                result.emailFound = !!email;
                result.emailText = email ? email.textContent : null;
            }

            // Detail panel
            const detail = document.querySelector('#crm-notes-detail');
            result.detailVisible = detail ? detail.style.display !== 'none' : false;

            // Notes
            const notes = document.querySelectorAll('.crm-note-item');
            result.noteCount = notes.length;

            // Modal close button
            const closeBtn = document.querySelector('.crm-modal-header .crm-modal-close');
            if (closeBtn) {
                const s = window.getComputedStyle(closeBtn);
                result.closeBtn = {
                    width: s.width,
                    height: s.height,
                    background: s.background,
                    border: s.border,
                    borderRadius: s.borderRadius,
                };
            }

            return result;
        });

        console.log('=== CRM NOTES STRUCTURE ===');
        console.log(JSON.stringify(crmStructure, null, 2));
    });
});

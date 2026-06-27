/*
 * SPDX-FileCopyrightText: 2026 Touchpoint Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { createApp } from 'vue'
import { createPinia } from 'pinia'
import AdminSettings from './components/AdminSettings.vue'

const app = createApp(AdminSettings)
app.use(createPinia())
app.mount('#crm-notes-admin-settings')

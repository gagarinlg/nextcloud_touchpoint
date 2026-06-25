# CLAUDE.md

Guidance for working in this repository.

## What this is

**CRM Notes** (`crm_notes`) — a Nextcloud app that attaches CRM-style notes to
address-book contacts. Notes have a type (Call, Meeting, Email, Task, General,
plus user-defined types with colors), can be pinned, can carry file attachments,
and can be shared with other users/groups. It surfaces:

- A standalone app page (left-nav "CRM Notes") with an all-notes view and a
  note-types manager.
- A tab inside the **Contacts** app (via `LoadContactsOcaApiEvent`) showing the
  notes for the open contact.
- An entry in the contacts menu (hover card) linking to a contact's notes.
- An admin settings section.

Version is **1.1.0** (kept in sync between `appinfo/info.xml` and `package.json`).

## Tech stack

- **Backend:** PHP 8.2+, Nextcloud App Framework. PSR-4 `OCA\CrmNotes\` → `lib/`.
- **Frontend:** Vue 3 + Pinia, built with Vite (`@nextcloud/vite-config`),
  using `@nextcloud/vue` 9.x components.
- **Tests:** PHPUnit 10.5 (unit) + Playwright (e2e, German locale, against
  `http://localhost` with admin/admin).

## Compatibility

- **Nextcloud:** 32, 33, and 34 — declared in `appinfo/info.xml`
  (`<nextcloud min-version="32" max-version="34" />`). All server APIs used exist
  since NC ≤32; the app uses no NC-33-introduced API. When touching backend code,
  do not introduce APIs newer than NC 32 without bumping `min-version`.
- **PHP:** 8.2–8.5.
- **`@nextcloud/vue`:** 9.x line targets NC 33/34 styling and is fine on 32. There
  is no v10 yet.
- The controllers still use **legacy PHPDoc annotations** (`@NoAdminRequired`,
  `@NoCSRFRequired`) rather than PHP attributes. These work on 32–34 but are
  deprecated; prefer `#[NoAdminRequired]` etc. for new controllers.

## Layout

```
appinfo/
  info.xml            App manifest: id, version, deps, navigation, contactsmenu, settings.
  routes.php          All HTTP routes (array-based routing).
lib/
  AppInfo/Application.php       Bootstrap; registers the Contacts-tab event listener.
  Controller/                   Thin controllers; errors funneled through ErrorHandler.
  Service/                      Business logic (NoteService, NoteTypeService, SettingsService).
  Db/                           QBMapper mappers + Entities (Note, NoteType, NoteContact,
                                NoteFile, NoteSharing).
  Migration/                    VersionXXXXDate*.php schema migrations.
  ContactsMenu/Provider.php     Contacts hover-menu entry.
  Listener/                     LoadContactsTabListener (injects the Contacts tab assets).
  Settings/                     Admin + AdminSection.
src/
  main.js                       App page entry.
  contacts-integration.js       Non-Vue island bolted into the Contacts app tab.
  adminSettings.js              Admin settings entry.
  App.vue, components/*.vue     UI.
  services/*.js                 Axios API clients.
  stores/*.js                   Pinia stores (notes, noteTypes, contacts, settings).
  utils/color.js
css/, js/, templates/           Built assets + PHP templates served to the page.
tests/Unit/                     PHPUnit tests mirroring lib/ structure.
e2e/                            Playwright specs.
```

## Database tables

All prefixed `crm_`: `crm_notes`, `crm_note_types`, `crm_note_contacts`,
`crm_note_files`, `crm_note_sharing`. Schema changes go through a new
`lib/Migration/VersionXXXXDate*.php` step — never edit existing migrations that
have shipped. Note that there are **no DB-level foreign keys**; child-row cleanup
on note deletion is done in `NoteService::delete()`, so do not bypass the service
layer for deletes.

## Common commands

```bash
# Frontend
npm run build           # production Vite build into js/ and css/
npm run dev             # watch build (development mode)
npm run lint            # eslint src/

# Backend tests
composer install
./vendor/bin/phpunit            # uses phpunit.xml (tests/Unit, excludes Migration)

# E2E (needs a running Nextcloud at localhost with the app installed)
npx playwright test
```

After changing anything in `src/`, run `npm run build` — the app serves the
prebuilt bundles under `js/`/`css/`, not the sources.

## Conventions & gotchas

- **Auth/ownership:** notes and note types are scoped by `user_id`. Review any
  new query or mutation for cross-user access (this is the historically weak
  area — sharing currently grants full edit/delete, and there are IDOR risks
  around file IDs and note types). Always verify the caller owns or is explicitly
  permitted on the row before mutating.
- **Contact UIDs** in URLs must be `encodeURIComponent`-ed on the JS side — they
  can contain `/`, `#`, `@`.
- **Markdown** note bodies are rendered through `marked` + **DOMPurify**; keep
  user-supplied HTML sanitized. Don't introduce `innerHTML` paths that bypass it.
- **i18n:** wrap all user-facing strings in `t('crm_notes', '...')`. Use
  interpolation (`t(..., { count })`) rather than concatenating numbers with
  translated fragments.
- **Design system:** prefer `@nextcloud/vue` components and NC CSS variables
  (`--color-*`, `--default-grid-baseline`) over hand-rolled controls and
  hardcoded hex/px. Use `vue-material-design-icons`, not emoji, for iconography.
- The repo root contains several throwaway `test_*.spec.js` files and a backup
  DB dump — these are scratch artifacts, not part of the app.

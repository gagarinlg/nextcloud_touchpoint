<!-- SPDX-FileCopyrightText: 2026 Touchpoint Contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# CLAUDE.md

Guidance for working in this repository.

## What this is

**Touchpoint** (`touchpoint`) — a Nextcloud app that attaches CRM-style notes to
address-book contacts. Notes have a type (Call, Meeting, Email, Task, General,
plus user-defined types with colors), can be pinned, can carry file attachments,
and can be shared with other users/groups. It surfaces:

- A standalone app page (left-nav "Touchpoint") with an all-notes view and a
  note-types manager.
- A tab inside the **Contacts** app (via `BeforeTemplateRenderedEvent` — Contacts
  does not fire its own event on its page, so the global before-render hook is
  used instead; see `Application.php`) showing the notes for the open contact.
- An entry in the contacts menu (hover card) linking to a contact's notes.
- An admin settings section.

The app version is kept in sync between `appinfo/info.xml` (`<version>`) and
`package.json` (`"version"`); treat those two files as the source of truth rather
than pinning a number here.

## Tech stack

- **Backend:** PHP 8.1+, Nextcloud App Framework. PSR-4 `OCA\Touchpoint\` → `lib/`.
- **Frontend:** Vue 3 + Pinia, built with Vite (`@nextcloud/vite-config`),
  using `@nextcloud/vue` 9.x components.
- **Tests:** PHPUnit 10.5 (unit) + Playwright (e2e, German locale, against
  `http://localhost` with admin/admin).

## Compatibility

- **Nextcloud:** 32, 33, and 34 — declared in `appinfo/info.xml`
  (`<nextcloud min-version="32" max-version="34" />`). All server APIs used exist
  since NC ≤32; the app uses no NC-33-introduced API. When touching backend code,
  do not introduce APIs newer than NC 32 without bumping `min-version`.
- **PHP:** 8.1–8.5.
- **`@nextcloud/vue`:** 9.x line targets NC 33/34 styling and is fine on 32. There
  is no v10 yet.
- All controllers already use **PHP attributes** exclusively
  (`#[NoAdminRequired]`, `#[NoCSRFRequired]`, `#[UserRateLimit]`) — no
  controller uses the legacy PHPDoc-annotation form. Keep using attributes for
  any new controller/route.

## Layout

```
appinfo/
  info.xml            App manifest: id, version, deps, navigation, contactsmenu, settings.
  routes.php          All HTTP routes (array-based routing).
lib/
  AppInfo/Application.php       Bootstrap; registers the Contacts-tab event listener, NoteSearchProvider,
                                and Notifier.
  Controller/                   Thin controllers; errors funneled through ErrorHandler.
  Service/                      Business logic (NoteService, NoteTypeService, SettingsService).
  Db/                           QBMapper mappers + Entities (Note, NoteType, NoteContact,
                                NoteFile, NoteSharing).
  Migration/                    VersionXXXXDate*.php schema migrations.
  ContactsMenu/Provider.php     Contacts hover-menu entry.
  Listener/                     LoadContactsTabListener (injects the Contacts tab assets).
  Search/                       NoteSearchProvider — Unified Search integration (OCP\Search\IProvider).
  Notification/Notifier.php     OCP\Notification\INotifier — 'note_shared' / 'note_mention' subjects,
                                deep-links to #note/{noteId}.
  Notification/NotificationService.php
                                Dispatch side (OCP\Notification\IManager). Called from
                                NoteService::create()/update(): notifies newly-added share targets
                                (groups expanded via IGroupManager) and @userId mentions in note
                                content (validated via IUserManager::userExists()). Never notifies
                                the acting user; swallows and logs its own exceptions. The frontend
                                resolves the #note/{noteId} hash in App.vue (see src/ below).
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

All named `touchpoint_*`: `touchpoint_notes`, `touchpoint_note_types`, `touchpoint_note_contacts`,
`touchpoint_note_files`, `touchpoint_note_sharing`. Schema changes go through a new
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
# SQLite drivers (pdo_sqlite) are installed on the dev machine.
# Tests should use a real SQLite database instead of mocking DB layers.
# Integration tests under tests/Integration/ already use SQLite via PdoQueryBuilder.

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
- **i18n:** wrap all user-facing strings in `t('touchpoint', '...')`. Use
  interpolation (`t(..., { count })`) rather than concatenating numbers with
  translated fragments.
- **Design system:** prefer `@nextcloud/vue` components and NC CSS variables
  (`--color-*`, `--default-grid-baseline`) over hand-rolled controls and
  hardcoded hex/px. Use `vue-material-design-icons`, not emoji, for iconography.
- The repo root contains several throwaway `test_*.spec.js` files and a backup
  DB dump — these are scratch artifacts, not part of the app.
- **`_searchSeq`** in the notes Pinia store is an internal race-guard counter;
  do not reset it outside of `cancelSearch()`. JavaScript numbers become
  `Infinity` at 2^53-1 (not a wrap-around); at `Infinity`, all subsequent
  `runSearch()` calls would be silently discarded. In practice this requires
  ~285,000 years of continuous 1000-searches/sec and is not a real risk.
- **`GET /api/notes/search`** returns HTTP 400 for `q` > 500 characters —
  consistent with `assertMaxLength` validation elsewhere. Do not switch to
  silent truncation.
- **`vue-material-design-icons`** icons compute their own `aria-hidden`/`aria-label`
  from a `title` **prop**, not from raw `aria-label`/`role` attributes passed by
  the caller — the component's template binds those internally and overrides
  whatever `v-bind="$attrs"` receives, so `<IconFoo aria-label="…" role="img" />`
  silently renders `aria-hidden="true"` (the icon vanishes from the a11y tree).
  Pass `:title="t('touchpoint', '…')"` instead to label an icon-only glyph.

## Keep documentation in sync with changes

Treat docs as part of the change, not a follow-up. Any change that alters
behaviour, structure, or contracts **must** update the relevant docs **in the
same commit/PR** as the code:

- **`CLAUDE.md`** — update when you change the app's structure, conventions,
  tech stack, compatibility (NC/PHP versions), the DB schema/tables, or any of
  the gotchas above. If a rule here becomes wrong, fix it; don't leave it stale.
- **API changes are non-negotiable.** Whenever you add, change, remove, or
  deprecate anything in the **API** — the internal HTTP routes
  (`/apps/touchpoint/api/...` in `appinfo/routes.php`), OCS/REST routes,
  dispatched `OCA\Touchpoint\Event\*` events, the `window.OCA.Touchpoint` JS API,
  capability keys, webhook payloads, or request/response shapes — update
  **`docs/API.md`** in the same commit, plus `CHANGELOG.md`, `docs/ROADMAP.md`
  (mark items shipped / adjust status), and `CLAUDE.md` if the contract or
  conventions shift. A published public-API contract is a compatibility promise;
  document version and deprecation, never silently change it.
- **User-facing or admin-facing changes** — update `README.md` (and screenshots
  if the UI moved meaningfully) and `CHANGELOG.md` under the unreleased section.
- **New routes / controllers / services / migrations / components** — keep the
  **Layout** and **Database tables** sections of this file accurate, update the
  prose in **`docs/ARCHITECTURE.md`**, and run **`make docs`** to refresh its
  auto-generated inventory (CI's "Docs in sync" job fails otherwise).
- **`docs/ARCHITECTURE.md`** is the orientation doc for future sessions: prose is
  hand-maintained; the inventory block is generated by `scripts/gen-docs.php`
  (`make docs`) — never hand-edit between the `AUTOGEN` markers.
- When unsure whether a doc needs updating, err toward updating it; a stale doc
  is worse than a verbose one.

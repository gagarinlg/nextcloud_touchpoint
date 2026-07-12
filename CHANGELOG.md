<!-- SPDX-FileCopyrightText: 2026 Touchpoint Contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/), and the project adheres to
[Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added
- **Admin: global note type management** — the Touchpoint admin settings page
  now has a "Global note types" section below "Note visibility" for managing
  the shared, instance-wide default note types (create, edit, delete), backed
  by new admin-only endpoints (`AdminNoteTypeController`,
  `/apps/touchpoint/api/admin/note-types`; create/update/delete are
  rate-limited exactly like the per-user note-type routes, matching
  `NoteTypeController`'s own pattern — the two read routes, `index` and
  `usage`, carry no rate limit on either controller) and a new frontend
  service
  (`src/services/AdminNoteTypeService.js`), Pinia store
  (`src/stores/globalNoteTypes.js`) and modal component
  (`AdminNoteTypeModal.vue`). Deleting a type still in use by any note on the
  instance is rejected with HTTP 409; the UI now checks usage proactively via a
  new `GET /api/admin/note-types/{id}/usage` endpoint before opening the
  confirm dialog, showing a specific in-use count instead of a confirm-then-fail
  flow (mirroring the pre-existing personal-type delete flow). See
  `docs/API.md`'s "Admin: global note types" section.
  `NoteTypeService::seedDefaults(string $userId)`'s return type widened from
  `void` to `array` (the existing-or-just-seeded list of global defaults), so
  `Settings\Admin::getForm()` can seed its initial state directly from the
  call instead of a redundant follow-up query; `PageController` (per-user
  seeding) is unaffected since it already discarded the return value.
- **Note type validation:** creating or renaming a note type (both the
  personal `POST`/`PUT /api/note-types` and admin
  `POST`/`PUT /api/admin/note-types` routes) now rejects a blank or
  whitespace-only `name` with HTTP 400 instead of persisting it.
- **Dashboard widget** (`OCA\Touchpoint\Dashboard\RecentNotesWidget`): a "Recent
  notes" card on the Nextcloud dashboard home screen, implementing
  `OCP\Dashboard\IAPIWidgetV2` + `OCP\Dashboard\IButtonWidget` +
  `OCP\Dashboard\IIconWidget`. Shows the current user's up to 7 most recent
  notes (owned + shared, same access scope as `NoteService::findAll()`, pinned
  notes prioritized ahead of unpinned ones), each with the note's title
  (truncated to 60 characters), a subtitle combining the linked contact's name
  and note type label (also truncated to 60 characters), a deep link straight
  to the note itself (`#note/<id>`, the same convention used by the
  note_shared/note_mention notification deep-links), the app icon, and a
  small pin badge overlay for pinned notes. A "Show all" button links to the
  Touchpoint app page. Degrades gracefully (omits the affected subtitle half)
  when a note has no linked contact or its contact/note type can no longer be
  resolved. `getItemsV2()` also wraps its note/note-type/contact lookups in a
  try/catch: since Nextcloud core's dashboard batch endpoint has no per-widget
  error boundary of its own, an uncaught exception here would 500 every other
  app's dashboard widget on the page too — a failure is logged and degrades to
  an empty, distinctly-worded widget instead. A caller-supplied `$limit` is
  clamped to `[1, 30]`. When the admin's "public notes" setting is enabled, the
  widget shows no items with a distinct "hidden while public notes mode is
  enabled" message (rather than surfacing every user's notes on every
  dashboard, and rather than the generic "no notes" message, which would be
  misleading here).
- **Notification dispatch** (`OCA\Touchpoint\Notification\NotificationService`):
  `NoteService::create()`/`update()` now dispatch real notifications via
  `OCP\Notification\IManager`. `create()` notifies every share target (group
  targets expanded to their current members via `IGroupManager`, capped at 200
  distinct recipients per note — truncated with a logged warning beyond that)
  since all of them are by definition new. `update()` diffs the note's sharing
  set before vs. after the save and notifies only the targets newly added in
  this call — a recipient who was already shared with is not re-notified just
  because the owner re-saved the share list or tweaked a `canEdit` flag.
  `create()` always scans the note body for `@userId` mention patterns;
  `update()` scans only when the submitted content actually differs from the
  note's previously-stored content (a resubmission of byte-identical content
  does not re-scan/re-notify). Each candidate that resolves to a real user via
  `IUserManager::userExists()` is notified, capped at 50 distinct valid
  mentions per note and additionally capped at 200 distinct candidate tokens
  scanned (valid or not), bounding `userExists()` calls against a pathological
  body full of fabricated `@token`s. A mentioned user who does not yet have
  read access to the note (no share/ownership) is notified WITHOUT the note's
  title in the notification text — only a mentioned user who already has
  access sees the title — so a potentially sensitive title is never disclosed
  via a bell/push preview to someone who cannot yet open the note. The acting
  user is never notified about their own share/mention. A `sharing` payload is
  capped at 100 distinct targets per request
  (`NoteService::MAX_SHARE_TARGETS_PER_REQUEST`, HTTP 400 beyond that), checked
  before any per-target principal lookup so an oversized payload cannot force
  many sequential DB/`IGroupManager` round-trips. `note#create`/`note#update`
  are both rate-limited (`#[UserRateLimit(limit: 30, period: 60)]`) as defense
  in depth. Deleting a note deletes any outstanding stored notification still
  referencing it, for every recipient
  (`NotificationService::dismissNoteNotifications()`, via
  `IManager::markProcessed()`). All dispatch is best-effort: `NotificationService`
  catches and logs any exception from
  `IManager::createNotification()`/`notify()`/`markProcessed()` (or
  group/user lookups) so a notification failure can never fail the note
  save/delete that triggered it.
- **Notifications** (`OCA\Touchpoint\Notification\Notifier`): implements
  `OCP\Notification\INotifier`, registered in `Application::register()`. Handles
  `note_shared` and `note_mention` subjects with a parsed subject (including
  the note's title, truncated to 60 characters, when available), a rich
  subject carrying a `user` RichObjectString parameter, an icon (`app-dark.svg`),
  and a deep link (`rawurlencode`'d) to `#note/{noteId}`. Before rendering,
  self-cleanup strictness differs by subject: `note_shared` re-verifies the
  recipient can still access the referenced note; `note_mention` only checks
  that the note still exists at all (not the recipient's access — @mention is
  deliberately usable on a user with no prior relationship to the note, so
  gating self-cleanup on access would delete the notification before that user
  ever sees it). Either throws `OCP\Notification\AlreadyProcessedException` if
  its check fails, so the notification is garbage-collected instead of left as
  a dead-end bell entry. Task due/overdue notifications are deferred until the
  Tasks integration ships. `note_mention`'s title-disclosure decision is
  re-evaluated on every render (not just at dispatch time): a mentioned user
  who had access when the title was persisted, but whose access is later
  revoked while the notification is still outstanding, now sees the
  title-less fallback wording on every subsequent bell/mobile fetch instead
  of the stale, now-inappropriate title.
- **Frontend `#note/{noteId}` deep link** (`src/App.vue`): resolves a
  `#note/{noteId}` hash (from a notification) on initial load and on
  `hashchange` — fetches the note via `GET /api/notes/{id}`, switches to the
  contact the note belongs to, highlights and scrolls to the note once rendered
  (`src/components/ContactNotesView.vue`, `src/components/NoteItem.vue`,
  respecting `prefers-reduced-motion`), then normalises the URL to
  `#contact/{uid}`. A missing or inaccessible note shows a toast instead of
  navigating. The highlighted note also receives DOM focus
  (`tabindex="-1"`, `preventScroll`) and an `aria-live` announcement of its
  title, so keyboard/screen-reader users get the same "this is the note the
  notification was about" confirmation sighted users get from the visual
  highlight.
- **`GET /api/notes/{id}` rate limit**: added `#[UserRateLimit(limit: 60,
  period: 60)]` to `NoteController::show()`, matching the pattern already used
  on `search`/`create`/`update`. Bounds a scripted sweep across sequential note
  IDs — access control itself was already sound (nonexistent and inaccessible
  IDs both 404 uniformly) but this endpoint is also what the notification
  deep-link fetches, making it a more attractive probing target.
- **Full-text note search** (`GET /api/notes/search?q=<term>`): case-insensitive
  iLike query across note title and content, scoped to owned and explicitly-shared
  notes. Returns HTTP 400 for `q` longer than 500 characters (not silently
  truncated). Blank or whitespace-only `q` returns 200 `[]`. Rate-limited to 30
  requests per 60 seconds per user (`#[UserRateLimit(limit: 30, period: 60)]`).
  The in-app search box debounces 300 ms, requires at least 2 characters,
  paginates with a **Load more** button, discriminates `429`/`400`/generic
  errors with actionable messages, and shows an explicit "search unavailable"
  state in public mode.
- **Nextcloud Unified Search provider** (`NoteSearchProvider`): notes now appear
  in the global Nextcloud search bar with the app icon. Results deep-link to the
  contact view using `rawurlencode` (spaces as `%20`, not `+`); long sublines are
  truncated with an ellipsis. Public mode suppresses search results in both the
  HTTP endpoint and the Unified Search provider to prevent cross-user information
  disclosure.
- **`scripts/check-l10n.js`** (`make check-l10n`): a permanent regression guard
  against `l10n/<lang>.json`/`l10n/<lang>.js` drift — the two are hand-maintained
  with no shared generation step, and disagreement between them has repeatedly
  shipped silently-untranslated strings for server-side-loaded text (the `.json`
  file is what Nextcloud's `IL10N` actually loads at runtime). Parses every
  language pair under `l10n/` and diffs key sets and values. Wired into
  `make check` and, as of this round, into CI's frontend job, so a future catalog
  edit that reintroduces `.json`/`.js` drift fails the PR check instead of merging
  silently.

### Fixed
- `NoteTypeService`'s validation error messages (e.g. an invalid/unknown
  `icon` value on `POST`/`PUT /api/note-types` and the admin equivalents)
  were being translated twice — once in `NoteTypeService` and again in
  `ErrorHandler` — which crashed with an uncaught `ValueError` whenever the
  message contained a stray, attacker-influenceable `%` (e.g.
  `icon=100%d`), turning a validation failure that should be a clean `400`
  into an unhandled fatal error. `NoteTypeService` no longer translates its
  own exception messages; translation happens exactly once, in
  `ErrorHandler`, matching `NoteService`'s existing convention.
  `ErrorHandler::translateError()` also now guards its own `IL10N::t()` call
  against this class of failure so a similarly-shaped message can never
  crash a request in the future. No user-visible message text changed as a
  result (the `message` field in error responses has always been localized
  via `ErrorHandler`; see `docs/API.md`'s "Error handling" section).
- **`NoteTypeController`'s personal note-type routes were unlimited** —
  `POST`/`PUT`/`DELETE /api/note-types{,/{id}}` carried no `#[UserRateLimit]`
  at all. They now carry the same `UserRateLimit(limit: 30, period: 60)` as
  `NoteController`'s `create`/`update`, closing the gap ahead of adding the
  admin `note-types` routes (which start out rate-limited from day one; see
  the "Admin: global note type management" bullet above).
- In-app search no longer flashes a false "No notes found" empty state during the
  300 ms debounce, and toggling the sort order while a search is active now
  re-runs the search instead of silently re-ordering only the background list.
- Search results are no longer silently capped at 50 with no affordance to reach
  the rest — the search list now paginates like the all-notes list.
- Every `<NcButton>`/`NcDialog` confirm button in the app was passing its visual
  style via the wrong prop (`type="primary"/"secondary"/"tertiary"`, the native
  HTML button-type attribute) instead of `variant`, the prop that actually
  drives styling — every button silently rendered with the default
  secondary/tertiary look, so Save/primary actions and destructive Delete
  confirmations were visually indistinguishable from Cancel. Fixed across all
  Vue components.
- `AdminSettings.vue`'s icon-only Edit/Delete buttons used `:title` (a hover
  tooltip on the native `<button>`) instead of `:aria-label`, leaving them with
  no accessible name for screen readers. Now uses `:aria-label`, matching the
  rest of the app.
- The admin "Global note types" list never refreshed on mount (only after a
  mutation) and its error state had no retry action; now loads on mount and
  shows a retryable empty-content state, matching the personal note-types view.
- Save/delete failures in the note-type admin UI now surface the server's
  specific validation message (e.g. duplicate name) instead of a generic
  string; the delete confirmation copy no longer claims notes "lose their
  type" (deletion is always blocked while any note still uses the type).
- `NoteTypeService::create()`/`update()`/`delete()` and their `*Global()`
  counterparts no longer duplicate validation/persistence logic; `NoteTypeMapper`'s
  `countGlobalUsage()` (a from-scratch reimplementation of
  `NoteMapper::countByNoteType()`'s null-`$userId` case) was removed in favor of
  reusing that existing method. Note-type names are now rejected as invalid if
  empty or whitespace-only (previously only a max-length check existed),
  applied consistently to per-user and admin/global create/update.
- `AdminNoteTypeModal.vue`/`NoteTypeModal.vue` no longer each keep a literal
  copy of the icon-option list and themed-default-color helper; both now import
  a single shared version from `utils/noteTypeIcon.js` (which also gained the
  previously-missing `icon-note` legacy option, closing a divergence from the
  server-side icon allow-list). Both modals now show a live badge preview and
  icon glyphs in the icon picker.
- `AdminNoteTypeModal.vue`/`NoteTypeModal.vue` were ~90% duplicated markup and
  styling; both are now thin wrappers around a new shared
  `NoteTypeFormModal.vue` (props/emits vs. Pinia store respectively).
  `NoteTypesView.vue`/`AdminSettings.vue`'s identical type-list row (badge +
  edit/delete buttons + CSS) is likewise extracted into a new
  `NoteTypeListItem.vue`.
- The personal note-types list (`NoteTypesView.vue`) was missing the
  double-click delete guard the admin list already had — a rapid double-click
  on Delete could fire two concurrent DELETE requests for the same row. Added
  the same per-row `deletingId` guard/spinner as `AdminSettings.vue`.
- `AdminSettings.vue`'s "Global note types" empty state was a bare, unstyled
  sentence; now uses the same `NcEmptyContent` (icon + description + "Add
  type" action) pattern as every other empty state in the app.
- Creating, renaming, or deleting a note type (personal or global) gave no
  success feedback beyond the modal silently closing; both flows now show a
  confirmation toast on success, matching the existing "Settings saved"
  precedent.
- Deleting a note-type row (personal or global) left keyboard/screen-reader
  focus on nothing (fell back to `<body>`) since the focused Delete button was
  removed from the DOM. Focus now moves to the next remaining row (or the "Add
  type" button if the list is now empty).
- The icon picker's "Note (legacy)" option — a compatibility shim for old rows,
  not a real style choice — no longer appears when creating a new type; it
  still appears (clearly labelled "kept for compatibility") when editing a
  type that already carries it.
- `AdminSettings.vue`'s "Notes are public" switch bypassed the app's
  established `useSettingsStore()` Pinia pattern in favor of hand-rolled local
  state; now goes through the same store `SettingsView.vue` uses.
- `lib/Settings/Admin.php` migrated from the deprecated `OCP\IInitialStateService`
  to `OCP\AppFramework\Services\IInitialState`, matching
  `PageController.php`'s existing usage.
- Added the missing German translation for the Contacts-tab island's
  "Create a note type first." empty-state hint.
- A global note type (e.g. one of the five seeded defaults) shown in a
  non-admin user's personal "Note types" list rendered fully-functional-looking
  Edit/Delete buttons that silently 404'd on click (global rows aren't owned by
  any user, so the per-user update/delete path never matches them).
  `NoteTypeListItem.vue` now shows a "Managed by admin" label instead of
  controls for global types in the personal view; the admin's own "Global note
  types" view (which passes a new `manage-global` prop) is unaffected and keeps
  working Edit/Delete on every row.
- `lib/Settings/Admin.php`'s admin settings page never seeded the five global
  default note types — seeding only happened when someone opened the
  Touchpoint app page first. An admin whose first action was Settings >
  Touchpoint on a fresh instance saw an empty "Global note types" list. Now
  seeds before reading the list back, mirroring `PageController::index()`.
- The Edit/Delete icon-only buttons on every note-type row had an identical,
  non-interpolated accessible name ("Edit type" / "Delete type") across every
  row, giving screen-reader users no way to tell which type a control acted
  on. Now interpolates the type's name into the label.
- The delete-flow orchestration (usage check, confirm dialog, per-row
  re-entrancy guard, focus recovery) duplicated between `NoteTypesView.vue`
  and `AdminSettings.vue` is now a shared composable,
  `composables/useNoteTypeDeletion.js`. Likewise, `stores/noteTypes.js` and
  `stores/globalNoteTypes.js`'s identical CRUD/guard action set is now built
  from a shared factory, `stores/createNoteTypeCrudStore.js`.
- The icon picker's "Note (legacy, kept for compatibility)" option had no
  German translation — the l10n catalogs only translated an older, shorter,
  now-unused string ("Note (legacy)"), so German-locale users saw raw English.
  Also added missing German translations for the "Note type saved"/"Note type
  deleted" success toasts and the admin empty-state description ("These note
  types are available to every user on this instance.").
- Added an e2e test asserting a non-admin user is rejected (not 2xx) on every
  `/api/admin/note-types*` route, and a reflection-based unit test asserting
  `AdminNoteTypeController`'s action methods never carry `#[NoAdminRequired]` —
  the admin-only access-control contract this controller relies on had no
  regression test at either layer before this round.
- German-locale users previously saw raw English text for the "Recent notes"
  dashboard widget (title, empty/error states, "Show all", "Untitled") and the
  `note_shared`/`note_mention` notification subjects: those strings existed
  only in `l10n/de.js`/`l10n/de_DE.js` (the client-side catalog), never in
  `l10n/de.json`/`l10n/de_DE.json` (the catalog Nextcloud's server-side
  `IL10N` actually loads), so any server-rendered surface (notifications,
  the dashboard API) fell back to English regardless of locale. All four
  catalogs are now in sync. Also corrected a drifted "Note visibility"
  translation ("Sichtbarkeit der Notiz" -> "Notizsichtbarkeit").
- All plural (count-dependent) German strings — e.g. "%n note found", "%n
  more note loaded", the note-type in-use warnings — were keyed by their flat
  singular source text and were therefore unreachable by
  `@nextcloud/l10n`'s `translatePlural()` for any count other than 1; every
  other count silently rendered the raw English text. Re-keyed every
  plural entry across all four catalogs to the combined
  `"_singular_::_plural_"` identifier format `translatePlural()`/`IL10N::n()`
  actually look up, matching the convention used by Nextcloud core apps.
  `scripts/check-l10n.js` now also fails if a future plural entry is added
  in the wrong key format.
- Removed dead/orphaned translation keys accumulated across several rounds of
  UI refactors (superseded wording for the note-type delete flow, and ~25
  leftover strings from earlier iterations of the notes/note-type UI with no
  remaining call site) from all four `l10n/de*.{json,js}` catalogs.
- The admin "Global note types" settings page rendered completely unstyled
  (rows collapsed to plain block layout, icons floating disconnected from
  their badges) because `Admin::getForm()` registered only the page's script
  bundle, never its stylesheet. Added the missing `Util::addStyle()` call,
  matching `PageController.php`'s pattern.
- The note-type add/edit modal (personal and admin/global) could only be
  closed via its small × button — pressing Escape or clicking the backdrop
  did nothing, because focus starts in a text input (which `@nextcloud/vue`'s
  modal hotkey ignores) and no modal in the app opted in to
  `close-on-click-outside`. Both dismissal conventions now work.
- `GET /apps/touchpoint/api/admin/note-types/{id}/usage` returned a real,
  system-wide "notes using this type" count for **any** note-type id,
  including a regular user's private (non-global) type — inconsistent with
  the update/delete endpoints' authorization boundary, which both reject a
  non-global id. `NoteTypeService::countGlobalUsage()` now verifies the id
  names a real global default first, same as `updateGlobal()`/`deleteGlobal()`.

### Removed
- Dead, intentionally-unscoped `NoteMapper::searchPublic()` (no caller; public-mode
  search is a deliberately-unsupported product decision). Removing it eliminates a
  latent cross-user-leak footgun.

### Changed
- Numeric (`\d+`) route requirements added to all `/api/notes/{id}` and
  `/api/notes/{noteId}/files/...` routes so literal sub-routes (e.g.
  `/api/notes/search`) can never be captured by a wildcard regardless of router
  declaration order.
- Restructured the repository to standard Nextcloud-app conventions: built
  `js/`/`css/` are no longer committed (built via `make build`), added a
  `Makefile`, `.nextcloudignore`, `README.md`, `CHANGELOG.md` and CI. The app is
  now deployed as a clean copy (see README) rather than a symlinked dev tree, so
  the Nextcloud updater's backup step is no longer broken by `node_modules`.

## [1.1.6] - 2026-06-26

### Fixed
- Fresh **MySQL/MariaDB** installs: `touchpoint_note_files.file_path` shortened to
  `VARCHAR(512)` so the composite unique index stays within InnoDB's 3072-byte
  key limit (a fresh install previously failed `CREATE TABLE`).
- `POST /api/notes` with a missing `contactUid`/`noteTypeId` now returns a clean
  400 instead of an opaque 500.
- Accessibility/styling polish on the in-Contacts add-note form.

## [1.1.5] - 2026-06-26

### Fixed
- The **Touchpoint panel now appears in the Contacts app** again: hook the global
  `BeforeTemplateRenderedEvent` (the Contacts app never dispatches its own OCA
  event on its page), resolve the contact UID from the Contacts 8.x base64 URL,
  and start the observer even when the script loads after `DOMContentLoaded`.

### Added
- Inline **add-note** form in the Contacts-app panel, and the panel now renders
  as the last section of the contact detail view.

## [1.1.4] - 2026-06-26

### Changed
- The contact list mirrors the Contacts app: it loads the **whole address book**
  and filters client-side (was capped at 200), using CSS `content-visibility`
  for smooth scrolling.

## [1.1.3] - 2026-06-26

### Added
- **Embedded Contacts card** shown next to a contact's notes (via the Contacts
  OCA API `mountContactDetails`), when the Contacts app is enabled.

## [1.1.2] - 2026-06-26

### Changed
- Renamed the left-navigation entry to **Notes** (Notizen); the contact list is
  a filter.

## [1.1.1] - 2026-06-26

### Fixed
- **Contact photos render** in the notes app: index the vCard `UID` property and
  read the embedded photo from the stored card, scoped to the user's accessible
  address books.

### Changed
- eGroupware importer: multi-database support, indexes the `UID` property on
  imported cards, falls back to DB-backed `egw_sqlfs.fs_content`, and clearer
  attachment reporting.

## [1.1.0] - 2026

### Added
- Initial version: CRM-style notes on address-book contacts; note types with
  colours/icons; pinning; Markdown bodies; file attachments; sharing with
  users/groups; standalone app page and Contacts-app tab; contacts hover-menu
  entry; admin settings. Compatible with Nextcloud 32–34.

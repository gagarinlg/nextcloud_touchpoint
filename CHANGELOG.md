<!-- SPDX-FileCopyrightText: 2026 Touchpoint Contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/), and the project adheres to
[Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added
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

### Fixed
- In-app search no longer flashes a false "No notes found" empty state during the
  300 ms debounce, and toggling the sort order while a search is active now
  re-runs the search instead of silently re-ordering only the background list.
- Search results are no longer silently capped at 50 with no affordance to reach
  the rest — the search list now paginates like the all-notes list.

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

<!-- SPDX-FileCopyrightText: 2026 Touchpoint Contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Touchpoint — Architecture & orientation

Dense map of the codebase for a maintainer (or a future AI session) coming in
cold. Read [`CLAUDE.md`](../CLAUDE.md) first for the rules of the road, then this
for *how it fits together*, [`docs/API.md`](API.md) for the HTTP surface, and
[`docs/ROADMAP.md`](ROADMAP.md) for what's planned.

> The **Inventory** section below is **auto-generated** from the source tree by
> `scripts/gen-docs.php` (run `make docs`); CI fails if it drifts. The prose is
> hand-maintained — update it in the same commit as architectural changes
> (see the "Keep documentation in sync" rule in `CLAUDE.md`).

## One-paragraph what

A Nextcloud app (`touchpoint`, namespace `OCA\Touchpoint`) that attaches
CRM-style notes to address-book contacts. Surfaces: a standalone app page, a
notes panel injected into the **Contacts** app, a contacts hover-menu entry, and
an admin settings section. PHP 8.1+ backend (NC App Framework) + Vue 3/Pinia
frontend built with Vite. NC 32–34.

## Request flow

```
Frontend:  component → Pinia store → services/*.js (axios, @nextcloud/axios) → /apps/touchpoint/api/*
Backend:   route (appinfo/routes.php) → Controller → Service → QBMapper → Entity → JSONResponse
```

The Contacts-app panel is a **non-Vue** island (`contacts-integration.js`) that
talks to the same backend API directly.

## Backend (`lib/`)

- **Controllers** are thin. Note/NoteType/Settings controllers wrap calls in an
  `ErrorHandler`/`handleNotFound()` pattern that maps service exceptions to
  status codes: `NoteNotFoundException`→404, `NoteForbiddenException`→403,
  `NoteValidationException`→400, generic `\Throwable`→500 (`{ "message": … }`
  body). `ContactController` returns its own 404 for the photo endpoint.
  `AdminNoteTypeController` is admin-only (no `#[NoAdminRequired]` attribute,
  unlike every other controller in the app) and manages the shared global
  default note types (`NoteTypeService::createGlobal()`/`updateGlobal()`/
  `deleteGlobal()`/`findGlobalDefaults()`/`countGlobalUsage()`,
  `NoteTypeMapper::findGlobalById()`) surfaced by `AdminSettings.vue`'s "Global
  note types" section. `countGlobalUsage()` reuses `NoteMapper::countByNoteType()`
  (called with no `$userId` for a system-wide count) rather than a dedicated
  mapper method — the two per-user/global delete paths (`delete()`/
  `deleteGlobal()`) and create/update paths share private helpers
  (`doCreate()`/`doUpdate()`/`doDelete()`) parameterized by scoping, rather than
  duplicating validation/persistence logic.
- **Services** hold all business logic and authorization.
  - `NoteService` — CRUD over owned **+ shared** notes; paging clamp; enriches
    each note with contacts/files/sharing via **batch** queries (avoids N+1);
    write-ACL via `assertCanWrite` (owner, or `can_edit` share, or public mode);
    file attachments resolved against the **owner's** `IRootFolder`. **All
    cascade deletes live here** (`delete()` calls each child mapper's
    `deleteByNoteId`) — there are no DB foreign keys, so never delete a note row
    directly.
  - `NoteTypeService` — user types + global defaults (sentinel `user_id=''`,
    `is_default=true`); validates icon against an allow-list.
  - `SettingsService` — admin `notes_public` (app config) + per-user default
    share targets (user config, JSON); validates principals exist.
- **Db** — `QBMapper` per table + `JsonSerializable` entities. Mappers chunk
  `IN()` queries (≤900 ids). `Note::jsonSerialize()` is **viewer-aware**: a share
  recipient sees only their own `sharing` entry and none of the audit/identity
  fields (`userId`, `createdBy`, `updatedBy`) — owners (and public mode) see all.
- **Search** — `lib/Search/NoteSearchProvider` implements `OCP\Search\IProvider`
  and is registered in `Application::register()` via
  `$context->registerSearchProvider(NoteSearchProvider::class)`. Notes appear in
  the Nextcloud Unified Search bar. Access scoping mirrors
  `NoteService::findAll()` exactly (owned + explicitly-shared; public mode returns
  `[]` in both the HTTP endpoint and the provider). Deep-links use
  `rawurlencode()` (`%20` for spaces in contact UIDs). Sublines are sanitised with
  `mb_substr(UTF-8) → html_entity_decode → strip_tags` to prevent
  double-encoding of stored Markdown entities. Note: `htmlspecialchars()` is
  intentionally NOT applied here — the NC Unified Search Vue component renders
  sublines via text interpolation and handles HTML-encoding itself; a PHP-side
  `htmlspecialchars()` would cause double-encoding visible as literal `&amp;`.
  **Deployment rollback:** if the app fails to boot after upgrade (e.g. due to a
  fatal error in `NoteSearchProvider`), run: `occ app:disable touchpoint`, revert
  to the previous release tarball, then `occ app:enable touchpoint`. This is
  standard NC app recovery.
- **Notification** — `lib/Notification/Notifier` implements `OCP\Notification\INotifier`
  and is registered in `Application::register()` via
  `$context->registerNotifierService(Notifier::class)`. Handles two subjects:
  `note_shared` (dispatched when a note's sharing targets gain a user) and
  `note_mention` (dispatched when note content contains an `@userId` pattern).
  Both render a parsed subject (the actor's display name, plus the note's
  title truncated to 60 characters when available, via a `noteTitle` subject
  parameter — falls back to the title-less phrasing when absent), a rich
  subject with a `user` RichObjectString parameter (`type`/`id`/`name`; there
  is no rich-object type for a free-text note title, so the rich variant omits
  it), an icon (`app-dark.svg` — see the icon-variant note below; this is
  **not** the same variant used by every registration point), and a link
  built via `IURLGenerator` to `#note/{noteId}` in the Touchpoint app page —
  `rawurlencode()`'d, matching the convention used by `NoteSearchProvider`.
  **Icon variant:** the app deliberately uses two icon variants, chosen by the
  background the icon renders against — `app-dark.svg` for light-background
  surfaces (this notification bell dropdown, and `AdminSection`'s admin
  settings sidebar), and the light `app.svg` for tinted/dark surfaces
  (`NoteSearchProvider`'s Unified Search results, and `ContactsMenu\Provider`'s
  hover card). Not drift — keep this split when adding a new integration point.
  Both subject variants share one private helper (`prepareActorSubject()`) to
  avoid the near-duplication two hand-written copies would otherwise carry.
  Before rendering, `prepare()` runs a self-cleanup check whose STRICTNESS
  differs by subject, via `prepareActorSubject()`'s `$requireRecipientAccess`
  flag: for `note_shared` it calls the lightweight `NoteService::isAccessible()`
  for the notification's recipient/note pair and self-cleans when it returns
  `false` (note deleted, or the recipient's access since revoked) — chosen over
  the heavier `NoteService::find()` because `prepare()` runs once per stored
  notification on every bell/mobile fetch and the enriched `Note` `find()`
  would return is never used here; for `note_mention`
  it instead calls the lighter `NoteService::noteExists()` (existence only, no
  recipient scoping) and self-cleans only if the note itself is gone. This
  split exists because `@mention` is deliberately usable on a recipient with
  no prior share (see the Notifications section's policy note below) — using
  the same access-scoped check for both subjects would delete a fresh mention
  notification the first time it renders, before the mentioned user ever sees
  it. Either path throws `OCP\Notification\AlreadyProcessedException` so
  `IManager` garbage-collects the notification instead of leaving a dead-end
  bell entry. `prepare()` throws `OCP\Notification\UnknownNotificationException` (the non-deprecated
  successor to `\InvalidArgumentException` for this purpose, per `INotifier`'s
  `@since 30.0.0` note) for any app other than `touchpoint` or any subject it
  does not recognise.
  `lib/Notification/NotificationService` is the dispatch side: it wraps
  `OCP\Notification\IManager` to build and send notifications shaped
  `app='touchpoint'`, `user=<target>`, `object_type='note'`, `object_id=<noteId>`,
  `subject='note_shared'|'note_mention'` with `actorUid` and `noteTitle`
  subject parameters (consumed by `Notifier::prepare()` above); both public
  dispatch methods (`sendShareNotification()`/`sendMentionNotification()`)
  delegate to one private `dispatch()` helper to avoid near-duplicated
  guard/try/catch/log bodies. It also holds the group-expansion helper
  (`expandShareTargetsToUserIds()`, via `IGroupManager`, capped at 200 distinct
  recipients per note — `MAX_NOTIFY_RECIPIENTS_PER_NOTE` — truncated with a
  logged warning beyond that so sharing with a very large group cannot turn a
  single HTTP request into thousands of sequential synchronous `notify()`
  calls). `NoteService::MAX_SHARE_TARGETS_PER_REQUEST` (100) separately bounds
  the number of distinct entries accepted in one `sharing` payload — checked
  before any per-target `principalExists()` DB/`IGroupManager` lookup runs, so
  a request listing many distinct (real or fabricated) group/user ids cannot
  force many sequential lookups even though no single group is large; an
  over-cap payload is rejected outright (`NoteValidationException` -> HTTP
  400), not silently truncated, since a note's sharing list is a
  security-relevant ACL. It also holds the `@userId` mention scanner (`extractMentionedUserIds()`,
  validated via `IUserManager::userExists()`, capped at 50 distinct **valid**
  mentions per note — `MAX_MENTIONS_PER_NOTE` — and additionally capped at 200
  distinct **candidate** tokens scanned regardless of validity —
  `MAX_CANDIDATES_SCANNED` — so a body packed with thousands of fabricated,
  never-resolving `@token`s cannot turn into thousands of `userExists()`
  lookups against a potentially remote identity backend). It also exposes
  `dismissNoteNotifications(int $noteId)`, called from `NoteService::delete()`,
  which builds a filter notification (`app`+`object_type`/`object_id` only) and
  hands it to `IManager::markProcessed()` (inherited from `IApp`) so any
  outstanding, stored `note_shared`/`note_mention` notification for the
  deleted note is deleted for every recipient rather than left dangling. This
  is deliberately not `IManager::dismissNotification()`, which only invokes
  the optional per-notifier `IDismissableNotifier` hook and never touches
  persisted rows — Touchpoint's `Notifier` does not implement that interface,
  so calling it would be a no-op. Every public method swallows
  and logs its own exceptions — a broken notification dispatch/dismissal must
  never fail the note save/delete that triggered it. `NoteService` is the only
  caller: `create()` notifies every share target (all of them are new on
  create); `update()` snapshots the sharing set before `syncSharing()`
  overwrites it and notifies only the targets newly added in that call (a
  recipient re-saved into the same share list, or one whose `canEdit` flag
  merely changed, is not re-notified). `create()` always scans the new content
  for mentions; `update()` snapshots the note's previous content before
  overwriting it and only rescans/notifies when the submitted content actually
  differs from that snapshot — a resave that resubmits byte-identical content
  (the frontend always includes `content` in its save payload, even for a
  title/pinned/contacts-only edit) does not re-trigger mention notifications.
  `NoteService::notifyMentions()` withholds the note's title from a mentioned
  user who does not yet have read access to the note (checked via the
  lightweight `userHasAccessToNote()`: owner, public mode, or an outstanding
  share — no `enrichNote()` overhead since this runs once per mentioned user,
  up to `MAX_MENTIONS_PER_NOTE` times per save), so a potentially sensitive
  title is never disclosed via the bell/push preview to someone who cannot yet
  open the note; `Notifier` falls back to its title-less wording in that case.
  Query cost: for a non-owner mentioned user when notes are not public,
  `userHasAccessToNote()` issues up to 2 DB queries
  (`getUserGroupIds()` + `findAccessibleNoteIds()`), so a single `create()`/
  `update()` call can add up to `2 * MAX_MENTIONS_PER_NOTE` synchronous
  round-trips in the worst case — bounded by that same cap, not memoized.
  `NoteController::create()`/`update()` both carry
  `#[UserRateLimit(limit: 30, period: 60)]` as defense in depth, bounding the
  note-save rate (and therefore notification fan-out) regardless of content.
  The actor is never notified about their own share/mention (enforced in both
  `NoteService` and `NotificationService`, defense in depth). The frontend
  resolves the `#note/{noteId}` hash in `App.vue` (`applyNoteDeepLink()`,
  wired into the `hashchange` listener and the initial `onMounted`): it
  fetches the note via `GET /api/notes/{id}`, switches to the contacts
  section, selects the note's `contactUid`, sets `notesStore.highlightNoteId`
  so `ContactNotesView` scrolls to and highlights the note once rendered
  (paging further if the note isn't on the first loaded page, respecting
  `prefers-reduced-motion` for the scroll/highlight animation), then
  normalises the URL to `#contact/{uid}` via `history.replaceState`. A note
  that is missing or inaccessible shows a `showError()` toast instead of
  navigating (the failure cause is additionally logged at `console.info` level
  for diagnosis, without being shown to the user). The pagination hunt itself
  (`ContactNotesView.applyHighlight()`) is bounded by `MAX_HIGHLIGHT_LOAD_PAGES`
  (20) `loadMoreContactNotes()` calls, mirroring the caps applied elsewhere in
  the notification pipeline (`MAX_MENTIONS_PER_NOTE`,
  `MAX_NOTIFY_RECIPIENTS_PER_NOTE`) — it stops paging once the target note is
  found, once `contactNotesHasMore` goes `false` (the contact's notes are
  exhausted), or once the cap is hit, whichever comes first. Either give-up
  case (note absent from every loaded page, e.g. deleted after the initial
  fetch but before the hunt reaches its page) surfaces a "could not be found"
  message via the same `role="status"` live region used for "N more notes
  loaded" announcements, and the transient `highlightNoteId` flag is cleared
  either way (via a 2s timer, cancelled on unmount) so a later re-render of
  the same contact never re-triggers the scroll/highlight.
- **Listener** — `LoadContactsTabListener` handles `BeforeTemplateRenderedEvent`,
  and when the rendered app is `contacts` injects the
  `touchpoint-contacts-integration` bundle. (The Contacts app exposes no real
  tab API; this is the workaround — see gotchas.)
- **Dashboard** — `lib/Dashboard/RecentNotesWidget` implements
  `OCP\Dashboard\IAPIWidgetV2` + `IButtonWidget` + `IIconWidget`, registered in
  `Application::register()` via
  `$context->registerDashboardWidget(RecentNotesWidget::class)`. Surfaces up to
  7 (clamped `[1, 30]`) of the current user's most recent notes on the NC
  dashboard home screen, reusing `NoteService::findAll()`'s owned+shared scope
  exactly (same method `NoteSearchProvider`/the all-notes view use) rather than
  re-implementing its own — and re-checks `SettingsService::isNotesPublic()`
  itself (the same guard `NoteSearchProvider::search()` uses) to return an
  empty `WidgetItems` while public mode is on, since a widget renders
  unconditionally on every dashboard load and must not leak every user's notes
  onto every user's home screen. `getItemsV2()` wraps its three fallible calls
  (`NoteService::findAll()`, `NoteTypeService::findAll()`,
  `IContactsManager::search()`) in one try/catch: core's
  `DashboardApiController::getWidgetItemsV2()` has no per-widget try/catch of
  its own, so an uncaught exception here would 500 the entire
  `/api/v2/widget-items` batch response and take down every other app's
  dashboard widget on the page, not just this card — caught and logged instead,
  falling back to an empty, translated "unavailable" message. Follows the same
  two-icon-variant convention as the Notification bullet above: the dark
  `app-dark.svg` for `IIconWidget::getIconUrl()` (the widget-picker's plain
  `<img>`, auto-inverted by core CSS for dark theme) versus the light `app.svg`
  for the per-item `WidgetItem` `iconUrl`/`overlayIconUrl` (rendered inside
  `NcAvatar`, a theme-following surface with no platform-side invert). See
  `docs/API.md`'s "Dashboard integration" section for the full field-by-field
  contract.
- **ContactController.photo()** — the historically tricky path. Reads the
  embedded vCard `PHOTO` straight from the dav `cards` table, **scoped to the
  numeric ids of address books the caller can read**, parses with
  `Sabre\VObject`, and sniffs the MIME from **magic bytes** (never the
  attacker-declared type), serving `inline` + `nosniff`, capped at 5 MiB. External
  URIs are refused (no SSRF). Availability (the URL `index()` advertises) and
  retrievability are kept in lockstep so avatars never 404.

## Frontend (`src/`)

- **Three entry bundles** (see `vite.config.mjs`): `main` (app page, mounts on
  `#content`), `adminSettings` (admin panel), `contacts-integration` (the
  Contacts-app island).
- **Stores (Pinia):** `notes` (all + per-contact lists, offset pagination
  PAGE_SIZE=50, pending file add/remove, sort), `contacts` (roster + selection +
  client-side filter), `noteTypes`, `globalNoteTypes`, `settings`. `noteTypes`
  and `globalNoteTypes` are both thin invocations of
  `stores/createNoteTypeCrudStore.js`, a factory building the shared
  list/loading/error/saving/showModal/editingType state and the
  `saving`-guarded create/update/remove/usage/openModal/closeModal actions
  once; each call site only supplies its own service module (`NoteTypeService.js`
  vs. `AdminNoteTypeService.js`) and, for the global store, the
  `globalNoteTypes` initial-state seed (refreshed via `load()` on mount).
- **Services:** one axios client per resource, all under `/apps/touchpoint/api/…`.
- **Composables:** `composables/useNoteTypeDeletion.js` — the delete-flow
  orchestration shared by `NoteTypesView.vue` and `AdminSettings.vue`
  (proactive usage lookup → confirm dialog → per-row re-entrancy guard →
  delete → success/409 toast → focus recovery), parameterized by which
  note-type store and confirm-dialog wording to use.
- **Components:** `App.vue` shell (nav: Contacts / Note types / Settings),
  `ContactNotesView` (detail + inline add-note + lazy `ContactCard` embedding the
  Contacts app's own card via `window.OCA.Contacts.mountContactDetails`),
  `NoteItem`/`NoteModal`, `NoteType*`, `SettingsView`, `AdminSettings`.
  `AdminSettings.vue` is a standalone island (see "Three entry bundles" above)
  that drives its "Global note types" section through the `globalNoteTypes`
  Pinia store. `AdminNoteTypeModal.vue` and `NoteTypeModal.vue` are both thin
  wrappers (props/emits vs. Pinia store, respectively) around the shared
  `NoteTypeFormModal.vue`, which owns the actual form markup/style/validation
  (`scope: 'personal' | 'global'` picks the right title/DOM ids); both also
  share their icon-option list and themed-default-color helper from
  `utils/noteTypeIcon.js`, and their list-row markup/style via
  `NoteTypeListItem.vue` (used by both `NoteTypesView.vue` and
  `AdminSettings.vue`) — none of these keep a literal duplicate copy.
  `NoteTypeListItem.vue` hides Edit/Delete behind a `manageGlobal` prop: a
  global (`isDefault`) type shown in the personal `NoteTypesView.vue` list
  (where it's visible but not owned by the caller — see `NoteTypeService::findAll()`)
  renders a "Managed by admin" label instead of controls that would 404;
  `AdminSettings.vue` passes `manageGlobal` so every row there keeps working
  Edit/Delete.
- **Contact list** renders the *whole* address book using CSS
  `content-visibility:auto` + `contain-intrinsic-size` for paint — **not** true
  row virtualization (a known scaling debt at ~5–6k contacts).
- **Markdown** (`utils/markdown.js`): `marked` → demote headings (+2 levels) →
  **DOMPurify** (global hook hardens `target="_blank"` links). Never assign
  `innerHTML` outside this pipeline.
- **i18n:** `t('touchpoint', …)` / `n('touchpoint', …)`.
- **Contacts island** (`contacts-integration.js`): a `MutationObserver`
  (rAF-coalesced) injects the notes panel as the **last** child of the contact
  detail wrapper. Resolves the open contact's UID by, in order: a
  `data-contact-uid` attribute, **base64-decoding the Contacts 8.x route**
  (`base64(uid~addressbookUri)`, split on the last `~`), then a legacy
  `contact:UID` hash regex. It **consumes** `window.OCA.Contacts` but does **not**
  expose any `window.OCA.Touchpoint` API (that's a roadmap item).

## Data model & invariants

- Tables are defined as `touchpoint_*` and get the physical `oc_` prefix at
  runtime. Schema is one consolidated migration; **never edit a shipped
  migration** — add a new `VersionXXXXDate*.php`.
- **No DB foreign keys.** Referential integrity is the service layer's job.
- `addressbook_id` is effectively a **dead column** (always 0; the contacts
  manager only exposes a non-numeric address-book key). Don't build auth on it.
- **Entity-default trap:** NC's `Entity` setter marks a field dirty only when the
  value differs from the property default, so explicitly setting `0`/`''` can omit
  the column on INSERT and hit a NOT NULL. The migration sets matching DB defaults
  (notably `note_types.user_id` default `''`) — keep them aligned.
- **Sharing currently grants full edit/delete** (`can_edit`); IDOR-sensitive
  areas are file ids and note types — verify ownership on every mutation.

## Build / test / CI

- **Build:** `npm run build` (Vite) → `js/` + `css/`, which are **gitignored
  build outputs** — rebuild after any `src/` change, then `make deploy`.
- **Tests:** PHPUnit (`tests/Unit`, ~321 tests) via `tests/bootstrap.php`, which
  loads OCP stubs (`tests/stubs.php`) and `Sabre\VObject` from the
  `sabre/vobject` dev dependency (with a `/var/www/html/3rdparty` fallback for
  running inside a real NC). Playwright e2e in `e2e/` (localhost, admin/admin).
- **Static analysis:** `phpstan.neon` level 5, `phpVersion 80100`, excludes
  `lib/Migration`, ignores Sabre/Contacts symbols.
- **Composer:** `php >=8.1`; `config.platform.php=8.1.0` pins the lock to the
  floor so it installs on 8.1→8.4 (sabre transitive deps otherwise demand 8.2).
- **CI:** `ci.yml` (PHP 8.1–8.4 phpstan+phpunit, frontend lint+build, docs-drift
  check), `nightly.yml` and `release.yml` (build + GitHub release + App Store
  publish gated on `APP_PRIVATE_KEY`/`APPSTORE_TOKEN` secrets). Actions pinned to
  Node-24 majors (`checkout@v5`, `setup-node@v5`).

## Inventory (auto-generated)

<!-- AUTOGEN:inventory START — generated by scripts/gen-docs.php; do not edit by hand -->

> Regenerate with `make docs` after any structural change. Do not hand-edit.

**App version:** `1.2.0` (info.xml and package.json in sync).

### HTTP routes (`appinfo/routes.php`)

| Verb | URL | Handler |
|---|---|---|
| `GET` | `/` | `page#index` |
| `GET` | `/api/contacts` | `contact#index` |
| `GET` | `/api/contacts/{uid}/photo` | `contact#photo` |
| `GET` | `/api/notes` | `note#index` |
| `GET` | `/api/notes/search` | `note#search` |
| `GET` | `/api/notes/contact/{contactUid}` | `note#byContact` |
| `GET` | `/api/notes/{id}` | `note#show` |
| `POST` | `/api/notes` | `note#create` |
| `PUT` | `/api/notes/{id}` | `note#update` |
| `DELETE` | `/api/notes/{id}` | `note#destroy` |
| `POST` | `/api/notes/{noteId}/files` | `note#addFile` |
| `DELETE` | `/api/notes/{noteId}/files/{noteFileId}` | `note#removeFile` |
| `GET` | `/api/settings` | `settings#get` |
| `POST` | `/api/settings` | `settings#save` |
| `GET` | `/api/settings/principals` | `settings#searchPrincipals` |
| `GET` | `/api/admin/note-types` | `admin_note_type#index` |
| `GET` | `/api/admin/note-types/{id}/usage` | `admin_note_type#usage` |
| `POST` | `/api/admin/note-types` | `admin_note_type#create` |
| `PUT` | `/api/admin/note-types/{id}` | `admin_note_type#update` |
| `DELETE` | `/api/admin/note-types/{id}` | `admin_note_type#destroy` |
| `GET` | `/api/note-types` | `note_type#index` |
| `GET` | `/api/note-types/{id}` | `note_type#show` |
| `GET` | `/api/note-types/{id}/usage` | `note_type#usage` |
| `POST` | `/api/note-types` | `note_type#create` |
| `PUT` | `/api/note-types/{id}` | `note_type#update` |
| `DELETE` | `/api/note-types/{id}` | `note_type#destroy` |

### Database tables

| Table | Defined in |
|---|---|
| `touchpoint_note_contacts` (physical `oc_touchpoint_note_contacts`) | `Version1000Date20260627000000.php` |
| `touchpoint_note_files` (physical `oc_touchpoint_note_files`) | `Version1000Date20260627000000.php` |
| `touchpoint_note_sharing` (physical `oc_touchpoint_note_sharing`) | `Version1000Date20260627000000.php` |
| `touchpoint_note_types` (physical `oc_touchpoint_note_types`) | `Version1000Date20260627000000.php` |
| `touchpoint_notes` (physical `oc_touchpoint_notes`) | `Version1000Date20260627000000.php` |

### Source file map

| Area | Files |
|---|---|
| Bootstrap | `Application.php` |
| Controllers | `AdminNoteTypeController.php`, `ContactController.php`, `NoteController.php`, `NoteTypeController.php`, `PageController.php`, `SettingsController.php` |
| Controller traits/helpers | `ErrorHandler.php`, `RequiresUser.php` |
| Services | `NoteService.php`, `NoteTypeService.php`, `SettingsService.php` |
| Exceptions | `UnauthenticatedException.php`, `NoteForbiddenException.php`, `NoteNotFoundException.php`, `NoteTypeForbiddenException.php`, `NoteTypeInUseException.php`, `NoteTypeNotFoundException.php`, `NoteValidationException.php` |
| Mappers | `NoteContactMapper.php`, `NoteFileMapper.php`, `NoteMapper.php`, `NoteSharingMapper.php`, `NoteTypeMapper.php` |
| Entities | `Note.php`, `NoteContact.php`, `NoteFile.php`, `NoteSharing.php`, `NoteType.php` |
| Migrations | `Version1000Date20260627000000.php` |
| Listeners | `LoadContactsTabListener.php` |
| Search providers | `NoteSearchProvider.php` |
| Notifiers | `NotificationService.php`, `Notifier.php` |
| ContactsMenu | `Provider.php` |
| Dashboard widgets | `RecentNotesWidget.php` |
| Settings | `Admin.php`, `AdminSection.php` |
| Vue entries | `adminSettings.js`, `contacts-integration.js`, `main.js` |
| Vue components | `AdminNoteTypeModal.vue`, `AdminSettings.vue`, `AllNotesView.vue`, `ConfirmDialog.vue`, `ContactAvatar.vue`, `ContactCard.vue`, `ContactNotesView.vue`, `NoteItem.vue`, `NoteModal.vue`, `NoteTypeBadge.vue`, `NoteTypeFormModal.vue`, `NoteTypeListItem.vue`, `NoteTypeModal.vue`, `NoteTypesView.vue`, `SettingsView.vue` |
| Pinia stores | `contacts.js`, `createNoteTypeCrudStore.js`, `globalNoteTypes.js`, `noteTypes.js`, `notes.js`, `settings.js` |
| API clients | `AdminNoteTypeService.js`, `ContactService.js`, `NoteService.js`, `NoteTypeService.js`, `SettingsService.js` |
| Composables | `useNoteTypeDeletion.js` |
| Frontend utils | `apiError.js`, `color.js`, `markdown.js`, `noteTypeIcon.js`, `scroll.js` |

<!-- AUTOGEN:inventory END -->

## Top gotchas

1. **Rebuild the frontend** — `js/`/`css/` are not committed; a stale bundle is
   the #1 "my change didn't show up."
2. **Delete only through `NoteService`** — no FKs; direct row deletes orphan
   children.
3. **Photo `PHOTO` values** may arrive as a still-serialized `PHOTO;…:` line or a
   `VALUE=uri:…/remote.php/dav/…vcf?photo` reference — the parser handles both;
   don't "simplify" it. Tests need `sabre/vobject` (dev dep), not a live NC.
4. **Contacts-tab integration is fragile** — DOM injection + base64 URL parsing;
   re-verify on every Contacts-app release. The real fix is an upstream
   `registerSection` API (roadmap).
5. **Entity-default / NOT NULL** — keep DB column defaults aligned with entity
   property defaults.
6. **e2e locale** — Playwright runs against a configured locale; UI-string
   assertions must match the active translation.
7. **Dashboard icon variants are opposite-luminance, not interchangeable** —
   `IIconWidget::getIconUrl()` (the widget-picker's `<img>`, auto-inverted by
   core CSS) needs the **dark** glyph (`app-dark.svg`); a per-item
   `WidgetItem`'s `iconUrl`/`overlayIconUrl` (rendered inside `NcAvatar`, no
   platform invert) needs the **light** glyph (`app.svg`). Swapping them is a
   silent visual regression (icon vanishes against its background) with no
   test failure to catch it — check any future dashboard-widget change doesn't
   flip these two.

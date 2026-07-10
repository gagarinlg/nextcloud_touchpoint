<!-- SPDX-FileCopyrightText: 2026 Touchpoint Contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Touchpoint — Developer / API documentation

This documents the HTTP endpoints Touchpoint exposes today, their request and
response shapes, and the integration surfaces that are **planned but not yet
implemented**.

> ⚠️ **Stability.** The `/apps/touchpoint/api/...` routes below are the app's
> **internal** API, consumed by the bundled Vue frontend. They are
> session-authenticated and CSRF-protected, **not** a versioned public contract —
> they can change between releases. A stable, documented **public** API (OCS/REST,
> dispatched events, a JS embedding API) is on the roadmap; see
> [Planned public API surface](#planned-public-api-surface) and
> [`ROADMAP.md`](ROADMAP.md). **When you change anything here, update this file in
> the same commit** (see the "Keep documentation in sync" rule in `CLAUDE.md`).

---

## Conventions

- **Base path:** `/apps/touchpoint/api`
- **Auth:** the logged-in Nextcloud session. All endpoints are
  `#[NoAdminRequired]` (any signed-in user) and scoped to that user — see
  [Authorization](#authorization).
- **CSRF:** required on every request except `contact#photo` (which is
  `#[NoCSRFRequired]` so `<img src>` can load it). Send the
  `requesttoken` header; `@nextcloud/axios` does this automatically.
- **Encoding:** request bodies are JSON or form-encoded; responses are JSON.
- **Path parameters** containing a contact UID (`{uid}`, `{contactUid}`) **must be
  `encodeURIComponent`-ed** — UIDs can contain `/`, `#`, `@`.
- **Errors:** all controller errors funnel through `ErrorHandler` and return
  `{ "message": "<reason>" }` with an appropriate status (`400` invalid input,
  `403` not permitted, `404` not found/owned, `500` unexpected). See
  [Error handling](#error-handling).

---

## Endpoints

### Contacts

| Method | Path | Description |
|---|---|---|
| GET | `/api/contacts?term=<q>` | Search readable address books (own + shared + group). Empty `term` lists up to the server cap, sorted by display name. Returns [`Contact[]`](#contact). |
| GET | `/api/contacts/{uid}/photo` | The contact's embedded vCard PHOTO bytes, sniffed to a safe raster MIME (`DataDisplayResponse`, `inline`, `nosniff`, cached 1 h). `404` if none / not readable. `#[NoCSRFRequired]`. |

The photo endpoint only reads vCards from address books whose key `is_numeric()`
(the dav `cards` table); the system address book (key `system`) is not served.
`index()` advertises a `photo` URL only when the endpoint can actually serve it.

### Notes

| Method | Path | Body / query | Description |
|---|---|---|---|
| GET | `/api/notes` | `sort` (`newest`\|`oldest`), `limit`, `offset` | All notes accessible to the user (owned + shared). Returns [`Note[]`](#note). |
| GET | `/api/notes/search` | `q` (string, max 500 chars), `sort`, `limit` (1–200, default 50), `offset` | Case-insensitive full-text search across note title and content. Returns [`Note[]`](#note) on 200; `400 { "message": … }` if `q` exceeds 500 characters. Blank or whitespace-only `q` returns 200 `[]`. Public mode returns 200 `[]`. Rate-limited to 30 requests per 60 seconds per user (`#[UserRateLimit]`). |
| GET | `/api/notes/{id}` | `id` numeric (`\d+`) | A single accessible note. The `\d+` requirement keeps `/api/notes/search` (and other literal sub-routes) from ever being captured by this wildcard. Rate-limited to 60 requests per 60 seconds per user (`#[UserRateLimit]`), bounding a scripted sweep across sequential note IDs (access itself is sound — nonexistent and inaccessible IDs both 404 uniformly — but this is also the endpoint the `note_shared`/`note_mention` notification deep-link fetches). |
| POST | `/api/notes` | see [create body](#note-create--update-body) | Create a note. |
| PUT | `/api/notes/{id}` | see [update body](#note-create--update-body) | Update a note (only supplied fields change; `null` = leave as-is). |
| DELETE | `/api/notes/{id}` | — | Delete a note and its child rows (files, contacts, sharing). |
| GET | `/api/notes/contact/{contactUid}` | `sort`, `limit`, `offset` | Notes linked to one contact. |
| POST | `/api/notes/{noteId}/files` | `fileId` (int) **or** `filePath` (string) | Attach an existing Nextcloud file. |
| DELETE | `/api/notes/{noteId}/files/{noteFileId}` | — | Detach a file attachment. |

#### Note create / update body

| Field | Type | Create | Update | Notes |
|---|---|---|---|---|
| `contactUid` | string | optional (`''`) | — | Primary linked contact; omit for an unlinked note. |
| `noteTypeId` | int | **required** (non-null, >0) | optional | Must be a note type visible to the user. |
| `title` | string | optional (`''`) | optional | |
| `content` | string\|null | optional | optional | Markdown; rendered client-side via `marked` + DOMPurify. |
| `addressbookId` | int | optional (`0`) | — | Currently stored but unused for auth/lookup. |
| `isPinned` | bool | optional (`false`) | optional | |
| `contactUids` | string[] | optional (`[]`) | optional | Additional linked contacts (junction rows). |
| `sharing` | array\|null | optional | optional | ACL entries: `{ type: 'user'\|'group', id: string, canEdit?: bool }`. Capped at 100 distinct entries per request (`NoteService::MAX_SHARE_TARGETS_PER_REQUEST`) — a payload listing more is rejected with HTTP 400 before any principal is looked up. Note: this is the **input** shape; the [`Note`](#note) response's `sharing` array uses the entity's own field names (`sharedWithType`/`sharedWithId`) — see below. |

Both return the resulting [`Note`](#note).

### Note types

| Method | Path | Body | Description |
|---|---|---|---|
| GET | `/api/note-types` | — | The user's note types (+ defaults). Returns [`NoteType[]`](#notetype). |
| GET | `/api/note-types/{id}` | — | One note type. |
| GET | `/api/note-types/{id}/usage` | — | `{ count }` of notes using this type (for delete confirmation). |
| POST | `/api/note-types` | `name` (req), `icon` (`icon-category-office`), `color` (`#0082c9`) | Create. |
| PUT | `/api/note-types/{id}` | `name?`, `icon?`, `color?` | Update (supplied fields only). |
| DELETE | `/api/note-types/{id}` | — | Delete a user-defined type. |

### Settings

| Method | Path | Body | Description |
|---|---|---|---|
| GET | `/api/settings` | — | The user's Touchpoint settings. |
| POST | `/api/settings` | `notesPublic?` (bool), `shareTargets?` (array) | Save settings. |
| GET | `/api/settings/principals?q=<q>` | — | Search users/groups to share with (autocomplete). |

---

## Data shapes

### Note

```jsonc
{
  "id": 42,
  "contactUid": "abc-123",
  "addressbookId": 0,
  "noteTypeId": 3,
  "title": "Kickoff call",
  "content": "## Notes\n…",            // Markdown, may be null
  "isPinned": false,
  "createdAt": "2026-06-27T10:00:00+00:00",  // ISO-8601 (ATOM)
  "updatedAt": "2026-06-27T10:05:00+00:00",
  "contacts": [ { "id": 1, "noteId": 42, "contactUid": "abc-123", "addressbookId": 0 } ],
  "files":    [ { "id": 7, "noteId": 42, "fileId": 9001, "filePath": "/Documents/x.pdf" } ],
  "sharing":  [ { "id": 5, "noteId": 42, "sharedWithType": "user", "sharedWithId": "bob", "canEdit": true } ],

  // Owner-only (or in public mode) — withheld from share recipients:
  "userId": "alice",
  "createdBy": "alice",
  "updatedBy": "alice"
}
```

A share recipient sees only **their own** `sharing` entry and none of the
audit/identity fields (`userId`, `createdBy`, `updatedBy`).

### NoteType

```jsonc
{ "id": 3, "name": "Call", "icon": "icon-phone", "color": "#0082c9",
  "userId": "alice", "isDefault": false }
```

### Contact

```jsonc
{
  "uid": "abc-123",
  "name": "Jane Doe",
  "email": "jane@example.com",   // flattened first value ('' if none)
  "phone": "+49 …",              // first TEL
  "org": "Example GmbH",         // ORG
  "photo": "/apps/touchpoint/api/contacts/abc-123/photo",  // '' if none servable
  "addressbookKey": "contacts-1",
  "isUser": false                // true = a real Nextcloud account (system book)
}
```

---

## Authorization

- Notes and note types are scoped by `user_id`. Every mutation verifies the
  caller owns — or is explicitly shared on — the row before acting; share grants
  currently allow full edit/delete.
- The contact photo lookup is constrained to the numeric ids of the caller's
  readable address books, so a UID in a book the caller cannot access is not
  served.
- **Never bypass the service layer for deletes** — there are no DB-level foreign
  keys; `NoteService::delete()` cleans the `touchpoint_note_*` child rows.

## Error handling

Controllers wrap calls in `ErrorHandler`/`handleNotFound()`, which map service
exceptions to status codes and a `{ "message": … }` body:

| Status | When |
|---|---|
| `400` | Invalid/missing input (e.g. absent/invalid `noteTypeId`; `q` exceeding 500 characters on `/api/notes/search`). |
| `403` | Authenticated but not permitted on the resource. |
| `404` | Resource missing or not owned/accessible by the caller. |
| `500` | Unexpected failure (logged; opaque message to the client). |

---

## Search integration

Notes appear in the **Nextcloud Unified Search** bar (top-right magnifier) via
`OCA\Touchpoint\Search\NoteSearchProvider` (`OCP\Search\IProvider`). Each result
deep-links to the note's contact view (`#contact/<rawurlencode(uid)>`), or to the
app root when the note has no linked contact.

Access scoping mirrors the authenticated session: only notes owned by or
explicitly shared with the searching user are returned. **Public mode suppresses
all results** (both via the HTTP endpoint and via the Unified Search provider) to
avoid surfacing other users' notes without per-user ownership context. There is
no public-mode keyword-search code path on the backend: the previously-unused,
intentionally-unscoped `NoteMapper::searchPublic()` was removed (it had no caller
and was a latent cross-user-leak footgun). If public-mode search is ever required
as a product feature, it must be (re)introduced with the access decision
co-located in the query method, not guarded only by a distant service-layer
early-return.

The provider ranks itself via `getOrder()`: it returns `-1` (float to the top)
on any `touchpoint.*` route — so notes lead the Unified Search results while the
user is inside the Touchpoint app — and the neutral default `30` everywhere else.

The `/api/notes/search` HTTP endpoint is the backing store for both the in-app
search box and the Unified Search provider.

**In-app search-box UX** (`AllNotesView.vue`), layered on top of the endpoint
above:

- Searches are debounced 300 ms and only fire for a trimmed term of **at least 2
  characters** (shorter input shows a "keep typing" hint rather than firing the
  most-expensive `%a%` scans and burning the rate-limit budget).
- The loading indicator is shown for the whole debounce window, so the
  "No notes found" empty state never flashes before the first response.
- Results paginate with a **Load more** button (`limit`/`offset`, `PAGE_SIZE`
  = 50), mirroring the all-notes list; the screen-reader live region announces
  "Showing first N notes" while more pages remain.
- Errors are discriminated by status: `429` shows a "too many searches, wait a
  moment" message (no auto-retry), `400` (over 500 chars) an explicit length
  message, anything else the generic retryable error.
- In **public mode** the box renders an explicit "Search is unavailable while
  notes are shared publicly" empty state and fires no request (the endpoint would
  return `[]` anyway), instead of a misleading generic "No notes found".

---

## Notifications

Server-side only — **no new HTTP routes**. Touchpoint dispatches native
Nextcloud notifications (the bell icon / desktop & mobile push, via
`OCP\Notification\IManager`) for two subjects:

| Subject | Dispatched when | Recipients |
|---|---|---|
| `note_shared` | A note's sharing targets gain a user (`NoteService::create()`: every share target; `NoteService::update()`: only targets newly added compared to the note's previous sharing set). | The newly-added target user(s); group targets are expanded to their current members via `IGroupManager`, capped at 200 distinct recipients per note (`MAX_NOTIFY_RECIPIENTS_PER_NOTE`) — truncated with a logged warning beyond that. The `sharing` payload itself is additionally capped at 100 distinct targets per request (`NoteService::MAX_SHARE_TARGETS_PER_REQUEST`, HTTP 400 beyond that), checked before any per-target principal lookup runs — bounding both the number of `IGroupManager`/DB round-trips a single request can trigger and the notification fan-out itself, not just fan-out from one oversized group. |
| `note_mention` | Note content, on create, or on update **only when the content actually changed** from the note's previously-stored value (a resubmission of byte-identical content does not re-scan/re-notify), contains an `@userId` token that resolves to a real user via `IUserManager::userExists()`. Capped at 50 distinct valid mentions per note (`MAX_MENTIONS_PER_NOTE`); the scan also stops after inspecting 200 distinct candidate tokens (`MAX_CANDIDATES_SCANNED`), valid or not, to bound `userExists()` calls against a pathological body full of fabricated `@token`s. | Each mentioned user. |

The acting user is never notified about their own share/mention. `note#create`
and `note#update` are both rate-limited (`#[UserRateLimit(limit: 30, period: 60)]`)
so the note-save rate — and therefore notification fan-out — is bounded per
user regardless of note content.

**Policy note (deliberate, not a gap):** `@mention` notifications are NOT
gated on the mentioned user having any prior relationship (share/ownership) to
the note. This is by design — the feature's purpose is to loop in a colleague
who does not yet have access, mirroring `@mention`-to-invite patterns in
comparable Nextcloud apps; a share-only gate would make it impossible to
mention someone into a note for the first time. The combination of the
per-note valid-mention cap (50), the per-note candidate-scan cap (200), and
the per-user rate limit above bounds the abuse surface (spam/enumeration-via-notification)
without removing the capability. Revisit only as an explicit product decision,
not as a silent behavior change.

Two corollaries of this policy, both implemented in `NoteService`/`Notifier`:

- **Self-cleanup is existence-only for `note_mention`.** `Notifier::prepare()`
  self-cleans (`AlreadyProcessedException`) a `note_shared` notification when
  the recipient's access is gone (share revoked or note deleted), but for
  `note_mention` it only self-cleans when the note itself no longer exists.
  Checking full recipient access for `note_mention` would delete the
  notification the very first time it renders for exactly the intended
  "mention someone with no access yet" case, before they ever see it.
- **The note title is withheld from a mentioned user with no access yet.**
  `NoteService::notifyMentions()` only passes the note's title into the
  `note_mention` dispatch when the mentioned user already has read access
  (owner, public mode, or an outstanding share); otherwise the notification
  uses `Notifier`'s title-less fallback wording. This avoids disclosing
  potentially sensitive note content via a push/bell preview to a user who
  cannot yet open the note to see it themselves — once the mentioning author
  (or someone else) actually grants that user a share, subsequent mentions
  include the title as normal.
- **Title visibility is re-checked on every render, not just at dispatch
  time.** The `noteTitle` subject parameter persisted with a `note_mention`
  notification only reflects the recipient's access AT DISPATCH TIME. If a
  mentioned user DID have access when mentioned (so the title was persisted),
  and that access is later revoked (share removed, public mode turned off)
  before they read the still-outstanding notification, `Notifier::prepare()`
  re-checks the recipient's CURRENT access (via the same lightweight
  `NoteService::isAccessible()` used for `note_shared`) on every render and
  falls back to the title-less wording if access is no longer present — even
  though the notification itself is not self-cleaned (existence-only
  self-cleanup for `note_mention` still applies; only the title-disclosure
  decision is access-gated).

Dispatch is best-effort:
`OCA\Touchpoint\Notification\NotificationService` catches and logs any failure
(bad group lookup, `IManager` exception, etc.) so a notification problem can
never fail the note save that triggered it. Deleting a note
(`NoteService::delete()`) dismisses any outstanding `note_shared`/`note_mention`
notification that still references it, via
`NotificationService::dismissNoteNotifications()`.

`OCA\Touchpoint\Notification\Notifier` (`OCP\Notification\INotifier`, registered
in `Application::register()`) renders both subjects into a parsed subject (the
acting user's display name, plus the note's title when available — truncated
to 60 characters — so several same-day notifications are distinguishable at a
glance), a rich subject (`user` RichObjectString parameter), an icon
(`app-dark.svg`), and a link to `#note/{noteId}` in the
Touchpoint app page (`rawurlencode()`'d, same convention as the search
deep-links above).

**Icon variant convention:** the app uses two icon variants across its four
registration points, chosen by the background the icon renders against —
`app-dark.svg` for surfaces with a light background (the notification bell
dropdown here, and the admin settings sidebar in `Settings\AdminSection`), and
`app.svg` (the light variant) for surfaces with a tinted/dark background
(Unified Search results in `NoteSearchProvider`, and the contacts hover card
in `ContactsMenu\Provider`). This is a deliberate per-surface choice, not
drift — do not "fix" all four to the same variant.

Before rendering, `Notifier::prepare()` re-checks that the
notification is still relevant: for `note_shared` it verifies the recipient
can still access the note (via the lightweight `NoteService::isAccessible()`,
which mirrors `find()`'s access resolution without its enrichment overhead —
`prepare()` runs once per stored notification on every bell/mobile fetch, and
the enriched `Note` `find()` would return is never used here); for
`note_mention` it
only verifies the note still exists (via `NoteService::noteExists()`), since
`@mention` is deliberately usable before the recipient has any access to the
note (see the Policy note above). Either way, if the check fails it throws
`OCP\Notification\AlreadyProcessedException`
so `IManager` garbage-collects the now-dead-end notification instead of
leaving it in the recipient's bell/mobile list. Separately from that
self-cleanup check, `note_mention` also re-evaluates `NoteService::isAccessible()`
on every render solely to decide whether the persisted `noteTitle` may be
shown (see the corollary above) — a `note_mention` notification whose
recipient's access was revoked after dispatch keeps rendering (no
self-clean) but falls back to the title-less wording. An unknown app or subject
throws `OCP\Notification\UnknownNotificationException` (the non-deprecated
successor to throwing `\InvalidArgumentException` directly). The frontend
(`src/App.vue`) resolves the `#note/{noteId}` hash on load/`hashchange`: it
fetches the note, switches to its contact, and highlights/scrolls to it in
`ContactNotesView`, then normalises the URL to `#contact/{uid}`. A missing or
inaccessible note shows a toast instead of navigating.

Task **due/overdue** notifications are deferred until the Tasks integration
(`ROADMAP.md` item 2) ships.

---

## Dashboard integration

Server-side only — **no new HTTP routes**. `OCA\Touchpoint\Dashboard\RecentNotesWidget`
implements `OCP\Dashboard\IAPIWidgetV2`, `OCP\Dashboard\IButtonWidget`, and
`OCP\Dashboard\IIconWidget` (the last is required for Nextcloud core to actually
call `getIconUrl()` for the widget's own icon — `IAPIWidgetV2`/`IButtonWidget`
alone do not trigger that), and is registered in `Application::register()` via
`$context->registerDashboardWidget(RecentNotesWidget::class)`. It surfaces the
current user's up to 7 most recent notes (**owned + shared**, same access scope
as `NoteService::findAll()` — the same method `NoteSearchProvider`/the all-notes
view use) on the Nextcloud dashboard home screen.

**Public-notes admin setting:** when the admin's "public notes" setting
(`SettingsService::isNotesPublic()`) is enabled, `NoteService::findAll()`
switches to an unscoped system-wide query that ignores `$userId` entirely (see
`NoteService::findAll()`'s own branch). The widget does not inherit that
behaviour onto the dashboard: `getItemsV2()` checks `isNotesPublic()` itself
(the same guard `NoteSearchProvider::search()` uses) and returns an empty
`WidgetItems` while public mode is on, rather than surfacing every user's notes
on every user's dashboard. The empty-content message in this case
(`"Recent notes are hidden while public notes mode is enabled"`) is
deliberately distinct from the genuinely-empty-list message ("No recent
notes") so a user who actually has recent notes can tell this is an
intentional admin-configuration effect rather than a bug.

| Widget aspect | Value |
|---|---|
| `getId()` | `touchpoint-recent-notes` |
| `getTitle()` | Translated `"Recent notes"` |
| `getIconUrl()` | `app-dark.svg` (dark/black variant, per `OCP\Dashboard\IIconWidget`'s documented contract — the icon "should be colored black or not have a color" and is "inverted automatically... in dark mode" by the platform. This is rendered by Nextcloud core's "Manage widgets" picker as a plain `<img>`, not inside `NcAvatar`, with `filter: var(--background-invert-if-dark)` applied by core's own CSS; matches every first-party `IIconWidget` implementation — `UserStatusWidget`, `FavoriteWidget`, `TasksWidget`, `MailWidget`, `ActivityWidget`, `TalkWidget` — and `Notifier`/`Settings\AdminSection`'s bell dropdown/sidebar entries, all of which use the dark variant for the same reason). Only actually used as the widget's icon because the class also implements `IIconWidget`. Distinct from the per-item `iconUrl` below, which uses the light variant instead — see that bullet. |
| `getUrl()` | The Touchpoint app page (`touchpoint.page.index`) |
| `getItemsV2()` | Up to `$limit` (default 7, clamped to `[1, 30]` regardless of what the caller requests) most recent notes, pinned notes first, then newest first. Returns an empty `WidgetItems` (with the "No recent notes" empty-content message) without querying note types when there are zero notes, and returns an empty `WidgetItems` with a distinct message while "public notes" mode is enabled (see above). When the item list is non-empty, the empty-content message is `''` (matching the first-party `FavoriteWidget`/`MailWidget` convention of only setting it for a genuinely empty list). |
| `getWidgetButtons()` | One `WidgetButton::TYPE_MORE` button, `"Show all"`, linking to the app page. **Known limitation:** the link does not carry the widget's own sort/pin-priority context (newest-first, pinned-first) — the all-notes view it lands on keeps whatever sort/filter state it last remembered, which may not match what the widget just showed. `src/stores/notes.js`'s `sort` is in-memory Pinia state with no URL query param or hash to pass through; adding one is tracked as a possible follow-up, not implemented here. |

Each `WidgetItem`:

- **title** — the note's title (or the translated `"Untitled"` fallback),
  truncated to 60 characters with a trailing `…`.
- **subtitle** — the linked contact's display name and the note type's label,
  joined with `" · "` when both are present; either half is silently omitted
  (not an error) when the note has no linked contact, the contact cannot be
  resolved (deleted/inaccessible), or the note type is not visible to the
  *viewing* user — which covers both an actually-deleted type and a shared
  note whose owner used one of their own non-default (non-global) note
  types, since `NoteTypeService`'s read scope never resolves another user's
  custom types (see `NoteTypeMapper::readScope()`). The assembled subtitle is
  then truncated to 60 characters with a trailing `…` (same fixed-width-card
  constraint as the title — the contact display name is uncapped
  address-book data). A dashboard widget must never fail to render because
  one referenced contact/type vanished or is invisible to the viewer. The
  contact name is resolved via `OCP\Contacts\IManager::search()` filtered on
  the `UID` property with `strict_search => true` (exact match) plus a
  defense-in-depth check that the returned entry's UID equals the requested
  one — a narrower, single-field lookup than `ContactController`'s broader
  `FN`/`EMAIL`/`TEL`/`ORG` search endpoint, which does not use
  `strict_search`. Note-type labels
  for all of the viewing user's notes are resolved in a single batched
  `NoteTypeService::findAll()` call per widget request (not one lookup per
  note) — and that call is skipped entirely when there are zero notes to
  render.
- **link** — a deep link straight to the note itself (`#note/<id>`), the same
  convention `note_shared`/`note_mention` notifications use (see
  `Notifier::buildIconUrl()`'s companion deep-link and `App.vue`'s
  `applyNoteDeepLink()`). Opening it fetches the note, switches to its
  contact, and scrolls to/highlights/focuses that specific note, rather than
  landing on the contact's entire (potentially long) note history with no
  indication of which note prompted the click.
- **iconUrl** — the app icon, `app.svg` (light/white variant — this one *is*
  rendered inside the dashboard client's `NcAvatar`
  (`ApiDashboardWidgetItem.vue`), whose background follows the
  theme-following `--color-main-background` CSS variable rather than being
  fixed-light, with no platform-side invert filter applied, so it needs the
  same light variant as `NoteSearchProvider`/`ContactsMenu\Provider`'s
  tinted/dark surfaces — **not** the same value as `getIconUrl()` above,
  which is a different render context needing the dark variant).
- **overlayIconUrl** — a small pin badge (`pin-badge-light.svg`, the same
  light/white-fill variant as the main icon, since it renders on the same
  theme-following surface) when the note is pinned, empty otherwise.

Pinned notes are moved ahead of unpinned notes in the returned list
(preserving newest-first order within each group), so a pinned note is never
pushed out of the widget's limited slot count by newer, unpinned notes.

`getItemsV2($userId, ...)` defends against a missing session (no logged-in
user) or a session/`$userId` mismatch the same way `NoteSearchProvider::search()`
does (returns an empty `WidgetItems` rather than another user's notes) even
though the Dashboard API only ever invokes it for the current session's own
user.

**Error boundary:** `OCA\Dashboard\Controller\DashboardApiController::getWidgetItemsV2()`
(Nextcloud core, internal — not an OCP public-API class) loops over every installed app's registered widget in a
single request with no per-widget try/catch of its own — an uncaught
exception from any one widget's `getItemsV2()` would 500 the entire
`/api/v2/widget-items` batch response, breaking every other app's dashboard
widget on the page, not just this one. `getItemsV2()` therefore wraps its
`NoteService::findAll()`, `NoteTypeService::findAll()`, and per-note
`IContactsManager::search()` calls in a `try`/`catch (\Throwable)`, logs via
`Psr\Log\LoggerInterface::warning()`, and degrades to an empty `WidgetItems`
with a distinct `"Recent notes are unavailable right now"` message on failure.

---

## Planned public API surface

These are **not implemented yet** — they are the intended *stable, documented*
ways for other apps to integrate **with** Touchpoint. Design and status live in
[`ROADMAP.md` → "Public API surface"](ROADMAP.md). When any of these ships,
document it in this file (concrete routes/event classes/JS entry points) and move
it out of "planned."

- **Lifecycle events** — `OCA\Touchpoint\Event\*` dispatched via
  `IEventDispatcher` on note created/updated/deleted/shared/pinned (foundational;
  consumed by Activity, Notifications, Webhooks, Flow, and third-party listeners).
- **OCS / public REST API** — versioned `/ocs/v2.php/apps/touchpoint/api/v1/…`
  superseding the internal routes above for external/automation use.
- **Webhooks** — outbound HTTP on the lifecycle events via `webhook_listeners`.
- **Reference provider + Smart Picker** — link/embed a note from Text/Talk/Deck.
- **Flow / WorkflowEngine** operations on the lifecycle events.
- **Capabilities** — `OCP\Capabilities\ICapability` so clients can feature-detect.
- **JS embedding API** — a documented `window.OCA.Touchpoint` (e.g.
  `mountNotesFor(el, contactUid)`), mirroring how we embed `window.OCA.Contacts`.

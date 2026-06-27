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
| GET | `/api/notes/{id}` | — | A single accessible note. |
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
| `sharing` | array\|null | optional | optional | ACL entries: `{ sharedWithType, sharedWithId, canEdit }`. |

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
| `400` | Invalid/missing input (e.g. absent/invalid `noteTypeId`). |
| `403` | Authenticated but not permitted on the resource. |
| `404` | Resource missing or not owned/accessible by the caller. |
| `500` | Unexpected failure (logged; opaque message to the client). |

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
- **Unified Search provider** — `OCP\Search\IProvider` for notes.
- **Flow / WorkflowEngine** operations on the lifecycle events.
- **Capabilities** — `OCP\Capabilities\ICapability` so clients can feature-detect.
- **JS embedding API** — a documented `window.OCA.Touchpoint` (e.g.
  `mountNotesFor(el, contactUid)`), mirroring how we embed `window.OCA.Contacts`.

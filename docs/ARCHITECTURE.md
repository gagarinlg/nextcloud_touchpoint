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
- **Listener** — `LoadContactsTabListener` handles `BeforeTemplateRenderedEvent`,
  and when the rendered app is `contacts` injects the
  `touchpoint-contacts-integration` bundle. (The Contacts app exposes no real
  tab API; this is the workaround — see gotchas.)
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
  client-side filter), `noteTypes`, `settings`.
- **Services:** one axios client per resource, all under `/apps/touchpoint/api/…`.
- **Components:** `App.vue` shell (nav: Contacts / Note types / Settings),
  `ContactNotesView` (detail + inline add-note + lazy `ContactCard` embedding the
  Contacts app's own card via `window.OCA.Contacts.mountContactDetails`),
  `NoteItem`/`NoteModal`, `NoteType*`, `SettingsView`, `AdminSettings`.
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
| `GET` | `/api/notes/{id}` | `note#show` |
| `POST` | `/api/notes` | `note#create` |
| `PUT` | `/api/notes/{id}` | `note#update` |
| `DELETE` | `/api/notes/{id}` | `note#destroy` |
| `GET` | `/api/notes/contact/{contactUid}` | `note#byContact` |
| `POST` | `/api/notes/{noteId}/files` | `note#addFile` |
| `DELETE` | `/api/notes/{noteId}/files/{noteFileId}` | `note#removeFile` |
| `GET` | `/api/settings` | `settings#get` |
| `POST` | `/api/settings` | `settings#save` |
| `GET` | `/api/settings/principals` | `settings#searchPrincipals` |
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
| Controllers | `ContactController.php`, `NoteController.php`, `NoteTypeController.php`, `PageController.php`, `SettingsController.php` |
| Controller traits/helpers | `ErrorHandler.php`, `RequiresUser.php` |
| Services | `NoteService.php`, `NoteTypeService.php`, `SettingsService.php` |
| Exceptions | `UnauthenticatedException.php`, `NoteForbiddenException.php`, `NoteNotFoundException.php`, `NoteTypeForbiddenException.php`, `NoteTypeInUseException.php`, `NoteTypeNotFoundException.php`, `NoteValidationException.php` |
| Mappers | `NoteContactMapper.php`, `NoteFileMapper.php`, `NoteMapper.php`, `NoteSharingMapper.php`, `NoteTypeMapper.php` |
| Entities | `Note.php`, `NoteContact.php`, `NoteFile.php`, `NoteSharing.php`, `NoteType.php` |
| Migrations | `Version1000Date20260627000000.php` |
| Listeners | `LoadContactsTabListener.php` |
| ContactsMenu | `Provider.php` |
| Settings | `Admin.php`, `AdminSection.php` |
| Vue entries | `adminSettings.js`, `contacts-integration.js`, `main.js` |
| Vue components | `AdminSettings.vue`, `AllNotesView.vue`, `ConfirmDialog.vue`, `ContactAvatar.vue`, `ContactCard.vue`, `ContactNotesView.vue`, `NoteItem.vue`, `NoteModal.vue`, `NoteTypeBadge.vue`, `NoteTypeModal.vue`, `NoteTypesView.vue`, `SettingsView.vue` |
| Pinia stores | `contacts.js`, `noteTypes.js`, `notes.js`, `settings.js` |
| API clients | `ContactService.js`, `NoteService.js`, `NoteTypeService.js`, `SettingsService.js` |
| Frontend utils | `color.js`, `markdown.js`, `noteTypeIcon.js`, `scroll.js` |

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

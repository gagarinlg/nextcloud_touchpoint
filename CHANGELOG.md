<!-- SPDX-FileCopyrightText: 2026 Touchpoint Contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/), and the project adheres to
[Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added
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

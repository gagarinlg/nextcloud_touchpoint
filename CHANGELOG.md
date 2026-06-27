<!-- SPDX-FileCopyrightText: 2026 CRM Notes Contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/), and the project adheres to
[Semantic Versioning](https://semver.org/).

## [Unreleased]

### Changed
- Restructured the repository to standard Nextcloud-app conventions: built
  `js/`/`css/` are no longer committed (built via `make build`), added a
  `Makefile`, `.nextcloudignore`, `README.md`, `CHANGELOG.md` and CI. The app is
  now deployed as a clean copy (see README) rather than a symlinked dev tree, so
  the Nextcloud updater's backup step is no longer broken by `node_modules`.

## [1.1.6] - 2026-06-26

### Fixed
- Fresh **MySQL/MariaDB** installs: `crm_note_files.file_path` shortened to
  `VARCHAR(512)` so the composite unique index stays within InnoDB's 3072-byte
  key limit (a fresh install previously failed `CREATE TABLE`).
- `POST /api/notes` with a missing `contactUid`/`noteTypeId` now returns a clean
  400 instead of an opaque 500.
- Accessibility/styling polish on the in-Contacts add-note form.

## [1.1.5] - 2026-06-26

### Fixed
- The **CRM Notes panel now appears in the Contacts app** again: hook the global
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

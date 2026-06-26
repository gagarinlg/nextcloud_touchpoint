<!-- SPDX-FileCopyrightText: 2026 CRM Notes Contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# CRM Notes — Feature Roadmap & Ideas

Ideas for evolving **CRM Notes** (`crm_notes`) from a solid "notes attached to
contacts" tool into a genuine, Nextcloud-native lightweight CRM.

**Two guiding themes for what's missing today:**
1. **Find** — right now you can only *navigate by contact*; there's no way to
   search note content, and notes are invisible to the rest of Nextcloud.
2. **Act** — notes are inert records; there are no tasks, due dates, reminders,
   or notifications, so the "Task"/"Call" types are just colored labels.

Effort key: **S** = ~1 day · **M** = a few days · **L** = a week+.
Status reflects the codebase as of v1.1.x (verified absent unless noted).

---

## P0 — Highest impact (turns it into a CRM)

### 1. Full-text note search  ·  **M**
Search note **title + content** (not just the contact list). With tens of
thousands of notes, this is the single biggest gap.
- Backend: `NoteService::search(term)` → `NoteMapper` query with `LIKE`/`ILIKE`
  on `title`/`content`, scoped to owned + shared notes (reuse `findAccessiblePage`
  access logic), paginated.
- Frontend: a search box in the all-notes view; debounced server query.
- **Also register a Unified Search provider** (`OCP\Search\IProvider`) so notes
  appear in Nextcloud's global search bar (top-right magnifier) and deep-link to
  the note. Big discoverability win and an app-store plus.
- *Verified absent: no search in NoteService/NoteMapper, no ISearchProvider.*

### 2. Tasks: due dates, status, reminders  ·  **L**
The "Task" note type exists but carries no task semantics.
- Schema (new migration): add `due_date` (nullable) and `status`
  (`open`/`done`) to `crm_notes`.
- UI: due-date picker + done toggle on Task-type notes; "open tasks" filter;
  overdue styling.
- Reminders via `OCP\Notification` (see #3) and/or **AppFramework cron**
  (`IJob`) that fires notifications for due/overdue tasks.
- Stretch: two-way sync to **Calendar (CalDAV VTODO)** or a **Deck** board so
  tasks live where users already work.
- *Verified absent: no due_date/reminder/status columns.*

### 3. Notifications  ·  **M**
Today, sharing a note with someone is silent.
- Implement `OCP\Notification\INotifier`; notify on: note **shared with you**,
  (optionally) **@mention**, and **task due/overdue**.
- Deep-link the notification to the note.
- *Verified absent: no INotifier / notifications wiring.*

---

## P1 — Native-Nextcloud wins (high value, mostly quick)

### 4. Dashboard widget  ·  **S–M**
`OCP\Dashboard\IAPIWidgetV2` widget(s): "Recent notes" and/or "My open tasks"
on the Nextcloud dashboard. Cheap, high visibility. *Verified absent.*

### 5. Activity stream integration  ·  **M**
Emit to the **Activity** app on note created/edited/deleted/shared via
`IEventListener` + an activity provider, so changes show in the global activity
feed and per-user streams. *Verified absent.*

### 6. Filtering in the all-notes / contact views  ·  **S**
There's sort (newest/oldest) but no filter. Add filters: by **note type**,
**pinned-first**, **date range**, **has attachments**, **task status**.

### 7. Note-count indicator per contact  ·  **S**
Show a badge (e.g. "12") next to each contact in the filter list so you can see
who has interaction history at a glance. Backend: a `countByContactUids` batch
query; frontend: badge in the list row. *Verified absent.*

### 8. Export a contact's notes  ·  **M**
Export/print a contact's interaction history to **PDF/CSV** (common CRM ask:
hand someone "everything we know about X"). Could reuse a print stylesheet for
PDF and a simple CSV endpoint.

---

## P2 — Nice-to-haves

- **Bulk actions** (S–M): multi-select notes → delete / change type / share.
- **Note templates** (M): predefined skeletons per type (e.g. a call-log
  template with prompts), so common note types are faster to fill in.
- **Mail → note capture** (M): a Mail-app action ("Save to CRM Notes") that
  attaches an email to a contact as an Email-type note — the "Email" type exists
  but nothing currently logs a real message.
- **Richer linking** (M): link a note to multiple contacts *and* to an
  organisation/company entity; "related notes" surfacing.
- **Per-note checklist / sub-tasks** (M): lightweight checkboxes inside a note.
- **Attachments: upload new files** (S): today attachments link an existing
  Nextcloud file via the picker; allow uploading a new file inline.
- **Inline edit & quick-add from the Contacts tab** (S): the Contacts-tab panel
  can now *create* notes — extend it to *edit* and *delete* inline too.
- **Reactions / comments on a note** (M): for team collaboration on a shared
  note.

---

## Platform & integration

- **Upstream the Contacts tab API** (M, external): the Contacts app has *no*
  third-party extension point for its detail view — our panel is injected via
  the global `BeforeTemplateRenderedEvent` + DOM injection + base64-URL UID
  parsing, which is fragile. The highest-leverage upstream contribution is a
  small PR to the Contacts app adding a `registerSection`/`registerTab` API
  (mirroring Files/Mail). Then this app drops the MutationObserver and integrates
  natively. *Engage Contacts maintainers in a GitHub discussion first.*
- **OCS / public REST API** (M): a stable, documented API so other apps and
  external tools can read/write notes; enables automation.
- **Webhooks** (S–M): fire on note create/update (NC has `webhook_listeners`)
  for integration with external systems.
- **Talk integration** (M): "create a note from this conversation", or surface a
  contact's notes in a Talk sidebar.
- **Mobile / responsive audit** (S): verify the standalone app and the Contacts
  panel work well on the Nextcloud mobile clients' web views.
- **App Store release** (S): the app is already shaped for it (SPDX/REUSE,
  info.xml metadata). Finish the submission process (signing cert,
  `signature.json`, screenshots, ToS) and publish.

---

## Technical debt & hardening backlog

- **Contact-list virtualization** (M): the list now renders the *whole* address
  book using CSS `content-visibility` for paint, but ~5–6k `NcListItem`
  instances still cost ~3 s to mount. Swap to true row virtualization
  (`vue-virtual-scroller` like the Contacts app, or a manual fixed-height
  windower) so only visible rows mount.
- **Foreign keys / referential integrity** (M): there are no DB-level FKs; child
  rows are cleaned in `NoteService::delete()`. Consider FKs (or document the
  invariant and keep all deletes funneled through the service).
- **PHPStan level up** (S): currently level 5 with phpstan 1.x. Upgrade to
  phpstan 2.x and raise the level incrementally.
- **e2e coverage** (M): expand Playwright specs to cover the new surfaces —
  embedded card, full contact list + search, Contacts-tab inline add-note,
  photo rendering, sharing permissions.
- **i18n completeness** (S): only German (`de`) is topped up; add a translation
  workflow (Transifex/weblate) and ensure every user-facing string is wrapped.
- **Attachment import for migrations** (S): the eGroupware importer skips files
  whose blobs are absent from the backup; document how to supply a complete VFS
  export, and add the DB-backed `fs_content` path test.
- **Accessibility pass** (S): the review loop keeps surfacing icon-button
  labels, `aria-controls`, focus-visible — adopt a checklist / lint rule.
- **Remove the legacy `css/style.css`** if it's still the stale non-Vue
  stylesheet duplicating component styles.

---

## Suggested first milestone
If picking a single coherent release: **"Find & Act v1"** —
1. Full-text note search + Unified Search provider (#1)
2. Task due dates + status + a cron-driven reminder notification (#2 + #3)
3. Dashboard "My open tasks" widget (#4)

That set is what converts *notes on contacts* into something a team would run a
CRM on, and every piece is a clean, app-store-rewarded Nextcloud integration.

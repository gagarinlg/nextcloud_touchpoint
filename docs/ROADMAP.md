<!-- SPDX-FileCopyrightText: 2026 Touchpoint Contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Touchpoint — Feature Roadmap & Ideas

Ideas for evolving **Touchpoint** (`touchpoint`) from a solid "notes attached to
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

### 2. Tasks via the official Tasks app (integration, not built-in)  ·  **M–L**
Do **not** build a task engine into Touchpoint — Nextcloud already has an
official **Tasks** app (CalDAV `VTODO`). Integrate with it when it's installed,
and degrade gracefully when it isn't:
The Tasks app (0.18.x) has no third-party JS/PHP API — it's a CalDAV `VTODO`
frontend — but Nextcloud's **public `OCP\Calendar` API fully supports this**
(no private hacks needed, unlike the contact-photo case):
- **Create** "follow-up task" from a note: pick a writable VTODO calendar via
  `IManager::getCalendarsForPrincipal()` filtered by `ICreateFromString` +
  `ICalendarIsWritable`, build a `VTODO` (SUMMARY, DUE, `RELATED-TO`/an
  `X-TOUCHPOINT-NOTE` prop + a deep link in DESCRIPTION) and persist it with
  `ICreateFromString::createFromString()`. Store the returned task UID on the
  note (new column or a small link table) so the two stay linked.
- **Read/surface** a contact's open tasks next to their notes via
  `IManager::search('', [], ['types' => ['VTODO']])` (or `searchForPrincipal()`),
  filtered by the link, showing status/due + a deep link into the Tasks app —
  read-only is enough for v1.
- Reminders/overdue come "for free" from the Tasks app + Calendar — no parallel
  reminder system here.
- Gate on `IAppManager::isEnabledForUser('tasks')`, mirroring the Contacts
  integration. *Confirmed available on this NC: `OCP\Calendar\ICreateFromString`,
  `IManager::search`/`searchForPrincipal`, `ICalendarIsWritable`,
  `ICalendarEventBuilder`.*
- *Rationale: avoids duplicating the Tasks app and keeps tasks where users
  already manage them; Touchpoint stays the relationship/notes layer.*

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
- **Mail → note capture** (M): a Mail-app action ("Save to Touchpoint") that
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
- **Outward-facing APIs** (events, OCS/REST, webhooks, Flow, JS embedding): see
  the dedicated *"Public API surface"* section below — these are how *other* apps
  integrate with Touchpoint.
- **Talk integration** (M): "create a note from this conversation", or surface a
  contact's notes in a Talk sidebar.
- **Mobile / responsive audit** (S): verify the standalone app and the Contacts
  panel work well on the Nextcloud mobile clients' web views.
- **App Store release** (S): the app is already shaped for it (SPDX/REUSE,
  info.xml metadata). Finish the submission process (signing cert,
  `signature.json`, screenshots, ToS) and publish.

---

## Nextcloud 33/34 integration surfaces

Integration points worth targeting, grounded in the NC 34 (Hub 26 "Spring")
release notes + developer manual. Items 1–3 are the high-leverage ones and work
on **NC 32–34** (not 34-only); items 4–6 are 34-specific notes.

### 1. Reference provider + Smart Picker  ·  **M**
Implement `OCP\Collaboration\Reference\ADiscoverableReferenceProvider` (+
`ISearchableReferenceProvider`, wired to the note Unified Search provider from
P0 #1 via `getSupportedSearchProviderIds`). Two wins:
- Pasting a note link into **Text / Talk / Deck / Notes** renders a rich
  preview card instead of a bare URL.
- Users `/`-pick a Touchpoint note inline via the Smart Picker.
Backend: listen to `OCP\Collaboration\Reference\RenderReferenceEvent` in
`Application.php` and register the picker component. *The single most
"Nextcloud-native" surface we're currently missing; composes directly with
full-text search (#1).*

### 2. Related Resources  ·  **S–M**
Implement an `OCP\RelatedResources` provider so a contact's notes show up as
"related" items in other apps' sidebars (and ours surfaces related
calendar/Talk/Deck resources for a contact). Reinforces the contact-as-hub model.

### 3. Teams (formerly Circles) sharing  ·  **M**
NC 34 adds **team-based contact filtering** in Contacts. Mirror it by allowing a
note to be shared with a **Team**, not just a user/group — "the notes about this
account, shared with the account's team." Extends `touchpoint_note_sharing`
with a team principal type; gate on the Teams/Circles app being enabled.

### 4. Calendar hardening for the Tasks integration (NC 34)  ·  *note*
NC 34 hardened the `OCP\Calendar` surface the P0 #2 Tasks-via-VTODO plan relies
on: configurable **default reminders**, delegation ACLs, base-component **UID
validation**, and calendar read/write **federation**. No API churn for us —
reminders/overdue come even more "for free." Build #2 against NC 34 confidently.

### 5. Federated address books (NC 34)  ·  *robustness*
Contacts now sync across federated instances. Notes are keyed by contact UID, so
**foreign/federated UIDs must round-trip cleanly** through the contact list,
photo endpoint, and `touchpoint_note_contacts`. Mostly a test/robustness task,
not a feature. *Verify before claiming NC 34 support in earnest.*

### 6. Still missing in 34: a Contacts detail-view extension API
NC 34 did **not** add a third-party tab/section API for the Contacts app detail
view, so our panel is still injected via `BeforeTemplateRenderedEvent` + DOM +
base64-UID parsing. The "upstream a `registerSection` API" item above remains the
real fix. *Re-check each Contacts-app release.*

---

## Public API surface — let other apps integrate *with* Touchpoint

Today Touchpoint is a closed app: no documented way for another app, script, or
external system to read/write notes or react to changes. To become a platform
others build on, expose these (roughly in dependency order):

### A. Emit app events on note lifecycle  ·  **S**  *(foundational)*
Dispatch typed events via `OCP\EventDispatcher\IEventDispatcher` on note
**created / updated / deleted / shared / pinned** (e.g.
`OCA\Touchpoint\Event\NoteCreatedEvent`, carrying note id, owner, contact UIDs,
type). This is the cheapest, most idiomatic hook — every other integration
(Activity, Notifications, Webhooks, Flow) consumes these, and third-party apps
can `IEventListener` against them. Keep the event classes in a stable,
documented `OCA\Touchpoint\Event\` namespace (treat as public API).

### B. OCS / public REST API  ·  **M**
A versioned, documented OCS API (`/ocs/v2.php/apps/touchpoint/api/v1/...`) so
other apps and external tools can list/create/update/delete notes and note types
and query notes-by-contact. Reuse the service layer + the same ownership/sharing
access checks as the web controllers. App-password + OCS auth; document with
real examples. *(Supersedes the bare "OCS/REST" bullet above.)*

### C. Webhooks  ·  **S–M**
Register with NC's `webhook_listeners` so admins can fire outbound HTTP calls on
the note events from (A) — integrate with external CRM/marketing systems with no
code. Cheap once (A) exists.

### D. Reference provider (inbound links)  ·  **M**
The Smart-Picker/Reference provider (integration surface #1 above) is *also* an
outward API: it gives every other app a first-class way to **link to and embed**
a Touchpoint note. Cross-listed deliberately.

### E. Search provider  ·  **M**
The Unified Search `OCP\Search\IProvider` (P0 #1) makes notes discoverable to the
whole platform, including as a search source other reference providers can pick
from (`getSupportedSearchProviderIds`).

### F. Flow / WorkflowEngine operations  ·  **M**
Expose the note events from (A) as **Flow** triggers and/or a custom
`OCP\WorkflowEngine` operation (e.g. "when a note of type X is created, notify
/ tag / create a task"), so admins automate without code.

### G. Capabilities endpoint  ·  **S**
Advertise Touchpoint + its API version via `OCP\Capabilities\ICapability` so
clients can feature-detect (presence, version, enabled sub-features) before
calling the OCS API.

### H. Documented JS embedding API  ·  **S–M**
Promote the existing Contacts-tab island into a small, documented
`window.OCA.Touchpoint` surface (e.g. `mountNotesFor(el, contactUid)`,
`createNote(...)`) so other front-ends can embed the notes panel the way we embed
the Contacts card — the inverse of our current consumption of
`window.OCA.Contacts`.

> **Stability note:** anything published here (event class names, OCS routes,
> JS entry points, capability keys) becomes a compatibility contract. Version it,
> document it under `docs/`, and follow the deprecation policy NC apps use.

---

## Ecosystem app integrations (App Store survey)

Specific third-party / sibling Nextcloud apps Touchpoint could integrate with,
beyond the platform hooks above. Most depend on the **lifecycle events + OCS
API** from the *Public API surface* section, plus a graceful
`IAppManager::isEnabledForUser()` gate so each degrades cleanly when the other
app isn't installed. (Deliberately **excluded**: full external CRMs — CiviCRM,
SuiteCRM — and overlapping NC CRM apps like Pipelinq/Shillinq; Touchpoint stays
the lightweight notes-on-contacts layer rather than syncing with another CRM.)

### Calendar — meeting notes from events  ·  **M**
Sibling of the Tasks integration (P0 #2), same `OCP\Calendar` API: log a
**meeting note** from a calendar event, and link note↔event via `RELATED-TO` (or
an `X-TOUCHPOINT-NOTE` prop + deep link), so a contact's notes and meetings stay
cross-referenced. Read a contact's upcoming/past events next to their notes.

### Deck — note ↔ card  ·  **M**
Turn a follow-up note into a **Deck card** (and link back), or attach an existing
card to a note, so action items live on a board while the context stays in
Touchpoint. Deck exposes an OCS API and a Smart Picker provider to build against.

### Forms — lead intake  ·  **S–M**
Auto-create a note (and optionally a contact) from a **Forms submission** — the
classic "leads in the door" path and the cheapest CRM-shaped win. Drive it off
Forms' submission event (via Flow/Webhooks or a direct listener).

### Appointments — booking → note  ·  **S–M**
When someone books via the **Appointments** app, automatically log a meeting note
on the booker's contact, turning scheduling into interaction history with no
manual entry.

### Zammad — helpdesk tickets ↔ contact timeline  ·  **M**
Surface a contact's **Zammad** support tickets alongside their notes (and/or log
a note when a ticket is opened/closed), so support history is part of the
relationship record. Integrate via the Zammad integration app / Zammad's REST API
+ token, gated on the app being enabled. Read-only ticket surfacing is enough for
v1.

### iTop — ITSM cases ↔ contact  ·  **M**
Same shape as Zammad for **iTop** (IT service management): link a contact to
their open cases. Niche/B2B; lower priority than Zammad.

### Project trackers — link a note to a task  ·  **M** (each)
For account-management / B2B use, link a note to an external task in
**OpenProject**, **Jira**, **GitLab**, or **GitHub** (all have NC integration
apps). Lower fit than the PIM apps; do only on demand. Prefer the generic
reference-provider / Smart Picker route so one mechanism covers all four.

### AI assistance (speculative, later)  ·  **M**
Via `OCP\TaskProcessing` (so it works with **Assistant**, **OpenAI/LocalAI**,
etc.): summarise a contact's note history ("catch me up on X"), suggest a
follow-up, or auto-tag a note's type. Translation of notes via
**DeepL/LibreTranslate**. Off the critical path — revisit once search + tasks
land.

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
- **i18n completeness** (S): only German (`de`, `de_DE`) is filled in; every
  user-facing string is wrapped, but the other ~80 locale files are stubs.
  Publishing on the App Store wires the app into Nextcloud's **Transifex**
  project, where the community supplies translations — so the practical task is
  to enable that, not to hand-translate. Sensible launch-priority languages
  (by Nextcloud community size): **French (fr)**, **Spanish (es)**,
  **Italian (it)**, **Dutch (nl)**, **Czech (cs)**, **Polish (pl)**,
  **Portuguese (pt_BR)**, then **Chinese (zh_CN)**, **Japanese (ja)**, plus the
  Nordics (**da, sv, nb, fi**), **Hungarian (hu)**, **Turkish (tr)**,
  **Ukrainian (uk)**, **Catalan (ca)**, **Greek (el)**, **Korean (ko)**.
  German is already done.
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
2. Tasks-app integration — create/link follow-up VTODOs from a note (#2) + share
   notifications (#3)
3. Dashboard "Recent notes" / "Follow-ups" widget (#4)

That set is what converts *notes on contacts* into something a team would run a
CRM on, and every piece is a clean, app-store-rewarded Nextcloud integration.

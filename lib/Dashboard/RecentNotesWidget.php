<?php

// SPDX-FileCopyrightText: 2026 Touchpoint Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Touchpoint\Dashboard;

use OCA\Touchpoint\Db\Note;
use OCA\Touchpoint\Db\NoteMapper;
use OCA\Touchpoint\Db\NoteType;
use OCA\Touchpoint\Service\NoteService;
use OCA\Touchpoint\Service\NoteTypeService;
use OCA\Touchpoint\Service\SettingsService;
use OCP\Contacts\IManager as IContactsManager;
use OCP\Dashboard\IAPIWidgetV2;
use OCP\Dashboard\IButtonWidget;
use OCP\Dashboard\IIconWidget;
use OCP\Dashboard\Model\WidgetButton;
use OCP\Dashboard\Model\WidgetItem;
use OCP\Dashboard\Model\WidgetItems;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Dashboard widget showing the current user's most recent notes (owned +
 * shared), matching the access scope of NoteService::findAll() used elsewhere
 * (NoteSearchProvider, the all-notes view) — which, when the admin's "public
 * notes" setting is enabled, returns every user's notes system-wide rather
 * than just $userId's owned+shared set. This widget does not re-implement its
 * own scoping: it defers to that existing setting via the same guard
 * NoteSearchProvider::search() uses, hiding its content entirely (empty
 * WidgetItems) while public mode is on rather than surfacing every user's
 * notes on every user's dashboard.
 */
class RecentNotesWidget implements IAPIWidgetV2, IButtonWidget, IIconWidget {

    /** Default number of items requested by IAPIWidgetV2::getItemsV2(). */
    private const DEFAULT_ITEM_LIMIT = 7;

    /**
     * Upper bound on the number of notes fetched/enriched per request,
     * matching the Nextcloud dashboard API's own documented cap
     * (`DashboardApiController::getWidgetItemsV2()`'s `@psalm-param
     * int<1, 30>`, which is a static-analysis hint only — not a runtime
     * clamp). A caller-supplied $limit is clamped to this range before
     * reaching NoteService::findAll() so a crafted/negative value can never
     * force an unbounded query or an uncaught exception from
     * QueryBuilder::setMaxResults().
     */
    private const MAX_ITEM_LIMIT = 30;

    /** Widget titles/subtitles are rendered in a fixed-width dashboard card; keep them short. */
    private const MAX_TITLE_LENGTH = 60;

    /**
     * Sorting weight passed to IManager::registerWidget()'s ordering of
     * first-party/third-party dashboard widgets. 10 sits below Nextcloud
     * core's own first-party widgets (Activity/Weather/Tasks register in the
     * 0-9 range), so Touchpoint's card appears after core widgets by default
     * rather than jostling for the first slot.
     */
    private const DASHBOARD_ORDER = 10;

    public function __construct(
        private IURLGenerator $urlGenerator,
        private IL10N $l10n,
        private NoteService $noteService,
        private NoteTypeService $noteTypeService,
        private IContactsManager $contactsManager,
        private IUserSession $userSession,
        private SettingsService $settingsService,
        private LoggerInterface $logger,
    ) {
    }

    public function getId(): string {
        return 'touchpoint-recent-notes';
    }

    public function getTitle(): string {
        return $this->l10n->t('Recent notes');
    }

    public function getOrder(): int {
        return self::DASHBOARD_ORDER;
    }

    public function getIconClass(): string {
        return '';
    }

    /**
     * OCP\Dashboard\IIconWidget's own icon. This is NOT the same render
     * context as the per-item icon below: Nextcloud core's "Manage widgets"
     * picker (apps/dashboard/src/DashboardApp.vue) renders this value as a
     * plain `<label><img :src="panel.iconUrl"></label>` — no NcAvatar
     * involved — on that modal's `label` background
     * (`var(--color-background-hover)`, also theme-following) and applies
     * `filter: var(--background-invert-if-dark)` to the `<img>` itself,
     * i.e. core auto-inverts whatever we return here for dark theme, per
     * IIconWidget's own docblock ("should be colored black or not have a
     * color... will be inverted automatically... in dark mode"). Every
     * first-party widget implementing IIconWidget (UserStatusWidget,
     * FavoriteWidget, TasksWidget, MailWidget, ActivityWidget, TalkWidget)
     * returns its dark/black glyph here for the same reason. Matches
     * Notifier's bell dropdown / Settings\AdminSection's sidebar entry
     * (also app-dark.svg, for the same "platform inverts it" contract).
     */
    public function getIconUrl(): string {
        return $this->urlGenerator->getAbsoluteURL(
            $this->urlGenerator->imagePath('touchpoint', 'app-dark.svg')
        );
    }

    /**
     * Per-item icon (WidgetItem's iconUrl), a DIFFERENT render context from
     * getIconUrl() above: apps/dashboard/src/components/ApiDashboardWidgetItem.vue
     * wraps this value in @nextcloud/vue's NcAvatar, whose `.avatardiv`
     * background is `var(--color-main-background)` (see NcAvatar's
     * stylesheet) — a theme-following surface that Nextcloud's built-in Dark
     * theme overrides to a dark value — and applies no invert filter of its
     * own (unlike the widget-picker's `<img>` above). So this one needs the
     * light/white glyph variant, matching NoteSearchProvider/
     * ContactsMenu\Provider, which also render on theme-following tinted
     * surfaces with no platform-side inversion.
     */
    private function getItemIconUrl(): string {
        return $this->urlGenerator->getAbsoluteURL(
            $this->urlGenerator->imagePath('touchpoint', 'app.svg')
        );
    }

    /**
     * Absolute URL to the small pin badge used as a pinned note's
     * overlayIconUrl. Self-contained SVG asset (same convention as
     * app.svg/app-dark.svg), path data taken from vue-material-design-icons'
     * Pin.vue so the dashboard badge matches the pin glyph used elsewhere in
     * the app (NoteItem.vue). Renders inside the same NcAvatar-adjacent
     * per-item surface as getItemIconUrl() above (ApiDashboardWidgetItem.vue's
     * `.item-icon` overlay has no background rule or invert filter of its
     * own), so it needs the matching light/white-fill variant, not a
     * fixed-black glyph.
     */
    private function getPinBadgeIconUrl(): string {
        return $this->urlGenerator->getAbsoluteURL(
            $this->urlGenerator->imagePath('touchpoint', 'pin-badge-light.svg')
        );
    }

    public function getUrl(): ?string {
        return $this->urlGenerator->getAbsoluteURL(
            $this->urlGenerator->linkToRoute('touchpoint.page.index')
        );
    }

    public function load(): void {
    }

    public function getWidgetButtons(string $userId): array {
        return [
            new WidgetButton(
                WidgetButton::TYPE_MORE,
                (string) $this->getUrl(),
                $this->l10n->t('Show all'),
            ),
        ];
    }

    public function getItemsV2(string $userId, ?string $since = null, int $limit = self::DEFAULT_ITEM_LIMIT): WidgetItems {
        // The dashboard API only ever calls this for the currently logged-in
        // user, but defend against a missing/mismatched session the same way
        // NoteSearchProvider::search() does: never let an unauthenticated or
        // mismatched session leak another user's notes if this were ever
        // invoked in an unexpected context.
        $sessionUser = $this->userSession->getUser();
        if ($sessionUser === null || $sessionUser->getUID() !== $userId) {
            return new WidgetItems([]);
        }

        // Belt-and-suspenders, matching NoteSearchProvider::search(): when the
        // admin's "public notes" setting is enabled, NoteService::findAll()
        // switches to NoteMapper::findAllPublic() and returns EVERY user's
        // notes system-wide, completely ignoring $userId. Unlike search
        // (which is a deliberate lookup), a dashboard widget renders
        // unconditionally on every page load for every logged-in user, so
        // that would leak other users' private note titles/subtitles/deep
        // links onto this user's dashboard. Hide the widget's content
        // entirely rather than surface unscoped data. Uses a distinct message
        // from the genuinely-empty case below so a user with actual recent
        // notes isn't told "No recent notes" (which reads as a bug/sync
        // failure) when the real reason is an admin setting.
        if ($this->settingsService->isNotesPublic()) {
            return new WidgetItems([], $this->l10n->t('Recent notes are hidden while public notes mode is enabled'));
        }

        // Clamp: DashboardApiController's own $limit bound is a Psalm
        // annotation, not a runtime check, so a crafted request could
        // otherwise pass an unbounded or negative value straight through to
        // NoteMapper's setMaxResults().
        $limit = max(1, min($limit, self::MAX_ITEM_LIMIT));

        // DashboardApiController::getWidgetItemsV2() (Nextcloud core) loops
        // over every installed app's registered widget in a single request
        // with NO per-widget try/catch of its own — an uncaught exception
        // from any of the three fallible calls below (NoteService::findAll(),
        // NoteTypeService::findAll(), IContactsManager::search() inside
        // buildSubtitle()/resolveContactName()) would 500 the ENTIRE
        // /api/v2/widget-items batch response, breaking every OTHER app's
        // dashboard widget on the page too, not just this card. Matches
        // NotificationService's own swallow-and-log convention for the same
        // "never let one integration point break something bigger" concern.
        try {
            $notes = $this->noteService->findAll($userId, $limit, 0, NoteMapper::SORT_NEWEST);
            $notes = $this->prioritisePinned($notes);

            if ($notes === []) {
                return new WidgetItems([], $this->l10n->t('No recent notes'));
            }

            $noteTypesById = $this->indexNoteTypesById($userId);
            $iconUrl = $this->getItemIconUrl();
            $pinBadgeIconUrl = $this->getPinBadgeIconUrl();

            $items = array_map(
                fn (Note $note) => $this->buildWidgetItem($note, $noteTypesById, $iconUrl, $pinBadgeIconUrl),
                $notes,
            );

            // Matches the first-party widget convention (FavoriteWidget/MailWidget
            // in Nextcloud core): pass the empty-content message only when there
            // are actually no items, not unconditionally — the current dashboard
            // client (ApiDashboardWidget.vue) only renders it behind an
            // items.length === 0 check, but a future/alternate client rendering it
            // unconditionally (e.g. as a footer) must not show stale "No recent
            // notes" text alongside real items.
            return new WidgetItems($items, '');
        } catch (Throwable $e) {
            $this->logger->warning(
                'Touchpoint: failed to build dashboard "Recent notes" widget items',
                ['exception' => $e, 'userId' => $userId]
            );
            return new WidgetItems([], $this->l10n->t('Recent notes are unavailable right now'));
        }
    }

    /**
     * Move pinned notes to the front of the (already newest-first) list,
     * preserving relative order within each group, so a pinned note is never
     * pushed out of the widget's limited slot count by newer, unpinned
     * notes. Documented in docs/API.md's "Dashboard integration" section.
     *
     * @param Note[] $notes
     * @return Note[]
     */
    private function prioritisePinned(array $notes): array {
        $pinned = array_values(array_filter($notes, fn (Note $note) => $note->getIsPinned()));
        $unpinned = array_values(array_filter($notes, fn (Note $note) => !$note->getIsPinned()));
        return array_merge($pinned, $unpinned);
    }

    /**
     * Resolve all of the viewing user's note types (including global
     * defaults) in a single call, mirroring NoteService::enrichNotes()'s
     * batch-then-map convention instead of calling NoteTypeService::find()
     * once per note (N+1 across up to MAX_ITEM_LIMIT notes per widget load).
     *
     * @return array<int, NoteType>
     */
    private function indexNoteTypesById(string $userId): array {
        $byId = [];
        foreach ($this->noteTypeService->findAll($userId) as $noteType) {
            $byId[$noteType->getId()] = $noteType;
        }
        return $byId;
    }

    /**
     * @param array<int, NoteType> $noteTypesById
     */
    private function buildWidgetItem(Note $note, array $noteTypesById, string $iconUrl, string $pinBadgeIconUrl): WidgetItem {
        $title = $note->getTitle() !== '' ? $note->getTitle() : $this->l10n->t('Untitled');
        $title = $this->truncate($title);

        $subtitle = $this->buildSubtitle($note, $noteTypesById);

        return new WidgetItem(
            $title,
            $subtitle,
            $this->buildNoteLink($note),
            $iconUrl,
            '',
            $note->getIsPinned() ? $pinBadgeIconUrl : '',
        );
    }

    /**
     * Subtitle combines the linked contact's display name (when the note is
     * linked to one) with the note type's label, e.g. "Jane Doe · Call".
     *
     * @param array<int, NoteType> $noteTypesById
     */
    private function buildSubtitle(Note $note, array $noteTypesById): string {
        $parts = [];

        $contactName = $this->resolveContactName($note->getContactUid());
        if ($contactName !== '') {
            $parts[] = $contactName;
        }

        $typeLabel = $this->resolveNoteTypeLabel($note->getNoteTypeId(), $noteTypesById);
        if ($typeLabel !== '') {
            $parts[] = $typeLabel;
        }

        // ' · ' carries no linguistic content (a plain list-join glyph, not
        // translatable text), so it is deliberately not wrapped in t() —
        // unlike every actual user-facing string in this class.
        //
        // Truncated the same as the title (same fixed-width dashboard card
        // constraint, see MAX_TITLE_LENGTH's docblock): the contact display
        // name is foreign, uncapped address-book data, so the assembled
        // subtitle has no inherent length bound otherwise.
        return $this->truncate(implode(' · ', $parts));
    }

    /**
     * Resolve a contact UID to its display name via the same contacts search
     * pattern ContactController uses (search by property, flatten the typed
     * result). Returns '' for an unlinked note or an unresolvable UID rather
     * than throwing — a dashboard widget must never fail to render because one
     * contact vanished from the address book.
     *
     * Bounded (at most MAX_ITEM_LIMIT calls per request) but unavoidable
     * per-item cost: OCP\Contacts\IManager exposes no bulk-by-UID-list search,
     * so unlike the note-type lookup below this cannot be batched into a
     * single call. Revisit if IManager ever grows a batch method.
     */
    private function resolveContactName(string $contactUid): string {
        if ($contactUid === '') {
            return '';
        }

        $results = $this->contactsManager->search(
            $contactUid,
            ['UID'],
            ['limit' => 1, 'strict_search' => true],
        );

        foreach ($results as $entry) {
            if (($entry['UID'] ?? '') === $contactUid) {
                $name = $entry['FN'] ?? '';
                return is_string($name) ? $name : '';
            }
        }

        return '';
    }

    /**
     * Resolve a note type id to its user-facing label from the pre-fetched
     * id->NoteType map (see indexNoteTypesById()). Falls back to '' (the
     * subtitle simply omits the type) if the type was deleted, or if it
     * belongs to a different user than the viewer: NoteTypeService's read
     * scope only ever resolves the viewing user's own custom types plus the
     * shared global defaults, never another user's custom types (see
     * NoteTypeMapper::readScope()) — so a note shared by someone using one
     * of their own non-default note types will have its type label omitted
     * for the recipient. This is fail-closed (no cross-user type data is
     * ever disclosed) and intentional; see docs/API.md's "Dashboard
     * integration" section for the documented behaviour.
     *
     * @param array<int, NoteType> $noteTypesById
     */
    private function resolveNoteTypeLabel(int $noteTypeId, array $noteTypesById): string {
        if ($noteTypeId <= 0) {
            return '';
        }
        return ($noteTypesById[$noteTypeId] ?? null)?->getName() ?? '';
    }

    /**
     * Deep-link straight to this specific note via the app's `#note/<id>`
     * convention (App.vue's applyNoteDeepLink(), the same mechanism the
     * note_shared/note_mention notifications use — see Notifier::buildIconUrl()'s
     * companion notification deep-link). This fetches the note, switches to its
     * contact, and scrolls to/highlights/focuses that individual note, rather
     * than dumping the user into the contact's entire note history with no
     * indication of which note prompted the click. Every note returned by
     * NoteService::findAll() has a server-issued id (see NoteService::create()),
     * so there is no fallback branch to a contact-only or app-root link here.
     */
    private function buildNoteLink(Note $note): string {
        return $this->urlGenerator->getAbsoluteURL(
            $this->urlGenerator->linkToRoute('touchpoint.page.index')
            . '#note/' . $note->getId()
        );
    }

    private function truncate(string $value): string {
        if (mb_strlen($value, 'UTF-8') <= self::MAX_TITLE_LENGTH) {
            return $value;
        }
        return mb_substr($value, 0, self::MAX_TITLE_LENGTH, 'UTF-8') . '…';
    }
}

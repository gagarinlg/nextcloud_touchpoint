<?php

// SPDX-FileCopyrightText: 2026 Touchpoint Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Touchpoint\Tests\Unit\Dashboard;

use OCA\Touchpoint\Dashboard\RecentNotesWidget;
use OCA\Touchpoint\Db\Note;
use OCA\Touchpoint\Db\NoteType;
use OCA\Touchpoint\Service\NoteService;
use OCA\Touchpoint\Service\NoteTypeService;
use OCA\Touchpoint\Service\SettingsService;
use OCP\Contacts\IManager as IContactsManager;
use OCP\Dashboard\IAPIWidgetV2;
use OCP\Dashboard\IButtonWidget;
use OCP\Dashboard\IIconWidget;
use OCP\Dashboard\Model\WidgetButton;
use OCP\Dashboard\Model\WidgetItems;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Unit tests for RecentNotesWidget.
 *
 * Covers:
 * (a) implements IAPIWidgetV2 + IButtonWidget
 * (b) getId() returns the stable widget id
 * (c) getTitle() returns translated 'Recent notes'
 * (d) getIconUrl() uses IURLGenerator::imagePath() with the dark app icon
 *     (the widget-picker's fixed-light/auto-inverted context); per-item
 *     iconUrl uses the light app icon (the NcAvatar-wrapped context)
 * (e) getUrl() links to the Touchpoint app page
 * (f) getItemsV2() fetches up to the requested limit via NoteService::findAll()
 *     (owned + shared) and maps to WidgetItem[] with title/subtitle/link/iconUrl
 * (g) title truncation beyond MAX_TITLE_LENGTH
 * (h) subtitle combines contact name + note type label with a separator
 * (i) subtitle degrades gracefully when contact is unresolvable / note has no contact
 * (j) subtitle degrades gracefully when note type was deleted
 * (k) deep link points to the note itself (#note/<id>), not the contact
 * (l) getWidgetButtons() returns exactly one 'Show all' button linking to the app page
 * (m) session/user UID mismatch returns no items (defensive guard)
 * (n) empty note list returns WidgetItems with an empty-content message
 * (o) not-logged-in session returns no items (defensive guard)
 * (p) pinned notes are moved ahead of unpinned notes and carry the pin overlay icon
 * (q) $limit is clamped to [1, MAX_ITEM_LIMIT]
 * (r) getOrder()/getIconClass() honour their interface return-type contracts
 * (s) the widget's own default limit matches IAPIWidgetV2's interface-level default (7)
 * (t) a non-null $since cursor is tolerated without erroring
 * (u) offset is always pinned to 0 regardless of $limit; sort is always 'newest'
 * (v) multiple notes preserve count/order and resolve distinct subtitles independently
 * (w) titles with HTML metacharacters pass through unescaped (no double-encoding)
 * (x) note-type lookup is scoped to the viewing user, never the note owner
 * (y) zero notes short-circuits before the note-type batch lookup (no N+1 avoidable query)
 * (z) "public notes" admin mode returns no items and skips NoteService::findAll() entirely
 * (aa) implements OCP\Dashboard\IIconWidget (required for core to call getIconUrl())
 * (ab) getItemsV2() never lets an exception from NoteService/NoteTypeService/
 *      IContactsManager escape the widget boundary (core's dashboard batch
 *      endpoint has no per-widget try/catch of its own)
 */
class RecentNotesWidgetTest extends TestCase {

    /** @var IURLGenerator&MockObject */
    private IURLGenerator $urlGenerator;
    /** @var IL10N&MockObject */
    private IL10N $l10n;
    /** @var NoteService&MockObject */
    private NoteService $noteService;
    /** @var NoteTypeService&MockObject */
    private NoteTypeService $noteTypeService;
    /** @var IContactsManager&MockObject */
    private IContactsManager $contactsManager;
    /** @var SettingsService&MockObject */
    private SettingsService $settingsService;
    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    protected function setUp(): void {
        $this->urlGenerator = $this->createMock(IURLGenerator::class);
        $this->l10n = $this->createMock(IL10N::class);
        $this->noteService = $this->createMock(NoteService::class);
        $this->noteTypeService = $this->createMock(NoteTypeService::class);
        $this->contactsManager = $this->createMock(IContactsManager::class);
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->l10n->method('t')->willReturnArgument(0);

        // Public-notes mode defaults to off; tests that need it on build their
        // own SettingsService mock via makeWidget()'s override (see below).
        $this->settingsService->method('isNotesPublic')->willReturn(false);

        $this->urlGenerator->method('linkToRoute')
            ->with('touchpoint.page.index')
            ->willReturn('/apps/touchpoint/');
        $this->urlGenerator->method('imagePath')
            ->willReturnMap([
                ['touchpoint', 'app.svg', '/apps/touchpoint/img/app.svg'],
                ['touchpoint', 'app-dark.svg', '/apps/touchpoint/img/app-dark.svg'],
                ['touchpoint', 'pin-badge-light.svg', '/apps/touchpoint/img/pin-badge-light.svg'],
            ]);
        $this->urlGenerator->method('getAbsoluteURL')
            ->willReturnCallback(fn (string $url) => 'https://nc.test' . $url);

        // No default findAll() stub here deliberately: PHPUnit's "first
        // unconstrained stub wins" means a blanket setUp()-level stub would
        // shadow any test-specific ->willReturn() below. NoteTypeService is
        // a typed-return mock, so an un-stubbed call still returns [] (the
        // declared array return type's safe default) rather than null.
    }

    /**
     * @param IUserSession|null $userSession `null` (the default, not passed)
     *     means "use a session for the logged-in user this test operates as"
     *     (alice) — matching production, where IUserSession is always
     *     injected. A test exercising the "not logged in" guard passes an
     *     explicit not-logged-in mock (getUser() returns null).
     */
    private function makeWidget(?IUserSession $userSession = null, ?SettingsService $settingsService = null, ?LoggerInterface $logger = null): RecentNotesWidget {
        $userSession ??= $this->makeSessionForUser('alice');

        return new RecentNotesWidget(
            $this->urlGenerator,
            $this->l10n,
            $this->noteService,
            $this->noteTypeService,
            $this->contactsManager,
            $userSession,
            $settingsService ?? $this->settingsService,
            $logger ?? $this->logger,
        );
    }

    private function makeNoteType(int $id, string $name): NoteType {
        $noteType = new NoteType();
        $noteType->setId($id);
        $noteType->setName($name);
        return $noteType;
    }

    private function makeNote(int $id, string $contactUid, string $title, int $noteTypeId): Note {
        $note = new Note();
        $note->setId($id);
        $note->setContactUid($contactUid);
        $note->setTitle($title);
        $note->setNoteTypeId($noteTypeId);
        return $note;
    }

    private function makeSessionForUser(string $uid): IUserSession {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturn($user);
        return $session;
    }

    // ── Interfaces ───────────────────────────────────────────────────────────

    public function testImplementsRequiredInterfaces(): void {
        $widget = $this->makeWidget();
        $this->assertInstanceOf(IAPIWidgetV2::class, $widget);
        $this->assertInstanceOf(IButtonWidget::class, $widget);
        // IIconWidget is required for Nextcloud core's DashboardApiController
        // to actually call getIconUrl() for the widget-level icon --
        // IAPIWidgetV2/IButtonWidget alone do not trigger that dispatch.
        $this->assertInstanceOf(IIconWidget::class, $widget);
    }

    // ── Static metadata ──────────────────────────────────────────────────────

    public function testGetId(): void {
        $this->assertSame('touchpoint-recent-notes', $this->makeWidget()->getId());
    }

    public function testGetTitleIsTranslated(): void {
        $l10n = $this->createMock(IL10N::class);
        $l10n->expects($this->atLeastOnce())
            ->method('t')
            ->with('Recent notes')
            ->willReturn('Neueste Notizen');

        $widget = new RecentNotesWidget(
            $this->urlGenerator,
            $l10n,
            $this->noteService,
            $this->noteTypeService,
            $this->contactsManager,
            $this->makeSessionForUser('alice'),
            $this->settingsService,
            $this->logger,
        );

        $this->assertSame('Neueste Notizen', $widget->getTitle());
    }

    /**
     * getIconUrl() is OCP\Dashboard\IIconWidget's own icon, rendered by
     * Nextcloud core's "Manage widgets" picker as a plain <img> (no
     * NcAvatar) on a background that core auto-inverts for dark theme via
     * `filter: var(--background-invert-if-dark)` — matching every
     * first-party IIconWidget implementation (UserStatusWidget,
     * FavoriteWidget, TasksWidget, MailWidget, ActivityWidget, TalkWidget),
     * it must use the dark/black glyph variant (app-dark.svg), not the
     * light/white app.svg variant (which is used for the per-item icon
     * instead — see testGetItemsV2MapsNoteToWidgetItem).
     */
    public function testGetIconUrlUsesAppDarkIcon(): void {
        $iconUrl = $this->makeWidget()->getIconUrl();
        $this->assertSame('https://nc.test/apps/touchpoint/img/app-dark.svg', $iconUrl);
    }

    public function testGetUrlLinksToAppPage(): void {
        $url = $this->makeWidget()->getUrl();
        $this->assertSame('https://nc.test/apps/touchpoint/', $url);
    }

    // ── getWidgetButtons ─────────────────────────────────────────────────────

    public function testGetWidgetButtonsReturnsSingleShowAllButton(): void {
        $buttons = $this->makeWidget()->getWidgetButtons('alice');

        $this->assertCount(1, $buttons);
        $this->assertInstanceOf(WidgetButton::class, $buttons[0]);
        $this->assertSame('Show all', $buttons[0]->getText());
        $this->assertSame('https://nc.test/apps/touchpoint/', $buttons[0]->getLink());
    }

    // ── getItemsV2: fetching ─────────────────────────────────────────────────

    public function testGetItemsV2FetchesUpToLimitOwnedAndShared(): void {
        $this->noteService->expects($this->once())
            ->method('findAll')
            ->with('alice', 7, 0, 'newest')
            ->willReturn([]);

        $result = $this->makeWidget()->getItemsV2('alice');

        $this->assertInstanceOf(WidgetItems::class, $result);
    }

    public function testGetItemsV2RespectsCustomLimit(): void {
        $this->noteService->expects($this->once())
            ->method('findAll')
            ->with('alice', 3, 0, 'newest')
            ->willReturn([]);

        $this->makeWidget()->getItemsV2('alice', null, 3);
    }

    public function testGetItemsV2ReturnsEmptyContentMessageWhenNoNotes(): void {
        $this->noteService->method('findAll')->willReturn([]);

        $result = $this->makeWidget()->getItemsV2('alice');

        $this->assertSame([], $result->getItems());
        $this->assertSame('No recent notes', $result->getEmptyContentMessage());
    }

    public function testGetItemsV2SkipsNoteTypeLookupWhenNoNotes(): void {
        $this->noteService->method('findAll')->willReturn([]);
        $this->noteTypeService->expects($this->never())->method('findAll');

        $this->makeWidget()->getItemsV2('alice');
    }

    public function testGetItemsV2ReturnsNoItemsAndSkipsFindAllWhenNotesArePublic(): void {
        $settingsService = $this->createMock(SettingsService::class);
        $settingsService->method('isNotesPublic')->willReturn(true);

        $this->noteService->expects($this->never())->method('findAll');
        $this->noteTypeService->expects($this->never())->method('findAll');

        $result = $this->makeWidget(null, $settingsService)->getItemsV2('alice');

        $this->assertSame([], $result->getItems());
        $this->assertSame('Recent notes are hidden while public notes mode is enabled', $result->getEmptyContentMessage());
    }

    /**
     * The public-notes-hidden empty message must be distinct from the
     * genuinely-empty ("no notes at all") message — otherwise a user with
     * actual recent notes sees the same text as a user with zero notes, with
     * no way to tell an admin setting is hiding the widget's content versus a
     * bug or sync failure.
     */
    public function testPublicNotesEmptyMessageDiffersFromGenuinelyEmptyMessage(): void {
        $settingsService = $this->createMock(SettingsService::class);
        $settingsService->method('isNotesPublic')->willReturn(true);
        $publicResult = $this->makeWidget(null, $settingsService)->getItemsV2('alice');

        $this->noteService->method('findAll')->willReturn([]);
        $genuinelyEmptyResult = $this->makeWidget()->getItemsV2('alice');

        $this->assertNotSame(
            $genuinelyEmptyResult->getEmptyContentMessage(),
            $publicResult->getEmptyContentMessage(),
        );
    }

    public function testGetItemsV2ClampsLimitToMaximum(): void {
        $this->noteService->expects($this->once())
            ->method('findAll')
            ->with('alice', 30, 0, 'newest')
            ->willReturn([]);

        $this->makeWidget()->getItemsV2('alice', null, 999999);
    }

    public function testGetItemsV2ClampsNegativeLimitToOne(): void {
        $this->noteService->expects($this->once())
            ->method('findAll')
            ->with('alice', 1, 0, 'newest')
            ->willReturn([]);

        $this->makeWidget()->getItemsV2('alice', null, -5);
    }

    // ── getItemsV2: mapping ──────────────────────────────────────────────────

    public function testGetItemsV2MapsNoteToWidgetItem(): void {
        $note = $this->makeNote(1, 'contact-abc', 'Follow up call', 5);
        $this->noteService->method('findAll')->willReturn([$note]);

        $this->noteTypeService->method('findAll')
            ->with('alice')
            ->willReturn([$this->makeNoteType(5, 'Call')]);

        $this->contactsManager->method('search')
            ->with('contact-abc', ['UID'], ['limit' => 1, 'strict_search' => true])
            ->willReturn([
                ['UID' => 'contact-abc', 'FN' => 'Jane Doe'],
            ]);

        $result = $this->makeWidget()->getItemsV2('alice');
        $items = $result->getItems();

        $this->assertCount(1, $items);
        $this->assertSame('Follow up call', $items[0]->getTitle());
        $this->assertSame('Jane Doe · Call', $items[0]->getSubtitle());
        $this->assertSame(
            'https://nc.test/apps/touchpoint/#note/1',
            $items[0]->getLink(),
        );
        // Per-item icon: rendered inside NcAvatar (theme-following, no
        // platform-side invert), so it's the light/white variant — distinct
        // from getIconUrl()'s dark widget-picker icon (see
        // testGetIconUrlUsesAppDarkIcon).
        $this->assertSame('https://nc.test/apps/touchpoint/img/app.svg', $items[0]->getIconUrl());
    }

    /**
     * Matches the first-party widget convention (FavoriteWidget/MailWidget in
     * Nextcloud core): the empty-content message is passed only when there
     * are actually no items, never alongside a non-empty item list — a future
     * client rendering it unconditionally must not show stale "No recent
     * notes" text next to real items.
     */
    public function testEmptyContentMessageIsBlankWhenItemsArePresent(): void {
        $note = $this->makeNote(1, '', 'Title', 0);
        $this->noteService->method('findAll')->willReturn([$note]);

        $result = $this->makeWidget()->getItemsV2('alice');

        $this->assertNotEmpty($result->getItems());
        $this->assertSame('', $result->getEmptyContentMessage());
    }

    public function testGetItemsV2FallsBackToUntitledForEmptyTitle(): void {
        $note = $this->makeNote(1, '', '', 0);
        $this->noteService->method('findAll')->willReturn([$note]);

        $result = $this->makeWidget()->getItemsV2('alice');
        $items = $result->getItems();

        $this->assertSame('Untitled', $items[0]->getTitle());
    }

    public function testGetItemsV2TruncatesLongTitle(): void {
        $longTitle = str_repeat('a', 100);
        $note = $this->makeNote(1, '', $longTitle, 0);
        $this->noteService->method('findAll')->willReturn([$note]);

        $result = $this->makeWidget()->getItemsV2('alice');
        $items = $result->getItems();

        $this->assertSame(61, mb_strlen($items[0]->getTitle(), 'UTF-8')); // 60 chars + ellipsis
        $this->assertStringEndsWith('…', $items[0]->getTitle());
        $this->assertStringStartsWith(str_repeat('a', 60), $items[0]->getTitle());
    }

    public function testGetItemsV2DoesNotTruncateShortTitle(): void {
        $note = $this->makeNote(1, '', 'Short title', 0);
        $this->noteService->method('findAll')->willReturn([$note]);

        $result = $this->makeWidget()->getItemsV2('alice');
        $items = $result->getItems();

        $this->assertSame('Short title', $items[0]->getTitle());
    }

    // ── Subtitle edge cases ──────────────────────────────────────────────────

    public function testSubtitleOmitsContactWhenNoteHasNoContact(): void {
        $this->noteTypeService->method('findAll')->willReturn([$this->makeNoteType(2, 'Task')]);

        $note = $this->makeNote(1, '', 'Title', 2);
        $this->noteService->method('findAll')->willReturn([$note]);

        $this->contactsManager->expects($this->never())->method('search');

        $result = $this->makeWidget()->getItemsV2('alice');
        $this->assertSame('Task', $result->getItems()[0]->getSubtitle());
    }

    public function testSubtitleOmitsContactWhenUnresolvable(): void {
        $this->noteTypeService->method('findAll')->willReturn([$this->makeNoteType(2, 'Task')]);

        $note = $this->makeNote(1, 'ghost-uid', 'Title', 2);
        $this->noteService->method('findAll')->willReturn([$note]);

        $this->contactsManager->method('search')->willReturn([]);

        $result = $this->makeWidget()->getItemsV2('alice');
        $this->assertSame('Task', $result->getItems()[0]->getSubtitle());
    }

    public function testSubtitleOmitsTypeWhenNoteTypeDeleted(): void {
        // Note references type id 99, which is absent from findAll()'s
        // result — i.e. deleted (or otherwise not visible to this user).
        $this->noteTypeService->method('findAll')->willReturn([]);

        $note = $this->makeNote(1, 'contact-abc', 'Title', 99);
        $this->noteService->method('findAll')->willReturn([$note]);

        $this->contactsManager->method('search')->willReturn([
            ['UID' => 'contact-abc', 'FN' => 'Jane Doe'],
        ]);

        $result = $this->makeWidget()->getItemsV2('alice');
        $this->assertSame('Jane Doe', $result->getItems()[0]->getSubtitle());
    }

    public function testSubtitleOmitsTypeWhenNoteTypeIdIsZero(): void {
        $this->noteTypeService->expects($this->once())->method('findAll')->willReturn([]);

        $note = $this->makeNote(1, 'contact-abc', 'Title', 0);
        $this->noteService->method('findAll')->willReturn([$note]);

        $this->contactsManager->method('search')->willReturn([
            ['UID' => 'contact-abc', 'FN' => 'Jane Doe'],
        ]);

        $result = $this->makeWidget()->getItemsV2('alice');
        $this->assertSame('Jane Doe', $result->getItems()[0]->getSubtitle());
    }

    public function testSubtitleEmptyWhenBothContactAndTypeUnavailable(): void {
        $note = $this->makeNote(1, '', 'Title', 0);
        $this->noteService->method('findAll')->willReturn([$note]);

        $result = $this->makeWidget()->getItemsV2('alice');
        $this->assertSame('', $result->getItems()[0]->getSubtitle());
    }

    public function testSubtitleIsTruncatedWhenContactAndTypeNamesAreLong(): void {
        $longContactName = str_repeat('a', 80);
        $longTypeName = str_repeat('b', 80);

        $this->noteTypeService->method('findAll')->willReturn([$this->makeNoteType(5, $longTypeName)]);

        $note = $this->makeNote(1, 'contact-abc', 'Title', 5);
        $this->noteService->method('findAll')->willReturn([$note]);

        $this->contactsManager->method('search')->willReturn([
            ['UID' => 'contact-abc', 'FN' => $longContactName],
        ]);

        $result = $this->makeWidget()->getItemsV2('alice');
        $subtitle = $result->getItems()[0]->getSubtitle();

        $this->assertSame(61, mb_strlen($subtitle, 'UTF-8')); // 60 chars + ellipsis
        $this->assertStringEndsWith('…', $subtitle);
        $this->assertStringStartsWith(str_repeat('a', 60), $subtitle);
    }

    // ── Pinned notes ─────────────────────────────────────────────────────────

    public function testPinnedNoteIsMovedAheadOfNewerUnpinnedNotes(): void {
        $unpinned = $this->makeNote(1, '', 'Newer unpinned', 0);
        $pinned = $this->makeNote(2, '', 'Older pinned', 0);
        $pinned->setIsPinned(true);

        // findAll() already returns newest-first: unpinned (newer) before
        // pinned (older) — the widget must still surface the pinned one first.
        $this->noteService->method('findAll')->willReturn([$unpinned, $pinned]);

        $result = $this->makeWidget()->getItemsV2('alice');
        $items = $result->getItems();

        $this->assertCount(2, $items);
        $this->assertSame('Older pinned', $items[0]->getTitle());
        $this->assertSame('Newer unpinned', $items[1]->getTitle());
    }

    public function testPinnedNoteCarriesOverlayIconUrl(): void {
        $pinned = $this->makeNote(1, '', 'Pinned', 0);
        $pinned->setIsPinned(true);
        $this->noteService->method('findAll')->willReturn([$pinned]);

        $result = $this->makeWidget()->getItemsV2('alice');

        $this->assertSame(
            'https://nc.test/apps/touchpoint/img/pin-badge-light.svg',
            $result->getItems()[0]->getOverlayIconUrl(),
        );
    }

    public function testUnpinnedNoteHasNoOverlayIconUrl(): void {
        $note = $this->makeNote(1, '', 'Not pinned', 0);
        $this->noteService->method('findAll')->willReturn([$note]);

        $result = $this->makeWidget()->getItemsV2('alice');

        $this->assertSame('', $result->getItems()[0]->getOverlayIconUrl());
    }

    // ── Deep link ────────────────────────────────────────────────────────────

    /**
     * Widget items link straight to the note itself (#note/<id>), matching
     * App.vue's applyNoteDeepLink() / the note_shared/note_mention
     * notification deep-link convention — not to the contact's entire note
     * history. This holds even when the note has no linked contact, since the
     * link no longer depends on contactUid at all.
     */
    public function testLinkPointsToNoteDeepLinkWhenNoContact(): void {
        $note = $this->makeNote(1, '', 'Title', 0);
        $this->noteService->method('findAll')->willReturn([$note]);

        $result = $this->makeWidget()->getItemsV2('alice');
        $this->assertSame('https://nc.test/apps/touchpoint/#note/1', $result->getItems()[0]->getLink());
    }

    public function testLinkPointsToNoteDeepLinkWithContact(): void {
        $note = $this->makeNote(42, 'uid with spaces', 'Title', 0);
        $this->noteService->method('findAll')->willReturn([$note]);

        $result = $this->makeWidget()->getItemsV2('alice');
        $this->assertSame('https://nc.test/apps/touchpoint/#note/42', $result->getItems()[0]->getLink());
    }

    // ── Session guard ────────────────────────────────────────────────────────

    public function testMismatchedSessionUserReturnsNoItems(): void {
        $session = $this->makeSessionForUser('bob');
        $this->noteService->expects($this->never())->method('findAll');

        $result = $this->makeWidget($session)->getItemsV2('alice');
        $this->assertSame([], $result->getItems());
    }

    public function testMatchingSessionUserFetchesNotes(): void {
        $session = $this->makeSessionForUser('alice');
        $this->noteService->expects($this->once())
            ->method('findAll')
            ->with('alice', 7, 0, 'newest')
            ->willReturn([]);

        $this->makeWidget($session)->getItemsV2('alice');
    }

    public function testNotLoggedInSessionReturnsNoItems(): void {
        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturn(null);

        $this->noteService->expects($this->never())->method('findAll');

        $result = $this->makeWidget($session)->getItemsV2('alice');
        $this->assertSame([], $result->getItems());
    }

    // ── Contract checks (merged from the former RecentNotesWidgetVerificationTest) ──

    /**
     * AC: IWidget::getOrder() -> int (sorting weight). No specific value is
     * mandated by the spec beyond "an int usable for ordering" — verify the
     * contract is honoured (an actual int, not a float/string/null that
     * would violate the declared return type at runtime under weak typing
     * edge cases, and not something absurd like a negative that would sort
     * before every other first-party widget without a documented reason).
     * The widget documents 10 as sitting below Nextcloud core's first-party
     * widgets (private DASHBOARD_ORDER const) — assert the actual value
     * rather than just "non-negative" so a silent drift is caught.
     */
    public function testGetOrderReturnsDocumentedValue(): void {
        $order = $this->makeWidget()->getOrder();
        $this->assertIsInt($order);
        $this->assertSame(10, $order);
    }

    /**
     * AC (IWidget::getIconClass() doc): "should be colored black or not have
     * a color" — many first-party widgets simply return '' when they supply
     * getIconUrl() instead (as this widget does per IAPIWidgetV2's icon
     * fields). Confirm it returns a string per the interface signature.
     */
    public function testGetIconClassReturnsString(): void {
        $this->assertIsString($this->makeWidget()->getIconClass());
    }

    /**
     * OCP\Dashboard\IAPIWidgetV2::getItemsV2() declares
     * `int $limit = 7` as its own interface-level default. The widget must
     * honour "up to 7 most recent notes" (CHANGELOG/API.md AC) when the
     * caller omits $limit entirely (not just when explicitly passed 7) —
     * i.e. the widget's internal default must match the interface default,
     * not silently diverge (e.g. defaulting to 10 or unlimited internally).
     */
    public function testDefaultLimitMatchesInterfaceDefaultOfSeven(): void {
        $this->noteService->expects($this->once())
            ->method('findAll')
            ->with('alice', 7, $this->anything(), $this->anything())
            ->willReturn([]);

        // Deliberately do not pass $limit — exercise the widget's own default.
        $this->makeWidget()->getItemsV2('alice');
    }

    /**
     * IAPIWidgetV2::getItemsV2(string $userId, ?string $since = null, ...)
     * — dashboard clients are free to pass a non-null $since (an opaque
     * cursor/timestamp per the interface's sinceId field). A widget that
     * does not implement incremental fetching must still not error out when
     * given one; it may ignore it, but must return a well-formed WidgetItems.
     */
    public function testNonNullSinceParameterDoesNotError(): void {
        $note = $this->makeNote(1, '', 'Title', 0);
        $this->noteService->method('findAll')->willReturn([$note]);

        $result = $this->makeWidget()->getItemsV2('alice', 'some-opaque-cursor', 7);

        $this->assertInstanceOf(WidgetItems::class, $result);
        $this->assertCount(1, $result->getItems());
    }

    /**
     * The dashboard API has no caller-supplied offset/page parameter — the
     * widget must always request the first page (offset 0) from
     * NoteService::findAll(), regardless of $limit. A regression that wires
     * $limit into the offset slot (or vice-versa) would silently skip a
     * user's most recent notes.
     */
    public function testOffsetIsAlwaysZeroRegardlessOfLimit(): void {
        $this->noteService->expects($this->once())
            ->method('findAll')
            ->with('alice', 3, 0, $this->anything())
            ->willReturn([]);

        $this->makeWidget()->getItemsV2('alice', null, 3);
    }

    /**
     * Sort order must be newest-first ("most recent notes") — verify the
     * literal sort keyword passed to NoteService::findAll() regardless of
     * limit/since, matching NoteMapper::SORT_NEWEST ('newest') used
     * elsewhere in the app (NoteSearchProvider, all-notes default view).
     */
    public function testSortOrderIsNewestFirst(): void {
        $this->noteService->expects($this->once())
            ->method('findAll')
            ->with('alice', $this->anything(), 0, 'newest')
            ->willReturn([]);

        $this->makeWidget()->getItemsV2('alice');
    }

    /**
     * AC: "up to 7 most recent notes" mapped to WidgetItem[]. Verify that
     * with several notes returned by NoteService::findAll() (already sorted
     * by the service per the requested sort), the widget preserves both the
     * count and the relative order when building WidgetItems — it must not
     * re-sort, reverse, deduplicate, or drop items during mapping.
     */
    public function testMultipleNotesPreserveCountAndOrder(): void {
        $notes = [
            $this->makeNote(1, '', 'First', 0),
            $this->makeNote(2, '', 'Second', 0),
            $this->makeNote(3, '', 'Third', 0),
        ];
        $this->noteService->method('findAll')->willReturn($notes);

        $result = $this->makeWidget()->getItemsV2('alice');
        $items = $result->getItems();

        $this->assertCount(3, $items);
        $this->assertSame('First', $items[0]->getTitle());
        $this->assertSame('Second', $items[1]->getTitle());
        $this->assertSame('Third', $items[2]->getTitle());
    }

    /**
     * Each note in a multi-note result must resolve its OWN contact/type
     * independently — a widget that reuses cached state across items
     * (e.g. a leftover contact-name variable) would misattribute a
     * subtitle from note A onto note B.
     */
    public function testMultipleNotesResolveDistinctSubtitlesIndependently(): void {
        $notes = [
            $this->makeNote(1, 'contact-a', 'Note A', 5),
            $this->makeNote(2, 'contact-b', 'Note B', 6),
        ];
        $this->noteService->method('findAll')->willReturn($notes);

        $this->noteTypeService->method('findAll')
            ->willReturn([
                $this->makeNoteType(5, 'Call'),
                $this->makeNoteType(6, 'Meeting'),
            ]);

        $this->contactsManager->method('search')
            ->willReturnCallback(function (string $pattern) {
                if ($pattern === 'contact-a') {
                    return [['UID' => 'contact-a', 'FN' => 'Alice Contact']];
                }
                if ($pattern === 'contact-b') {
                    return [['UID' => 'contact-b', 'FN' => 'Bob Contact']];
                }
                return [];
            });

        $result = $this->makeWidget()->getItemsV2('alice');
        $items = $result->getItems();

        $this->assertSame('Alice Contact · Call', $items[0]->getSubtitle());
        $this->assertSame('Bob Contact · Meeting', $items[1]->getSubtitle());
    }

    /**
     * WidgetItem's fields are consumed by the dashboard frontend as plain
     * text (there is no markdown/HTML rendering step for dashboard card
     * titles — unlike note bodies elsewhere in the app, per CLAUDE.md's
     * marked+DOMPurify note). A title containing characters that are
     * meaningful in HTML (e.g. `&`, `<`) must be passed through as the raw
     * string value from the Note entity — the widget must not HTML-entity-
     * encode it itself (that would double-encode once the dashboard client
     * does its own escaping when inserting into the DOM), and must not
     * strip it either.
     */
    public function testTitleWithHtmlMetacharactersPassesThroughUnescaped(): void {
        $note = $this->makeNote(1, '', 'Q&A <urgent>', 0);
        $this->noteService->method('findAll')->willReturn([$note]);

        $result = $this->makeWidget()->getItemsV2('alice');

        $this->assertSame('Q&A <urgent>', $result->getItems()[0]->getTitle());
    }

    /**
     * Note-type resolution is scoped to the *viewing* user ($userId from
     * getItemsV2), not the note's own owner — relevant because a shared
     * note's noteTypeId belongs to the original owner's type table, and
     * CLAUDE.md flags cross-user IDOR as the historically weak area here.
     * NoteTypeService::findAll($userId) is called with the viewing user's id
     * (never the note owner's), and a type id absent from that result (e.g.
     * because it's a non-default type owned by someone else) is treated the
     * same as "deleted" — the subtitle silently omits the type rather than
     * disclosing another user's custom type name.
     */
    public function testNoteTypeLookupScopedToViewingUserNotNoteOwner(): void {
        $note = $this->makeNote(1, '', 'Shared note', 42);
        $this->noteService->method('findAll')->willReturn([$note]);

        $this->noteTypeService->expects($this->once())
            ->method('findAll')
            ->with('alice')
            ->willReturn([$this->makeNoteType(42, 'Custom')]);

        $items = $this->makeWidget()->getItemsV2('alice')->getItems();
        $this->assertSame('Custom', $items[0]->getSubtitle());
    }

    // ── Error boundary ───────────────────────────────────────────────────────

    /**
     * Nextcloud core's DashboardApiController::getWidgetItemsV2() loops over
     * every installed app's registered widget in a single request with NO
     * per-widget try/catch — an uncaught exception from NoteService::findAll()
     * would 500 the ENTIRE /api/v2/widget-items batch response, breaking
     * every other app's dashboard widget on the page, not just this one.
     * getItemsV2() must catch it, log, and degrade to an empty WidgetItems.
     */
    public function testFindAllExceptionIsCaughtAndReturnsEmptyWidgetItems(): void {
        $this->noteService->method('findAll')->willThrowException(new RuntimeException('DB unavailable'));

        $this->logger->expects($this->once())->method('warning');

        $result = $this->makeWidget()->getItemsV2('alice');

        $this->assertInstanceOf(WidgetItems::class, $result);
        $this->assertSame([], $result->getItems());
        $this->assertNotSame('', $result->getEmptyContentMessage());
    }

    /**
     * Same error-boundary guarantee for the note-type batch lookup
     * (NoteTypeService::findAll()), which runs after notes are fetched but
     * before mapping to WidgetItem[].
     */
    public function testNoteTypeServiceExceptionIsCaughtAndReturnsEmptyWidgetItems(): void {
        $note = $this->makeNote(1, '', 'Title', 5);
        $this->noteService->method('findAll')->willReturn([$note]);
        $this->noteTypeService->method('findAll')->willThrowException(new RuntimeException('LDAP timeout'));

        $this->logger->expects($this->once())->method('warning');

        $result = $this->makeWidget()->getItemsV2('alice');

        $this->assertSame([], $result->getItems());
    }

    /**
     * Same error-boundary guarantee for the per-note contact resolution
     * (IContactsManager::search(), a CardDAV/LDAP-backed lookup that can
     * time out or fail independently of the note/note-type DB queries).
     */
    public function testContactsManagerExceptionIsCaughtAndReturnsEmptyWidgetItems(): void {
        $note = $this->makeNote(1, 'contact-abc', 'Title', 0);
        $this->noteService->method('findAll')->willReturn([$note]);
        $this->contactsManager->method('search')->willThrowException(new RuntimeException('CardDAV backend down'));

        $this->logger->expects($this->once())->method('warning');

        $result = $this->makeWidget()->getItemsV2('alice');

        $this->assertSame([], $result->getItems());
    }
}

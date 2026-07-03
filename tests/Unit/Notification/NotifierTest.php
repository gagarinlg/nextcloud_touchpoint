<?php

// SPDX-FileCopyrightText: 2026 Touchpoint Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Touchpoint\Tests\Unit\Notification;

use OCA\Touchpoint\Notification\Notifier;
use OCA\Touchpoint\Service\NoteService;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Notification\AlreadyProcessedException;
use OCP\Notification\INotification;
use OCP\Notification\UnknownNotificationException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Notifier.
 *
 * Covers acceptance criteria:
 * 1. getID() === 'touchpoint', getName() returns translated 'Touchpoint'.
 * 2. prepare() handles 'note_shared' and 'note_mention'.
 * 3. 'note_shared': parsed subject '{user} shared a note with you' (or, with a
 *    note title, '{user} shared the note "{title}" with you'), rich subject
 *    with a 'user' parameter, an icon, and a link to the deep-link URL
 *    '#note/{noteId}'.
 * 4. 'note_mention': parsed subject '{user} mentioned you in a note' (or with
 *    title), similar rich subject, same deep-link pattern.
 * 5. IURLGenerator builds absolute deep-link URLs with rawurlencode.
 * 6. Unknown app or unknown subject -> UnknownNotificationException.
 * 7. A note that no longer exists/is no longer accessible to the recipient ->
 *    AlreadyProcessedException (self-cleanup contract).
 */
class NotifierTest extends TestCase {

    /** @var IFactory&MockObject */
    private IFactory $l10nFactory;
    /** @var IURLGenerator&MockObject */
    private IURLGenerator $urlGenerator;
    /** @var IUserManager&MockObject */
    private IUserManager $userManager;
    /** @var NoteService&MockObject */
    private NoteService $noteService;
    /** @var IL10N&MockObject */
    private IL10N $l10n;

    protected function setUp(): void {
        $this->l10nFactory = $this->createMock(IFactory::class);
        $this->urlGenerator = $this->createMock(IURLGenerator::class);
        $this->userManager = $this->createMock(IUserManager::class);
        $this->noteService = $this->createMock(NoteService::class);
        $this->l10n = $this->createMock(IL10N::class);

        // Passthrough translation: t('foo %s', ['bar']) -> 'foo bar'
        // (sprintf semantics), t('foo', []) -> 'foo'.
        $this->l10n->method('t')->willReturnCallback(
            function (string $text, $parameters = []): string {
                if ($parameters === [] || $parameters === null) {
                    return $text;
                }
                return vsprintf($text, is_array($parameters) ? $parameters : [$parameters]);
            }
        );

        $this->l10nFactory->method('get')->willReturn($this->l10n);

        $this->urlGenerator->method('linkToRoute')
            ->with('touchpoint.page.index')
            ->willReturn('/apps/touchpoint/');
        $this->urlGenerator->method('imagePath')
            ->with('touchpoint', 'app-dark.svg')
            ->willReturn('/apps/touchpoint/img/app-dark.svg');
        $this->urlGenerator->method('getAbsoluteURL')
            ->willReturnCallback(fn (string $url) => 'https://nc.test' . $url);

        // Default: the note is still accessible to whichever recipient asks.
        // Individual tests override this to exercise the AlreadyProcessedException path.
        $this->noteService->method('isAccessible')->willReturn(true);
        // Default: the note still exists (used by the note_mention self-cleanup
        // path, which checks existence only, not the recipient's access).
        // Individual tests override this to exercise the AlreadyProcessedException path.
        $this->noteService->method('noteExists')->willReturn(true);
    }

    private function makeNotifier(): Notifier {
        return new Notifier($this->l10nFactory, $this->urlGenerator, $this->userManager, $this->noteService);
    }

    /**
     * Build a mock INotification whose setters record their arguments and
     * whose fluent setters return $this, matching the real INotification
     * contract used by Notifier via method chaining.
     */
    /**
     * @return INotification&MockObject
     */
    private function makeNotification(string $app, string $subject, array $subjectParams, string $objectId = '', string $user = 'recipient'): INotification {
        $notification = $this->createMock(INotification::class);
        $notification->method('getApp')->willReturn($app);
        $notification->method('getSubject')->willReturn($subject);
        $notification->method('getSubjectParameters')->willReturn($subjectParams);
        $notification->method('getObjectId')->willReturn($objectId);
        $notification->method('getUser')->willReturn($user);

        foreach (['setParsedSubject', 'setRichSubject', 'setLink', 'setIcon'] as $fluentSetter) {
            $notification->method($fluentSetter)->willReturnSelf();
        }

        return $notification;
    }

    // ── AC 1: getID / getName ────────────────────────────────────────────────

    public function testGetIdReturnsTouchpoint(): void {
        $this->assertSame('touchpoint', $this->makeNotifier()->getID());
    }

    public function testGetNameReturnsTranslatedTouchpoint(): void {
        $l10n = $this->createMock(IL10N::class);
        $l10n->expects($this->once())
            ->method('t')
            ->with('Touchpoint')
            ->willReturn('Touchpoint-DE');

        $factory = $this->createMock(IFactory::class);
        $factory->expects($this->once())
            ->method('findLanguage')
            ->with(null)
            ->willReturn('de');
        $factory->expects($this->once())
            ->method('get')
            ->with('touchpoint', 'de')
            ->willReturn($l10n);

        $notifier = new Notifier($factory, $this->urlGenerator, $this->userManager, $this->noteService);
        $this->assertSame('Touchpoint-DE', $notifier->getName());
    }

    // ── AC 6: unknown app / subject ──────────────────────────────────────────

    public function testPrepareThrowsForUnknownApp(): void {
        $notifier = $this->makeNotifier();
        $notification = $this->makeNotification('other_app', Notifier::SUBJECT_NOTE_SHARED, []);

        $this->expectException(UnknownNotificationException::class);
        $notifier->prepare($notification, 'en');
    }

    public function testPrepareThrowsForUnknownSubject(): void {
        $notifier = $this->makeNotifier();
        $notification = $this->makeNotification('touchpoint', 'note_overdue', []);

        $this->expectException(UnknownNotificationException::class);
        $notifier->prepare($notification, 'en');
    }

    // ── AC 7: self-cleanup via AlreadyProcessedException ─────────────────────

    public function testPrepareThrowsAlreadyProcessedWhenNoteNoLongerAccessible(): void {
        // A fresh NoteService mock, NOT $this->noteService: setUp() already
        // registers an unconstrained isAccessible() => true stub, and PHPUnit
        // resolves an unconstrained ->method() stub to the FIRST one
        // registered — a second stub here would be silently ignored.
        $noteService = $this->createMock(NoteService::class);
        $noteService->method('isAccessible')->willReturn(false);
        $notifier = new Notifier($this->l10nFactory, $this->urlGenerator, $this->userManager, $noteService);
        $notification = $this->makeNotification(
            'touchpoint',
            Notifier::SUBJECT_NOTE_SHARED,
            ['noteId' => 42, 'actorUid' => 'alice'],
            '',
            'bob',
        );

        $this->expectException(AlreadyProcessedException::class);
        $notifier->prepare($notification, 'en');
    }

    public function testPrepareChecksAccessForTheNotificationRecipientNotTheActor(): void {
        $this->noteService->expects($this->once())
            ->method('isAccessible')
            ->with(42, 'bob')
            ->willReturn(true);

        $notifier = $this->makeNotifier();
        $this->userManager->method('getDisplayName')->willReturn('Alice');
        $notification = $this->makeNotification(
            'touchpoint',
            Notifier::SUBJECT_NOTE_SHARED,
            ['noteId' => 42, 'actorUid' => 'alice'],
            '',
            'bob',
        );

        $notifier->prepare($notification, 'en');
    }

    // ── note_mention self-cleanup is existence-only, NOT access-gated ────────
    // (regression coverage: @mention is deliberately usable on a recipient
    // with no prior share/ownership relationship to the note — see
    // docs/API.md's Notifications policy note. Gating the self-cleanup check
    // on the same NoteService::isAccessible() check used for note_shared would
    // delete the notification the very first time it renders for exactly that
    // intended "loop in a colleague who doesn't have access yet" case, before
    // the recipient ever sees it.)

    public function testPrepareNoteMentionSurvivesWhenRecipientHasNoAccessToNote(): void {
        // The recipient has no share/ownership relationship to the note, so
        // isAccessible() would return false — but noteExists() (existence-only)
        // reports true, since the note itself has not been deleted.
        $this->noteService->method('isAccessible')->willReturn(false);
        $this->noteService->method('noteExists')->willReturn(true);
        $this->userManager->method('getDisplayName')->with('alice')->willReturn('Alice');

        $notifier = $this->makeNotifier();
        $notification = $this->makeNotification(
            'touchpoint',
            Notifier::SUBJECT_NOTE_MENTION,
            ['noteId' => 42, 'actorUid' => 'alice'],
            '',
            'bob',
        );

        $notification->expects($this->once())
            ->method('setParsedSubject')
            ->with('Alice mentioned you in a note')
            ->willReturnSelf();

        // Must not throw AlreadyProcessedException.
        $notifier->prepare($notification, 'en');
    }

    public function testPrepareNoteMentionThrowsAlreadyProcessedWhenNoteActuallyDeleted(): void {
        // A fresh NoteService mock, NOT $this->noteService: PHPUnit resolves
        // an unconstrained ->method() stub to the FIRST one registered, and
        // setUp() already registers noteExists() => true on $this->noteService
        // (see class-level comment above for why re-stubbing it here would be
        // silently ignored).
        $noteService = $this->createMock(NoteService::class);
        $noteService->method('noteExists')->willReturn(false);
        $notifier = new Notifier($this->l10nFactory, $this->urlGenerator, $this->userManager, $noteService);

        $notification = $this->makeNotification(
            'touchpoint',
            Notifier::SUBJECT_NOTE_MENTION,
            ['noteId' => 42, 'actorUid' => 'alice'],
            '',
            'bob',
        );

        $this->expectException(AlreadyProcessedException::class);
        $notifier->prepare($notification, 'en');
    }

    public function testPrepareNoteMentionChecksExistenceNotFindForSelfCleanup(): void {
        // note_mention's SELF-CLEANUP decision must use existence only, never
        // the access-scoped find(). isAccessible() IS still called (once) —
        // not for self-cleanup, but to decide whether the title may be shown
        // (see testPrepareNoteMentionWithholdsTitleWhenAccessRevokedAfterMention
        // below) — so this test asserts call counts/args rather than
        // asserting isAccessible() is never invoked.
        $this->noteService->expects($this->once())
            ->method('noteExists')
            ->with(42)
            ->willReturn(true);
        $this->noteService->expects($this->once())
            ->method('isAccessible')
            ->with(42, 'bob')
            ->willReturn(true);
        $this->userManager->method('getDisplayName')->willReturn('Alice');

        $notifier = $this->makeNotifier();
        $notification = $this->makeNotification(
            'touchpoint',
            Notifier::SUBJECT_NOTE_MENTION,
            ['noteId' => 42, 'actorUid' => 'alice'],
            '',
            'bob',
        );

        $notifier->prepare($notification, 'en');
    }

    /**
     * Regression for the "stale title disclosure" fix: a mentioned user who
     * HAD access at dispatch time (so the note's title was persisted into the
     * notification's subject parameters) but whose access was subsequently
     * REVOKED before they read the still-outstanding notification must not
     * see the title on a later prepare() call — the notification must still
     * render (not self-clean, per the existence-only policy), just with the
     * title-less fallback wording.
     */
    public function testPrepareNoteMentionWithholdsTitleWhenAccessRevokedAfterMention(): void {
        // Fresh NoteService mock, NOT $this->noteService: setUp() already
        // registers an unconstrained isAccessible() => true stub, and PHPUnit
        // resolves an unconstrained ->method() stub to the FIRST one
        // registered — re-stubbing $this->noteService here would be silently
        // ignored (see class-level comments elsewhere in this file).
        // The note still exists (no self-clean), but the recipient's access
        // was revoked since the title was persisted at dispatch time.
        $noteService = $this->createMock(NoteService::class);
        $noteService->method('noteExists')->willReturn(true);
        $noteService->method('isAccessible')->willReturn(false);
        $notifier = new Notifier($this->l10nFactory, $this->urlGenerator, $this->userManager, $noteService);

        $this->userManager->method('getDisplayName')->with('alice')->willReturn('Alice');

        $notification = $this->makeNotification(
            'touchpoint',
            Notifier::SUBJECT_NOTE_MENTION,
            ['noteId' => 42, 'actorUid' => 'alice', 'noteTitle' => 'Confidential Q3 numbers'],
            '',
            'bob',
        );

        $notification->expects($this->once())
            ->method('setParsedSubject')
            ->with('Alice mentioned you in a note')
            ->willReturnSelf();

        // Must not throw AlreadyProcessedException — existence-only self-clean.
        $notifier->prepare($notification, 'en');
    }

    /**
     * Symmetric case: access still present -> the persisted title is shown,
     * confirming the fix does not withhold titles it shouldn't.
     */
    public function testPrepareNoteMentionShowsTitleWhenAccessStillPresent(): void {
        $this->noteService->method('noteExists')->willReturn(true);
        $this->noteService->method('isAccessible')->willReturn(true);
        $this->userManager->method('getDisplayName')->with('alice')->willReturn('Alice');

        $notifier = $this->makeNotifier();
        $notification = $this->makeNotification(
            'touchpoint',
            Notifier::SUBJECT_NOTE_MENTION,
            ['noteId' => 42, 'actorUid' => 'alice', 'noteTitle' => 'Kickoff call'],
            '',
            'bob',
        );

        $notification->expects($this->once())
            ->method('setParsedSubject')
            ->with('Alice mentioned you in the note "Kickoff call"')
            ->willReturnSelf();

        $notifier->prepare($notification, 'en');
    }

    // ── AC 2/3: note_shared ───────────────────────────────────────────────────

    public function testPrepareNoteSharedSetsParsedSubject(): void {
        $notifier = $this->makeNotifier();
        $this->userManager->method('getDisplayName')->with('alice')->willReturn('Alice');

        $notification = $this->makeNotification(
            'touchpoint',
            Notifier::SUBJECT_NOTE_SHARED,
            ['noteId' => 42, 'actorUid' => 'alice'],
        );

        $notification->expects($this->once())
            ->method('setParsedSubject')
            ->with('Alice shared a note with you')
            ->willReturnSelf();

        $result = $notifier->prepare($notification, 'en');
        $this->assertSame($notification, $result);
    }

    public function testPrepareNoteSharedIncludesNoteTitleWhenPresent(): void {
        $notifier = $this->makeNotifier();
        $this->userManager->method('getDisplayName')->with('alice')->willReturn('Alice');

        $notification = $this->makeNotification(
            'touchpoint',
            Notifier::SUBJECT_NOTE_SHARED,
            ['noteId' => 42, 'actorUid' => 'alice', 'noteTitle' => 'Q3 planning'],
        );

        $notification->expects($this->once())
            ->method('setParsedSubject')
            ->with('Alice shared the note "Q3 planning" with you')
            ->willReturnSelf();

        $notifier->prepare($notification, 'en');
    }

    public function testPrepareNoteSharedTruncatesLongNoteTitle(): void {
        $notifier = $this->makeNotifier();
        $this->userManager->method('getDisplayName')->willReturn('Alice');

        $longTitle = str_repeat('x', 100);
        $notification = $this->makeNotification(
            'touchpoint',
            Notifier::SUBJECT_NOTE_SHARED,
            ['noteId' => 42, 'actorUid' => 'alice', 'noteTitle' => $longTitle],
        );

        $notification->expects($this->once())
            ->method('setParsedSubject')
            ->with($this->callback(function (string $subject): bool {
                $this->assertLessThanOrEqual(120, mb_strlen($subject));
                $this->assertStringContainsString('…', $subject);
                return true;
            }))
            ->willReturnSelf();

        $notifier->prepare($notification, 'en');
    }

    public function testPrepareNoteSharedSetsRichSubjectWithUserParameter(): void {
        $notifier = $this->makeNotifier();
        $this->userManager->method('getDisplayName')->with('alice')->willReturn('Alice');

        $notification = $this->makeNotification(
            'touchpoint',
            Notifier::SUBJECT_NOTE_SHARED,
            ['noteId' => 42, 'actorUid' => 'alice'],
        );

        $notification->expects($this->once())
            ->method('setRichSubject')
            ->with(
                '{user} shared a note with you',
                $this->callback(function (array $params): bool {
                    $this->assertArrayHasKey('user', $params);
                    $this->assertSame('user', $params['user']['type']);
                    $this->assertSame('alice', $params['user']['id']);
                    $this->assertSame('Alice', $params['user']['name']);
                    return true;
                }),
            )
            ->willReturnSelf();

        $notifier->prepare($notification, 'en');
    }

    public function testPrepareNoteSharedSetsIcon(): void {
        $notifier = $this->makeNotifier();
        $this->userManager->method('getDisplayName')->willReturn('Alice');

        $notification = $this->makeNotification(
            'touchpoint',
            Notifier::SUBJECT_NOTE_SHARED,
            ['noteId' => 42, 'actorUid' => 'alice'],
        );

        $notification->expects($this->once())
            ->method('setIcon')
            ->with('https://nc.test/apps/touchpoint/img/app-dark.svg')
            ->willReturnSelf();

        $notifier->prepare($notification, 'en');
    }

    public function testPrepareNoteSharedSetsDeepLink(): void {
        $notifier = $this->makeNotifier();
        $this->userManager->method('getDisplayName')->willReturn('Alice');

        $notification = $this->makeNotification(
            'touchpoint',
            Notifier::SUBJECT_NOTE_SHARED,
            ['noteId' => 42, 'actorUid' => 'alice'],
        );

        $notification->expects($this->once())
            ->method('setLink')
            ->with('https://nc.test/apps/touchpoint/#note/42')
            ->willReturnSelf();

        $notifier->prepare($notification, 'en');
    }

    /**
     * Falls back to the raw UID when IUserManager has no display name (e.g.
     * user was deleted after the notification was queued).
     */
    public function testPrepareNoteSharedFallsBackToUidWhenNoDisplayName(): void {
        $notifier = $this->makeNotifier();
        $this->userManager->method('getDisplayName')->with('ghost')->willReturn(null);

        $notification = $this->makeNotification(
            'touchpoint',
            Notifier::SUBJECT_NOTE_SHARED,
            ['noteId' => 1, 'actorUid' => 'ghost'],
        );

        $notification->expects($this->once())
            ->method('setParsedSubject')
            ->with('ghost shared a note with you')
            ->willReturnSelf();

        $notifier->prepare($notification, 'en');
    }

    // ── AC 2/4: note_mention ──────────────────────────────────────────────────

    public function testPrepareNoteMentionSetsParsedSubject(): void {
        $notifier = $this->makeNotifier();
        $this->userManager->method('getDisplayName')->with('bob')->willReturn('Bob');

        $notification = $this->makeNotification(
            'touchpoint',
            Notifier::SUBJECT_NOTE_MENTION,
            ['noteId' => 7, 'actorUid' => 'bob'],
        );

        $notification->expects($this->once())
            ->method('setParsedSubject')
            ->with('Bob mentioned you in a note')
            ->willReturnSelf();

        $notifier->prepare($notification, 'en');
    }

    public function testPrepareNoteMentionIncludesNoteTitleWhenPresent(): void {
        $notifier = $this->makeNotifier();
        $this->userManager->method('getDisplayName')->with('bob')->willReturn('Bob');

        $notification = $this->makeNotification(
            'touchpoint',
            Notifier::SUBJECT_NOTE_MENTION,
            ['noteId' => 7, 'actorUid' => 'bob', 'noteTitle' => 'Kickoff call'],
        );

        $notification->expects($this->once())
            ->method('setParsedSubject')
            ->with('Bob mentioned you in the note "Kickoff call"')
            ->willReturnSelf();

        $notifier->prepare($notification, 'en');
    }

    public function testPrepareNoteMentionSetsRichSubjectWithUserParameter(): void {
        $notifier = $this->makeNotifier();
        $this->userManager->method('getDisplayName')->with('bob')->willReturn('Bob');

        $notification = $this->makeNotification(
            'touchpoint',
            Notifier::SUBJECT_NOTE_MENTION,
            ['noteId' => 7, 'actorUid' => 'bob'],
        );

        $notification->expects($this->once())
            ->method('setRichSubject')
            ->with(
                '{user} mentioned you in a note',
                $this->callback(function (array $params): bool {
                    $this->assertArrayHasKey('user', $params);
                    $this->assertSame('user', $params['user']['type']);
                    $this->assertSame('bob', $params['user']['id']);
                    $this->assertSame('Bob', $params['user']['name']);
                    return true;
                }),
            )
            ->willReturnSelf();

        $notifier->prepare($notification, 'en');
    }

    public function testPrepareNoteMentionSetsDeepLink(): void {
        $notifier = $this->makeNotifier();
        $this->userManager->method('getDisplayName')->willReturn('Bob');

        $notification = $this->makeNotification(
            'touchpoint',
            Notifier::SUBJECT_NOTE_MENTION,
            ['noteId' => 7, 'actorUid' => 'bob'],
        );

        $notification->expects($this->once())
            ->method('setLink')
            ->with('https://nc.test/apps/touchpoint/#note/7')
            ->willReturnSelf();

        $notifier->prepare($notification, 'en');
    }

    // ── AC 5: rawurlencode on the deep link ──────────────────────────────────

    /**
     * A noteId containing characters that rawurlencode() and urlencode() treat
     * differently (space -> %20 vs +) proves rawurlencode() is used, matching
     * the JS-side decodeURIComponent() convention used elsewhere in this app.
     */
    public function testDeepLinkUsesRawurlencodeNotUrlencode(): void {
        $notifier = $this->makeNotifier();
        $this->userManager->method('getDisplayName')->willReturn('Alice');

        $notification = $this->makeNotification(
            'touchpoint',
            Notifier::SUBJECT_NOTE_SHARED,
            ['noteId' => 'note id with spaces', 'actorUid' => 'alice'],
        );

        $notification->expects($this->once())
            ->method('setLink')
            ->with($this->callback(function (string $link): bool {
                $this->assertStringContainsString('%20', $link);
                $this->assertStringNotContainsString('+', $link);
                $this->assertStringContainsString('#note/note%20id%20with%20spaces', $link);
                return true;
            }))
            ->willReturnSelf();

        $notifier->prepare($notification, 'en');
    }

    /**
     * When subject parameters omit 'noteId' (defensive fallback), the object
     * id set via INotification::setObject() is used instead.
     */
    public function testFallsBackToObjectIdWhenNoteIdParameterMissing(): void {
        $notifier = $this->makeNotifier();
        $this->userManager->method('getDisplayName')->willReturn('Alice');

        $notification = $this->makeNotification(
            'touchpoint',
            Notifier::SUBJECT_NOTE_SHARED,
            ['actorUid' => 'alice'],
            '99',
        );

        $notification->expects($this->once())
            ->method('setLink')
            ->with('https://nc.test/apps/touchpoint/#note/99')
            ->willReturnSelf();

        $notifier->prepare($notification, 'en');
    }

    // ── prepare() passes the correct language code through ──────────────────

    public function testPrepareRequestsL10nForGivenLanguageCode(): void {
        $factory = $this->createMock(IFactory::class);
        $factory->expects($this->once())
            ->method('get')
            ->with('touchpoint', 'de')
            ->willReturn($this->l10n);

        $notifier = new Notifier($factory, $this->urlGenerator, $this->userManager, $this->noteService);
        $this->userManager->method('getDisplayName')->willReturn('Alice');

        $notification = $this->makeNotification(
            'touchpoint',
            Notifier::SUBJECT_NOTE_SHARED,
            ['noteId' => 1, 'actorUid' => 'alice'],
        );

        $notifier->prepare($notification, 'de');
    }
}

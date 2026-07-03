<?php

// SPDX-FileCopyrightText: 2026 Touchpoint Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Touchpoint\Tests\Unit\Notification;

use OCA\Touchpoint\Db\Note;
use OCA\Touchpoint\Db\NoteContactMapper;
use OCA\Touchpoint\Db\NoteFileMapper;
use OCA\Touchpoint\Db\NoteMapper;
use OCA\Touchpoint\Db\NoteSharingMapper;
use OCA\Touchpoint\Notification\NotificationService;
use OCA\Touchpoint\Notification\Notifier;
use OCA\Touchpoint\Service\NoteService;
use OCA\Touchpoint\Service\NoteTypeService;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\IRootFolder;
use OCP\IUserManager;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Black-box integration coverage for the note-sharing / @mention
 * notification dispatch pipeline.
 *
 * These tests exercise NoteService/NotificationService/Notifier purely
 * through their public constructors and methods (no implementation
 * internals read beyond public signatures), specifically targeting
 * behavioral contracts that are easy to satisfy vacuously if only tested
 * through mocks that already assume the answer:
 *
 *  - a bogus/non-existent share principal must not generate a
 *    notification, even though it is present in the raw `sharing` payload
 *    NoteService::create()/update() receive from the API layer;
 *  - a non-owner editor's content edit must still trigger @mention
 *    notifications (mentions are not restricted to the owner, unlike
 *    sharing management);
 *  - Notifier actually implements the OCP\Notification\INotifier contract
 *    (type-level, not just structurally similar);
 *  - the notifier is registered through the real
 *    IRegistrationContext::registerNotifierService() entry point.
 */
class NotificationDispatchIntegrationTest extends TestCase {

    private NoteMapper $mapper;
    private NoteContactMapper $noteContactMapper;
    private NoteFileMapper $noteFileMapper;
    private NoteSharingMapper $noteSharingMapper;
    /** @var \OCA\Touchpoint\Service\SettingsService&MockObject */
    private \OCA\Touchpoint\Service\SettingsService $settingsService;
    private NoteTypeService $noteTypeService;
    private IRootFolder $rootFolder;
    private LoggerInterface $logger;
    /** @var NotificationService&MockObject */
    private NotificationService $notificationService;
    private NoteService $service;

    protected function setUp(): void {
        $this->mapper = $this->createMock(NoteMapper::class);
        $this->noteContactMapper = $this->createMock(NoteContactMapper::class);
        $this->noteFileMapper = $this->createMock(NoteFileMapper::class);
        $this->noteSharingMapper = $this->createMock(NoteSharingMapper::class);
        $this->settingsService = $this->createMock(\OCA\Touchpoint\Service\SettingsService::class);
        $this->noteTypeService = $this->createMock(NoteTypeService::class);
        $this->rootFolder = $this->createMock(IRootFolder::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->notificationService = $this->createMock(NotificationService::class);

        $this->settingsService->method('isNotesPublic')->willReturn(false);
        $this->settingsService->method('getUserGroupIds')->willReturn([]);
        $this->settingsService->method('getUserShareTargets')->willReturn([]);
        $this->noteSharingMapper->method('findAccessibleNoteIds')->willReturn([]);
        $this->noteSharingMapper->method('findWritableNoteIds')->willReturn([]);
        $this->noteSharingMapper->method('findByNoteId')->willReturn([]);
        $this->noteSharingMapper->method('findByNoteIds')->willReturn([]);
        $this->noteContactMapper->method('findByNoteId')->willReturn([]);
        $this->noteContactMapper->method('findByNoteIds')->willReturn([]);
        $this->noteFileMapper->method('findByNoteId')->willReturn([]);
        $this->noteFileMapper->method('findByNoteIds')->willReturn([]);

        $this->service = new NoteService(
            $this->mapper,
            $this->noteContactMapper,
            $this->noteFileMapper,
            $this->noteSharingMapper,
            $this->settingsService,
            $this->noteTypeService,
            $this->rootFolder,
            $this->logger,
            $this->notificationService,
        );
    }

    // ── Gap 1: bogus share principal is filtered before notification ────────

    /**
     * A share-target payload naming a principal that does not exist (e.g. a
     * deleted user/group, or a client-forged id) must never reach
     * NotificationService — sanitisation of the sharing payload happens
     * before dispatch, not just "empty payload = no dispatch". This is the
     * behavioral contract "only real, valid new targets are notified", which
     * a test that stubs expandShareTargetsToUserIds() to return a canned
     * value cannot actually verify.
     */
    public function testCreateDoesNotNotifyForNonExistentSharePrincipal(): void {
        $this->mapper->method('insert')
            ->willReturnCallback(function (Note $n) {
                $n->setId(101);
                return $n;
            });
        $this->noteContactMapper->method('insert')->willReturnArgument(0);
        $this->settingsService->method('principalExists')->willReturn(false);

        $this->notificationService->expects($this->never())->method('expandShareTargetsToUserIds');
        $this->notificationService->expects($this->never())->method('sendShareNotification');

        $this->service->create(
            'uid',
            1,
            1,
            'Title',
            'Body',
            'user1',
            false,
            [],
            [['type' => 'user', 'id' => 'ghost-user', 'canEdit' => false]],
        );
    }

    /**
     * Same guarantee on update(): a newly-added target that fails
     * principal-existence validation must not be notified even though it is
     * present in the raw sharing array the caller supplied.
     */
    public function testUpdateDoesNotNotifyForNonExistentSharePrincipal(): void {
        $note = new Note();
        $note->setId(2);
        $note->setUserId('owner');

        $this->mapper->method('findById')->with(2, 'owner')->willReturn($note);
        $this->mapper->method('update')->willReturnArgument(0);
        $this->settingsService->method('principalExists')->willReturn(false);

        $this->notificationService->expects($this->never())->method('expandShareTargetsToUserIds');
        $this->notificationService->expects($this->never())->method('sendShareNotification');

        $this->service->update(
            2,
            'owner',
            null,
            null,
            null,
            null,
            null,
            [['type' => 'user', 'id' => 'phantom-group-member', 'canEdit' => false]],
        );
    }

    // ── Gap 2: mention notification is not gated by sharing-management rights ──

    /**
     * A write-share recipient (non-owner) editing note content must still
     * trigger @mention notifications for anyone newly mentioned — mentions
     * are a content-scanning concern, orthogonal to canManageSharing()'s
     * owner-only restriction on the sharing/ACL field. Fritz's suite only
     * exercises the mention-notify path with the note's owner as editor; this
     * closes the non-owner-editor variant.
     */
    public function testNonOwnerEditorMentionsStillNotify(): void {
        $note = new Note();
        $note->setId(3);
        $note->setUserId('owner');

        $this->mapper->method('findById')->willThrowException(new DoesNotExistException('nope'));
        $this->mapper->method('findByIdPublic')->with(3)->willReturn($note);
        $this->mapper->method('update')->willReturnArgument(0);

        $writableMapper = $this->createMock(NoteSharingMapper::class);
        $writableMapper->method('findWritableNoteIds')->willReturn([3]);
        $writableMapper->method('findAccessibleNoteIds')->willReturn([3]);
        $writableMapper->method('findByNoteId')->willReturn([]);
        $writableMapper->method('findByNoteIds')->willReturn([]);

        $service = new NoteService(
            $this->mapper,
            $this->noteContactMapper,
            $this->noteFileMapper,
            $writableMapper,
            $this->settingsService,
            $this->noteTypeService,
            $this->rootFolder,
            $this->logger,
            $this->notificationService,
        );

        $this->notificationService->expects($this->once())
            ->method('extractMentionedUserIds')
            ->with('please check @dana', 'editor')
            ->willReturn(['dana']);
        $this->notificationService->expects($this->once())
            ->method('sendMentionNotification')
            ->with(3, 'editor', 'dana');

        $service->update(3, 'editor', null, 'please check @dana');
    }

    // ── Gap 3: type-level contract checks ────────────────────────────────────

    /**
     * Notifier must actually implement OCP\Notification\INotifier — the real
     * interface the notification pipeline type-checks against when iterating
     * registered notifiers, not merely a class exposing similarly-named
     * methods.
     */
    public function testNotifierImplementsRealInotifierInterface(): void {
        $l10nFactory = $this->createMock(\OCP\L10N\IFactory::class);
        $urlGenerator = $this->createMock(\OCP\IURLGenerator::class);
        $userManager = $this->createMock(IUserManager::class);
        $noteService = $this->createMock(NoteService::class);

        $notifier = new Notifier($l10nFactory, $urlGenerator, $userManager, $noteService);

        $this->assertInstanceOf(INotifier::class, $notifier);
    }

    /**
     * Registration must go through the real
     * IRegistrationContext::registerNotifierService() entry point — the only
     * mechanism by which OCP\Notification\IManager discovers this app's
     * INotifier at runtime. A notifier class existing without this call is
     * silently never invoked; this is exactly the class of bug an
     * implementer's own test (spying on their own registration code) could
     * fail to catch if it asserted on the wrong method.
     */
    public function testApplicationRegistersNotifierThroughRealEntryPoint(): void {
        $context = $this->createMock(IRegistrationContext::class);

        $registered = [];
        $context->method('registerNotifierService')
            ->willReturnCallback(function (string $class) use (&$registered) {
                $registered[] = $class;
            });

        $app = new \OCA\Touchpoint\AppInfo\Application();
        $app->register($context);

        $this->assertContains(Notifier::class, $registered);
    }

    // ── Gap 4: deep-link format contract (through the public URL, end to end) ──

    /**
     * The link a real client receives for a note_shared notification must be
     * of the documented form ".../#note/{noteId}" with the literal noteId
     * value, decodable back to the same id — verified by decoding the
     * fragment rather than string-matching an expected literal, so the test
     * does not simply mirror the implementation's own encoding call.
     */
    public function testShareNotificationLinkDecodesBackToOriginalNoteId(): void {
        $l10nFactory = $this->createMock(\OCP\L10N\IFactory::class);
        $urlGenerator = $this->createMock(\OCP\IURLGenerator::class);
        $userManager = $this->createMock(IUserManager::class);
        $noteService = $this->createMock(NoteService::class);
        $noteService->method('isAccessible')->willReturn(true);
        $l10n = $this->createMock(\OCP\IL10N::class);

        $l10n->method('t')->willReturnCallback(
            fn (string $text, $params = []) => $params === [] ? $text : vsprintf($text, is_array($params) ? $params : [$params])
        );
        $l10nFactory->method('get')->willReturn($l10n);
        $urlGenerator->method('linkToRoute')->willReturn('/apps/touchpoint/');
        $urlGenerator->method('imagePath')->willReturn('/apps/touchpoint/img/app-dark.svg');
        $urlGenerator->method('getAbsoluteURL')->willReturnCallback(fn (string $u) => 'https://cloud.example.com' . $u);

        $notifier = new Notifier($l10nFactory, $urlGenerator, $userManager, $noteService);

        $noteId = 424242;
        $notification = $this->createMock(INotification::class);
        $notification->method('getApp')->willReturn('touchpoint');
        $notification->method('getUser')->willReturn('bob');
        $notification->method('getSubject')->willReturn(Notifier::SUBJECT_NOTE_SHARED);
        $notification->method('getSubjectParameters')->willReturn(['noteId' => $noteId, 'actorUid' => 'alice']);
        foreach (['setParsedSubject', 'setRichSubject', 'setIcon'] as $m) {
            $notification->method($m)->willReturnSelf();
        }

        $capturedLink = null;
        $notification->method('setLink')->willReturnCallback(function (string $link) use ($notification, &$capturedLink) {
            $capturedLink = $link;
            return $notification;
        });

        $notifier->prepare($notification, 'en');

        $this->assertNotNull($capturedLink);
        $fragment = parse_url($capturedLink, PHP_URL_FRAGMENT);
        $this->assertNotNull($fragment, 'Deep link must carry a URL fragment');
        $this->assertMatchesRegularExpression('#^note/\d+$#', $fragment);
        [, $encodedId] = explode('/', $fragment, 2);
        $this->assertSame($noteId, (int) rawurldecode($encodedId));
    }

    // ── Gap 5: delete() dismisses, but never dispatches, notifications ──────────

    /**
     * delete() must never dispatch a share/mention notification — dispatch is
     * scoped to create() and update(); there is no "note deleted" notification
     * subject. It does dismiss any outstanding notification for the deleted
     * note (see NotificationServiceTest::testDismissNoteNotificationsBuildsFilterAndCallsMarkProcessed
     * and NoteServiceTest::testDeleteDismissesOutstandingNotifications), which
     * is a distinct, non-dispatching operation asserted here for completeness.
     */
    public function testDeleteNeverDispatchesAnyNotification(): void {
        $note = new Note();
        $note->setId(4);
        $note->setUserId('user1');
        $note->setContent('cc @bob');

        $this->mapper->method('findById')->with(4, 'user1')->willReturn($note);
        $this->mapper->expects($this->once())->method('delete')->with($note);

        $this->notificationService->expects($this->never())->method('sendShareNotification');
        $this->notificationService->expects($this->never())->method('sendMentionNotification');
        $this->notificationService->expects($this->never())->method('extractMentionedUserIds');
        $this->notificationService->expects($this->once())->method('dismissNoteNotifications')->with(4);
        $this->notificationService->expects($this->never())->method('expandShareTargetsToUserIds');

        $this->service->delete(4, 'user1');
    }
}

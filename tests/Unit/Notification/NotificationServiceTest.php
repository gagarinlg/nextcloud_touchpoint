<?php

// SPDX-FileCopyrightText: 2026 Touchpoint Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Touchpoint\Tests\Unit\Notification;

use OCA\Touchpoint\Notification\NotificationService;
use OCA\Touchpoint\Notification\Notifier;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for NotificationService.
 *
 * Covers acceptance criteria:
 * 1. sendShareNotification(noteId, actorUserId, targetUserId) and
 *    sendMentionNotification(noteId, actorUserId, mentionedUserId).
 * 2. Uses OCP\Notification\INotificationManager to create + notify.
 * 3. app='touchpoint', user=targetUserId, object_type='note', object_id=noteId,
 *    subject='note_shared'/'note_mention' with actor parameter.
 * 7. Never notifies the actor themselves.
 * 8. Catches and logs exceptions from dispatch (non-fatal).
 * Plus the group-expansion and @mention-scanning helpers NoteService relies on.
 */
class NotificationServiceTest extends TestCase {

    /** @var INotificationManager&MockObject */
    private INotificationManager $notificationManager;
    /** @var IGroupManager&MockObject */
    private IGroupManager $groupManager;
    /** @var IUserManager&MockObject */
    private IUserManager $userManager;
    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;
    private NotificationService $service;

    protected function setUp(): void {
        $this->notificationManager = $this->createMock(INotificationManager::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->userManager = $this->createMock(IUserManager::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new NotificationService(
            $this->notificationManager,
            $this->groupManager,
            $this->userManager,
            $this->logger,
        );
    }

    /**
     * @return INotification&MockObject
     */
    private function makeFluentNotificationMock(): INotification {
        $notification = $this->createMock(INotification::class);
        foreach (['setApp', 'setUser', 'setObject', 'setSubject', 'setDateTime'] as $fluentSetter) {
            $notification->method($fluentSetter)->willReturnSelf();
        }
        return $notification;
    }

    // ── AC 1/2/3: sendShareNotification ──────────────────────────────────────

    public function testSendShareNotificationCreatesAndNotifiesWithCorrectShape(): void {
        $notification = $this->makeFluentNotificationMock();
        $this->notificationManager->method('createNotification')->willReturn($notification);

        $notification->expects($this->once())->method('setApp')->with('touchpoint')->willReturnSelf();
        $notification->expects($this->once())->method('setUser')->with('bob')->willReturnSelf();
        $notification->expects($this->once())->method('setObject')->with('note', '42')->willReturnSelf();
        $notification->expects($this->once())
            ->method('setSubject')
            ->with(Notifier::SUBJECT_NOTE_SHARED, ['noteId' => 42, 'actorUid' => 'alice', 'noteTitle' => ''])
            ->willReturnSelf();

        $this->notificationManager->expects($this->once())
            ->method('notify')
            ->with($notification);

        $this->service->sendShareNotification(42, 'alice', 'bob');
    }

    public function testSendShareNotificationPassesNoteTitleThrough(): void {
        $notification = $this->makeFluentNotificationMock();
        $this->notificationManager->method('createNotification')->willReturn($notification);

        $notification->expects($this->once())
            ->method('setSubject')
            ->with(Notifier::SUBJECT_NOTE_SHARED, ['noteId' => 42, 'actorUid' => 'alice', 'noteTitle' => 'Q3 planning'])
            ->willReturnSelf();

        $this->service->sendShareNotification(42, 'alice', 'bob', 'Q3 planning');
    }

    public function testSendShareNotificationSubjectIsNoteShared(): void {
        $this->assertSame('note_shared', Notifier::SUBJECT_NOTE_SHARED);
    }

    // ── AC 1/2/3: sendMentionNotification ────────────────────────────────────

    public function testSendMentionNotificationCreatesAndNotifiesWithCorrectShape(): void {
        $notification = $this->makeFluentNotificationMock();
        $this->notificationManager->method('createNotification')->willReturn($notification);

        $notification->expects($this->once())->method('setApp')->with('touchpoint')->willReturnSelf();
        $notification->expects($this->once())->method('setUser')->with('carol')->willReturnSelf();
        $notification->expects($this->once())->method('setObject')->with('note', '7')->willReturnSelf();
        $notification->expects($this->once())
            ->method('setSubject')
            ->with(Notifier::SUBJECT_NOTE_MENTION, ['noteId' => 7, 'actorUid' => 'alice', 'noteTitle' => ''])
            ->willReturnSelf();

        $this->notificationManager->expects($this->once())
            ->method('notify')
            ->with($notification);

        $this->service->sendMentionNotification(7, 'alice', 'carol');
    }

    // ── AC 7: never notify the actor themselves ──────────────────────────────

    public function testSendShareNotificationSkipsWhenTargetIsActor(): void {
        $this->notificationManager->expects($this->never())->method('createNotification');
        $this->notificationManager->expects($this->never())->method('notify');

        $this->service->sendShareNotification(1, 'alice', 'alice');
    }

    public function testSendMentionNotificationSkipsWhenMentionedIsActor(): void {
        $this->notificationManager->expects($this->never())->method('createNotification');
        $this->notificationManager->expects($this->never())->method('notify');

        $this->service->sendMentionNotification(1, 'alice', 'alice');
    }

    public function testSendShareNotificationSkipsBlankTarget(): void {
        $this->notificationManager->expects($this->never())->method('createNotification');
        $this->service->sendShareNotification(1, 'alice', '');
    }

    public function testSendMentionNotificationSkipsBlankMentioned(): void {
        $this->notificationManager->expects($this->never())->method('createNotification');
        $this->service->sendMentionNotification(1, 'alice', '');
    }

    // ── AC 8: exceptions are caught and logged, never propagate ─────────────

    public function testSendShareNotificationSwallowsAndLogsExceptionFromCreateNotification(): void {
        $this->notificationManager->method('createNotification')
            ->willThrowException(new \RuntimeException('boom'));

        $this->logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('note_shared'), $this->anything());

        // Must not throw.
        $this->service->sendShareNotification(1, 'alice', 'bob');
    }

    public function testSendShareNotificationSwallowsAndLogsExceptionFromNotify(): void {
        $notification = $this->makeFluentNotificationMock();
        $this->notificationManager->method('createNotification')->willReturn($notification);
        $this->notificationManager->method('notify')
            ->willThrowException(new \RuntimeException('boom'));

        $this->logger->expects($this->once())->method('warning');

        $this->service->sendShareNotification(1, 'alice', 'bob');
    }

    public function testSendMentionNotificationSwallowsAndLogsException(): void {
        $this->notificationManager->method('createNotification')
            ->willThrowException(new \RuntimeException('boom'));

        $this->logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('note_mention'), $this->anything());

        $this->service->sendMentionNotification(1, 'alice', 'bob');
    }

    // ── AC 4: expandShareTargetsToUserIds ─────────────────────────────────────

    public function testExpandShareTargetsPassesThroughUserTargets(): void {
        $result = $this->service->expandShareTargetsToUserIds(
            [['type' => 'user', 'id' => 'bob']],
            'alice',
        );
        $this->assertSame(['bob'], $result);
    }

    public function testExpandShareTargetsExpandsGroupToMembers(): void {
        $memberA = $this->createMock(IUser::class);
        $memberA->method('getUID')->willReturn('bob');
        $memberB = $this->createMock(IUser::class);
        $memberB->method('getUID')->willReturn('carol');

        $group = $this->createMock(IGroup::class);
        $group->method('getUsers')->willReturn([$memberA, $memberB]);

        $this->groupManager->method('get')->with('team-x')->willReturn($group);

        $result = $this->service->expandShareTargetsToUserIds(
            [['type' => 'group', 'id' => 'team-x']],
            'alice',
        );
        sort($result);
        $this->assertSame(['bob', 'carol'], $result);
    }

    public function testExpandShareTargetsNeverIncludesActor(): void {
        $memberA = $this->createMock(IUser::class);
        $memberA->method('getUID')->willReturn('alice');
        $memberB = $this->createMock(IUser::class);
        $memberB->method('getUID')->willReturn('bob');

        $group = $this->createMock(IGroup::class);
        $group->method('getUsers')->willReturn([$memberA, $memberB]);
        $this->groupManager->method('get')->willReturn($group);

        $result = $this->service->expandShareTargetsToUserIds(
            [['type' => 'user', 'id' => 'alice'], ['type' => 'group', 'id' => 'team-x']],
            'alice',
        );
        $this->assertSame(['bob'], $result);
    }

    public function testExpandShareTargetsDeduplicatesUserPresentDirectlyAndViaGroup(): void {
        $member = $this->createMock(IUser::class);
        $member->method('getUID')->willReturn('bob');

        $group = $this->createMock(IGroup::class);
        $group->method('getUsers')->willReturn([$member]);
        $this->groupManager->method('get')->willReturn($group);

        $result = $this->service->expandShareTargetsToUserIds(
            [['type' => 'user', 'id' => 'bob'], ['type' => 'group', 'id' => 'team-x']],
            'alice',
        );
        $this->assertSame(['bob'], $result);
    }

    public function testExpandShareTargetsSkipsUnknownGroup(): void {
        $this->groupManager->method('get')->willReturn(null);

        $result = $this->service->expandShareTargetsToUserIds(
            [['type' => 'group', 'id' => 'ghost-group']],
            'alice',
        );
        $this->assertSame([], $result);
    }

    public function testExpandShareTargetsSkipsBlankId(): void {
        $result = $this->service->expandShareTargetsToUserIds(
            [['type' => 'user', 'id' => '']],
            'alice',
        );
        $this->assertSame([], $result);
    }

    public function testExpandShareTargetsIgnoresUnknownType(): void {
        $result = $this->service->expandShareTargetsToUserIds(
            [['type' => 'circle', 'id' => 'whatever']],
            'alice',
        );
        $this->assertSame([], $result);
    }

    public function testExpandShareTargetsHandlesEmptyList(): void {
        $this->assertSame([], $this->service->expandShareTargetsToUserIds([], 'alice'));
    }

    public function testExpandShareTargetsLogsAndContinuesOnGroupLookupException(): void {
        $this->groupManager->method('get')->willThrowException(new \RuntimeException('db down'));
        $this->logger->expects($this->once())->method('warning');

        $result = $this->service->expandShareTargetsToUserIds(
            [['type' => 'group', 'id' => 'team-x']],
            'alice',
        );
        $this->assertSame([], $result);
    }

    // ── AC 6: extractMentionedUserIds ─────────────────────────────────────────

    public function testExtractMentionsReturnsExistingUsersOnly(): void {
        $this->userManager->method('userExists')->willReturnMap([
            ['bob', true],
            ['ghost', false],
        ]);

        $result = $this->service->extractMentionedUserIds('Hi @bob, please check with @ghost too', 'alice');
        $this->assertSame(['bob'], $result);
    }

    public function testExtractMentionsExcludesActor(): void {
        $this->userManager->method('userExists')->willReturn(true);

        $result = $this->service->extractMentionedUserIds('cc @alice @bob', 'alice');
        $this->assertSame(['bob'], $result);
    }

    public function testExtractMentionsDeduplicates(): void {
        $this->userManager->method('userExists')->willReturn(true);

        $result = $this->service->extractMentionedUserIds('@bob thanks @bob!', 'alice');
        $this->assertSame(['bob'], $result);
    }

    public function testExtractMentionsReturnsEmptyForNullOrBlankContent(): void {
        $this->assertSame([], $this->service->extractMentionedUserIds(null, 'alice'));
        $this->assertSame([], $this->service->extractMentionedUserIds('', 'alice'));
    }

    public function testExtractMentionsWithNoAtSignNeverCallsUserExists(): void {
        $this->userManager->expects($this->never())->method('userExists');
        $result = $this->service->extractMentionedUserIds('completely plain text without any mention', 'alice');
        $this->assertSame([], $result);
    }

    public function testExtractMentionsCapsAtMaxMentionsPerNote(): void {
        $this->userManager->method('userExists')->willReturn(true);

        $content = '';
        for ($i = 0; $i < 60; $i++) {
            $content .= '@user' . $i . ' ';
        }

        $result = $this->service->extractMentionedUserIds($content, 'alice');
        $this->assertCount(50, $result);
    }

    public function testExtractMentionsPreservesFirstOccurrenceOrder(): void {
        $this->userManager->method('userExists')->willReturn(true);

        $result = $this->service->extractMentionedUserIds('@carol then @bob then @carol again', 'alice');
        $this->assertSame(['carol', 'bob'], $result);
    }

    /**
     * Known limitation, pinned down rather than incidental: MENTION_PATTERN
     * allows '@' inside the captured token, so two mentions with no separator
     * between them ("@alice@bob") parse as a single candidate "alice@bob"
     * rather than two mentions. That candidate does not resolve via
     * userExists(), so both intended mentions are silently dropped.
     */
    public function testExtractMentionsAdjacentMentionsWithNoSeparatorAreDropped(): void {
        $this->userManager->method('userExists')->willReturnMap([
            ['alice@bob', false],
            ['alice', true],
            ['bob', true],
        ]);

        $result = $this->service->extractMentionedUserIds('thanks @alice@bob', 'zoe');
        $this->assertSame([], $result);
    }

    // ── extractMentionedUserIds: distinct-candidate scan cap ─────────────────

    public function testExtractMentionsCapsDistinctCandidatesScannedEvenWhenAllInvalid(): void {
        $this->userManager->expects($this->atMost(200))->method('userExists')->willReturn(false);

        $content = '';
        for ($i = 0; $i < 3000; $i++) {
            $content .= '@nonexistent' . $i . ' ';
        }

        $result = $this->service->extractMentionedUserIds($content, 'alice');
        $this->assertSame([], $result);
    }

    // ── expandShareTargetsToUserIds: fan-out cap ──────────────────────────────

    // ── dismissNoteNotifications ──────────────────────────────────────────────

    public function testDismissNoteNotificationsBuildsFilterAndCallsMarkProcessed(): void {
        $notification = $this->makeFluentNotificationMock();
        $this->notificationManager->method('createNotification')->willReturn($notification);

        $notification->expects($this->once())->method('setApp')->with('touchpoint')->willReturnSelf();
        $notification->expects($this->once())->method('setObject')->with('note', '55')->willReturnSelf();

        $this->notificationManager->expects($this->once())
            ->method('markProcessed')
            ->with($notification);

        $this->service->dismissNoteNotifications(55);
    }

    public function testDismissNoteNotificationsSwallowsAndLogsException(): void {
        $this->notificationManager->method('createNotification')
            ->willThrowException(new \RuntimeException('boom'));

        $this->logger->expects($this->once())->method('warning');

        // Must not throw.
        $this->service->dismissNoteNotifications(55);
    }

    public function testExpandShareTargetsCapsGroupFanOutAndLogsWarning(): void {
        $members = [];
        for ($i = 0; $i < 300; $i++) {
            $member = $this->createMock(IUser::class);
            $member->method('getUID')->willReturn('user' . $i);
            $members[] = $member;
        }
        $group = $this->createMock(IGroup::class);
        $group->method('getUsers')->willReturn($members);
        $this->groupManager->method('get')->with('huge-group')->willReturn($group);

        $this->logger->expects($this->atLeastOnce())->method('warning');

        $result = $this->service->expandShareTargetsToUserIds(
            [['type' => 'group', 'id' => 'huge-group']],
            'alice',
        );

        $this->assertLessThanOrEqual(200, count($result));
    }

    /**
     * Regression: a direct 'user' target listed AFTER a large group in the
     * payload must survive the group-expansion cap. A direct user target has
     * a fixed cost of exactly one notify() call regardless of when it is
     * processed, so it must never be dropped purely because of its position
     * relative to an oversized group earlier in the array.
     */
    public function testExpandShareTargetsKeepsDirectUserTargetAfterLargeGroupOverflow(): void {
        $members = [];
        for ($i = 0; $i < 300; $i++) {
            $member = $this->createMock(IUser::class);
            $member->method('getUID')->willReturn('staff' . $i);
            $members[] = $member;
        }
        $group = $this->createMock(IGroup::class);
        $group->method('getUsers')->willReturn($members);
        $this->groupManager->method('get')->with('all-staff')->willReturn($group);

        $result = $this->service->expandShareTargetsToUserIds(
            [
                ['type' => 'group', 'id' => 'all-staff'],
                ['type' => 'user', 'id' => 'ceo'],
            ],
            'alice',
        );

        $this->assertContains('ceo', $result);
        $this->assertLessThanOrEqual(200, count($result));
    }
}

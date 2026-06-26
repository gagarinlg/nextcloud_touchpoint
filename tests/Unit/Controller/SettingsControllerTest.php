<?php

// SPDX-FileCopyrightText: 2026 CRM Notes Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\CrmNotes\Tests\Unit\Controller;

use OCA\CrmNotes\Controller\SettingsController;
use OCA\CrmNotes\Service\SettingsService;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

class SettingsControllerTest extends TestCase {

    private SettingsController $controller;
    private SettingsService $service;
    private IRequest $request;
    private IUserSession $userSession;
    private IGroupManager $groupManager;

    protected function setUp(): void {
        $this->request = $this->createMock(IRequest::class);
        $this->service = $this->createMock(SettingsService::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->groupManager = $this->createMock(IGroupManager::class);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('caller');
        $this->userSession->method('getUser')->willReturn($user);

        $this->controller = new SettingsController(
            $this->request,
            $this->service,
            $this->userSession,
            $this->groupManager,
        );
    }

    public function testSaveAppliesPublicFlagForAdminWhenExplicitlySent(): void {
        // An admin POSTing notesPublic=true must persist the instance-wide flag.
        $this->groupManager->method('isAdmin')->with('caller')->willReturn(true);
        $this->service->expects($this->once())
            ->method('setNotesPublic')
            ->with(true);
        $this->service->method('isNotesPublic')->willReturn(true);
        $this->service->method('getUserShareTargets')->willReturn([]);

        $response = $this->controller->save(true, null);
        $this->assertSame(['notesPublic' => true, 'isAdmin' => true, 'shareTargets' => []], $response->getData());
    }

    public function testSaveDoesNotTouchPublicFlagWhenOmittedByAdmin(): void {
        // GRUMPY DEV #1: an admin client that POSTs only shareTargets (no
        // notesPublic key) must NOT silently flip the instance-wide flag off.
        // notesPublic is now nullable and defaults to null, so an absent value
        // means "leave it alone".
        $this->groupManager->method('isAdmin')->with('caller')->willReturn(true);
        $this->service->expects($this->never())->method('setNotesPublic');
        $this->service->expects($this->once())
            ->method('setUserShareTargets')
            ->with('caller', [['type' => 'user', 'id' => 'bob']]);
        $this->service->method('isNotesPublic')->willReturn(true);
        $this->service->method('getUserShareTargets')->willReturn([]);

        $this->controller->save(null, [['type' => 'user', 'id' => 'bob']]);
    }

    public function testSaveIgnoresPublicFlagForNonAdmin(): void {
        // A non-admin may never change the instance-wide flag, even when sent.
        $this->groupManager->method('isAdmin')->with('caller')->willReturn(false);
        $this->service->expects($this->never())->method('setNotesPublic');
        $this->service->method('isNotesPublic')->willReturn(false);
        $this->service->method('getUserShareTargets')->willReturn([]);

        $this->controller->save(true, null);
    }

    public function testSearchPrincipalsRejectsShortQuery(): void {
        // A 1-character (or empty) query must never reach the service, so the
        // autocomplete cannot be walked to enumerate the directory.
        $this->service->expects($this->never())->method('searchPrincipals');

        $this->assertSame([], $this->controller->searchPrincipals('a')->getData());
        $this->assertSame([], $this->controller->searchPrincipals(' ')->getData());
        $this->assertSame([], $this->controller->searchPrincipals('')->getData());
    }

    public function testSearchPrincipalsRejectsSingleMultibyteChar(): void {
        // GRUMPY DEV #4.3: the guard counts characters, not bytes. A single
        // multibyte character (CJK / emoji) is one character and must be rejected
        // just like a single ASCII char — a byte-length check (strlen) would let
        // it through because it spans 2-4 bytes.
        $this->service->expects($this->never())->method('searchPrincipals');

        $this->assertSame([], $this->controller->searchPrincipals('好')->getData());
        $this->assertSame([], $this->controller->searchPrincipals('😀')->getData());
        $this->assertSame([], $this->controller->searchPrincipals(' 好 ')->getData());
    }

    public function testSearchPrincipalsAcceptsTwoMultibyteChars(): void {
        // Two multibyte characters clear the >= 2 character minimum and reach the
        // service.
        $expected = [['type' => 'user', 'id' => 'u', 'name' => 'U']];
        $this->service->expects($this->once())
            ->method('searchPrincipals')
            ->with('好友', 10, 'caller')
            ->willReturn($expected);

        $this->assertSame($expected, $this->controller->searchPrincipals('好友')->getData());
    }

    public function testSearchPrincipalsForwardsCallerUserId(): void {
        $expected = [['type' => 'user', 'id' => 'bob', 'name' => 'Bob']];
        // The caller's UID must be passed so the service can scope results to
        // shared groups under restrict-to-group.
        $this->service->expects($this->once())
            ->method('searchPrincipals')
            ->with('bob', 10, 'caller')
            ->willReturn($expected);

        $this->assertSame($expected, $this->controller->searchPrincipals('bob')->getData());
    }

    public function testSearchPrincipalsReturns401WhenUnauthenticated(): void {
        // GRUMPY DEV #4.1: searchPrincipals() resolves the caller's UID via the
        // RequiresUser trait, which throws UnauthenticatedException when the
        // session has no user. That exception must be funneled through
        // handleNotFound() to a clean 401, exactly like get()/save(), not escape
        // to the generic 500 handler.
        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturn(null);
        $service = $this->createMock(SettingsService::class);
        // The query is long enough to pass the length guard and reach getUserId().
        $service->expects($this->never())->method('searchPrincipals');

        $controller = new SettingsController(
            $this->request,
            $service,
            $session,
            $this->groupManager,
        );

        $response = $controller->searchPrincipals('bob');
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }
}

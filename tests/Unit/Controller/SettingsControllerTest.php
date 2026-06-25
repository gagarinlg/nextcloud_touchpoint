<?php

declare(strict_types=1);

namespace OCA\CrmNotes\Tests\Unit\Controller;

use OCA\CrmNotes\Controller\SettingsController;
use OCA\CrmNotes\Service\SettingsService;
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
}

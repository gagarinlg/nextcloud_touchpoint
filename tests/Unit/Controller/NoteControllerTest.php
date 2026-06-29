<?php

// SPDX-FileCopyrightText: 2026 Touchpoint Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Touchpoint\Tests\Unit\Controller;

use OCA\Touchpoint\Controller\NoteController;
use OCA\Touchpoint\Db\Note;
use OCA\Touchpoint\Db\NoteMapper;
use OCA\Touchpoint\Service\NoteNotFoundException;
use OCA\Touchpoint\Service\NoteService;
use OCP\AppFramework\Http\Http;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class NoteControllerTest extends TestCase {

    private NoteController $controller;
    private NoteService $service;
    private IRequest $request;
    private IUserSession $userSession;
    private IL10N $l10n;
    private LoggerInterface $logger;

    protected function setUp(): void {
        $this->request = $this->createMock(IRequest::class);
        $this->service = $this->createMock(NoteService::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->l10n = $this->createMock(IL10N::class);
        $this->l10n->method('t')->willReturnArgument(0);
        $this->logger = $this->createMock(LoggerInterface::class);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('testuser');
        $this->userSession->method('getUser')->willReturn($user);

        $this->controller = new NoteController(
            $this->request,
            $this->service,
            $this->userSession,
            $this->l10n,
            $this->logger,
        );
    }

    public function testIndex(): void {
        $notes = [new Note(), new Note()];

        // When no limit/offset are provided the controller defaults to 50/0 to
        // avoid returning the entire table.
        $this->request->method('getParam')->willReturn(null);

        $this->service->expects($this->once())
            ->method('findAll')
            ->with('testuser', 50, 0, 'newest')
            ->willReturn($notes);

        $result = $this->controller->index();
        $this->assertSame(200, $result->getStatus());
        $this->assertCount(2, $result->getData());
    }

    public function testIndexWithLimitAndOffset(): void {
        $this->request->method('getParam')
            ->willReturnCallback(fn (string $key, $default = null) => match ($key) {
                'limit' => '10',
                'offset' => '5',
                default => $default,
            });

        $this->service->expects($this->once())
            ->method('findAll')
            ->with('testuser', 10, 5, 'newest')
            ->willReturn([]);

        $result = $this->controller->index();
        $this->assertSame(200, $result->getStatus());
    }

    public function testIndexWithLimitOnly(): void {
        $this->request->method('getParam')
            ->willReturnCallback(fn (string $key, $default = null) => match ($key) {
                'limit' => '20',
                default => $default,
            });

        // offset defaults to 0 when absent.
        $this->service->expects($this->once())
            ->method('findAll')
            ->with('testuser', 20, 0, 'newest')
            ->willReturn([]);

        $this->controller->index();
    }

    public function testIndexClampsOversizedLimit(): void {
        // A caller cannot defeat the cap with ?limit=1000000 — it is clamped to
        // the 200-row maximum before reaching the service.
        $this->request->method('getParam')
            ->willReturnCallback(fn (string $key, $default = null) => match ($key) {
                'limit' => '1000000',
                default => $default,
            });

        $this->service->expects($this->once())
            ->method('findAll')
            ->with('testuser', 200, 0, 'newest')
            ->willReturn([]);

        $this->controller->index();
    }

    public function testIndexClampsNegativeLimitAndOffset(): void {
        // Negative/zero values are clamped to the [1, 200] / [0, ∞) ranges so a
        // negative offset or non-positive limit can never reach the service.
        $this->request->method('getParam')
            ->willReturnCallback(fn (string $key, $default = null) => match ($key) {
                'limit' => '-5',
                'offset' => '-10',
                default => $default,
            });

        $this->service->expects($this->once())
            ->method('findAll')
            ->with('testuser', 1, 0, 'newest')
            ->willReturn([]);

        $this->controller->index();
    }

    public function testIndexPassesOldestSort(): void {
        // ?sort=oldest is forwarded to the service verbatim.
        $this->request->method('getParam')
            ->willReturnCallback(fn (string $key, $default = null) => match ($key) {
                'sort' => 'oldest',
                default => $default,
            });

        $this->service->expects($this->once())
            ->method('findAll')
            ->with('testuser', 50, 0, 'oldest')
            ->willReturn([]);

        $this->controller->index();
    }

    public function testIndexRejectsInvalidSort(): void {
        // Any value other than 'oldest' is normalised to the 'newest' default, so
        // a bogus ?sort never reaches the service (and never the SQL direction).
        $this->request->method('getParam')
            ->willReturnCallback(fn (string $key, $default = null) => match ($key) {
                'sort' => 'sideways; DROP TABLE',
                default => $default,
            });

        $this->service->expects($this->once())
            ->method('findAll')
            ->with('testuser', 50, 0, 'newest')
            ->willReturn([]);

        $this->controller->index();
    }

    public function testByContactPassesOldestSort(): void {
        $this->request->method('getParam')
            ->willReturnCallback(fn (string $key, $default = null) => match ($key) {
                'sort' => 'oldest',
                default => $default,
            });

        $this->service->expects($this->once())
            ->method('findByContact')
            ->with('contact-uid', 'testuser', null, null, 'oldest')
            ->willReturn([]);

        $this->controller->byContact('contact-uid');
    }

    public function testShow(): void {
        $note = new Note();
        $note->setId(1);
        $note->setTitle('Test');

        $this->service->expects($this->once())
            ->method('find')
            ->with(1, 'testuser')
            ->willReturn($note);

        $result = $this->controller->show(1);
        $this->assertSame(200, $result->getStatus());
    }

    public function testShowNotFound(): void {
        $this->service->method('find')
            ->willThrowException(new NoteNotFoundException('Not found'));

        $result = $this->controller->show(999);
        $this->assertSame(Http::STATUS_NOT_FOUND, $result->getStatus());
    }

    public function testByContact(): void {
        // No paging params present: the controller passes null/null through and
        // lets the service apply its own defaults/clamp.
        $this->request->method('getParam')->willReturn(null);

        $notes = [new Note()];
        $this->service->expects($this->once())
            ->method('findByContact')
            ->with('contact-uid', 'testuser', null, null, 'newest')
            ->willReturn($notes);

        $result = $this->controller->byContact('contact-uid');
        $this->assertSame(200, $result->getStatus());
        $this->assertCount(1, $result->getData());
    }

    public function testByContactPassesLimitAndOffset(): void {
        // Paging params are forwarded to the service, which performs the clamp.
        $this->request->method('getParam')
            ->willReturnCallback(fn (string $key, $default = null) => match ($key) {
                'limit' => '25',
                'offset' => '50',
                default => $default,
            });

        $this->service->expects($this->once())
            ->method('findByContact')
            ->with('contact-uid', 'testuser', 25, 50, 'newest')
            ->willReturn([]);

        $result = $this->controller->byContact('contact-uid');
        $this->assertSame(200, $result->getStatus());
    }

    public function testByContactEmpty(): void {
        $this->request->method('getParam')->willReturn(null);
        $this->service->method('findByContact')->willReturn([]);

        $result = $this->controller->byContact('no-contact');
        $this->assertSame(200, $result->getStatus());
        $this->assertEmpty($result->getData());
    }

    public function testCreate(): void {
        $note = new Note();
        $note->setId(1);
        $note->setTitle('New note');

        // Service still receives args in (contactUid, addressbookId, noteTypeId,
        // title, content, userId, ...) order. The controller signature now takes
        // (contactUid, noteTypeId, title, addressbookId=0, content, ...) so the
        // unused addressbookId can be omitted by clients and defaults to 0.
        $this->service->expects($this->once())
            ->method('create')
            ->with('contact-123', 2, 3, 'New note', 'Content', 'testuser', false, [], null)
            ->willReturn($note);

        $result = $this->controller->create('contact-123', 3, 'New note', 2, 'Content');
        $this->assertSame(200, $result->getStatus());
    }

    public function testCreateWithPinned(): void {
        $note = new Note();
        $note->setId(1);

        $this->service->expects($this->once())
            ->method('create')
            ->with('uid', 1, 1, 'Pinned', null, 'testuser', true, [], null)
            ->willReturn($note);

        $result = $this->controller->create('uid', 1, 'Pinned', 1, null, true);
        $this->assertSame(200, $result->getStatus());
    }

    public function testCreateWithoutContactUidDefaultsToEmpty(): void {
        // A request that omits contactUid entirely must NOT raise a TypeError
        // (which would escape handleNotFound() as an opaque 500). contactUid now
        // defaults to '' so an absent primary contact flows through the normal
        // create() path; the service decides what an empty primary contact means.
        $note = new Note();
        $note->setId(1);

        $this->service->expects($this->once())
            ->method('create')
            ->with('', 0, 1, 'No primary contact', null, 'testuser', false, [], null)
            ->willReturn($note);

        // Call create() the way the AppFramework would when contactUid is absent:
        // every later param supplied, contactUid relying on its default.
        $result = $this->controller->create(noteTypeId: 1, title: 'No primary contact');
        $this->assertSame(200, $result->getStatus());
    }

    public function testUpdate(): void {
        $note = new Note();
        $note->setId(1);
        $note->setTitle('Updated');

        $this->service->expects($this->once())
            ->method('update')
            ->with(1, 'testuser', 'Updated', null, null, null, null, null)
            ->willReturn($note);

        $result = $this->controller->update(1, 'Updated');
        $this->assertSame(200, $result->getStatus());
    }

    public function testUpdateNotFound(): void {
        $this->service->method('update')
            ->willThrowException(new NoteNotFoundException('Not found'));

        $result = $this->controller->update(999, 'Title');
        $this->assertSame(Http::STATUS_NOT_FOUND, $result->getStatus());
    }

    public function testUpdateMultipleFields(): void {
        $note = new Note();
        $note->setId(1);

        $this->service->expects($this->once())
            ->method('update')
            ->with(1, 'testuser', 'Title', 'Content', 5, true, null, null)
            ->willReturn($note);

        $result = $this->controller->update(1, 'Title', 'Content', 5, true);
        $this->assertSame(200, $result->getStatus());
    }

    public function testDestroy(): void {
        $note = new Note();
        $note->setId(1);

        $this->service->expects($this->once())
            ->method('delete')
            ->with(1, 'testuser')
            ->willReturn($note);

        $result = $this->controller->destroy(1);
        $this->assertSame(200, $result->getStatus());
    }

    public function testDestroyNotFound(): void {
        $this->service->method('delete')
            ->willThrowException(new NoteNotFoundException('Not found'));

        $result = $this->controller->destroy(999);
        $this->assertSame(Http::STATUS_NOT_FOUND, $result->getStatus());
    }

    public function testNotFoundResponseContainsGenericMessage(): void {
        // The raw exception message is not leaked; a generic message is returned.
        $this->service->method('find')
            ->willThrowException(new NoteNotFoundException('Note with id 999 not found'));

        $result = $this->controller->show(999);
        $this->assertSame(Http::STATUS_NOT_FOUND, $result->getStatus());
        $this->assertSame('Not found', $result->getData()['message']);
    }

    public function testCreateWithContactUids(): void {
        $note = new Note();
        $note->setId(1);

        $this->service->expects($this->once())
            ->method('create')
            ->with('uid-1', 1, 1, 'Title', null, 'testuser', false, ['uid-1', 'uid-2'], null)
            ->willReturn($note);

        $result = $this->controller->create('uid-1', 1, 'Title', 1, null, false, ['uid-1', 'uid-2']);
        $this->assertSame(200, $result->getStatus());
    }

    public function testUpdateWithContactUids(): void {
        $note = new Note();
        $note->setId(1);

        $this->service->expects($this->once())
            ->method('update')
            ->with(1, 'testuser', 'Title', null, null, null, ['uid-a', 'uid-b'], null)
            ->willReturn($note);

        $result = $this->controller->update(1, 'Title', null, null, null, ['uid-a', 'uid-b']);
        $this->assertSame(200, $result->getStatus());
    }

    public function testAddFile(): void {
        $note = new Note();
        $note->setId(1);

        // filePath and fileId are read from the request body, not method args.
        $this->request->method('getParam')
            ->willReturnCallback(fn (string $key, $default = null) => match ($key) {
                'filePath' => '/Documents/file.pdf',
                'fileId' => '42',
                default => $default,
            });

        $this->service->expects($this->once())
            ->method('addFile')
            ->with(1, 42, '/Documents/file.pdf', 'testuser')
            ->willReturn($note);

        $result = $this->controller->addFile(1);
        $this->assertSame(200, $result->getStatus());
    }

    public function testAddFileNotFound(): void {
        $this->request->method('getParam')
            ->willReturnCallback(fn (string $key, $default = null) => match ($key) {
                'filePath' => '/test.txt',
                'fileId' => '1',
                default => $default,
            });

        $this->service->method('addFile')
            ->willThrowException(new NoteNotFoundException('Not found'));

        $result = $this->controller->addFile(999);
        $this->assertSame(Http::STATUS_NOT_FOUND, $result->getStatus());
    }

    public function testRemoveFile(): void {
        $note = new Note();
        $note->setId(1);

        $this->service->expects($this->once())
            ->method('removeFile')
            ->with(10, 1, 'testuser')
            ->willReturn($note);

        $result = $this->controller->removeFile(1, 10);
        $this->assertSame(200, $result->getStatus());
    }

    public function testRemoveFileNotFound(): void {
        $this->service->method('removeFile')
            ->willThrowException(new NoteNotFoundException('Not found'));

        $result = $this->controller->removeFile(999, 1);
        $this->assertSame(Http::STATUS_NOT_FOUND, $result->getStatus());
    }

    // ── search() ─────────────────────────────────────────────────────────────

    /**
     * q of exactly 500 chars (= MAX_SEARCH_TERM_LENGTH) must be accepted (200).
     */
    public function testSearchWith500CharQueryReturns200(): void {
        $q = str_repeat('a', NoteMapper::MAX_SEARCH_TERM_LENGTH);

        $this->request->method('getParam')
            ->willReturnCallback(fn (string $key, $default = null) => match ($key) {
                'q'      => $q,
                default  => $default,
            });

        $this->service->expects($this->once())
            ->method('search')
            ->willReturn([]);

        $result = $this->controller->search();
        $this->assertSame(200, $result->getStatus());
    }

    /**
     * q of 501 chars (> MAX_SEARCH_TERM_LENGTH) must return HTTP 400 with a
     * 'message' key in the JSON body.
     */
    public function testSearchWith501CharQueryReturns400(): void {
        $q = str_repeat('a', NoteMapper::MAX_SEARCH_TERM_LENGTH + 1);

        $this->request->method('getParam')
            ->willReturnCallback(fn (string $key, $default = null) => match ($key) {
                'q'      => $q,
                default  => $default,
            });

        // service must NOT be called — the controller rejects the request early.
        $this->service->expects($this->never())->method('search');

        $result = $this->controller->search();
        $this->assertSame(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $this->assertArrayHasKey('message', $result->getData());
    }

    /**
     * A crafted array-typed q (e.g. ?q[]=x) must NOT raise an E_WARNING via a
     * (string) cast (PHPUnit turns warnings into errors, so this test fails on
     * the unguarded cast). A non-string q degrades to an empty term → clean 200.
     */
    public function testSearchWithArrayQueryParamReturns200WithoutWarning(): void {
        $this->request->method('getParam')
            ->willReturnCallback(fn (string $key, $default = null) => match ($key) {
                'q'      => ['x'],   // attacker-crafted array-typed param
                default  => $default,
            });

        // q coerces to '' → the service is asked to search a blank term.
        $this->service->expects($this->once())
            ->method('search')
            ->with($this->anything(), '', $this->anything(), $this->anything(), $this->anything())
            ->willReturn([]);

        $result = $this->controller->search();
        $this->assertSame(200, $result->getStatus());
    }

    /**
     * The 400 error message must be wrapped through l10n->t() so non-English
     * instances receive a translated string.
     */
    public function testSearch400MessageIsTranslated(): void {
        $q = str_repeat('x', NoteMapper::MAX_SEARCH_TERM_LENGTH + 1);

        $this->request->method('getParam')
            ->willReturnCallback(fn (string $key, $default = null) => match ($key) {
                'q'      => $q,
                default  => $default,
            });

        // l10n mock returns its argument (already set in setUp).
        $result = $this->controller->search();
        $this->assertSame(Http::STATUS_BAD_REQUEST, $result->getStatus());
        // The message must be whatever l10n->t() returns — the mock returns its arg.
        $this->assertSame('Search query must not exceed 500 characters.', $result->getData()['message']);
    }

    /**
     * Absent/empty q must be accepted (200) and return an empty array — the
     * service returns [] for an empty term.
     */
    public function testSearchWithEmptyQReturns200AndEmptyArray(): void {
        $this->request->method('getParam')
            ->willReturnCallback(fn (string $key, $default = null) => match ($key) {
                'q'      => '',
                default  => $default,
            });

        $this->service->expects($this->once())
            ->method('search')
            ->willReturn([]);

        $result = $this->controller->search();
        $this->assertSame(200, $result->getStatus());
        $this->assertSame([], $result->getData());
    }

    /**
     * limit=500 must be clamped to 200 before reaching noteService->search().
     */
    public function testSearchClampsOversizedLimit(): void {
        $this->request->method('getParam')
            ->willReturnCallback(fn (string $key, $default = null) => match ($key) {
                'q'      => 'test',
                'limit'  => '500',
                default  => $default,
            });

        $this->service->expects($this->once())
            ->method('search')
            ->with('testuser', 'test', 200, 0, 'newest')
            ->willReturn([]);

        $result = $this->controller->search();
        $this->assertSame(200, $result->getStatus());
    }

    /**
     * The controller passes the trimmed q and correct paging args to
     * noteService->search().
     */
    public function testSearchPassesCorrectArgsToService(): void {
        $this->request->method('getParam')
            ->willReturnCallback(fn (string $key, $default = null) => match ($key) {
                'q'      => '  hello world  ',
                'limit'  => '10',
                'offset' => '20',
                default  => $default,
            });

        // Trimmed 'hello world', limit 10, offset 20, default sort 'newest'.
        $this->service->expects($this->once())
            ->method('search')
            ->with('testuser', 'hello world', 10, 20, 'newest')
            ->willReturn([]);

        $this->controller->search();
    }

    /**
     * Verify that #[UserRateLimit] attribute is present on the search() method.
     * This prevents the rate-limit from being silently dropped during refactoring.
     */
    public function testSearchMethodHasUserRateLimitAttribute(): void {
        $ref = new \ReflectionMethod(NoteController::class, 'search');
        $attrs = $ref->getAttributes(\OCP\AppFramework\Http\Attribute\UserRateLimit::class);
        $this->assertNotEmpty(
            $attrs,
            'NoteController::search() must carry the #[UserRateLimit] attribute',
        );
    }
}

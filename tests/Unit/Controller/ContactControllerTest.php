<?php

// SPDX-FileCopyrightText: 2026 CRM Notes Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\CrmNotes\Tests\Unit\Controller;

use OCA\CrmNotes\Controller\ContactController;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Contacts\IManager;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ContactControllerTest extends TestCase {

    private ContactController $controller;
    private IManager $contactsManager;
    private IRequest $request;
    private IURLGenerator $urlGenerator;
    private LoggerInterface $logger;

    protected function setUp(): void {
        $this->request = $this->createMock(IRequest::class);
        $this->contactsManager = $this->createMock(IManager::class);
        $this->urlGenerator = $this->createMock(IURLGenerator::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->urlGenerator->method('linkToRoute')
            ->willReturnCallback(fn (string $route, array $args = []) => '/route/' . ($args['uid'] ?? ''));

        $this->controller = new ContactController(
            $this->request,
            $this->contactsManager,
            $this->urlGenerator,
            $this->logger,
        );
    }

    public function testIndexReturnsContacts(): void {
        $this->request->method('getParam')
            ->with('term', '')
            ->willReturn('');

        $this->contactsManager->expects($this->once())
            ->method('search')
            ->with('', ['FN', 'EMAIL'], ['types' => true])
            ->willReturn([
                ['UID' => 'uid-1', 'FN' => 'Alice Smith', 'EMAIL' => 'alice@example.com', 'addressbook-key' => '1'],
                ['UID' => 'uid-2', 'FN' => 'Bob Jones', 'EMAIL' => 'bob@example.com', 'addressbook-key' => '2'],
            ]);

        $result = $this->controller->index();
        $this->assertInstanceOf(JSONResponse::class, $result);
        $data = $result->getData();
        $this->assertCount(2, $data);
        // Sorted alphabetically
        $this->assertSame('Alice Smith', $data[0]['name']);
        $this->assertSame('Bob Jones', $data[1]['name']);
    }

    public function testIndexSortsByName(): void {
        $this->request->method('getParam')->willReturn('');

        $this->contactsManager->method('search')
            ->willReturn([
                ['UID' => 'uid-z', 'FN' => 'Zara', 'EMAIL' => '', 'addressbook-key' => '1'],
                ['UID' => 'uid-a', 'FN' => 'Adam', 'EMAIL' => '', 'addressbook-key' => '1'],
                ['UID' => 'uid-m', 'FN' => 'Mike', 'EMAIL' => '', 'addressbook-key' => '1'],
            ]);

        $data = $this->controller->index()->getData();
        $this->assertSame('Adam', $data[0]['name']);
        $this->assertSame('Mike', $data[1]['name']);
        $this->assertSame('Zara', $data[2]['name']);
    }

    public function testIndexCaseInsensitiveSort(): void {
        $this->request->method('getParam')->willReturn('');

        $this->contactsManager->method('search')
            ->willReturn([
                ['UID' => 'uid-b', 'FN' => 'beta', 'EMAIL' => '', 'addressbook-key' => '1'],
                ['UID' => 'uid-a', 'FN' => 'Alpha', 'EMAIL' => '', 'addressbook-key' => '1'],
            ]);

        $data = $this->controller->index()->getData();
        $this->assertSame('Alpha', $data[0]['name']);
        $this->assertSame('beta', $data[1]['name']);
    }

    public function testIndexSkipsEntriesWithoutUid(): void {
        $this->request->method('getParam')->willReturn('');

        $this->contactsManager->method('search')
            ->willReturn([
                ['UID' => '', 'FN' => 'No UID', 'EMAIL' => 'x@y.com', 'addressbook-key' => '1'],
                ['FN' => 'Missing UID key', 'EMAIL' => 'x@y.com', 'addressbook-key' => '1'],
                ['UID' => 'valid', 'FN' => 'Valid', 'EMAIL' => 'v@y.com', 'addressbook-key' => '1'],
            ]);

        $data = $this->controller->index()->getData();
        $this->assertCount(1, $data);
        $this->assertSame('Valid', $data[0]['name']);
    }

    public function testIndexHandlesArrayEmail(): void {
        $this->request->method('getParam')->willReturn('');

        $this->contactsManager->method('search')
            ->willReturn([
                ['UID' => 'uid-1', 'FN' => 'Multi Email', 'EMAIL' => ['first@x.com', 'second@x.com'], 'addressbook-key' => '1'],
            ]);

        $data = $this->controller->index()->getData();
        $this->assertCount(1, $data);
        $this->assertSame('first@x.com', $data[0]['email']);
    }

    public function testIndexHandlesStringEmail(): void {
        $this->request->method('getParam')->willReturn('');

        $this->contactsManager->method('search')
            ->willReturn([
                ['UID' => 'uid-1', 'FN' => 'Single Email', 'EMAIL' => 'only@x.com', 'addressbook-key' => '2'],
            ]);

        $data = $this->controller->index()->getData();
        $this->assertSame('only@x.com', $data[0]['email']);
    }

    public function testIndexHandlesMissingEmail(): void {
        $this->request->method('getParam')->willReturn('');

        $this->contactsManager->method('search')
            ->willReturn([
                ['UID' => 'uid-1', 'FN' => 'No Email', 'addressbook-key' => '1'],
            ]);

        $data = $this->controller->index()->getData();
        $this->assertSame('', $data[0]['email']);
    }

    public function testIndexHandlesEmptyEmail(): void {
        $this->request->method('getParam')->willReturn('');

        $this->contactsManager->method('search')
            ->willReturn([
                ['UID' => 'uid-1', 'FN' => 'Empty Email', 'EMAIL' => '', 'addressbook-key' => '1'],
            ]);

        $data = $this->controller->index()->getData();
        $this->assertSame('', $data[0]['email']);
    }

    public function testIndexPassesSearchTerm(): void {
        $this->request->method('getParam')
            ->with('term', '')
            ->willReturn('alice');

        $this->contactsManager->expects($this->once())
            ->method('search')
            ->with('alice', ['FN', 'EMAIL'], ['types' => true])
            ->willReturn([]);

        $this->controller->index();
    }

    public function testIndexReturnsEmptyForNoContacts(): void {
        $this->request->method('getParam')->willReturn('');

        $this->contactsManager->method('search')->willReturn([]);

        $data = $this->controller->index()->getData();
        $this->assertCount(0, $data);
        $this->assertSame(200, $this->controller->index()->getStatus());
    }

    public function testIndexReturnsCorrectStructure(): void {
        $this->request->method('getParam')->willReturn('');

        $this->contactsManager->method('search')
            ->willReturn([
                ['UID' => 'abc-123', 'FN' => 'Test User', 'EMAIL' => 'test@x.com', 'addressbook-key' => '5'],
            ]);

        $data = $this->controller->index()->getData();
        $entry = $data[0];
        $this->assertArrayHasKey('uid', $entry);
        $this->assertArrayHasKey('name', $entry);
        $this->assertArrayHasKey('email', $entry);
        $this->assertArrayHasKey('photo', $entry);
        $this->assertArrayHasKey('addressbookKey', $entry);
        $this->assertSame('abc-123', $entry['uid']);
        $this->assertSame('Test User', $entry['name']);
        $this->assertSame('test@x.com', $entry['email']);
        $this->assertSame('5', $entry['addressbookKey']);
    }

    public function testIndexHandlesMissingFn(): void {
        $this->request->method('getParam')->willReturn('');

        $this->contactsManager->method('search')
            ->willReturn([
                ['UID' => 'uid-1', 'EMAIL' => 'x@y.com', 'addressbook-key' => '1'],
            ]);

        $data = $this->controller->index()->getData();
        $this->assertSame('', $data[0]['name']);
    }

    public function testIndexHandlesMissingAddressbookKey(): void {
        $this->request->method('getParam')->willReturn('');

        $this->contactsManager->method('search')
            ->willReturn([
                ['UID' => 'uid-1', 'FN' => 'Test', 'EMAIL' => 'x@y.com'],
            ]);

        $data = $this->controller->index()->getData();
        $this->assertSame('', $data[0]['addressbookKey']);
    }

    public function testIndexHttpStatus(): void {
        $this->request->method('getParam')->willReturn('');
        $this->contactsManager->method('search')->willReturn([]);

        $result = $this->controller->index();
        $this->assertSame(200, $result->getStatus());
    }

    public function testIndexReturnsEmptyPhotoWhenContactHasNoStoredPhoto(): void {
        // Without a PHOTO property in the search result, the photo URL stays empty.
        $this->request->method('getParam')->willReturn('');

        $this->contactsManager->method('search')
            ->willReturn([
                ['UID' => 'uid-1', 'FN' => 'No Photo', 'EMAIL' => '', 'addressbook-key' => '1'],
            ]);

        $data = $this->controller->index()->getData();
        $this->assertSame('', $data[0]['photo']);
    }

    public function testIndexSetsPhotoUrlWhenEntryHasPhoto(): void {
        // A PHOTO present on the search result — including for contacts living in a
        // SHARED address book that search() surfaces — yields a photo route URL.
        $this->request->method('getParam')->willReturn('');

        $this->contactsManager->method('search')
            ->willReturn([
                ['UID' => 'shared-uid', 'FN' => 'Shared Contact', 'EMAIL' => '', 'PHOTO' => 'data:image/png;base64,AAAA', 'addressbook-key' => '99'],
            ]);

        $data = $this->controller->index()->getData();
        $this->assertSame('/route/shared-uid', $data[0]['photo']);
    }

    public function testIndexPhotoEmptyWhenPhotoValueBlank(): void {
        $this->request->method('getParam')->willReturn('');

        $this->contactsManager->method('search')
            ->willReturn([
                ['UID' => 'uid-1', 'FN' => 'Blank Photo', 'EMAIL' => '', 'PHOTO' => '', 'addressbook-key' => '1'],
            ]);

        $data = $this->controller->index()->getData();
        $this->assertSame('', $data[0]['photo']);
    }

    public function testIndexPhotoFieldIsAlwaysPresent(): void {
        $this->request->method('getParam')->willReturn('');

        $this->contactsManager->method('search')
            ->willReturn([
                ['UID' => 'uid-1', 'FN' => 'Anyone', 'EMAIL' => '', 'addressbook-key' => '1'],
            ]);

        $data = $this->controller->index()->getData();
        $this->assertArrayHasKey('photo', $data[0]);
    }

    public function testPhotoReturns404WhenContactHasNoPhoto(): void {
        $this->contactsManager->method('search')
            ->with('missing-uid', ['UID'], ['types' => true])
            ->willReturn([]);

        $result = $this->controller->photo('missing-uid');
        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertSame(404, $result->getStatus());
    }

    public function testPhotoResolvesDataUriPhotoFromAnyAddressBook(): void {
        // A 1x1 transparent PNG as a data: URI — resolved via the contacts
        // manager, so it works for shared/group address books too.
        $png = base64_encode("\x89PNG\r\n\x1a\nrest");
        $this->contactsManager->method('search')
            ->with('shared-uid', ['UID'], ['types' => true])
            ->willReturn([
                ['UID' => 'shared-uid', 'PHOTO' => 'data:image/png;base64,' . $png],
            ]);

        $result = $this->controller->photo('shared-uid');
        $this->assertInstanceOf(DataDisplayResponse::class, $result);
        $this->assertSame(200, $result->getStatus());
        $this->assertSame('image/png', $result->getHeaders()['Content-Type']);
    }

    public function testPhotoReturns404ForEmptyUid(): void {
        $this->contactsManager->expects($this->never())->method('search');
        $result = $this->controller->photo('');
        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertSame(404, $result->getStatus());
    }

    // ── Content-Type hardening (GRUMPY DEV #1: stored XSS via PHOTO data: URI) ──

    public function testPhotoSetsInlineContentDisposition(): void {
        $png = base64_encode("\x89PNG\r\n\x1a\nrest");
        $this->contactsManager->method('search')
            ->willReturn([['UID' => 'uid', 'PHOTO' => 'data:image/png;base64,' . $png]]);

        $result = $this->controller->photo('uid');
        $headers = $result->getHeaders();
        $this->assertSame('inline; filename="photo"', $headers['Content-Disposition']);
        $this->assertSame('nosniff', $headers['X-Content-Type-Options']);
    }

    public function testPhotoIgnoresAttackerDeclaredHtmlMime(): void {
        // The data: URI claims text/html but the bytes are not a known image —
        // the served Content-Type must NEVER be the attacker-chosen text/html.
        $payload = base64_encode('<script>alert(1)</script>');
        $this->contactsManager->method('search')
            ->willReturn([['UID' => 'uid', 'PHOTO' => 'data:text/html;base64,' . $payload]]);

        $result = $this->controller->photo('uid');
        $this->assertInstanceOf(DataDisplayResponse::class, $result);
        $this->assertSame('application/octet-stream', $result->getHeaders()['Content-Type']);
    }

    public function testPhotoIgnoresAttackerDeclaredSvgMime(): void {
        // image/svg+xml is scriptable and must never be served as such.
        $payload = base64_encode('<svg xmlns="http://www.w3.org/2000/svg"><script>1</script></svg>');
        $this->contactsManager->method('search')
            ->willReturn([['UID' => 'uid', 'PHOTO' => 'data:image/svg+xml;base64,' . $payload]]);

        $result = $this->controller->photo('uid');
        $this->assertSame('application/octet-stream', $result->getHeaders()['Content-Type']);
    }

    public function testPhotoDerivesMimeFromBytesNotDeclaration(): void {
        // Declared image/gif but the bytes are a JPEG — the sniffed type wins.
        $jpeg = base64_encode("\xff\xd8\xff\xe0rest");
        $this->contactsManager->method('search')
            ->willReturn([['UID' => 'uid', 'PHOTO' => 'data:image/gif;base64,' . $jpeg]]);

        $result = $this->controller->photo('uid');
        $this->assertSame('image/jpeg', $result->getHeaders()['Content-Type']);
    }

    public function testPhotoDetectsWebp(): void {
        // RIFF + 4 size bytes + WEBP fourCC — the WebP magic on the allow-list.
        $raw = 'RIFF' . "\x10\x00\x00\x00" . 'WEBP' . 'VP8 ';
        $webp = base64_encode($raw);
        $this->contactsManager->method('search')
            ->willReturn([['UID' => 'uid', 'PHOTO' => 'data:image/webp;base64,' . $webp]]);

        $result = $this->controller->photo('uid');
        $this->assertSame('image/webp', $result->getHeaders()['Content-Type']);
    }

    public function testPhotoUnknownBinaryBecomesOctetStream(): void {
        // Raw (non-data-URI) binary that is not a recognised image format.
        $this->contactsManager->method('search')
            ->willReturn([['UID' => 'uid', 'PHOTO' => 'not-an-image-just-text']]);

        $result = $this->controller->photo('uid');
        $this->assertInstanceOf(DataDisplayResponse::class, $result);
        $this->assertSame('application/octet-stream', $result->getHeaders()['Content-Type']);
    }
}

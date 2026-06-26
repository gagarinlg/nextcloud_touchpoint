<?php

// SPDX-FileCopyrightText: 2026 CRM Notes Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\CrmNotes\Tests\Unit\Controller;

use OCA\CrmNotes\Controller\ContactController;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Contacts\IManager;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAddressBook;
use OCP\IDBConnection;
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
    private IDBConnection $db;

    protected function setUp(): void {
        $this->request = $this->createMock(IRequest::class);
        $this->contactsManager = $this->createMock(IManager::class);
        $this->urlGenerator = $this->createMock(IURLGenerator::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->db = $this->createMock(IDBConnection::class);

        $this->urlGenerator->method('linkToRoute')
            ->willReturnCallback(fn (string $route, array $args = []) => '/route/' . ($args['uid'] ?? ''));

        $this->controller = new ContactController(
            $this->request,
            $this->contactsManager,
            $this->urlGenerator,
            $this->logger,
            $this->db,
        );
    }

    /**
     * Wire getUserAddressBooks() to return books with the given keys (numeric →
     * readable by the photo lookup; non-numeric like 'system' → skipped).
     *
     * @param array<int|string> $keys
     */
    private function mockAddressBooks(array $keys): void {
        $books = [];
        foreach ($keys as $key) {
            $book = $this->createMock(IAddressBook::class);
            $book->method('getKey')->willReturn($key);
            $books[] = $book;
        }
        $this->contactsManager->method('getUserAddressBooks')->willReturn($books);
    }

    /**
     * Wire the DB query builder so the photo lookup (SELECT carddata FROM cards
     * WHERE uid = ? AND addressbookid IN (...)) returns the given carddata (or
     * null/false for "no row"). Records the addressbookid array bound via the IN
     * filter into $capturedIds so a test can assert the access scoping.
     *
     * @param array<int> $capturedIds  populated by reference with the bound ids
     */
    private function mockCardLookup(mixed $carddata, ?array &$capturedIds = null): void {
        $expr = $this->createMock(IExpressionBuilder::class);
        $expr->method('eq')->willReturn('eq_expr');
        $expr->method('in')->willReturn('in_expr');

        $qb = $this->createMock(IQueryBuilder::class);
        $qb->method('expr')->willReturn($expr);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setMaxResults')->willReturnSelf();
        $qb->method('createNamedParameter')
            ->willReturnCallback(function ($value, $type = null) use (&$capturedIds) {
                if ($capturedIds !== null && is_array($value)) {
                    $capturedIds = $value;
                }
                return 'param';
            });

        $result = new class($carddata) {
            public function __construct(private mixed $carddata) {
            }

            public function fetchOne(): mixed {
                return $this->carddata === null ? false : $this->carddata;
            }

            public function closeCursor(): void {
            }
        };
        $qb->method('executeQuery')->willReturn($result);

        $this->db->method('getQueryBuilder')->willReturn($qb);
    }

    /** Build a minimal vCard string carrying an embedded base64 PHOTO. */
    private function vcardWithPhoto(string $uid, string $rawImage, string $type = 'PNG'): string {
        return "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:" . $uid . "\r\n"
            . 'PHOTO;ENCODING=b;TYPE=' . $type . ':' . base64_encode($rawImage) . "\r\n"
            . "END:VCARD\r\n";
    }

    public function testIndexReturnsContacts(): void {
        $this->request->method('getParam')
            ->with('term', '')
            ->willReturn('');

        $this->contactsManager->expects($this->once())
            ->method('search')
            ->with('', ['FN', 'EMAIL', 'TEL', 'ORG'], ['types' => true, 'limit' => 10000])
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
            ->with('alice', ['FN', 'EMAIL', 'TEL', 'ORG'], ['types' => true, 'limit' => 10000])
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

    // ── Result-set bounding (GRUMPY DEV #1: unbounded contact search) ─────────

    public function testIndexPassesLimitOptionToSearch(): void {
        // index() must pass a 'limit' so the contacts backend can cap at source;
        // an empty term must not invite the backend to stream the whole directory.
        $this->request->method('getParam')->willReturn('');

        $this->contactsManager->expects($this->once())
            ->method('search')
            ->with('', ['FN', 'EMAIL', 'TEL', 'ORG'], ['types' => true, 'limit' => 10000])
            ->willReturn([]);

        $this->controller->index();
    }

    public function testIndexCapsMaterialisedResultsEvenIfBackendIgnoresLimit(): void {
        // If the contacts backend ignores 'limit' and hands back more than the
        // cap, index() must still materialise/return no more than MAX_SEARCH_RESULTS,
        // so an empty term can never flatten + photo-check the whole directory.
        $this->request->method('getParam')->willReturn('');

        // Hand back more rows than the cap (MAX_SEARCH_RESULTS = 10000) so the
        // test exercises the materialise ceiling, not just a smaller backend set.
        $big = [];
        for ($i = 0; $i < 10050; $i++) {
            $big[] = ['UID' => 'uid-' . $i, 'FN' => 'User ' . $i, 'EMAIL' => '', 'addressbook-key' => '1'];
        }
        $this->contactsManager->method('search')->willReturn($big);

        $data = $this->controller->index()->getData();
        $this->assertCount(10000, $data);
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
        // SHARED address book that search() surfaces — yields a photo route URL,
        // provided the book has a numeric (servable) key.
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

    // ── availability vs retrievability for the system/non-numeric book (GRUMPY DEV #2) ──

    public function testIndexDoesNotAdvertisePhotoForSystemBookEntry(): void {
        // A system-book entry (key 'system', isLocalSystemBook) carries a PHOTO,
        // but extractPhotoForUid() reads only numeric-key books — it can never
        // serve this one. index() must NOT emit a photo URL that would 404.
        $this->request->method('getParam')->willReturn('');

        $this->contactsManager->method('search')
            ->willReturn([
                [
                    'UID' => 'admin',
                    'FN' => 'Admin',
                    'EMAIL' => '',
                    'PHOTO' => 'data:image/png;base64,AAAA',
                    'addressbook-key' => 'system',
                    'isLocalSystemBook' => true,
                ],
            ]);

        $data = $this->controller->index()->getData();
        $this->assertSame('', $data[0]['photo']);
    }

    public function testIndexDoesNotAdvertisePhotoForNonNumericKeyBook(): void {
        // Any non-numeric address-book key is unservable by the photo endpoint
        // (the dav cards table is keyed by numeric addressbookid), so no URL.
        $this->request->method('getParam')->willReturn('');

        $this->contactsManager->method('search')
            ->willReturn([
                ['UID' => 'c1', 'FN' => 'NonNumeric', 'EMAIL' => '', 'PHOTO' => 'data:image/png;base64,AAAA', 'addressbook-key' => 'contacts-shared'],
            ]);

        $data = $this->controller->index()->getData();
        $this->assertSame('', $data[0]['photo']);
    }

    // ── photo() endpoint: reads embedded PHOTO from the cards table ───────────

    public function testPhotoReturns404WhenNoAccessibleAddressBooks(): void {
        // No readable address books → no access → 404 (and the card table is
        // never even queried).
        $this->mockAddressBooks([]);
        $this->db->expects($this->never())->method('getQueryBuilder');

        $result = $this->controller->photo('any-uid');
        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertSame(404, $result->getStatus());
    }

    public function testPhotoReturns404WhenCardNotFound(): void {
        $this->mockAddressBooks(['1', '2']);
        $this->mockCardLookup(null);

        $result = $this->controller->photo('missing-uid');
        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertSame(404, $result->getStatus());
    }

    public function testPhotoReadsEmbeddedBase64PhotoFromCard(): void {
        // The embedded PHOTO (ENCODING=b) is read straight from the stored vCard
        // and decoded to its raw bytes; the MIME is sniffed from those bytes.
        $png = "\x89PNG\r\n\x1a\nrealbytes";
        $this->mockAddressBooks(['1']);
        $this->mockCardLookup($this->vcardWithPhoto('u1', $png, 'PNG'));

        $result = $this->controller->photo('u1');
        $this->assertInstanceOf(DataDisplayResponse::class, $result);
        $this->assertSame(200, $result->getStatus());
        $this->assertSame('image/png', $result->getHeaders()['Content-Type']);
    }

    public function testPhotoLookupIsScopedToAccessibleAddressBookIds(): void {
        // Access control: the cards query is constrained to ONLY the numeric ids
        // of address books the caller can read. Non-numeric keys (system) are
        // dropped from the scope, so a caller cannot pull a photo from a book
        // they cannot access.
        $jpeg = "\xff\xd8\xff\xe0jpeg";
        $this->mockAddressBooks(['3', 'system', '7']);
        $captured = [];
        $this->mockCardLookup($this->vcardWithPhoto('u1', $jpeg, 'JPEG'), $captured);

        $result = $this->controller->photo('u1');
        $this->assertInstanceOf(DataDisplayResponse::class, $result);
        $this->assertSame('image/jpeg', $result->getHeaders()['Content-Type']);
        // Only the numeric ids made it into the IN(...) scope.
        $this->assertSame([3, 7], $captured);
    }

    public function testPhotoReturns404WhenCardHasNoPhoto(): void {
        $this->mockAddressBooks(['1']);
        $this->mockCardLookup("BEGIN:VCARD\r\nVERSION:3.0\r\nUID:u1\r\nFN:No Photo\r\nEND:VCARD\r\n");

        $result = $this->controller->photo('u1');
        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertSame(404, $result->getStatus());
    }

    public function testPhotoReturns404ForEmptyUid(): void {
        // An empty UID short-circuits before any address-book or DB access.
        $this->contactsManager->expects($this->never())->method('getUserAddressBooks');
        $this->db->expects($this->never())->method('getQueryBuilder');

        $result = $this->controller->photo('');
        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertSame(404, $result->getStatus());
    }

    public function testPhotoHandlesResourceCarddata(): void {
        // Some DB drivers hand back a stream resource for the carddata column;
        // the controller must read it to a string before parsing.
        $png = "\x89PNG\r\n\x1a\nresourcebytes";
        $card = $this->vcardWithPhoto('u1', $png, 'PNG');
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $card);
        rewind($stream);

        $this->mockAddressBooks(['1']);
        $this->mockCardLookup($stream);

        $result = $this->controller->photo('u1');
        $this->assertInstanceOf(DataDisplayResponse::class, $result);
        $this->assertSame('image/png', $result->getHeaders()['Content-Type']);
    }

    // ── Content-Type hardening (GRUMPY DEV: stored XSS via PHOTO data: URI) ──

    public function testPhotoSetsInlineContentDisposition(): void {
        $png = "\x89PNG\r\n\x1a\nbytes";
        $this->mockAddressBooks(['1']);
        $this->mockCardLookup($this->vcardWithPhoto('u1', $png, 'PNG'));

        $result = $this->controller->photo('u1');
        $headers = $result->getHeaders();
        $this->assertSame('inline; filename="photo"', $headers['Content-Disposition']);
        $this->assertSame('nosniff', $headers['X-Content-Type-Options']);
    }

    public function testPhotoIgnoresAttackerDeclaredHtmlMime(): void {
        // The vCard PHOTO claims text/html but the bytes are not a known image —
        // the served Content-Type must NEVER be the attacker-chosen text/html.
        $payload = '<script>alert(1)</script>';
        $line = 'PHOTO;ENCODING=b;TYPE=HTML:' . base64_encode($payload);
        $card = "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:u1\r\n" . $line . "\r\nEND:VCARD\r\n";
        $this->mockAddressBooks(['1']);
        $this->mockCardLookup($card);

        $result = $this->controller->photo('u1');
        $this->assertInstanceOf(DataDisplayResponse::class, $result);
        $this->assertSame('application/octet-stream', $result->getHeaders()['Content-Type']);
    }

    public function testPhotoIgnoresAttackerDeclaredSvgMime(): void {
        // image/svg+xml is scriptable and must never be served as such.
        $payload = '<svg xmlns="http://www.w3.org/2000/svg"><script>1</script></svg>';
        $line = 'PHOTO;ENCODING=b;TYPE=SVG:' . base64_encode($payload);
        $card = "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:u1\r\n" . $line . "\r\nEND:VCARD\r\n";
        $this->mockAddressBooks(['1']);
        $this->mockCardLookup($card);

        $result = $this->controller->photo('u1');
        $this->assertSame('application/octet-stream', $result->getHeaders()['Content-Type']);
    }

    public function testPhotoDerivesMimeFromBytesNotDeclaration(): void {
        // Declared TYPE=GIF but the bytes are a JPEG — the sniffed type wins.
        $jpeg = "\xff\xd8\xff\xe0rest";
        $line = 'PHOTO;ENCODING=b;TYPE=GIF:' . base64_encode($jpeg);
        $card = "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:u1\r\n" . $line . "\r\nEND:VCARD\r\n";
        $this->mockAddressBooks(['1']);
        $this->mockCardLookup($card);

        $result = $this->controller->photo('u1');
        $this->assertSame('image/jpeg', $result->getHeaders()['Content-Type']);
    }

    public function testPhotoDetectsWebp(): void {
        // RIFF + 4 size bytes + WEBP fourCC — the WebP magic on the allow-list.
        $raw = 'RIFF' . "\x10\x00\x00\x00" . 'WEBP' . 'VP8 ';
        $line = 'PHOTO;ENCODING=b;TYPE=WEBP:' . base64_encode($raw);
        $card = "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:u1\r\n" . $line . "\r\nEND:VCARD\r\n";
        $this->mockAddressBooks(['1']);
        $this->mockCardLookup($card);

        $result = $this->controller->photo('u1');
        $this->assertSame('image/webp', $result->getHeaders()['Content-Type']);
    }

    public function testPhotoRefusesExternalUriPhoto(): void {
        // An external-URI PHOTO is a remote image we deliberately never fetch
        // (SSRF/auth) — 404 so the client falls back to initials cleanly.
        $card = "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:u1\r\nPHOTO;VALUE=uri:https://example.com/p.png\r\nEND:VCARD\r\n";
        $this->mockAddressBooks(['1']);
        $this->mockCardLookup($card);

        $result = $this->controller->photo('u1');
        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertSame(404, $result->getStatus());
    }

    public function testPhotoDecodesDataUriPhotoValue(): void {
        // A vCard 4.0 inline data: URI PHOTO value is decoded to the real bytes.
        $png = "\x89PNG\r\n\x1a\ndatauri";
        $card = "BEGIN:VCARD\r\nVERSION:4.0\r\nUID:u1\r\nPHOTO:data:image/png;base64," . base64_encode($png) . "\r\nEND:VCARD\r\n";
        $this->mockAddressBooks(['1']);
        $this->mockCardLookup($card);

        $result = $this->controller->photo('u1');
        $this->assertInstanceOf(DataDisplayResponse::class, $result);
        $this->assertSame('image/png', $result->getHeaders()['Content-Type']);
    }

    // ── entryHasPhoto: CardDAV-export reference vs arbitrary external URI ──────

    public function testIndexAdvertisesPhotoForCardDavExportReference(): void {
        // Nextcloud hands an embedded vCard photo back from search() as a
        // reference to its own CardDAV photo export. entryHasPhoto() must treat
        // that "remote.php/dav/....vcf?photo" reference as a real, servable photo.
        $this->request->method('getParam')->willReturn('');
        $ref = 'VALUE=uri:https://host/remote.php/dav/addressbooks/users/me/contacts/card-1.vcf?photo';
        $this->contactsManager->method('search')
            ->willReturn([
                ['UID' => 'u1', 'FN' => 'Embedded', 'EMAIL' => '', 'PHOTO' => $ref, 'addressbook-key' => '1'],
            ]);

        $data = $this->controller->index()->getData();
        $this->assertSame('/route/u1', $data[0]['photo']);
    }

    public function testIndexDoesNotAdvertisePhotoForArbitraryExternalUri(): void {
        // An arbitrary external http(s) PHOTO URI (NOT the CardDAV-export
        // reference) is not retrievable, so index() must NOT advertise it.
        $this->request->method('getParam')->willReturn('');
        $this->contactsManager->method('search')
            ->willReturn([
                ['UID' => 'u1', 'FN' => 'Ext', 'EMAIL' => '', 'PHOTO' => 'https://example.com/p.png', 'addressbook-key' => '1'],
            ]);

        $data = $this->controller->index()->getData();
        $this->assertSame('', $data[0]['photo']);
    }

    public function testIndexAdvertisesPhotoForNonBase64DataUri(): void {
        // entryHasPhoto() applies the same parse as the photo endpoint, so a
        // valid percent-encoded data: URI is advertised (URL emitted).
        $this->request->method('getParam')->willReturn('');
        $encoded = rawurlencode("\x89PNG\r\n\x1a\nrest");
        $this->contactsManager->method('search')
            ->willReturn([
                ['UID' => 'u', 'FN' => 'PE', 'EMAIL' => '', 'PHOTO' => 'data:image/png,' . $encoded, 'addressbook-key' => '1'],
            ]);

        $data = $this->controller->index()->getData();
        $this->assertSame('/route/u', $data[0]['photo']);
    }

    public function testIndexDoesNotAdvertisePhotoForExternalUri(): void {
        // An external http(s) PHOTO URI is not retrievable (we never fetch remote
        // images), so index() must NOT advertise a photo URL that would 404.
        $this->request->method('getParam')->willReturn('');
        $this->contactsManager->method('search')
            ->willReturn([
                ['UID' => 'u', 'FN' => 'Ext', 'EMAIL' => '', 'PHOTO' => 'https://example.com/p.png', 'addressbook-key' => '1'],
            ]);

        $data = $this->controller->index()->getData();
        $this->assertSame('', $data[0]['photo']);
    }

    // ── Contacts-app row parity: ORG + TEL exposed and flattened ──────────────

    public function testIndexReturnsOrgAndPhoneFlattened(): void {
        // ORG and TEL come back as typed structures under ['types' => true]; the
        // API must flatten each to a scalar string (never a typed object),
        // mirroring the firstContactValue() handling already applied to FN/EMAIL.
        $this->request->method('getParam')->willReturn('');
        $this->contactsManager->method('search')
            ->willReturn([
                [
                    'UID' => 'uid-1',
                    'FN' => 'Carol',
                    'EMAIL' => '',
                    'TEL' => [['type' => ['CELL'], 'value' => '+1 555 0100']],
                    'ORG' => ['Acme Inc'],
                    'addressbook-key' => '1',
                ],
            ]);

        $data = $this->controller->index()->getData();
        $this->assertArrayHasKey('org', $data[0]);
        $this->assertArrayHasKey('phone', $data[0]);
        $this->assertSame('Acme Inc', $data[0]['org']);
        $this->assertSame('+1 555 0100', $data[0]['phone']);
    }
}

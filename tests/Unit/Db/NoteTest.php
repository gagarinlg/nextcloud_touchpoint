<?php

declare(strict_types=1);

namespace OCA\CrmNotes\Tests\Unit\Db;

use DateTime;
use OCA\CrmNotes\Db\Note;
use PHPUnit\Framework\TestCase;

class NoteTest extends TestCase {

    private Note $note;

    protected function setUp(): void {
        $this->note = new Note();
    }

    public function testSetAndGetContactUid(): void {
        $this->note->setContactUid('abc-123');
        $this->assertSame('abc-123', $this->note->getContactUid());
    }

    public function testSetAndGetAddressbookId(): void {
        $this->note->setAddressbookId(5);
        $this->assertSame(5, $this->note->getAddressbookId());
    }

    public function testSetAndGetNoteTypeId(): void {
        $this->note->setNoteTypeId(3);
        $this->assertSame(3, $this->note->getNoteTypeId());
    }

    public function testSetAndGetTitle(): void {
        $this->note->setTitle('Follow up call');
        $this->assertSame('Follow up call', $this->note->getTitle());
    }

    public function testSetAndGetContent(): void {
        $this->note->setContent('Discussed pricing');
        $this->assertSame('Discussed pricing', $this->note->getContent());
    }

    public function testSetAndGetNullContent(): void {
        $this->note->setContent(null);
        $this->assertNull($this->note->getContent());
    }

    public function testSetAndGetUserId(): void {
        $this->note->setUserId('admin');
        $this->assertSame('admin', $this->note->getUserId());
    }

    public function testSetAndGetIsPinned(): void {
        $this->note->setIsPinned(true);
        $this->assertTrue($this->note->getIsPinned());
    }

    public function testSetAndGetCreatedAt(): void {
        $dt = new DateTime('2026-03-07 10:00:00');
        $this->note->setCreatedAt($dt);
        $this->assertSame($dt, $this->note->getCreatedAt());
    }

    public function testSetAndGetUpdatedAt(): void {
        $dt = new DateTime('2026-03-07 12:00:00');
        $this->note->setUpdatedAt($dt);
        $this->assertSame($dt, $this->note->getUpdatedAt());
    }

    public function testDefaultValues(): void {
        $this->assertSame('', $this->note->getContactUid());
        $this->assertSame(0, $this->note->getAddressbookId());
        $this->assertSame(0, $this->note->getNoteTypeId());
        $this->assertSame('', $this->note->getTitle());
        $this->assertNull($this->note->getContent());
        $this->assertSame('', $this->note->getUserId());
        $this->assertFalse($this->note->getIsPinned());
        $this->assertNull($this->note->getCreatedAt());
        $this->assertNull($this->note->getUpdatedAt());
    }

    public function testJsonSerialize(): void {
        $created = new DateTime('2026-01-15 09:30:00');
        $updated = new DateTime('2026-03-07 14:22:00');

        $this->note->setId(10);
        $this->note->setContactUid('contact-xyz');
        $this->note->setAddressbookId(2);
        $this->note->setNoteTypeId(1);
        $this->note->setTitle('Initial meeting');
        $this->note->setContent('Met at conference');
        $this->note->setUserId('user1');
        $this->note->setIsPinned(true);
        $this->note->setCreatedAt($created);
        $this->note->setUpdatedAt($updated);

        $json = $this->note->jsonSerialize();

        $this->assertSame(10, $json['id']);
        $this->assertSame('contact-xyz', $json['contactUid']);
        $this->assertSame(2, $json['addressbookId']);
        $this->assertSame(1, $json['noteTypeId']);
        $this->assertSame('Initial meeting', $json['title']);
        $this->assertSame('Met at conference', $json['content']);
        $this->assertSame('user1', $json['userId']);
        $this->assertTrue($json['isPinned']);
        // Dates are serialized as ISO-8601 (ATOM) strings.
        $this->assertSame($created->format(\DateTimeInterface::ATOM), $json['createdAt']);
        $this->assertSame($updated->format(\DateTimeInterface::ATOM), $json['updatedAt']);
        $this->assertSame([], $json['contacts']);
        $this->assertSame([], $json['files']);
        $this->assertSame([], $json['sharing']);
    }

    public function testJsonSerializeKeys(): void {
        // By default exposeAudit is true (owner view), so audit fields appear.
        $json = $this->note->jsonSerialize();
        $expectedKeys = [
            'id', 'contactUid', 'addressbookId', 'noteTypeId',
            'title', 'content', 'isPinned',
            'createdAt', 'updatedAt', 'contacts', 'files', 'sharing',
            'userId', 'createdBy', 'updatedBy',
        ];
        $this->assertSame($expectedKeys, array_keys($json));
    }

    public function testJsonSerializeHidesAuditFieldsForShareRecipients(): void {
        $this->note->setUserId('owner');
        $this->note->setExposeAudit(false);
        $json = $this->note->jsonSerialize();
        $this->assertArrayNotHasKey('userId', $json);
        $this->assertArrayNotHasKey('createdBy', $json);
        $this->assertArrayNotHasKey('updatedBy', $json);
    }

    public function testJsonSerializeNullDates(): void {
        $json = $this->note->jsonSerialize();
        $this->assertNull($json['createdAt']);
        $this->assertNull($json['updatedAt']);
    }

    public function testFromParams(): void {
        $note = Note::fromParams([
            'contactUid' => 'uid-123',
            'addressbookId' => 1,
            'noteTypeId' => 2,
            'title' => 'Test',
            'content' => 'Body',
            'userId' => 'admin',
            'isPinned' => false,
        ]);

        $this->assertSame('uid-123', $note->getContactUid());
        $this->assertSame(1, $note->getAddressbookId());
        $this->assertSame(2, $note->getNoteTypeId());
        $this->assertSame('Test', $note->getTitle());
        $this->assertSame('Body', $note->getContent());
        $this->assertSame('admin', $note->getUserId());
        $this->assertFalse($note->getIsPinned());
    }

    public function testSetId(): void {
        $this->note->setId(77);
        $this->assertSame(77, $this->note->getId());
    }

    public function testFieldTypesRegistered(): void {
        $types = $this->note->getFieldTypes();
        $this->assertArrayHasKey('id', $types);
        $this->assertArrayHasKey('contactUid', $types);
        $this->assertArrayHasKey('addressbookId', $types);
        $this->assertArrayHasKey('noteTypeId', $types);
        $this->assertArrayHasKey('title', $types);
        $this->assertArrayHasKey('content', $types);
        $this->assertArrayHasKey('userId', $types);
        $this->assertArrayHasKey('isPinned', $types);
        $this->assertArrayHasKey('createdAt', $types);
        $this->assertArrayHasKey('updatedAt', $types);
    }

    public function testUpdatedFieldsTracking(): void {
        $this->note->resetUpdatedFields();
        $this->assertEmpty($this->note->getUpdatedFields());

        $this->note->setTitle('Changed');
        $updated = $this->note->getUpdatedFields();
        $this->assertArrayHasKey('title', $updated);
    }

    public function testMultipleFieldUpdates(): void {
        $this->note->resetUpdatedFields();
        $this->note->setTitle('T');
        $this->note->setContent('C');
        $this->note->setIsPinned(true);

        $updated = $this->note->getUpdatedFields();
        $this->assertCount(3, $updated);
        $this->assertArrayHasKey('title', $updated);
        $this->assertArrayHasKey('content', $updated);
        $this->assertArrayHasKey('isPinned', $updated);
    }

    public function testColumnToProperty(): void {
        $this->assertSame('contactUid', $this->note->columnToProperty('contact_uid'));
        $this->assertSame('addressbookId', $this->note->columnToProperty('addressbook_id'));
        $this->assertSame('noteTypeId', $this->note->columnToProperty('note_type_id'));
        $this->assertSame('createdAt', $this->note->columnToProperty('created_at'));
        $this->assertSame('isPinned', $this->note->columnToProperty('is_pinned'));
    }

    public function testPropertyToColumn(): void {
        $this->assertSame('contact_uid', $this->note->propertyToColumn('contactUid'));
        $this->assertSame('addressbook_id', $this->note->propertyToColumn('addressbookId'));
        $this->assertSame('is_pinned', $this->note->propertyToColumn('isPinned'));
    }

    public function testSetAndGetContacts(): void {
        $contacts = [['id' => 1, 'contactUid' => 'uid-1', 'noteId' => 5, 'addressbookId' => 2]];
        $this->note->setContacts($contacts);
        $this->assertSame($contacts, $this->note->getContacts());
    }

    public function testDefaultContacts(): void {
        $this->assertSame([], $this->note->getContacts());
    }

    public function testSetAndGetFiles(): void {
        $files = [['id' => 1, 'noteId' => 5, 'fileId' => 42, 'filePath' => '/test.pdf']];
        $this->note->setFiles($files);
        $this->assertSame($files, $this->note->getFiles());
    }

    public function testDefaultFiles(): void {
        $this->assertSame([], $this->note->getFiles());
    }

    public function testJsonSerializeIncludesContactsAndFiles(): void {
        $contacts = [['contactUid' => 'uid-a']];
        $files = [['filePath' => '/doc.pdf']];
        $this->note->setContacts($contacts);
        $this->note->setFiles($files);

        $json = $this->note->jsonSerialize();
        $this->assertSame($contacts, $json['contacts']);
        $this->assertSame($files, $json['files']);
    }
}

<?php

// SPDX-FileCopyrightText: 2026 Touchpoint Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Touchpoint\Tests\Unit\Db;

use OCA\Touchpoint\Db\NoteContact;
use PHPUnit\Framework\TestCase;

class NoteContactTest extends TestCase {

    private NoteContact $entity;

    protected function setUp(): void {
        $this->entity = new NoteContact();
    }

    public function testSetAndGetNoteId(): void {
        $this->entity->setNoteId(42);
        $this->assertSame(42, $this->entity->getNoteId());
    }

    public function testSetAndGetContactUid(): void {
        $this->entity->setContactUid('contact-abc');
        $this->assertSame('contact-abc', $this->entity->getContactUid());
    }

    public function testSetAndGetAddressbookId(): void {
        $this->entity->setAddressbookId(7);
        $this->assertSame(7, $this->entity->getAddressbookId());
    }

    public function testDefaultValues(): void {
        $this->assertSame(0, $this->entity->getNoteId());
        $this->assertSame('', $this->entity->getContactUid());
        $this->assertSame(0, $this->entity->getAddressbookId());
    }

    public function testJsonSerialize(): void {
        $this->entity->setId(10);
        $this->entity->setNoteId(5);
        $this->entity->setContactUid('uid-xyz');
        $this->entity->setAddressbookId(3);

        $json = $this->entity->jsonSerialize();

        $this->assertSame(10, $json['id']);
        $this->assertSame(5, $json['noteId']);
        $this->assertSame('uid-xyz', $json['contactUid']);
        $this->assertSame(3, $json['addressbookId']);
    }

    public function testJsonSerializeKeys(): void {
        $json = $this->entity->jsonSerialize();
        $this->assertSame(['id', 'noteId', 'contactUid', 'addressbookId'], array_keys($json));
    }

    public function testFromParams(): void {
        $entity = NoteContact::fromParams([
            'noteId' => 1,
            'contactUid' => 'uid-1',
            'addressbookId' => 2,
        ]);

        $this->assertSame(1, $entity->getNoteId());
        $this->assertSame('uid-1', $entity->getContactUid());
        $this->assertSame(2, $entity->getAddressbookId());
    }

    public function testFieldTypesRegistered(): void {
        $types = $this->entity->getFieldTypes();
        $this->assertArrayHasKey('id', $types);
        $this->assertArrayHasKey('noteId', $types);
        $this->assertArrayHasKey('contactUid', $types);
        $this->assertArrayHasKey('addressbookId', $types);
    }

    public function testColumnToProperty(): void {
        $this->assertSame('noteId', $this->entity->columnToProperty('note_id'));
        $this->assertSame('contactUid', $this->entity->columnToProperty('contact_uid'));
        $this->assertSame('addressbookId', $this->entity->columnToProperty('addressbook_id'));
    }
}

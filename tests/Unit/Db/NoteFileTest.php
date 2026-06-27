<?php

// SPDX-FileCopyrightText: 2026 Touchpoint Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Touchpoint\Tests\Unit\Db;

use OCA\Touchpoint\Db\NoteFile;
use PHPUnit\Framework\TestCase;

class NoteFileTest extends TestCase {

    private NoteFile $entity;

    protected function setUp(): void {
        $this->entity = new NoteFile();
    }

    public function testSetAndGetNoteId(): void {
        $this->entity->setNoteId(10);
        $this->assertSame(10, $this->entity->getNoteId());
    }

    public function testSetAndGetFileId(): void {
        $this->entity->setFileId(99);
        $this->assertSame(99, $this->entity->getFileId());
    }

    public function testSetAndGetFilePath(): void {
        $this->entity->setFilePath('/Documents/contract.pdf');
        $this->assertSame('/Documents/contract.pdf', $this->entity->getFilePath());
    }

    public function testSetAndGetUserId(): void {
        $this->entity->setUserId('admin');
        $this->assertSame('admin', $this->entity->getUserId());
    }

    public function testDefaultValues(): void {
        $this->assertSame(0, $this->entity->getNoteId());
        // fileId is nullable since Version1002 (files without a known file id).
        $this->assertNull($this->entity->getFileId());
        $this->assertSame('', $this->entity->getFilePath());
        $this->assertSame('', $this->entity->getUserId());
    }

    public function testJsonSerialize(): void {
        $this->entity->setId(5);
        $this->entity->setNoteId(3);
        $this->entity->setFileId(42);
        $this->entity->setFilePath('/Photos/logo.png');

        $json = $this->entity->jsonSerialize();

        $this->assertSame(5, $json['id']);
        $this->assertSame(3, $json['noteId']);
        $this->assertSame(42, $json['fileId']);
        $this->assertSame('/Photos/logo.png', $json['filePath']);
    }

    public function testJsonSerializeKeys(): void {
        $json = $this->entity->jsonSerialize();
        $this->assertSame(['id', 'noteId', 'fileId', 'filePath'], array_keys($json));
    }

    public function testJsonSerializeExcludesUserId(): void {
        $this->entity->setUserId('admin');
        $json = $this->entity->jsonSerialize();
        $this->assertArrayNotHasKey('userId', $json);
    }

    public function testFromParams(): void {
        $entity = NoteFile::fromParams([
            'noteId' => 1,
            'fileId' => 50,
            'filePath' => '/test.txt',
            'userId' => 'user1',
        ]);

        $this->assertSame(1, $entity->getNoteId());
        $this->assertSame(50, $entity->getFileId());
        $this->assertSame('/test.txt', $entity->getFilePath());
        $this->assertSame('user1', $entity->getUserId());
    }

    public function testFieldTypesRegistered(): void {
        $types = $this->entity->getFieldTypes();
        $this->assertArrayHasKey('id', $types);
        $this->assertArrayHasKey('noteId', $types);
        $this->assertArrayHasKey('fileId', $types);
        $this->assertArrayHasKey('filePath', $types);
        $this->assertArrayHasKey('userId', $types);
    }

    public function testColumnToProperty(): void {
        $this->assertSame('noteId', $this->entity->columnToProperty('note_id'));
        $this->assertSame('fileId', $this->entity->columnToProperty('file_id'));
        $this->assertSame('filePath', $this->entity->columnToProperty('file_path'));
        $this->assertSame('userId', $this->entity->columnToProperty('user_id'));
    }
}

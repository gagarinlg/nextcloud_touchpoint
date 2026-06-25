<?php

// SPDX-FileCopyrightText: 2026 CRM Notes Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\CrmNotes\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * @method int getNoteId()
 * @method void setNoteId(int $noteId)
 * @method int|null getFileId()
 * @method void setFileId(int $fileId)
 * @method string getFilePath()
 * @method void setFilePath(string $filePath)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 */
class NoteFile extends Entity implements \JsonSerializable {
    protected int $noteId = 0;
    protected ?int $fileId = null;
    protected string $filePath = '';
    protected string $userId = '';

    public function __construct() {
        $this->addType('id', Types::INTEGER);
        $this->addType('noteId', Types::INTEGER);
        $this->addType('fileId', Types::INTEGER);
        $this->addType('filePath', Types::STRING);
        $this->addType('userId', Types::STRING);
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->getId(),
            'noteId' => $this->getNoteId(),
            'fileId' => $this->getFileId(),
            'filePath' => $this->getFilePath(),
        ];
    }
}

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
 * @method string getSharedWithType()
 * @method void setSharedWithType(string $sharedWithType)
 * @method string getSharedWithId()
 * @method void setSharedWithId(string $sharedWithId)
 * @method bool getCanEdit()
 * @method void setCanEdit(bool $canEdit)
 */
class NoteSharing extends Entity implements \JsonSerializable {
    protected int $noteId = 0;
    protected string $sharedWithType = '';
    protected string $sharedWithId = '';
    protected bool $canEdit = false;

    public function __construct() {
        $this->addType('id', Types::INTEGER);
        $this->addType('noteId', Types::INTEGER);
        $this->addType('sharedWithType', Types::STRING);
        $this->addType('sharedWithId', Types::STRING);
        $this->addType('canEdit', Types::BOOLEAN);
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->getId(),
            'noteId' => $this->getNoteId(),
            'sharedWithType' => $this->getSharedWithType(),
            'sharedWithId' => $this->getSharedWithId(),
            'canEdit' => $this->getCanEdit(),
        ];
    }
}

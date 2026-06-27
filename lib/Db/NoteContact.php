<?php

// SPDX-FileCopyrightText: 2026 Touchpoint Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Touchpoint\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * @method int getNoteId()
 * @method void setNoteId(int $noteId)
 * @method string getContactUid()
 * @method void setContactUid(string $contactUid)
 * @method int getAddressbookId()
 * @method void setAddressbookId(int $addressbookId)
 */
class NoteContact extends Entity implements \JsonSerializable {
    protected int $noteId = 0;
    protected string $contactUid = '';
    protected int $addressbookId = 0;

    public function __construct() {
        $this->addType('id', Types::INTEGER);
        $this->addType('noteId', Types::INTEGER);
        $this->addType('contactUid', Types::STRING);
        $this->addType('addressbookId', Types::INTEGER);
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->getId(),
            'noteId' => $this->getNoteId(),
            'contactUid' => $this->getContactUid(),
            'addressbookId' => $this->getAddressbookId(),
        ];
    }
}

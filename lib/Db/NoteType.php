<?php

// SPDX-FileCopyrightText: 2026 CRM Notes Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\CrmNotes\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * @method string getName()
 * @method void setName(string $name)
 * @method string|null getIcon()
 * @method void setIcon(?string $icon)
 * @method string|null getColor()
 * @method void setColor(?string $color)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method bool getIsDefault()
 * @method void setIsDefault(bool $isDefault)
 */
class NoteType extends Entity implements \JsonSerializable {
    protected string $name = '';
    protected ?string $icon = 'icon-note';
    protected ?string $color = '#0082c9';
    protected string $userId = '';
    protected bool $isDefault = false;

    public function __construct() {
        $this->addType('id', Types::INTEGER);
        $this->addType('name', Types::STRING);
        $this->addType('icon', Types::STRING);
        $this->addType('color', Types::STRING);
        $this->addType('userId', Types::STRING);
        $this->addType('isDefault', Types::BOOLEAN);
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->getId(),
            'name' => $this->getName(),
            'icon' => $this->getIcon(),
            'color' => $this->getColor(),
            'userId' => $this->getUserId(),
            'isDefault' => $this->getIsDefault(),
        ];
    }
}

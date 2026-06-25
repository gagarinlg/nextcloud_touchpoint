<?php

// SPDX-FileCopyrightText: 2026 CRM Notes Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\CrmNotes\Service;

use OCA\CrmNotes\Db\NoteMapper;
use OCA\CrmNotes\Db\NoteType;
use OCA\CrmNotes\Db\NoteTypeMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use Psr\Log\LoggerInterface;

class NoteTypeService {

    /** crm_note_types.name column length (VARCHAR(128)). */
    private const MAX_NAME_LENGTH = 128;

    public function __construct(
        private NoteTypeMapper $mapper,
        private NoteMapper $noteMapper,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return NoteType[]
     */
    public function findAll(string $userId): array {
        return $this->mapper->findAll($userId);
    }

    /**
     * @throws NoteTypeNotFoundException
     */
    public function find(int $id, string $userId): NoteType {
        try {
            return $this->mapper->findById($id, $userId);
        } catch (DoesNotExistException | MultipleObjectsReturnedException $e) {
            throw new NoteTypeNotFoundException('Note type not found');
        }
    }

    public function create(
        string $name,
        string $icon,
        string $color,
        string $userId,
        bool $isDefault = false,
    ): NoteType {
        $this->assertNameLength($name);

        $noteType = new NoteType();
        $noteType->setName($name);
        $noteType->setIcon($icon);
        $noteType->setColor($this->normalizeColor($color));
        $noteType->setUserId($userId);
        $noteType->setIsDefault($isDefault);

        return $this->mapper->insert($noteType);
    }

    /**
     * Find a note type the caller owns, for mutation. Global defaults and other
     * users' types are rejected, so a caller cannot edit/delete a type they
     * don't own.
     *
     * @throws NoteTypeNotFoundException
     */
    private function findOwned(int $id, string $userId): NoteType {
        try {
            return $this->mapper->findOwnedById($id, $userId);
        } catch (DoesNotExistException | MultipleObjectsReturnedException $e) {
            throw new NoteTypeNotFoundException('Note type not found');
        }
    }

    /**
     * @throws NoteTypeNotFoundException
     */
    public function update(int $id, string $name, string $icon, string $color, string $userId): NoteType {
        $this->assertNameLength($name);

        $noteType = $this->findOwned($id, $userId);

        $noteType->setName($name);
        $noteType->setIcon($icon);
        $noteType->setColor($this->normalizeColor($color));

        return $this->mapper->update($noteType);
    }

    /**
     * Delete a note type the caller owns. Blocked while any of the caller's
     * notes still reference it, to avoid orphaning note_type_id.
     *
     * @throws NoteTypeNotFoundException
     * @throws NoteTypeInUseException
     */
    public function delete(int $id, string $userId): NoteType {
        $noteType = $this->findOwned($id, $userId);

        $inUse = $this->noteMapper->countByNoteType($id, $userId);
        if ($inUse > 0) {
            throw new NoteTypeInUseException(
                'Note type is still used by ' . $inUse . ' note(s)'
            );
        }

        $this->mapper->delete($noteType);
        return $noteType;
    }

    /**
     * Number of the caller's notes using a given note type.
     */
    public function countUsage(int $id, string $userId): int {
        return $this->noteMapper->countByNoteType($id, $userId);
    }

    /**
     * Reject an over-length note-type name before it hits the column limit, so
     * callers get a clean 400 instead of an opaque 500 from DB truncation.
     *
     * @throws NoteValidationException
     */
    private function assertNameLength(string $name): void {
        if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
            throw new NoteValidationException(
                'Name must not exceed ' . self::MAX_NAME_LENGTH . ' characters'
            );
        }
    }

    /**
     * Validate a CSS color. Accepts #rgb, #rrggbb, and hsl()/hsla() values.
     * Falls back to the NC primary element color for anything unparseable so
     * we never persist an arbitrary string that ends up in an inline style.
     */
    private function normalizeColor(string $color): string {
        $color = trim($color);
        if (preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $color)) {
            return $color;
        }
        if (preg_match('/^hsla?\(\s*\d{1,3}\s*,\s*\d{1,3}%\s*,\s*\d{1,3}%\s*(,\s*(0|1|0?\.\d+)\s*)?\)$/', $color)) {
            return $color;
        }
        return '#0082c9';
    }

    /**
     * Ensure the shared global default note types exist (seeded once per
     * instance, not per user). They are stored with an empty user_id and
     * is_default = true, so every user sees and can select them
     * (NoteTypeMapper::findAll/findById include the global set) while no one owns
     * them — hence no one can edit or delete them, because update/delete use
     * findOwnedById(), which excludes globals. Safe against the historical
     * cross-user IDOR: only the '' sentinel, never a real user's id, is shared.
     *
     * Called in a user context (on app/page access); the userId is not needed
     * for the rows themselves, only as the natural "ensure defaults exist" hook.
     */
    public function seedDefaults(string $userId): void {
        if (count($this->mapper->findGlobalDefaults()) > 0) {
            return;
        }
        $defaults = [
            ['name' => 'Call', 'icon' => 'icon-phone', 'color' => '#2ecc71'],
            ['name' => 'Meeting', 'icon' => 'icon-calendar', 'color' => '#3498db'],
            ['name' => 'Email', 'icon' => 'icon-mail', 'color' => '#9b59b6'],
            ['name' => 'Task', 'icon' => 'icon-checkmark', 'color' => '#e67e22'],
            ['name' => 'General', 'icon' => 'icon-note', 'color' => '#0082c9'],
        ];

        foreach ($defaults as $default) {
            $noteType = new NoteType();
            $noteType->setName($default['name']);
            $noteType->setIcon($default['icon']);
            $noteType->setColor($this->normalizeColor($default['color']));
            $noteType->setUserId('');
            $noteType->setIsDefault(true);
            $this->mapper->insert($noteType);
        }
    }
}

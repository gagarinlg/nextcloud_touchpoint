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

    /** crm_note_types.icon column length (VARCHAR(64)). */
    private const MAX_ICON_LENGTH = 64;

    /**
     * Allow-list of icon tokens the app can actually render.
     *
     * The render surfaces (NoteTypeBadge via iconComponentForType, the Contacts
     * island via iconPathForType) resolve only these tokens; anything else
     * renders as no icon. Rejecting unknown tokens at write time turns a value
     * that would be silently dropped at render into a clean 400, and the length
     * cap prevents an over-long string from triggering a DB-truncation 500.
     *
     * Keep in sync with src/utils/noteTypeIcon.js and NoteTypeModal.vue's
     * iconOptions. `icon-note`/`icon-calendar` are legacy tokens kept valid so
     * the shipped global default set (seedDefaults) and the column/controller
     * default never fail validation.
     */
    private const ALLOWED_ICONS = [
        'icon-comment',
        'icon-phone',
        'icon-calendar',
        'icon-calendar-dark',
        'icon-mail',
        'icon-checkmark',
        'icon-star',
        'icon-link',
        'icon-category-office',
        'icon-note',
    ];

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
            $this->logger->debug('CRM Notes: note type lookup failed', ['exception' => $e]);
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
        $this->assertValidIcon($icon);

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
            $this->logger->debug('CRM Notes: owned note type lookup failed', ['exception' => $e]);
            throw new NoteTypeNotFoundException('Note type not found');
        }
    }

    /**
     * Partial update: only the non-null fields are applied, so a caller can
     * PATCH just the name (a rename) without resetting the icon/color, and a
     * name-only payload behaves consistently with create()'s optional fields.
     * Provided fields are validated/normalised exactly as in create().
     *
     * @throws NoteTypeNotFoundException
     * @throws NoteValidationException
     */
    public function update(
        int $id,
        string $userId,
        ?string $name = null,
        ?string $icon = null,
        ?string $color = null,
    ): NoteType {
        $noteType = $this->findOwned($id, $userId);

        if ($name !== null) {
            $this->assertNameLength($name);
            $noteType->setName($name);
        }
        if ($icon !== null) {
            $this->assertValidIcon($icon);
            $noteType->setIcon($icon);
        }
        if ($color !== null) {
            $noteType->setColor($this->normalizeColor($color));
        }

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
     * Reject an icon token the app cannot render before it reaches the DB.
     *
     * Enforces both the VARCHAR(64) column bound (so an over-long token can't
     * trigger a truncation 500 / silent corruption) and the render allow-list
     * (so an unknown token fails fast with a 400 instead of being stored and
     * silently dropped at render time).
     *
     * @throws NoteValidationException
     */
    private function assertValidIcon(string $icon): void {
        if (mb_strlen($icon) > self::MAX_ICON_LENGTH) {
            throw new NoteValidationException(
                'Icon must not exceed ' . self::MAX_ICON_LENGTH . ' characters'
            );
        }
        if (!in_array($icon, self::ALLOWED_ICONS, true)) {
            throw new NoteValidationException('Unknown icon: ' . $icon);
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

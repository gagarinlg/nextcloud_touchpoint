<?php

// SPDX-FileCopyrightText: 2026 Touchpoint Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Touchpoint\Db;

use DateTime;
use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * @method string getContactUid()
 * @method void setContactUid(string $contactUid)
 * @method int getAddressbookId()
 * @method void setAddressbookId(int $addressbookId)
 * @method int getNoteTypeId()
 * @method void setNoteTypeId(int $noteTypeId)
 * @method string getTitle()
 * @method void setTitle(string $title)
 * @method string|null getContent()
 * @method void setContent(?string $content)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method bool getIsPinned()
 * @method void setIsPinned(bool $isPinned)
 * @method DateTime|null getCreatedAt()
 * @method void setCreatedAt(DateTime $createdAt)
 * @method DateTime|null getUpdatedAt()
 * @method void setUpdatedAt(DateTime $updatedAt)
 * @method string|null getCreatedBy()
 * @method void setCreatedBy(?string $createdBy)
 * @method string|null getUpdatedBy()
 * @method void setUpdatedBy(?string $updatedBy)
 */
class Note extends Entity implements \JsonSerializable {
    protected string $contactUid = '';
    protected int $addressbookId = 0;
    protected int $noteTypeId = 0;
    protected string $title = '';
    protected ?string $content = null;
    protected string $userId = '';
    protected bool $isPinned = false;
    protected ?DateTime $createdAt = null;
    protected ?DateTime $updatedAt = null;
    protected ?string $createdBy = null;
    protected ?string $updatedBy = null;

    /** @var array Extra data not persisted in this table */
    private array $contacts = [];
    private array $files = [];
    private array $sharing = [];

    /**
     * When false, audit fields (owner user id, created/updated-by) are omitted
     * from serialization so a share recipient cannot see who else touched the
     * note. Defaults to true for backwards compatibility (owner view).
     */
    private bool $exposeAudit = true;

    /**
     * The user the note is being serialized for. Used, when exposeAudit is
     * false, to reduce the exposed 'sharing' list to only the caller's own
     * share entry — so a read-only recipient cannot enumerate every other user
     * and group the note is shared with.
     */
    private ?string $viewerUserId = null;
    private array $viewerGroupIds = [];

    public function __construct() {
        $this->addType('id', Types::INTEGER);
        $this->addType('contactUid', Types::STRING);
        $this->addType('addressbookId', Types::INTEGER);
        $this->addType('noteTypeId', Types::INTEGER);
        $this->addType('title', Types::STRING);
        $this->addType('content', Types::STRING);
        $this->addType('userId', Types::STRING);
        $this->addType('isPinned', Types::BOOLEAN);
        $this->addType('createdAt', Types::DATETIME);
        $this->addType('updatedAt', Types::DATETIME);
        $this->addType('createdBy', Types::STRING);
        $this->addType('updatedBy', Types::STRING);
    }

    public function setContacts(array $contacts): void {
        $this->contacts = $contacts;
    }

    public function getContacts(): array {
        return $this->contacts;
    }

    public function setFiles(array $files): void {
        $this->files = $files;
    }

    public function getFiles(): array {
        return $this->files;
    }

    public function setSharing(array $sharing): void {
        $this->sharing = $sharing;
    }

    public function getSharing(): array {
        return $this->sharing;
    }

    public function setExposeAudit(bool $expose): void {
        $this->exposeAudit = $expose;
    }

    /**
     * Whether owner-identity/audit data (and, by extension, owner-private file
     * paths and internal file IDs) may be exposed for this note to the current
     * caller. True for the owner and in public mode; false for plain recipients.
     */
    public function getExposeAudit(): bool {
        return $this->exposeAudit;
    }

    /**
     * Record who the note is being serialized for (and their group memberships),
     * so non-owner share recipients only see their own share entry rather than
     * the whole ACL.
     */
    public function setViewer(?string $userId, array $groupIds = []): void {
        $this->viewerUserId = $userId;
        $this->viewerGroupIds = $groupIds;
    }

    /**
     * Reduce the sharing list to the entry/entries that apply to the current
     * viewer (their own user share plus any group share they belong to). Used
     * when audit fields are hidden so the recipient cannot enumerate the full
     * ACL of a note they do not own.
     *
     * @param array<array{sharedWithType?: string, sharedWithId?: string}> $sharing
     * @return array
     */
    private function filterSharingForViewer(array $sharing): array {
        if ($this->viewerUserId === null) {
            return [];
        }
        return array_values(array_filter($sharing, function (array $entry): bool {
            $type = $entry['sharedWithType'] ?? '';
            $id = $entry['sharedWithId'] ?? '';
            if ($type === 'user') {
                return $id === $this->viewerUserId;
            }
            if ($type === 'group') {
                return in_array($id, $this->viewerGroupIds, true);
            }
            return false;
        }));
    }

    public function jsonSerialize(): array {
        $data = [
            'id' => $this->getId(),
            'contactUid' => $this->getContactUid(),
            'addressbookId' => $this->getAddressbookId(),
            'noteTypeId' => $this->getNoteTypeId(),
            'title' => $this->getTitle(),
            'content' => $this->getContent(),
            'isPinned' => $this->getIsPinned(),
            'createdAt' => $this->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'updatedAt' => $this->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
            'contacts' => $this->contacts,
            'files' => $this->files,
            // The full sharing/ACL list is identity information of the same class
            // the audit fields below withhold. Owners (and everyone in public
            // mode) see the whole list; a plain share recipient sees only their
            // own entry, never the complete roster of who else the note is shared
            // with.
            'sharing' => $this->exposeAudit
                ? $this->sharing
                : $this->filterSharingForViewer($this->sharing),
        ];

        // Only expose audit/identity fields to the owner (or in public mode).
        // Share recipients should not learn the identities of other users.
        if ($this->exposeAudit) {
            $data['userId'] = $this->getUserId();
            $data['createdBy'] = $this->getCreatedBy();
            $data['updatedBy'] = $this->getUpdatedBy();
        }

        return $data;
    }
}

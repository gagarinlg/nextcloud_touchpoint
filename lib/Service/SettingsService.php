<?php

// SPDX-FileCopyrightText: 2026 CRM Notes Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\CrmNotes\Service;

use OCA\CrmNotes\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;

class SettingsService {

    public function __construct(
        private IAppConfig $appConfig,
        private IConfig $config,
        private IGroupManager $groupManager,
        private IUserManager $userManager,
    ) {
    }

    // ── Admin settings ───────────────────────────────────────────────────────

    public function isNotesPublic(): bool {
        return $this->appConfig->getValueBool(Application::APP_ID, 'notes_public', false);
    }

    public function setNotesPublic(bool $value): void {
        $this->appConfig->setValueBool(Application::APP_ID, 'notes_public', $value);
    }

    // ── Per-user default share targets ───────────────────────────────────────

    /**
     * Default share targets for new notes. Each target carries a canEdit flag so
     * the create-from-defaults path can express edit defaults the same way the
     * explicit-sharing path does. Legacy entries without canEdit are normalised
     * to read-only (canEdit = false).
     *
     * @return array<array{type: string, id: string, name: string, canEdit: bool}>
     */
    public function getUserShareTargets(string $userId): array {
        $json = $this->config->getUserValue($userId, Application::APP_ID, 'share_targets', '[]');
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }
        return array_map(static function ($target) {
            $target['canEdit'] = !empty($target['canEdit']);
            return $target;
        }, $decoded);
    }

    /** Maximum number of default share targets a user may persist. */
    private const MAX_SHARE_TARGETS = 100;

    /**
     * Persist a user's default share targets. The incoming structure is
     * normalised defensively before storage: only the well-known keys
     * {type,id,name,canEdit} are kept (arbitrary extra keys are dropped),
     * entries whose type is not 'user'/'group' or whose id is empty are
     * discarded, non-existent principals are rejected, the name is coerced to a
     * trimmed string, and the list length is bounded. This keeps unbounded /
     * unvalidated junk out of the user's config and ensures only resolvable
     * principals are ever stored.
     *
     * @param array<array{type?: string, id?: string, name?: string, canEdit?: bool}> $targets
     */
    public function setUserShareTargets(string $userId, array $targets): void {
        $normalised = [];
        $seen = [];
        foreach ($targets as $target) {
            if (!is_array($target)) {
                continue;
            }
            $type = (string)($target['type'] ?? '');
            $id   = (string)($target['id'] ?? '');
            if (($type !== 'user' && $type !== 'group') || $id === '') {
                continue;
            }
            // Deduplicate by (type, id) so the same principal is stored once.
            $key = $type . ':' . $id;
            if (isset($seen[$key])) {
                continue;
            }
            if (!$this->principalExists($type, $id)) {
                continue;
            }
            $seen[$key] = true;
            $name = trim((string)($target['name'] ?? ''));
            $normalised[] = [
                'type' => $type,
                'id' => $id,
                'name' => $name !== '' ? $name : $id,
                'canEdit' => !empty($target['canEdit']),
            ];
            if (count($normalised) >= self::MAX_SHARE_TARGETS) {
                break;
            }
        }
        $this->config->setUserValue($userId, Application::APP_ID, 'share_targets', json_encode($normalised));
    }

    // ── Group/user lookup for autocomplete ───────────────────────────────────

    /**
     * Search users/groups for the share-target autocomplete, honouring the
     * instance's share-dialog enumeration privacy settings exactly the way
     * Nextcloud core (\OC\Collaboration\Collaborators) gates principal lookups:
     *
     *  - core/shareapi_allow_share_dialog_user_enumeration (default 'yes'):
     *    when disabled, an authenticated user may NOT harvest the directory.
     *    Lookups then only resolve an EXACT principal match (a user/group whose
     *    id or display name equals the query), so the autocomplete still lets
     *    you add a colleague whose username you already know, but it can no
     *    longer be walked to enumerate the whole instance.
     *  - core/shareapi_restrict_user_enumeration_to_group (default 'no'):
     *    when enabled, user results are limited to principals that share at
     *    least one group with the caller. Group results are likewise limited to
     *    the caller's own groups.
     *
     * On a hardened instance where the admin turned enumeration off, this app
     * no longer silently re-enables it.
     *
     * @param string      $search        the query string
     * @param int         $limit         maximum combined results
     * @param string|null $callerUserId  the searching user (needed to scope
     *                                    results to shared groups); when null,
     *                                    restrict-to-group degrades to "no match"
     *                                    rather than leaking unscoped results
     * @return array<array{type: string, id: string, name: string}>
     */
    public function searchPrincipals(string $search, int $limit = 10, ?string $callerUserId = null): array {
        $limit = max(1, $limit);
        $search = trim($search);
        if ($search === '') {
            return [];
        }

        $allowEnumeration = $this->config->getAppValue(
            'core', 'shareapi_allow_share_dialog_user_enumeration', 'yes'
        ) === 'yes';
        $restrictToGroup = $this->config->getAppValue(
            'core', 'shareapi_restrict_user_enumeration_to_group', 'no'
        ) === 'yes';

        // Caller's own groups, used both for restrict-to-group scoping and for
        // the exact-match group lookup when enumeration is disabled.
        $callerGroupIds = $callerUserId !== null
            ? $this->getUserGroupIds($callerUserId)
            : [];

        // Give each principal type its own budget so a flood of group matches
        // can never starve out user results (and vice versa). We over-fetch a
        // little per bucket and then backfill from whichever type returned
        // fewer rows, so the combined list always fills up to $limit if there
        // are that many distinct principals overall.
        $perType = (int) ceil($limit / 2);

        $groups = $this->searchGroups($search, $perType, $allowEnumeration, $restrictToGroup, $callerGroupIds);
        $users  = $this->searchUsers($search, $perType, $allowEnumeration, $restrictToGroup, $callerUserId, $callerGroupIds);

        // Reserve the unused half of one bucket for the other so neither type
        // can monopolise the result set when the search is lopsided.
        $groupBudget = min(count($groups), max($perType, $limit - count($users)));
        $userBudget  = min(count($users), max($perType, $limit - count($groups)));

        $results = array_merge(
            array_slice($groups, 0, $groupBudget),
            array_slice($users, 0, $userBudget),
        );

        return array_slice($results, 0, $limit);
    }

    /**
     * @param string[] $callerGroupIds
     * @return array<array{type: string, id: string, name: string}>
     */
    private function searchGroups(
        string $search,
        int $perType,
        bool $allowEnumeration,
        bool $restrictToGroup,
        array $callerGroupIds,
    ): array {
        // When enumeration is disabled, only resolve an exact match (by GID or
        // display name) so the endpoint cannot be walked to list every group.
        $candidates = $allowEnumeration
            ? $this->groupManager->search($search, $perType)
            : $this->exactMatchGroups($search);

        $allowed = ($allowEnumeration && !$restrictToGroup)
            ? null
            : array_fill_keys($callerGroupIds, true);

        $groups = [];
        foreach ($candidates as $group) {
            $gid = $group->getGID();
            // Restrict-to-group (and the disabled-enumeration case) limit group
            // results to the caller's own groups, mirroring core behaviour.
            if ($allowed !== null && !isset($allowed[$gid])) {
                continue;
            }
            $groups[] = [
                'type' => 'group',
                'id' => $gid,
                'name' => $group->getDisplayName() ?: $gid,
            ];
        }
        return $groups;
    }

    /**
     * Resolve a query to an exact group match (by GID, then by display name).
     *
     * @return list<IGroup>
     */
    private function exactMatchGroups(string $search): array {
        $exact = $this->groupManager->get($search);
        if ($exact !== null) {
            return [$exact];
        }
        // Fall back to a display-name exact match within a bounded candidate set.
        foreach ($this->groupManager->search($search, 50) as $group) {
            if (strcasecmp($group->getDisplayName(), $search) === 0) {
                return [$group];
            }
        }
        return [];
    }

    /**
     * @param string[] $callerGroupIds
     * @return array<array{type: string, id: string, name: string}>
     */
    private function searchUsers(
        string $search,
        int $perType,
        bool $allowEnumeration,
        bool $restrictToGroup,
        ?string $callerUserId,
        array $callerGroupIds,
    ): array {
        // When enumeration is disabled, only an exact UID/display-name match is
        // returned — never a prefix scan of the directory.
        $candidates = $allowEnumeration
            ? $this->userManager->searchDisplayName($search, $perType)
            : $this->exactMatchUsers($search);

        // Restrict-to-group: build the set of UIDs that share a group with the
        // caller. A null set means "no scoping" (full enumeration allowed).
        $scopedUids = null;
        if ($restrictToGroup) {
            if ($callerUserId === null) {
                // We cannot determine shared groups → leak nothing.
                return [];
            }
            $scopedUids = [];
            foreach ($callerGroupIds as $gid) {
                $group = $this->groupManager->get($gid);
                if ($group === null) {
                    continue;
                }
                foreach ($group->getUsers() as $member) {
                    $scopedUids[$member->getUID()] = true;
                }
            }
        }

        $users = [];
        foreach ($candidates as $user) {
            $uid = $user->getUID();
            if ($scopedUids !== null && !isset($scopedUids[$uid])) {
                continue;
            }
            $users[] = [
                'type' => 'user',
                'id' => $uid,
                'name' => $user->getDisplayName() ?: $uid,
            ];
        }
        return $users;
    }

    /**
     * Resolve a query to an exact user match (by UID, then by display name).
     *
     * @return list<IUser>
     */
    private function exactMatchUsers(string $search): array {
        $exact = $this->userManager->get($search);
        if ($exact !== null) {
            return [$exact];
        }
        foreach ($this->userManager->searchDisplayName($search, 50) as $user) {
            if (strcasecmp($user->getDisplayName(), $search) === 0) {
                return [$user];
            }
        }
        return [];
    }

    /**
     * Validate a share principal: its type must be 'user' or 'group' and the
     * referenced principal must actually exist. Used to keep phantom/invalid
     * rows out of the sharing permission table.
     */
    public function principalExists(string $type, string $id): bool {
        if ($id === '') {
            return false;
        }
        if ($type === 'user') {
            return $this->userManager->get($id) !== null;
        }
        if ($type === 'group') {
            return $this->groupManager->groupExists($id);
        }
        return false;
    }

    /**
     * Return group IDs the given user belongs to.
     *
     * @return string[]
     */
    public function getUserGroupIds(string $userId): array {
        $user = $this->userManager->get($userId);
        if ($user === null) {
            return [];
        }
        return array_map(
            fn (IGroup $g) => $g->getGID(),
            $this->groupManager->getUserGroups($user)
        );
    }
}

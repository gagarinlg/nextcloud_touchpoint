<?php

// SPDX-FileCopyrightText: 2026 CRM Notes Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\CrmNotes\Tests\Unit\Service;

use OCA\CrmNotes\AppInfo\Application;
use OCA\CrmNotes\Service\SettingsService;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

class SettingsServiceTest extends TestCase {

    private SettingsService $service;
    private IAppConfig $appConfig;
    private IConfig $config;
    private IGroupManager $groupManager;
    private IUserManager $userManager;

    protected function setUp(): void {
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->config = $this->createMock(IConfig::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->userManager = $this->createMock(IUserManager::class);

        $this->service = new SettingsService(
            $this->appConfig,
            $this->config,
            $this->groupManager,
            $this->userManager,
        );
    }

    /**
     * Decode whatever JSON the service handed to setUserValue.
     *
     * @return array<int, array<string, mixed>>
     */
    private function captureStored(): array {
        $captured = null;
        $this->config->expects($this->once())
            ->method('setUserValue')
            ->willReturnCallback(function ($uid, $app, $key, $value) use (&$captured) {
                $this->assertSame('user1', $uid);
                $this->assertSame(Application::APP_ID, $app);
                $this->assertSame('share_targets', $key);
                $captured = json_decode($value, true);
            });
        return [&$captured];
    }

    public function testSetUserShareTargetsKeepsOnlyKnownKeysAndExistingPrincipals(): void {
        // 'alice' exists, 'ghost' does not.
        $this->userManager->method('get')
            ->willReturnCallback(fn ($id) => $id === 'alice' ? $this->createMock(\OCP\IUser::class) : null);
        $this->groupManager->method('groupExists')->willReturn(false);

        $ref = $this->captureStored();

        $this->service->setUserShareTargets('user1', [
            // Valid user, with an injected junk key that must be stripped.
            ['type' => 'user', 'id' => 'alice', 'name' => 'Alice', 'canEdit' => true, 'evil' => '<script>'],
            // Non-existent principal — dropped.
            ['type' => 'user', 'id' => 'ghost', 'name' => 'Ghost'],
            // Bogus type — dropped.
            ['type' => 'role', 'id' => 'admins', 'name' => 'x'],
            // Empty id — dropped.
            ['type' => 'group', 'id' => '', 'name' => 'nope'],
        ]);

        $stored = $ref[0];
        $this->assertCount(1, $stored);
        $this->assertSame(
            ['type' => 'user', 'id' => 'alice', 'name' => 'Alice', 'canEdit' => true],
            $stored[0],
        );
        // Junk key never persisted.
        $this->assertArrayNotHasKey('evil', $stored[0]);
    }

    public function testSetUserShareTargetsDefaultsCanEditFalseAndFallsBackName(): void {
        $this->userManager->method('get')->willReturn(null);
        $this->groupManager->method('groupExists')
            ->willReturnCallback(fn ($id) => $id === 'team');

        $ref = $this->captureStored();

        $this->service->setUserShareTargets('user1', [
            // No name → falls back to id; no canEdit → false.
            ['type' => 'group', 'id' => 'team'],
        ]);

        $stored = $ref[0];
        $this->assertSame(
            [['type' => 'group', 'id' => 'team', 'name' => 'team', 'canEdit' => false]],
            $stored,
        );
    }

    public function testSetUserShareTargetsDeduplicatesByTypeAndId(): void {
        $this->userManager->method('get')->willReturn($this->createMock(\OCP\IUser::class));
        $this->groupManager->method('groupExists')->willReturn(false);

        $ref = $this->captureStored();

        $this->service->setUserShareTargets('user1', [
            ['type' => 'user', 'id' => 'alice', 'name' => 'Alice', 'canEdit' => false],
            ['type' => 'user', 'id' => 'alice', 'name' => 'Alice again', 'canEdit' => true],
        ]);

        $stored = $ref[0];
        $this->assertCount(1, $stored);
        $this->assertSame('Alice', $stored[0]['name']);
    }

    // ── searchPrincipals enumeration-privacy gating ─────────────────────────

    private function mockUser(string $uid, string $displayName = ''): IUser {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $user->method('getDisplayName')->willReturn($displayName !== '' ? $displayName : $uid);
        return $user;
    }

    private function mockGroup(string $gid, string $displayName = '', array $members = []): IGroup {
        $group = $this->createMock(IGroup::class);
        $group->method('getGID')->willReturn($gid);
        $group->method('getDisplayName')->willReturn($displayName !== '' ? $displayName : $gid);
        $group->method('getUsers')->willReturn($members);
        return $group;
    }

    /**
     * Configure the two core enumeration settings.
     */
    private function withEnumerationSettings(string $allow, string $restrict): void {
        $this->config->method('getAppValue')->willReturnCallback(
            function (string $app, string $key, $default = '') use ($allow, $restrict) {
                $this->assertSame('core', $app);
                return match ($key) {
                    'shareapi_allow_share_dialog_user_enumeration' => $allow,
                    'shareapi_restrict_user_enumeration_to_group' => $restrict,
                    default => $default,
                };
            }
        );
    }

    public function testSearchPrincipalsEmptyQueryReturnsNothing(): void {
        // No backend calls at all for an empty/whitespace query.
        $this->config->expects($this->never())->method('getAppValue');
        $this->userManager->expects($this->never())->method('searchDisplayName');
        $this->groupManager->expects($this->never())->method('search');

        $this->assertSame([], $this->service->searchPrincipals('   ', 10, 'caller'));
    }

    public function testSearchPrincipalsEnumerationAllowedReturnsPrefixMatches(): void {
        $this->withEnumerationSettings('yes', 'no');

        $this->groupManager->method('search')->with('al', 5)
            ->willReturn([$this->mockGroup('alpha', 'Alpha')]);
        $this->userManager->method('searchDisplayName')->with('al', 5)
            ->willReturn([$this->mockUser('alice', 'Alice'), $this->mockUser('alan', 'Alan')]);
        // No group membership lookups needed when not restricted.
        $this->groupManager->expects($this->never())->method('getUserGroups');

        $results = $this->service->searchPrincipals('al', 10, 'caller');

        $ids = array_column($results, 'id');
        sort($ids);
        $this->assertSame(['alan', 'alice', 'alpha'], $ids);
    }

    public function testSearchPrincipalsEnumerationDisabledReturnsOnlyExactMatch(): void {
        $this->withEnumerationSettings('no', 'no');

        // With enumeration off, a prefix scan must NOT happen — only exact lookups.
        $this->userManager->expects($this->never())->method('searchDisplayName');
        $this->userManager->method('get')->willReturnCallback(
            fn ($id) => $id === 'alice' ? $this->mockUser('alice', 'Alice') : null
        );
        // Exact group lookup: 'alice' is neither a GID nor a group display name.
        // A bounded display-name search is allowed (it only returns exact,
        // case-insensitive matches), but here it yields nothing.
        $this->groupManager->method('get')->willReturn(null);
        $this->groupManager->method('search')->willReturn([]);

        $results = $this->service->searchPrincipals('alice', 10, 'caller');

        $this->assertCount(1, $results);
        $this->assertSame(['type' => 'user', 'id' => 'alice', 'name' => 'Alice'], $results[0]);
    }

    public function testSearchPrincipalsEnumerationDisabledNoExactMatchReturnsNothing(): void {
        $this->withEnumerationSettings('no', 'no');

        $this->userManager->method('get')->willReturn(null);
        $this->groupManager->method('get')->willReturn(null);
        // Bounded display-name searches are permitted but return no exact match,
        // so nothing is leaked.
        $this->userManager->method('searchDisplayName')->willReturn([]);
        $this->groupManager->method('search')->willReturn([]);

        // 'al' resolves to nothing exactly → directory is not enumerable.
        $this->assertSame([], $this->service->searchPrincipals('al', 10, 'caller'));
    }

    public function testSearchPrincipalsRestrictToGroupScopesUsersToSharedGroups(): void {
        $this->withEnumerationSettings('yes', 'yes');

        $caller = $this->mockUser('caller', 'Caller');
        // The caller's group 'team' contains caller + bob (but not eve).
        $team = $this->mockGroup('team', 'Team', [$caller, $this->mockUser('bob', 'Bob')]);

        // getUserGroupIds(caller) → ['team']
        $this->userManager->method('get')->willReturnCallback(
            fn ($id) => $id === 'caller' ? $caller : null
        );
        $this->groupManager->method('getUserGroups')->with($caller)->willReturn([$team]);
        // group lookups for scoping + group search
        $this->groupManager->method('get')->willReturnCallback(
            fn ($gid) => $gid === 'team' ? $team : null
        );
        $this->groupManager->method('search')->willReturn([$team, $this->mockGroup('other', 'Other')]);

        // The directory contains bob (shared group) and eve (no shared group).
        $this->userManager->method('searchDisplayName')->willReturn([
            $this->mockUser('bob', 'Bob'),
            $this->mockUser('eve', 'Eve'),
        ]);

        $results = $this->service->searchPrincipals('e', 10, 'caller');

        $userIds = array_column(array_filter($results, fn ($r) => $r['type'] === 'user'), 'id');
        $groupIds = array_column(array_filter($results, fn ($r) => $r['type'] === 'group'), 'id');
        // eve is filtered out (no shared group); bob remains.
        $this->assertContains('bob', $userIds);
        $this->assertNotContains('eve', $userIds);
        // group results limited to the caller's own groups; 'other' filtered out.
        $this->assertContains('team', $groupIds);
        $this->assertNotContains('other', $groupIds);
    }

    public function testSearchPrincipalsRestrictToGroupWithoutCallerLeaksNothing(): void {
        $this->withEnumerationSettings('yes', 'yes');

        // Anonymous-ish call (no caller): user scoping cannot be computed, so no
        // user results may leak, and groups (no caller groups) are empty too.
        $this->userManager->method('searchDisplayName')->willReturn([
            $this->mockUser('bob', 'Bob'),
        ]);
        $this->groupManager->method('search')->willReturn([$this->mockGroup('team', 'Team')]);

        $results = $this->service->searchPrincipals('b', 10, null);

        $this->assertSame([], $results);
    }
}

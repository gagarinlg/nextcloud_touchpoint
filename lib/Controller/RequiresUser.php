<?php

// SPDX-FileCopyrightText: 2026 CRM Notes Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\CrmNotes\Controller;

use OCP\IUserSession;

/**
 * Resolves the current user's UID and refuses to coerce an unauthenticated
 * session into an empty string. Controllers using this trait must expose an
 * IUserSession via the $userSession property.
 */
trait RequiresUser {

    private function getUserId(): string {
        /** @var IUserSession $session */
        $session = $this->userSession;
        $user = $session->getUser();
        if ($user === null) {
            throw new UnauthenticatedException('No authenticated user in session');
        }
        return $user->getUID();
    }
}

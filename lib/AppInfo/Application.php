<?php

// SPDX-FileCopyrightText: 2026 CRM Notes Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\CrmNotes\AppInfo;

use OCA\Contacts\Event\LoadContactsOcaApiEvent;
use OCA\CrmNotes\Listener\LoadContactsTabListener;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap {

    public const APP_ID = 'crm_notes';

    public function __construct(array $params = []) {
        parent::__construct(self::APP_ID, $params);
    }

    public function register(IRegistrationContext $context): void {
        $context->registerEventListener(
            LoadContactsOcaApiEvent::class,
            LoadContactsTabListener::class,
        );
    }

    public function boot(IBootContext $context): void {
    }
}

<?php

// SPDX-FileCopyrightText: 2026 Touchpoint Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Touchpoint\AppInfo;

use OCA\Touchpoint\Listener\LoadContactsTabListener;
use OCA\Touchpoint\Search\NoteSearchProvider;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;

class Application extends App implements IBootstrap {

    public const APP_ID = 'touchpoint';

    public function __construct(array $params = []) {
        parent::__construct(self::APP_ID, $params);
    }

    public function register(IRegistrationContext $context): void {
        // The Contacts app exposes no extension point for adding a section to its
        // contact-detail view (and its LoadContactsOcaApiEvent only fires when a
        // consumer dispatches it, never on the Contacts page itself). So hook the
        // global before-render event and load our integration script whenever the
        // page being rendered belongs to the Contacts app; the script injects the
        // notes panel client-side.
        $context->registerEventListener(
            BeforeTemplateRenderedEvent::class,
            LoadContactsTabListener::class,
        );
        $context->registerSearchProvider(NoteSearchProvider::class);
    }

    public function boot(IBootContext $context): void {
    }
}

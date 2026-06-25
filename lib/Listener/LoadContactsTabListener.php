<?php

// SPDX-FileCopyrightText: 2026 CRM Notes Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\CrmNotes\Listener;

use OCA\Contacts\Event\LoadContactsOcaApiEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

/**
 * @template-implements IEventListener<LoadContactsOcaApiEvent>
 */
class LoadContactsTabListener implements IEventListener {

    public function handle(Event $event): void {
        if (!($event instanceof LoadContactsOcaApiEvent)) {
            return;
        }
        Util::addScript('crm_notes', 'crm_notes-contacts-integration', 'crm_notes');
    }
}

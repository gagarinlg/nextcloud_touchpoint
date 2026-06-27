<?php

// SPDX-FileCopyrightText: 2026 Touchpoint Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Touchpoint\Listener;

use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

/**
 * Injects the Touchpoint panel into the Contacts app's contact-detail view.
 *
 * The Contacts app provides no dedicated extension point for adding a section to
 * its detail view, so we hook the global before-render event: when the page being
 * rendered belongs to the Contacts app we load our integration script, which adds
 * the notes panel to the open contact client-side.
 *
 * @template-implements IEventListener<BeforeTemplateRenderedEvent>
 */
class LoadContactsTabListener implements IEventListener {

    public function handle(Event $event): void {
        if (!($event instanceof BeforeTemplateRenderedEvent)) {
            return;
        }
        // Only act on the Contacts app's own pages — this event fires for every
        // server-rendered page in the instance.
        if ($event->getResponse()->getApp() !== 'contacts') {
            return;
        }
        Util::addScript('touchpoint', 'touchpoint-contacts-integration', 'touchpoint');
    }
}

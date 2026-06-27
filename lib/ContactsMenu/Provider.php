<?php

// SPDX-FileCopyrightText: 2026 Touchpoint Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Touchpoint\ContactsMenu;

use OCP\Contacts\ContactsMenu\IActionFactory;
use OCP\Contacts\ContactsMenu\IEntry;
use OCP\Contacts\ContactsMenu\IProvider;
use OCP\IL10N;
use OCP\IURLGenerator;

class Provider implements IProvider {

    public function __construct(
        private IURLGenerator $urlGenerator,
        private IActionFactory $actionFactory,
        private IL10N $l10n,
    ) {
    }

    public function process(IEntry $entry): void {
        $uid = $entry->getProperty('UID');
        if ($uid === null) {
            return;
        }

        if ($entry->getProperty('isLocalSystemBook') === true) {
            return;
        }

        $iconUrl = $this->urlGenerator->getAbsoluteURL(
            $this->urlGenerator->imagePath('touchpoint', 'app.svg')
        );

        $notesUrl = $this->urlGenerator->getAbsoluteURL(
            $this->urlGenerator->linkToRoute('touchpoint.page.index') . '#contact/' . urlencode($uid)
        );

        $action = $this->actionFactory->newLinkAction(
            $iconUrl,
            $this->l10n->t('Touchpoint'),
            $notesUrl,
        );
        $action->setPriority(10);
        $entry->addAction($action);
    }
}

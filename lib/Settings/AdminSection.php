<?php

// SPDX-FileCopyrightText: 2026 Touchpoint Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Touchpoint\Settings;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

class AdminSection implements IIconSection {

    public function __construct(
        private IL10N $l,
        private IURLGenerator $urlGenerator,
    ) {
    }

    public function getID(): string {
        return 'touchpoint';
    }

    public function getName(): string {
        return $this->l->t('Touchpoint');
    }

    public function getPriority(): int {
        return 75;
    }

    public function getIcon(): string {
        return $this->urlGenerator->imagePath('touchpoint', 'app-dark.svg');
    }
}

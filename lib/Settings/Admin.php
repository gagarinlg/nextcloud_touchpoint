<?php

// SPDX-FileCopyrightText: 2026 CRM Notes Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\CrmNotes\Settings;

use OCA\CrmNotes\AppInfo\Application;
use OCA\CrmNotes\Service\SettingsService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IInitialStateService;
use OCP\Settings\ISettings;
use OCP\Util;

class Admin implements ISettings {

    public function __construct(
        private SettingsService $settingsService,
        private IInitialStateService $initialStateService,
    ) {
    }

    public function getForm(): TemplateResponse {
        $this->initialStateService->provideInitialState(
            Application::APP_ID,
            'notesPublic',
            $this->settingsService->isNotesPublic(),
        );
        Util::addScript(Application::APP_ID, 'crm_notes-adminSettings', Application::APP_ID);
        return new TemplateResponse(Application::APP_ID, 'admin', [], 'blank');
    }

    public function getSection(): string {
        return 'crm_notes';
    }

    public function getPriority(): int {
        return 50;
    }
}

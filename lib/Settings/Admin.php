<?php

// SPDX-FileCopyrightText: 2026 Touchpoint Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Touchpoint\Settings;

use OCA\Touchpoint\AppInfo\Application;
use OCA\Touchpoint\Service\NoteTypeService;
use OCA\Touchpoint\Service\SettingsService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\Settings\ISettings;
use OCP\Util;

class Admin implements ISettings {

    public function __construct(
        private SettingsService $settingsService,
        private NoteTypeService $noteTypeService,
        private IInitialState $initialState,
    ) {
    }

    public function getForm(): TemplateResponse {
        $this->initialState->provideInitialState(
            'notesPublic',
            $this->settingsService->isNotesPublic(),
        );
        // Ensure the five global defaults exist before reading them back — an
        // admin opening Settings > Touchpoint may be the very first person to
        // touch this instance, and PageController::index() (which is the other
        // seeding trigger) may never have run yet. seedDefaults() is instance-
        // scoped (idempotent, guarded by the UNIQUE(user_id, name) index) and
        // its $userId argument is not used for the seeded rows themselves
        // (they're stored with user_id=''), so passing '' here is correct.
        // It also returns the resulting global-defaults set directly, so no
        // separate findGlobalDefaults() call is needed to read them back.
        $this->initialState->provideInitialState(
            'globalNoteTypes',
            $this->noteTypeService->seedDefaults(''),
        );
        Util::addStyle(Application::APP_ID, 'touchpoint-adminSettings');
        Util::addScript(Application::APP_ID, 'touchpoint-adminSettings', Application::APP_ID);
        return new TemplateResponse(Application::APP_ID, 'admin', [], 'blank');
    }

    public function getSection(): string {
        return 'touchpoint';
    }

    public function getPriority(): int {
        return 50;
    }
}

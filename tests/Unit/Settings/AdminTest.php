<?php

// SPDX-FileCopyrightText: 2026 Touchpoint Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Touchpoint\Tests\Unit\Settings;

use OCA\Touchpoint\Db\NoteType;
use OCA\Touchpoint\Service\NoteTypeService;
use OCA\Touchpoint\Service\SettingsService;
use OCA\Touchpoint\Settings\Admin;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use PHPUnit\Framework\TestCase;

class AdminTest extends TestCase {

    private Admin $admin;
    private SettingsService $settingsService;
    private NoteTypeService $noteTypeService;
    private IInitialState $initialState;

    protected function setUp(): void {
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->noteTypeService = $this->createMock(NoteTypeService::class);
        $this->initialState = $this->createMock(IInitialState::class);

        $this->admin = new Admin(
            $this->settingsService,
            $this->noteTypeService,
            $this->initialState,
        );
    }

    public function testGetFormSeedsGlobalDefaults(): void {
        // The seed call must happen even when PageController::index() has never
        // run for this instance (an admin's very first action being Settings >
        // Touchpoint is a plausible first-run path) — getForm() must not rely on
        // someone else having opened the app page first.
        $this->noteTypeService->expects($this->once())
            ->method('seedDefaults')
            ->with('')
            ->willReturn([]);

        $this->settingsService->method('isNotesPublic')->willReturn(false);

        $result = $this->admin->getForm();
        $this->assertInstanceOf(TemplateResponse::class, $result);
    }

    public function testGetFormProvidesGlobalNoteTypesFromSeedDefaultsReturnValue(): void {
        // seedDefaults() now returns the resulting global-defaults set directly
        // (either the pre-existing rows or the freshly-inserted ones) — getForm()
        // must use that return value rather than issuing a second,
        // now-nonexistent-on-this-mock findGlobalDefaults() call to read them back.
        $noteType = $this->createMock(NoteType::class);
        $this->noteTypeService->expects($this->once())
            ->method('seedDefaults')
            ->with('')
            ->willReturn([$noteType]);

        $this->settingsService->method('isNotesPublic')->willReturn(true);

        $this->initialState->expects($this->exactly(2))
            ->method('provideInitialState')
            ->willReturnCallback(function (string $key, $value) use ($noteType) {
                if ($key === 'globalNoteTypes') {
                    $this->assertSame([$noteType], $value);
                } elseif ($key === 'notesPublic') {
                    $this->assertTrue($value);
                } else {
                    $this->fail('Unexpected initial state key: ' . $key);
                }
            });

        $this->admin->getForm();
    }

    public function testGetFormReturnsBlankTemplateResponse(): void {
        $this->settingsService->method('isNotesPublic')->willReturn(false);
        $this->noteTypeService->method('seedDefaults')->willReturn([]);

        $result = $this->admin->getForm();
        $this->assertInstanceOf(TemplateResponse::class, $result);
    }

    public function testGetSectionReturnsTouchpoint(): void {
        $this->assertSame('touchpoint', $this->admin->getSection());
    }

    public function testGetPriorityReturns50(): void {
        $this->assertSame(50, $this->admin->getPriority());
    }
}

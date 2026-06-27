<?php

// SPDX-FileCopyrightText: 2026 Touchpoint Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Touchpoint\Tests\Unit\ContactsMenu;

use OCA\Touchpoint\ContactsMenu\Provider;
use OCP\Contacts\ContactsMenu\IAction;
use OCP\Contacts\ContactsMenu\IActionFactory;
use OCP\Contacts\ContactsMenu\IEntry;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

class ProviderTest extends TestCase {

    private Provider $provider;
    private IURLGenerator $urlGenerator;
    private IActionFactory $actionFactory;
    private IL10N $l10n;

    protected function setUp(): void {
        $this->urlGenerator = $this->createMock(IURLGenerator::class);
        $this->actionFactory = $this->createMock(IActionFactory::class);
        $this->l10n = $this->createMock(IL10N::class);
        $this->l10n->method('t')->willReturnArgument(0);

        $this->provider = new Provider(
            $this->urlGenerator,
            $this->actionFactory,
            $this->l10n,
        );
    }

    public function testProcessAddsAction(): void {
        $entry = $this->createMock(IEntry::class);
        $entry->method('getProperty')
            ->willReturnMap([
                ['UID', 'contact-123'],
                ['isLocalSystemBook', false],
            ]);

        $this->urlGenerator->method('imagePath')
            ->with('touchpoint', 'app.svg')
            ->willReturn('/apps/touchpoint/img/app.svg');

        $this->urlGenerator->method('linkToRoute')
            ->with('touchpoint.page.index')
            ->willReturn('/apps/touchpoint/');

        $this->urlGenerator->method('getAbsoluteURL')
            ->willReturnCallback(fn (string $url) => 'https://cloud.example.com' . $url);

        $action = $this->createMock(IAction::class);
        $action->expects($this->once())
            ->method('setPriority')
            ->with(10);

        $this->actionFactory->expects($this->once())
            ->method('newLinkAction')
            ->with(
                'https://cloud.example.com/apps/touchpoint/img/app.svg',
                'Touchpoint',
                'https://cloud.example.com/apps/touchpoint/#contact/contact-123',
            )
            ->willReturn($action);

        $entry->expects($this->once())
            ->method('addAction')
            ->with($action);

        $this->provider->process($entry);
    }

    public function testProcessSkipsNullUid(): void {
        $entry = $this->createMock(IEntry::class);
        $entry->method('getProperty')
            ->with('UID')
            ->willReturn(null);

        $entry->expects($this->never())->method('addAction');
        $this->actionFactory->expects($this->never())->method('newLinkAction');

        $this->provider->process($entry);
    }

    public function testProcessSkipsSystemBook(): void {
        $entry = $this->createMock(IEntry::class);
        $entry->method('getProperty')
            ->willReturnMap([
                ['UID', 'system-user'],
                ['isLocalSystemBook', true],
            ]);

        $entry->expects($this->never())->method('addAction');

        $this->provider->process($entry);
    }

    public function testProcessEncodesUidInUrl(): void {
        $entry = $this->createMock(IEntry::class);
        $entry->method('getProperty')
            ->willReturnMap([
                ['UID', 'uid with spaces'],
                ['isLocalSystemBook', false],
            ]);

        $this->urlGenerator->method('imagePath')->willReturn('/img/app.svg');
        $this->urlGenerator->method('linkToRoute')->willReturn('/apps/touchpoint/');
        $this->urlGenerator->method('getAbsoluteURL')
            ->willReturnCallback(fn (string $url) => 'https://nc.test' . $url);

        $action = $this->createMock(IAction::class);
        $action->method('setPriority');

        $this->actionFactory->expects($this->once())
            ->method('newLinkAction')
            ->with(
                $this->anything(),
                $this->anything(),
                'https://nc.test/apps/touchpoint/#contact/uid+with+spaces',
            )
            ->willReturn($action);

        $entry->method('addAction');
        $this->provider->process($entry);
    }

    public function testProcessUsesTranslation(): void {
        $this->l10n = $this->createMock(IL10N::class);
        $this->l10n->expects($this->once())
            ->method('t')
            ->with('Touchpoint')
            ->willReturn('CRM Notizen');

        $provider = new Provider($this->urlGenerator, $this->actionFactory, $this->l10n);

        $entry = $this->createMock(IEntry::class);
        $entry->method('getProperty')
            ->willReturnMap([
                ['UID', 'uid-1'],
                ['isLocalSystemBook', false],
            ]);

        $this->urlGenerator->method('imagePath')->willReturn('/img.svg');
        $this->urlGenerator->method('linkToRoute')->willReturn('/route');
        $this->urlGenerator->method('getAbsoluteURL')->willReturnArgument(0);

        $action = $this->createMock(IAction::class);
        $action->method('setPriority');

        $this->actionFactory->expects($this->once())
            ->method('newLinkAction')
            ->with($this->anything(), 'CRM Notizen', $this->anything())
            ->willReturn($action);

        $entry->method('addAction');
        $provider->process($entry);
    }
}

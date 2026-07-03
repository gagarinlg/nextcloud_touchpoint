<?php

// SPDX-FileCopyrightText: 2026 Touchpoint Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Touchpoint\Tests\Unit\AppInfo;

use OCA\Touchpoint\AppInfo\Application;
use OCA\Touchpoint\Notification\Notifier;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;

class ApplicationTest extends TestCase {

    public function testAppId(): void {
        $this->assertSame('touchpoint', Application::APP_ID);
    }

    public function testConstructor(): void {
        $app = new Application();
        $this->assertInstanceOf(Application::class, $app);
    }

    public function testRegister(): void {
        $context = $this->createMock(IRegistrationContext::class);
        $context->expects($this->once())
            ->method('registerNotifierService')
            ->with(Notifier::class);

        $app = new Application();
        $app->register($context);
    }

    public function testBoot(): void {
        $context = $this->createMock(IBootContext::class);
        $app = new Application();
        $app->boot($context);
        // Empty method — just ensure it doesn't throw
        $this->assertTrue(true);
    }
}

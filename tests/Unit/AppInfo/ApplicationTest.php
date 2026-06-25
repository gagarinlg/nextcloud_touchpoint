<?php

declare(strict_types=1);

namespace OCA\CrmNotes\Tests\Unit\AppInfo;

use OCA\CrmNotes\AppInfo\Application;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;

class ApplicationTest extends TestCase {

    public function testAppId(): void {
        $this->assertSame('crm_notes', Application::APP_ID);
    }

    public function testConstructor(): void {
        $app = new Application();
        $this->assertInstanceOf(Application::class, $app);
    }

    public function testRegister(): void {
        $context = $this->createMock(IRegistrationContext::class);
        $app = new Application();
        $app->register($context);
        // Empty method — just ensure it doesn't throw
        $this->assertTrue(true);
    }

    public function testBoot(): void {
        $context = $this->createMock(IBootContext::class);
        $app = new Application();
        $app->boot($context);
        // Empty method — just ensure it doesn't throw
        $this->assertTrue(true);
    }
}

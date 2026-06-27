<?php

// SPDX-FileCopyrightText: 2026 Touchpoint Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Touchpoint\Tests\Unit\Controller;

use OCA\Touchpoint\Service\NoteNotFoundException;
use OCA\Touchpoint\Service\NoteTypeNotFoundException;
use OCP\AppFramework\Http\Http;
use OCP\AppFramework\Http\JSONResponse;
use PHPUnit\Framework\TestCase;

/**
 * Test the ErrorHandler trait via anonymous class.
 */
class ErrorHandlerTest extends TestCase {

    private object $handler;

    protected function setUp(): void {
        $this->handler = new class {
            use \OCA\Touchpoint\Controller\ErrorHandler;

            public function callHandleNotFound(\Closure $callback): \OCP\AppFramework\Http\JSONResponse {
                return $this->handleNotFound($callback);
            }
        };
    }

    public function testHandleNotFoundSuccess(): void {
        $result = $this->handler->callHandleNotFound(fn () => ['id' => 1, 'name' => 'Test']);

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertSame(200, $result->getStatus());
        $this->assertSame(['id' => 1, 'name' => 'Test'], $result->getData());
    }

    public function testHandleNotFoundWithNoteNotFoundException(): void {
        $result = $this->handler->callHandleNotFound(function () {
            throw new NoteNotFoundException('Note not found');
        });

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertSame(Http::STATUS_NOT_FOUND, $result->getStatus());
        // The handler returns a generic, non-leaking message rather than the
        // raw exception message.
        $this->assertSame('Not found', $result->getData()['message']);
    }

    public function testHandleNotFoundWithNoteTypeNotFoundException(): void {
        $result = $this->handler->callHandleNotFound(function () {
            throw new NoteTypeNotFoundException('Type not found');
        });

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertSame(Http::STATUS_NOT_FOUND, $result->getStatus());
        $this->assertSame('Not found', $result->getData()['message']);
    }

    public function testHandleNotFoundWithScalarReturn(): void {
        $result = $this->handler->callHandleNotFound(fn () => 42);

        $this->assertSame(200, $result->getStatus());
        $this->assertSame(42, $result->getData());
    }

    public function testHandleNotFoundWithNullReturn(): void {
        $result = $this->handler->callHandleNotFound(fn () => null);

        $this->assertSame(200, $result->getStatus());
        $this->assertNull($result->getData());
    }

    public function testHandleNotFoundReturnsGenericMessageNotExceptionDetail(): void {
        // Internal detail must never leak to the client.
        $msg = 'Custom error: entity 42 does not exist';
        $result = $this->handler->callHandleNotFound(function () use ($msg) {
            throw new NoteNotFoundException($msg);
        });

        $this->assertSame('Not found', $result->getData()['message']);
        $this->assertStringNotContainsString('42', $result->getData()['message']);
    }

    public function testHandleNotFoundConvertsUnexpectedExceptionTo500(): void {
        // Unexpected throwables are caught and reported as a generic 500 so DB
        // internals are not exposed.
        $result = $this->handler->callHandleNotFound(function () {
            throw new \RuntimeException('Unexpected error');
        });

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $result->getStatus());
        $this->assertSame('An unexpected error occurred', $result->getData()['message']);
    }
}

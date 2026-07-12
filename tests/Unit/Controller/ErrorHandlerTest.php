<?php

// SPDX-FileCopyrightText: 2026 Touchpoint Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Touchpoint\Tests\Unit\Controller;

use OCA\Touchpoint\Service\NoteNotFoundException;
use OCA\Touchpoint\Service\NoteTypeNotFoundException;
use OCA\Touchpoint\Service\NoteValidationException;
use OCP\AppFramework\Http\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

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

    /**
     * A real IL10N::t() renders via vsprintf() internally
     * (OC\L10N\L10NString::__toString()) — mocked IL10N instances elsewhere
     * in this suite use willReturnArgument(0), which discards $parameters and
     * never performs real substitution, masking bugs where a message is
     * translated twice or contains a stray '%' with no supplied argument.
     * This fake reproduces the real vsprintf() semantics so those bugs are
     * actually caught here.
     */
    private function makeVsprintfL10n(): IL10N {
        return new class implements IL10N {
            public function t(string $text, $parameters = []): string {
                $parameters = \is_array($parameters) ? $parameters : [$parameters];
                return \vsprintf($text, $parameters);
            }
        };
    }

    private function makeHandlerWithL10n(IL10N $l10n): object {
        return new class($l10n) {
            use \OCA\Touchpoint\Controller\ErrorHandler;

            public function __construct(private IL10N $l10n) {
            }

            public function callHandleNotFound(\Closure $callback): \OCP\AppFramework\Http\JSONResponse {
                return $this->handleNotFound($callback);
            }
        };
    }

    private function makeHandlerWithL10nAndLogger(IL10N $l10n, LoggerInterface $logger): object {
        return new class($l10n, $logger) {
            use \OCA\Touchpoint\Controller\ErrorHandler;

            public function __construct(private IL10N $l10n, private LoggerInterface $logger) {
            }

            public function callHandleNotFound(\Closure $callback): \OCP\AppFramework\Http\JSONResponse {
                return $this->handleNotFound($callback);
            }
        };
    }

    public function testValidationExceptionWithStrayPercentDoesNotCrashAndReturnsCleanBadRequest(): void {
        // Reproduces the double-translation ValueError: a NoteValidationException
        // message that itself contains an unresolved '%' sequence (e.g. an
        // attacker-controlled icon token echoed back into "Unknown icon: %s"
        // rendered ONCE already) must not make translateError()'s single
        // remaining IL10N::t() call blow up with
        // "ValueError: The arguments array must contain 1 items, 0 given".
        $handler = $this->makeHandlerWithL10n($this->makeVsprintfL10n());

        $result = $handler->callHandleNotFound(function () {
            throw new NoteValidationException('Unknown icon: 100%d');
        });

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertSame(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $this->assertSame('Unknown icon: 100%d', $result->getData()['message']);
    }

    public function testValidationExceptionWithBarePercentSDoesNotCrash(): void {
        $handler = $this->makeHandlerWithL10n($this->makeVsprintfL10n());

        $result = $handler->callHandleNotFound(function () {
            throw new NoteValidationException('Unknown icon: %s');
        });

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertSame(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $this->assertSame('Unknown icon: %s', $result->getData()['message']);
    }

    public function testTranslateErrorValueErrorIsLogged(): void {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        $handler = $this->makeHandlerWithL10nAndLogger($this->makeVsprintfL10n(), $logger);

        $handler->callHandleNotFound(function () {
            throw new NoteValidationException('Unknown icon: %s');
        });
    }

    public function testFixedMessagesStillTranslateNormallyWithRealL10n(): void {
        // A message with no stray '%' still round-trips through vsprintf()
        // fine (vsprintf() with zero placeholders and zero args is valid) —
        // confirms the ValueError guard doesn't mask normal translation.
        $handler = $this->makeHandlerWithL10n($this->makeVsprintfL10n());

        $result = $handler->callHandleNotFound(function () {
            throw new NoteNotFoundException('Note not found');
        });

        $this->assertSame(Http::STATUS_NOT_FOUND, $result->getStatus());
        $this->assertSame('Not found', $result->getData()['message']);
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

    public function testValidationExceptionWithErrorCodeExposesCodeInResponseBody(): void {
        // A NoteValidationException carrying an errorCode (e.g. the
        // duplicate-name rejection from NoteTypeService::mapDuplicateName())
        // must surface a stable, untranslated `code` field alongside the
        // translated `message`, so clients can branch on `code` instead of
        // string-matching the locale-dependent message text (docs/API.md's
        // Error handling section).
        $result = $this->handler->callHandleNotFound(function () {
            throw new NoteValidationException('A note type with this name already exists', 'duplicate_name');
        });

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertSame(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $this->assertSame('duplicate_name', $result->getData()['code']);
    }

    public function testValidationExceptionWithoutErrorCodeOmitsCodeField(): void {
        // Existing NoteValidationException call sites that pass no code
        // (the vast majority) must not gain a spurious `code` key.
        $result = $this->handler->callHandleNotFound(function () {
            throw new NoteValidationException('Name must not be empty');
        });

        $this->assertSame(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $this->assertArrayNotHasKey('code', $result->getData());
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

<?php

// SPDX-FileCopyrightText: 2026 Touchpoint Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Touchpoint\Service;

class NoteValidationException extends \Exception {

    /**
     * Optional stable, machine-readable error code (e.g. 'duplicate_name'),
     * exposed by ErrorHandler alongside the translated `message` so clients
     * can branch on a value that survives locale/wording changes instead of
     * string-matching the (translated) message text. Null when the
     * validation failure has no dedicated code — callers must fall back to
     * the HTTP status code alone in that case.
     *
     * Named getErrorCode() rather than getCode(): \Exception::getCode() is
     * declared `final` (returns int), so it cannot be overridden/widened to
     * a nullable string.
     */
    public function __construct(
        string $message,
        private readonly ?string $errorCode = null,
    ) {
        parent::__construct($message);
    }

    public function getErrorCode(): ?string {
        return $this->errorCode;
    }
}

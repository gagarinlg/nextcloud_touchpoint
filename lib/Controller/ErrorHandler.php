<?php

// SPDX-FileCopyrightText: 2026 Touchpoint Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Touchpoint\Controller;

use Closure;
use OCA\Touchpoint\Service\NoteForbiddenException;
use OCA\Touchpoint\Service\NoteNotFoundException;
use OCA\Touchpoint\Service\NoteTypeForbiddenException;
use OCA\Touchpoint\Service\NoteTypeInUseException;
use OCA\Touchpoint\Service\NoteTypeNotFoundException;
use OCA\Touchpoint\Service\NoteValidationException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

trait ErrorHandler {

    protected function handleNotFound(Closure $callback): JSONResponse {
        try {
            return new JSONResponse($callback());
        } catch (UnauthenticatedException $e) {
            return new JSONResponse(
                ['message' => $this->translateError('Authentication required')],
                Http::STATUS_UNAUTHORIZED,
            );
        } catch (NoteNotFoundException | NoteTypeNotFoundException $e) {
            return new JSONResponse(
                ['message' => $this->translateError('Not found')],
                Http::STATUS_NOT_FOUND,
            );
        } catch (NoteForbiddenException | NoteTypeForbiddenException $e) {
            return new JSONResponse(
                ['message' => $this->translateError('You are not allowed to perform this action')],
                Http::STATUS_FORBIDDEN,
            );
        } catch (NoteValidationException $e) {
            // Predictable input-validation failures (e.g. over-length fields)
            // surface as a clean 400 with the specific reason rather than a 500.
            // `code`, when present, is a stable machine-readable identifier
            // (e.g. 'duplicate_name') clients should branch on instead of the
            // translated `message` text — see docs/API.md's Error handling
            // section.
            $body = ['message' => $this->translateError($e->getMessage())];
            if ($e->getErrorCode() !== null) {
                $body['code'] = $e->getErrorCode();
            }
            return new JSONResponse($body, Http::STATUS_BAD_REQUEST);
        } catch (NoteTypeInUseException $e) {
            return new JSONResponse(
                ['message' => $this->translateError('This note type is still used by existing notes.')],
                Http::STATUS_CONFLICT,
            );
        } catch (\Throwable $e) {
            // Never leak internal/DB exception details to the client.
            $this->logError($e);
            return new JSONResponse(
                ['message' => $this->translateError('An unexpected error occurred')],
                Http::STATUS_INTERNAL_SERVER_ERROR,
            );
        }
    }

    /**
     * Translate a message exactly once. $message must be a raw, untranslated
     * template (a fixed literal defined in this trait, or an exception's
     * getMessage() built from raw literals/concatenation) — never the output
     * of a prior IL10N::t() call, since IL10N::t() renders via vsprintf()
     * internally (OC\L10N\L10NString::__toString()) and translating an
     * already-substituted string a second time would attempt to vsprintf()
     * it again.
     *
     * $message may echo back attacker-controlled input (e.g. an invalid
     * icon token in a validation message), which can contain a stray '%'
     * that vsprintf() can't resolve with zero supplied parameters — that
     * throws \ValueError on PHP 8.0+, not a catchable-by-callers-of-t()
     * scenario, so it is caught here and the raw (untranslated) message is
     * returned rather than letting a malformed-input value 500 the request.
     */
    private function translateError(string $message): string {
        if (property_exists($this, 'l10n') && $this->l10n instanceof IL10N) {
            try {
                return $this->l10n->t($message);
            } catch (\ValueError $e) {
                $this->logError($e);
                return $message;
            }
        }
        return $message;
    }

    private function logError(\Throwable $e): void {
        if (property_exists($this, 'logger') && $this->logger instanceof LoggerInterface) {
            $this->logger->error('Touchpoint request failed: ' . $e->getMessage(), ['exception' => $e]);
        }
    }
}

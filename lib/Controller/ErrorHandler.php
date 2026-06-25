<?php

// SPDX-FileCopyrightText: 2026 CRM Notes Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\CrmNotes\Controller;

use Closure;
use OCA\CrmNotes\Service\NoteForbiddenException;
use OCA\CrmNotes\Service\NoteNotFoundException;
use OCA\CrmNotes\Service\NoteTypeForbiddenException;
use OCA\CrmNotes\Service\NoteTypeInUseException;
use OCA\CrmNotes\Service\NoteTypeNotFoundException;
use OCA\CrmNotes\Service\NoteValidationException;
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
            return new JSONResponse(
                ['message' => $this->translateError($e->getMessage())],
                Http::STATUS_BAD_REQUEST,
            );
        } catch (NoteTypeInUseException $e) {
            return new JSONResponse(
                ['message' => $this->translateError('This note type is still used by existing notes')],
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

    private function translateError(string $message): string {
        if (property_exists($this, 'l10n') && $this->l10n instanceof IL10N) {
            return $this->l10n->t($message);
        }
        return $message;
    }

    private function logError(\Throwable $e): void {
        if (property_exists($this, 'logger') && $this->logger instanceof LoggerInterface) {
            $this->logger->error('CRM Notes request failed: ' . $e->getMessage(), ['exception' => $e]);
        }
    }
}

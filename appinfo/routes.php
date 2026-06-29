<?php

// SPDX-FileCopyrightText: 2026 Touchpoint Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

return [
    'routes' => [
        // Page routes
        ['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],

        // Contacts API
        ['name' => 'contact#index', 'url' => '/api/contacts', 'verb' => 'GET'],
        ['name' => 'contact#photo', 'url' => '/api/contacts/{uid}/photo', 'verb' => 'GET'],

        // Note CRUD
        ['name' => 'note#index', 'url' => '/api/notes', 'verb' => 'GET'],
        // note#search is declared before note#show for readability, but the
        // {id} wildcard now also carries a numeric requirement ('\d+'), so the
        // literal sub-route /api/notes/search can never be captured by it
        // regardless of router/matcher declaration-order behaviour.
        // note#byContact (/api/notes/contact/{uid}) is depth-4 and does not compete
        // with depth-3 routes regardless of declaration order.
        ['name' => 'note#search',    'url' => '/api/notes/search',               'verb' => 'GET'],
        ['name' => 'note#byContact', 'url' => '/api/notes/contact/{contactUid}', 'verb' => 'GET'],
        ['name' => 'note#show',      'url' => '/api/notes/{id}',                 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
        ['name' => 'note#create', 'url' => '/api/notes', 'verb' => 'POST'],
        ['name' => 'note#update', 'url' => '/api/notes/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
        ['name' => 'note#destroy', 'url' => '/api/notes/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '\d+']],

        // Note file attachments
        ['name' => 'note#addFile', 'url' => '/api/notes/{noteId}/files', 'verb' => 'POST', 'requirements' => ['noteId' => '\d+']],
        ['name' => 'note#removeFile', 'url' => '/api/notes/{noteId}/files/{noteFileId}', 'verb' => 'DELETE', 'requirements' => ['noteId' => '\d+', 'noteFileId' => '\d+']],

        // Settings
        ['name' => 'settings#get', 'url' => '/api/settings', 'verb' => 'GET'],
        ['name' => 'settings#save', 'url' => '/api/settings', 'verb' => 'POST'],
        ['name' => 'settings#searchPrincipals', 'url' => '/api/settings/principals', 'verb' => 'GET'],

        // Note type CRUD
        ['name' => 'note_type#index', 'url' => '/api/note-types', 'verb' => 'GET'],
        ['name' => 'note_type#show', 'url' => '/api/note-types/{id}', 'verb' => 'GET'],
        ['name' => 'note_type#usage', 'url' => '/api/note-types/{id}/usage', 'verb' => 'GET'],
        ['name' => 'note_type#create', 'url' => '/api/note-types', 'verb' => 'POST'],
        ['name' => 'note_type#update', 'url' => '/api/note-types/{id}', 'verb' => 'PUT'],
        ['name' => 'note_type#destroy', 'url' => '/api/note-types/{id}', 'verb' => 'DELETE'],
    ],
];

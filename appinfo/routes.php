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
        ['name' => 'note#show', 'url' => '/api/notes/{id}', 'verb' => 'GET'],
        ['name' => 'note#create', 'url' => '/api/notes', 'verb' => 'POST'],
        ['name' => 'note#update', 'url' => '/api/notes/{id}', 'verb' => 'PUT'],
        ['name' => 'note#destroy', 'url' => '/api/notes/{id}', 'verb' => 'DELETE'],
        ['name' => 'note#byContact', 'url' => '/api/notes/contact/{contactUid}', 'verb' => 'GET'],

        // Note file attachments
        ['name' => 'note#addFile', 'url' => '/api/notes/{noteId}/files', 'verb' => 'POST'],
        ['name' => 'note#removeFile', 'url' => '/api/notes/{noteId}/files/{noteFileId}', 'verb' => 'DELETE'],

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

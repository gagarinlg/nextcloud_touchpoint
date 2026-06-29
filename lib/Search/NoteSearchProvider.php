<?php

// SPDX-FileCopyrightText: 2026 Touchpoint Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Touchpoint\Search;

use OCA\Touchpoint\Service\NoteService;
use OCA\Touchpoint\Service\SettingsService;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Search\IProvider;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use OCP\Search\SearchResultEntry;

class NoteSearchProvider implements IProvider {

    public function __construct(
        private IURLGenerator $urlGenerator,
        private IL10N $l10n,
        private IUserSession $userSession,
        private NoteService $noteService,
        private SettingsService $settingsService,
    ) {
    }

    public function getId(): string {
        return 'touchpoint_notes';
    }

    public function getName(): string {
        return $this->l10n->t('Notes');
    }

    public function getOrder(string $route, array $routeParameters): ?int {
        // Float Touchpoint notes to the top when the user is already inside the
        // Touchpoint app (any 'touchpoint.*' route), mirroring how NC's own
        // providers rank themselves higher on their own app's route. A lower
        // number sorts earlier; elsewhere fall back to the neutral default.
        return str_starts_with($route, 'touchpoint.') ? -1 : 30;
    }

    public function search(IUser $user, ISearchQuery $query): SearchResult {
        $sessionUser = $this->userSession->getUser();
        // Defensive guard: NC Unified Search always passes the authenticated request
        // user as $user, so a UID mismatch should not occur in normal operation.
        // This guard prevents data leakage if the framework behavior changes or if
        // this provider is invoked from an unexpected context (e.g., a future
        // multi-user aggregation feature). Return empty rather than throwing to
        // avoid suppressing other providers' results in the search framework.
        if ($sessionUser === null || $sessionUser->getUID() !== $user->getUID()) {
            return SearchResult::complete($this->getName(), []);
        }

        // Belt-and-suspenders: NoteService::search() also returns [] in public mode.
        // Public mode (admin setting) makes all notes readable by any authenticated
        // user. Unified Search results are scoped per-user in the NC UI; returning
        // all notes here would surface other users' notes to any authenticated
        // searcher without the expected per-user ownership context.
        // Return empty results in public mode.
        if ($this->settingsService->isNotesPublic()) {
            return SearchResult::complete($this->getName(), []);
        }

        $rawCursor = $query->getCursor();
        // getCursor() returns int|string|null. A string cursor (possible in
        // multi-provider environments) is treated as offset 0; never pass
        // it directly to setFirstResult().
        $offset = is_int($rawCursor) ? $rawCursor : 0;

        $notes = $this->noteService->search(
            $user->getUID(),
            $query->getTerm(),
            $query->getLimit(),
            $offset,
            'newest',
        );

        $entries = [];
        foreach ($notes as $note) {
            $content = $note->getContent() ?? '';
            // Sanitisation pipeline:
            //   1. mb_substr(…, 'UTF-8') — truncate to 120 chars without splitting
            //      multibyte sequences (explicit encoding avoids mbstring.internal_encoding
            //      misconfiguration on non-standard PHP deployments).
            //   2. html_entity_decode — expand stored Markdown HTML entities so
            //      '&lt;script&gt;' becomes '<script>'.
            //   3. strip_tags — remove any resulting HTML markup.
            // Do NOT apply htmlspecialchars() here: SearchResultEntry.jsonSerialize()
            // passes the subline as a raw JSON string; the NC Unified Search Vue
            // component renders it via {{ result.subline }} (text interpolation),
            // which already HTML-encodes it. A PHP-side htmlspecialchars() would
            // cause double-encoding ('&' → '&amp;' → displayed as '&amp;').
            $subline = strip_tags(
                html_entity_decode(
                    mb_substr($content, 0, 120, 'UTF-8'),
                    ENT_QUOTES | ENT_HTML5,
                    'UTF-8',
                )
            );
            // Signal truncation with an ellipsis, matching how the rest of NC's
            // search UI indicates a cut subline. Appended post-strip_tags so it is
            // never inside the 120-char byte/character budget; the ellipsis is a
            // plain character (not markup) and needs no further sanitisation. Use
            // the original content length, not the stripped subline length, so a
            // note whose tail is only markup still shows the cut.
            if (mb_strlen($content, 'UTF-8') > 120) {
                $subline .= '…';
            }

            $uid = $note->getContactUid();
            // rawurlencode() produces %20 for spaces — correct for URI fragments.
            // urlencode() produces + for spaces, which decodeURIComponent() in
            // App.vue does NOT decode, breaking deep links for UIDs with spaces.
            // Note::getContactUid() returns a typed non-nullable string (default ''),
            // so the null check is omitted — only the empty-string case matters.
            $url = ($uid !== '')
                ? $this->urlGenerator->getAbsoluteURL(
                    $this->urlGenerator->linkToRoute('touchpoint.page.index')
                    . '#contact/' . rawurlencode($uid)
                )
                : $this->urlGenerator->getAbsoluteURL(
                    $this->urlGenerator->linkToRoute('touchpoint.page.index')
                );

            $entries[] = new SearchResultEntry(
                '',
                $note->getTitle() ?: $this->l10n->t('Untitled'),
                $subline,
                $url,
                // Give results the app glyph (5th ctor arg = icon URL/class) so
                // Touchpoint notes are recognisable in the Unified Search dropdown
                // alongside files/contacts, instead of rendering icon-less.
                $this->urlGenerator->imagePath('touchpoint', 'app.svg'),
            );
        }

        $nextCursor = $offset + count($entries);
        return SearchResult::paginated($this->getName(), $entries, $nextCursor);
    }
}

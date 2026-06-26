<?php

// SPDX-FileCopyrightText: 2026 CRM Notes Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\CrmNotes\Controller;

use OCA\CrmNotes\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Contacts\IManager;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use Sabre\VObject\Property\Binary;
use Sabre\VObject\Reader;

class ContactController extends Controller {

    /** Hard cap on decoded photo size (5 MiB) to avoid memory blow-ups. */
    private const MAX_PHOTO_BYTES = 5 * 1024 * 1024;

    /**
     * The only Content-Types we will ever serve for a contact photo. The MIME
     * is derived solely from the decoded bytes (magic-byte sniffing); anything
     * we cannot positively identify as one of these raster image formats is
     * served as application/octet-stream so it can never be treated as an
     * active document (HTML/SVG/etc.) on top-level navigation.
     */
    private const ALLOWED_PHOTO_MIMES = [
        'image/png',
        'image/jpeg',
        'image/gif',
        'image/webp',
    ];

    public function __construct(
        IRequest $request,
        private IManager $contactsManager,
        private IURLGenerator $urlGenerator,
        private LoggerInterface $logger,
        private IDBConnection $db,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    /**
     * Flatten a value returned by IManager::search() into a scalar string.
     *
     * With the ['types' => true] option, properties come back as typed
     * structures — e.g. [['type' => ['WORK'], 'value' => 'a@b.com']] — rather
     * than plain strings. This returns the first usable string value (or '' if
     * none), guaranteeing the API never emits an array/object for name/email.
     */
    private function firstContactValue(mixed $value): string {
        if (is_string($value)) {
            return $value;
        }
        if (!is_array($value)) {
            return '';
        }
        foreach ($value as $key => $item) {
            if (is_string($item) && $item !== '') {
                return $item;
            }
            if (is_array($item)) {
                if (isset($item['value']) && is_string($item['value']) && $item['value'] !== '') {
                    return $item['value'];
                }
                foreach ($item as $sub) {
                    if (is_string($sub) && $sub !== '') {
                        return $sub;
                    }
                }
            }
            // Some shapes key the entry by the value itself (e.g. 'a@b.com' => [...]).
            if (is_string($key) && $key !== '' && !ctype_digit($key)) {
                return $key;
            }
        }
        return '';
    }

    #[NoAdminRequired]
    public function index(): JSONResponse {
        $term = (string) $this->request->getParam('term', '');
        // Ask the contacts manager for the same vCard properties the Contacts app
        // renders for a list row — FN (display name), EMAIL and TEL (the Contacts
        // app's row subline is email, falling back to the first phone number) and
        // ORG (organisation) — plus PHOTO so we can decide photo availability from
        // the search result itself rather than a query keyed only on the caller's
        // own address books. This lets contacts living in shared/group address
        // books (which search() returns) get photos too. Searching these extra
        // fields also matches the Contacts app, which searches name/org/email/etc.
        $results = $this->contactsManager->search(
            $term,
            ['FN', 'EMAIL', 'TEL', 'ORG'],
            ['types' => true]
        );

        $contacts = [];
        foreach ($results as $entry) {
            $uid = $entry['UID'] ?? '';
            if ($uid === '') {
                continue;
            }

            // With the ['types' => true] search option, FN/EMAIL come back as
            // typed structures (arrays of ['type' => ..., 'value' => ...]), not
            // plain strings. Flatten to a scalar so the API always returns a
            // string — otherwise the client renders the raw object ("JSON" under
            // the name) and the contact filter throws calling .toLowerCase() on it.
            $email = $this->firstContactValue($entry['EMAIL'] ?? '');
            // TEL and ORG come back as the same typed structures as EMAIL/FN under
            // ['types' => true]; flatten each to a scalar so the API never emits a
            // typed object (which the client would render as raw JSON / crash the
            // filter's .toLowerCase()).
            $phone = $this->firstContactValue($entry['TEL'] ?? '');
            $org   = $this->firstContactValue($entry['ORG'] ?? '');

            // Use a photo URL only if this contact actually carries a PHOTO in any
            // address book the user can read (own or shared).
            $photoUrl = '';
            if ($this->entryHasPhoto($entry)) {
                $photoUrl = $this->urlGenerator->linkToRoute(
                    'crm_notes.contact.photo',
                    ['uid' => $uid]
                );
            }

            $contacts[] = [
                'uid' => $uid,
                'name' => $this->firstContactValue($entry['FN'] ?? ''),
                'email' => $email,
                // Phone (first TEL) and organisation (ORG): the Contacts app shows
                // the email — or, when there is none, the first phone number — as a
                // row's subline, and uses ORG elsewhere. Both are flattened scalars.
                'phone' => $phone,
                'org' => $org,
                'photo' => $photoUrl,
                'addressbookKey' => $entry['addressbook-key'] ?? '',
                // Whether this entry is a real Nextcloud user account (lives in the
                // system address book). The frontend uses this — rather than a
                // hyphen heuristic on the UID — to decide whether the core avatar
                // endpoint applies, so hyphenated usernames resolve correctly.
                'isUser' => $this->entryIsSystemUser($entry),
            ];
        }

        usort($contacts, fn (array $a, array $b) => strcasecmp($a['name'], $b['name']));

        return new JSONResponse($contacts);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function photo(string $uid): Http\Response {
        $photoData = $this->extractPhotoForUid($uid);
        if ($photoData === null) {
            return new JSONResponse(['message' => 'Not found'], Http::STATUS_NOT_FOUND);
        }

        [$mimeType, $binary] = $photoData;
        // Never trust an attacker-controlled MIME from the vCard PHOTO data: URI.
        // Constrain to a fixed raster-image allow-list (the MIME has already been
        // derived from the decoded bytes by detectMime()); anything else degrades
        // to a non-active octet-stream. The inline Content-Disposition with a
        // fixed filename further prevents the response being interpreted as a
        // top-level active document.
        if (!in_array($mimeType, self::ALLOWED_PHOTO_MIMES, true)) {
            $mimeType = 'application/octet-stream';
        }
        $response = new DataDisplayResponse($binary, Http::STATUS_OK, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'inline; filename="photo"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->cacheFor(3600);
        return $response;
    }

    /**
     * Whether a search-result entry represents a real Nextcloud user account
     * (it lives in the system address book) rather than a plain vCard contact.
     * The contacts manager flags system-book entries with isLocalSystemBook, and
     * the system address book uses the reserved key 'system'. We deliberately do
     * NOT infer this from the UID shape (e.g. "no hyphen"), because Nextcloud
     * allows hyphenated usernames.
     *
     * @param array<string, mixed> $entry
     */
    private function entryIsSystemUser(array $entry): bool {
        if (!empty($entry['isLocalSystemBook'])) {
            return true;
        }
        return ($entry['addressbook-key'] ?? '') === 'system';
    }

    /**
     * Whether a search result entry carries a *retrievable* PHOTO value. Works
     * for any address book the caller can read (own, shared or group), because
     * the value comes straight from the IManager search result rather than a
     * query scoped to the caller's own principal.
     *
     * Crucially this advertises a photo URL only when parsePhotoValue() would
     * actually succeed for the same value, so availability (the URL emitted by
     * index()) and retrievability (the photo endpoint) never diverge — otherwise
     * a contact with, say, an external-URI or unparseable PHOTO would be given a
     * photo URL that then 404s, showing a broken avatar.
     *
     * @param array<string, mixed> $entry
     */
    private function entryHasPhoto(array $entry): bool {
        if (!isset($entry['PHOTO'])) {
            return false;
        }
        $photo = $entry['PHOTO'];
        if (is_array($photo)) {
            $photo = $photo[0] ?? '';
        }
        if (is_array($photo)) {
            // Typed structure (['type' => ..., 'value' => ...]) under ['types' => true].
            $photo = $photo['value'] ?? '';
        }
        if (!is_string($photo) || $photo === '') {
            return false;
        }
        $photo = trim($photo);

        // Inline value (data: URI or a still-serialized "PHOTO;...:" line, or raw
        // base64): advertise only if parsePhotoValue() can actually decode it, so the
        // endpoint never 404s on a URL index() promised.
        if (str_starts_with($photo, 'data:') || preg_match('/^PHOTO[;:]/i', $photo) === 1) {
            return $this->parsePhotoValue($photo) !== null;
        }

        // Nextcloud hands an *embedded* vCard photo back from search() as a reference
        // to its own CardDAV photo export (e.g.
        // "VALUE=uri:https://host/remote.php/dav/addressbooks/.../<card>.vcf?photo").
        // That signals a real, servable embedded photo — the endpoint reads the bytes
        // straight from the stored vCard rather than fetching this URL.
        if (preg_match('#/remote\.php/dav/.+\.vcf\?photo#i', $photo) === 1) {
            return true;
        }

        // Any other external URI (arbitrary http/https, or VALUE=uri: wrapping one)
        // is a remote image we deliberately do not fetch — do not advertise it.
        if (preg_match('#^(?:VALUE=uri:)?https?://#i', $photo) === 1) {
            return false;
        }

        return $this->parsePhotoValue($photo) !== null;
    }

    /**
     * Resolve the PHOTO binary for a contact UID.
     *
     * IManager::search() does not reliably hand back inline photo bytes: for an
     * *embedded* vCard PHOTO it returns a "VALUE=uri:" reference to Nextcloud's own
     * CardDAV photo export, which we must not HTTP-fetch (SSRF / auth). Instead we
     * read the embedded PHOTO straight from the stored vCard.
     *
     * Access control: we only ever look in address books the *current user* can
     * read (IManager::getUserAddressBooks() — own, shared and group books). The
     * card lookup is constrained to those address-book ids, so a caller cannot pull
     * a photo for a contact UID that happens to exist in a book they cannot access.
     *
     * @return array{string, string}|null  [mimeType, binaryData] or null
     */
    private function extractPhotoForUid(string $uid): ?array {
        if ($uid === '') {
            return null;
        }

        // The set of address-book ids the current user may read. Empty → no access.
        $accessibleIds = [];
        try {
            foreach ($this->contactsManager->getUserAddressBooks() as $addressBook) {
                $key = $addressBook->getKey();
                if (is_numeric($key)) {
                    $accessibleIds[] = (int) $key;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->debug('CRM Notes: address book enumeration failed', ['exception' => $e]);
            return null;
        }
        if ($accessibleIds === []) {
            return null;
        }

        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('carddata')
                ->from('cards')
                ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
                ->andWhere($qb->expr()->in(
                    'addressbookid',
                    $qb->createNamedParameter($accessibleIds, IQueryBuilder::PARAM_INT_ARRAY)
                ))
                ->setMaxResults(1);
            $result = $qb->executeQuery();
            $carddata = $result->fetchOne();
            $result->closeCursor();
        } catch (\Throwable $e) {
            $this->logger->debug('CRM Notes: contact photo lookup failed', ['exception' => $e]);
            return null;
        }

        if (is_resource($carddata)) {
            $carddata = stream_get_contents($carddata);
        }
        if (!is_string($carddata) || $carddata === '') {
            return null;
        }

        return $this->extractEmbeddedPhotoFromVCard($carddata);
    }

    /**
     * Decode the embedded PHOTO from a full vCard's raw data.
     *
     * For a base64 (ENCODING=b) PHOTO, Sabre exposes the already-decoded raw image
     * bytes via the Binary property. A data: URI value is decoded through the value
     * parser; an external URI is refused there.
     *
     * @return array{string, string}|null  [mimeType, binaryData] or null
     */
    private function extractEmbeddedPhotoFromVCard(string $carddata): ?array {
        try {
            $doc = Reader::read($carddata, Reader::OPTION_FORGIVING);
        } catch (\Throwable $e) {
            $this->logger->debug('CRM Notes: could not parse contact vCard for photo', ['exception' => $e]);
            return null;
        }

        if (!isset($doc->PHOTO)) {
            return null;
        }
        $photo = $doc->PHOTO;

        if ($photo instanceof Binary) {
            $binary = (string) $photo->getValue();
            if ($binary === '' || strlen($binary) > self::MAX_PHOTO_BYTES) {
                return null;
            }
            return [$this->detectMime($binary), $binary];
        }

        $value = trim((string) $photo->getValue());
        if ($value === '') {
            return null;
        }
        return $this->parsePhotoValue($value);
    }

    /**
     * Parse a PHOTO property value (data: URI, raw/base64 binary or external URI)
     * into [mimeType, binaryData].
     *
     * @return array{string, string}|null
     */
    private function parsePhotoValue(string $value): ?array {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        // Runtime bug fix: IManager::search() can hand back the PHOTO as a raw,
        // still-serialized vCard property line (e.g.
        //   "PHOTO;ENCODING=b;TYPE=JPEG:/9j/4AAQ..."  or
        //   "PHOTO;VALUE=uri:data:image/png;base64,iVBOR...")
        // rather than a clean value. Feeding that literal string to the browser as
        // octet-stream is exactly the ~100-byte "PHOTO;VALUE=uri:..." breakage that
        // made every photo contact fall back to initials. When the value still
        // carries the property name, hand it to Sabre\VObject — which bundles with
        // Nextcloud — to decode it properly (base64 ENCODING=b becomes a Binary
        // property whose getValue() is the raw image bytes) instead of hand-rolling
        // the vCard grammar.
        if (preg_match('/^PHOTO[;:]/i', $value)) {
            // The vCard parser is authoritative for a serialized property line:
            // whatever it returns (decoded image bytes, or null for an external/
            // unparseable URI) is the answer. We must NOT fall through to the
            // value-based parsing below, or the literal "PHOTO;VALUE=uri:..."
            // string would be mis-served as octet-stream — the exact bug we fix.
            return $this->parsePhotoViaVObject($value);
        }

        // vCard 4.0 data: URI value. Per RFC 2397 the syntax is
        //   data:[<mediatype>][;base64],<data>
        // where <mediatype> may carry any number of ';param=value' segments and
        // the optional ';base64' token (if present) is the LAST one before the
        // comma. We must therefore accept:
        //   - the base64 form  (data:image/png;base64,....)
        //   - the same with extra params (data:image/jpeg;charset=...;base64,...)
        //   - the percent-encoded (non-base64) form (data:image/png,%89PNG%0D...)
        // Earlier code only matched the bare ';base64' form, so valid non-base64
        // data: photos were dropped: entryHasPhoto() advertised a URL the photo
        // endpoint then 404'd on, leaving a broken avatar in the UI.
        if (str_starts_with($value, 'data:')) {
            $comma = strpos($value, ',');
            if ($comma === false) {
                return null;
            }
            // Everything between 'data:' and the first comma is the metadata; the
            // payload is everything after it. The declared MIME is deliberately
            // never trusted — detectMime() sniffs the decoded bytes instead.
            $meta = substr($value, 5, $comma - 5);
            $payload = substr($value, $comma + 1);
            $isBase64 = preg_match('/(^|;)base64$/i', $meta) === 1;

            if ($isBase64) {
                $binary = base64_decode($payload, true);
            } else {
                // Percent-decode the URL-encoded byte sequence. rawurldecode does
                // not turn '+' into a space (correct for arbitrary binary).
                $binary = rawurldecode($payload);
            }

            if ($binary === false || $binary === '' || strlen($binary) > self::MAX_PHOTO_BYTES) {
                return null;
            }
            return [$this->detectMime($binary), $binary];
        }

        // External URI (http/https) — we do not fetch remote images.
        if (preg_match('#^https?://#i', $value)) {
            return null;
        }

        // Otherwise treat as (possibly base64-encoded) binary.
        $binary = $value;
        if ($this->looksLikeBase64($value)) {
            $decoded = base64_decode(preg_replace('/\s+/', '', $value) ?? $value, true);
            if ($decoded !== false && $decoded !== '') {
                $binary = $decoded;
            }
        }

        // $binary is provably non-empty here ($value was trimmed-non-empty at the
        // top and the base64 branch only overwrites it with a non-empty decode),
        // so the only remaining guard is the size cap.
        if (strlen($binary) > self::MAX_PHOTO_BYTES) {
            return null;
        }

        return [$this->detectMime($binary), $binary];
    }

    /**
     * Decode a still-serialized vCard PHOTO property line with Sabre\VObject.
     *
     * The line is wrapped in a minimal vCard so the bundled parser can apply the
     * proper vCard semantics: an embedded base64 photo (ENCODING=b / vCard 4.0
     * inline binary) is exposed as a Binary property whose getValue() returns the
     * decoded raw bytes, from which we sniff the MIME. A data:/http(s) URI value
     * is routed back through the value-based parser (data: URIs are decoded,
     * external URIs are refused with null so the client falls back cleanly).
     *
     * @return array{string, string}|null  [mimeType, binaryData] or null
     */
    private function parsePhotoViaVObject(string $propertyLine): ?array {
        // Fold any continuation, then wrap in the smallest valid vCard.
        $card = "BEGIN:VCARD\r\nVERSION:3.0\r\n" . $propertyLine . "\r\nEND:VCARD\r\n";
        try {
            $doc = Reader::read($card, Reader::OPTION_FORGIVING);
        } catch (\Throwable $e) {
            $this->logger->debug('CRM Notes: could not parse PHOTO vCard line', ['exception' => $e]);
            return null;
        }

        if (!isset($doc->PHOTO)) {
            return null;
        }
        $photo = $doc->PHOTO;

        // A vCard-4.0 inline 'data:' URI is a value, not an ENCODING=b binary;
        // some Sabre versions still surface it as a Binary and would then
        // base64-decode the whole "data:image/...;base64,..." string into garbage.
        // Detect the data: URI in the raw line and decode it through the
        // (correct) value parser instead.
        if (preg_match('/:\s*(data:[^\r\n]+)$/i', $propertyLine, $m)) {
            return $this->parsePhotoValue(trim($m[1]));
        }

        // Embedded binary (base64 ENCODING=b): Sabre exposes the decoded raw
        // bytes directly via the Binary property.
        if ($photo instanceof Binary) {
            $binary = (string) $photo->getValue();
            if ($binary === '' || strlen($binary) > self::MAX_PHOTO_BYTES) {
                return null;
            }
            return [$this->detectMime($binary), $binary];
        }

        // Otherwise the value is a URI string (data: or external). Re-run it
        // through the value parser so a data: URI is decoded and an external URI
        // is refused — but guard against recursing back into this method.
        $inner = trim((string) $photo->getValue());
        if ($inner === '' || preg_match('/^PHOTO[;:]/i', $inner)) {
            return null;
        }
        return $this->parsePhotoValue($inner);
    }

    private function looksLikeBase64(string $value): bool {
        $stripped = preg_replace('/\s+/', '', $value) ?? $value;
        return $stripped !== '' && preg_match('#^[A-Za-z0-9+/]+={0,2}$#', $stripped) === 1;
    }

    /**
     * Determine the image MIME type purely from the decoded bytes' magic number.
     * This is the SOLE source of the served Content-Type — the vCard-declared
     * MIME is never trusted, since it is attacker-controllable and could ask the
     * browser to render the payload as an active document (text/html, SVG).
     *
     * Returns one of self::ALLOWED_PHOTO_MIMES for a recognised raster image, or
     * application/octet-stream for anything else (so it cannot execute).
     */
    private function detectMime(string $data): string {
        if (str_starts_with($data, "\x89PNG")) {
            return 'image/png';
        }
        if (str_starts_with($data, "\xff\xd8")) {
            return 'image/jpeg';
        }
        if (str_starts_with($data, 'GIF8')) {
            return 'image/gif';
        }
        // WebP: RIFF container with a 'WEBP' fourCC at offset 8.
        if (str_starts_with($data, 'RIFF') && substr($data, 8, 4) === 'WEBP') {
            return 'image/webp';
        }
        return 'application/octet-stream';
    }
}

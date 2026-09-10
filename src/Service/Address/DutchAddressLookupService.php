<?php

declare(strict_types=1);

namespace App\Service\Address;

/**
 * Looks up a Dutch address by postcode + house number (+ optional addition)
 * against PDOK's free, public Locatieserver API (BAG-backed, no API key, no
 * paid tier — see https://github.com/PDOK/locatieserver/wiki/API-Locatieserver
 * and the live "free" endpoint below, verified by hand against the real API
 * before writing this, not from possibly-outdated examples).
 *
 * Mirrors App\Service\TurnstileVerifier's and
 * App\Service\Shipping\PostNl\PostNlRateFetcher's HTTP approach:
 * file_get_contents()+stream_context, no ext-curl dependency (works on
 * Vimexx shared hosting without a specific compiled extension), and the only
 * network-touching method (httpGet()) is `protected` specifically so tests
 * can fake it with an anonymous subclass instead of ever hitting PDOK — see
 * tests/Service/Address/DutchAddressLookupServiceTest.php.
 *
 * lookup() returns a DutchAddressLookupResult with found=false for a
 * genuinely-unmatched postcode/house-number/addition combination (PDOK was
 * reached fine, nothing matches) — that is a normal, expected outcome, not a
 * failure. It only *throws* (AddressValidationException::unavailable()) when
 * PDOK itself couldn't be reached/parsed, which callers must treat
 * completely differently: never accept an address as valid just because the
 * lookup service is temporarily down (see MAIN.MD "Dutch address
 * validation").
 *
 * Matching algorithm: PDOK's `free` endpoint is queried with exact filter
 * queries (`fq=`, not the free-text `q=` relevance search) for
 * type:adres + the normalized postcode + house number — this can return
 * more than one document (the same house number with different additions,
 * e.g. "12", "12A", "12B" are distinct BAG nummeraanduidingen). The
 * customer's normalized addition is then matched against each candidate's
 * own addition (derived from `huis_nlt` minus its numeric house-number
 * prefix, e.g. "1A-A" -> "A-A" for house number 1 — the authoritative BAG
 * suffix, rather than reconstructing it from `huisletter`/
 * `huisnummertoevoeging` separately, which can legitimately both be set on
 * the same address). Normalization strips everything but letters/digits and
 * uppercases before comparing, so "12 a", "12-A" and "12A" from the customer
 * all match a candidate suffix of "A" — cosmetic formatting differences must
 * never cause a false rejection (MAIN.MD: "Be careful not to incorrectly
 * reject legitimate addresses"). A blank customer addition only matches a
 * candidate with no addition at all, unless the postcode+house-number
 * combination resolves to exactly one address regardless of addition (a
 * single registered unit at that number) — in every other case an
 * unspecified addition among multiple real candidates is correctly treated
 * as "not found" (ambiguous), not guessed.
 */
class DutchAddressLookupService
{
    private const FREE_URL = 'https://api.pdok.nl/bzk/locatieserver/search/v3_1/free';

    private const HTTP_TIMEOUT_SECONDS = 8;

    /**
     * PDOK expects a caller to identify itself. App\Service\HttpUserAgent
     * builds one from the application and this installation's own public
     * base URL, so a copy of this CMS does not introduce itself to a public
     * service as somebody else's shop.
     */
    private const USER_AGENT_PURPOSE = 'NL address verification';

    /** @throws AddressValidationException when PDOK can't be reached/parsed right now */
    public function lookup(string $postalCode, string $houseNumber, ?string $addition): DutchAddressLookupResult
    {
        $normalizedPostcode = self::normalizePostalCode($postalCode);
        $normalizedHouseNumber = self::normalizeHouseNumber($houseNumber);

        if ($normalizedPostcode === null || $normalizedHouseNumber === null) {
            // Not even a well-formed NL postcode/house number — no point
            // asking PDOK, this can never resolve to a real address.
            return DutchAddressLookupResult::notFound();
        }

        $normalizedAddition = self::normalizeAdditionKey($addition);

        $url = self::FREE_URL . '?' . implode('&', [
            'q=' . rawurlencode('*'),
            'fq=' . rawurlencode('type:adres'),
            'fq=' . rawurlencode('postcode:' . $normalizedPostcode),
            'fq=' . rawurlencode('huisnummer:' . $normalizedHouseNumber),
            'rows=20',
        ]);

        try {
            $body = $this->httpGet($url);
        } catch (\Throwable $e) {
            error_log('[DutchAddressLookupService] PDOK request failed: ' . $e->getMessage());

            throw AddressValidationException::unavailable();
        }

        $decoded = json_decode($body, true);
        $docs = $decoded['response']['docs'] ?? null;

        if (!is_array($decoded) || !is_array($docs)) {
            error_log('[DutchAddressLookupService] PDOK returned an unreadable response.');

            throw AddressValidationException::unavailable();
        }

        if ($docs === []) {
            return DutchAddressLookupResult::notFound();
        }

        $exactMatches = array_values(array_filter(
            $docs,
            static fn (array $doc): bool => self::additionKeyOf($doc, $normalizedHouseNumber) === $normalizedAddition
        ));

        if (count($exactMatches) === 1) {
            return self::toResult($exactMatches[0], $normalizedPostcode, $normalizedHouseNumber);
        }

        // No exact addition match, but the postcode + house number combination
        // is unambiguous on its own (only one registered unit exists at all) —
        // accept it rather than reject a legitimate address purely because the
        // customer's addition field didn't line up with BAG's own bookkeeping.
        if (count($docs) === 1) {
            return self::toResult($docs[0], $normalizedPostcode, $normalizedHouseNumber);
        }

        return DutchAddressLookupResult::notFound();
    }

    /** @param array<string, mixed> $doc */
    private static function toResult(array $doc, string $fallbackPostcode, string $fallbackHouseNumber): DutchAddressLookupResult
    {
        $street = trim((string) ($doc['straatnaam'] ?? ''));
        $city = trim((string) ($doc['woonplaatsnaam'] ?? ''));
        $postcode = trim((string) ($doc['postcode'] ?? '')) ?: $fallbackPostcode;
        $houseNumber = isset($doc['huisnummer']) ? (string) $doc['huisnummer'] : $fallbackHouseNumber;

        if ($street === '' || $city === '') {
            // Should never happen for a type:adres document, but never
            // fabricate a canonical address out of a malformed one.
            return DutchAddressLookupResult::notFound();
        }

        $addition = self::additionSuffixOf($doc, $houseNumber);

        return DutchAddressLookupResult::found(
            $street,
            $city,
            $postcode,
            $houseNumber,
            $addition !== '' ? $addition : null
        );
    }

    /**
     * The authoritative addition suffix for a document: `huis_nlt` (e.g.
     * "1A-A", "12", "10A-A") with its numeric house-number prefix stripped
     * off. Falls back to concatenating huisletter/huisnummertoevoeging only
     * if `huis_nlt` is unexpectedly absent.
     *
     * @param array<string, mixed> $doc
     */
    private static function additionSuffixOf(array $doc, string $houseNumber): string
    {
        $huisNlt = $doc['huis_nlt'] ?? null;
        if (is_string($huisNlt) && str_starts_with($huisNlt, $houseNumber)) {
            return substr($huisNlt, strlen($houseNumber));
        }

        $letter = trim((string) ($doc['huisletter'] ?? ''));
        $toevoeging = trim((string) ($doc['huisnummertoevoeging'] ?? ''));
        if ($letter !== '' && $toevoeging !== '') {
            return $letter . '-' . $toevoeging;
        }

        return $letter . $toevoeging;
    }

    /** @param array<string, mixed> $doc */
    private static function additionKeyOf(array $doc, string $houseNumber): string
    {
        return self::normalizeAdditionKey(self::additionSuffixOf($doc, $houseNumber));
    }

    /** @throws \RuntimeException */
    protected function httpGet(string $url): string
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: " . \App\Service\HttpUserAgent::forPurpose(self::USER_AGENT_PURPOSE) . "\r\nAccept: application/json\r\n",
                'timeout' => self::HTTP_TIMEOUT_SECONDS,
                'ignore_errors' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);

        $statusLine = $http_response_header[0] ?? '';
        $statusOk = (bool) preg_match('#^HTTP/\S+\s+2\d\d#', $statusLine);

        if ($body === false || !$statusOk) {
            throw new \RuntimeException('Could not reach PDOK (' . ($statusLine ?: 'no response') . ')');
        }

        return $body;
    }

    /** Uppercase, no spaces, must be exactly 4 digits + 2 letters (e.g. "6511AA"); null if malformed. */
    public static function normalizePostalCode(string $raw): ?string
    {
        $value = strtoupper(preg_replace('/\s+/', '', $raw) ?? '');

        return preg_match('/^[1-9][0-9]{3}[A-Z]{2}$/', $value) === 1 ? $value : null;
    }

    /** Digits only, no leading zero beyond a bare "0"; null if not a plausible house number. */
    public static function normalizeHouseNumber(string $raw): ?string
    {
        $value = trim($raw);

        if (!preg_match('/^[0-9]{1,5}$/', $value)) {
            return null;
        }

        // Strip leading zeros ("007" -> "7") but never collapse "0" itself.
        $normalized = ltrim($value, '0');

        return $normalized === '' ? '0' : $normalized;
    }

    /** Strips everything but letters/digits and uppercases, so formatting differences never break matching. */
    public static function normalizeAdditionKey(?string $raw): string
    {
        if ($raw === null) {
            return '';
        }

        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $raw) ?? '');
    }
}

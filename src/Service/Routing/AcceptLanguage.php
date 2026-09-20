<?php

declare(strict_types=1);

namespace App\Service\Routing;

use App\Service\Language\LanguageCode;

/**
 * The `Accept-Language` header, read well enough for real browsers and for
 * nothing else (docs/multilingual/ROUTING.md).
 *
 * Step 3 of App\Service\Routing\LanguageResolver's chain, and the only step
 * that has to parse anything. It is a pure function of a header string and a
 * list of supported codes: no database, no request globals, no state — which
 * is what makes it testable against the header shapes browsers actually send.
 *
 * WHAT IT UNDERSTANDS:
 *
 *     nl,en;q=0.9,de-DE;q=0.8,*;q=0.1
 *
 *   - a quality value, defaulting to 1.0 when absent;
 *   - `q=0`, which is a browser saying "not this one" and is therefore
 *     dropped rather than ranked last;
 *   - a region or script subtag (`de-DE`, `pt-BR`, `zh-Hans`), matched on its
 *     base language, because that is all this CMS stores
 *     (App\Service\Language\LanguageCode);
 *   - anything it does not know, which is skipped.
 *
 * `*` is ignored on purpose. It means "any language will do", which is not a
 * preference; honouring it would hand an arbitrary language to a visitor who
 * expressed none, and the site default already is the answer to that.
 *
 * WHAT IT DOES NOT DO: no GeoIP, no external service, no browser-side
 * detection, and no ranking beyond q. Ties keep the order the header listed
 * them in, so a header without any q values behaves exactly like "the first
 * one you support".
 */
final class AcceptLanguage
{
    /** A header longer than this is a client trying something, not a browser. */
    private const MAX_HEADER_LENGTH = 512;

    /** At most this many entries are considered; browsers send a handful. */
    private const MAX_ENTRIES = 32;

    /**
     * The first supported language this header asks for, or null when it asks
     * for none of them.
     *
     * @param list<string> $supported the codes that may be answered with, in
     *                                the site's own order; anything outside
     *                                this list can never be returned
     */
    public static function best(string $header, array $supported): ?string
    {
        if ($supported === []) {
            return null;
        }

        foreach (self::ranked($header) as $code) {
            if (in_array($code, $supported, true)) {
                return $code;
            }
        }

        return null;
    }

    /**
     * Every base language the header names, best first, duplicates removed.
     *
     * Public because the shape of this list is the thing worth testing: the
     * q-sort, the dropped `q=0`, the `de-DE` -> `de` narrowing and the
     * ignored `*` are all visible here without a site or a registry.
     *
     * @return list<string>
     */
    public static function ranked(string $header): array
    {
        $header = trim($header);

        if ($header === '' || strlen($header) > self::MAX_HEADER_LENGTH) {
            return [];
        }

        $entries = [];
        $position = 0;

        foreach (array_slice(explode(',', $header), 0, self::MAX_ENTRIES) as $part) {
            $parsed = self::parseEntry($part);
            if ($parsed === null) {
                continue;
            }

            [$code, $quality] = $parsed;

            // A language named twice keeps its best quality and its first
            // position: "nl;q=0.2,nl" is a browser being odd, not a browser
            // changing its mind.
            if (isset($entries[$code]) && $entries[$code]['q'] >= $quality) {
                continue;
            }

            $entries[$code] = ['q' => $quality, 'position' => $entries[$code]['position'] ?? $position++];
        }

        uasort($entries, static function (array $a, array $b): int {
            return $b['q'] <=> $a['q'] ?: $a['position'] <=> $b['position'];
        });

        return array_keys($entries);
    }

    /**
     * One comma-separated entry as [base language, quality], or null when it
     * is not a language this CMS could ever answer with.
     *
     * @return array{0: string, 1: float}|null
     */
    private static function parseEntry(string $part): ?array
    {
        $pieces = explode(';', $part);
        $tag = strtolower(trim(array_shift($pieces)));

        if ($tag === '' || $tag === '*') {
            return null;
        }

        // The base language is everything before the first subtag separator.
        // Both are seen in the wild, and neither is a code this CMS stores.
        $base = LanguageCode::normalise((string) preg_split('/[-_]/', $tag)[0]);
        if ($base === null) {
            return null;
        }

        $quality = 1.0;
        foreach ($pieces as $piece) {
            $piece = strtolower(trim($piece));
            if (!str_starts_with($piece, 'q=')) {
                continue;
            }

            $value = substr($piece, 2);
            if (!is_numeric($value)) {
                // An unreadable q is not a reason to drop the language; RFC
                // 9110 says the default is 1, and that is what a browser
                // sending a malformed value most likely meant.
                continue;
            }

            $quality = max(0.0, min(1.0, (float) $value));
        }

        // q=0 is an explicit refusal, not a low preference.
        return $quality > 0.0 ? [$base, $quality] : null;
    }
}

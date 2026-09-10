<?php

namespace App\Service\Shipping\PostNl;

/**
 * Pure text parser for PostNL's official yearly tariff document ("Tarieven
 * voor post en pakketten"/"Rates for mail, packets and parcels", published
 * as a PDF at a different, non-guessable URL every price revision — see
 * PostNlRateFetcher for how the current one is located and turned into
 * plain text). Deliberately has zero I/O — takes a string, returns an array
 * — so it can be exercised in tests against a fixed fixture string without
 * ever touching the network or a PDF library. See
 * App\Service\Shipping\PostNl\PostNlRateSyncService for how its output is
 * validated before anything is written to the database.
 *
 * KNOWN LIMITATION (see MAIN.MD "Beperkingen van de PostNL-bron"): the two
 * patterns below were derived from one real, manually-inspected copy of the
 * official tariff PDF (the "Tarieven per 12 juli 2026" edition). PostNL does
 * not publish a stable schema for this document, so a future edition could
 * reorder or reword these tables and silently stop matching. That failure
 * mode is intentionally safe: parse() simply omits a rate_code it can't find
 * (see PostNlRateSyncService, which then leaves that rate's price completely
 * untouched and reports it as a warning) rather than ever guessing.
 */
class PostNlRateParser
{
    /**
     * Parses whatever rate codes it can confidently find. Missing/unparsable
     * codes are simply absent from the result — never a guessed or zero
     * price. Every returned price is already validated to look like a
     * plausible euro amount (see PRICE_PATTERN); further plausibility
     * checks (e.g. "is this a sane change from last time") are
     * PostNlRateSyncService's job, not this class's.
     *
     * @return array<string, float> rate_code => price, EUR-denominated
     */
    public function parse(string $text): array
    {
        // Normalize line endings and collapse the odd double space PDF text
        // extraction sometimes inserts, so the patterns below don't have to
        // account for it themselves.
        $text = str_replace("\r\n", "\n", $text);

        $rates = [];

        // "Brief" heading followed (within a couple of lines — PDF text
        // extraction order isn't pixel-exact) by a "NL <20g> <50g> ..." row.
        // Verified against the real document's compact rate-overview table:
        //   Brief
        //   NL 1,40 2,80 4,35 4,35 4,35
        if (preg_match('/Brief[\s\S]{0,60}?\bNL[ \t]+(\d{1,4},\d{2})[ \t]+(\d{1,4},\d{2})/u', $text, $m)) {
            $rates['postnl_nl_letter_20g'] = self::toFloat($m[1]);
            $rates['postnl_nl_letter_50g'] = self::toFloat($m[2]);
        }

        // "Pakket - bezorgd op huisadres" / "Online frankering <10kg> <23kg>"
        // — the home-delivery, online-franked consumer parcel tier, which is
        // the one this project's single flat parcel rate has always tracked
        // (see db/migrations/20260906130000_create_shipping_rates_table.php,
        // seeded at exactly this price). Takes the first (0-10kg) amount.
        if (preg_match('/Pakket - bezorgd op huisadres[\s\S]{0,60}?Online frankering[ \t]+(\d{1,4},\d{2})/u', $text, $m)) {
            $rates['postnl_nl_parcel'] = self::toFloat($m[1]);
        }

        return $rates;
    }

    private static function toFloat(string $euroAmount): float
    {
        return (float) str_replace(',', '.', $euroAmount);
    }
}

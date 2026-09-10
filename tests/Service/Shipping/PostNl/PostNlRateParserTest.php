<?php

declare(strict_types=1);

namespace Tests\Service\Shipping\PostNl;

use App\Service\Shipping\PostNl\PostNlRateParser;
use PHPUnit\Framework\TestCase;

/**
 * Fixture-based — never touches the network or a real PDF (see
 * PostNlRateFetcher for that side). The fixture text below is a trimmed,
 * verbatim excerpt of the real structure App\Service\Shipping\PostNl\
 * PostNlRateFetcher extracts from PostNL's official tariff PDF (confirmed
 * against the live "Tarieven per 12 juli 2026" edition while building this
 * feature — see PostNlRateParser's docblock for the layout-stability caveat).
 */
final class PostNlRateParserTest extends TestCase
{
    private const REAL_EXCERPT = <<<'TEXT'
        4 I  Tarieven en diensten

        Brievenbuspakje+
        Tarieven in euro's. Zie productkenmerken.
        t/m 2 kg
        Brievenbuspakje+
        Online frankering	4,55
        Frankering bij PostNL-punt	5,85

        5 I  Tarieven en diensten

        Pakketten
        Tarieven in euro's. Productkenmerken vanaf pagina 25.
        t/m 10 kg 23 kg
        Pakket - bezorgd bij PostNL-punt
        Alleen online frankering	6,95 16,95
        Pakket - bezorgd op huisadres
        Online frankering	7,45 17,45
        Frankering bij PostNL-punt	8,75 18,75

        Tarieven in euro's en vrijgesteld van btw.
        t/m20 g 50 g 100 g 350 g 2 kg
        Brief
        NL 1,40 2,80 4,35 4,35 4,35
        Internationaal2,11 4,22 6,33 8,44 10,55
        TEXT;

    public function testParsesAllThreeRateCodesFromTheRealDocumentStructure(): void
    {
        $rates = (new PostNlRateParser())->parse(self::REAL_EXCERPT);

        $this->assertSame([
            'postnl_nl_letter_20g' => 1.40,
            'postnl_nl_letter_50g' => 2.80,
            'postnl_nl_parcel' => 7.45,
        ], $rates);
    }

    public function testMissingLetterSectionOnlyYieldsParcel(): void
    {
        $text = "Pakket - bezorgd op huisadres\nOnline frankering\t7,45 17,45\n";

        $rates = (new PostNlRateParser())->parse($text);

        $this->assertSame(['postnl_nl_parcel' => 7.45], $rates);
    }

    public function testMissingParcelSectionOnlyYieldsLetterRates(): void
    {
        $text = "Brief\nNL 1,40 2,80 4,35 4,35 4,35\n";

        $rates = (new PostNlRateParser())->parse($text);

        $this->assertSame(['postnl_nl_letter_20g' => 1.40, 'postnl_nl_letter_50g' => 2.80], $rates);
    }

    public function testUnrelatedTextYieldsNoRatesRatherThanGuessing(): void
    {
        $rates = (new PostNlRateParser())->parse('PostNL wenst u een fijne dag. Geen tarieven hier te vinden.');

        $this->assertSame([], $rates);
    }

    public function testDoesNotConfuseTheParcelToCounterVariantWithTheHomeDeliveryVariant(): void
    {
        // Only the "bezorgd bij PostNL-punt" (counter) tier is present, never
        // followed by "bezorgd op huisadres" — must not match the wrong row.
        $text = "Pakket - bezorgd bij PostNL-punt\nAlleen online frankering\t6,95 16,95\n";

        $rates = (new PostNlRateParser())->parse($text);

        $this->assertSame([], $rates);
    }

    public function testAmountsWithoutACommaDecimalAreNotMatched(): void
    {
        // A hypothetical reformatted document using "1.40" instead of "1,40"
        // must not silently produce a wrong number — see class docblock:
        // an unmatched code is safely omitted, never guessed.
        $text = "Brief\nNL 1.40 2.80 4.35 4.35 4.35\n";

        $rates = (new PostNlRateParser())->parse($text);

        $this->assertSame([], $rates);
    }
}

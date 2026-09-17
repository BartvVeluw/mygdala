<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\Language\LanguageCode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What a website language code may look like (docs/multilingual/ARCHITECTURE.md).
 *
 * No database: LanguageCode only checks the shape of a string. The rule is a
 * pattern, not a list, so a language nobody wrote into PHP is as valid as
 * Dutch.
 */
final class LanguageCodeTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function languagesNoCodeNames(): array
    {
        // Languages that nothing in src/ names. They must pass for the same
        // reason `nl` does: two lowercase letters.
        return [
            'Spanish' => ['es'],
            'Portuguese' => ['pt'],
            'Polish' => ['pl'],
            'Swedish' => ['sv'],
            'Danish' => ['da'],
            'Czech' => ['cs'],
        ];
    }

    #[DataProvider('languagesNoCodeNames')]
    public function testALanguageThatNoCodeNamesIsValidAsItIs(string $code): void
    {
        self::assertTrue(LanguageCode::isValid($code));
        self::assertSame($code, LanguageCode::normalise($code));
    }

    public function testEveryPairOfLowercaseLettersIsAValidCode(): void
    {
        // The whole space, including pairs that are no language at all. If
        // this ever fails for one pair, somebody added a list.
        $checked = 0;
        foreach (range('a', 'z') as $first) {
            foreach (range('a', 'z') as $second) {
                $code = $first . $second;

                self::assertTrue(LanguageCode::isValid($code), $code);
                self::assertSame($code, LanguageCode::normalise(strtoupper($code)), strtoupper($code));
                $checked++;
            }
        }

        self::assertSame(26 * 26, $checked);
    }

    public function testCaseAndSurroundingWhitespaceAreForgiven(): void
    {
        self::assertSame('nl', LanguageCode::normalise('NL'));
        self::assertSame('en', LanguageCode::normalise(' En '));
        self::assertSame('es', LanguageCode::normalise('ES'));
        self::assertSame('pt', LanguageCode::normalise("\tpT"));
        self::assertSame('sv', LanguageCode::normalise("sv\n"));
    }

    public function testOnlyTheStoredFormIsValidWithoutNormalising(): void
    {
        self::assertFalse(LanguageCode::isValid('NL'));
        self::assertFalse(LanguageCode::isValid('Cs'));
        self::assertFalse(LanguageCode::isValid(' nl'));
        self::assertFalse(LanguageCode::isValid("da\n"), 'a trailing newline is not a valid stored code');
    }

    /** @return array<string, array{0: string|null}> */
    public static function notACode(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'only whitespace' => ['  '],
            'one letter' => ['n'],
            'three letters' => ['nld'],
            'four letters' => ['port'],
            'region with a hyphen' => ['pt-BR'],
            'region in lowercase' => ['en-gb'],
            'region with an underscore' => ['en_GB'],
            'script subtag' => ['zh-Hans'],
            'two digits' => ['12'],
            'a digit' => ['n1'],
            'an underscore' => ['e_'],
            'a dot' => ['e.'],
            'whitespace inside' => ['n l'],
            'a path' => ['../nl'],
            'markup' => ['<b>'],
            'sql' => ["nl'; DROP TABLE site_languages; --"],
            'non-ascii' => ['ñl'],
            'non-ascii capital' => ['ÉS'],
        ];
    }

    #[DataProvider('notACode')]
    public function testAnythingElseIsRefused(?string $candidate): void
    {
        self::assertNull(LanguageCode::normalise($candidate));
    }

    public function testTheStorageLeavesRoomForARegionSubtagLater(): void
    {
        // V1 refuses `pt-br`, but the column must not be the reason a later
        // version cannot accept it.
        self::assertGreaterThanOrEqual(strlen('zh-hans-cn'), LanguageCode::MAX_LENGTH);
    }
}

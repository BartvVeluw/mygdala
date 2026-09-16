<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\Language\LanguageCode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What a website language code may look like (docs/multilingual/ARCHITECTURE.md).
 *
 * No database: LanguageCode only checks the shape of a string.
 */
final class LanguageCodeTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function v1Codes(): array
    {
        return [
            'Dutch' => ['nl'],
            'English' => ['en'],
            'German' => ['de'],
            'French' => ['fr'],
            'Italian' => ['it'],
        ];
    }

    #[DataProvider('v1Codes')]
    public function testTheV1LanguagesAreValidAsTheyAre(string $code): void
    {
        self::assertTrue(LanguageCode::isValid($code));
        self::assertSame($code, LanguageCode::normalise($code));
    }

    public function testCaseAndSurroundingWhitespaceAreForgiven(): void
    {
        self::assertSame('nl', LanguageCode::normalise('NL'));
        self::assertSame('en', LanguageCode::normalise(' En '));
        self::assertSame('de', LanguageCode::normalise("de\n"));
    }

    public function testOnlyTheStoredFormIsValidWithoutNormalising(): void
    {
        self::assertFalse(LanguageCode::isValid('NL'));
        self::assertFalse(LanguageCode::isValid(' nl'));
        self::assertFalse(LanguageCode::isValid("nl\n"), 'a trailing newline is not a valid stored code');
    }

    /** @return array<string, array{0: string|null}> */
    public static function notACode(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'one letter' => ['n'],
            'three letters' => ['nld'],
            'region with a hyphen' => ['en-gb'],
            'region with an underscore' => ['pt_BR'],
            'a digit' => ['n1'],
            'whitespace inside' => ['n l'],
            'a path' => ['../nl'],
            'markup' => ['<b>'],
            'sql' => ["nl'; DROP TABLE site_languages; --"],
            'non-ascii' => ['ñl'],
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
        self::assertGreaterThanOrEqual(strlen('pt-br'), LanguageCode::MAX_LENGTH);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\Language\EntityTranslations;
use App\Service\Language\LanguageFallback;
use App\Service\Language\TranslationTable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\SiteLanguageFixture;

/**
 * The shared primitive of Multilingual 2.0 phase 4 without a database:
 * App\Service\Language\LanguageFallback (THE fallback of the typed
 * translation tables and the localized settings),
 * App\Service\Language\TranslationTable (the closed declaration whose names
 * reach SQL) and App\Service\Language\EntityTranslations (the per-table API
 * the domains put a typed face on).
 */
final class EntityTranslationsTest extends TestCase
{
    protected function setUp(): void
    {
        SiteLanguageFixture::useBilingual('nl');
    }

    protected function tearDown(): void
    {
        SiteLanguageFixture::reset();
    }

    // -------------------------------------------------------------- fallback

    public function testTheFallbackIsTheAskedLanguageThenTheDefaultThenNothing(): void
    {
        $words = ['nl' => 'Over ons', 'en' => 'About us'];

        self::assertSame('About us', LanguageFallback::resolve($words, 'en'));
        self::assertSame('Over ons', LanguageFallback::resolve(['nl' => 'Over ons'], 'en'));
        self::assertSame('Over ons', LanguageFallback::resolve($words, 'de'), 'a language without words gets the default language');
        self::assertSame('', LanguageFallback::resolve(['en' => 'About us'], 'nl'), 'the default language falls back to nothing');
        self::assertSame('', LanguageFallback::resolve(['en' => 'About us'], 'de'), 'never to a third language');
    }

    public function testTheFallbackFollowsTheRegistryNotDutch(): void
    {
        SiteLanguageFixture::useBilingual('en');

        self::assertSame('About us', LanguageFallback::resolve(['nl' => '', 'en' => 'About us'], 'nl'));
        self::assertSame('', LanguageFallback::resolve(['nl' => 'Over ons'], 'en'));
    }

    public function testWhitespaceIsNoWords(): void
    {
        self::assertSame('Over ons', LanguageFallback::resolve(['nl' => 'Over ons', 'en' => "  \t\n"], 'en'));
    }

    public function testTheAdminNameGoesOneStepFurtherThanAVisitor(): void
    {
        self::assertSame('Over ons', LanguageFallback::name(['nl' => 'Over ons', 'en' => 'About us']));
        self::assertSame('About us', LanguageFallback::name(['en' => 'About us']), 'a row with words only in a translation is still named');
        self::assertSame('', LanguageFallback::name([]));
    }

    // ----------------------------------------------------------- declaration

    /** @return iterable<string, array{0: string, 1: string, 2: array<string, int>}> */
    public static function unsafeDeclarations(): iterable
    {
        yield 'a quote in the table' => ["nav_items`; DROP TABLE x; --", 'owner_id', ['label' => 10]];
        yield 'a space in the owner column' => ['nav_item_translations', 'nav item id', ['label' => 10]];
        yield 'capitals in a field' => ['nav_item_translations', 'nav_item_id', ['Label' => 10]];
        yield 'no fields' => ['nav_item_translations', 'nav_item_id', []];
        yield 'a zero length' => ['nav_item_translations', 'nav_item_id', ['label' => 0]];
        yield 'a field named like the language column' => ['nav_item_translations', 'nav_item_id', ['language_code' => 10]];
    }

    /** @param array<string, int> $fields */
    #[DataProvider('unsafeDeclarations')]
    public function testADeclarationRefusesAnythingButPlainNames(string $table, string $owner, array $fields): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new TranslationTable($table, $owner, $fields);
    }

    // --------------------------------------------------------------- the API

    public function testRawHasNoFallbackAndValueHasIt(): void
    {
        $store = $this->store();
        $store->overrideForTests(7, ['nl' => ['label' => 'Diensten', 'hint' => 'Wat wij doen'], 'en' => ['label' => 'Services']]);

        self::assertSame('', $store->raw(7, 'hint', 'en'), 'an editor sees the empty translation');
        self::assertSame('Wat wij doen', $store->value(7, 'hint', 'en'), 'a visitor gets the default language');
        self::assertSame('Services', $store->value(7, 'label', 'en'));
        self::assertTrue($store->has(7, 'en'));
        self::assertFalse($store->has(7, 'de'));
    }

    public function testAnUndeclaredFieldIsRefused(): void
    {
        $store = $this->store();
        $store->overrideForTests(7, []);

        $this->expectException(\InvalidArgumentException::class);
        $store->raw(7, 'label_nl', 'nl');
    }

    public function testProblemsRequireWordsOnlyInTheDefaultLanguageAndMeasureInCharacters(): void
    {
        $store = $this->store();

        self::assertSame(['label' => 'missing'], $store->problems('nl', ['label' => '  '], ['label']));
        self::assertSame([], $store->problems('en', ['label' => ''], ['label']), 'a translation may be empty');
        self::assertSame(['hint' => 'too_long'], $store->problems('en', ['label' => 'x', 'hint' => str_repeat('é', 21)], ['label']));
        self::assertSame([], $store->problems('nl', ['label' => str_repeat('é', 20)], ['label']), 'twenty accented letters are twenty characters');
    }

    public function testSavingAnUnregisteredLanguageIsRefusedBeforeTheDatabase(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->store()->save(7, 'de', ['label' => 'Leistungen']);
    }

    private function store(): EntityTranslations
    {
        return new EntityTranslations(new TranslationTable('zz_test_translations', 'zz_owner_id', ['label' => 20, 'hint' => 20]));
    }
}

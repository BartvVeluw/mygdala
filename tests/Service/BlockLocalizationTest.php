<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\Blocks\BlockLocalization;
use App\Service\Blocks\TranslatableField;
use App\Service\Language\LanguageRegistry;
use PHPUnit\Framework\TestCase;
use Tests\Support\SiteLanguageFixture;

/**
 * The block localization API without a database (Multilingual 2.0 phase 3,
 * docs/multilingual/ARCHITECTURE.md): the fallback rule, what an editor sees,
 * what the CMS calls a block, the V1 output adapter, the validator and the
 * closed registry. Stored words and the registry are pinned through the
 * class's own test seams; the website languages through SiteLanguageFixture.
 */
final class BlockLocalizationTest extends TestCase
{
    private const TABLE = 'zz_test_blocks';
    private const ID = 7;

    protected function setUp(): void
    {
        BlockLocalization::overrideRegistryForTests([
            self::TABLE => [
                TranslatableField::plain('title', 20)->required(),
                TranslatableField::plain('lead', 100),
                TranslatableField::rich('body', 1000),
            ],
        ]);
        SiteLanguageFixture::useBilingual('nl');
    }

    protected function tearDown(): void
    {
        BlockLocalization::overrideRegistryForTests(null);
        BlockLocalization::clearCache();
        SiteLanguageFixture::reset();
    }

    // ------------------------------------------------------------ the fallback

    public function testTheAskedLanguageThenTheDefaultThenNothing(): void
    {
        $this->store(['nl' => ['title' => 'Titel', 'lead' => 'Inleiding'], 'en' => ['title' => 'Title']]);

        self::assertSame('Title', BlockLocalization::value(self::TABLE, self::ID, 'title', 'en'));
        self::assertSame('Inleiding', BlockLocalization::value(self::TABLE, self::ID, 'lead', 'en'), 'an untranslated field falls back to the default language');
        self::assertSame('', BlockLocalization::value(self::TABLE, self::ID, 'body', 'en'), 'nothing in either language is nothing');
    }

    public function testWithEnglishAsTheDefaultTheFallbackRunsTheOtherWay(): void
    {
        SiteLanguageFixture::useBilingual('en');
        $this->store(['nl' => ['title' => 'Titel'], 'en' => ['title' => 'Title', 'lead' => 'Intro']]);

        self::assertSame('en', BlockLocalization::defaultLanguage());
        self::assertSame('Titel', BlockLocalization::value(self::TABLE, self::ID, 'title', 'nl'));
        self::assertSame('Intro', BlockLocalization::value(self::TABLE, self::ID, 'lead', 'nl'));
    }

    public function testAThirdLanguageNeedsNoCode(): void
    {
        SiteLanguageFixture::useLanguages([
            SiteLanguageFixture::language('nl', isDefault: true, sortOrder: 0),
            SiteLanguageFixture::language('en', sortOrder: 1),
            SiteLanguageFixture::language('de', sortOrder: 2),
        ]);
        $this->store(['nl' => ['title' => 'Titel', 'lead' => 'Inleiding'], 'de' => ['title' => 'Titel auf Deutsch']]);

        self::assertSame('Titel auf Deutsch', BlockLocalization::value(self::TABLE, self::ID, 'title', 'de'));
        self::assertSame('Inleiding', BlockLocalization::value(self::TABLE, self::ID, 'lead', 'de'));
        self::assertSame('', BlockLocalization::raw(self::TABLE, self::ID, 'lead', 'de'));
    }

    public function testWhitespaceIsNoWords(): void
    {
        $this->store(['nl' => ['title' => 'Titel'], 'en' => ['title' => "  \n "]]);

        self::assertSame('', BlockLocalization::raw(self::TABLE, self::ID, 'title', 'en'));
        self::assertSame('Titel', BlockLocalization::value(self::TABLE, self::ID, 'title', 'en'));
    }

    public function testAnEditorSeesOnlyTheStoredWords(): void
    {
        $this->store(['nl' => ['title' => 'Titel']]);

        self::assertSame('Titel', BlockLocalization::raw(self::TABLE, self::ID, 'title', 'nl'));
        self::assertSame('', BlockLocalization::raw(self::TABLE, self::ID, 'title', 'en'), 'never the default language\'s words in a translation field');
    }

    public function testRichTextIsSanitizedOnTheWayOutWhateverIsStored(): void
    {
        $this->store([
            'nl' => ['body' => '<p>Veilig <strong>vet</strong></p><script>alert(1)</script>'],
            'en' => ['body' => '<p onmouseover="x()">Safe</p>'],
        ]);

        $dutch = BlockLocalization::value(self::TABLE, self::ID, 'body', 'nl');
        self::assertStringContainsString('<strong>vet</strong>', $dutch, 'markup stays markup');
        self::assertStringNotContainsString('<script', $dutch);

        $english = BlockLocalization::value(self::TABLE, self::ID, 'body', 'en');
        self::assertStringContainsString('Safe', $english);
        self::assertStringNotContainsString('onmouseover', $english);

        self::assertStringContainsString('<script>', BlockLocalization::raw(self::TABLE, self::ID, 'body', 'nl'), 'raw() is what is stored');
    }

    public function testPlainTextComesOutAsStoredAndIsEscapedByWhoeverPrintsIt(): void
    {
        $this->store(['nl' => ['title' => '<b>Titel</b>']]);

        self::assertSame('<b>Titel</b>', BlockLocalization::value(self::TABLE, self::ID, 'title', 'nl'));
    }

    // ------------------------------------------------------------ names

    public function testTheCmsNamesABlockInTheDefaultLanguageOrElseTheFirstLanguageThatHasWords(): void
    {
        $this->store(['en' => ['title' => 'Only English']]);

        self::assertSame('', BlockLocalization::value(self::TABLE, self::ID, 'title', 'nl'), 'a visitor never gets that step');
        self::assertSame('Only English', BlockLocalization::name(self::TABLE, self::ID, 'title'));

        $this->store(['nl' => ['title' => 'Nederlands'], 'en' => ['title' => 'English']]);
        self::assertSame('Nederlands', BlockLocalization::name(self::TABLE, self::ID, 'title'));
    }

    // ------------------------------------------------------------ the V1 adapter

    public function testTheV1PairIsBuiltFromTheResolvedHalves(): void
    {
        $this->store(['nl' => ['title' => 'Titel', 'lead' => 'Inleiding'], 'en' => ['title' => 'Title']]);

        $title = BlockLocalization::bilingual(self::TABLE, self::ID, 'title');
        self::assertSame('Titel', $title->primaryValue());
        self::assertSame(['nl' => 'Titel', 'en' => 'Title'], $title->attributeValues());

        $lead = BlockLocalization::bilingual(self::TABLE, self::ID, 'lead');
        self::assertSame(['nl' => 'Inleiding', 'en' => 'Inleiding'], $lead->attributeValues());
    }

    public function testWithEnglishAsTheDefaultTheEnglishHalfIsShownFirst(): void
    {
        SiteLanguageFixture::useBilingual('en');
        $this->store(['nl' => ['body' => '<p>Nederlands</p>'], 'en' => ['body' => '<p>English</p>']]);

        $body = BlockLocalization::bilingual(self::TABLE, self::ID, 'body');
        self::assertSame(LanguageRegistry::ENGLISH, $body->primaryLanguage());
        self::assertSame('<p>English</p>', $body->primaryValue());
    }

    public function testAnEnglishDefaultWithoutEnglishWordsShowsNothingRatherThanTheDutchWords(): void
    {
        SiteLanguageFixture::useBilingual('en');
        $this->store(['nl' => ['body' => '<p>Alleen Nederlands</p>']]);

        $body = BlockLocalization::bilingual(self::TABLE, self::ID, 'body');
        self::assertSame('', $body->primaryValue(), 'the default language decides what a visitor sees first');
        self::assertSame('<p>Alleen Nederlands</p>', $body->in(LanguageRegistry::DUTCH));
    }

    public function testAGermanDefaultStillFillsBothV1Halves(): void
    {
        SiteLanguageFixture::useLanguages([
            SiteLanguageFixture::language('de', isDefault: true, sortOrder: 0),
            SiteLanguageFixture::language('nl', sortOrder: 1),
            SiteLanguageFixture::language('en', sortOrder: 2),
        ]);
        $this->store(['de' => ['title' => 'Deutsch']]);

        self::assertSame(['nl' => 'Deutsch', 'en' => 'Deutsch'], BlockLocalization::bilingual(self::TABLE, self::ID, 'title')->attributeValues());
    }

    // ------------------------------------------------------------ the validator

    public function testRequiredOnlyInTheDefaultLanguageAndTooLongEverywhere(): void
    {
        self::assertSame(['title' => TranslatableField::MISSING], BlockLocalization::problems(self::TABLE, 'nl', ['lead' => 'x']));
        self::assertSame([], BlockLocalization::problems(self::TABLE, 'en', ['lead' => 'x']));
        self::assertSame(
            ['title' => TranslatableField::TOO_LONG, 'lead' => TranslatableField::TOO_LONG],
            BlockLocalization::problems(self::TABLE, 'en', ['title' => str_repeat('a', 21), 'lead' => str_repeat('b', 101)])
        );
    }

    public function testOneMessagePerKindOfProblem(): void
    {
        self::assertSame(
            ['validation.veld_verplicht', 'validation.text_too_long'],
            BlockLocalization::messageKeys(['title' => TranslatableField::MISSING, 'lead' => TranslatableField::TOO_LONG, 'body' => TranslatableField::TOO_LONG])
        );
        self::assertSame([], BlockLocalization::messageKeys([]));
    }

    // ------------------------------------------------------------ the closed registry

    public function testAFieldNoBlockDeclaresIsAProgrammingError(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        BlockLocalization::value(self::TABLE, self::ID, 'title_nl', 'nl');
    }

    public function testATableNoBlockDeclaresHasNoFieldsAndNoWords(): void
    {
        self::assertSame([], BlockLocalization::fields('pages'));
        self::assertSame([], BlockLocalization::translations('pages', 1));
        self::assertSame([self::TABLE], BlockLocalization::ownerTables());
    }

    public function testSavingIsRefusedForAnUndeclaredTableOrFieldBeforeTheDatabaseIsAsked(): void
    {
        foreach ([
            ['pages', ['title' => 'x']],
            [self::TABLE, ['title_en' => 'x']],
            [self::TABLE, ['title' => str_repeat('a', 21)]],
        ] as [$table, $values]) {
            try {
                BlockLocalization::save($table, self::ID, 'nl', $values);
                self::fail('saved into ' . $table . ': ' . json_encode($values));
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testTheRealRegistryIsReadFromTheBlockDefinitions(): void
    {
        BlockLocalization::overrideRegistryForTests(null);

        $declared = [];
        foreach (\App\Service\Blocks\BlockDefinitions::all() as $definition) {
            $declared = array_merge($declared, array_keys($definition->translatableFields()));
        }

        self::assertSame(array_values(array_unique($declared)), BlockLocalization::ownerTables());

        foreach (BlockLocalization::ownerTables() as $table) {
            self::assertNotSame([], BlockLocalization::fields($table), $table);
            self::assertMatchesRegularExpression('/\A[a-z][a-z0-9_]*\z/', $table);
        }
    }

    /** @param array<string, array<string, string>> $translations */
    private function store(array $translations): void
    {
        BlockLocalization::overrideForTests(self::TABLE, self::ID, $translations);
    }
}

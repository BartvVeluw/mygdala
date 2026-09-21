<?php

declare(strict_types=1);

namespace Tests\Blog;

use App\Database;
use App\Module\ModuleRegistry;
use App\Repository\SiteLanguageRepository;
use App\Service\Blog\BlogLocalizedSettings;
use App\Service\Blog\BlogSettings;
use App\Service\Language\SiteLanguages;
use App\Service\LocalizedSiteSettings;
use PHPUnit\Framework\TestCase;

/**
 * The Blog's two word settings — what the listing is called and the paragraph
 * under that heading — stored per website language in
 * `site_setting_translations` (Multilingual 2.0 phase 5, closing entry),
 * against the real test database.
 *
 * What must hold: a closed catalogue of two that no request can extend; one
 * language's save never touches another; a third language is only a row in
 * site_languages; an empty value has no row and falls back; the title falls
 * back to the code default in every language; the module's own
 * `blog_settings` no longer knows these keys; and CORE AND THE BLOG SHARE THE
 * TABLE WITHOUT SHARING A CATALOGUE — neither can read or write the other's
 * keys, although the rows sit side by side.
 *
 * Every value this test writes is removed again in tearDown(), and the German
 * language row with it. It only ever deletes its own two keys, so Core's
 * localized settings in the same table are left alone.
 */
final class BlogLocalizedSettingsTest extends TestCase
{
    /** @var array<string, array<string, string>> */
    private array $original = [];

    /** The one Core key this test writes, so it can be put back exactly. */
    private const CORE_KEY = LocalizedSiteSettings::FOOTER_SLOGAN;

    /** @var array<string, string> */
    private array $originalCore = [];

    private bool $addedGerman = false;

    protected function setUp(): void
    {
        self::assertSame('nl', SiteLanguages::defaultCode(), 'this test expects the Dutch-default test database');

        ModuleRegistry::overrideForTests(['shop' => true, 'personalization' => true, 'blog' => true, 'multilingual' => true]);

        BlogLocalizedSettings::clearCache();
        LocalizedSiteSettings::clearCache();
        foreach (array_keys(BlogLocalizedSettings::KEYS) as $key) {
            $this->original[$key] = BlogLocalizedSettings::store()->words($key);
        }
        $this->originalCore = LocalizedSiteSettings::words(self::CORE_KEY);
        $this->clearBlogWords();
    }

    protected function tearDown(): void
    {
        $this->clearBlogWords();
        foreach ($this->original as $key => $words) {
            foreach ($words as $language => $value) {
                BlogLocalizedSettings::save($language, [$key => $value]);
            }
        }

        foreach (SiteLanguages::activeCodes() as $code) {
            LocalizedSiteSettings::save($code, [self::CORE_KEY => $this->originalCore[$code] ?? '']);
        }

        if ($this->addedGerman) {
            (new SiteLanguageRepository())->delete('de');
        }

        ModuleRegistry::overrideForTests(null);
        BlogLocalizedSettings::clearCache();
        LocalizedSiteSettings::clearCache();
        BlogSettings::clearCache();
        SiteLanguages::clearCache();
    }

    /* ------------------------------------------------------------------ */
    /* The catalogue                                                       */
    /* ------------------------------------------------------------------ */

    /**
     * Adding a key here is a decision this test makes visible. Only what a
     * visitor reads on a Blog page belongs in it — never a switch, never a
     * page size, and never a setting of another domain.
     */
    public function testTheBlogCatalogueIsTwoKeysAndRefusesEveryOther(): void
    {
        self::assertSame(['blog_title', 'blog_intro'], array_keys(BlogLocalizedSettings::KEYS));

        foreach (['blog_title_en', 'blog_rss_enabled', 'site_name', 'city'] as $key) {
            try {
                BlogLocalizedSettings::save('nl', [$key => 'Iets']);
                self::fail('"' . $key . '" is not a localized Blog setting and must be refused');
            } catch (\InvalidArgumentException) {
            }
        }

        self::assertSame([], $this->rowsOfBlogKeys(), 'a refused key writes nothing at all');
    }

    /**
     * ONE TABLE, TWO CATALOGUES. Core's catalogue does not name the Blog's
     * keys and the Blog's does not name Core's, so neither can reach the
     * other's rows — which is what lets the Blog keep its settings in Core's
     * storage without Core learning that a blog exists (MODULES.md).
     */
    public function testCoreAndTheBlogShareTheTableWithoutSharingACatalogue(): void
    {
        foreach (array_keys(BlogLocalizedSettings::KEYS) as $key) {
            self::assertArrayNotHasKey($key, LocalizedSiteSettings::KEYS, 'Core must not own ' . $key);

            try {
                LocalizedSiteSettings::save('nl', [$key => 'Van de Blog']);
                self::fail('Core must refuse the Blog key ' . $key);
            } catch (\InvalidArgumentException) {
            }
        }

        foreach (array_keys(LocalizedSiteSettings::KEYS) as $key) {
            self::assertArrayNotHasKey($key, BlogLocalizedSettings::KEYS, 'the Blog must not own ' . $key);
        }

        // And a row of the other catalogue is invisible here, not a value with
        // a strange name: they are separate reads of the same table.
        LocalizedSiteSettings::save('nl', [LocalizedSiteSettings::FOOTER_SLOGAN => 'Met zorg gemaakt']);
        BlogLocalizedSettings::save('nl', [BlogLocalizedSettings::TITLE => 'Werkplaatslogboek']);

        self::assertSame(
            ['blog_title'],
            array_values(array_unique(array_map(
                static fn (array $row): string => $row['setting_key'],
                $this->rowsOfBlogKeys()
            )))
        );
        self::assertSame('Met zorg gemaakt', LocalizedSiteSettings::raw(LocalizedSiteSettings::FOOTER_SLOGAN, 'nl'));

        LocalizedSiteSettings::save('nl', [LocalizedSiteSettings::FOOTER_SLOGAN => '']);
    }

    /* ------------------------------------------------------------------ */
    /* Saving and reading                                                  */
    /* ------------------------------------------------------------------ */

    public function testSavingOneLanguageLeavesEveryOtherLanguageAlone(): void
    {
        BlogLocalizedSettings::save('nl', [
            BlogLocalizedSettings::TITLE => 'Werkplaatslogboek',
            BlogLocalizedSettings::INTRO => 'Wat er bij ons gebeurt.',
        ]);
        BlogLocalizedSettings::save('en', [
            BlogLocalizedSettings::TITLE => 'Workshop log',
            BlogLocalizedSettings::INTRO => 'What happens here.',
        ]);

        BlogLocalizedSettings::save('en', [
            BlogLocalizedSettings::TITLE => 'Workshop journal',
            BlogLocalizedSettings::INTRO => 'What happens here.',
        ]);

        self::assertSame('Werkplaatslogboek', BlogLocalizedSettings::title('nl'));
        self::assertSame('Wat er bij ons gebeurt.', BlogLocalizedSettings::intro('nl'));
        self::assertSame('Workshop journal', BlogLocalizedSettings::title('en'));
    }

    public function testAnEmptyTranslationHasNoRowAndTheReaderFallsBack(): void
    {
        BlogLocalizedSettings::save('nl', [
            BlogLocalizedSettings::TITLE => 'Werkplaatslogboek',
            BlogLocalizedSettings::INTRO => 'Wat er bij ons gebeurt.',
        ]);
        BlogLocalizedSettings::save('en', [BlogLocalizedSettings::TITLE => 'Workshop log']);

        BlogLocalizedSettings::save('en', [BlogLocalizedSettings::TITLE => "  \t "]);

        self::assertSame(['nl'], $this->languagesWithARow(BlogLocalizedSettings::TITLE));
        self::assertSame('', BlogLocalizedSettings::raw(BlogLocalizedSettings::TITLE, 'en'), 'an editor sees the empty translation');
        self::assertSame('Werkplaatslogboek', BlogLocalizedSettings::title('en'), 'a visitor gets the default language');
        self::assertSame('Wat er bij ons gebeurt.', BlogLocalizedSettings::intro('en'));
    }

    /**
     * THE CODE DEFAULT survived the move. A Blog nobody has renamed is called
     * "Blog" in every language — the name of a thing that has no name is not
     * a translation — while an unwritten introduction stays empty, because
     * there is nothing sensible to invent for a paragraph.
     */
    public function testAnUnwrittenTitleFallsBackToTheCodeDefaultInEveryLanguage(): void
    {
        self::assertSame(BlogLocalizedSettings::DEFAULT_TITLE, BlogLocalizedSettings::title('nl'));
        self::assertSame(BlogLocalizedSettings::DEFAULT_TITLE, BlogLocalizedSettings::title('en'));
        self::assertSame('', BlogLocalizedSettings::intro('nl'));
        self::assertSame('', BlogLocalizedSettings::intro('en'));
    }

    /** An untranslated title and intro read the default language's words in every other language. */
    public function testAnUntranslatedTitleAndIntroFallBackToTheDefaultLanguage(): void
    {
        BlogLocalizedSettings::save('nl', [
            BlogLocalizedSettings::TITLE => 'Werkplaatslogboek',
            BlogLocalizedSettings::INTRO => 'Wat er bij ons gebeurt.',
        ]);

        self::assertSame('Werkplaatslogboek', BlogLocalizedSettings::title('en'));
        self::assertSame('Wat er bij ons gebeurt.', BlogLocalizedSettings::intro('en'));
    }

    /**
     * German is a row in site_languages and nothing else: no column, no
     * constant, no branch. And a language that still holds words cannot be
     * deleted — the foreign key says so, not the application.
     */
    public function testAThirdLanguageIsOnlyARowInTheRegistry(): void
    {
        (new SiteLanguageRepository())->create('de', 'German', 'Deutsch');
        $this->addedGerman = true;
        SiteLanguages::clearCache();

        BlogLocalizedSettings::save('nl', [BlogLocalizedSettings::TITLE => 'Werkplaatslogboek']);
        BlogLocalizedSettings::save('de', [
            BlogLocalizedSettings::TITLE => 'Werkstattbuch',
            BlogLocalizedSettings::INTRO => 'Was bei uns geschieht.',
        ]);

        self::assertSame('Werkstattbuch', BlogLocalizedSettings::title('de'));
        self::assertSame('Was bei uns geschieht.', BlogLocalizedSettings::intro('de'));
        self::assertSame('Werkplaatslogboek', BlogLocalizedSettings::title('en'), 'English still falls back to Dutch');
        self::assertSame('', BlogLocalizedSettings::intro('en'), 'and an introduction only Germans have is not shown to others');

        try {
            Database::connection()->prepare("DELETE FROM site_languages WHERE code = 'de'")->execute();
            self::fail('the foreign key must refuse deleting a language with words');
        } catch (\PDOException $e) {
            self::assertSame('23000', $e->getCode());
        }

        BlogLocalizedSettings::save('de', [
            BlogLocalizedSettings::TITLE => '',
            BlogLocalizedSettings::INTRO => '',
        ]);
    }

    public function testAnUnregisteredLanguageOrATooLongValueIsRefused(): void
    {
        self::assertSame(
            [BlogLocalizedSettings::TITLE => 'too_long'],
            BlogLocalizedSettings::problems([BlogLocalizedSettings::TITLE => str_repeat('é', BlogLocalizedSettings::TITLE_MAX_LENGTH + 1)])
        );
        self::assertSame(
            [],
            BlogLocalizedSettings::problems([BlogLocalizedSettings::INTRO => str_repeat('é', BlogLocalizedSettings::INTRO_MAX_LENGTH)])
        );

        $this->expectException(\InvalidArgumentException::class);
        BlogLocalizedSettings::save('xx', [BlogLocalizedSettings::TITLE => 'Nergens']);
    }

    /* ------------------------------------------------------------------ */
    /* The module, and what it left behind                                 */
    /* ------------------------------------------------------------------ */

    /**
     * Switching a module off never touches the database (CLAUDE.md), so the
     * texts an owner typed are still there when the Blog comes back — and the
     * store never asks whether the module is on.
     */
    public function testSwitchingTheModuleOffAndOnKeepsTheWords(): void
    {
        BlogLocalizedSettings::save('nl', [BlogLocalizedSettings::TITLE => 'Werkplaatslogboek']);
        BlogLocalizedSettings::save('en', [BlogLocalizedSettings::TITLE => 'Workshop log']);

        ModuleRegistry::overrideForTests(['shop' => true, 'personalization' => true, 'blog' => false, 'multilingual' => true]);
        BlogLocalizedSettings::clearCache();

        self::assertSame('Werkplaatslogboek', BlogLocalizedSettings::title('nl'));
        self::assertCount(2, $this->rowsOfBlogKeys(), 'a disabled module keeps its rows');

        ModuleRegistry::overrideForTests(['shop' => true, 'personalization' => true, 'blog' => true, 'multilingual' => true]);
        BlogLocalizedSettings::clearCache();

        self::assertSame('Workshop log', BlogLocalizedSettings::title('en'), 'the same words come back');
    }

    /**
     * THE FOUR OLD KEYS ARE GONE. `blog_settings` keeps only what reads the
     * same in every language, and a stale form field naming one of them
     * writes nothing rather than reviving Dutch/English storage.
     */
    public function testTheModuleSettingsStoreNoLongerKnowsTheWordKeys(): void
    {
        self::assertSame(
            [
                'blog_posts_per_page',
                'blog_show_author',
                'blog_show_date',
                'blog_related_posts',
                'blog_rss_enabled',
            ],
            BlogSettings::keys()
        );

        BlogSettings::save([
            'blog_title' => 'Terug naar vroeger',
            'blog_title_en' => 'Back in time',
            'blog_intro' => 'Nee',
            'blog_intro_en' => 'No',
        ]);
        BlogSettings::clearCache();

        self::assertSame([], array_intersect(
            ['blog_title', 'blog_title_en', 'blog_intro', 'blog_intro_en'],
            array_keys(BlogSettings::all())
        ));

        $stmt = Database::connection()->query(
            "SELECT COUNT(*) FROM blog_settings WHERE setting_key IN ('blog_title', 'blog_title_en', 'blog_intro', 'blog_intro_en')"
        );
        self::assertSame(0, (int) $stmt->fetchColumn(), 'no legacy key may come back in blog_settings');
    }

    /* --------------------------------------------------------------- helpers */

    private function clearBlogWords(): void
    {
        $stmt = Database::connection()->prepare(
            'DELETE FROM site_setting_translations WHERE setting_key IN (:title, :intro)'
        );
        $stmt->execute(['title' => BlogLocalizedSettings::TITLE, 'intro' => BlogLocalizedSettings::INTRO]);

        BlogLocalizedSettings::clearCache();
    }

    /** @return list<array{setting_key: string, language_code: string}> */
    private function rowsOfBlogKeys(): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT setting_key, language_code FROM site_setting_translations
              WHERE setting_key IN (:title, :intro) ORDER BY setting_key ASC, language_code ASC'
        );
        $stmt->execute(['title' => BlogLocalizedSettings::TITLE, 'intro' => BlogLocalizedSettings::INTRO]);

        return array_map(
            static fn (array $row): array => [
                'setting_key' => (string) $row['setting_key'],
                'language_code' => (string) $row['language_code'],
            ],
            $stmt->fetchAll()
        );
    }

    /** @return list<string> */
    private function languagesWithARow(string $key): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT language_code FROM site_setting_translations WHERE setting_key = :key ORDER BY language_code DESC'
        );
        $stmt->execute(['key' => $key]);

        return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }
}

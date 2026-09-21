<?php

declare(strict_types=1);

namespace Tests\Repository;

use App\Database;
use App\Repository\SiteLanguageRepository;
use App\Service\Forms\FormRenderState;
use App\Service\Language\SiteLanguages;
use App\Service\LocalizedSiteSettings;
use PHPUnit\Framework\TestCase;

/**
 * The site settings that are website text, stored per website language in
 * `site_setting_translations` (Multilingual 2.0 phase 4 wave B), against the
 * real test database.
 *
 * What must hold: a closed catalogue no request can extend; one language's
 * save never touches another; a third language is only a row in
 * site_languages; an empty value has no row; a language that still has words
 * cannot be deleted; and the one fallback reaches the contact block that
 * prints the place.
 *
 * Every value this test writes is put back in tearDown(); the German language
 * row is removed.
 */
final class LocalizedSiteSettingsTest extends TestCase
{
    /** @var array<string, array<string, string>> */
    private array $original = [];

    private bool $addedGerman = false;

    protected function setUp(): void
    {
        self::assertSame('nl', SiteLanguages::defaultCode(), 'this test expects the Dutch-default test database');

        LocalizedSiteSettings::clearCache();
        foreach (array_keys(LocalizedSiteSettings::KEYS) as $key) {
            $this->original[$key] = LocalizedSiteSettings::words($key);
        }
        $this->clearAll();
    }

    protected function tearDown(): void
    {
        $this->clearAll();
        foreach ($this->original as $key => $words) {
            foreach ($words as $language => $value) {
                LocalizedSiteSettings::save($language, [$key => $value]);
            }
        }

        if ($this->addedGerman) {
            (new SiteLanguageRepository())->delete('de');
        }

        LocalizedSiteSettings::clearCache();
        SiteLanguages::clearCache();
    }

    public function testSavingOneLanguageLeavesEveryOtherLanguageAlone(): void
    {
        LocalizedSiteSettings::save('nl', [LocalizedSiteSettings::CITY => 'Nijmegen, Nederland']);
        LocalizedSiteSettings::save('en', [LocalizedSiteSettings::CITY => 'Nijmegen, the Netherlands']);

        LocalizedSiteSettings::save('en', [LocalizedSiteSettings::CITY => 'Nijmegen, NL']);

        self::assertSame(
            ['nl' => 'Nijmegen, Nederland', 'en' => 'Nijmegen, NL'],
            LocalizedSiteSettings::words(LocalizedSiteSettings::CITY)
        );
    }

    public function testAKeyNotGivenIsLeftAlone(): void
    {
        LocalizedSiteSettings::save('nl', [LocalizedSiteSettings::FOOTER_DESCRIPTION => 'Een omschrijving', LocalizedSiteSettings::FOOTER_SLOGAN => 'Met zorg']);

        LocalizedSiteSettings::save('nl', [LocalizedSiteSettings::FOOTER_SLOGAN => 'Met veel zorg']);

        self::assertSame('Een omschrijving', LocalizedSiteSettings::raw(LocalizedSiteSettings::FOOTER_DESCRIPTION, 'nl'));
        self::assertSame('Met veel zorg', LocalizedSiteSettings::raw(LocalizedSiteSettings::FOOTER_SLOGAN, 'nl'));
    }

    public function testAnEmptyValueHasNoRowAndFallsBack(): void
    {
        LocalizedSiteSettings::save('nl', [LocalizedSiteSettings::CITY => 'Utrecht']);
        LocalizedSiteSettings::save('en', [LocalizedSiteSettings::CITY => 'Utrecht (NL)']);

        LocalizedSiteSettings::save('en', [LocalizedSiteSettings::CITY => "  \t "]);

        self::assertSame(['nl'], $this->languagesWithARow(LocalizedSiteSettings::CITY));
        self::assertSame('', LocalizedSiteSettings::raw(LocalizedSiteSettings::CITY, 'en'), 'an editor sees the empty translation');
        self::assertSame('Utrecht', LocalizedSiteSettings::value(LocalizedSiteSettings::CITY, 'en'), 'a visitor gets the default language');
    }

    public function testTheCatalogueIsClosed(): void
    {
        try {
            LocalizedSiteSettings::save('nl', ['site_name' => 'Geen tekst per taal']);
            self::fail('a key outside the catalogue must be refused');
        } catch (\InvalidArgumentException) {
        }

        try {
            LocalizedSiteSettings::save('nl', ['city_nl' => 'Utrecht']);
            self::fail('the old key is not a localized setting');
        } catch (\InvalidArgumentException) {
        }

        self::assertSame(0, (int) Database::connection()->query("SELECT COUNT(*) FROM site_setting_translations WHERE setting_key NOT IN ('city', 'footer_description', 'footer_slogan')")->fetchColumn());
    }

    public function testAValueOverItsLengthOrALanguageTheRegistryDoesNotHaveIsRefused(): void
    {
        self::assertSame(['city' => 'too_long'], LocalizedSiteSettings::problems([LocalizedSiteSettings::CITY => str_repeat('é', 151)]));
        self::assertSame([], LocalizedSiteSettings::problems([LocalizedSiteSettings::CITY => str_repeat('é', 150)]));

        $this->expectException(\InvalidArgumentException::class);
        LocalizedSiteSettings::save('xx', [LocalizedSiteSettings::CITY => 'Nergens']);
    }

    public function testAThirdLanguageIsOnlyARowInTheRegistryAndCannotBeDeletedWhileItHasWords(): void
    {
        (new SiteLanguageRepository())->create('de', 'German', 'Deutsch');
        $this->addedGerman = true;
        SiteLanguages::clearCache();

        LocalizedSiteSettings::save('nl', [LocalizedSiteSettings::FOOTER_SLOGAN => 'Met zorg gemaakt']);
        LocalizedSiteSettings::save('de', [LocalizedSiteSettings::FOOTER_SLOGAN => 'Mit Sorgfalt gemacht']);

        self::assertSame('Mit Sorgfalt gemacht', LocalizedSiteSettings::value(LocalizedSiteSettings::FOOTER_SLOGAN, 'de'));
        self::assertSame('Met zorg gemaakt', LocalizedSiteSettings::value(LocalizedSiteSettings::FOOTER_SLOGAN, 'en'));

        try {
            Database::connection()->prepare("DELETE FROM site_languages WHERE code = 'de'")->execute();
            self::fail('the foreign key must refuse deleting a language with words');
        } catch (\PDOException $e) {
            self::assertSame('23000', $e->getCode());
        }

        LocalizedSiteSettings::save('de', [LocalizedSiteSettings::FOOTER_SLOGAN => '']);
    }

    /**
     * The one consumer outside the settings screens: the contact block prints
     * the place from here, in the language being read, as text.
     */
    public function testTheContactBlockPrintsThePlaceFromTheLocalizedStore(): void
    {
        require_once dirname(__DIR__, 2) . '/partials/section-contact-form.php';

        LocalizedSiteSettings::save('nl', [LocalizedSiteSettings::CITY => 'Nijmegen, Nederland']);
        LocalizedSiteSettings::save('en', [LocalizedSiteSettings::CITY => '<b>Nijmegen</b>']);

        ob_start();
        render_section_contact_form(
            ['state' => 'active', 'title' => 'Contact', 'form_id' => null, 'allow_attachment' => false],
            null,
            FormRenderState::fresh(FormRenderState::tokenFor('localized-settings-test', 'contact')),
            ['email' => '', 'city' => LocalizedSiteSettings::value(LocalizedSiteSettings::CITY, 'en')]
        );
        $html = (string) ob_get_clean();

        self::assertStringContainsString('<span>&lt;b&gt;Nijmegen&lt;/b&gt;</span>', $html);
        self::assertStringNotContainsString('Nijmegen, Nederland', $html, 'one language per page');
    }

    private function clearAll(): void
    {
        Database::connection()->exec('DELETE FROM site_setting_translations');
        LocalizedSiteSettings::clearCache();
    }

    /** @return list<string> */
    private function languagesWithARow(string $key): array
    {
        $stmt = Database::connection()->prepare('SELECT language_code FROM site_setting_translations WHERE setting_key = :key ORDER BY language_code DESC');
        $stmt->execute(['key' => $key]);

        return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }
}

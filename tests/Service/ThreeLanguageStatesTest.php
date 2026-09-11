<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\Language\AdminLocale;
use App\Service\Language\AdminTranslator;
use App\Service\Language\ContentEditingLanguage;
use App\Service\Language\ContentLanguages;
use App\Service\Language\LocalizedValue;
use App\Service\Language\SiteText;
use App\Service\SiteSettings;
use App\Service\Translation\TranslationRequest;
use App\Service\Translation\TranslationService;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeTranslationProvider;

/**
 * THE THREE LANGUAGE STATES, and that none of them can reach the others.
 *
 *   1. CMS interface language   AdminLocale              per admin user
 *   2. Content editing language ContentEditingLanguage   per admin user
 *   3. Public visitor language  the browser              per visitor
 *
 * Multilingual V1 had only two of these and used the site's own settings for
 * the second, which meant the only way to edit English content was to change
 * the website's configuration — shared with every colleague, and wired to
 * whether visitors were offered English at all. This file is the guard that
 * the three cannot grow back together.
 *
 * No database, no webserver, no network: every state has a test seam, and the
 * site's settings are overridden in memory.
 */
final class ThreeLanguageStatesTest extends TestCase
{
    protected function tearDown(): void
    {
        AdminLocale::overrideForTests(null);
        ContentEditingLanguage::overrideForTests(null);
        SiteSettings::clearCache();
    }

    /**
     * Put the CMS in one state and answer the three questions it decides.
     *
     * @return array{interface: string, editing: string, required_on_primary: bool}
     */
    private function cms(string $interface, string $editing, string $primary = 'nl'): array
    {
        SiteSettings::overrideForTests(['primary_content_language' => $primary]);
        AdminLocale::overrideForTests($interface);
        ContentEditingLanguage::overrideForTests($editing);

        return [
            'interface' => AdminLocale::current(),
            'editing' => ContentEditingLanguage::current(),
            'required_on_primary' => ContentEditingLanguage::current() === ContentLanguages::primary(),
        ];
    }

    // ------------------------------------------------- the mandatory matrix

    /**
     * All four combinations, and in each one the CMS reads in the interface
     * language while the editor forms point at the content language. These
     * are the four cases a person actually sits in.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function matrix(): array
    {
        return [
            // interface, editing, a word only the interface language has
            'CMS NL + edit NL' => ['nl', 'nl', 'Opslaan'],
            'CMS NL + edit EN' => ['nl', 'en', 'Opslaan'],
            'CMS EN + edit NL' => ['en', 'nl', 'Save'],
            'CMS EN + edit EN' => ['en', 'en', 'Save'],
        ];
    }

    /** @dataProvider matrix */
    public function testTheFourCombinationsAreAllReachableAndIndependent(
        string $interface,
        string $editing,
        string $expectedLabel,
    ): void {
        $state = $this->cms($interface, $editing);

        self::assertSame($interface, $state['interface'], 'the CMS interface language is what was chosen');
        self::assertSame($editing, $state['editing'], 'the editing language is what was chosen');

        // The CMS labels follow the INTERFACE language and nothing else.
        self::assertSame($expectedLabel, AdminTranslator::trans('common.save'));

        // The website's own configuration is untouched by either preference.
        self::assertSame('nl', ContentLanguages::primary(), 'the website default is unchanged');
        self::assertSame(['nl', 'en'], ContentLanguages::enabled(), 'both languages stay published');
    }

    /** @dataProvider matrix */
    public function testTheOtherLanguagesDataIsPreservedInEveryCombination(
        string $interface,
        string $editing,
    ): void {
        $this->cms($interface, $editing);

        // One row, both languages filled. Whichever language the editor is
        // looking at, the OTHER one's stored words are still there — that is
        // what makes the hidden pane safe to submit.
        $value = LocalizedValue::ofDutchEnglish('Neem contact op', 'Get in touch');

        self::assertSame('Neem contact op', $value->raw('nl'));
        self::assertSame('Get in touch', $value->raw('en'));
    }

    public function testTheInterfaceLanguageNeverMovesTheEditingLanguage(): void
    {
        $this->cms('nl', 'en');
        self::assertSame('en', ContentEditingLanguage::current());

        AdminLocale::overrideForTests('en');
        self::assertSame('en', ContentEditingLanguage::current(), 'still editing English');

        AdminLocale::overrideForTests('nl');
        self::assertSame('en', ContentEditingLanguage::current(), 'still editing English');
    }

    public function testTheEditingLanguageNeverMovesTheInterfaceLanguage(): void
    {
        $this->cms('en', 'nl');
        self::assertSame('en', AdminLocale::current());

        ContentEditingLanguage::overrideForTests('en');
        self::assertSame('en', AdminLocale::current(), 'the CMS is still English');

        ContentEditingLanguage::overrideForTests('nl');
        self::assertSame('en', AdminLocale::current(), 'the CMS is still English');
    }

    // -------------------------------------------------- the editing default

    public function testAnAdministratorWhoNeverChoseEditsTheDefaultWebsiteLanguage(): void
    {
        SiteSettings::overrideForTests(['primary_content_language' => 'en']);
        ContentEditingLanguage::overrideForTests(null);

        self::assertSame('en', ContentEditingLanguage::normalise(null));
        self::assertSame('en', ContentEditingLanguage::normalise(''));
    }

    public function testAnUnstorableEditingLanguageFallsBackRatherThanReachingAColumnName(): void
    {
        SiteSettings::overrideForTests(['primary_content_language' => 'nl']);

        self::assertSame('nl', ContentEditingLanguage::normalise('de'));
        self::assertSame('nl', ContentEditingLanguage::normalise('../../etc/passwd'));
        self::assertSame('nl', ContentEditingLanguage::normalise('nl; DROP TABLE pages'));
    }

    public function testTheTranslationSourceIsTheOtherLanguage(): void
    {
        $this->cms('nl', 'en');
        self::assertSame('nl', ContentEditingLanguage::source());

        $this->cms('nl', 'nl');
        self::assertSame('en', ContentEditingLanguage::source());
    }

    // ------------------------------------------------------- the public site

    /** @dataProvider matrix */
    public function testNoAdminPreferenceChangesWhatAVisitorIsOffered(
        string $interface,
        string $editing,
    ): void {
        $this->cms($interface, $editing);

        self::assertTrue(SiteText::showsLanguageSwitch(), 'the switch is always offered');
        self::assertSame(['nl', 'en'], SiteText::switchableLanguages());
        self::assertSame('nl', SiteText::documentLanguage(), 'the page still renders in the site default');
    }

    public function testTheSwitchSurvivesTheDeprecatedSingleLanguageRow(): void
    {
        SiteSettings::overrideForTests([
            'primary_content_language' => 'nl',
            'enabled_content_languages' => 'nl',
        ]);

        self::assertTrue(SiteText::showsLanguageSwitch());
        self::assertSame(['nl', 'en'], SiteText::switchableLanguages());
    }

    public function testAVisitorReadsEachLanguageAndFallsBackPerField(): void
    {
        SiteSettings::overrideForTests(['primary_content_language' => 'nl']);

        $translated = LocalizedValue::ofDutchEnglish('Neem contact op', 'Get in touch');
        self::assertSame('Neem contact op', $translated->in('nl'));
        self::assertSame('Get in touch', $translated->in('en'));

        $untranslated = LocalizedValue::ofDutchEnglish('Neem contact op', '');
        self::assertSame('Neem contact op', $untranslated->in('nl'));
        self::assertSame('Neem contact op', $untranslated->in('en'), 'a visitor never sees a blank button');
    }

    public function testTheFallbackIsForVisitorsOnlyAndNeverFillsAnEditorField(): void
    {
        // THE DISTINCTION THE WHOLE MODEL RESTS ON. If an editor's English
        // field showed the Dutch words, the next Save would store them as a
        // real translation and the site would lose track of what is actually
        // translated.
        SiteSettings::overrideForTests(['primary_content_language' => 'nl']);

        $value = LocalizedValue::ofDutchEnglish('Neem contact op', '');

        self::assertSame('Neem contact op', $value->in('en'), 'the visitor gets the fallback');
        self::assertSame('', $value->raw('en'), 'the editor gets an empty field');
        self::assertFalse($value->isTranslated('en'));
    }

    // ------------------------------------------------ automatic translation

    public function testAnEditorWritingEnglishTranslatesFromDutch(): void
    {
        $this->cms('nl', 'en');

        $provider = new FakeTranslationProvider();
        $service = new TranslationService($provider);

        $service->translateEntity('cta-band', '', ContentEditingLanguage::current(), [
            new TranslationRequest('title', 'Neem contact op', ''),
        ], ContentEditingLanguage::source());

        self::assertSame('nl', $provider->calls[0]['source']);
        self::assertSame('en', $provider->calls[0]['target']);
    }

    public function testAManuallyWrittenTranslationIsNotOverwritten(): void
    {
        $this->cms('nl', 'en');

        $provider = new FakeTranslationProvider();
        $service = new TranslationService($provider);

        // No state record for this field means "a person wrote it", which is
        // the protective direction (TranslationState::classify).
        $result = $service->translateEntity('cta-band', '', 'en', [
            new TranslationRequest('title', 'Neem contact op', 'Get in touch'),
        ], 'nl');

        self::assertArrayNotHasKey('title', $result->translations, 'the hand-written text is left alone');
        self::assertSame('manual', $result->skipped['title'] ?? null);
    }

    public function testSwitchingEditingLanguageTranslatesNothing(): void
    {
        $provider = new FakeTranslationProvider();
        // The service is built but never asked to translate, which is exactly
        // what changing language does: nothing goes out.
        new TranslationService($provider);

        $this->cms('nl', 'nl');
        $this->cms('nl', 'en');
        $this->cms('en', 'nl');
        $this->cms('en', 'en');

        self::assertSame([], $provider->calls, 'no provider call was made by switching language');
    }
}

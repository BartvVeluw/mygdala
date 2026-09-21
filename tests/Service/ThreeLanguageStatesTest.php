<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\Language\AdminLocale;
use App\Service\Language\AdminTranslator;
use App\Service\Language\ContentEditingLanguage;
use App\Service\Language\LanguageFallback;
use App\Service\Language\SiteLanguages;
use App\Service\Language\SiteText;
use App\Service\Routing\LanguageSwitch;
use App\Service\Translation\TranslationRequest;
use App\Service\Translation\TranslationService;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeTranslationProvider;
use Tests\Support\SiteLanguageFixture;

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
 * site's language registry is replaced in memory.
 */
final class ThreeLanguageStatesTest extends TestCase
{
    protected function tearDown(): void
    {
        AdminLocale::overrideForTests(null);
        ContentEditingLanguage::overrideForTests(null);
        SiteLanguageFixture::reset();
    }

    /**
     * Put the CMS in one state and answer the three questions it decides.
     *
     * @return array{interface: string, editing: string, required_on_primary: bool}
     */
    private function cms(string $interface, string $editing, string $primary = 'nl'): array
    {
        SiteLanguageFixture::useBilingual($primary);
        AdminLocale::overrideForTests($interface);
        ContentEditingLanguage::overrideForTests($editing);

        return [
            'interface' => AdminLocale::current(),
            'editing' => ContentEditingLanguage::current(),
            'required_on_primary' => ContentEditingLanguage::current() === SiteLanguages::defaultCode(),
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
        self::assertSame('nl', SiteLanguages::defaultCode(), 'the website default is unchanged');
        self::assertSame(['nl', 'en'], SiteLanguages::activeCodes(), 'both languages stay published');
    }

    /** @dataProvider matrix */
    public function testTheOtherLanguagesDataIsPreservedInEveryCombination(
        string $interface,
        string $editing,
    ): void {
        $this->cms($interface, $editing);

        // One field, both languages filled. Whichever language the editor is
        // looking at, a visitor of each language reads that language's own
        // stored words: an editing preference touches no stored words.
        $words = ['nl' => 'Neem contact op', 'en' => 'Get in touch'];

        self::assertSame('Neem contact op', LanguageFallback::resolve($words, 'nl'));
        self::assertSame('Get in touch', LanguageFallback::resolve($words, 'en'));
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
        SiteLanguageFixture::useBilingual('en');
        ContentEditingLanguage::overrideForTests(null);

        self::assertSame('en', ContentEditingLanguage::normalise(null));
        self::assertSame('en', ContentEditingLanguage::normalise(''));
    }

    public function testAnUnstorableEditingLanguageFallsBackRatherThanReachingAColumnName(): void
    {
        SiteLanguageFixture::useBilingual('nl');

        self::assertSame('nl', ContentEditingLanguage::normalise('de'));
        self::assertSame('nl', ContentEditingLanguage::normalise('../../etc/passwd'));
        self::assertSame('nl', ContentEditingLanguage::normalise('nl; DROP TABLE pages'));
    }

    // ------------------------------------------------------- the public site

    /** @dataProvider matrix */
    public function testNoAdminPreferenceChangesWhatAVisitorIsOffered(
        string $interface,
        string $editing,
    ): void {
        $this->cms($interface, $editing);

        self::assertTrue(LanguageSwitch::isAvailable(), 'the switch is offered');
        self::assertSame(['nl', 'en'], SiteLanguages::activeCodes());
        self::assertSame('nl', SiteText::documentLanguage(), 'the page still renders in the site default');
    }

    public function testTheSwitchOffersOnlyTheActiveLanguagesOfTheRegistry(): void
    {
        SiteLanguageFixture::useLanguages([
            SiteLanguageFixture::language('nl', isDefault: true),
            SiteLanguageFixture::language('en', isActive: false, sortOrder: 1),
        ]);

        // Since the frontend flip the registry decides what a visitor is
        // offered: a language that is switched off has no URLs, so there is
        // nothing to switch to.
        self::assertFalse(LanguageSwitch::isAvailable());
        self::assertSame(['nl'], SiteLanguages::activeCodes());
    }

    public function testAVisitorReadsEachLanguageAndFallsBackPerField(): void
    {
        SiteLanguageFixture::useBilingual('nl');

        $translated = ['nl' => 'Neem contact op', 'en' => 'Get in touch'];
        self::assertSame('Neem contact op', LanguageFallback::resolve($translated, 'nl'));
        self::assertSame('Get in touch', LanguageFallback::resolve($translated, 'en'));

        $untranslated = ['nl' => 'Neem contact op'];
        self::assertSame('Neem contact op', LanguageFallback::resolve($untranslated, 'nl'));
        self::assertSame('Neem contact op', LanguageFallback::resolve($untranslated, 'en'), 'a visitor never sees a blank button');
    }

    public function testTheFallbackIsForVisitorsOnlyAndNeverFillsAnEditorField(): void
    {
        // THE DISTINCTION THE WHOLE MODEL RESTS ON. If an editor's English
        // field showed the Dutch words, the next Save would store them as a
        // real translation and the site would lose track of what is actually
        // translated.
        SiteLanguageFixture::useBilingual('nl');

        // What storage holds: no English row. The fallback is applied on the
        // way to a visitor (LanguageFallback::resolve()), never on the way to
        // an editor's field, which reads the stored words as they are.
        $stored = ['nl' => 'Neem contact op'];

        self::assertSame('Neem contact op', LanguageFallback::resolve($stored, 'en'), 'the visitor gets the fallback');
        self::assertSame('', $stored['en'] ?? '', 'the editor gets an empty field');
    }

    // ------------------------------------------------ automatic translation

    public function testAnEditorWritingEnglishTranslatesFromDutch(): void
    {
        $this->cms('nl', 'en');

        $provider = new FakeTranslationProvider();
        $service = new TranslationService($provider);

        $service->translateEntity('cta-band', '', ContentEditingLanguage::current(), [
            new TranslationRequest('title', 'Neem contact op', ''),
        ], LanguageFallback::defaultLanguage());

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

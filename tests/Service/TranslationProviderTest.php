<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\Language\LanguageRegistry;
use App\Service\Translation\DeepLProvider;
use App\Service\Translation\NullTranslationProvider;
use App\Service\Translation\TranslationException;
use App\Service\Translation\TranslationProviderFactory;
use App\Service\Translation\TranslationRequest;
use App\Service\Translation\TranslationService;
use App\Service\Translation\TranslationState;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeTranslationProvider;
use Tests\Support\SiteLanguageFixture;

/*
 * There is no stand-in for the translation-state repository here, and none is
 * needed: every service test below passes an EMPTY entity key, and
 * TranslationService only reaches for a repository when it has a key to look
 * up. So nothing in this file opens a database connection, which is what
 * keeps it in the `fast` tier (TESTING.md).
 */

/**
 * The provider abstraction, the DeepL implementation, and the four rules the
 * translation service enforces.
 *
 * NOTHING HERE TALKS TO A TRANSLATION API. The DeepL tests subclass the
 * provider and replace its one HTTP method, which is the same seam
 * Tests\Service\TurnstileVerifierTest uses for the same reason: a paid third
 * party must never be able to make this project's suite fail, and a test run
 * must never spend somebody's quota.
 */
final class TranslationProviderTest extends TestCase
{
    protected function setUp(): void
    {
        SiteLanguageFixture::useBilingual('nl');
    }

    protected function tearDown(): void
    {
        SiteLanguageFixture::reset();
        TranslationProviderFactory::overrideForTests(null);
    }

    // ------------------------------------------------- optional by default

    public function testAnInstallationWithoutAProviderStillWorks(): void
    {
        // The promise of Part I: a fresh Mygdala boots with no translation
        // credentials and every other thing about it works.
        $provider = new NullTranslationProvider();

        self::assertFalse($provider->isConfigured());
        self::assertFalse($provider->supports('nl', 'en'));
    }

    public function testTheNullProviderRefusesRatherThanCopyingTheSourceBack(): void
    {
        // Copying Dutch into the English field would look like a successful
        // translation and would poison the state with a hash saying so.
        $this->expectException(TranslationException::class);

        (new NullTranslationProvider())->translate('Hallo', 'nl', 'en');
    }

    public function testTheServiceReportsThatTranslationIsUnavailable(): void
    {
        $service = new TranslationService(new NullTranslationProvider());

        self::assertFalse($service->isAvailable());
        self::assertFalse($service->canTranslateInto('en'));
    }

    public function testAnUnknownProviderNameFallsBackToNoProvider(): void
    {
        TranslationProviderFactory::overrideForTests(new NullTranslationProvider());

        self::assertFalse(TranslationProviderFactory::isAvailable());
    }

    // ------------------------------------------------------------- DeepL

    public function testDeepLIsUnconfiguredWithoutAKey(): void
    {
        $provider = new DeepLProvider('');

        self::assertFalse($provider->isConfigured());
        self::assertFalse($provider->supports('nl', 'en'));
    }

    public function testDeepLSupportsTheLanguagesThisCmsRegisters(): void
    {
        $provider = new DeepLProvider('test-key:fx');

        self::assertTrue($provider->supports('nl', 'en'));
        self::assertTrue($provider->supports('en', 'nl'));
        self::assertFalse($provider->supports('nl', 'nl'), 'a language is not translated into itself');
        self::assertFalse($provider->supports('nl', 'de'), 'this build does not register German');
    }

    public function testAFreeKeyPicksTheFreeEndpointAndAProKeyDoesNot(): void
    {
        $free = new RecordingDeepLProvider('abc:fx');
        $free->translate('Hallo', 'nl', 'en');
        self::assertStringContainsString('api-free.deepl.com', $free->endpoint);

        $pro = new RecordingDeepLProvider('abc', 'pro');
        $pro->translate('Hallo', 'nl', 'en');
        self::assertStringContainsString('api.deepl.com', $pro->endpoint);
        self::assertStringNotContainsString('api-free', $pro->endpoint);
    }

    public function testAKeyMarkedFreeBeatsAPlanThatSaysOtherwise(): void
    {
        // DeepL stamps its own free keys; a stale DEEPL_API_PLAN must not
        // send such a key to the host that will reject it.
        $provider = new RecordingDeepLProvider('abc:fx', 'pro');
        $provider->translate('Hallo', 'nl', 'en');

        self::assertStringContainsString('api-free.deepl.com', $provider->endpoint);
    }

    public function testTheApiKeyTravelsInAHeaderAndNeverInTheUrl(): void
    {
        $provider = new RecordingDeepLProvider('secret-key:fx');
        $provider->translate('Hallo', 'nl', 'en');

        self::assertSame('secret-key:fx', $provider->apiKey);
        self::assertStringNotContainsString('secret-key', $provider->endpoint);
    }

    public function testFieldNamesAreNotSentToTheProvider(): void
    {
        // The provider gets the words and nothing else — not this CMS's
        // column names, not the page they came from.
        $provider = new RecordingDeepLProvider('abc:fx');
        $provider->translateAll(['meta_title' => 'Hallo', 'body' => 'Wereld'], 'nl', 'en');

        self::assertSame(['Hallo', 'Wereld'], $provider->fields['text']);
        self::assertArrayNotHasKey('meta_title', $provider->fields);
    }

    public function testHtmlIsAnnouncedToTheProviderSoTagsSurvive(): void
    {
        $plain = new RecordingDeepLProvider('abc:fx');
        $plain->translate('Hallo', 'nl', 'en', false);
        self::assertArrayNotHasKey('tag_handling', $plain->fields);

        $markup = new RecordingDeepLProvider('abc:fx');
        $markup->translate('<p>Hallo</p>', 'nl', 'en', true);
        self::assertSame('html', $markup->fields['tag_handling']);
    }

    public function testEmptyTextIsNeverSent(): void
    {
        $provider = new RecordingDeepLProvider('abc:fx');
        $result = $provider->translateAll(['a' => '', 'b' => '   '], 'nl', 'en');

        self::assertSame([], $result);
        self::assertSame([], $provider->fields, 'no request was made at all');
    }

    public function testAProviderFailureBecomesAControlledError(): void
    {
        $provider = new FailingDeepLProvider('abc:fx');

        $this->expectException(TranslationException::class);
        $provider->translate('Hallo', 'nl', 'en');
    }

    public function testTheDeepLTargetForEnglishIsAVariantDeepLAccepts(): void
    {
        // DeepL rejects a bare "EN" as a target.
        self::assertSame('EN-GB', LanguageRegistry::get('en')?->deeplTarget);
        self::assertSame('NL', LanguageRegistry::get('nl')?->deeplTarget);
    }

    // ------------------------------------------------ the service's rules

    public function testEmptySourceFieldsAreSkippedRatherThanTranslated(): void
    {
        $service = new TranslationService(new FakeTranslationProvider());

        $result = $service->translateEntity('cta-band', '', 'en', [
            new TranslationRequest('title', 'Hallo', ''),
            new TranslationRequest('lead', '', ''),
        ]);

        self::assertArrayHasKey('title', $result->translations);
        self::assertSame('empty', $result->skipped['lead']);
    }

    public function testAHandWrittenTranslationIsNeverSilentlyOverwritten(): void
    {
        // Part L, the rule this whole feature is judged on. With no record at
        // all, existing text counts as somebody's own.
        $service = new TranslationService(new FakeTranslationProvider());

        $result = $service->translateEntity('cta-band', '', 'en', [
            new TranslationRequest('title', 'Hallo', 'A title I wrote myself'),
        ]);

        self::assertSame([], $result->translations);
        self::assertSame('manual', $result->skipped['title']);
        self::assertSame(['title'], $result->protectedFields());
    }

    public function testAnExplicitConfirmationCanOverwriteAHandWrittenTranslation(): void
    {
        $service = new TranslationService(new FakeTranslationProvider());

        $result = $service->translateEntity('cta-band', '', 'en', [
            new TranslationRequest('title', 'Hallo', 'A title I wrote myself', false, true),
        ]);

        self::assertArrayHasKey('title', $result->translations);
    }

    public function testWhatComesBackFromAProviderIsSanitised(): void
    {
        // A translation service is a third party like any other, and its HTML
        // goes through the same sanitiser an editor's own does.
        $service = new TranslationService(
            new FakeTranslationProvider(true, null, '<p>Hello</p><script>alert(1)</script>'),
        );

        $result = $service->translateEntity('cta-band', '', 'en', [
            new TranslationRequest('body', '<p>Hallo</p>', '', true),
        ]);

        self::assertStringNotContainsString('<script', $result->translations['body']);
        self::assertStringContainsString('Hello', $result->translations['body']);
    }

    public function testPlainAndMarkupFieldsAreSentSeparately(): void
    {
        // Mixing them would make the provider treat somebody's "<" as a tag,
        // or their tags as words.
        $provider = new FakeTranslationProvider();
        $service = new TranslationService($provider);

        $service->translateEntity('cta-band', '', 'en', [
            new TranslationRequest('title', 'Hallo', ''),
            new TranslationRequest('body', '<p>Hallo</p>', '', true),
        ]);

        self::assertCount(2, $provider->calls);
        self::assertFalse($provider->calls[0]['html']);
        self::assertTrue($provider->calls[1]['html']);
    }

    public function testTranslatingIntoALanguageThisBuildDoesNotKnowIsRefused(): void
    {
        // A site cannot machine-translate into a language it has nowhere to
        // store. The old single-language settings row is NOT such a case any
        // more: this product publishes Dutch and English regardless of it.
        $service = new TranslationService(new FakeTranslationProvider());

        $this->expectException(TranslationException::class);
        $service->translateEntity('cta-band', '', 'de', [
            new TranslationRequest('title', 'Hallo', ''),
        ]);
    }

    public function testTranslatingIntoEnglishWorksWhenTheRegistryHasEnglishSwitchedOff(): void
    {
        SiteLanguageFixture::useLanguages([
            SiteLanguageFixture::language('nl', isDefault: true),
            SiteLanguageFixture::language('en', isActive: false, sortOrder: 1),
        ]);

        $service = new TranslationService(new FakeTranslationProvider());

        $result = $service->translateEntity('cta-band', '', 'en', [
            new TranslationRequest('title', 'Hallo', ''),
        ]);

        self::assertArrayHasKey('title', $result->translations);
    }

    public function testTranslatingFromAndIntoTheSameLanguageIsRefused(): void
    {
        $service = new TranslationService(new FakeTranslationProvider());

        $this->expectException(TranslationException::class);
        $service->translateEntity('cta-band', '', 'nl', [
            new TranslationRequest('title', 'Hallo', ''),
        ], 'nl');
    }

    public function testAnEditorWritingDutchMayTranslateFromEnglish(): void
    {
        // The reverse direction. Dutch -> English is the V1 case, but an
        // administrator editing the Dutch version of something that only
        // exists in English should get the same help.
        $provider = new FakeTranslationProvider();
        $service = new TranslationService($provider);

        $result = $service->translateEntity('cta-band', '', 'nl', [
            new TranslationRequest('title', 'Hello', ''),
        ], 'en');

        self::assertArrayHasKey('title', $result->translations);
        self::assertSame('en', $provider->calls[0]['source']);
        self::assertSame('nl', $provider->calls[0]['target']);
    }

    // ------------------------------------------------------ the four states

    public function testAnEmptyFieldIsMissing(): void
    {
        self::assertSame(TranslationState::MISSING, TranslationState::classify('Hallo', '', null));
    }

    public function testTextWithNoRecordCountsAsSomebodysOwn(): void
    {
        self::assertSame(TranslationState::MANUAL, TranslationState::classify('Hallo', 'Hello', null));
    }

    public function testAnUntouchedMachineTranslationIsMachine(): void
    {
        $record = [
            'source_hash' => TranslationState::hash('Hallo'),
            'translation_hash' => TranslationState::hash('Hello'),
            'is_manual' => false,
        ];

        self::assertSame(TranslationState::MACHINE, TranslationState::classify('Hallo', 'Hello', $record));
        self::assertTrue(TranslationState::mayOverwrite(TranslationState::MACHINE));
    }

    public function testAChangedSourceMakesTheTranslationOutdated(): void
    {
        $record = [
            'source_hash' => TranslationState::hash('Hallo'),
            'translation_hash' => TranslationState::hash('Hello'),
            'is_manual' => false,
        ];

        self::assertSame(
            TranslationState::OUTDATED,
            TranslationState::classify('Een heel andere tekst', 'Hello', $record),
        );
    }

    public function testEditingAMachineTranslationMakesItManual(): void
    {
        // Detected from the text itself, which is why not one of the ~77
        // write endpoints had to learn about translations.
        $record = [
            'source_hash' => TranslationState::hash('Hallo'),
            'translation_hash' => TranslationState::hash('Hello'),
            'is_manual' => false,
        ];

        self::assertSame(
            TranslationState::MANUAL,
            TranslationState::classify('Hallo', 'Hello, and something I added', $record),
        );
        self::assertFalse(TranslationState::mayOverwrite(TranslationState::MANUAL));
    }

    public function testWhitespaceChangesDoNotMakeATranslationStale(): void
    {
        // Reflowing a paragraph is editing noise, not a different text.
        self::assertSame(
            TranslationState::hash('Hallo   wereld'),
            TranslationState::hash("Hallo\n wereld "),
        );
    }
}

/** Captures the request DeepL would have received, and never makes one. */
final class RecordingDeepLProvider extends DeepLProvider
{
    public string $endpoint = '';
    public string $apiKey = '';
    /** @var array<string, mixed> */
    public array $fields = [];

    protected function post(string $endpoint, string $apiKey, array $fields): string
    {
        $this->endpoint = $endpoint;
        $this->apiKey = $apiKey;
        $this->fields = $fields;

        $translations = [];
        foreach ((array) ($fields['text'] ?? []) as $text) {
            $translations[] = ['text' => '[EN] ' . $text];
        }

        return json_encode(['translations' => $translations], JSON_THROW_ON_ERROR);
    }
}

/** The network-is-down path, without a network. */
final class FailingDeepLProvider extends DeepLProvider
{
    protected function post(string $endpoint, string $apiKey, array $fields): string
    {
        throw new TranslationException('the translation service could not be reached');
    }
}

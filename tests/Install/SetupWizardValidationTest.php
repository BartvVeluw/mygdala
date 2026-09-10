<?php

declare(strict_types=1);

namespace Tests\Install;

use App\Install\SetupWizard;
use App\Module\ModuleConfig;
use App\Module\ModuleRegistry;
use App\Module\ModuleSettings;
use App\Service\AppUrl;
use App\Service\Media\MediaService;
use App\Service\PageTemplates\PageTemplates;
use App\Service\SiteSettings;
use App\Service\Theme\ThemeSettings;
use PHPUnit\Framework\TestCase;

/**
 * What the Setup Wizard accepts, what it refuses, and what it quietly drops.
 *
 * Only validation — nothing here writes anything. The other half, what
 * finishing actually builds, is Tests\Install\SetupCompletionTest, which
 * runs the whole thing against a from-zero database.
 *
 * The recurring theme is that an EMPTY answer is a real answer. A wizard
 * that demanded an address, a KVK number or a logo would be a wizard that
 * invents a business, and inventing one is exactly what the fresh-install
 * cleanup removed. Only the site name is required.
 *
 * No database and no web server: the media library, the theme and the module
 * configuration all have override seams.
 */
final class SetupWizardValidationTest extends TestCase
{
    /** @var array<string, string|null> */
    private array $originalEnvironment = [];

    protected function setUp(): void
    {
        foreach (['APP_URL', 'MODULE_SHOP_ENABLED', 'MODULE_PERSONALIZATION_ENABLED'] as $variable) {
            $this->originalEnvironment[$variable] = $_ENV[$variable] ?? null;
            unset($_ENV[$variable]);
        }

        MediaService::overrideForTests([]);
        ModuleSettings::overrideForTests([]);
        SiteSettings::overrideForTests([]);
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnvironment as $variable => $value) {
            if ($value === null) {
                unset($_ENV[$variable]);
            } else {
                $_ENV[$variable] = $value;
            }
        }

        MediaService::overrideForTests(null);
        ModuleSettings::overrideForTests(null);
        SiteSettings::overrideForTests(null);
        ModuleRegistry::overrideForTests(null);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{values: array<string, mixed>, errors: array<string, string>}
     */
    private function validate(array $input = []): array
    {
        return SetupWizard::validate($input + ['site_name' => 'Testbedrijf']);
    }

    /* ------------------------------------------------------------------ */
    /* Step 1: the website                                                 */
    /* ------------------------------------------------------------------ */

    public function testTheSiteNameIsTheOnlyRequiredAnswerInTheWholeWizard(): void
    {
        $result = SetupWizard::validate([]);

        $this->assertSame(['site_name'], array_keys($result['errors']));
    }

    public function testAWizardWithNothingButASiteNameIsValid(): void
    {
        $result = $this->validate();

        $this->assertSame([], $result['errors']);
        $this->assertSame('Testbedrijf', $result['values']['identity']['site_name']);
    }

    public function testEveryOtherIdentityFieldMayStayEmpty(): void
    {
        $result = $this->validate([
            'email' => '',
            'footer_description_nl' => '',
            'city_nl' => '',
            'kvk_number' => '',
        ]);

        $this->assertSame([], $result['errors']);

        foreach (['email', 'footer_description_nl', 'city_nl', 'kvk_number'] as $key) {
            $this->assertSame('', $result['values']['identity'][$key], $key . ' must be allowed to stay empty');
        }
    }

    public function testAFilledInEmailAddressStillHasToBeOne(): void
    {
        $this->assertArrayHasKey('email', $this->validate(['email' => 'geen-adres'])['errors']);
        $this->assertSame([], $this->validate(['email' => 'info@voorbeeld.nl'])['errors']);
    }

    public function testAnOverlongAnswerIsRejectedRatherThanTruncated(): void
    {
        $result = $this->validate(['site_name' => str_repeat('a', 400)]);

        $this->assertArrayHasKey('site_name', $result['errors']);
    }

    /* ------------------------------------------------------------------ */
    /* Step 1: the base URL, and who owns it                               */
    /* ------------------------------------------------------------------ */

    public function testTheBaseUrlIsStoredWhenTheEnvironmentDoesNotNameOne(): void
    {
        $result = $this->validate([AppUrl::SETTING_KEY => 'https://www.voorbeeld.nl/']);

        $this->assertSame([], $result['errors']);
        $this->assertSame('https://www.voorbeeld.nl', $result['values']['identity'][AppUrl::SETTING_KEY]);
    }

    public function testAnUnusableBaseUrlIsRefusedWithAnExplanation(): void
    {
        $result = $this->validate([AppUrl::SETTING_KEY => 'www.voorbeeld.nl']);

        $this->assertArrayHasKey(AppUrl::SETTING_KEY, $result['errors']);
        $this->assertStringContainsString('https://', $result['errors'][AppUrl::SETTING_KEY]);
    }

    public function testAnEmptyBaseUrlIsAllowedAndSimplyStaysUnconfigured(): void
    {
        $result = $this->validate([AppUrl::SETTING_KEY => '']);

        $this->assertSame([], $result['errors']);
        $this->assertSame('', $result['values']['identity'][AppUrl::SETTING_KEY]);
    }

    /**
     * The wizard must not pretend it can change something .env owns. A value
     * posted anyway is ignored rather than stored, so the CMS never holds a
     * second answer that silently loses.
     */
    public function testABaseUrlPostedWhileTheEnvironmentPinsOneIsIgnored(): void
    {
        $_ENV['APP_URL'] = 'https://www.uit-de-omgeving.example';

        $result = $this->validate([AppUrl::SETTING_KEY => 'https://www.stiekem.example']);

        $this->assertSame([], $result['errors']);
        $this->assertArrayNotHasKey(AppUrl::SETTING_KEY, $result['values']['identity']);
    }

    /* ------------------------------------------------------------------ */
    /* Step 2: branding                                                    */
    /* ------------------------------------------------------------------ */

    public function testBrandingIsOptionalAndAnEmptyFieldMeansNoImage(): void
    {
        $result = $this->validate();

        $this->assertSame([], $result['errors']);
        $this->assertSame('', $result['values']['branding']['logo_media_id']);
        $this->assertSame('', $result['values']['branding']['logo_path']);
    }

    public function testAChosenImageFillsBothTheReferenceAndItsPathFallback(): void
    {
        MediaService::overrideForTests([
            7 => ['path' => 'assets/media/logo.svg', 'mime_type' => 'image/svg+xml', 'original_filename' => 'logo.svg'],
        ]);

        $result = $this->validate(['logo_media_id' => '7']);

        $this->assertSame([], $result['errors']);
        $this->assertSame('7', $result['values']['branding']['logo_media_id']);
        $this->assertSame('assets/media/logo.svg', $result['values']['branding']['logo_path']);
    }

    public function testAnIdThatNamesNothingIsRefusedRatherThanStored(): void
    {
        MediaService::overrideForTests([999999 => null]);

        $result = $this->validate(['logo_media_id' => '999999']);

        $this->assertArrayHasKey('branding', $result['errors']);
        $this->assertArrayNotHasKey('logo_media_id', $result['values']['branding']);
    }

    /* ------------------------------------------------------------------ */
    /* Step 3: appearance                                                  */
    /* ------------------------------------------------------------------ */

    public function testAppearanceGoesThroughTheExistingThemeEngineUnchanged(): void
    {
        $result = $this->validate([
            'primary_color' => '2b6cb0',
            'font_pairing' => 'poppins-inter',
            'button_shape' => 'rounded',
        ]);

        $this->assertSame([], $result['errors']);
        $this->assertSame('#2B6CB0', $result['values']['theme']['primary_color']);
        $this->assertSame('poppins-inter', $result['values']['theme']['font_pairing']);
        $this->assertSame('rounded', $result['values']['theme']['button_shape']);
    }

    public function testAColourThatIsNotAColourIsRefusedByTheThemeEngineItself(): void
    {
        $result = $this->validate(['primary_color' => 'red; background:url(x)']);

        $this->assertArrayHasKey('primary_color', $result['errors']);
    }

    public function testAFontPairingOutsideTheClosedListIsRefused(): void
    {
        $result = $this->validate(['font_pairing' => 'comic-sans']);

        $this->assertArrayHasKey('font_pairing', $result['errors']);
    }

    public function testTheWizardDoesNotInventAppearanceSettingsOfItsOwn(): void
    {
        $result = $this->validate(array_fill_keys(ThemeSettings::keys(), ''));

        // Empty theme fields are simply not stored — a missing row means the
        // shipped default, which is what makes a fresh install coherent
        // before anybody has chosen anything (THEMING.md).
        $this->assertSame([], array_diff(array_keys($result['values']['theme']), ThemeSettings::keys()));
    }

    /* ------------------------------------------------------------------ */
    /* Step 4: modules                                                     */
    /* ------------------------------------------------------------------ */

    public function testOnlyRegisteredModuleKeysSurvive(): void
    {
        $result = $this->validate(['modules' => ['shop' => '1', 'kassasysteem' => '1']]);

        $this->assertArrayNotHasKey('kassasysteem', $result['values']['modules']);
        $this->assertTrue($result['values']['modules']['shop']);
    }

    /**
     * Every module the wizard actually OFFERS is recorded, including the ones
     * left unticked — otherwise "I do not want this" would be stored as "no
     * opinion" and the default would decide instead.
     *
     * A module the environment pins is deliberately not among them: the
     * wizard shows that checkbox disabled and writes nothing for it, because
     * .env has the last word (SETUP.md). This suite runs in a container that
     * pins one, so the two cases are asserted apart rather than assumed away.
     */
    public function testAnUntickedModuleIsRecordedAsOffRatherThanForgotten(): void
    {
        $result = $this->validate(['modules' => []]);

        foreach (ModuleRegistry::keys() as $key) {
            if (ModuleConfig::isPinnedByEnvironment($key)) {
                $this->assertArrayNotHasKey($key, $result['values']['modules'], $key . ' is pinned in the environment');
                continue;
            }

            $this->assertFalse($result['values']['modules'][$key], $key);
        }
    }

    public function testPersonalisationWithoutTheShopIsRefusedWithAReason(): void
    {
        $result = $this->validate(['modules' => ['personalization' => '1']]);

        $this->assertArrayHasKey('modules', $result['errors']);
        $this->assertStringContainsString(ModuleRegistry::label('shop'), $result['errors']['modules']);
    }

    public function testPersonalisationWithTheShopIsFine(): void
    {
        $result = $this->validate(['modules' => ['shop' => '1', 'personalization' => '1']]);

        $this->assertSame([], $result['errors']);
        $this->assertTrue($result['values']['modules']['personalization']);
    }

    public function testAModuleTheEnvironmentPinsIsNotTheWizardsToChange(): void
    {
        $_ENV['MODULE_SHOP_ENABLED'] = 'false';

        $result = $this->validate(['modules' => ['shop' => '1']]);

        $this->assertArrayNotHasKey('shop', $result['values']['modules']);
        $this->assertTrue(ModuleConfig::isPinnedByEnvironment('shop'));
    }

    /* ------------------------------------------------------------------ */
    /* Step 5: starter pages                                               */
    /* ------------------------------------------------------------------ */

    public function testNoStarterPageIsSelectedByDefault(): void
    {
        $this->assertSame([], $this->validate()['values']['pages']);
    }

    public function testOnlyKnownStarterKeysSurvive(): void
    {
        $result = $this->validate(['pages' => ['about' => '1', 'webshop-landing' => '1']]);

        $this->assertSame(['about'], $result['values']['pages']);
    }

    public function testEveryStarterPageNamesARegisteredGenericTemplate(): void
    {
        foreach (SetupWizard::STARTER_PAGES as $key => $page) {
            $this->assertTrue(
                PageTemplates::has($page['template']),
                "starter page {$key} names template {$page['template']}, which is not registered"
            );
        }
    }

    /**
     * Portfolio is the one an owner might expect and must not be offered:
     * there is no generic Portfolio template to build it from, and a
     * checkbox that cannot be honoured is worse than no checkbox.
     */
    public function testNoStarterPageIsOfferedWithoutATemplateBehindIt(): void
    {
        $this->assertArrayNotHasKey('portfolio', SetupWizard::STARTER_PAGES);
    }

    public function testTheStarterPagesCarryNoContentOfTheirOwn(): void
    {
        foreach (SetupWizard::STARTER_PAGES as $key => $page) {
            foreach (['van veluw', 'laserdesign', 'nijmegen', 'lasergravure'] as $literal) {
                $this->assertStringNotContainsString(
                    $literal,
                    strtolower(implode(' ', [
                        $page['title'], $page['slug'], $page['alternate_slug'], $page['label'], $page['description'],
                    ])),
                    "starter page {$key} must not describe one particular company"
                );
            }
        }
    }
}

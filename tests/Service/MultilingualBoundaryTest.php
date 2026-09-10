<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\Language\ContentLanguages;
use App\Service\Language\LanguageRegistry;
use PHPUnit\Framework\TestCase;

/**
 * The boundaries Multilingual V1 draws, read off the source.
 *
 * A source-reading test rather than a behavioural one, for the same reason
 * Tests\Module\ShopDisabledTest and Tests\Service\FormBoundaryTest are: these
 * rules are about what the code may MENTION, and the cheapest place to notice
 * a regression is the file that reintroduces it.
 */
final class MultilingualBoundaryTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function read(string $relative): string
    {
        $path = self::root() . '/' . $relative;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /** @return string[] */
    private static function glob(string $pattern): array
    {
        return glob(self::root() . '/' . $pattern) ?: [];
    }

    // ---------------------------------------------- the API key stays server-side

    public function testTheTranslationApiKeyNeverReachesTheBrowser(): void
    {
        // The single most important rule of Part H. The key is read from the
        // environment by PHP; nothing in admin/, partials/ or assets/ may so
        // much as name the variable.
        $patterns = ['admin/*.php', 'admin/assets/*.js', 'partials/*.php', 'assets/js/*.js', 'assets/js/**/*.js'];

        foreach ($patterns as $pattern) {
            foreach (self::glob($pattern) as $file) {
                $source = (string) file_get_contents($file);

                self::assertStringNotContainsString(
                    'DEEPL_API_KEY',
                    $source,
                    basename($file) . ' must never name the translation API key',
                );
                self::assertStringNotContainsString(
                    'deepl.com',
                    $source,
                    basename($file) . ' must never call a translation API directly',
                );
            }
        }
    }

    public function testOnlyTheProviderKnowsTheProvidersEndpoints(): void
    {
        // Everything else goes through the interface, which is what makes a
        // second provider one class plus one line.
        foreach (self::glob('src/Service/**/*.php') as $file) {
            if (str_ends_with($file, 'DeepLProvider.php')) {
                continue;
            }

            self::assertStringNotContainsString(
                'deepl.com',
                (string) file_get_contents($file),
                basename($file) . ' must not know a provider\'s endpoint',
            );
        }
    }

    public function testTheTranslateEndpointWritesNoContent(): void
    {
        // It hands the translation back to the screen; the screen saves it
        // with the form's own endpoint. A second write path into every
        // content table is exactly what this project does not have.
        $source = self::read('api/admin/translate-fields.php');

        foreach (['UPDATE ', 'INSERT ', 'DELETE ', 'upsert'] as $forbidden) {
            self::assertStringNotContainsString(
                $forbidden,
                $source,
                'the translate endpoint must not write content',
            );
        }
    }

    public function testEveryTranslationEndpointCarriesTheUsualGuards(): void
    {
        foreach (['api/admin/translate-fields.php', 'api/admin/update-language-settings.php', 'api/admin/update-account-preferences.php'] as $endpoint) {
            $source = self::read($endpoint);

            self::assertStringContainsString('AdminAuth::requireLoginForApi()', $source, $endpoint . ' checks login');
            self::assertStringContainsString("REQUEST_METHOD'] !== 'POST'", $source, $endpoint . ' checks the method');
            self::assertStringContainsString('Csrf::validate(', $source, $endpoint . ' checks CSRF');
        }
    }

    public function testChangingTheCmsLanguageCannotTouchTheWebsite(): void
    {
        // Enforced by construction: the endpoint that writes a person's CMS
        // language cannot reach site_settings at all.
        $source = self::read('api/admin/update-account-preferences.php');

        self::assertStringNotContainsString('SiteSettingRepository', $source);
        self::assertStringNotContainsString('ContentLanguages', $source);
    }

    public function testChangingTheWebsiteLanguageCannotTouchAnybodysCmsLanguage(): void
    {
        $source = self::read('api/admin/update-language-settings.php');

        self::assertStringNotContainsString('AdminUserRepository', $source);
        self::assertStringNotContainsString('AdminLocale', $source);
        self::assertStringNotContainsString('interface_language', $source);
    }

    // ---------------------------------------------- the CMS interface is curated

    public function testTheCmsInterfaceIsNeverMachineTranslated(): void
    {
        // Curated application strings, always. A button that says "Opslaan"
        // must say "Save" and not whatever an API returned this morning.
        foreach (self::glob('src/Service/Language/*.php') as $file) {
            // An IMPORT, not a mention. These files are allowed — encouraged,
            // even — to explain in prose why the CMS interface is never
            // machine translated, and AdminTranslator's docblock does exactly
            // that. What they may not do is reach for a provider.
            self::assertDoesNotMatchRegularExpression(
                '/^use App\\\\Service\\\\Translation\\\\/m',
                (string) file_get_contents($file),
                basename($file) . ' must not reach for a translation provider',
            );
        }
    }

    // ---------------------------------------------- existing storage is untouched

    public function testNoMigrationInThisStepRemovesABilingualColumn(): void
    {
        // Part A: existing `_nl` and `_en` columns stay, and existing sites
        // depend on them. A migration that dropped one would take somebody's
        // content with it.
        foreach (['db/migrations/20260910140000_add_multilingual_language_settings.php',
                  'db/migrations/20260910150000_create_the_translation_state_table.php'] as $migration) {
            $source = self::read($migration);

            self::assertStringNotContainsString('removeColumn(\'title', $source);
            self::assertStringNotContainsString('_en\')->', $source);
            self::assertDoesNotMatchRegularExpression(
                '/removeColumn\(\s*\'[a-z_]+_(nl|en)\'/',
                $source,
                basename($migration) . ' must not remove a bilingual column',
            );
        }
    }

    public function testTheStateTableStoresNoTranslatedText(): void
    {
        // It remembers facts ABOUT a translation; the translation itself
        // stays in the column it has always lived in.
        $source = self::read('db/migrations/20260910150000_create_the_translation_state_table.php');

        self::assertStringContainsString("addColumn('source_hash'", $source);
        self::assertStringContainsString("addColumn('translation_hash'", $source);
        self::assertStringNotContainsString("addColumn('translated_text'", $source);
        self::assertStringNotContainsString("addColumn('content'", $source);
    }

    // ---------------------------------------------- V1's deliberate limits

    public function testNoLocalizedUrlsOrHreflangWereIntroduced(): void
    {
        // Part P. Both languages still live on one URL; localized routes and
        // hreflang are deferred to a later step, and SEO.md says so.
        foreach (self::glob('partials/*.php') as $file) {
            // The rendered ATTRIBUTE, not the word: partials/seo-head.php
            // explains in a comment why this project emits none, and that
            // comment is the documentation of the decision.
            self::assertStringNotContainsString(
                'hreflang="',
                (string) file_get_contents($file),
                basename($file) . ' must not emit hreflang in V1',
            );
        }
    }

    public function testTheRegistryIsClosedAndWrittenInCode(): void
    {
        $source = self::read('src/Service/Language/LanguageRegistry.php');

        // The same closed-list guarantee as BlockDefinitions and
        // ModuleRegistry: no scanning, no reflection, no class name from a
        // row or a request.
        self::assertStringNotContainsString('glob(', $source);
        self::assertStringNotContainsString('scandir(', $source);
        self::assertStringNotContainsString('ReflectionClass', $source);
        self::assertStringNotContainsString('$_GET', $source);
        self::assertStringNotContainsString('$_POST', $source);
    }

    public function testV1CeilingIsOneSecondaryLanguage(): void
    {
        self::assertSame(2, ContentLanguages::MAX_ENABLED);
        self::assertCount(2, LanguageRegistry::codes(), 'V1 registers exactly Dutch and English');
    }

    // ---------------------------------------------- the editor component

    public function testTheEditorComponentPreservesADisabledLanguagesValues(): void
    {
        // The mechanism behind "turning a language off deletes nothing": the
        // field is still rendered and still submits, it is just `hidden`.
        $source = self::read('admin/_language_fields.php');

        self::assertStringContainsString("' hidden'", $source);
        self::assertStringNotContainsString('disabled="disabled"', $source);
        self::assertStringNotContainsString("' disabled'", $source);
    }

    public function testRequiredIsOnlyEverOnThePrimaryLanguage(): void
    {
        // A required control inside a hidden pane is a form that cannot be
        // submitted and cannot say why.
        $source = self::read('admin/_language_fields.php');

        self::assertStringContainsString('admin_lang_required', $source);
        self::assertStringContainsString('admin_lang_primary()', $source);
    }

    public function testNoEditorStillHardcodesADutchOnlyFallbackPlaceholder(): void
    {
        // "Leeg = zelfde als NL" is wrong on an English-primary site. Every
        // converted editor asks admin_lang_placeholder_attr() instead.
        foreach (self::glob('admin/*.php') as $file) {
            $source = (string) file_get_contents($file);

            if (basename($file) === '_language_fields.php' || !str_contains($source, 'admin_lang_pane_start')) {
                continue;
            }

            self::assertStringNotContainsString(
                'Leeg = zelfde als NL',
                $source,
                basename($file) . ' must not hardcode a Dutch-only fallback hint',
            );
        }
    }

    public function testEveryEditorWithLanguagePanesLoadsTheComponentAndItsScript(): void
    {
        foreach (self::glob('admin/*.php') as $file) {
            $source = (string) file_get_contents($file);

            if (basename($file) === '_language_fields.php' || !str_contains($source, 'admin_lang_pane_start')) {
                continue;
            }

            self::assertStringContainsString(
                "_language_fields.php",
                $source,
                basename($file) . ' must require the language component',
            );
            self::assertStringContainsString(
                'admin_lang_tabs()',
                $source,
                basename($file) . ' must render a language tab strip',
            );
            self::assertStringContainsString(
                'admin_lang_tabs_script()',
                $source,
                basename($file) . ' must load the language tab script',
            );
        }
    }
}

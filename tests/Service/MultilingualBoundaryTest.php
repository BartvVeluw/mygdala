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
        foreach (['api/admin/translate-fields.php', 'api/admin/update-language-settings.php', 'api/admin/update-account-preferences.php', 'api/admin/update-content-language.php'] as $endpoint) {
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

    public function testChangingTheEditingLanguageCannotTouchTheWebsiteOrTheCmsLanguage(): void
    {
        // The third state, guarded the same way as the other two: the
        // endpoint that records which language version an administrator is
        // editing can reach neither the site's settings nor anybody's CMS
        // interface language.
        $source = self::read('api/admin/update-content-language.php');

        self::assertStringNotContainsString('SiteSettingRepository', $source);
        self::assertStringNotContainsString('AdminLocale', $source);
        self::assertStringNotContainsString('interface_language', $source);
    }

    public function testTheEditingLanguageServiceKnowsNothingOfTheCmsLanguage(): void
    {
        $source = self::read('src/Service/Language/ContentEditingLanguage.php');

        // The prose may name its sibling; the CODE may not call it.
        self::assertStringNotContainsString('AdminLocale::', $source);
        self::assertStringNotContainsString('SiteSettingRepository', $source);
        self::assertStringNotContainsString("'interface_language'", $source);
    }

    public function testTheCmsLanguageServiceKnowsNothingOfTheEditingLanguage(): void
    {
        $source = self::read('src/Service/Language/AdminLocale.php');

        self::assertStringNotContainsString('ContentEditingLanguage::', $source);
        self::assertStringNotContainsString('content_editing_language', $source);
    }

    public function testTheEditingLanguageIsPersistedByItsOwnStatement(): void
    {
        // Two preferences on one row, and two separate writes. One statement
        // setting both is the place where they would start moving together
        // by accident.
        $source = self::read('src/Repository/AdminUserRepository.php');

        self::assertStringContainsString('updateInterfaceLanguage', $source);
        self::assertStringContainsString('updateContentEditingLanguage', $source);
        self::assertStringNotContainsString(
            'interface_language = :language, content_editing_language',
            $source,
            'the two preferences must not share one UPDATE',
        );
    }

    public function testThePublicLanguageSwitchIsNotGatedOnASetting(): void
    {
        // THE REGRESSION THIS GUARD EXISTS FOR. The header used to hide the
        // switch when a settings row said the site published one language,
        // which left visitors no way to ask for English on a site that had
        // English content.
        $source = self::read('src/Service/Language/SiteText.php');

        self::assertStringNotContainsString(
            'ContentLanguages::isMultilingual()',
            $source,
            'the public switch must not be gated on the deprecated enabled-languages setting',
        );
    }

    public function testNoStoredValueDecidesWhichLanguagesArePublished(): void
    {
        // The deprecated `enabled_content_languages` row is gone since
        // Multilingual 2.0 phase 1, and the registry that replaced it must not
        // take over its old job before the frontend flip: ::enabled() answers
        // from the closed V1 registry. Only ::primary() reads the website
        // language registry.
        $source = self::read('src/Service/Language/ContentLanguages.php');

        $enabled = substr($source, strpos($source, 'public static function enabled()'));
        $enabled = substr($enabled, 0, strpos($enabled, 'public static function secondaries()'));

        self::assertStringContainsString('LanguageRegistry::contentLanguages()', $enabled);
        self::assertStringNotContainsString('SiteLanguages::', $enabled, '::enabled() must not read the active flags of the registry yet');
        self::assertStringNotContainsString('SiteSettings', $source, 'no website language is a settings row any more');
    }

    // ---------------------------------------------- the website language registry

    /** The Core of Multilingual 2.0 phase 1 (docs/multilingual/ARCHITECTURE.md). */
    private const LANGUAGE_CORE = [
        'src/Service/Language/SiteLanguages.php',
        'src/Service/Language/SiteLanguage.php',
        'src/Service/Language/LanguageCode.php',
        'src/Repository/SiteLanguageRepository.php',
    ];

    public function testTheWebsiteLanguageCoreCannotReachTheCmsLanguage(): void
    {
        foreach (self::LANGUAGE_CORE as $file) {
            $source = self::read($file);

            self::assertStringNotContainsString('AdminLocale::', $source, $file);
            self::assertStringNotContainsString('AdminTranslator::', $source, $file);
            self::assertStringNotContainsString('AdminUserRepository', $source, $file);
            self::assertStringNotContainsString('interface_language', $source, $file);
            self::assertStringNotContainsString('ContentEditingLanguage::', $source, $file);
        }
    }

    public function testTheCmsLanguageCannotReachTheWebsiteLanguageRegistry(): void
    {
        foreach (['src/Service/Language/AdminLocale.php', 'src/Service/Language/AdminTranslator.php', 'api/admin/update-account-preferences.php'] as $file) {
            $source = self::read($file);

            self::assertStringNotContainsString('SiteLanguages', $source, $file);
            self::assertStringNotContainsString('SiteLanguageRepository', $source, $file);
            self::assertStringNotContainsString('site_languages', $source, $file);
        }
    }

    /** A quoted language code, with or without a region: 'nl', "de", 'pt-BR'. */
    private const QUOTED_CODE = '/[\x27"]([a-z]{2}(?:[-_][a-z]{2})?)[\x27"]/i';

    /** A language by its English, Dutch or own name. */
    private const LANGUAGE_NAME = '/\b(?:Dutch|Nederlands|English|Engels|German|Deutsch|Duits|French|Français|Frans|Italian|Italiano|Spanish|Español|Portuguese|Polish|Swedish|Danish|Czech)\b/iu';

    public function testTheLanguageCoreKnowsNoLanguageByName(): void
    {
        // Dynamic languages are rows. A code or a language name in the code,
        // be it a whitelist, a match arm or a default, or a reach back into
        // the closed V1 registry, would make the core a closed list again.
        // Comments are left out, so a docblock may still give an example.
        foreach (self::LANGUAGE_CORE as $file) {
            $source = self::read($file);
            $code = self::withoutComments($source);

            preg_match_all(self::QUOTED_CODE, $code, $quoted);
            // `id` is the row id column of site_languages, not a language.
            $codes = array_values(array_diff($quoted[1], ['id']));

            self::assertSame([], $codes, $file . ' names a language code');
            self::assertDoesNotMatchRegularExpression(self::LANGUAGE_NAME, $code, $file . ' names a language');
            self::assertStringNotContainsString('LanguageRegistry', $source, $file);
            self::assertStringNotContainsString('LanguageDefinition', $code, $file);
            self::assertStringNotContainsString('ModuleRegistry', $source, $file . ' must not check a module');
        }
    }

    public function testTheCoreNameCheckWouldNoticeAWhitelist(): void
    {
        // The check above only proves something if it fails on the thing it
        // guards against.
        $whitelist = "<?php\n/** Supported: nl, en. */\nconst CODES = ['nl', \"de\", 'pt-BR'];\n\$label = 'Deutsch';\n\$row['id'];\n";
        $code = self::withoutComments($whitelist);

        preg_match_all(self::QUOTED_CODE, $code, $quoted);

        self::assertSame(['nl', 'de', 'pt-BR'], array_values(array_diff($quoted[1], ['id'])));
        self::assertMatchesRegularExpression(self::LANGUAGE_NAME, $code);
        self::assertStringNotContainsString('Supported', $code, 'a comment is not code');
    }

    /** The files that decide a WEBSITE language, next to the Core itself. */
    private const WEBSITE_LANGUAGE_CALLERS = [
        'src/Service/Language/ContentLanguages.php',
        'src/Install/SetupWizard.php',
        'api/admin/update-language-settings.php',
        'admin/setup.php',
    ];

    /** The files that own the CMS interface language (docs/multilingual/CMS-LANGUAGE.md). */
    private const CMS_LANGUAGE_OWNERS = [
        'src/Service/Language/AdminLocale.php',
        'src/Service/Language/AdminTranslator.php',
        'api/admin/update-account-preferences.php',
        'admin/account.php',
    ];

    public function testNoWebsiteLanguageIsValidatedThroughTheCmsLanguage(): void
    {
        // AdminLocale is the CMS interface language, and nothing else. Its
        // list holds Dutch and English as well, so borrowing its normalise()
        // for a website language works today and quietly breaks the day a
        // site gets a language the CMS is not translated into. admin/setup.php
        // did exactly that until Multilingual 2.0 phase 1.
        $validating = '/\bAdminLocale::(?:normalise|choices|is|persist)\s*\(/';

        foreach (array_merge(self::LANGUAGE_CORE, self::WEBSITE_LANGUAGE_CALLERS) as $file) {
            self::assertDoesNotMatchRegularExpression($validating, self::read($file), $file . ' is about the website language');
        }

        $offenders = [];
        foreach (self::applicationSources() as $relative => $file) {
            if (!in_array($relative, self::CMS_LANGUAGE_OWNERS, true)
                && preg_match($validating, (string) file_get_contents($file)) === 1) {
                $offenders[] = $relative;
            }
        }

        self::assertSame([], $offenders, 'only the CMS language screens may validate through AdminLocale; a website language goes through LanguageCode and SiteLanguages');
    }

    public function testTheSetupWizardTakesItsWebsiteLanguageFromTheWebsiteLanguageLayer(): void
    {
        $screen = self::read('admin/setup.php');

        // The one AdminLocale call left is the language the screen itself is
        // shown in, for <html lang>.
        preg_match_all('/\bAdminLocale::(\w+)/', $screen, $calls);
        self::assertSame(['current'], array_values(array_unique($calls[1])));
        self::assertStringContainsString('SetupWizard::websiteLanguage(', $screen, 'the dropdown reads a rejected submission back the way the save reads it');

        $wizard = self::read('src/Install/SetupWizard.php');
        $method = substr($wizard, (int) strpos($wizard, 'public static function websiteLanguage('));
        $method = substr($method, 0, (int) strpos($method, "\n    }"));

        self::assertStringContainsString('LanguageCode::normalise(', $method);
        self::assertStringContainsString('ContentLanguages::normalisePrimary(', $method);
        self::assertStringContainsString("self::websiteLanguage(\$input['primary_content_language']", $wizard, 'the save goes through the same method');
        self::assertStringNotContainsString('AdminLocale::', $wizard);
    }

    public function testOnlyItsRepositoryWritesTheRegistryTable(): void
    {
        // The invariants (one active default that is never switched off or
        // deleted) are in that repository's SQL. A statement anywhere else
        // would walk past them.
        $statement = '/\b(?:FROM|INTO|UPDATE|JOIN|TABLE)\s+`?site_languages\b/i';
        $files = self::applicationSources();

        $offenders = [];
        foreach ($files as $relative => $file) {
            if ($relative === 'src/Repository/SiteLanguageRepository.php') {
                continue;
            }

            if (preg_match($statement, (string) file_get_contents($file)) === 1) {
                $offenders[] = $relative;
            }
        }

        self::assertNotSame([], $files);
        self::assertSame([], $offenders);
    }

    /** @return array<string, string> every application PHP file, relative path => absolute path */
    private static function applicationSources(): array
    {
        $files = array_merge(
            self::glob('src/*.php'),
            self::glob('src/*/*.php'),
            self::glob('src/*/*/*.php'),
            self::glob('src/*/*/*/*.php'),
            self::glob('api/*.php'),
            self::glob('api/*/*.php'),
            self::glob('admin/*.php'),
            self::glob('partials/*.php'),
            self::glob('scripts/*.php'),
            self::glob('*.php'),
        );

        $sources = [];
        foreach ($files as $file) {
            $sources[ltrim(substr(str_replace(DIRECTORY_SEPARATOR, '/', $file), strlen(str_replace(DIRECTORY_SEPARATOR, '/', self::root()))), '/')] = $file;
        }

        return $sources;
    }

    /** PHP source with its comments and docblocks left out. */
    private static function withoutComments(string $source): string
    {
        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
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

    /**
     * A HIDDEN LANGUAGE PANE IS ACTUALLY HIDDEN.
     *
     * This is the rule the Navigation/Footer bug was: `.admin-lang-pane`
     * gives the pane a `display`, and a class that sets `display` silently
     * beats the browser's own [hidden]{display:none}. Both languages were
     * then on the form at once, under labels that deliberately no longer say
     * which is which, and an editor who believed the form had switched typed
     * their translation into the other language's field.
     *
     * It survived for a while because the block editors wrap their form in
     * .admin-product-form, which restores the attribute for its own subtree.
     * Navigation, Footer, the portfolio and the personalization builder do
     * not use that class, and that is exactly the set of screens where the
     * bug showed. The rule has to belong to the pane.
     */
    public function testAHiddenLanguagePaneIsActuallyHidden(): void
    {
        $css = self::read('admin/assets/admin.css');

        self::assertMatchesRegularExpression(
            '/\.admin-lang-pane\[hidden\]\s*\{[^}]*display:\s*none/',
            $css,
            'admin.css must give .admin-lang-pane[hidden] a display:none of its own, '
                . 'or the language an editor is not editing stays on screen'
        );
    }

    /**
     * NO LOCALIZED CONTROL SPELLS `required` BY HAND.
     *
     * `required` on a translation is wrong twice over. A translation is
     * optional by definition (the site falls back), and a required, empty
     * control inside a `hidden` pane makes the browser refuse to submit while
     * being unable to focus the field it is complaining about, so Save stops
     * working with nothing on screen to explain it.
     *
     * admin_lang_required() answers both at once, and the point of this test
     * is that every editor asks it instead of writing the attribute. The old
     * version of this test only checked that the helper existed, which is why
     * navigation-item.php, footer-column.php, footer-link.php and
     * settings.php could carry a literal one for as long as they did.
     */
    public function testNoLocalizedFieldSpellsRequiredByHand(): void
    {
        $offenders = [];

        foreach (self::glob('admin/*.php') as $file) {
            $source = (string) file_get_contents($file);

            // A screen with no second language on it has no pane to hide a
            // control in, so `required` there is an ordinary required field.
            if (in_array(basename($file), self::SCREENS_WITHOUT_A_SECOND_LANGUAGE, true)) {
                continue;
            }

            if (!str_contains($source, 'admin_lang_pane_start')) {
                continue;
            }

            // A tag may carry a PHP echo, whose closing angle bracket must
            // not be read as the end of the tag.
            preg_match_all('/<(?:input|textarea)\b(?:\?>|[^>])*>/', $source, $tags);

            foreach ($tags[0] as $tag) {
                if (preg_match(self::LOCALIZED_FIELD, $tag) !== 1) {
                    continue;
                }

                $withoutHelper = str_replace('admin_lang_required', '', $tag);

                if (preg_match('/\brequired\b/', $withoutHelper) === 1) {
                    $offenders[] = basename($file) . ': ' . trim((string) preg_replace('/\s+/', ' ', $tag));
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            "these localized controls are `required` regardless of which language is on screen:\n  "
                . implode("\n  ", $offenders)
        );
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
                'admin_lang_bar(',
                $source,
                basename($file) . ' must render the localized-fields bar',
            );

            // A PARTIAL has no </body> of its own and so cannot load a
            // script; the screen that includes it does. What matters is that
            // the script reaches the browser, not which file wrote the tag.
            if (str_starts_with(basename($file), '_')) {
                $screens = self::screensIncluding(basename($file));

                self::assertNotSame(
                    [],
                    $screens,
                    basename($file) . ' renders language panes but no screen includes it',
                );

                foreach ($screens as $screen) {
                    self::assertStringContainsString(
                        'admin_lang_script()',
                        (string) file_get_contents($screen),
                        basename($screen) . ' includes ' . basename($file) . ' and must load the language script',
                    );
                }

                continue;
            }

            self::assertStringContainsString(
                'admin_lang_script()',
                $source,
                basename($file) . ' must load the language script',
            );
        }
    }

    // ------------------------------------------- no editor is bilingual on screen

    /**
     * THE REGRESSION THIS FILE EXISTS FOR, after the browser found what the
     * suite did not.
     *
     * Every earlier check here only inspected editors that ALREADY used the
     * component, so the screens that had never been converted — the page
     * editor among them — were simply never looked at, and shipped with a
     * Dutch field printed beside an English one on a Dutch-only site. This
     * test asks the opposite question: is there a localized field anywhere in
     * admin/ that is NOT inside a language pane?
     *
     * A field is localized when its name carries a `_nl` or `_en` suffix, or
     * when it is a rich-text field declared with one. Those are the only two
     * ways this project spells a translatable control.
     */
    public function testNoLocalizedFieldIsRenderedOutsideALanguagePane(): void
    {
        $offenders = [];

        foreach (self::glob('admin/*.php') as $file) {
            if (in_array(basename($file), self::SCREENS_WITHOUT_A_SECOND_LANGUAGE, true)) {
                continue;
            }

            $depth = 0;
            foreach (explode("\n", str_replace("\r\n", "\n", (string) file_get_contents($file))) as $number => $line) {
                $opens = substr_count($line, 'admin_lang_pane_start');
                $closes = substr_count($line, 'admin_lang_pane_end');

                if (preg_match_all(self::LOCALIZED_FIELD, $line, $matches, PREG_SET_ORDER) > 0 && $depth + $opens === 0) {
                    foreach ($matches as $match) {
                        $offenders[] = sprintf(
                            '%s:%d %s',
                            basename($file),
                            $number + 1,
                            $match[1] !== '' ? $match[1] : $match[2]
                        );
                    }
                }

                $depth += $opens - $closes;
            }
        }

        self::assertSame(
            [],
            $offenders,
            "these fields are printed next to their other language instead of in a pane:\n  "
                . implode("\n  ", $offenders)
        );
    }

    /**
     * No label says "(NL)" or "(EN)" any more. Inside a pane the suffix is
     * noise (the tab already says which language you are in) and on a
     * single-language site it is a question about a language the site does
     * not publish.
     */
    public function testNoEditorLabelsAFieldWithALanguageSuffix(): void
    {
        foreach (self::glob('admin/*.php') as $file) {
            if (in_array(basename($file), self::SCREENS_WITHOUT_A_SECOND_LANGUAGE, true)) {
                continue;
            }

            self::assertDoesNotMatchRegularExpression(
                '/>[^<>]*\((?:NL|EN)\)/',
                (string) file_get_contents($file),
                basename($file) . ' must not label a field with its language',
            );
        }
    }

    /**
     * The bug that `php -l` cannot see: a screen that calls admin_lang_tabs()
     * without requiring the file that defines it is a fatal error at the
     * first line of its <form>, which renders as half a page and no message.
     * admin/rich-text.php shipped exactly that.
     */
    public function testEveryScreenCanReachTheHelpersItCalls(): void
    {
        $definedBy = [
            'admin_lang_' => '_language_fields.php',
            'admin_te(' => '_translate.php',
        ];

        foreach (self::glob('admin/*.php') as $file) {
            $source = (string) file_get_contents($file);
            $name = basename($file);

            foreach ($definedBy as $call => $definition) {
                if ($name === $definition || !str_contains($source, $call)) {
                    continue;
                }

                // _language_fields.php requires _translate.php, and
                // _header.php does too, so either include is enough.
                $reachable = str_contains($source, $definition)
                    || ($definition === '_translate.php'
                        && (str_contains($source, '_language_fields.php') || str_contains($source, '_header.php')));

                self::assertTrue(
                    $reachable,
                    $name . ' calls ' . $call . ' but never requires ' . $definition,
                );
            }
        }
    }

    // ---------------------------------------------- the CMS interface switches

    /**
     * The sidebar is the first thing anybody sees, and in V1 it was the last
     * thing that stayed Dutch: switching the CMS to English changed five
     * words in the shell and left every menu item alone, which reads as
     * "the setting does nothing".
     */
    public function testEverySidebarEntryHasATranslation(): void
    {
        $missing = [];

        foreach (self::navigationKeys() as $key) {
            foreach (['nl', 'en'] as $locale) {
                if (!isset(self::catalog($locale)['nav.' . $key])) {
                    $missing[] = $locale . ': nav.' . $key;
                }
            }
        }

        self::assertSame([], $missing, 'untranslated sidebar entries: ' . implode(', ', $missing));
    }

    /**
     * A page that hardcodes lang="nl" tells a screen reader and a spell
     * checker the wrong thing the moment somebody runs the CMS in English.
     */
    public function testNoAdminScreenHardcodesADutchDocumentLanguage(): void
    {
        foreach (self::glob('admin/*.php') as $file) {
            self::assertStringNotContainsString(
                '<html lang="nl">',
                (string) file_get_contents($file),
                basename($file) . ' must let AdminLocale decide the document language',
            );
        }
    }

    /**
     * The catalogs are two halves of one thing: a key that exists in Dutch
     * and not in English renders Dutch on an English screen.
     */
    public function testTheEnglishCatalogIsCompleteAgainstTheDutchOne(): void
    {
        $missing = array_diff(array_keys(self::catalog('nl')), array_keys(self::catalog('en')));

        self::assertSame([], array_values($missing), 'missing English: ' . implode(', ', $missing));

        $extra = array_diff(array_keys(self::catalog('en')), array_keys(self::catalog('nl')));

        self::assertSame([], array_values($extra), 'English keys with no Dutch reference: ' . implode(', ', $extra));
    }

    // ---------------------------------------------------------------- helpers

    /**
     * A localized control: `name="x_nl"` / `name="x_en"`, or a rich-text
     * field declared with such a name.
     */
    private const LOCALIZED_FIELD = '/name="([a-z0-9_]+_(?:nl|en))"'
        . '|renderRichTextField\(\s*\x27([a-z0-9_]+_(?:nl|en))\x27'
        . '|name="<\?=\s*[A-Za-z\\\\]+::([A-Z0-9_]+_(?:NL|EN))\s*\?>"/';

    /**
     * Screens that legitimately write a `_nl` column with no `_en` beside it,
     * so there is no second language on screen to hide.
     *
     * setup.php runs BEFORE a site has languages at all — it is the screen
     * that creates the settings ContentLanguages later reads — and form.php's
     * "add a field" form asks for one label, which the field's own editor
     * then translates.
     */
    private const SCREENS_WITHOUT_A_SECOND_LANGUAGE = ['setup.php', 'form.php'];

    /** @return string[] every admin screen that require()s $partial */
    private static function screensIncluding(string $partial): array
    {
        $screens = [];
        foreach (self::glob('admin/*.php') as $file) {
            if (basename($file) === $partial) {
                continue;
            }

            if (str_contains((string) file_get_contents($file), $partial)) {
                $screens[] = $file;
            }
        }

        return $screens;
    }

    /** @return string[] the 'key' of every navigation entry Core and the modules declare */
    private static function navigationKeys(): array
    {
        $keys = [];
        $sources = array_merge(
            [self::root() . '/src/Service/AdminNavigation.php'],
            self::glob('src/Module/*Module.php')
        );

        foreach ($sources as $source) {
            preg_match_all(
                '/\x27key\x27\s*=>\s*\x27([a-z0-9_]+)\x27,\s*\n\s*\x27label\x27/',
                (string) file_get_contents($source),
                $matches
            );
            foreach ($matches[1] as $key) {
                $keys[$key] = true;
            }
        }

        return array_keys($keys);
    }

    /** @return array<string, string> */
    private static function catalog(string $locale): array
    {
        /** @var array<string, string> $messages */
        $messages = require self::root() . '/src/Service/Language/messages/' . $locale . '.php';

        return $messages;
    }

    // ------------------------------------------ no screen ships Dutch-only

    /**
     * Dutch words that give away a SENTENCE rather than a name. Deliberately
     * function words and CMS verbs, not "every Dutch word": a proper noun, a
     * unit, a file extension and a brand name are all legitimate literals in
     * a template, and a guard that flagged them would be turned off within a
     * week.
     */
    private const DUTCH_GIVEAWAY = '/\b(de|het|een|deze|dit|niet|geen|wordt|worden|zijn|kun|kunt'
        . '|moet|nog|voor|van|naar|bij|waar|welke|hoe|wie|opslaan|verwijderen|bewerken|toevoegen'
        . '|aanmaken|wijzigen|verplicht|pagina|gebruiker|afbeelding|formulier|bestand|instellingen)\b/iu';

    /**
     * Screens and strings that are Dutch on purpose.
     *
     * Every entry names something that is NOT CMS interface copy:
     *   - content this CMS writes into the database for an editor to edit,
     *   - a value stored in site settings, which is the site owner's own text,
     *   - the catalogue files themselves, which are supposed to hold Dutch.
     *
     * Anything else that turns up here is a screen that would ship Dutch-only,
     * and the way to clear it is a key, not a line in this list.
     *
     * @var array<string, string> file or fragment => why
     */
    private const DUTCH_ON_PURPOSE = [
        '_labels.php' => 'reads the catalogue; its own literals are keys',
        'setup.php' => 'the wizard writes starter CONTENT, not interface copy',
    ];

    /**
     * THE GUARD. No admin screen may print a Dutch sentence as a literal.
     *
     * This is what stops the next screen shipping Dutch-only: the words a
     * person reads have to come from the catalogue, and a template that spells
     * one out fails here rather than on somebody's screen.
     *
     * It reads TEXT NODES only — what a browser would render — after masking
     * PHP, comments and <script>/<style>, because everything else in an admin
     * file is code, and a guard that read code would be noise.
     */
    public function testNoAdminScreenPrintsADutchSentenceOfItsOwn(): void
    {
        $offenders = [];

        foreach (self::glob('admin/*.php') as $file) {
            $name = basename($file);
            if (isset(self::DUTCH_ON_PURPOSE[$name])) {
                continue;
            }

            foreach (self::textNodes((string) file_get_contents($file)) as $text) {
                if (preg_match(self::DUTCH_GIVEAWAY, $text) === 1) {
                    $offenders[] = $name . ': ' . mb_substr($text, 0, 70);
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            "these screens print Dutch instead of asking the catalogue:\n  " . implode("\n  ", $offenders)
        );
    }

    /**
     * The same rule for what a write endpoint hands BACK to a person.
     *
     * A validation message is interface copy too — it is read by the same
     * administrator, on the same screen, in the same language.
     */
    public function testNoAdminEndpointAnswersWithADutchSentenceOfItsOwn(): void
    {
        $offenders = [];

        foreach (self::glob('api/admin/*.php') as $file) {
            $source = (string) file_get_contents($file);

            preg_match_all(
                '/(?:\$errors\[\]\s*=\s*|\$_SESSION\[\'[a-z_]+\'\]\s*=\s*)\'([^\']{8,240})\'/',
                $source,
                $matches
            );

            foreach ($matches[1] as $message) {
                if (preg_match(self::DUTCH_GIVEAWAY, $message) === 1) {
                    $offenders[] = basename($file) . ': ' . mb_substr($message, 0, 70);
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            "these endpoints answer in Dutch instead of asking the catalogue:\n  " . implode("\n  ", $offenders)
        );
    }

    // ------------------------------------- Pages on per-language storage (phase 2)

    public function testOnlyItsRepositoryQueriesPageTranslations(): void
    {
        // PageLocalization owns the fallback and the rule that a language
        // must be registered; SQL anywhere else would walk past both.
        $statement = '/\b(?:FROM|INTO|UPDATE|JOIN|TABLE)\s+`?page_translations\b/i';
        $offenders = [];

        foreach (self::applicationSources() as $relative => $file) {
            if ($relative === 'src/Repository/PageTranslationRepository.php') {
                continue;
            }

            if (preg_match($statement, (string) file_get_contents($file)) === 1) {
                $offenders[] = $relative;
            }
        }

        self::assertSame([], $offenders);
    }

    public function testOnlyThePageLocalizationApiUsesThatRepository(): void
    {
        $offenders = [];

        foreach (self::applicationSources() as $relative => $file) {
            if (in_array($relative, ['src/Repository/PageTranslationRepository.php', 'src/Service/PageLocalization.php'], true)) {
                continue;
            }

            if (str_contains((string) file_get_contents($file), 'PageTranslationRepository')) {
                $offenders[] = $relative;
            }
        }

        self::assertSame([], $offenders, 'templates, endpoints and services reach page text through App\Service\PageLocalization');
    }

    public function testNothingReadsTheDroppedPageTextColumns(): void
    {
        // 20260917150000 dropped title, title_en, meta_title(_en) and
        // meta_description(_en) from `pages`. SQL that still names one fails
        // at runtime, and a row key that still reads one silently reads NULL.
        $offenders = [];

        foreach (self::applicationSources() as $relative => $file) {
            $source = (string) file_get_contents($file);

            // `p.title` only where `p` is `pages`: the blog aliases its own
            // table as `p` too.
            // A quoted `'pages.title'` is a catalogue key, not SQL.
            $sql = preg_match('/\bpages\s+(?:AS\s+)?p\b/i', $source) === 1
                ? '/(?<![\x27\w.])(?:p|pages)\.(?:title|meta_title|meta_description)\b/'
                : '/(?<![\x27\w.])pages\.(?:title|meta_title|meta_description)\b/';

            if (preg_match($sql, $source) === 1) {
                $offenders[] = $relative . ' (SQL)';
            }

            if (preg_match('/\$(?:page|pageRow|pageLabelRow|targetPage|linkedPage)\[[\x27"](?:title|title_en|meta_title|meta_title_en|meta_description|meta_description_en)[\x27"]\]/', $source) === 1) {
                $offenders[] = $relative . ' (row key)';
            }
        }

        foreach (['src/Repository/PageRepository.php', 'src/Service/PageContent.php', 'src/Service/PageSeo.php'] as $file) {
            $code = self::withoutComments(self::read($file));

            foreach (['title_en', 'meta_title', 'meta_description'] as $column) {
                if (str_contains($code, "'" . $column)) {
                    $offenders[] = $file . ' (' . $column . ')';
                }
            }
        }

        self::assertSame([], $offenders);
    }

    public function testThePageFallbackIsDecidedInOnePlace(): void
    {
        // requested language -> default language -> '' lives in
        // PageLocalization::value(). A breadcrumb, the SEO head, an editor or a
        // template that asked for the default language itself would be a
        // second fallback that can drift from the first.
        foreach ([
            'src/Service/Breadcrumbs/PageBreadcrumb.php',
            'src/Service/Breadcrumbs/BreadcrumbTrail.php',
            'src/Service/PageSeo.php',
            'src/Service/PageContent.php',
            'admin/page.php',
            'partials/page-head.php',
            'pagina.php',
        ] as $file) {
            $code = self::withoutComments(self::read($file));

            // Asking for the default language is the first step of writing a
            // fallback, so none of these may ask.
            self::assertStringNotContainsString('SiteLanguages::defaultCode', $code, $file);
            self::assertStringNotContainsString('ContentLanguages::primary', $code, $file);
            self::assertStringNotContainsString('PageLocalization::defaultLanguage', $code, $file);
            self::assertStringNotContainsString('LocalizedValue::of', $code, $file);
        }
    }

    // ------------------------------- Blocks on per-language storage (phase 3)

    public function testOnlyItsRepositoryQueriesBlockTranslations(): void
    {
        // BlockLocalization owns the closed list of owner tables and fields,
        // the fallback, the language check and the orphan guard; SQL anywhere
        // else would walk past all four.
        $statement = '/\b(?:FROM|INTO|UPDATE|JOIN|TABLE)\s+`?block_translations\b/i';
        $offenders = [];

        foreach (self::applicationSources() as $relative => $file) {
            if ($relative === 'src/Repository/BlockTranslationRepository.php') {
                continue;
            }

            if (preg_match($statement, (string) file_get_contents($file)) === 1) {
                $offenders[] = $relative;
            }
        }

        self::assertSame([], $offenders);
    }

    public function testOnlyTheBlockLocalizationApiUsesThatRepository(): void
    {
        $offenders = [];

        foreach (self::applicationSources() as $relative => $file) {
            if (in_array($relative, ['src/Repository/BlockTranslationRepository.php', 'src/Service/Blocks/BlockLocalization.php'], true)) {
                continue;
            }

            if (str_contains((string) file_get_contents($file), 'BlockTranslationRepository')) {
                $offenders[] = $relative;
            }
        }

        self::assertSame([], $offenders, 'block content classes, partials and endpoints reach block words through App\Service\Blocks\BlockLocalization');
    }

    public function testDeletingABlockTakesItsWordsInsideTheSameTransaction(): void
    {
        // owner_id cannot be a foreign key, so this line in the one delete
        // path every block goes through is the integrity guard.
        $registry = self::withoutComments(self::read('src/Service/SectionRegistry.php'));

        // The words go first: a block's child rows are found through its row,
        // and deleteContent() lets the database cascade them away.
        self::assertMatchesRegularExpression(
            '/beginTransaction\(\);\s*try\s*\{.*?BlockLocalization::deleteOwner\(\$contentTable,.*?->deleteContent\(\$pageSection\);.*?\$db->commit\(\);/s',
            $registry
        );
    }

    public function testAPageLoadsTheWordsOfAllItsBlocksAtOnce(): void
    {
        $registry = self::withoutComments(self::read('src/Service/SectionRegistry.php'));

        self::assertMatchesRegularExpression(
            '/function renderPage\(.*?BlockLocalization::preloadSections\(\$sections\);\s*foreach \(\$sections as \$pageSection\)/s',
            $registry,
            'one query for the words of every block on a page, before the first block renders'
        );
    }

    /**
     * The files of the block types on block_translations: the three of phase
     * 3A, and the ones phase 3B moved, wave by wave. Always six, in this
     * order: definition, repository, content class, partial, editor, endpoint.
     */
    private const CONVERTED_BLOCK_FILES = [
        'rich_text' => [
            'src/Service/Blocks/RichTextBlock.php', 'src/Repository/RichTextRepository.php', 'src/Service/RichTextContent.php',
            'partials/section-rich-text.php', 'admin/rich-text.php', 'api/admin/update-rich-text-section.php',
        ],
        'cta_band' => [
            'src/Service/Blocks/CtaBandBlock.php', 'src/Repository/CtaBandRepository.php', 'src/Service/CtaBandContent.php',
            'partials/section-cta-band.php', 'admin/cta-band.php', 'api/admin/update-cta-band.php',
        ],
        'contact_card' => [
            'src/Service/Blocks/ContactCardBlock.php', 'src/Repository/ContactCardRepository.php', 'src/Service/ContactCardContent.php',
            'partials/section-contact-card.php', 'admin/contact-card.php', 'api/admin/update-contact-card.php',
        ],
        // Phase 3B, wave A: blocks without child rows.
        'page_hero' => [
            'src/Service/Blocks/PageHeroBlock.php', 'src/Repository/PageHeroRepository.php', 'src/Service/PageHeroContent.php',
            'partials/section-page-hero.php', 'admin/page-hero.php', 'api/admin/update-page-hero.php',
        ],
        'form' => [
            'src/Service/Blocks/FormBlock.php', 'src/Repository/FormBlockRepository.php', 'src/Service/FormBlockContent.php',
            'partials/section-form.php', 'admin/form-block.php', 'api/admin/update-form-block.php',
        ],
        'contact_form' => [
            'src/Service/Blocks/ContactFormBlock.php', 'src/Repository/ContactFormRepository.php', 'src/Service/ContactFormContent.php',
            'partials/section-contact-form.php', 'admin/contact-form.php', 'api/admin/update-contact-form.php',
        ],
        'item_gallery' => [
            'src/Service/Blocks/ItemGalleryBlock.php', 'src/Repository/ItemGalleryRepository.php', 'src/Service/ItemGalleryContent.php',
            'partials/section-item-gallery.php', 'admin/item-gallery.php', 'api/admin/update-item-gallery.php',
        ],
        'project_cards' => [
            'src/Service/Blocks/ProjectCardsBlock.php', 'src/Repository/ItemGalleryRepository.php', 'src/Service/ItemGalleryContent.php',
            'partials/section-item-gallery.php', 'admin/project-cards.php', 'api/admin/update-project-cards.php',
        ],
    ];

    /**
     * What a converted block's own files may still print the V1 way, because
     * it is another domain's Dutch/English pair that moves in its own phase:
     * file => the keys and helpers it may name.
     */
    private const OTHER_DOMAINS_PAIRS = [
        // The contact details are Site-instellingen (phase 4).
        'partials/section-contact-form.php' => ['city_nl', 'city_en', 'SiteText::attrs(', 'SiteText::visible(', 'LocalizedValue::ofDutchEnglish'],
        'src/Service/Blocks/ContactFormBlock.php' => ['city_nl', 'city_en'],
        // The cards are a Portfolio item or a product, with its category (phase 5).
        'partials/section-item-gallery.php' => ['alt_nl', 'alt_en', 'title_nl', 'title_en', 'subtitle_nl', 'subtitle_en', 'name_nl', 'name_en', 'SiteText::attrs(', 'SiteText::visible('],
        'src/Service/Blocks/ItemGalleryBlock.php' => ['alt_nl', 'alt_en'],
        'src/Service/Blocks/ProjectCardsBlock.php' => ['alt_nl', 'alt_en'],
    ];

    public function testNothingReadsTheDroppedColumnsOfTheConvertedBlocks(): void
    {
        // 20260917170000 and the phase 3B migrations dropped the Dutch/English
        // word columns of these blocks. A file that still names one fails at
        // runtime, or silently reads nothing.
        $offenders = [];

        foreach (self::CONVERTED_BLOCK_FILES as $files) {
            foreach ($files as $file) {
                $code = self::withoutComments(self::read($file));

                preg_match_all('/[\x27"$\[>]\s*((?:content_html(?:_en)?|[a-z_]+_(?:nl|en)))\b/', $code, $matches);
                foreach (array_unique($matches[1]) as $name) {
                    if (!in_array($name, self::OTHER_DOMAINS_PAIRS[$file] ?? [], true)) {
                        $offenders[] = $file . ' (' . $name . ')';
                    }
                }
            }
        }

        // The two consumers outside the blocks' own files: the terms hash
        // reads the Tekstblok body, the portfolio detail page borrows a CTA
        // band (its own portfolio columns are phase 5's).
        if (str_contains(self::withoutComments(self::read('src/Service/LegalPages.php')), "'content_html")) {
            $offenders[] = 'src/Service/LegalPages.php (content_html)';
        }

        if (preg_match('/\$cta\[\x27[a-z_]+_(?:nl|en)\x27\]/', self::withoutComments(self::read('portfolio-detail.php'))) === 1) {
            $offenders[] = 'portfolio-detail.php ($cta[…_nl/_en])';
        }

        self::assertSame([], $offenders);
    }

    public function testTheConvertedBlocksDeclareTheirWordsAndDecideNoLanguageThemselves(): void
    {
        foreach (self::CONVERTED_BLOCK_FILES as $type => $files) {
            self::assertNotSame([], \App\Service\Blocks\BlockDefinitions::get($type)?->translatableFields() ?? [], $type . ' declares its translatable fields');

            foreach ($files as $file) {
                $code = self::withoutComments(self::read($file));

                // The fallback and the default language are BlockLocalization's.
                foreach (['SiteLanguages::defaultCode', 'ContentLanguages::primary', 'LocalizedValue::ofDutchEnglish', 'SiteText::attrs(', 'SiteText::visible(', 'LanguageRegistry::'] as $forbidden) {
                    if (!in_array($forbidden, self::OTHER_DOMAINS_PAIRS[$file] ?? [], true)) {
                        self::assertStringNotContainsString($forbidden, $code, $file);
                    }
                }
            }
        }
    }

    public function testTheConvertedPartialsPrintThroughSiteTextAndOnlyRichTextAsHtml(): void
    {
        $rich = self::withoutComments(self::read('partials/section-rich-text.php'));
        self::assertStringContainsString('SiteText::htmlAttrsOf($section[\'body\'])', $rich, 'the body is marked data-lang-html through the one helper');
        self::assertStringContainsString('SiteText::visibleOf(', $rich);

        $plainPartials = array_unique(array_filter(
            array_map(static fn (array $files): string => $files[3], self::CONVERTED_BLOCK_FILES),
            static fn (string $partial): bool => $partial !== 'partials/section-rich-text.php'
        ));

        foreach ($plainPartials as $plain) {
            $code = self::withoutComments(self::read($plain));
            self::assertStringContainsString('SiteText::attrsOf(', $code, $plain);
            self::assertStringNotContainsString('htmlAttrsOf', $code, $plain . ' prints only plain text');
            self::assertStringNotContainsString('data-lang-html', $code, $plain);
        }

        $siteText = self::withoutComments(self::read('src/Service/Language/SiteText.php'));
        self::assertMatchesRegularExpression('/function htmlAttrsOf\(.*?\' data-lang-html\' \. self::attrsOf\(/s', $siteText);
        self::assertMatchesRegularExpression('/function attrsOf\(.*?self::escape\(\$value\)/s', $siteText, 'every half is escaped');
    }

    public function testTheConvertedEditorsShowOneLanguageAndTheirEndpointsWriteOnlyThatLanguage(): void
    {
        foreach (self::CONVERTED_BLOCK_FILES as $type => $files) {
            [, , , , $editor, $endpoint] = $files;
            $screen = self::withoutComments(self::read($editor));
            $write = self::withoutComments(self::read($endpoint));

            self::assertStringContainsString('_localized_fields.php', $screen, $editor);
            self::assertStringNotContainsString('_language_fields.php', $screen, $editor . ': no V1 panes');
            self::assertStringContainsString('admin_localized_input($editLanguage)', $screen, $editor);
            self::assertStringContainsString('BlockLocalization::raw(', $screen, $editor . ': the stored words, without the fallback');
            self::assertStringContainsString("['language_code'] ?? null) === \$editLanguage", $screen, $editor . ': handed-back words only in their own language');
            self::assertStringContainsString('data-save-bar-unsaved', $screen, $editor);

            self::assertStringContainsString('SiteLanguages::isActive($languageCode)', $write, $endpoint);
            self::assertMatchesRegularExpression('/BlockLocalization::save\(\x27[a-z_]+\x27, [^,]+, \$languageCode,/', $write, $endpoint);
            self::assertStringContainsString('BlockLocalization::problems(', $write, $endpoint . ': the declared fields are the validation');
            self::assertMatchesRegularExpression('/beginTransaction\(\);.*?upsert(?:Section)?\(.*?BlockLocalization::save\(.*?commit\(\);/s', $write, $endpoint . ': settings and words are one save');
        }
    }

    public function testThePageBuilderLoadsTheWordsOfItsBlockLabelsAtOnce(): void
    {
        self::assertMatchesRegularExpression(
            '/\$allSections = \$repository->findForPage\(\$pageId\);\s*.*?BlockLocalization::preloadSections\(\$allSections\);/s',
            self::withoutComments(self::read('admin/page.php'))
        );
    }

    public function testTheBlockLocalizationApiKnowsNoLanguageByName(): void
    {
        foreach (['src/Service/Blocks/BlockLocalization.php', 'src/Service/Blocks/TranslatableField.php', 'src/Repository/BlockTranslationRepository.php'] as $file) {
            $code = self::withoutComments(self::read($file));

            preg_match_all(self::QUOTED_CODE, $code, $quoted);
            self::assertSame([], $quoted[0], $file . ': no quoted language code');
            self::assertSame(0, preg_match(self::LANGUAGE_NAME, $code), $file . ': no language name');
            self::assertDoesNotMatchRegularExpression('/_(?:nl|en)\b/', $code, $file . ': no fixed language suffix');
        }
    }

    public function testTheLocalizedFieldsComponentKnowsNoLanguageByName(): void
    {
        // The Admin primitive of phase 2: the languages are rows of
        // site_languages, so a code, a language name or a `_nl`/`_en` suffix in
        // its code would make it a closed list again.
        $code = self::withoutComments(self::read('admin/_localized_fields.php'));

        preg_match_all(self::QUOTED_CODE, $code, $quoted);
        self::assertSame([], $quoted[0], 'no quoted language code');
        self::assertSame(0, preg_match(self::LANGUAGE_NAME, $code), 'no language name');
        self::assertDoesNotMatchRegularExpression('/_(?:nl|en)\b/', $code, 'no fixed language suffix');
        self::assertStringNotContainsString('LanguageRegistry::', $code, 'the V1 registry is not its list');
        self::assertStringContainsString('SiteLanguages::active()', $code);
        self::assertStringContainsString('ContentEditingLanguage::current()', $code, 'the shell switch is the one language control');
        self::assertStringNotContainsString(' hidden', $code, 'nothing of another language is rendered hidden');
    }

    public function testThePageEndpointsWriteTextOnlyThroughTheLocalizationApi(): void
    {
        $update = self::read('api/admin/update-page.php');
        $create = self::read('api/admin/create-page.php');

        self::assertStringContainsString('PageLocalization::save($id, $languageCode,', $update);
        self::assertStringContainsString('SiteLanguages::isActive($languageCode)', $update, 'the language is checked against the registry before anything is written');
        self::assertMatchesRegularExpression('/\$title === \'\' && \$isDefaultLanguage/', $update, 'the title is required in the default language only');

        self::assertStringContainsString('PageLocalization::defaultLanguage() => [', $create, 'a new page is written in the default language');

        foreach ([$update, $create] as $source) {
            self::assertStringNotContainsString('_en', self::withoutComments($source));
        }
    }

    public function testTheShellSwitchListsTheWebsiteLanguagesFromTheRegistry(): void
    {
        $header = self::withoutComments(self::read('admin/_header.php'));
        $service = self::withoutComments(self::read('src/Service/Language/ContentEditingLanguage.php'));

        self::assertStringContainsString('ContentEditingLanguage::choices()', $header);
        self::assertStringContainsString('isDefault', $header, 'the default language is marked');
        self::assertStringNotContainsString('ContentLanguages::enabled()', $header);
        self::assertStringContainsString('SiteLanguages::active()', $service);
        self::assertStringNotContainsString('AdminLocale', $service);
    }

    // ---------------------------------- the language switch renders text as text

    /**
     * The whole point of the data-nl/data-en swap: a value is EDITOR-supplied
     * plain text unless an element opts out with data-lang-html. assets/js/core.js
     * writes the default with textContent — never innerHTML — so a label an
     * editor typed as "<img src=x onerror=…>" can never execute when a visitor
     * toggles the language. This is the fix for the stored-XSS route the
     * pages.manage permission opened through a navigation label; before it,
     * applyLang() assigned el.innerHTML unconditionally.
     */
    public function testTheLanguageSwitchWritesPlainTextWithTextContent(): void
    {
        $core = self::read('assets/js/core.js');

        // The default path for a bilingual element is textContent.
        self::assertStringContainsString('el.textContent = val;', $core);

        // innerHTML is reachable ONLY inside the data-lang-html branch.
        self::assertMatchesRegularExpression(
            '/hasAttribute\("data-lang-html"\)\)\s*\{\s*el\.innerHTML = val;/',
            $core,
            'core.js may write a language value with innerHTML only for a data-lang-html element',
        );

        // And that is the ONLY innerHTML assignment in the file: no second,
        // unguarded sink may creep back in next to it.
        self::assertSame(
            1,
            substr_count($core, '.innerHTML ='),
            'core.js must assign innerHTML exactly once, in the data-lang-html branch',
        );

        // The exact old vulnerable line must be gone.
        self::assertStringNotContainsString('if (val != null) el.innerHTML = val;', $core);
    }

    /**
     * The block scripts that build bilingual DOM themselves follow the same
     * rule. The marquee re-renders plain-text material/category labels, so it
     * writes them with textContent; only the shop's product-detail description
     * — server-sanitized HTML (DescriptionSanitizer) — is marked data-lang-html
     * so applyLang() keeps rendering it as markup on a switch.
     */
    public function testBlockScriptsThatSwapLanguagesDoNotFeedDatasetToInnerHtml(): void
    {
        $marquee = self::read('assets/js/blocks/marquee.js');
        self::assertStringContainsString('span.textContent =', $marquee);
        self::assertStringNotContainsString('span.innerHTML', $marquee);

        $shop = self::read('assets/js/shop/shop.js');
        self::assertStringContainsString('descEl.setAttribute("data-lang-html", "");', $shop);
        self::assertSame(
            1,
            substr_count($shop, 'setAttribute("data-lang-html"'),
            'only the product description — sanitized HTML — is marked as HTML in shop.js',
        );
    }

    /**
     * Every element that genuinely carries HTML — rich text (RichTextSanitizer),
     * the hero title fragment (a hardcoded <em> around escaped text), the
     * developer-authored cookie/checkout link sentences — marks itself with
     * data-lang-html, or the language switch would print its markup as text.
     */
    public function testEveryGenuinelyHtmlBilingualElementIsMarked(): void
    {
        $mustMark = [
            'partials/section-rich-text.php',
            'partials/section-detail-section.php',
            'partials/section-homepage-hero.php',
            'partials/cookie-consent.php',
            'blog-post.php',
            'collectie.php',
            'portfolio-detail.php',
            'checkout.php',
            'bestelling-status.php',
        ];

        foreach ($mustMark as $file) {
            self::assertStringContainsString(
                'data-lang-html',
                self::read($file),
                $file . ' renders real HTML through data-nl/data-en and must mark it as HTML',
            );
        }
    }

    /**
     * And the opposite guard, which is the one that keeps false positives out:
     * a template that only ever prints plain-text labels — navigation, the
     * footer, the breadcrumb, form labels — must NOT carry the marker, so a
     * future editor field is never quietly promoted to HTML.
     */
    public function testPlainTextTemplatesAreNeverMarkedAsHtml(): void
    {
        $mustNotMark = [
            'partials/header.php',
            'partials/footer.php',
            'partials/breadcrumb.php',
            'partials/form.php',
            'partials/section-card-carousel.php',
        ];

        foreach ($mustNotMark as $file) {
            self::assertStringNotContainsString(
                'data-lang-html',
                self::read($file),
                $file . ' carries only plain-text labels and must not mark them as HTML',
            );
        }
    }

    /**
     * The behaviour behind the source guards, proven with PHP's own DOM as the
     * browser's: the exact value the server escapes into data-en and the
     * browser decodes back on read is inert when written with textContent (the
     * default) and only becomes live markup when written with innerHTML (the
     * data-lang-html path). Malicious-looking plain text stays text; allowed
     * sanitized rich text stays HTML.
     */
    public function testTextContentKeepsAPayloadInertWhileInnerHtmlKeepsRichText(): void
    {
        $payload = '<img src=x onerror="document.body.dataset.xss=\'1\'">';

        // Server → attribute → browser dataset read is a lossless round-trip.
        $decoded = html_entity_decode(
            htmlspecialchars($payload, ENT_QUOTES, 'UTF-8'),
            ENT_QUOTES,
            'UTF-8'
        );
        self::assertSame($payload, $decoded, 'the attribute round-trip returns the editor value verbatim');

        // applyLang()'s default: textContent. The value never becomes an element.
        $doc = new \DOMDocument();
        $span = $doc->appendChild($doc->createElement('span'));
        $span->textContent = $decoded;
        self::assertSame(0, $span->getElementsByTagName('img')->length, 'a plain-text label must stay text, never an <img>');
        self::assertSame($payload, $span->textContent, 'and the literal payload is what a visitor sees, as text');

        // applyLang()'s data-lang-html path: innerHTML keeps sanitized markup.
        $rich = '<p>Bold <strong>text</strong> and a <a href="/x">link</a></p>';
        $htmlDoc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $htmlDoc->loadHTML('<?xml encoding="utf-8"?><div>' . $rich . '</div>');
        libxml_clear_errors();
        self::assertSame(1, $htmlDoc->getElementsByTagName('strong')->length, 'allowed rich text keeps its markup as HTML');
        self::assertSame(1, $htmlDoc->getElementsByTagName('a')->length);
    }

    /**
     * What a browser would RENDER from an admin template: the text between
     * tags, with PHP, comments and <script>/<style> masked out first.
     *
     * A docblock in this project carries usage examples that include `?>`, so
     * comments are masked BEFORE the PHP blocks — otherwise the mask ends in
     * the middle of a comment and its prose reads as page text.
     *
     * @return list<string>
     */
    private static function textNodes(string $source): array
    {
        $masked = preg_replace('/\/\*.*?\*\//s', ' ', $source) ?? $source;
        $masked = preg_replace('/<\?php.*?\?>|<\?=.*?\?>|<\?php.*\z/s', "\x00", $masked) ?? $masked;
        $masked = preg_replace('/<(script|style)\b[^<>]*>.*?<\/\1\s*>/is', "\x00", $masked) ?? $masked;
        $masked = preg_replace('/<[^<>]*>/s', "\x00", $masked) ?? $masked;

        $nodes = [];
        foreach (explode("\x00", $masked) as $piece) {
            $text = trim(preg_replace('/\s+/u', ' ', $piece) ?? $piece);
            if ($text === '' || mb_strlen($text) < 4) {
                continue;
            }

            $nodes[] = $text;
        }

        return $nodes;
    }
}

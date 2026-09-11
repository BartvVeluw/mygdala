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

    public function testTheDeprecatedEnabledLanguagesSettingDecidesNothing(): void
    {
        // The row may still be read for diagnostics, and it is still written
        // so it stays truthful. What it may never do again is decide which
        // languages a visitor or an editor gets.
        $source = self::read('src/Service/Language/ContentLanguages.php');

        $enabled = substr($source, strpos($source, 'public static function enabled()'));
        $enabled = substr($enabled, 0, strpos($enabled, 'public static function storedEnabled()'));

        self::assertStringNotContainsString(
            'SETTING_ENABLED',
            $enabled,
            '::enabled() must answer from the registry, not from the settings row',
        );
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

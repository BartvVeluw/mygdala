<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\Personalization\PersonalizationLocalization;
use PHPUnit\Framework\TestCase;
use Tests\Support\SiteLanguageFixture;

/**
 * The Personalisatie module's words per website language (Multilingual 2.0
 * phase 5 wave D, docs/multilingual/ARCHITECTURE.md, MODULES.md
 * "Personalisatie"), without a database: the three stores are
 * App\Service\Language\EntityTranslations, so this file asks what
 * App\Service\Personalization\PersonalizationLocalization adds on top of it —
 * which field belongs to which store, the lengths the columns had, the
 * fallback, and above all that WORDS ARE NOT THE CONFIGURATION.
 *
 * The real SQL and the editor are
 * Tests\Repository\ProductPersonalizationRepositoryIntegrationTest's and
 * Tests\Service\PersonalizationCmsSeparationTest's; the backfill is
 * Tests\Install\PersonalizationWordsMigrationTest's.
 */
final class PersonalizationLocalizationTest extends TestCase
{
    private const SETTINGS = 101;
    private const VIEW = 102;
    private const ZONE = 103;

    protected function setUp(): void
    {
        SiteLanguageFixture::useBilingual('nl');
        PersonalizationLocalization::clearCache();
    }

    protected function tearDown(): void
    {
        PersonalizationLocalization::clearCache();
        SiteLanguageFixture::reset();
    }

    /* ------------------------------------------------------------------ */
    /* Which field lives where                                             */
    /* ------------------------------------------------------------------ */

    public function testEachStoreDeclaresItsOwnTableAndFields(): void
    {
        $settings = PersonalizationLocalization::settings()->table();
        $views = PersonalizationLocalization::views()->table();
        $zones = PersonalizationLocalization::zones()->table();

        self::assertSame('product_personalization_translations', $settings->name);
        self::assertSame('settings_id', $settings->ownerColumn);
        self::assertSame(['instructions'], $settings->fieldNames());

        self::assertSame('product_personalization_view_translations', $views->name);
        self::assertSame('view_id', $views->ownerColumn);
        self::assertSame(['label'], $views->fieldNames());

        self::assertSame('product_personalization_zone_translations', $zones->name);
        self::assertSame('zone_id', $zones->ownerColumn);
        self::assertSame(['label', 'instructions', 'placeholder'], $zones->fieldNames());
    }

    /**
     * THE LINE THIS WAVE HANGS ON. A store of the module's words knows nothing
     * that decides what a customer may DO: not the key an order points at, not
     * the geometry, not a switch, not the surcharge. A language switch
     * therefore cannot move a zone, enable one, or change what engraving
     * costs.
     */
    public function testNoStoreKnowsAnythingTheConfigurationDecidesWith(): void
    {
        foreach ([
            PersonalizationLocalization::settings(),
            PersonalizationLocalization::views(),
            PersonalizationLocalization::zones(),
        ] as $store) {
            foreach ([
                'view_key', 'zone_key', 'settings_id', 'view_id', 'preview_image_path',
                'area_x', 'area_y', 'area_width', 'area_height',
                'allow_text', 'allow_image', 'is_enabled', 'is_required', 'allow_rotation',
                'max_text_length', 'surcharge', 'default_font', 'allowed_fonts',
                'personalization_mode', 'sort_order',
            ] as $configuration) {
                self::assertNotContains(
                    $configuration,
                    $store->table()->fieldNames(),
                    $store->table()->name . '.' . $configuration
                );
            }
        }
    }

    public function testTheLengthsAreTheOnesTheOldColumnsHad(): void
    {
        self::assertSame(100, PersonalizationLocalization::LABEL_MAX_LENGTH);
        self::assertSame(500, PersonalizationLocalization::INSTRUCTIONS_MAX_LENGTH);
        self::assertSame(100, PersonalizationLocalization::PLACEHOLDER_MAX_LENGTH);

        // And a word past its length is refused, not cut down.
        self::assertSame(
            [PersonalizationLocalization::LABEL => 'too_long'],
            PersonalizationLocalization::zones()->problems('nl', [
                PersonalizationLocalization::LABEL => str_repeat('a', 101),
            ])
        );
    }

    /** A view has a label and nothing else to say. */
    public function testAViewHasNoInstructions(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PersonalizationLocalization::views()->value(self::VIEW, PersonalizationLocalization::INSTRUCTIONS, 'nl');
    }

    /* ------------------------------------------------------------------ */
    /* The fallback                                                        */
    /* ------------------------------------------------------------------ */

    public function testAVisitorGetsTheAskedForLanguageThenTheDefaultThenNothing(): void
    {
        PersonalizationLocalization::zones()->overrideForTests(self::ZONE, [
            'nl' => [
                PersonalizationLocalization::LABEL => 'Naam',
                PersonalizationLocalization::PLACEHOLDER => 'Bijv. Bart',
            ],
            'en' => [PersonalizationLocalization::LABEL => 'Name'],
        ]);

        self::assertSame('Naam', PersonalizationLocalization::zoneWord(self::ZONE, PersonalizationLocalization::LABEL, 'nl'));
        self::assertSame('Name', PersonalizationLocalization::zoneWord(self::ZONE, PersonalizationLocalization::LABEL, 'en'));
        self::assertSame(
            'Bijv. Bart',
            PersonalizationLocalization::zoneWord(self::ZONE, PersonalizationLocalization::PLACEHOLDER, 'en'),
            'an untranslated placeholder falls back to the default language'
        );
        self::assertSame(
            '',
            PersonalizationLocalization::zoneWord(self::ZONE, PersonalizationLocalization::INSTRUCTIONS, 'en'),
            'nothing in either language is nothing'
        );
    }

    /** raw() is for an editor: what is stored in THIS language, no fallback. */
    public function testAnEditorSeesOnlyWhatIsStoredInTheLanguageOnScreen(): void
    {
        PersonalizationLocalization::settings()->overrideForTests(self::SETTINGS, [
            'nl' => [PersonalizationLocalization::INSTRUCTIONS => 'Personaliseer dit product.'],
        ]);

        self::assertSame(
            'Personaliseer dit product.',
            PersonalizationLocalization::rawInstructions(self::SETTINGS, 'nl')
        );
        self::assertSame('', PersonalizationLocalization::rawInstructions(self::SETTINGS, 'en'));
        self::assertSame(
            'Personaliseer dit product.',
            PersonalizationLocalization::instructions(self::SETTINGS, 'en'),
            'but a visitor still gets the fallback'
        );
    }

    /** What the CMS calls a view, so a card is never blank in the editor. */
    public function testTheCmsNamesAViewByItsDefaultLanguagesLabel(): void
    {
        PersonalizationLocalization::views()->overrideForTests(self::VIEW, [
            'en' => [PersonalizationLocalization::LABEL => 'Front'],
        ]);

        self::assertSame('', PersonalizationLocalization::viewLabel(self::VIEW, 'nl'), 'a visitor gets nothing');
        self::assertSame(
            'Front',
            PersonalizationLocalization::viewName(self::VIEW),
            'but an editor must still be able to find it'
        );
    }

    /* ------------------------------------------------------------------ */
    /* A third language                                                    */
    /* ------------------------------------------------------------------ */

    public function testAThirdLanguageNeedsNoSchemaOrCodeChange(): void
    {
        SiteLanguageFixture::useLanguages([
            SiteLanguageFixture::language('nl', isDefault: true),
            SiteLanguageFixture::language('en', sortOrder: 1),
            SiteLanguageFixture::language('de', sortOrder: 2),
        ]);
        PersonalizationLocalization::clearCache();

        PersonalizationLocalization::zones()->overrideForTests(self::ZONE, [
            'nl' => [PersonalizationLocalization::LABEL => 'Naam'],
            'de' => [PersonalizationLocalization::LABEL => 'Name'],
        ]);

        self::assertSame('Name', PersonalizationLocalization::zoneWord(self::ZONE, PersonalizationLocalization::LABEL, 'de'));
        self::assertSame(
            'Naam',
            PersonalizationLocalization::zoneWord(self::ZONE, PersonalizationLocalization::LABEL, 'en'),
            'and the one that has no German falls back like any other'
        );
    }
}

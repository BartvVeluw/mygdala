<?php

declare(strict_types=1);

namespace Tests\Install;

use App\Install\InstallState;
use App\Install\SetupState;
use App\Module\ModuleSettings;
use App\Service\PageContent;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\ScratchInstall;

/**
 * What finishing the Setup Wizard actually builds, and — just as important —
 * what an unfinished one leaves behind.
 *
 * Run against a database that has never held anything, in its own process
 * (Tests\Support\ScratchInstall), because that is the only place the
 * question can be asked: the ordinary test database is a copy of development
 * and already contains a configured site.
 *
 * The claim being defended: setup is atomic in the way that matters. It is
 * marked complete only after every change has succeeded, everything it
 * creates can be created twice without duplicating, and a rejected
 * submission changes nothing at all.
 */
#[Group('migration-backfill')]
final class SetupCompletionTest extends TestCase
{
    private const DATABASE = 'mygdala_scratch_setup';

    private const SITE_NAME = 'Atelier Testbedrijf';
    private const BASE_URL = 'https://www.testbedrijf.example';

    private static ?ScratchInstall $install = null;

    public static function setUpBeforeClass(): void
    {
        if (!ScratchInstall::available()) {
            return;
        }

        self::$install = ScratchInstall::fresh(self::DATABASE);
    }

    public static function tearDownAfterClass(): void
    {
        self::$install?->drop();
        self::$install = null;
    }

    private function install(): ScratchInstall
    {
        if (self::$install === null) {
            $this->markTestSkipped(
                'A from-zero install needs the MySQL root account (DB_ROOT_PASSWORD in .env), like scripts/test-db.php.'
            );
        }

        return self::$install;
    }

    /**
     * Runs the wizard in its own process against the scratch database.
     *
     * @param array<string, mixed> $input
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function runWizard(array $input): array
    {
        [$status, $output] = $this->install()->runScript(
            'tests/Support/complete-setup-cli.php',
            [json_encode($input, JSON_THROW_ON_ERROR)]
        );

        $decoded = json_decode(trim($output), true);

        $this->assertIsArray($decoded, 'the wizard runner printed something unreadable: ' . $output);

        return [$status, $decoded];
    }

    /** @return array<string, string> */
    private function settings(): array
    {
        $values = [];
        foreach ($this->install()->rows('SELECT setting_key, setting_value FROM site_settings') as $row) {
            $values[(string) $row['setting_key']] = (string) ($row['setting_value'] ?? '');
        }

        return $values;
    }

    private function setupMarker(): ?string
    {
        $rows = $this->install()->rows(
            'SELECT state_value FROM install_state WHERE state_key = ?',
            [SetupState::KEY_SETUP_COMPLETED_AT]
        );

        return $rows === [] ? null : (string) $rows[0]['state_value'];
    }

    /**
     * The full, ordinary submission. Deliberately not the minimum: every
     * step is exercised at once, because they are saved at once.
     *
     * @return array<string, mixed>
     */
    private function submission(): array
    {
        return [
            'site_name' => self::SITE_NAME,
            'email' => 'hallo@testbedrijf.example',
            'footer_description_nl' => 'Wij maken dingen.',
            'city_nl' => 'Utrecht',
            'kvk_number' => '12345678',
            'canonical_base_url' => self::BASE_URL,

            'primary_color' => '#2B6CB0',
            'font_pairing' => 'poppins-inter',
            'button_shape' => 'rounded',

            'modules' => ['shop' => '1'],

            'pages' => ['about' => '1', 'contact' => '1'],
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Before the wizard runs                                              */
    /* ------------------------------------------------------------------ */

    public function testAFromZeroInstallationStartsOutNeedingSetup(): void
    {
        $pdo = $this->install()->pdo();

        $this->assertSame(InstallState::KIND_FRESH, InstallState::kind($pdo));
        $this->assertTrue(SetupState::isSetupRequired($pdo));
        $this->assertNull(SetupState::completedAt($pdo));
    }

    /**
     * A rejected submission must change NOTHING — not the settings, and
     * above all not the completion marker. This runs before the successful
     * one on purpose (PHPUnit runs methods in source order), so it is asking
     * about a genuinely unfinished install.
     */
    public function testARejectedSubmissionDoesNotCompleteSetup(): void
    {
        $before = $this->settings();

        [$status, $result] = $this->runWizard(['site_name' => '', 'pages' => ['about' => '1']]);

        $this->assertSame(1, $status);
        $this->assertFalse($result['ok']);
        $this->assertNull($this->setupMarker(), 'a refused wizard must never mark setup complete');
        $this->assertSame($before, $this->settings(), 'a refused wizard must not write a single setting');
        $this->assertSame(0, (int) $this->install()->rows(
            "SELECT COUNT(*) AS c FROM pages WHERE slug = 'over-ons'"
        )[0]['c'], 'a refused wizard must not create a page');
    }

    /* ------------------------------------------------------------------ */
    /* Finishing it                                                        */
    /* ------------------------------------------------------------------ */

    public function testTheWizardFinishesAndSaysWhichPagesItCreated(): void
    {
        [$status, $result] = $this->runWizard($this->submission());

        $this->assertSame(0, $status, 'the wizard failed: ' . ($result['error'] ?? ''));
        $this->assertTrue($result['ok']);
        // Not '/contact': this codebase still ships contact.php at the
        // project root, so App\Service\ReservedRoutes reserves that word and
        // the wizard falls back to the alternate name it publishes on the
        // checkbox (SetupWizard::plannedSlug()).
        $this->assertSame(['over-ons', 'contact-opnemen'], $result['created']);
    }

    public function testSetupIsMarkedCompleteOnlyAfterEverythingSucceeded(): void
    {
        $marker = $this->setupMarker();

        $this->assertNotNull($marker);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $marker);
        $this->assertFalse(SetupState::isSetupRequired($this->install()->pdo()));
        $this->assertTrue(SetupState::isComplete($this->install()->pdo()));
    }

    public function testTheSiteIdentityIsStored(): void
    {
        $settings = $this->settings();

        $this->assertSame(self::SITE_NAME, $settings['site_name']);
        $this->assertSame('hallo@testbedrijf.example', $settings['email']);
        $this->assertSame('Wij maken dingen.', $settings['footer_description_nl']);
        $this->assertSame('Utrecht', $settings['city_nl']);
        $this->assertSame('12345678', $settings['kvk_number']);
        $this->assertSame(self::BASE_URL, $settings['canonical_base_url']);
    }

    public function testTheAppearanceIsStoredInTheThemeTableAndNowhereElse(): void
    {
        $theme = [];
        foreach ($this->install()->rows('SELECT setting_key, setting_value FROM theme_settings') as $row) {
            $theme[(string) $row['setting_key']] = (string) $row['setting_value'];
        }

        $this->assertSame('#2B6CB0', $theme['primary_color']);
        $this->assertSame('poppins-inter', $theme['font_pairing']);
        $this->assertSame('rounded', $theme['button_shape']);

        // Only what was actually chosen: an unchosen colour keeps meaning
        // "the shipped default" (THEMING.md).
        $this->assertArrayNotHasKey('surface_color', $theme);

        $this->assertArrayNotHasKey(
            'primary_color',
            $this->settings(),
            'appearance must never leak into site_settings'
        );
    }

    public function testTheModuleChoiceIsStoredAsAPreferenceRatherThanAnEnvironmentGuess(): void
    {
        $modules = [];
        foreach ($this->install()->rows('SELECT setting_key, setting_value FROM module_settings') as $row) {
            $modules[(string) $row['setting_key']] = (string) $row['setting_value'];
        }

        $this->assertSame('1', $modules[ModuleSettings::settingKey('shop')]);
        $this->assertSame(
            '0',
            $modules[ModuleSettings::settingKey('personalization')],
            'an unticked module is a decision, and is stored as one'
        );
    }

    /* ------------------------------------------------------------------ */
    /* The pages it created                                                */
    /* ------------------------------------------------------------------ */

    public function testTheSelectedStarterPagesAreOrdinaryDraftPages(): void
    {
        foreach (['over-ons', 'contact-opnemen'] as $slug) {
            $page = $this->page($slug);

            $this->assertNotNull($page, $slug . ' should have been created');
            $this->assertSame(PageContent::STATUS_DRAFT, $page['status'], $slug . ' must start as a draft');
            $this->assertSame(0, (int) $page['is_system'], $slug . ' must be an ordinary CMS page');
            $this->assertNull($page['route_path'], $slug . ' must not claim an application route');
        }
    }

    public function testAStarterPageCarriesTheBlocksOfItsTemplateAndNoSiteSpecificText(): void
    {
        $page = $this->page('over-ons');
        $this->assertNotNull($page);

        $blocks = array_column(
            $this->install()->rows(
                'SELECT section_type FROM page_sections WHERE page_id = ? ORDER BY sort_order',
                [(int) $page['id']]
            ),
            'section_type'
        );

        $this->assertSame(['page_hero', 'text_image_split', 'rich_text', 'cta_band'], $blocks);
    }

    public function testAnUnselectedStarterPageIsNotCreated(): void
    {
        foreach (['diensten', 'onze-diensten'] as $slug) {
            $this->assertNull($this->page($slug), 'nothing the owner did not ask for may appear');
        }
    }

    /**
     * A starter page never claims a word an application route already owns,
     * however the wizard arrives at its slug.
     */
    public function testNoStarterPageClaimsAReservedApplicationRoute(): void
    {
        $slugs = array_column(
            $this->install()->rows('SELECT slug FROM pages WHERE is_system = 0'),
            'slug'
        );

        $this->assertNotSame([], $slugs);

        foreach ($slugs as $slug) {
            $this->assertFalse(
                \App\Service\ReservedRoutes::isReserved((string) $slug),
                "the wizard created /{$slug}, which an application route already owns"
            );
        }
    }

    public function testTheHomepageIsUntouchedAndStillTheOnlySiteRoot(): void
    {
        $roots = $this->install()->rows("SELECT content_key FROM pages WHERE route_path = '/'");

        $this->assertSame([['content_key' => 'index']], $roots);
    }

    /* ------------------------------------------------------------------ */
    /* The menu                                                            */
    /* ------------------------------------------------------------------ */

    public function testEachCreatedPageGetsOneMenuItemAndNothingElseIsInvented(): void
    {
        // The label is in nav_item_translations since Multilingual 2.0 phase 4,
        // in the default language this wizard run chose (Dutch).
        $items = $this->install()->rows(
            "SELECT t.label, i.link_type, i.target_route, i.target_page_id
               FROM nav_items i
               LEFT JOIN nav_item_translations t ON t.nav_item_id = i.id AND t.language_code = 'nl'
              ORDER BY i.sort_order"
        );

        $labels = array_column($items, 'label');

        // No Shop item: the install bootstrap no longer seeds a storefront
        // page or its menu link (INSTALL-BOOTSTRAP.md), and this wizard adds
        // nothing beyond the pages it made.
        $this->assertSame(['Home', 'Over ons', 'Contact'], $labels);

        foreach ($items as $item) {
            if (in_array($item['label'], ['Over ons', 'Contact'], true)) {
                $this->assertSame('page', $item['link_type'], 'a starter page is linked as a PAGE, so it follows a rename');
                $this->assertNotNull($item['target_page_id']);
            }
        }
    }

    public function testNoFooterColumnIsInvented(): void
    {
        $this->assertSame(0, $this->install()->count('footer_columns'));
        $this->assertSame(0, $this->install()->count('footer_links'));
    }

    /* ------------------------------------------------------------------ */
    /* Running it again                                                    */
    /* ------------------------------------------------------------------ */

    /**
     * The wizard's own steps are idempotent, which is what makes finishing
     * an interrupted run safe. (The HTTP endpoint refuses a second run
     * outright — see Tests\Service\SetupAccessTest — so this is the belt
     * under that brace, not the way it is normally reached.)
     */
    public function testRunningItAgainCreatesNoSecondPageAndNoSecondMenuItem(): void
    {
        $pagesBefore = $this->install()->count('pages');
        $navBefore = $this->install()->count('nav_items');

        [$status] = $this->runWizard($this->submission());

        $this->assertSame(0, $status);
        $this->assertSame($pagesBefore, $this->install()->count('pages'));
        $this->assertSame($navBefore, $this->install()->count('nav_items'));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function page(string $slug): ?array
    {
        $rows = $this->install()->rows('SELECT * FROM pages WHERE slug = ?', [$slug]);

        return $rows === [] ? null : $rows[0];
    }
}

<?php

declare(strict_types=1);

namespace Tests\Install;

use App\Install\InstallState;
use App\Module\ModuleRegistry;
use App\Install\SetupState;
use App\Service\PageContent;
use App\Service\PageTemplates\PageTemplates;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\ScratchInstall;

/**
 * What a brand-new installation of this CMS gets.
 *
 * Every migration is run in order against a database that has never held
 * anything — not against the test database, which is a copy of development
 * and therefore already contains the very content this test is about. Only
 * an empty database can answer "what is seeded", and INSTALL-BOOTSTRAP.md is
 * the written version of the answer.
 *
 * The claim being defended: Core creates the Homepage, the Shop module
 * creates the storefront, and everything else is the editor's — made when
 * the site needs it, from a page template if they want the usual shape of
 * one. Diensten, Portfolio, Over mij, Contact and three Dutch legal pages
 * belong to Van Veluw Laserdesign, and a new installation is not that site.
 */
final class FreshInstallTest extends TestCase
{
    private const DATABASE = 'vanveluw_scratch_fresh';

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

    public function testEveryMigrationRunsAgainstAnEmptyDatabase(): void
    {
        // Reaching here at all means phinx exited 0 — ScratchInstall throws
        // with the migration output otherwise. The tables below are the ones
        // the rest of this file reads, so a silent partial run cannot pass.
        foreach (['pages', 'page_sections', 'nav_items', 'site_settings', 'install_state'] as $table) {
            $this->assertTrue($this->install()->hasTable($table), "Missing table after a from-zero migrate: {$table}");
        }
    }

    public function testTheInstallIsMarkedAsGenericRatherThanAsThisSitesHistory(): void
    {
        $this->assertSame(
            InstallState::KIND_FRESH,
            InstallState::kind($this->install()->pdo()),
            'A database built from zero must identify itself as a fresh generic install.'
        );
    }

    public function testTheHomepageExists(): void
    {
        $page = $this->page('index');

        $this->assertNotNull($page, 'A fresh install must have a homepage.');
        $this->assertSame('/', $page['route_path']);
        $this->assertSame('published', $page['status']);
        $this->assertSame(1, (int) $page['is_system']);
    }

    public function testTheHomepageIsProtected(): void
    {
        $page = $this->page('index');
        $this->assertNotNull($page);

        $this->assertTrue(
            PageContent::isSiteRoot($page),
            'Protection follows route_path = "/" — the site root must always render.'
        );
    }

    public function testTheHomepageCarriesItsHero(): void
    {
        $sections = $this->install()->rows(
            'SELECT section_type FROM page_sections WHERE page_slug = ? ORDER BY sort_order',
            ['index']
        );

        $this->assertSame([['section_type' => 'homepage_hero']], $sections);
    }

    /**
     * The point of the whole cleanup.
     */
    public function testNoneOfThisSitesContentPagesAreCreated(): void
    {
        foreach (['diensten', 'portfolio', 'over-mij', 'contact'] as $contentKey) {
            $this->assertNull(
                $this->page($contentKey),
                "A generic install must not receive the page '{$contentKey}'."
            );
        }
    }

    public function testNoLegalOrShippingPagesAreCreated(): void
    {
        // Which legal pages a business owes its customers depends on that
        // business. A CMS that seeds three Dutch ones is guessing.
        foreach (['verzenden-retourneren', 'algemene-voorwaarden', 'privacyverklaring'] as $contentKey) {
            $this->assertNull(
                $this->page($contentKey),
                "A generic install must not receive the page '{$contentKey}'."
            );
        }
    }

    public function testNoPageContentIsSeededAnywhere(): void
    {
        // Every table a content block stores its copy in. Empty here means
        // the historical backfills left nothing orphaned behind either: no
        // hero for a page that does not exist, no CTA band pointing at one.
        $blockTables = [
            'page_heroes', 'cta_bands', 'feature_grids', 'feature_grid_items',
            'faq_sections', 'faq_items', 'text_image_splits', 'text_image_split_paragraphs',
            'text_image_split_images', 'stat_strips', 'stat_strip_items',
            'step_list_sections', 'step_list_items', 'marquee_sections', 'marquee_items',
            'rich_text_sections', 'detail_sections', 'card_carousels', 'item_galleries',
            'contact_cards', 'portfolio_gallery_items', 'portfolio_categories',
        ];

        foreach ($blockTables as $table) {
            if (!$this->install()->hasTable($table)) {
                continue;
            }

            $this->assertSame(0, $this->install()->count($table), "Seeded content left in {$table}.");
        }
    }

    public function testNoFormIsCreated(): void
    {
        // The quote form on this site's Contact page became a Form
        // definition; a new install has no contact page and no reason for
        // one, notification address included.
        $this->assertSame(0, $this->install()->count('forms'));
        $this->assertSame(0, $this->install()->count('form_fields'));
    }

    public function testTheMenuOnlyLinksToPagesThatExist(): void
    {
        $routes = array_column(
            $this->install()->rows('SELECT target_route FROM nav_items ORDER BY sort_order'),
            'target_route'
        );

        $this->assertSame(['home', 'shop'], $routes);
    }

    public function testNoSettingPointsAtAPageThisInstallDoesNotHave(): void
    {
        // The header button used to be pinned to the Contact page, with the
        // raw path /contact.php as its fallback "if that page is somehow
        // missing" — which is now every new install. A button aimed at a 404
        // is worse than no button, and the code default is already no button.
        $offenders = [];

        foreach ($this->install()->rows('SELECT setting_key, setting_value FROM site_settings') as $row) {
            $value = (string) $row['setting_value'];

            foreach (['contact.php', 'diensten.php', 'portfolio.php', 'over-mij.php'] as $route) {
                if (str_contains($value, $route)) {
                    $offenders[] = $row['setting_key'] . ' = ' . $value;
                }
            }
        }

        $this->assertSame([], $offenders);
    }

    public function testTheFooterStartsEmpty(): void
    {
        $this->assertSame(0, $this->install()->count('footer_columns'));
        $this->assertSame(0, $this->install()->count('footer_links'));
    }

    // ------------------------------------------------------------- modules

    public function testTheShopModuleBringsItsOwnStorefront(): void
    {
        $page = $this->page('shop');

        $this->assertNotNull($page, 'The Shop module owns /shop.php and bootstraps it.');
        $this->assertSame('/shop.php', $page['route_path']);
    }

    public function testTheStorefrontCarriesTheBlockThatProtectsIt(): void
    {
        $sections = $this->install()->rows(
            'SELECT section_type FROM page_sections WHERE page_slug = ? ORDER BY sort_order',
            ['shop']
        );

        $this->assertSame([['section_type' => 'product_grid']], $sections);
    }

    public function testNothingOutsideTheShopModuleIsBootstrappedByIt(): void
    {
        // The Shop brings the storefront and nothing that looks like site
        // copy: no products, no collections, no orders.
        foreach (['products', 'collections', 'orders'] as $table) {
            $this->assertSame(0, $this->install()->count($table));
        }
    }

    // ---------------------------------------------------- what replaces it

    public function testThePageTemplateCatalogueIsAvailable(): void
    {
        $keys = PageTemplates::keys();

        foreach (['blank', 'standard', 'about', 'services', 'contact', 'landing'] as $key) {
            $this->assertContains($key, $keys);
        }
    }

    public function testAnEditorCanStillCreateTheOmittedPages(): void
    {
        $wanted = [
            'services' => ['diensten', 'Diensten'],
            'about' => ['over-ons', 'Over ons'],
            'contact' => ['contact', 'Contact'],
        ];

        foreach ($wanted as $template => [$slug, $title]) {
            [$status, $output] = $this->install()->runScript(
                'tests/Support/create-page-from-template.php',
                [$template, $slug, $title]
            );

            $this->assertSame(0, $status, "Creating a '{$template}' page failed:\n" . $output);

            $page = $this->page($slug);
            $this->assertNotNull($page, "The '{$template}' template created no page.");
            $this->assertSame('draft', $page['status'], 'A new page starts as a draft, template or not.');
            $this->assertNull($page['route_path'], 'A template makes an ordinary CMS page, never a fixed route.');
            $this->assertSame(0, (int) $page['is_system']);

            $this->assertNotSame(
                [],
                $this->install()->rows('SELECT id FROM page_sections WHERE page_slug = ?', [$slug]),
                "The '{$template}' template placed no blocks."
            );
        }
    }

    /* ------------------------------------------------------------------ */
    /* Settings: who the site is, and who it is not                        */
    /* ------------------------------------------------------------------ */

    /**
     * The other half of the cleanup. Pages were the visible part; the
     * business details were the part that survived it, and they are what the
     * Setup Wizard exists to ask about (SETUP.md).
     */
    public function testNotOneStoredSettingNamesThisCompany(): void
    {
        $offenders = [];

        foreach ($this->settings() as $key => $value) {
            foreach (['van veluw', 'laserdesign', 'vanveluwlaserdesign', 'nijmegen', '97749540', 'vld-f'] as $literal) {
                if (str_contains(strtolower($value), $literal)) {
                    $offenders[$key] = $value;
                }
            }
        }

        $this->assertSame([], $offenders, 'A generic install must not receive this company\'s details.');
    }

    public function testTheIdentitySettingsAreSimplyAbsentRatherThanFilledInWithSomebodyElse(): void
    {
        $settings = $this->settings();

        foreach ([
            'site_name', 'email', 'city_nl', 'city_en', 'kvk_number',
            'footer_description_nl', 'footer_description_en',
            'logo_path', 'favicon_path', 'og_image_path',
            'company_city', 'company_website', 'invoice_number_prefix',
            'canonical_base_url',
        ] as $key) {
            $this->assertArrayNotHasKey(
                $key,
                $settings,
                "A fresh install stores no '{$key}': a missing row means the generic code default."
            );
        }
    }

    /**
     * The Media Library adopts the files `site_settings` points at. With no
     * branding rows to point anywhere, a brand-new installation starts with
     * an empty library instead of this company's logo, favicon and workshop
     * photograph.
     */
    public function testNoBrandingMediaIsAdoptedIntoTheLibrary(): void
    {
        $this->assertSame(0, $this->install()->count('media'), 'A fresh Media Library must be empty.');
    }

    /* ------------------------------------------------------------------ */
    /* Setup state                                                         */
    /* ------------------------------------------------------------------ */

    public function testTheInstallationKnowsItHasNotBeenSetUpYet(): void
    {
        $pdo = $this->install()->pdo();

        $this->assertTrue(SetupState::isSetupRequired($pdo));
        $this->assertNull(SetupState::completedAt($pdo));
    }

    public function testTheSetupMarkerLivesInTheExistingInstallStateTableRatherThanASecondOne(): void
    {
        $this->assertTrue($this->install()->hasTable(InstallState::TABLE));
        $this->assertFalse($this->install()->hasTable('setup_state'));

        $keys = array_column(
            $this->install()->rows('SELECT state_key FROM ' . InstallState::TABLE),
            'state_key'
        );

        $this->assertSame([InstallState::KEY_INSTALL_KIND], $keys);
    }

    /**
     * Deployment configuration the wizard can write. Empty on a fresh
     * install, because "no row" is what keeps the environment variable in
     * front of it and the "enabled" default behind it (MODULES.md).
     */
    public function testTheModulePreferenceTableExistsAndIsEmpty(): void
    {
        $this->assertTrue($this->install()->hasTable('module_settings'));
        $this->assertSame(0, $this->install()->count('module_settings'));
    }

    /**
     * The Blog's tables are created like any other module's — schema is not
     * configuration — and every one of them starts EMPTY. No sample article,
     * no default category, no tag: a new site's blog is whatever its owner
     * writes, and there is nothing here to delete first (BLOG.md).
     */
    public function testTheBlogTablesExistAndAreCompletelyEmpty(): void
    {
        foreach ([
            'blog_posts',
            'blog_categories',
            'blog_tags',
            'blog_post_categories',
            'blog_post_tags',
            'blog_settings',
        ] as $table) {
            $this->assertTrue($this->install()->hasTable($table), "Missing after a from-zero migrate: {$table}");
            $this->assertSame(0, $this->install()->count($table), "{$table} must start empty");
        }
    }

    /**
     * And the module itself is OFF, without a row saying so: an installation
     * that has expressed no opinion gets App\Module\BlogModule's own default,
     * so a site that never wanted a blog never has a /blog URL to explain.
     */
    public function testTheBlogModuleStartsSwitchedOff(): void
    {
        $this->assertFalse(
            ModuleRegistry::definition('blog')?->enabledByDefault(),
            'the Blog is the module that waits to be asked for'
        );
        $this->assertSame(
            [],
            $this->install()->rows('SELECT * FROM module_settings WHERE setting_key = ?', ['module_blog_enabled']),
            'and it is off by having no preference at all, not by a stored one'
        );
    }

    /**
     * How the CMS itself looks. Empty for the same reason as the two tables
     * above: no row means the code default, and the code default is the
     * Default dashboard theme, which is this admin panel's ordinary
     * appearance. A new site therefore starts where every existing site is.
     */
    public function testTheDashboardStartsOnTheDefaultThemeWithNoRowToSaySo(): void
    {
        $this->assertTrue($this->install()->hasTable('admin_settings'));
        $this->assertSame(0, $this->install()->count('admin_settings'));
    }

    #[Group('migration-backfill')]
    public function testTheCleanupAddedNoMigrationThatDeletesPages(): void
    {
        // The safe way to make a fresh install generic is to skip a seed,
        // never to seed and then delete: a delete that is even slightly
        // wrong destroys live content on a real site. Guard the rule at the
        // source, where a future migration would break it.
        $files = glob(dirname(__DIR__, 2) . '/db/migrations/*.php') ?: [];
        $offenders = [];

        foreach ($files as $file) {
            $body = (string) file_get_contents($file);

            if (preg_match('/DELETE\s+FROM\s+`?pages`?/i', $body) === 1) {
                $offenders[] = basename($file);
            }
        }

        $this->assertSame([], $offenders, 'A migration deletes rows from `pages`.');
    }

    /**
     * @return array<string, string>
     */
    private function settings(): array
    {
        $values = [];
        foreach ($this->install()->rows('SELECT setting_key, setting_value FROM site_settings') as $row) {
            $values[(string) $row['setting_key']] = (string) ($row['setting_value'] ?? '');
        }

        return $values;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function page(string $contentKey): ?array
    {
        $rows = $this->install()->rows('SELECT * FROM pages WHERE content_key = ?', [$contentKey]);

        return $rows[0] ?? null;
    }
}

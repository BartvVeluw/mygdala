<?php

declare(strict_types=1);

namespace Tests\Install;

use App\Install\InstallState;
use App\Install\SetupState;
use App\Repository\OrderRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\ScratchInstall;

/**
 * The other half of the install story: an installation that already carries
 * this site's content must come through the cleanup with all of it.
 *
 * Simulated honestly rather than described. The scratch database runs the
 * first migration, has its install marker flipped to `legacy_existing_site`
 * — which is the state every database that predates the marker is in — and
 * then runs every remaining migration. So it takes exactly the code path the
 * real development and production databases take, and every historical
 * backfill still considers itself responsible for its own content.
 *
 * If a guard added by the fresh-install cleanup were to fire one migration
 * too eagerly, this is where it shows: as a page, a block or a menu entry
 * that a real site would have lost.
 */
#[Group('migration-backfill')]
final class LegacyUpgradeTest extends TestCase
{
    private const DATABASE = 'mygdala_scratch_legacy';

    private static ?ScratchInstall $install = null;

    public static function setUpBeforeClass(): void
    {
        if (!ScratchInstall::available()) {
            return;
        }

        self::$install = ScratchInstall::legacy(self::DATABASE);
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
                'Replaying an existing installation needs the MySQL root account (DB_ROOT_PASSWORD in .env).'
            );
        }

        return self::$install;
    }

    public function testItIsRecognisedAsAnExistingInstallation(): void
    {
        $this->assertSame(
            InstallState::KIND_LEGACY,
            InstallState::kind($this->install()->pdo()),
            'A database with history must never be treated as a disposable new install.'
        );
    }

    public function testEveryContentPageSurvives(): void
    {
        $keys = array_column(
            $this->install()->rows('SELECT content_key FROM pages ORDER BY sort_order, id'),
            'content_key'
        );

        $this->assertSame(
            [
                'index', 'shop', 'diensten', 'portfolio', 'over-mij', 'contact',
                'verzenden-retourneren', 'algemene-voorwaarden', 'privacyverklaring',
            ],
            $keys
        );
    }

    public function testTheFixedRoutesAreUnchanged(): void
    {
        $routes = [];
        foreach ($this->install()->rows('SELECT content_key, route_path FROM pages') as $row) {
            $routes[(string) $row['content_key']] = $row['route_path'];
        }

        $this->assertSame('/', $routes['index']);
        $this->assertSame('/shop.php', $routes['shop']);
        $this->assertSame('/diensten.php', $routes['diensten']);
        $this->assertSame('/portfolio.php', $routes['portfolio']);
        $this->assertSame('/over-mij.php', $routes['over-mij']);
        $this->assertSame('/contact.php', $routes['contact']);
        $this->assertNull($routes['privacyverklaring'], 'The legal pages are ordinary CMS pages, not fixed routes.');
    }

    public function testThePageIdsAreUnchanged(): void
    {
        // Navigation, the footer, the header button and every redirect store
        // a page id. A backfill that renumbered them would quietly repoint
        // half the site.
        $ids = [];
        foreach ($this->install()->rows('SELECT id, content_key FROM pages') as $row) {
            $ids[(string) $row['content_key']] = (int) $row['id'];
        }

        $this->assertSame(1, $ids['index']);
        $this->assertSame(2, $ids['shop']);
        $this->assertSame(3, $ids['diensten']);
        $this->assertSame(4, $ids['portfolio']);
        $this->assertSame(5, $ids['over-mij']);
        $this->assertSame(6, $ids['contact']);
    }

    public function testTheSeededSeoTextSurvives(): void
    {
        $rows = $this->install()->rows('SELECT meta_title FROM pages WHERE content_key = ?', ['diensten']);

        $this->assertNotSame([], $rows);
        $this->assertStringContainsString('Van Veluw Laserdesign', (string) $rows[0]['meta_title']);
    }

    public function testEveryPageKeepsItsBlocks(): void
    {
        $perPage = [];
        foreach ($this->install()->rows('SELECT page_slug, section_type FROM page_sections ORDER BY page_slug, sort_order') as $row) {
            $perPage[(string) $row['page_slug']][] = (string) $row['section_type'];
        }

        $this->assertSame(
            ['page_hero', 'quicknav', 'detail_section', 'detail_section', 'detail_section', 'detail_section', 'faq', 'cta_band'],
            $perPage['diensten'] ?? []
        );
        $this->assertSame(['page_hero', 'contact_form', 'contact_card'], $perPage['contact'] ?? []);
        $this->assertSame(['page_hero', 'item_gallery', 'cta_band'], $perPage['portfolio'] ?? []);
        $this->assertSame(
            ['page_hero', 'text_image_split', 'feature_grid', 'text_image_split'],
            $perPage['over-mij'] ?? []
        );
    }

    public function testTheLegalPagesKeepTheirText(): void
    {
        $rows = $this->install()->rows(
            'SELECT content_html FROM rich_text_sections WHERE page_slug = ? AND section_key = ?',
            ['algemene-voorwaarden', 'content']
        );

        $this->assertNotSame([], $rows, 'The terms page lost its rich-text block.');
        $this->assertStringContainsString('Identiteit van de ondernemer', (string) $rows[0]['content_html']);
    }

    public function testTheMenuAndFooterSurvive(): void
    {
        $labels = array_column(
            $this->install()->rows('SELECT label_nl FROM nav_items ORDER BY sort_order'),
            'label_nl'
        );

        $this->assertSame(['Home', 'Diensten', 'Portfolio', 'Shop', 'Over mij', 'Contact'], $labels);

        $this->assertSame(4, $this->install()->count('footer_columns'));
        $this->assertGreaterThan(10, $this->install()->count('footer_links'));
    }

    public function testTheContactFormSurvives(): void
    {
        $forms = $this->install()->rows('SELECT id, name FROM forms');

        $this->assertCount(1, $forms);
        $this->assertSame('Contactformulier', (string) $forms[0]['name']);
        $this->assertSame(5, $this->install()->count('form_fields'));
    }

    public function testStoredCtaBandsKeepTheirOwnDestinations(): void
    {
        // The new-block default changed; stored rows did not. This one was
        // written by a migration in 2026-09 and still points where it did.
        $rows = $this->install()->rows(
            'SELECT primary_url FROM cta_bands WHERE page_slug = ? AND section_key = ?',
            ['index', 'main']
        );

        $this->assertNotSame([], $rows);
        $this->assertSame('contact.php', (string) $rows[0]['primary_url']);
    }

    public function testTheHeaderButtonStillPointsAtTheContactPage(): void
    {
        $settings = [];
        foreach ($this->install()->rows('SELECT setting_key, setting_value FROM site_settings') as $row) {
            $settings[(string) $row['setting_key']] = (string) $row['setting_value'];
        }

        $this->assertSame('page', $settings['header_cta_link_type'] ?? '');
        $this->assertSame('Vraag offerte aan', $settings['header_cta_label_nl'] ?? '');
        $this->assertNotSame('', $settings['header_cta_target_page_id'] ?? '');
        $this->assertSame(
            'Ontworpen & gebouwd met zorg in Nijmegen',
            $settings['footer_slogan_nl'] ?? ''
        );
    }

    /* ------------------------------------------------------------------ */
    /* The Setup Wizard must never appear here                             */
    /* ------------------------------------------------------------------ */

    /**
     * The single most important regression in the whole Setup Wizard step.
     * An existing site opening its CMS must land on its dashboard, not in a
     * wizard offering to give it a new identity.
     */
    public function testAnExistingInstallationIsAlreadyConfiguredAndNeverEntersTheWizard(): void
    {
        $pdo = $this->install()->pdo();

        $this->assertFalse(
            SetupState::isSetupRequired($pdo),
            'A database with history must never be sent to the Setup Wizard.'
        );
        $this->assertTrue(SetupState::isComplete($pdo));
    }

    public function testMarkingSetupCompleteOnAnExistingInstallationWritesNothing(): void
    {
        $before = $this->install()->rows('SELECT * FROM install_state');

        SetupState::markComplete($this->install()->pdo());

        $this->assertSame(
            $before,
            $this->install()->rows('SELECT * FROM install_state'),
            'An existing installation carries no setup marker, and must not be given one.'
        );
    }

    /* ------------------------------------------------------------------ */
    /* Identity, branding and modules survive the generic defaults         */
    /* ------------------------------------------------------------------ */

    /**
     * The business details moved out of App\Service\SiteSettings' code
     * defaults and into real rows. On a site that already had them nothing
     * may have shifted by a character.
     */
    public function testEveryBusinessDetailIsStillStoredExactlyAsItWas(): void
    {
        $settings = $this->settings();

        $this->assertSame('Van Veluw Laserdesign', $settings['site_name'] ?? '');
        $this->assertSame('info@vanveluwlaserdesign.nl', $settings['email'] ?? '');
        $this->assertSame('Nijmegen, Nederland', $settings['city_nl'] ?? '');
        $this->assertSame('Nijmegen, the Netherlands', $settings['city_en'] ?? '');
        $this->assertSame('97749540', $settings['kvk_number'] ?? '');
        $this->assertSame('Nijmegen', $settings['company_city'] ?? '');
        $this->assertSame('www.vanveluwlaserdesign.nl', $settings['company_website'] ?? '');
        $this->assertSame('VLD-F', $settings['invoice_number_prefix'] ?? '');
        $this->assertStringContainsString('Nijmegen', $settings['footer_description_nl'] ?? '');
        $this->assertSame('VLD', $settings['order_number_prefix'] ?? '');
    }

    /**
     * The prefix this installation now stores makes exactly the string its
     * customers, its Mollie payments and its bookkeeping already have — the
     * one the formatter produced while "VLD-" was hardcoded. That is what
     * 20260913120000 stores on every existing order;
     * Tests\Install\OrderNumberSnapshotMigrationTest proves the stored values.
     */
    public function testHistoricalOrderNumbersComeOutExactlyAsTheyWereIssued(): void
    {
        $prefix = $this->settings()['order_number_prefix'] ?? '';

        $this->assertSame('VLD-2026-000127', OrderRepository::formatOrderNumber(127, new \DateTimeImmutable('2026-03-14'), $prefix));
        $this->assertSame('VLD-2027-1234567', OrderRepository::formatOrderNumber(1234567, new \DateTimeImmutable('2027-05-01'), $prefix));
    }

    public function testTheBrandingFilesAreStillPointedAtAndStillAdoptedIntoTheLibrary(): void
    {
        $settings = $this->settings();

        $this->assertSame('assets/images/vanveluwlaserdesignlogo.svg', $settings['logo_path'] ?? '');
        $this->assertSame('assets/images/favicon-v.png', $settings['favicon_path'] ?? '');
        $this->assertSame('assets/images/hero-collage-a.webp', $settings['og_image_path'] ?? '');

        foreach (['logo_media_id', 'favicon_media_id', 'og_image_media_id'] as $key) {
            $this->assertNotSame('', $settings[$key] ?? '', $key . ' lost its Media Library reference');
        }
    }

    /**
     * The canonical base URL used to be a code default. It is a row now, so
     * that a fresh install stops inheriting this domain — and this site's
     * canonical tags, og:url and sitemap must come out unchanged.
     */
    public function testTheCanonicalBaseUrlIsPinnedSoNoSeoOutputMoves(): void
    {
        $this->assertSame(
            'https://www.vanveluwlaserdesign.nl',
            $this->settings()['canonical_base_url'] ?? ''
        );
    }

    public function testNoModulePreferenceIsInventedForASiteThatNeverChoseOne(): void
    {
        $this->assertTrue($this->install()->hasTable('module_settings'));
        $this->assertSame(
            0,
            $this->install()->count('module_settings'),
            'An existing site keeps running whatever its environment says; nothing may pre-empt that.'
        );
    }

    public function testAnExistingCmsKeepsTheAdminItAlreadyHad(): void
    {
        // Dashboard themes arrived after this database did. The migration
        // that introduced them creates an empty table and nothing else, so
        // the CMS an owner signs into tomorrow looks exactly like the one
        // they signed into yesterday.
        $this->assertTrue($this->install()->hasTable('admin_settings'));
        $this->assertSame(
            0,
            $this->install()->count('admin_settings'),
            'An existing installation must stay on the Default dashboard theme until somebody chooses otherwise.'
        );
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

    public function testTheFreshInstallBootstrapChangedNothingHere(): void
    {
        // It creates a homepage and a one-item menu. On a database with
        // history both already exist, and the migration must have returned
        // without touching either of them.
        $this->assertSame(
            1,
            (int) $this->install()->rows('SELECT COUNT(*) AS c FROM pages WHERE route_path = ?', ['/'])[0]['c'],
            'A second homepage would mean the bootstrap ran on an existing installation.'
        );

        $heroes = $this->install()->rows('SELECT title_nl FROM homepage_hero WHERE page_slug = ?', ['index']);
        $this->assertCount(1, $heroes);
        $this->assertStringNotContainsString(
            'pas deze titel aan',
            (string) $heroes[0]['title_nl'],
            'The existing hero was overwritten with placeholder copy.'
        );
    }
}

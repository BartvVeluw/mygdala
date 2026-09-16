<?php

declare(strict_types=1);

namespace Tests\Install;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\ScratchInstall;

/**
 * The header button moving into the navigation:
 * db/migrations/20260916230000_move_the_header_button_into_the_navigation.php.
 *
 * WHAT IT HAS TO GET RIGHT. Until this migration the header had one
 * call-to-action button, stored as eight header_cta_* settings. The header
 * now renders buttons from nav_items, so without the copy an existing site
 * would lose its button the moment the code is deployed. Nobody should have
 * to re-enter it by hand.
 *
 * Two throwaway databases (Tests\Support\ScratchInstall): one built from zero,
 * and one standing where a site stood before this migration, with a menu of
 * two links and the button settings the 20260909220000 pin wrote on a real
 * site — here written by hand, because that pin skips a database built from
 * zero, and on the order and shape it wrote them in. Tests\Install\
 * LegacyUpgradeTest proves the same end state on a database whose settings
 * the pin really produced.
 *
 * What it proves: both databases get the same two columns with defaults that
 * make every existing row a menu link; the configured button becomes exactly
 * one visible button with its label, target and new-tab flag; the menu keeps
 * every item in its order; the old settings are left as they were; a second
 * run adds no second button; and a button that was switched off, or whose
 * page no longer exists, or whose route belongs to a module, is kept rather
 * than dropped.
 */
#[Group('migration-backfill')]
final class HeaderButtonMigrationTest extends TestCase
{
    private const FRESH = 'mygdala_scratch_header_button_fresh';
    private const DEPLOYED = 'mygdala_scratch_header_button_deployed';

    /** The migration before this one. */
    private const BEFORE = '20260916140000';

    private const MOVE = '20260916230000';

    private static ?ScratchInstall $fresh = null;
    private static ?ScratchInstall $deployed = null;

    private static int $contactPageId = 0;

    /** @var array<string, string> */
    private static array $settings = [];

    public static function setUpBeforeClass(): void
    {
        if (!ScratchInstall::available()) {
            return;
        }

        self::$fresh = ScratchInstall::fresh(self::FRESH);

        self::$deployed = ScratchInstall::upTo(self::DEPLOYED, self::BEFORE);
        $pdo = self::$deployed->pdo();

        $pdo->prepare(
            'INSERT INTO pages (content_key, slug, title, status, is_system, sort_order, created_at, updated_at)
             VALUES (?, ?, ?, ?, 0, 900, NOW(), NOW())'
        )->execute(['zz-header-button-contact', 'zz-header-button-contact', 'Contact', 'published']);
        self::$contactPageId = (int) $pdo->lastInsertId();

        // A second menu link after whatever the bootstrap seeded, so the order
        // of more than one item is on record.
        $pdo->exec(
            "INSERT INTO nav_items (label_nl, label_en, link_type, target_route, open_in_new_tab, parent_id, sort_order, is_visible, created_at, updated_at)
             VALUES ('Winkel', 'Shop', 'route', 'shop', 0, NULL, 7, 1, NOW(), NOW())"
        );

        self::$settings = [
            'header_cta_enabled' => '1',
            'header_cta_label_nl' => 'Vraag offerte aan',
            'header_cta_label_en' => 'Request a quote',
            'header_cta_link_type' => 'page',
            'header_cta_target_page_id' => (string) self::$contactPageId,
            'header_cta_target_route' => '',
            'header_cta_external_url' => '',
            'header_cta_open_in_new_tab' => '1',
        ];
        self::writeSettings(self::$settings);

        self::$deployed->catchUp();
    }

    protected function setUp(): void
    {
        if (!ScratchInstall::available()) {
            $this->markTestSkipped('no root database credentials for a throwaway installation');
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::$fresh?->drop();
        self::$deployed?->drop();
        self::$fresh = null;
        self::$deployed = null;
    }

    public function testBothDatabasesEndWithTheSameTwoColumns(): void
    {
        foreach ([self::FRESH => self::$fresh, self::DEPLOYED => self::$deployed] as $name => $install) {
            $this->assertNotNull($install);

            foreach (['presentation' => 'link', 'button_variant' => 'primary'] as $column => $default) {
                $definition = $install->pdo()->query("SHOW COLUMNS FROM nav_items LIKE '{$column}'")->fetch();

                $this->assertIsArray($definition, "{$name} has {$column}");
                $this->assertSame('NO', $definition['Null'], "{$name}: {$column} is never NULL");
                $this->assertSame($default, $definition['Default'], "{$name}: every existing row gets {$default}");
            }
        }
    }

    public function testAFreshInstallGetsNoButton(): void
    {
        $this->assertNotNull(self::$fresh);

        $this->assertSame([], $this->buttons(self::$fresh));
    }

    public function testTheConfiguredButtonBecomesOneVisibleButton(): void
    {
        $buttons = $this->buttons(self::$deployed);

        $this->assertCount(1, $buttons);
        $this->assertSame(
            [
                'label_nl' => 'Vraag offerte aan',
                'label_en' => 'Request a quote',
                'link_type' => 'page',
                'target_page_id' => self::$contactPageId,
                'target_route' => null,
                'external_url' => null,
                'open_in_new_tab' => 1,
                'button_variant' => 'primary',
                'parent_id' => null,
                'sort_order' => 0,
                'is_visible' => 1,
            ],
            $buttons[0]
        );
    }

    public function testTheMenuKeepsEveryItemInItsOrder(): void
    {
        $this->assertNotNull(self::$deployed);

        $menu = array_map(
            static fn (array $row): array => [(string) $row['label_nl'], (int) $row['sort_order']],
            self::$deployed->rows("SELECT label_nl, sort_order FROM nav_items WHERE presentation = 'link' ORDER BY sort_order, id")
        );

        $this->assertContains(['Winkel', 7], $menu, 'an existing link keeps its label and its place');
        $this->assertSame(
            self::$deployed->count('nav_items') - 1,
            count($menu),
            'every row but the copied button is still a menu link'
        );
    }

    public function testTheOldSettingsAreLeftExactlyAsTheyWere(): void
    {
        $this->assertSame(self::$settings, $this->storedSettings());
    }

    public function testRunningItAgainAddsNoSecondButton(): void
    {
        $this->assertNotNull(self::$deployed);

        self::$deployed->replay(self::MOVE);

        $this->assertCount(1, $this->buttons(self::$deployed));
    }

    /**
     * A switched-off button is copied hidden, not dropped: what an editor
     * typed is kept. Its page is gone, so the target becomes NULL — which the
     * foreign key requires and which renders nothing, as before.
     */
    public function testASwitchedOffButtonWithAMissingPageIsKeptHidden(): void
    {
        $this->assertNotNull(self::$deployed);

        self::$deployed->pdo()->exec("DELETE FROM nav_items WHERE presentation = 'button'");
        self::writeSettings(['header_cta_enabled' => '0', 'header_cta_target_page_id' => '999999']);

        self::$deployed->replay(self::MOVE);

        $buttons = $this->buttons(self::$deployed);
        $this->assertCount(1, $buttons);
        $this->assertSame(0, $buttons[0]['is_visible']);
        $this->assertSame('page', $buttons[0]['link_type']);
        $this->assertNull($buttons[0]['target_page_id']);
        $this->assertSame('Vraag offerte aan', $buttons[0]['label_nl']);
    }

    /**
     * A route is copied as stored, even one only a module registers: whether
     * it resolves is decided per render, so switching the module back on
     * brings the button back.
     */
    public function testARouteTargetIsCopiedAsStored(): void
    {
        $this->assertNotNull(self::$deployed);

        self::$deployed->pdo()->exec("DELETE FROM nav_items WHERE presentation = 'button'");
        self::writeSettings([
            'header_cta_enabled' => '1',
            'header_cta_link_type' => 'route',
            'header_cta_target_route' => 'shop',
            'header_cta_open_in_new_tab' => '0',
        ]);

        self::$deployed->replay(self::MOVE);

        $buttons = $this->buttons(self::$deployed);
        $this->assertCount(1, $buttons);
        $this->assertSame(
            ['route', null, 'shop', null, 0, 1],
            [$buttons[0]['link_type'], $buttons[0]['target_page_id'], $buttons[0]['target_route'], $buttons[0]['external_url'], $buttons[0]['open_in_new_tab'], $buttons[0]['is_visible']],
            'only the companion field of the stored kind is copied'
        );
    }

    /** @param array<string, string> $settings */
    private static function writeSettings(array $settings): void
    {
        $statement = self::$deployed->pdo()->prepare(
            'INSERT INTO site_settings (setting_key, setting_value, created_at, updated_at) VALUES (?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );

        foreach ($settings as $key => $value) {
            $statement->execute([$key, $value]);
        }
    }

    /** @return array<string, string> */
    private function storedSettings(): array
    {
        $this->assertNotNull(self::$deployed);

        $settings = [];
        foreach (self::$deployed->rows("SELECT setting_key, setting_value FROM site_settings WHERE setting_key LIKE 'header\\_cta\\_%'") as $row) {
            $settings[(string) $row['setting_key']] = (string) $row['setting_value'];
        }

        // In the order they were written, so assertSame() compares values.
        $ordered = [];
        foreach (array_keys(self::$settings) as $key) {
            if (array_key_exists($key, $settings)) {
                $ordered[$key] = $settings[$key];
            }
        }

        return $ordered + $settings;
    }

    /** @return list<array<string, mixed>> */
    private function buttons(?ScratchInstall $install): array
    {
        $this->assertNotNull($install);

        return array_map(
            static fn (array $row): array => [
                'label_nl' => (string) $row['label_nl'],
                'label_en' => (string) $row['label_en'],
                'link_type' => (string) $row['link_type'],
                'target_page_id' => $row['target_page_id'] === null ? null : (int) $row['target_page_id'],
                'target_route' => $row['target_route'],
                'external_url' => $row['external_url'],
                'open_in_new_tab' => (int) $row['open_in_new_tab'],
                'button_variant' => (string) $row['button_variant'],
                'parent_id' => $row['parent_id'],
                'sort_order' => (int) $row['sort_order'],
                'is_visible' => (int) $row['is_visible'],
            ],
            $install->rows("SELECT * FROM nav_items WHERE presentation = 'button' ORDER BY id")
        );
    }
}

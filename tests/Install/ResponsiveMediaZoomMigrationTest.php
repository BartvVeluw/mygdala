<?php

declare(strict_types=1);

namespace Tests\Install;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\ScratchInstall;

/**
 * db/migrations/20261002100000_give_block_images_a_zoom.php (Responsive
 * Media 3.0): a zoom next to the focus point of every picture a block crops,
 * on a database built from zero and on one upgraded from the migration
 * before it:
 *
 *   - a fresh install and an upgrade end on the same schema;
 *   - <prefix>zoom is TINYINT UNSIGNED NOT NULL DEFAULT 100 and
 *     <prefix>mobile_zoom TINYINT UNSIGNED NULL, in all eight places, right
 *     after the phone's point;
 *   - every existing row gets 100 and NULL — nothing on a page changes — and
 *     keeps every other value, its point and its phone point included;
 *   - running it again changes nothing, a zoom stored in between included.
 */
#[Group('migration-backfill')]
final class ResponsiveMediaZoomMigrationTest extends TestCase
{
    private const FRESH = 'mygdala_scratch_rm_zoom_fresh';
    private const UPGRADED = 'mygdala_scratch_rm_zoom_upgraded';

    /** The last migration before it (Page Themes 1.0). */
    private const BEFORE = '20261001100000';

    private const ZOOM = '20261002100000';

    /** table => prefix */
    private const SLOTS = [
        'carousel_cards' => 'image_',
        'text_image_split_items' => 'image_',
        'page_heroes' => 'image_',
        'cta_bands' => 'background_',
        'media_banners' => 'image_',
        'hover_card_grid_items' => 'image_',
        'homepage_hero' => 'image_',
        'detail_section_images' => 'image_',
    ];

    private static ?ScratchInstall $fresh = null;
    private static ?ScratchInstall $upgraded = null;

    /** @var array<string, list<array<string, mixed>>> */
    private static array $before = [];

    /** @var array<string, list<array<string, mixed>>> */
    private static array $after = [];

    /** @var array<string, list<array<string, mixed>>> */
    private static array $beforeReplay = [];

    /** @var array<string, list<array<string, mixed>>> */
    private static array $afterReplay = [];

    public static function setUpBeforeClass(): void
    {
        if (!ScratchInstall::available()) {
            return;
        }

        self::$fresh = ScratchInstall::upTo(self::FRESH, self::ZOOM);

        self::$upgraded = ScratchInstall::upTo(self::UPGRADED, self::BEFORE);
        $pdo = self::$upgraded->pdo();

        $pdo->exec("INSERT INTO media (path, mime_type, alt_text, created_at, updated_at) VALUES ('assets/media/zz-zoom-photo.jpg', 'image/jpeg', 'Foto', NOW(), NOW())");
        $picture = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO media (path, mime_type, alt_text, created_at, updated_at) VALUES ('assets/media/zz-zoom-phone.jpg', 'image/jpeg', 'Telefoon', NOW(), NOW())");
        $phone = (int) $pdo->lastInsertId();

        // One row per place with a point of its own, a phone picture and a
        // phone point where the place has them, and one in the middle.
        $pdo->exec("INSERT INTO card_carousels (page_slug, section_key, is_active, created_at, updated_at) VALUES ('zz-zoom', 'custom-zoom', 1, NOW(), NOW())");
        $carousel = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO text_image_splits (page_slug, section_key, is_active, created_at, updated_at) VALUES ('zz-zoom', 'custom-zoom', 1, NOW(), NOW())");
        $split = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO hover_card_grids (page_slug, section_key, created_at, updated_at) VALUES ('zz-zoom', 'custom-zoom', NOW(), NOW())");
        $grid = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO detail_sections (page_slug, section_key, is_active, created_at, updated_at) VALUES ('zz-zoom', 'custom-zoom', 1, NOW(), NOW())");
        $detail = (int) $pdo->lastInsertId();

        foreach ([[37, 64, $phone, 10, 90], [50, 50, null, null, null]] as $order => [$x, $y, $mobile, $mx, $my]) {
            $pdo->prepare('INSERT INTO carousel_cards (carousel_id, media_id, image_focus_x, image_focus_y, image_mobile_media_id, image_mobile_focus_x, image_mobile_focus_y, image_fit, sort_order, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())')
                ->execute([$carousel, $picture, $x, $y, $mobile, $mx, $my, $order === 0 ? 'contain' : 'cover', $order]);
            $pdo->prepare('INSERT INTO text_image_split_items (text_image_split_id, media_id, image_focus_x, image_focus_y, image_mobile_media_id, image_mobile_focus_x, image_mobile_focus_y, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())')
                ->execute([$split, $picture, $x, $y, $mobile, $mx, $my, $order]);
            $pdo->prepare("INSERT INTO page_heroes (page_slug, media_id, image_mode, image_focus_x, image_focus_y, image_mobile_media_id, image_mobile_focus_x, image_mobile_focus_y, is_active, created_at, updated_at) VALUES (?, ?, 'background', ?, ?, ?, ?, ?, 1, NOW(), NOW())")
                ->execute(['zz-zoom-' . $order, $picture, $x, $y, $mobile, $mx, $my]);
            $pdo->prepare('INSERT INTO cta_bands (page_slug, section_key, background_media_id, background_focus_x, background_focus_y, background_mobile_media_id, background_mobile_focus_x, background_mobile_focus_y, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())')
                ->execute(['zz-zoom', 'custom-' . $order, $picture, $x, $y, $mobile, $mx, $my]);
            $pdo->prepare('INSERT INTO media_banners (page_slug, section_key, media_id, image_focus_x, image_focus_y, image_mobile_media_id, image_mobile_focus_x, image_mobile_focus_y, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())')
                ->execute(['zz-zoom', 'custom-' . $order, $picture, $x, $y, $mobile, $mx, $my]);
            $pdo->prepare('INSERT INTO hover_card_grid_items (hover_card_grid_id, media_id, image_focus_x, image_focus_y, image_mobile_media_id, image_mobile_focus_x, image_mobile_focus_y, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$grid, $picture, $x, $y, $mobile, $mx, $my, $order]);
            $pdo->prepare('INSERT INTO detail_section_images (section_id, media_id, image_focus_x, image_focus_y, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())')
                ->execute([$detail, $picture, $x, $y, $order]);
        }
        $pdo->prepare('UPDATE homepage_hero SET media_id = ?, image_focus_x = 20, image_focus_y = 80, image_mobile_focus_x = 5, image_mobile_focus_y = 95')->execute([$picture]);

        self::$before = self::contentOf(self::$upgraded);
        self::$upgraded->catchUp(self::ZOOM);
        self::$after = self::contentOf(self::$upgraded);

        // A zoom stored in between, then the migration once more.
        $pdo->exec('UPDATE carousel_cards SET image_zoom = 150, image_mobile_zoom = 200 WHERE sort_order = 0');
        $pdo->exec('UPDATE cta_bands SET background_zoom = 175');
        self::$beforeReplay = self::contentOf(self::$upgraded);
        self::$upgraded->replay(self::ZOOM, self::ZOOM);
        self::$afterReplay = self::contentOf(self::$upgraded);
    }

    protected function setUp(): void
    {
        if (!ScratchInstall::available()) {
            $this->markTestSkipped('A from-zero install needs the MySQL root account (DB_ROOT_PASSWORD in .env).');
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::$fresh?->drop();
        self::$upgraded?->drop();
        self::$fresh = null;
        self::$upgraded = null;
    }

    public function testAFreshInstallAndAnUpgradeEndOnTheSameSchema(): void
    {
        foreach (array_keys(self::SLOTS) as $table) {
            self::assertNotSame([], self::shape(self::$fresh, $table), $table);
            self::assertSame(self::shape(self::$fresh, $table), self::shape(self::$upgraded, $table), $table);
        }
    }

    public function testBothColumnsHaveTheirContractInEveryPlaceAfterThePhonesPoint(): void
    {
        foreach (self::SLOTS as $table => $prefix) {
            $columns = [];
            $order = [];
            foreach (self::shape(self::$fresh, $table) as $column) {
                $columns[$column['name']] = [preg_replace('/^tinyint\(\d+\)/', 'tinyint', $column['type']), $column['nullable'], $column['default']];
                $order[] = $column['name'];
            }

            self::assertSame(['tinyint unsigned', 'NO', '100'], $columns[$prefix . 'zoom'] ?? null, $table . '.' . $prefix . 'zoom');
            self::assertSame(['tinyint unsigned', 'YES', null], $columns[$prefix . 'mobile_zoom'] ?? null, $table . '.' . $prefix . 'mobile_zoom');

            $at = array_search($prefix . 'mobile_focus_y', $order, true);
            self::assertSame([$prefix . 'zoom', $prefix . 'mobile_zoom'], array_slice($order, (int) $at + 1, 2), $table);
        }
    }

    public function testEveryExistingRowShowsWhatItShowedAndKeepsEverythingElse(): void
    {
        foreach (self::SLOTS as $table => $prefix) {
            self::assertNotSame([], self::$after[$table], $table);
            self::assertCount(count(self::$before[$table]), self::$after[$table], $table);

            foreach (self::$before[$table] as $index => $row) {
                $after = self::$after[$table][$index];
                self::assertSame(100, (int) $after[$prefix . 'zoom'], $table . ': no zoom');
                self::assertNull($after[$prefix . 'mobile_zoom'], $table . ': no phone zoom');

                foreach ($row as $column => $value) {
                    self::assertSame($value, $after[$column], $table . '.' . $column);
                }
            }
        }
    }

    public function testRunningItAgainChangesNothing(): void
    {
        self::assertSame(self::$beforeReplay, self::$afterReplay);
        self::assertSame([150, 200], [(int) self::$afterReplay['carousel_cards'][0]['image_zoom'], (int) self::$afterReplay['carousel_cards'][0]['image_mobile_zoom']]);
        self::assertSame(175, (int) self::$afterReplay['cta_bands'][0]['background_zoom']);
    }

    /** @return array<string, list<array<string, mixed>>> */
    private static function contentOf(ScratchInstall $install): array
    {
        $content = [];
        foreach (array_keys(self::SLOTS) as $table) {
            $content[$table] = $install->rows('SELECT * FROM `' . $table . '` ORDER BY id');
        }

        return $content;
    }

    /** @return list<array{name: string, type: string, nullable: string, default: string|null}> */
    private static function shape(?ScratchInstall $install, string $table): array
    {
        self::assertNotNull($install);

        return $install->rows(
            'SELECT column_name AS name, column_type AS type, is_nullable AS nullable, column_default AS `default`
               FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = ?
              ORDER BY ordinal_position',
            [$table]
        );
    }
}

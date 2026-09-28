<?php

declare(strict_types=1);

namespace Tests\Install;

use App\Service\Media\ImageFocus;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\ScratchInstall;

/**
 * The two migrations of Responsive Media 2.0, on a database built from zero
 * and on one upgraded from the migration before them:
 *
 *   20260928220000  a free focus point (<prefix>focus_x / _y, 0-100) on the
 *                   seven places a block crops a picture; the nine keys of
 *                   the five places that had one copied into it, the key
 *                   column dropped;
 *   20260928230000  a phone's own picture (RESTRICT) and point on all seven,
 *                   fit / phone fit where the frame is the block's own,
 *                   a phone height where the block has a height, and
 *                   card_carousels.flat_image_ratio.
 *
 *   - a fresh install and an upgrade end on the same schema, without a key
 *     column left;
 *   - every key becomes exactly the point it always showed (ImageFocus gave
 *     the same numbers), anything else the middle;
 *   - everything else of an existing row stays as it was, and no existing row
 *     gets a phone setting: NULL, cover, 'auto';
 *   - a phone's picture is a RESTRICT key into the library in every table;
 *   - running both again changes nothing, rows stored in between included.
 */
#[Group('migration-backfill')]
final class ResponsiveMediaMigrationTest extends TestCase
{
    private const FRESH = 'mygdala_scratch_responsive_media_fresh';
    private const UPGRADED = 'mygdala_scratch_responsive_media_upgraded';

    /** The last migration before these two (the Portfolio homepage flag). */
    private const BEFORE = '20260928210000';

    private const FOCUS = '20260928220000';

    private const MOBILE = '20260928230000';

    /** table => [the key column it had (or null), the prefix of its columns, fit, phone height] */
    private const SLOTS = [
        'carousel_cards' => ['image_focus', 'image_', true, false],
        'text_image_split_items' => ['image_focus', 'image_', true, true],
        'page_heroes' => ['image_focus', 'image_', true, true],
        'cta_bands' => ['background_focus', 'background_', false, false],
        'media_banners' => ['image_focus', 'image_', true, true],
        'hover_card_grid_items' => [null, 'image_', true, false],
        'homepage_hero' => [null, 'image_', false, false],
    ];

    /** Every key the editor ever stored, with the point it always showed; anything else was the middle. */
    private const POINTS = [
        'top-left' => [0, 0], 'top' => [50, 0], 'top-right' => [100, 0],
        'left' => [0, 50], 'center' => [50, 50], 'right' => [100, 50],
        'bottom-left' => [0, 100], 'bottom' => [50, 100], 'bottom-right' => [100, 100],
        'nowhere' => [50, 50],
    ];

    private static ?ScratchInstall $fresh = null;
    private static ?ScratchInstall $upgraded = null;

    /** @var array<string, list<array<string, mixed>>> table => rows before the upgrade */
    private static array $before = [];

    /** @var array<string, list<array<string, mixed>>> table => the same rows after it */
    private static array $after = [];

    /** @var array<string, list<array<string, mixed>>> */
    private static array $beforeReplay = [];

    /** @var array<string, list<array<string, mixed>>> */
    private static array $afterReplay = [];

    private static int $phonePicture = 0;

    public static function setUpBeforeClass(): void
    {
        if (!ScratchInstall::available()) {
            return;
        }

        self::$fresh = ScratchInstall::upTo(self::FRESH, self::MOBILE);

        self::$upgraded = ScratchInstall::upTo(self::UPGRADED, self::BEFORE);
        $pdo = self::$upgraded->pdo();

        $pdo->exec("INSERT INTO media (path, mime_type, alt_text, created_at, updated_at) VALUES ('assets/media/zz-rm-photo.jpg', 'image/jpeg', 'Foto', NOW(), NOW())");
        $picture = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO media (path, mime_type, alt_text, created_at, updated_at) VALUES ('assets/media/zz-rm-phone.jpg', 'image/jpeg', 'Telefoon', NOW(), NOW())");
        self::$phonePicture = (int) $pdo->lastInsertId();

        // One row per key in each of the five places that had one.
        $pdo->exec("INSERT INTO card_carousels (page_slug, section_key, is_active, created_at, updated_at) VALUES ('zz-rm', 'custom-rm', 1, NOW(), NOW())");
        $carouselId = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO text_image_splits (page_slug, section_key, is_active, created_at, updated_at) VALUES ('zz-rm', 'custom-rm', 1, NOW(), NOW())");
        $splitId = (int) $pdo->lastInsertId();

        $order = 0;
        foreach (array_keys(self::POINTS) as $key) {
            $order++;
            $pdo->prepare("INSERT INTO carousel_cards (carousel_id, media_id, image_focus, sort_order, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, 1, NOW(), NOW())")
                ->execute([$carouselId, $picture, $key, $order]);
            $pdo->prepare("INSERT INTO text_image_split_items (text_image_split_id, media_id, image_focus, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())")
                ->execute([$splitId, $picture, $key, $order]);
            $pdo->prepare("INSERT INTO page_heroes (page_slug, media_id, image_mode, image_focus, is_active, created_at, updated_at) VALUES (?, ?, 'background', ?, 1, NOW(), NOW())")
                ->execute(['zz-rm-' . $key, $picture, $key]);
            $pdo->prepare("INSERT INTO cta_bands (page_slug, section_key, background_media_id, background_focus, is_active, created_at, updated_at) VALUES ('zz-rm', ?, ?, ?, 1, NOW(), NOW())")
                ->execute(['custom-' . $key, $picture, $key]);
            $pdo->prepare("INSERT INTO media_banners (page_slug, section_key, media_id, image_focus, created_at, updated_at) VALUES ('zz-rm', ?, ?, ?, NOW(), NOW())")
                ->execute(['custom-' . $key, $picture, $key]);
        }

        // The two places that get their first point.
        $pdo->exec("INSERT INTO hover_card_grids (page_slug, section_key, created_at, updated_at) VALUES ('zz-rm', 'custom-rm', NOW(), NOW())");
        $gridId = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO hover_card_grid_items (hover_card_grid_id, media_id, sort_order) VALUES ({$gridId}, {$picture}, 0)");
        $pdo->exec("UPDATE homepage_hero SET media_id = {$picture}");

        self::$before = self::contentOf(self::$upgraded);

        self::$upgraded->catchUp(self::MOBILE);

        self::$after = self::contentOf(self::$upgraded);

        // Rows written in between, then both migrations once more.
        $pdo->exec("UPDATE carousel_cards SET image_focus_x = 37, image_focus_y = 64, image_mobile_media_id = " . self::$phonePicture . ", image_mobile_focus_x = 10, image_mobile_focus_y = 90, image_fit = 'contain', image_mobile_fit = 'cover' WHERE sort_order = 1");
        $pdo->exec("UPDATE page_heroes SET image_mobile_height = 'large' WHERE page_slug = 'zz-rm-top'");
        $pdo->exec("UPDATE card_carousels SET flat_image_ratio = '4-3' WHERE id = {$carouselId}");

        self::$beforeReplay = self::contentOf(self::$upgraded);
        self::$upgraded->replay(self::FOCUS, self::MOBILE);
        self::$upgraded->replay(self::MOBILE, self::MOBILE);
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

    public function testAFreshInstallAndAnUpgradeEndOnTheSameSchemaWithoutAKeyColumn(): void
    {
        foreach ([...array_keys(self::SLOTS), 'card_carousels'] as $table) {
            self::assertNotSame([], self::shape(self::$fresh, $table), $table);
            self::assertSame(self::shape(self::$fresh, $table), self::shape(self::$upgraded, $table), $table);
        }

        foreach (self::SLOTS as $table => [$keyColumn]) {
            if ($keyColumn !== null) {
                self::assertArrayNotHasKey($keyColumn, self::$after[$table][0], $table . ' keeps one truth per picture');
                self::assertArrayHasKey($keyColumn, self::$before[$table][0], $table . ' had the key before');
            }
        }
    }

    public function testEveryColumnHasItsContract(): void
    {
        foreach (self::SLOTS as $table => [, $prefix, $fit, $mobileHeight]) {
            $columns = [];
            foreach (self::shape(self::$fresh, $table) as $column) {
                $columns[$column['name']] = [$column['type'], $column['nullable'], $column['default']];
            }

            $expected = [
                $prefix . 'focus_x' => ['tinyint(3) unsigned', 'NO', '50'],
                $prefix . 'focus_y' => ['tinyint(3) unsigned', 'NO', '50'],
                $prefix . 'mobile_media_id' => ['int(10) unsigned', 'YES', null],
                $prefix . 'mobile_focus_x' => ['tinyint(3) unsigned', 'YES', null],
                $prefix . 'mobile_focus_y' => ['tinyint(3) unsigned', 'YES', null],
            ];
            if ($fit) {
                $expected[$prefix . 'fit'] = ['varchar(10)', 'NO', 'cover'];
                $expected[$prefix . 'mobile_fit'] = ['varchar(10)', 'YES', null];
            }
            if ($mobileHeight) {
                $expected[$prefix . 'mobile_height'] = ['varchar(10)', 'YES', null];
            }

            foreach ($expected as $name => $contract) {
                self::assertArrayHasKey($name, $columns, $table);
                // MySQL 8 prints no display width; MariaDB and 5.7 do.
                $columns[$name][0] = preg_replace('/^(tinyint|int)\(\d+\)/', '$1', $columns[$name][0]);
                $contract[0] = preg_replace('/^(tinyint|int)\(\d+\)/', '$1', $contract[0]);
                self::assertSame($contract, $columns[$name], $table . '.' . $name);
            }

            foreach (['fit', 'mobile_fit'] as $part) {
                self::assertSame($fit, isset($columns[$prefix . $part]), $table . ' has a fit only where the frame is its own');
            }
            self::assertSame($mobileHeight, isset($columns[$prefix . 'mobile_height']), $table . ' has a phone height only where the block has a height');
        }

        $ratio = self::$fresh->rows("SELECT column_type AS type, is_nullable AS nullable, column_default AS `default` FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'card_carousels' AND column_name = 'flat_image_ratio'");
        self::assertSame([['type' => 'varchar(10)', 'nullable' => 'NO', 'default' => 'auto']], $ratio);
    }

    public function testEveryKeyBecomesExactlyThePointItAlwaysShowed(): void
    {
        foreach (self::SLOTS as $table => [$keyColumn, $prefix]) {
            if ($keyColumn === null) {
                continue;
            }

            self::assertCount(count(self::POINTS), self::$after[$table], $table);
            foreach (self::$before[$table] as $index => $row) {
                $key = (string) $row[$keyColumn];
                $after = self::$after[$table][$index];

                self::assertSame(
                    self::POINTS[$key],
                    [(int) $after[$prefix . 'focus_x'], (int) $after[$prefix . 'focus_y']],
                    $table . ': ' . $key
                );
            }
        }

        // The editor's nine one-click points are the same nine points.
        foreach (self::POINTS as $key => $point) {
            if ($key !== 'nowhere') {
                self::assertSame($point, ImageFocus::point($key), $key);
            }
        }
    }

    public function testThePlacesWithoutAKeyStartInTheMiddle(): void
    {
        foreach (['hover_card_grid_items', 'homepage_hero'] as $table) {
            self::assertNotSame([], self::$after[$table], $table);
            foreach (self::$after[$table] as $row) {
                self::assertSame([50, 50], [(int) $row['image_focus_x'], (int) $row['image_focus_y']], $table);
            }
        }
    }

    public function testEverythingElseOfAnExistingRowStaysAsItWasWithoutAPhoneSetting(): void
    {
        foreach (self::SLOTS as $table => [$keyColumn, $prefix, $fit, $mobileHeight]) {
            foreach (self::$before[$table] as $index => $row) {
                $after = self::$after[$table][$index];

                foreach ($row as $column => $value) {
                    if ($column !== $keyColumn) {
                        self::assertSame($value, $after[$column], $table . '.' . $column);
                    }
                }

                self::assertSame([null, null, null], [$after[$prefix . 'mobile_media_id'], $after[$prefix . 'mobile_focus_x'], $after[$prefix . 'mobile_focus_y']], $table);
                if ($fit) {
                    self::assertSame(['cover', null], [$after[$prefix . 'fit'], $after[$prefix . 'mobile_fit']], $table);
                }
                if ($mobileHeight) {
                    self::assertNull($after[$prefix . 'mobile_height'], $table);
                }
            }
        }

        foreach (self::$after['card_carousels'] as $carousel) {
            self::assertSame('auto', $carousel['flat_image_ratio']);
        }
    }

    public function testAPhonePictureIsARestrictKeyIntoTheLibraryEverywhere(): void
    {
        foreach ([self::$fresh, self::$upgraded] as $install) {
            foreach (self::SLOTS as $table => [, $prefix]) {
                self::assertSame(
                    [['referenced_table_name' => 'media', 'delete_rule' => 'RESTRICT']],
                    $install->rows(
                        "SELECT k.referenced_table_name AS referenced_table_name, r.delete_rule AS delete_rule
                           FROM information_schema.key_column_usage k
                           JOIN information_schema.referential_constraints r
                             ON r.constraint_schema = k.constraint_schema AND r.constraint_name = k.constraint_name
                          WHERE k.table_schema = DATABASE() AND k.table_name = ? AND k.column_name = ?",
                        [$table, $prefix . 'mobile_media_id']
                    ),
                    $table
                );
            }
        }

        // A picture only a phone shows cannot leave the library either.
        $this->expectException(\PDOException::class);
        self::$upgraded->pdo()->exec('DELETE FROM media WHERE id = ' . self::$phonePicture);
    }

    public function testRunningBothAgainChangesNothing(): void
    {
        $card = self::$beforeReplay['carousel_cards'][0];
        self::assertSame(
            [37, 64, self::$phonePicture, 10, 90, 'contain', 'cover'],
            [(int) $card['image_focus_x'], (int) $card['image_focus_y'], (int) $card['image_mobile_media_id'], (int) $card['image_mobile_focus_x'], (int) $card['image_mobile_focus_y'], $card['image_fit'], $card['image_mobile_fit']],
            'the rows written in between are there'
        );
        self::assertSame(self::$beforeReplay, self::$afterReplay);
    }

    /** @return array<string, list<array<string, mixed>>> */
    private static function contentOf(ScratchInstall $install): array
    {
        $content = [];
        foreach ([...array_keys(self::SLOTS), 'card_carousels'] as $table) {
            // This test's own rows; a fresh install's starting blocks are not its business.
            $where = match ($table) {
                'homepage_hero' => '',
                'page_heroes' => " WHERE page_slug LIKE 'zz-rm-%'",
                'carousel_cards' => " WHERE carousel_id IN (SELECT id FROM card_carousels WHERE page_slug = 'zz-rm')",
                'text_image_split_items' => " WHERE text_image_split_id IN (SELECT id FROM text_image_splits WHERE page_slug = 'zz-rm')",
                'hover_card_grid_items' => " WHERE hover_card_grid_id IN (SELECT id FROM hover_card_grids WHERE page_slug = 'zz-rm')",
                default => " WHERE page_slug = 'zz-rm'",
            };
            $content[$table] = $install->rows("SELECT * FROM `{$table}`{$where} ORDER BY id");
        }

        return $content;
    }

    /** @return list<array<string, mixed>> */
    private static function shape(ScratchInstall $install, string $table): array
    {
        return $install->rows(
            'SELECT column_name AS name, column_type AS type, is_nullable AS nullable, column_default AS `default` FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = ? ORDER BY ordinal_position',
            [$table]
        );
    }
}

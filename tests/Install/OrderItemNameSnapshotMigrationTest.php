<?php

declare(strict_types=1);

namespace Tests\Install;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\ScratchInstall;

/**
 * The order-line half of Multilingual 2.0 phase 5 wave C
 * (docs/multilingual/ARCHITECTURE.md, MODULES.md "Shop"):
 *
 *   20260918220000_create_the_order_item_name_snapshot_table.php
 *   20260918230000_move_order_item_english_names_into_the_snapshot_table.php
 *
 * A SEPARATE FILE FROM Tests\Install\ShopWordsMigrationTest BECAUSE IT IS A
 * SEPARATE KIND OF THING. `order_items.product_name(_en)` is what a product
 * was CALLED when somebody bought it, not a translation of what it is called
 * now, so it gets a store of its own with a rule of its own
 * (App\Service\OrderItemNameSnapshot). These are the properties that make
 * that safe:
 *
 *   - ONE column moves. `order_items.product_name` is not touched at all: it
 *     stays the one language-free snapshot the invoice, the confirmation
 *     e-mail and the CMS order screen print.
 *   - NOTHING is rewritten from a current product name. A snapshot from a
 *     renamed — or deleted — product still says what it said.
 *   - Every other column of a placed order keeps its exact value: quantity,
 *     unit price, variant label, the surcharge, the product link.
 *
 *   fresh      every migration from zero, up to MOVE
 *   upgraded   an installation that stood just before the pair, with order
 *              lines in every state their columns could be in
 *   broken     the same, with English names but no English row in the registry
 */
#[Group('migration-backfill')]
final class OrderItemNameSnapshotMigrationTest extends TestCase
{
    private const FRESH = 'mygdala_scratch_order_snapshot_fresh';
    private const UPGRADED = 'mygdala_scratch_order_snapshot_upgraded';
    private const BROKEN = 'mygdala_scratch_order_snapshot_broken';

    /** The last migration before this pair. */
    private const BEFORE = '20260918210000';

    private const SCHEMA = '20260918220000';
    private const MOVE = '20260918230000';

    /** Everything a document prints. None of it may change. */
    private const LINE_COLUMNS = 'id, order_id, product_id, variant_id, variant_label, product_name, '
        . 'quantity, unit_price, base_unit_price, personalization_surcharge';

    private static ?ScratchInstall $fresh = null;
    private static ?ScratchInstall $upgraded = null;

    /** @var list<array<string, mixed>> */
    private static array $linesBefore = [];

    /** @var array<string, int> */
    private static array $ids = [];

    private static int $rowsAfterFirstRun = 0;
    private static int $rowsAfterReplay = 0;

    private static ?string $brokenFailure = null;

    /** @var list<string> */
    private static array $brokenColumnsAfterFailure = [];

    public static function setUpBeforeClass(): void
    {
        if (!ScratchInstall::available()) {
            return;
        }

        self::$fresh = ScratchInstall::upTo(self::FRESH, self::MOVE);

        self::$upgraded = ScratchInstall::upTo(self::UPGRADED, self::BEFORE);
        self::$ids = self::seed(self::$upgraded);
        self::$linesBefore = self::$upgraded->rows('SELECT ' . self::LINE_COLUMNS . ' FROM order_items ORDER BY id');
        self::$upgraded->catchUp(self::MOVE);
        self::$rowsAfterFirstRun = self::$upgraded->count('order_item_translations');
        self::$upgraded->replay(self::SCHEMA, self::MOVE);
        self::$upgraded->replay(self::MOVE, self::MOVE);
        self::$rowsAfterReplay = self::$upgraded->count('order_item_translations');

        $broken = ScratchInstall::upTo(self::BROKEN, self::SCHEMA);
        self::seed($broken);
        foreach ([
            'page_translations',
            'block_translations',
            'nav_item_translations',
            'footer_column_translations',
            'footer_link_translations',
            'site_setting_translations',
            'form_translations',
            'form_field_translations',
            'form_field_option_translations',
            'portfolio_category_translations',
            'portfolio_item_translations',
            'portfolio_item_image_translations',
            'blog_post_translations',
            'blog_category_translations',
            'blog_tag_translations',
            'product_translations',
            'collection_translations',
        ] as $table) {
            $broken->pdo()->exec("DELETE FROM {$table} WHERE language_code = 'en'");
        }
        $broken->pdo()->exec("DELETE FROM site_languages WHERE code = 'en'");
        try {
            $broken->catchUp(self::MOVE);
        } catch (\RuntimeException $e) {
            self::$brokenFailure = $e->getMessage();
        }
        self::$brokenColumnsAfterFailure = array_column($broken->rows('SHOW COLUMNS FROM order_items'), 'Field');
        $broken->drop();
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

    // ------------------------------------------------------------ the schema

    public function testBothDatabasesEndWithTheSameSchemaAndOnlyTheEnglishColumnGone(): void
    {
        foreach (['order_items', 'order_item_translations'] as $table) {
            self::assertSame(self::shape(self::$fresh, $table), self::shape(self::$upgraded, $table), $table);
        }

        $columns = array_keys(self::shape(self::$fresh, 'order_items'));

        self::assertNotContains('product_name_en', $columns);
        self::assertContains(
            'product_name',
            $columns,
            'the language-free snapshot every document prints stays on the line itself'
        );
    }

    public function testTheSnapshotTableHasItsOwnerLanguageUniqueAndItsTwoForeignKeys(): void
    {
        $unique = self::$fresh->rows(
            "SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index) AS columns_in_index
               FROM information_schema.statistics
              WHERE table_schema = DATABASE() AND table_name = 'order_item_translations'
                AND non_unique = 0 AND index_name <> 'PRIMARY'
              GROUP BY index_name"
        );
        self::assertSame([['columns_in_index' => 'order_item_id,language_code']], $unique);

        $keys = self::$fresh->rows(
            "SELECT k.column_name AS own, k.referenced_table_name AS points_at, r.delete_rule AS on_delete
               FROM information_schema.key_column_usage k
               JOIN information_schema.referential_constraints r
                 ON r.constraint_name = k.constraint_name AND r.constraint_schema = k.constraint_schema
              WHERE k.table_schema = DATABASE() AND k.table_name = 'order_item_translations'
              ORDER BY k.column_name"
        );

        self::assertEqualsCanonicalizing(
            [
                ['own' => 'language_code', 'points_at' => 'site_languages', 'on_delete' => 'RESTRICT'],
                ['own' => 'order_item_id', 'points_at' => 'order_items', 'on_delete' => 'CASCADE'],
            ],
            $keys,
            'the line cascades, the language is refused'
        );
    }

    // ------------------------------------------------------------- the names

    public function testAnEnglishNameBecomesAnEnglishRow(): void
    {
        self::assertSame(
            [['language_code' => 'en', 'product_name' => 'Wooden coaster']],
            self::$upgraded->rows(
                'SELECT language_code, product_name FROM order_item_translations
                  WHERE order_item_id = ? ORDER BY language_code',
                [self::$ids['both']]
            )
        );
    }

    /**
     * NO DUTCH ROW IS WRITTEN. The neutral snapshot on the line itself is the
     * fallback by construction, so a second copy of it would be a copy that
     * could only ever disagree with the first.
     */
    public function testTheNeutralNameIsNotCopiedIntoTheTable(): void
    {
        self::assertSame(
            [],
            self::$upgraded->rows("SELECT order_item_id FROM order_item_translations WHERE language_code = 'nl'"),
            'the neutral snapshot stays exactly one value, on the line'
        );
    }

    /** NULL, '' and whitespace all meant "no English name" to the one reader. */
    public function testALineWithoutAnEnglishNameGetsNoRow(): void
    {
        foreach (['dutch_only', 'empty', 'whitespace'] as $fixture) {
            self::assertSame(
                [],
                self::$upgraded->rows(
                    'SELECT language_code FROM order_item_translations WHERE order_item_id = ?',
                    [self::$ids[$fixture]]
                ),
                $fixture . ': no English name means no row, which reads as the neutral snapshot'
            );
        }
    }

    /** A value is copied, never trimmed or re-encoded on the way. */
    public function testNamesAreCopiedByteForByte(): void
    {
        self::assertSame(
            [['product_name' => " Spaced  &amp; \"quoted\" \u{00a0}"]],
            self::$upgraded->rows(
                "SELECT product_name FROM order_item_translations
                  WHERE order_item_id = ? AND language_code = 'en'",
                [self::$ids['exotic']]
            )
        );
    }

    /**
     * A line whose product was DELETED keeps its snapshot: `product_id` is
     * NULL and there is nothing to read a current name from, which is exactly
     * the case a snapshot exists for.
     */
    public function testALineWhoseProductIsGoneStillKeepsItsName(): void
    {
        self::assertSame(
            [['product_id' => null, 'product_name' => 'Verdwenen product']],
            self::$upgraded->rows(
                'SELECT product_id, product_name FROM order_items WHERE id = ?',
                [self::$ids['orphaned']]
            )
        );

        self::assertSame(
            [['product_name' => 'Vanished product']],
            self::$upgraded->rows(
                "SELECT product_name FROM order_item_translations
                  WHERE order_item_id = ? AND language_code = 'en'",
                [self::$ids['orphaned']]
            )
        );
    }

    // ------------------------------------------------- what must not change

    /**
     * A PLACED ORDER IS A DOCUMENT. Not one quantity, price, surcharge,
     * variant label or product link changed, and neither did the neutral
     * name — even for the line whose product has since been renamed.
     */
    public function testEveryOtherPartOfEveryOrderLineIsUntouched(): void
    {
        self::assertSame(
            self::$linesBefore,
            self::$upgraded->rows('SELECT ' . self::LINE_COLUMNS . ' FROM order_items ORDER BY id')
        );
    }

    /** And nothing was read from `products`: the rename did not reach the order. */
    public function testTheSnapshotDidNotFollowALaterProductRename(): void
    {
        self::assertSame(
            [['name' => 'Hernoemd product']],
            self::$upgraded->rows('SELECT name FROM product_translations WHERE product_id = ? AND language_code = ?', [
                self::$ids['product'],
                'nl',
            ]),
            'the product really was renamed after the order'
        );

        self::assertSame(
            [['product_name' => 'Houten onderzetter']],
            self::$upgraded->rows('SELECT product_name FROM order_items WHERE id = ?', [self::$ids['both']]),
            'and the order still says what it said'
        );
    }

    public function testASecondRunChangesNothing(): void
    {
        self::assertGreaterThan(0, self::$rowsAfterFirstRun);
        self::assertSame(self::$rowsAfterFirstRun, self::$rowsAfterReplay);
    }

    // ------------------------------------------------------- refuses to lose

    /**
     * An order's own history is the one thing that must never be thrown away
     * quietly, so this is the one case that fails loudly.
     */
    public function testNamesInALanguageTheRegistryDoesNotHaveStopTheMigrationBeforeTheDrop(): void
    {
        self::assertNotNull(self::$brokenFailure, 'the migration should have refused');
        self::assertStringContainsString('order line', (string) self::$brokenFailure);
        self::assertStringContainsString('"en"', (string) self::$brokenFailure);

        self::assertContains('product_name_en', self::$brokenColumnsAfterFailure, 'nothing was dropped');
        self::assertContains('product_name', self::$brokenColumnsAfterFailure);
    }

    /* ------------------------------------------------------------------ */
    /* Fixtures                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * Order lines in every state their two snapshot columns could be in, plus
     * one whose product was deleted and one whose product was renamed after
     * the order was placed.
     *
     * @return array<string, int>
     */
    private static function seed(ScratchInstall $install): array
    {
        $ids = [];
        $pdo = $install->pdo();

        // The product this order came from — and which is renamed below, so
        // the snapshot can be seen NOT to follow it.
        $pdo->prepare(
            'INSERT INTO products (slug, price, stock, active, in_shop, in_personalization_catalog,
                                   shipping_profile, shipping_weight_grams, requires_parcel, created_at, updated_at)
             VALUES (?, ?, ?, 1, 1, 0, ?, ?, 0, NOW(), NOW())'
        )->execute(['zz-order-snapshot-product', '12.50', 3, 'letter', 20]);
        $ids['product'] = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO product_translations (product_id, language_code, name, created_at, updated_at)
             VALUES (?, ?, ?, NOW(), NOW())'
        )->execute([$ids['product'], 'nl', 'Hernoemd product']);

        $pdo->prepare(
            'INSERT INTO customers (name, email, created_at, updated_at) VALUES (?, ?, NOW(), NOW())'
        )->execute(['ZZ Klant', 'zz-order-snapshot@__test__.invalid']);
        $customerId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO orders (customer_id, total, shipping_cost, shipping_method, currency, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())'
        )->execute([$customerId, '50.00', '0.00', 'afhalen', 'EUR', 'paid']);
        $orderId = (int) $pdo->lastInsertId();

        $line = $pdo->prepare(
            'INSERT INTO order_items (order_id, product_id, variant_id, variant_label, product_name, product_name_en,
                                      quantity, unit_price, base_unit_price, personalization_surcharge, created_at, updated_at)
             VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
        );

        $line->execute([$orderId, $ids['product'], 'Kleur: Noten', 'Houten onderzetter', 'Wooden coaster', 2, '12.50', '10.00', '2.50']);
        $ids['both'] = (int) $pdo->lastInsertId();

        $line->execute([$orderId, $ids['product'], null, 'Alleen Nederlands', null, 1, '12.50', null, null]);
        $ids['dutch_only'] = (int) $pdo->lastInsertId();

        $line->execute([$orderId, $ids['product'], null, 'Lege Engelse naam', '', 1, '12.50', null, null]);
        $ids['empty'] = (int) $pdo->lastInsertId();

        $line->execute([$orderId, $ids['product'], null, 'Witruimte', "  \n\t ", 1, '12.50', null, null]);
        $ids['whitespace'] = (int) $pdo->lastInsertId();

        $line->execute([$orderId, $ids['product'], null, 'Exotisch', " Spaced  &amp; \"quoted\" \u{00a0}", 1, '12.50', null, null]);
        $ids['exotic'] = (int) $pdo->lastInsertId();

        // A line whose product is gone: product_id NULL, exactly what
        // 20260908120000's ON DELETE SET NULL leaves behind.
        $line->execute([$orderId, null, null, 'Verdwenen product', 'Vanished product', 1, '9.95', null, null]);
        $ids['orphaned'] = (int) $pdo->lastInsertId();

        return $ids;
    }

    /** @return array<string, string> column => type */
    private static function shape(ScratchInstall $install, string $table): array
    {
        $shape = [];
        foreach ($install->rows('SHOW COLUMNS FROM ' . $table) as $column) {
            $shape[(string) $column['Field']] = (string) $column['Type'];
        }

        return $shape;
    }
}

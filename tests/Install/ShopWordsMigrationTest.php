<?php

declare(strict_types=1);

namespace Tests\Install;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\ScratchInstall;

/**
 * Multilingual 2.0 phase 5 wave C on the kinds of database it meets
 * (docs/multilingual/ARCHITECTURE.md, MODULES.md "Shop"):
 *
 *   20260918200000_create_the_shop_translation_tables.php
 *   20260918210000_move_shop_words_into_translation_tables.php
 *
 *   fresh      every migration from zero, up to MOVE
 *   upgraded   an installation that stood just before wave C, with products
 *              and collections in every state their columns could be in
 *   broken     the same, with English words but no English row in the registry
 *
 * What must hold: Dutch stays Dutch and English stays English, byte for byte,
 * the rich description included; an empty or whitespace value gets no row;
 * several fields of one product share one row per language; and above all
 * NOTHING THAT IS IDENTITY, MONEY OR STOCK MOVES — ids, slugs, prices,
 * stock, shipping settings, channel switches, image paths, sort orders and
 * every relation keep their exact values, so a language switch can no more
 * change a price after this migration than it could before. A second run
 * changes nothing, and words that cannot be moved stop the migration before
 * any column is dropped.
 *
 * `order_items` is deliberately not in this file: that table holds a
 * SNAPSHOT, not a translation, and 20260918230000 has its own test.
 *
 * NOTHING HERE ASKS WHETHER THE SHOP IS ENABLED. A scratch install runs with
 * no MODULE_* variable set at all, so the module is off, and both databases
 * must still end on the same schema with every word moved.
 */
#[Group('migration-backfill')]
final class ShopWordsMigrationTest extends TestCase
{
    private const FRESH = 'mygdala_scratch_shop_words_fresh';
    private const UPGRADED = 'mygdala_scratch_shop_words_upgraded';
    private const BROKEN = 'mygdala_scratch_shop_words_broken';

    /** The last migration before wave C. */
    private const BEFORE = '20260918190000';

    private const SCHEMA = '20260918200000';
    private const MOVE = '20260918210000';

    /** Everything a shop DECIDES with. None of it may move or change. */
    private const NEUTRAL_PRODUCT_COLUMNS = 'id, slug, price, stock, image_path, og_image_path, active, in_shop, '
        . 'in_personalization_catalog, shipping_profile, shipping_weight_grams, requires_parcel';
    private const NEUTRAL_COLLECTION_COLUMNS = 'id, slug, image_path, og_image_path, is_active, '
        . 'show_related_products, sort_order';

    private static ?ScratchInstall $fresh = null;
    private static ?ScratchInstall $upgraded = null;

    /** @var array<string, list<array<string, mixed>>> */
    private static array $neutralBefore = [];

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
        self::$neutralBefore['products'] = self::$upgraded->rows(
            'SELECT ' . self::NEUTRAL_PRODUCT_COLUMNS . ' FROM products ORDER BY id'
        );
        self::$neutralBefore['collections'] = self::$upgraded->rows(
            'SELECT ' . self::NEUTRAL_COLLECTION_COLUMNS . ' FROM collections ORDER BY id'
        );
        self::$neutralBefore['collection_products'] = self::$upgraded->rows(
            'SELECT collection_id, product_id, sort_order FROM collection_products ORDER BY collection_id, product_id'
        );
        self::$upgraded->catchUp(self::MOVE);
        self::$rowsAfterFirstRun = self::rowCount(self::$upgraded);
        self::$upgraded->replay(self::SCHEMA, self::MOVE);
        self::$upgraded->replay(self::MOVE, self::MOVE);
        self::$rowsAfterReplay = self::rowCount(self::$upgraded);

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
        ] as $table) {
            $broken->pdo()->exec("DELETE FROM {$table} WHERE language_code = 'en'");
        }
        $broken->pdo()->exec("DELETE FROM site_languages WHERE code = 'en'");
        try {
            $broken->catchUp(self::MOVE);
        } catch (\RuntimeException $e) {
            self::$brokenFailure = $e->getMessage();
        }
        self::$brokenColumnsAfterFailure = array_column($broken->rows('SHOW COLUMNS FROM products'), 'Field');
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

    public function testBothDatabasesEndWithTheSameSchemaAndNoWordColumnsLeft(): void
    {
        foreach (['products', 'collections', 'product_translations', 'collection_translations'] as $table) {
            self::assertSame(self::shape(self::$fresh, $table), self::shape(self::$upgraded, $table), $table);
        }

        // The bare Dutch column is gone as well as the _en one, so the only
        // way to ask "what is this product called" is the words store.
        foreach ([
            'products' => ['name', 'name_en', 'description', 'description_en', 'meta_title', 'meta_title_en', 'meta_description', 'meta_description_en'],
            'collections' => ['name', 'name_en', 'description', 'description_en', 'meta_title', 'meta_title_en', 'meta_description', 'meta_description_en', 'related_heading_nl', 'related_heading_en'],
        ] as $table => $gone) {
            $columns = array_keys(self::shape(self::$fresh, $table));

            foreach ($gone as $column) {
                self::assertNotContains($column, $columns, $table . '.' . $column . ' should be gone');
            }
        }
    }

    /**
     * THE SLUG STAYED, on both tables, exactly where a route can find it.
     * /product.php?id= and /collecties/<slug> answer what they answered
     * before; a slug per language needs the router of phase 6.
     */
    public function testBothTablesKeptTheirOneLanguageNeutralSlug(): void
    {
        foreach (['products', 'collections'] as $table) {
            self::assertArrayHasKey('slug', self::shape(self::$fresh, $table), $table);
        }

        foreach (['product_translations', 'collection_translations'] as $table) {
            self::assertArrayNotHasKey('slug', self::shape(self::$fresh, $table), $table . ' must not carry a slug');
        }
    }

    public function testEachTableHasItsOwnerLanguageUniqueAndItsTwoForeignKeys(): void
    {
        foreach ([
            'product_translations' => ['product_id', 'products'],
            'collection_translations' => ['collection_id', 'collections'],
        ] as $table => [$ownerColumn, $ownerTable]) {
            $unique = self::$fresh->rows(
                "SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index) AS columns_in_index
                   FROM information_schema.statistics
                  WHERE table_schema = DATABASE() AND table_name = ? AND non_unique = 0 AND index_name <> 'PRIMARY'
                  GROUP BY index_name",
                [$table]
            );
            self::assertSame([['columns_in_index' => $ownerColumn . ',language_code']], $unique, $table);

            $keys = self::$fresh->rows(
                "SELECT k.column_name AS own, k.referenced_table_name AS points_at, r.delete_rule AS on_delete
                   FROM information_schema.key_column_usage k
                   JOIN information_schema.referential_constraints r
                     ON r.constraint_name = k.constraint_name AND r.constraint_schema = k.constraint_schema
                  WHERE k.table_schema = DATABASE() AND k.table_name = ?
                  ORDER BY k.column_name",
                [$table]
            );

            self::assertEqualsCanonicalizing(
                [
                    ['own' => 'language_code', 'points_at' => 'site_languages', 'on_delete' => 'RESTRICT'],
                    ['own' => $ownerColumn, 'points_at' => $ownerTable, 'on_delete' => 'CASCADE'],
                ],
                $keys,
                $table . ': the owner cascades, the language is refused'
            );
        }
    }

    // ------------------------------------------------------------- the words

    public function testAProductKeepsEveryFieldPerLanguageInOneRow(): void
    {
        self::assertSame(
            [
                [
                    'language_code' => 'en',
                    'name' => 'Wooden coaster',
                    'description' => '<p>Made of birch.</p>',
                    'meta_title' => 'Coasters | Shop',
                    'meta_description' => null,
                ],
                [
                    'language_code' => 'nl',
                    'name' => 'Houten onderzetter',
                    'description' => '<p>Van berkenhout.</p>',
                    'meta_title' => 'Onderzetters | Shop',
                    'meta_description' => 'Handgemaakt in Nederland.',
                ],
            ],
            self::$upgraded->rows(
                'SELECT language_code, name, description, meta_title, meta_description
                   FROM product_translations WHERE product_id = ? ORDER BY language_code',
                [self::$ids['product']]
            ),
            'four fields of one product share one row per language, whitespace excluded'
        );
    }

    /** A product with a Dutch name and nothing else has one row with one value. */
    public function testAProductWithOnlyANameHasOneValue(): void
    {
        self::assertSame(
            [['language_code' => 'nl', 'name' => 'Alleen een naam', 'description' => null, 'meta_title' => null]],
            self::$upgraded->rows(
                'SELECT language_code, name, description, meta_title FROM product_translations
                  WHERE product_id = ? ORDER BY language_code',
                [self::$ids['bare_product']]
            )
        );
    }

    public function testACollectionKeepsItsFieldsAndItsOwnRelatedHeading(): void
    {
        self::assertSame(
            [
                [
                    'language_code' => 'en',
                    'name' => 'Coasters',
                    'description' => null,
                    'related_heading' => 'More coasters',
                ],
                [
                    'language_code' => 'nl',
                    'name' => 'Onderzetters',
                    'description' => '<p>Onderzetters van berkenhout.</p>',
                    'related_heading' => 'Meer onderzetters',
                ],
            ],
            self::$upgraded->rows(
                'SELECT language_code, name, description, related_heading FROM collection_translations
                  WHERE collection_id = ? ORDER BY language_code',
                [self::$ids['collection']]
            )
        );

        self::assertSame(
            [['language_code' => 'nl', 'name' => 'Zonder eigen kop', 'related_heading' => null]],
            self::$upgraded->rows(
                'SELECT language_code, name, related_heading FROM collection_translations
                  WHERE collection_id = ? ORDER BY language_code',
                [self::$ids['bare_collection']]
            ),
            'whitespace is no heading, and no heading means "use the global one"'
        );
    }

    /** A value is copied, never trimmed, sanitized or re-encoded on the way. */
    public function testWordsAreCopiedByteForByte(): void
    {
        self::assertSame(
            [['name' => " Ruimte  eromheen \u{00a0}", 'description' => '<p>Ampersand &amp; "quotes"</p>']],
            self::$upgraded->rows(
                "SELECT name, description FROM product_translations
                  WHERE product_id = ? AND language_code = 'nl'",
                [self::$ids['exotic_product']]
            )
        );
    }

    // -------------------------------------------- the shop-wide heading

    /**
     * The one localized SETTING of this wave. It goes into the existing
     * `site_setting_translations` catalogue
     * (App\Service\LocalizedSiteSettings), not into a table of the Shop's
     * own, and both legacy rows are removed once it has.
     */
    public function testTheGlobalRelatedProductsHeadingBecameALocalizedSetting(): void
    {
        self::assertSame(
            [
                ['language_code' => 'en', 'value' => 'Related products'],
                ['language_code' => 'nl', 'value' => 'Ook interessant'],
            ],
            self::$upgraded->rows(
                "SELECT language_code, value FROM site_setting_translations
                  WHERE setting_key = 'related_products_heading' ORDER BY language_code"
            )
        );

        self::assertSame(
            [],
            self::$upgraded->rows(
                "SELECT setting_key FROM site_settings WHERE setting_key LIKE 'related_products_heading%'"
            ),
            'the two legacy rows are gone, so nothing can read a stale copy'
        );

        // And the two that are NOT words stayed exactly where they were.
        self::assertSame(
            [
                ['setting_key' => 'related_products_enabled', 'setting_value' => '1'],
                ['setting_key' => 'related_products_max_items', 'setting_value' => '4'],
            ],
            self::$upgraded->rows(
                "SELECT setting_key, setting_value FROM site_settings
                  WHERE setting_key IN ('related_products_enabled', 'related_products_max_items')
                  ORDER BY setting_key"
            )
        );
    }

    // ------------------------------------------------- what must not change

    /**
     * The assertion this whole wave is judged by: not one price, id, slug,
     * stock level, channel switch, image path or sort order changed.
     */
    public function testNothingThatIsIdentityMoneyOrStockMoved(): void
    {
        foreach ([
            'products' => self::NEUTRAL_PRODUCT_COLUMNS,
            'collections' => self::NEUTRAL_COLLECTION_COLUMNS,
        ] as $table => $columns) {
            self::assertSame(
                self::$neutralBefore[$table],
                self::$upgraded->rows('SELECT ' . $columns . ' FROM ' . $table . ' ORDER BY id'),
                $table . ': ids, slugs, prices, stock, switches, images and orders are untouched'
            );
        }
    }

    /** Which product is in which collection, and in which order, is language-neutral. */
    public function testTheCollectionMembershipIsUntouched(): void
    {
        self::assertSame(
            self::$neutralBefore['collection_products'],
            self::$upgraded->rows(
                'SELECT collection_id, product_id, sort_order FROM collection_products ORDER BY collection_id, product_id'
            )
        );
    }

    /** An order is a snapshot, and this migration never goes near one. */
    public function testNoOrderLineWasTouched(): void
    {
        self::assertSame(
            [['id' => self::$ids['order_item'], 'product_name' => 'Houten onderzetter', 'product_name_en' => 'Wooden coaster']],
            self::$upgraded->rows(
                'SELECT id, product_name, product_name_en FROM order_items ORDER BY id'
            ),
            'the snapshot columns still hold what they held; 20260918230000 moves the English one'
        );
    }

    public function testASecondRunChangesNothing(): void
    {
        self::assertGreaterThan(0, self::$rowsAfterFirstRun);
        self::assertSame(self::$rowsAfterFirstRun, self::$rowsAfterReplay);
    }

    // ------------------------------------------------------- refuses to lose

    public function testWordsInALanguageTheRegistryDoesNotHaveStopTheMigrationBeforeTheDrop(): void
    {
        self::assertNotNull(self::$brokenFailure, 'the migration should have refused');
        self::assertStringContainsString('products', (string) self::$brokenFailure);
        self::assertStringContainsString('"en"', (string) self::$brokenFailure);

        foreach (['name', 'name_en', 'description', 'description_en', 'meta_description_en'] as $column) {
            self::assertContains($column, self::$brokenColumnsAfterFailure, $column . ' must still be there');
        }
    }

    /* ------------------------------------------------------------------ */
    /* Fixtures                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * Products and collections in every state their columns could be in: both
     * languages, Dutch only, whitespace, empty, and one with characters a
     * careless copy would change. Plus one membership row and one order line,
     * so what must NOT move can be checked afterwards.
     *
     * @return array<string, int>
     */
    private static function seed(ScratchInstall $install): array
    {
        $ids = [];
        $pdo = $install->pdo();

        $product = $pdo->prepare(
            'INSERT INTO products
                (name, name_en, slug, description, description_en, price, stock, image_path,
                 active, in_shop, in_personalization_catalog,
                 shipping_profile, shipping_weight_grams, requires_parcel,
                 meta_title, meta_title_en, meta_description, meta_description_en, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $product->execute([
            'Houten onderzetter', 'Wooden coaster', 'zz-houten-onderzetter',
            '<p>Van berkenhout.</p>', '<p>Made of birch.</p>',
            '12.50', 7, 'assets/images/products/zz-onderzetter.webp',
            1, 1, 0, 'letter', 20, 0,
            'Onderzetters | Shop', 'Coasters | Shop', 'Handgemaakt in Nederland.', '  ',
            '2026-01-02 03:04:05', '2026-02-03 04:05:06',
        ]);
        $ids['product'] = (int) $pdo->lastInsertId();

        $product->execute([
            'Alleen een naam', '   ', 'zz-alleen-een-naam',
            '', null, '3.00', 0, null,
            0, 0, 1, 'parcel', 900, 1,
            null, null, null, null,
            '2026-01-02 03:04:05', '2026-01-02 03:04:05',
        ]);
        $ids['bare_product'] = (int) $pdo->lastInsertId();

        $product->execute([
            " Ruimte  eromheen \u{00a0}", null, 'zz-exotisch-product',
            '<p>Ampersand &amp; "quotes"</p>', null, '99999.99', 1, null,
            1, 1, 1, 'letter', 1, 0,
            null, null, null, null,
            '2026-01-02 03:04:05', '2026-01-02 03:04:05',
        ]);
        $ids['exotic_product'] = (int) $pdo->lastInsertId();

        $collection = $pdo->prepare(
            'INSERT INTO collections
                (name, name_en, slug, description, description_en, image_path, is_active,
                 show_related_products, related_heading_nl, related_heading_en, sort_order,
                 meta_title, meta_title_en, meta_description, meta_description_en, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $collection->execute([
            'Onderzetters', 'Coasters', 'zz-onderzetters',
            '<p>Onderzetters van berkenhout.</p>', "\n\t ",
            'assets/images/sections/zz-onderzetters.webp', 1,
            1, 'Meer onderzetters', 'More coasters', 10,
            null, null, null, null,
            '2026-01-02 03:04:05', '2026-01-02 03:04:05',
        ]);
        $ids['collection'] = (int) $pdo->lastInsertId();

        $collection->execute([
            'Zonder eigen kop', null, 'zz-zonder-eigen-kop',
            null, null, null, 0,
            0, '   ', '', 20,
            null, null, null, null,
            '2026-01-02 03:04:05', '2026-01-02 03:04:05',
        ]);
        $ids['bare_collection'] = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO collection_products (collection_id, product_id, sort_order) VALUES (?, ?, ?)'
        )->execute([$ids['collection'], $ids['product'], 0]);

        // The shop-wide heading, in a wording of this installation's own, so
        // the migration is seen to move a VALUE rather than a default.
        $setting = $pdo->prepare(
            'INSERT INTO site_settings (setting_key, setting_value, created_at, updated_at)
             VALUES (?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );
        $setting->execute(['related_products_heading_nl', 'Ook interessant']);
        $setting->execute(['related_products_heading_en', 'Related products']);
        $setting->execute(['related_products_enabled', '1']);
        $setting->execute(['related_products_max_items', '4']);

        $ids['order_item'] = self::seedOrderLine($install, $ids['product']);

        return $ids;
    }

    /**
     * One placed order line, only so the wave can be seen NOT to touch it.
     * Its own migration is 20260918230000.
     */
    private static function seedOrderLine(ScratchInstall $install, int $productId): int
    {
        $pdo = $install->pdo();

        $pdo->prepare(
            'INSERT INTO customers (name, email, created_at, updated_at) VALUES (?, ?, NOW(), NOW())'
        )->execute(['ZZ Klant', 'zz-shop-words@__test__.invalid']);
        $customerId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO orders (customer_id, total, shipping_cost, shipping_method, currency, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())'
        )->execute([$customerId, '12.50', '0.00', 'afhalen', 'EUR', 'paid']);
        $orderId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO order_items (order_id, product_id, variant_id, variant_label, product_name, product_name_en,
                                      quantity, unit_price, created_at, updated_at)
             VALUES (?, ?, NULL, NULL, ?, ?, ?, ?, NOW(), NOW())'
        )->execute([$orderId, $productId, 'Houten onderzetter', 'Wooden coaster', 1, '12.50']);

        return (int) $pdo->lastInsertId();
    }

    private static function rowCount(ScratchInstall $install): int
    {
        return $install->count('product_translations') + $install->count('collection_translations');
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

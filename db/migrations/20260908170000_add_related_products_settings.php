<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * "Gerelateerde producten": the settings behind the automatic related-products
 * section on a product detail page (see App\Service\RelatedProductsContent and
 * partials/related-products.php).
 *
 * There is deliberately NO relation table. Which products are related is
 * derived entirely from the existing `collection_products` membership — a
 * product's collection IS the pool — so this migration only adds the
 * configuration that decides whether, and how, that pool is shown.
 *
 * Two storage locations, each the one this project already uses for that kind
 * of value:
 *
 * 1. GLOBAL settings as `site_settings` rows, exactly like
 *    20260907230000_add_footer_settings.php: key/value, no schema change, and
 *    App\Service\SiteSettings::DEFAULTS carries the same values so the feature
 *    behaves correctly even before this migration runs or if a row is missing.
 *    `related_products_heading_en` is seeded EMPTY on purpose — an empty EN
 *    value means "use the NL heading", the same bilingual fallback every other
 *    pair in this project uses, rather than a second copy of the Dutch text.
 *
 * 2. PER-COLLECTION settings as columns on `collections`, next to `is_active`
 *    and `sort_order` — they are properties of one collection, and putting them
 *    anywhere else would mean a second lookup for every product page. Adding a
 *    column with a default backfills every EXISTING collection with
 *    show_related_products = 1, and MySQL applies the same default to every new
 *    collection (CollectionRepository::create() never names the column), which
 *    is exactly the required "enabled by default, now and later" behaviour.
 *
 * `related_heading_nl`/`_en` are the optional per-collection override of the
 * global heading; NULL/empty means "use the global one". Deliberately the only
 * presentation setting a collection gets — everything else (max items, on/off
 * globally) stays central.
 */
final class AddRelatedProductsSettings extends AbstractMigration
{
    /** Global key => seeded value. Must stay in sync with SiteSettings::DEFAULTS. */
    private const SETTINGS = [
        'related_products_enabled' => '1',
        'related_products_heading_nl' => 'Gerelateerde producten',
        'related_products_heading_en' => '',
        'related_products_max_items' => '4',
    ];

    public function up(): void
    {
        $this->table('collections')
            ->addColumn('show_related_products', 'boolean', [
                'default' => true,
                'null' => false,
                'after' => 'is_active',
            ])
            ->addColumn('related_heading_nl', 'string', [
                'limit' => 255,
                'null' => true,
                'after' => 'show_related_products',
            ])
            ->addColumn('related_heading_en', 'string', [
                'limit' => 255,
                'null' => true,
                'after' => 'related_heading_nl',
            ])
            ->update();

        $now = date('Y-m-d H:i:s');
        $data = [];
        foreach (self::SETTINGS as $key => $value) {
            $data[] = [
                'setting_key' => $key,
                'setting_value' => $value,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $this->table('site_settings')->insert($data)->saveData();
    }

    public function down(): void
    {
        $this->table('collections')
            ->removeColumn('show_related_products')
            ->removeColumn('related_heading_nl')
            ->removeColumn('related_heading_en')
            ->update();

        $keys = array_keys(self::SETTINGS);
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $this->execute("DELETE FROM site_settings WHERE setting_key IN ($placeholders)", $keys);
    }
}

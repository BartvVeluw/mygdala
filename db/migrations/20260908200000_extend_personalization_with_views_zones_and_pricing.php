<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Phase 2 of product personalization: several engraving zones per product,
 * grouped into preview views (front/back/...), each zone with its own labels,
 * required/optional state, allowed fonts and optional surcharge.
 *
 * This EXTENDS the Phase 1 model rather than replacing it. Phase 1
 * deliberately gave `product_personalization_zones` and
 * `order_item_personalizations` a `zone_key` with a UNIQUE key per parent, so
 * "more zones" was always meant to be more rows. Nothing here changes what a
 * zone row means; it only adds the columns a zone could not yet express.
 *
 * ## What happens to an existing Phase 1 product
 *
 * Phase 1 stored the single preview image on the settings row, because there
 * was exactly one implicit view. Phase 2 gives views their own table, so this
 * migration BACKFILLS one view per configured product ("default", carrying
 * that same image), points the product's existing zone at it, and only then
 * removes the now-superseded settings column. An administrator therefore has
 * nothing to redo: their configured product comes out the other side as a
 * one-view, one-zone product that behaves exactly as before.
 *
 * ## What happens to an existing Phase 1 order
 *
 * Nothing at all. Order personalization is a snapshot
 * (`config_snapshot_json`, `version: 1`), read from the order row and never
 * from the product, so no historical order is rewritten or needs to be. The
 * new order-side columns are nullable/defaulted, and NULL keeps meaning
 * exactly what a Phase 1 order meant: one zone, no view, no font choice, no
 * surcharge.
 *
 * ## Pricing
 *
 * `order_items` gains `base_unit_price` and `personalization_surcharge` so an
 * order line can always explain its own price. `unit_price` deliberately
 * stays the AUTHORITATIVE per-unit line price (base + surcharge), which is
 * why the invoice PDF, the confirmation email and the CSV export keep working
 * untouched — they all read `unit_price` and all stay correct. For a Phase 1
 * line both new columns are NULL, which reads as "no surcharge, base equals
 * unit_price".
 *
 * MySQL 5.7 / PHP 8.2 compatible: plain columns, no JSON column type, no
 * generated columns, and every foreign-key column `signed => false` to match
 * Phinx's own unsigned `id`.
 */
final class ExtendPersonalizationWithViewsZonesAndPricing extends AbstractMigration
{
    public function up(): void
    {
        // ---------------------------------------------------------------
        // 1. Preview views (front / back / ... ), one or more per product
        // ---------------------------------------------------------------
        $this->table('product_personalization_views')
            ->addColumn('settings_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('view_key', 'string', ['limit' => 32, 'null' => false])
            ->addColumn('label', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('label_en', 'string', ['limit' => 100, 'null' => true])
            // The image this view's zones are positioned on. Every zone
            // coordinate is a percentage OF THIS IMAGE, which is exactly why
            // a view owns its own image instead of sharing one.
            ->addColumn('preview_image_path', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('sort_order', 'integer', ['default' => 0])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addForeignKey('settings_id', 'product_personalization_settings', 'id', [
                'delete' => 'CASCADE',
                'update' => 'CASCADE',
            ])
            ->addIndex(['settings_id', 'view_key'], ['unique' => true])
            ->create();

        // ---------------------------------------------------------------
        // 2. Everything a zone could not yet express
        //
        // `zone_key` stays UNIQUE per SETTINGS row, not per view: an order
        // line's personalization is keyed by zone_key alone, so a zone key
        // has to identify one zone within the whole product regardless of
        // which view it sits on. That is what lets order rows stay exactly
        // as Phase 1 wrote them.
        //
        // No 'after' positions here on purpose: several of these columns
        // would have to reference a sibling added in the same batch, which
        // is needlessly fragile for a purely cosmetic column order.
        // ---------------------------------------------------------------
        $this->table('product_personalization_zones')
            ->addColumn('view_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('label_en', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('instructions', 'string', ['limit' => 500, 'null' => true])
            ->addColumn('instructions_en', 'string', ['limit' => 500, 'null' => true])
            ->addColumn('placeholder', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('placeholder_en', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('is_enabled', 'boolean', ['default' => true])
            ->addColumn('is_required', 'boolean', ['default' => false])
            ->addColumn('allow_rotation', 'boolean', ['default' => true])
            // Font identifiers only — the fonts themselves live in the code
            // registry App\Service\Personalization\PersonalizationFonts, so a
            // stale or forged key is dropped on read instead of becoming a
            // font nobody can engrave. Comma-separated because the list is
            // tiny and always passes through that registry's sanitizer, the
            // same pattern App\Service\AdminPermissions already uses.
            ->addColumn('default_font', 'string', ['limit' => 32, 'null' => true])
            ->addColumn('allowed_fonts', 'string', ['limit' => 255, 'null' => true])
            // A fixed amount added once when this zone is actually used.
            // decimal, never a float — see App\Service\Personalization\Money.
            ->addColumn('surcharge', 'decimal', ['precision' => 10, 'scale' => 2, 'default' => '0.00'])
            ->addForeignKey('view_id', 'product_personalization_views', 'id', [
                'delete' => 'CASCADE',
                'update' => 'CASCADE',
            ])
            ->update();

        // ---------------------------------------------------------------
        // 3. Order-side additions (all nullable/defaulted: a Phase 1 row
        //    stays valid and untouched)
        // ---------------------------------------------------------------
        $this->table('order_item_personalizations')
            ->addColumn('view_key', 'string', ['limit' => 32, 'null' => true])
            ->addColumn('font_key', 'string', ['limit' => 32, 'null' => true])
            ->addColumn('surcharge', 'decimal', ['precision' => 10, 'scale' => 2, 'default' => '0.00'])
            ->update();

        $this->table('order_items')
            ->addColumn('base_unit_price', 'decimal', ['precision' => 10, 'scale' => 2, 'null' => true])
            ->addColumn('personalization_surcharge', 'decimal', ['precision' => 10, 'scale' => 2, 'null' => true])
            ->update();

        // ---------------------------------------------------------------
        // 4. Backfill: give every already-configured product the one view it
        //    implicitly had, and point its zones at it.
        // ---------------------------------------------------------------
        $pdo = $this->getAdapter()->getConnection();
        $now = date('Y-m-d H:i:s');

        // Two distinct placeholder names for the same value on purpose:
        // this project's PDO runs with ATTR_EMULATE_PREPARES => false, and a
        // native prepare rejects a named placeholder used twice.
        $insertView = $pdo->prepare(
            'INSERT INTO product_personalization_views
                (settings_id, view_key, label, label_en, preview_image_path, sort_order, created_at, updated_at)
             VALUES (:settings_id, :view_key, NULL, NULL, :preview_image_path, 0, :created_at, :updated_at)'
        );
        $pointZones = $pdo->prepare(
            'UPDATE product_personalization_zones
             SET view_id = :view_id
             WHERE settings_id = :settings_id AND view_id IS NULL'
        );

        foreach ($this->fetchAll('SELECT id, preview_image_path FROM product_personalization_settings') as $settings) {
            $insertView->execute([
                'settings_id' => (int) $settings['id'],
                'view_key' => 'default',
                'preview_image_path' => $settings['preview_image_path'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $pointZones->execute([
                'view_id' => (int) $pdo->lastInsertId(),
                'settings_id' => (int) $settings['id'],
            ]);
        }

        // ---------------------------------------------------------------
        // 5. The settings-level image is now superseded by the view's own.
        //    Removed rather than left behind: two columns holding "the
        //    preview image" is exactly the ambiguity that produces a product
        //    whose engraving area is measured against the wrong picture.
        //    down() restores both the column and its value.
        // ---------------------------------------------------------------
        $this->table('product_personalization_settings')
            ->removeColumn('preview_image_path')
            ->update();
    }

    public function down(): void
    {
        $this->table('product_personalization_settings')
            ->addColumn('preview_image_path', 'string', ['limit' => 255, 'null' => true])
            ->update();

        // Put each product's first view's image back where Phase 1 kept it.
        $this->getAdapter()->getConnection()->exec(
            'UPDATE product_personalization_settings s
             INNER JOIN product_personalization_views v
                ON v.settings_id = s.id AND v.sort_order = 0
             SET s.preview_image_path = v.preview_image_path'
        );

        $this->table('order_items')
            ->removeColumn('base_unit_price')
            ->removeColumn('personalization_surcharge')
            ->update();

        $this->table('order_item_personalizations')
            ->removeColumn('view_key')
            ->removeColumn('font_key')
            ->removeColumn('surcharge')
            ->update();

        // The foreign key has to go before the column it lives on.
        $this->table('product_personalization_zones')->dropForeignKey('view_id')->update();

        $this->table('product_personalization_zones')
            ->removeColumn('view_id')
            ->removeColumn('label_en')
            ->removeColumn('instructions')
            ->removeColumn('instructions_en')
            ->removeColumn('placeholder')
            ->removeColumn('placeholder_en')
            ->removeColumn('is_enabled')
            ->removeColumn('is_required')
            ->removeColumn('allow_rotation')
            ->removeColumn('default_font')
            ->removeColumn('allowed_fonts')
            ->removeColumn('surcharge')
            ->update();

        $this->table('product_personalization_views')->drop()->save();
    }
}

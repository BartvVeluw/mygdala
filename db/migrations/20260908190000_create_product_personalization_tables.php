<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Product personalization / live engraving preview — the complete data model.
 *
 * Four tables, deliberately split along the one architectural line this
 * feature has to hold: personalization CONFIGURATION belongs to the product,
 * customer personalization DATA belongs to the order line. Nothing a customer
 * types or uploads is ever written back onto a `products` row.
 *
 *   product_personalization_settings  — one optional row per product: the
 *       master on/off switch, the dedicated preview base image and the
 *       optional help text. NO row at all is the default for every existing
 *       product, and "no row" reads as "personalization disabled", so this
 *       migration cannot change the behaviour of a single existing product.
 *
 *   product_personalization_zones     — the rectangle(s) on that preview
 *       image where customer content may appear, in RELATIVE percentages
 *       (never pixels), plus what is allowed inside each zone. Version 1
 *       always creates exactly one zone (`zone_key` = 'default'); the table
 *       is keyed UNIQUE(settings_id, zone_key) precisely so multiple zones
 *       (front/back, several engraving areas) become extra rows later
 *       instead of a schema redesign.
 *
 *   personalization_uploads           — one row per validated customer image
 *       upload. Rows start UNCLAIMED (`claimed_at` NULL) the moment the file
 *       is uploaded on the product page, and become claimed when the order
 *       that uses them is created. Unclaimed rows (and their files) are
 *       disposable — see App\Service\Personalization\PersonalizationUploadStorage
 *       and the opportunistic sweep in api/personalization-upload.php; no
 *       cron is required.
 *
 *   order_item_personalizations       — the immutable order-side record: the
 *       customer's exact text, a link to the retained original upload, the
 *       normalized transform, and a JSON snapshot of the product's
 *       personalization configuration as it was at the moment of purchase.
 *       Also keyed per zone, so a multi-zone order line is extra rows.
 *
 * MySQL 5.7 / PHP 8.2 compatible: no JSON column type (plain TEXT holding
 * json_encode() output), no generated columns, no defaults on TEXT. Every
 * foreign key column is `signed => false` to match Phinx's own unsigned `id`
 * columns — see MAIN.MD (2026-09-03) for what happens when it isn't.
 */
final class CreateProductPersonalizationTables extends AbstractMigration
{
    public function up(): void
    {
        // ---------------------------------------------------------------
        // Product-level configuration (one optional row per product)
        // ---------------------------------------------------------------
        $this->table('product_personalization_settings')
            ->addColumn('product_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('is_enabled', 'boolean', ['default' => false])
            // The ONE image the personalization preview is built on. Kept
            // separate from the product/variant gallery on purpose: the
            // engraving area is stored as percentages OF THIS IMAGE, so it
            // must never silently follow gallery ordering or a variant
            // switch. See MAIN.MD "Personalisatie".
            ->addColumn('preview_image_path', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('instructions', 'string', ['limit' => 500, 'null' => true])
            ->addColumn('instructions_en', 'string', ['limit' => 500, 'null' => true])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addForeignKey('product_id', 'products', 'id', [
                'delete' => 'CASCADE',
                'update' => 'CASCADE',
            ])
            ->addIndex(['product_id'], ['unique' => true])
            ->create();

        // ---------------------------------------------------------------
        // Engraving/personalization areas — relative coordinates only
        // ---------------------------------------------------------------
        $this->table('product_personalization_zones')
            ->addColumn('settings_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('zone_key', 'string', ['limit' => 32, 'default' => 'default'])
            ->addColumn('label', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('allow_text', 'boolean', ['default' => true])
            ->addColumn('allow_image', 'boolean', ['default' => true])
            ->addColumn('max_text_length', 'integer', ['default' => 30])
            // Percentages of the preview image's own box (0-100), so the
            // preview stays correct at every responsive size and on every
            // device. decimal(6,3) keeps a third decimal for a rectangle
            // dragged with a mouse without ever storing a float.
            ->addColumn('area_x', 'decimal', ['precision' => 6, 'scale' => 3, 'default' => '25.000'])
            ->addColumn('area_y', 'decimal', ['precision' => 6, 'scale' => 3, 'default' => '35.000'])
            ->addColumn('area_width', 'decimal', ['precision' => 6, 'scale' => 3, 'default' => '50.000'])
            ->addColumn('area_height', 'decimal', ['precision' => 6, 'scale' => 3, 'default' => '30.000'])
            ->addColumn('sort_order', 'integer', ['default' => 0])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addForeignKey('settings_id', 'product_personalization_settings', 'id', [
                'delete' => 'CASCADE',
                'update' => 'CASCADE',
            ])
            ->addIndex(['settings_id', 'zone_key'], ['unique' => true])
            ->create();

        // ---------------------------------------------------------------
        // Customer uploads (untrusted input, stored outside the webroot)
        // ---------------------------------------------------------------
        $this->table('personalization_uploads')
            // Unguessable public handle (32 hex chars). The only identifier
            // the browser ever sees; the physical filenames are derived from
            // it server-side and never leave the server.
            ->addColumn('token', 'string', ['limit' => 64, 'null' => false])
            // Which product the file was uploaded for, so a token can never
            // be replayed against a product that does not allow uploads.
            // Nullable so deleting a product never destroys order evidence.
            ->addColumn('product_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('stored_filename', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('preview_filename', 'string', ['limit' => 255, 'null' => false])
            // Display metadata only — never used to build a path.
            ->addColumn('original_filename', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('mime_type', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('image_width', 'integer', ['null' => true])
            ->addColumn('image_height', 'integer', ['null' => true])
            ->addColumn('byte_size', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('claimed_at', 'datetime', ['null' => true])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addForeignKey('product_id', 'products', 'id', [
                'delete' => 'SET NULL',
                'update' => 'CASCADE',
            ])
            ->addIndex(['token'], ['unique' => true])
            ->addIndex(['claimed_at', 'created_at'])
            ->create();

        // ---------------------------------------------------------------
        // Immutable order-side personalization
        // ---------------------------------------------------------------
        $this->table('order_item_personalizations')
            ->addColumn('order_item_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('zone_key', 'string', ['limit' => 32, 'default' => 'default'])
            ->addColumn('upload_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('text_value', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('transform_json', 'text', ['null' => true])
            // What the product's personalization configuration looked like at
            // the moment this order was placed. A later change to the
            // engraving area, the maximum text length or the preview image
            // must never rewrite what this customer actually submitted.
            ->addColumn('config_snapshot_json', 'text', ['null' => true])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addForeignKey('order_item_id', 'order_items', 'id', [
                'delete' => 'CASCADE',
                'update' => 'CASCADE',
            ])
            ->addForeignKey('upload_id', 'personalization_uploads', 'id', [
                'delete' => 'SET NULL',
                'update' => 'CASCADE',
            ])
            ->addIndex(['order_item_id', 'zone_key'], ['unique' => true])
            ->create();
    }

    public function down(): void
    {
        $this->table('order_item_personalizations')->drop()->save();
        $this->table('personalization_uploads')->drop()->save();
        $this->table('product_personalization_zones')->drop()->save();
        $this->table('product_personalization_settings')->drop()->save();
    }
}

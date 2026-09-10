<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Two additions to product personalization, both additive and both optional
 * for every row that already exists.
 *
 * ## 1. Composed preview snapshots (`personalization_preview_snapshots`)
 *
 * The CMS already RECONSTRUCTS a customer's preview from the structured data
 * stored with the order, and that reconstruction stays — it is reproducible,
 * verifiable and works for every historical order. What it cannot do is
 * hand the owner a single file to send to the laser software, and it can only
 * approximate a webfont the browser rendered.
 *
 * So the browser now also composes exactly what the customer saw, per VIEW
 * (front, back, ...), and posts it as a PNG. The server validates it like any
 * other upload — real PNG, size and dimension caps, re-encoded through GD so
 * the stored bytes are pixels this server produced — and keeps it beside the
 * customer's own upload in the same protected storage directory.
 *
 * This snapshot is deliberately SUPPLEMENTARY:
 *   - the structured personalization (text, font, colour, transform) and the
 *     customer's ORIGINAL upload remain the source of truth;
 *   - nothing replaces or rewrites `personalization_uploads`;
 *   - an order without a snapshot — every historical one — stays fully
 *     readable and still renders its reconstruction.
 *
 * Lifecycle mirrors `personalization_uploads` exactly, which is why the
 * columns look familiar: a row starts UNCLAIMED (`order_item_id` NULL) when
 * the browser posts it, and is CLAIMED inside the checkout transaction. An
 * unclaimed row is disposable and swept by the same opportunistic cleanup.
 * `order_item_id` + `view_key` is UNIQUE, so one order line can hold at most
 * one snapshot per view; MySQL allows many NULLs in a unique index, so the
 * unclaimed rows do not collide with each other.
 *
 * ## 2. Text colour (`order_item_personalizations.text_color`)
 *
 * The customer can now pick the colour their text is previewed in, from a
 * small fixed palette (App\Service\Personalization\PersonalizationColors) —
 * never an arbitrary CSS value. The chosen KEY is recorded on the order line
 * for the same reason the font key is: so the CMS shows what the customer
 * actually chose, years later. NULL on every existing row, which reads as
 * "the palette's default", exactly what those orders were rendered in.
 *
 * MySQL 5.7 / PHP 8.2 compatible: plain columns, no JSON column type, every
 * foreign-key column `signed => false` to match Phinx's own unsigned `id`.
 */
final class AddPreviewSnapshotsAndTextColor extends AbstractMigration
{
    public function up(): void
    {
        $this->table('personalization_preview_snapshots')
            // Unguessable public handle, the only identifier the browser ever
            // sees; the physical filename is derived from it server-side.
            ->addColumn('token', 'string', ['limit' => 64, 'null' => false])
            // Which product and which VIEW this composed image belongs to, so
            // a token can never be replayed onto a different product's order
            // line or presented as the wrong side of the product.
            ->addColumn('product_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('view_key', 'string', ['limit' => 32, 'null' => false])
            ->addColumn('stored_filename', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('mime_type', 'string', ['limit' => 100, 'default' => 'image/png'])
            ->addColumn('image_width', 'integer', ['null' => true])
            ->addColumn('image_height', 'integer', ['null' => true])
            ->addColumn('byte_size', 'integer', ['signed' => false, 'null' => true])
            // Claimed = this snapshot belongs to a placed order and is never
            // swept. NULL = still a draft the visitor may abandon.
            ->addColumn('order_item_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('claimed_at', 'datetime', ['null' => true])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addForeignKey('product_id', 'products', 'id', [
                'delete' => 'SET NULL',
                'update' => 'CASCADE',
            ])
            // Deleting an order removes its snapshots with it, the same way
            // order_item_personalizations already cascades.
            ->addForeignKey('order_item_id', 'order_items', 'id', [
                'delete' => 'CASCADE',
                'update' => 'CASCADE',
            ])
            ->addIndex(['token'], ['unique' => true])
            ->addIndex(['order_item_id', 'view_key'], ['unique' => true])
            ->addIndex(['claimed_at', 'created_at'])
            ->create();

        $this->table('order_item_personalizations')
            ->addColumn('text_color', 'string', ['limit' => 32, 'null' => true])
            ->update();
    }

    public function down(): void
    {
        $this->table('order_item_personalizations')
            ->removeColumn('text_color')
            ->update();

        $this->table('personalization_preview_snapshots')->drop()->save();
    }
}

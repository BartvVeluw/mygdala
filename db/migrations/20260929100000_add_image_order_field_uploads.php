<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Shop Admin UX & Order Fields 2.0: the sixth kind of order question,
 * "Afbeelding uploaden" — the customer sends ONE picture with a product (a
 * pet, a logo, a design to engrave). App\Service\OrderFields\*, MODULES.md
 * "Bestelvelden".
 *
 *   product_order_fields.max_file_size_mb   an image question's own limit, one
 *                                            of 2, 5 or 10 MB; NULL for every
 *                                            other question (and for an image
 *                                            question: the default). Never
 *                                            above what PHP accepts.
 *   order_field_uploads                      one PRIVATE customer picture. The
 *                                            file itself sits outside the
 *                                            webroot (OrderFieldUploadStorage),
 *                                            never in the database and never in
 *                                            the Media Library; this row is its
 *                                            metadata and its state:
 *
 *     TEMPORARY  claimed_at NULL, order_item_field_id NULL: uploaded from a
 *                product page, known to the browser only by a random token of
 *                which this row keeps the SHA-256 (`token_hash`). Bound to the
 *                product and the question it was uploaded for, and gone after
 *                `expires_at` (the sweep deletes row and files).
 *     CLAIMED    claimed_at and order_item_field_id set, once, in the
 *                checkout's transaction: from then on it is order data, next
 *                to the answer's snapshot row, and never swept.
 *
 * product_id is SET NULL with its product (a claimed picture stays with its
 * order; a temporary one then no longer fits any question and expires).
 * field_id points at the question for reference only, like
 * order_item_fields.field_id: no foreign key. order_item_field_id is
 * RESTRICT: a snapshot row cannot disappear while its picture's file still
 * exists — nothing in Mygdala deletes orders, and if something ever does it
 * must delete the files first, so no private file is ever orphaned.
 *
 * NEW TABLE AND ONE NULLABLE COLUMN, nothing rewritten; every existing
 * question keeps NULL, every existing order has no picture. No filesystem
 * work here. Idempotent: the column and the table are checked first.
 */
final class AddImageOrderFieldUploads extends AbstractMigration
{
    public function up(): void
    {
        $fields = $this->table('product_order_fields');
        if (!$fields->hasColumn('max_file_size_mb')) {
            $fields->addColumn('max_file_size_mb', 'integer', [
                'limit' => \Phinx\Db\Adapter\MysqlAdapter::INT_TINY,
                'signed' => false,
                'null' => true,
                'default' => null,
                'after' => 'max_length',
                'comment' => 'image only: 2, 5 or 10; NULL = the default (App\\Service\\OrderFields\\OrderFieldUploadPolicy)',
            ])->update();
        }

        if (!$this->hasTable('order_field_uploads')) {
            $this->table('order_field_uploads', ['id' => true])
                ->addColumn('token_hash', 'char', ['limit' => 64, 'null' => false, 'encoding' => 'ascii', 'collation' => 'ascii_bin', 'comment' => 'SHA-256 of the browser\'s token; the token itself is never stored'])
                ->addColumn('product_id', 'integer', ['signed' => false, 'null' => true, 'default' => null])
                ->addColumn('field_id', 'integer', ['signed' => false, 'null' => false, 'comment' => 'product_order_fields.id it was uploaded for; reference only'])
                ->addColumn('storage_name', 'char', ['limit' => 32, 'null' => false, 'encoding' => 'ascii', 'collation' => 'ascii_bin', 'comment' => 'random, unrelated to the token and to the customer\'s filename'])
                ->addColumn('extension', 'string', ['limit' => 5, 'null' => false, 'encoding' => 'ascii', 'collation' => 'ascii_bin'])
                ->addColumn('original_filename', 'string', ['limit' => 200, 'null' => false, 'comment' => 'display only, sanitized'])
                ->addColumn('mime_type', 'string', ['limit' => 40, 'null' => false])
                ->addColumn('byte_size', 'integer', ['signed' => false, 'null' => false])
                ->addColumn('image_width', 'integer', ['signed' => false, 'null' => false])
                ->addColumn('image_height', 'integer', ['signed' => false, 'null' => false])
                ->addColumn('created_at', 'datetime', ['null' => false])
                ->addColumn('expires_at', 'datetime', ['null' => false])
                ->addColumn('claimed_at', 'datetime', ['null' => true, 'default' => null])
                ->addColumn('order_item_field_id', 'integer', ['signed' => false, 'null' => true, 'default' => null])
                ->addIndex(['token_hash'], ['unique' => true, 'name' => 'uq_order_field_uploads_token'])
                ->addIndex(['storage_name'], ['unique' => true, 'name' => 'uq_order_field_uploads_storage'])
                ->addIndex(['order_item_field_id'], ['unique' => true, 'name' => 'uq_order_field_uploads_answer'])
                ->addIndex(['claimed_at', 'expires_at'], ['name' => 'idx_order_field_uploads_sweep'])
                ->addForeignKey('product_id', 'products', 'id', ['delete' => 'SET_NULL', 'update' => 'CASCADE', 'constraint' => 'fk_order_field_uploads_product'])
                ->addForeignKey('order_item_field_id', 'order_item_fields', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE', 'constraint' => 'fk_order_field_uploads_answer'])
                ->create();
        }
    }

    /** Forward-only (db/migrations/CLAUDE.md). */
    public function down(): void
    {
    }
}

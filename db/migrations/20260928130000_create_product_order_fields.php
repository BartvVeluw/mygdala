<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Shop Product & Ordering 2.0, bestelvelden: a product can ask the customer
 * for order details before it goes in the cart — "Naam op het bord" — and
 * the answers become part of the order (App\Service\OrderFields\*,
 * MODULES.md "Bestelvelden"). Separate from Personalisatie, which places
 * text and pictures on a preview image; these are plain answers.
 *
 *   products.order_fields_enabled   "Bestelgegevens vragen", 0 for every
 *                                   existing product
 *   product_order_fields            one question: its type (text, textarea,
 *                                   radio, select, checkbox), required or
 *                                   not, an optional maximum length, order
 *   product_order_field_translations          label and help text per
 *                                             website language
 *   product_order_field_options               the choices of a radio or
 *                                             select question, in order
 *   product_order_field_option_translations   a choice's label per language
 *   order_item_fields               THE SNAPSHOT on an order line: the
 *                                   question's label and type and the answer
 *                                   as they were at checkout. field_id and
 *                                   option_id only point back for reference
 *                                   (no foreign key): a question deleted or
 *                                   renamed later never changes an order.
 *
 * The four product tables go with their product (and each other) on delete;
 * the translation tables have the shape every typed translation table has
 * (App\Service\Language\TranslationTable). The snapshot goes with its order
 * line.
 *
 * NEW TABLES AND ONE COLUMN, nothing rewritten: every existing product asks
 * nothing, every existing order line has no answers. No fresh-install guard;
 * idempotent: every table and the column are checked first.
 */
final class CreateProductOrderFields extends AbstractMigration
{
    public function up(): void
    {
        $products = $this->table('products');
        if (!$products->hasColumn('order_fields_enabled')) {
            $products->addColumn('order_fields_enabled', 'boolean', [
                'default' => false,
                'null' => false,
                'after' => 'purchase_mode',
                'comment' => '1 = Bestelgegevens vragen: the product_order_fields are asked before it goes in the cart',
            ])->update();
        }

        if (!$this->hasTable('product_order_fields')) {
            $this->table('product_order_fields', ['id' => true])
                ->addColumn('product_id', 'integer', ['signed' => false, 'null' => false])
                ->addColumn('field_type', 'string', ['limit' => 20, 'null' => false, 'comment' => 'text, textarea, radio, select or checkbox (App\\Service\\OrderFields\\OrderFieldType)'])
                ->addColumn('is_required', 'boolean', ['default' => false, 'null' => false])
                ->addColumn('max_length', 'integer', ['signed' => false, 'null' => true, 'default' => null, 'comment' => 'text and textarea only; NULL = the type\'s own limit'])
                ->addColumn('sort_order', 'integer', ['default' => 0, 'null' => false])
                ->addColumn('created_at', 'datetime', ['null' => true])
                ->addColumn('updated_at', 'datetime', ['null' => true])
                ->addIndex(['product_id', 'sort_order'], ['name' => 'idx_product_order_fields_product'])
                ->addForeignKey('product_id', 'products', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_product_order_fields_product'])
                ->create();
        }

        $this->translationTable('product_order_field_translations', 'field_id', 'product_order_fields', 'fk_product_order_field_translations', [
            ['label', 150],
            ['help_text', 500],
        ]);

        if (!$this->hasTable('product_order_field_options')) {
            $this->table('product_order_field_options', ['id' => true])
                ->addColumn('field_id', 'integer', ['signed' => false, 'null' => false])
                ->addColumn('sort_order', 'integer', ['default' => 0, 'null' => false])
                ->addColumn('created_at', 'datetime', ['null' => true])
                ->addColumn('updated_at', 'datetime', ['null' => true])
                ->addIndex(['field_id', 'sort_order'], ['name' => 'idx_product_order_field_options_field'])
                ->addForeignKey('field_id', 'product_order_fields', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_product_order_field_options_field'])
                ->create();
        }

        $this->translationTable('product_order_field_option_translations', 'option_id', 'product_order_field_options', 'fk_product_order_field_option_translations', [
            ['label', 150],
        ]);

        if (!$this->hasTable('order_item_fields')) {
            $this->table('order_item_fields', ['id' => true])
                ->addColumn('order_item_id', 'integer', ['signed' => false, 'null' => false])
                ->addColumn('field_id', 'integer', ['signed' => false, 'null' => true, 'default' => null, 'comment' => 'The question it answered, for reference only: no foreign key, the snapshot stands on its own'])
                ->addColumn('field_type', 'string', ['limit' => 20, 'null' => false])
                ->addColumn('label', 'string', ['limit' => 150, 'null' => false, 'comment' => 'The question as it was asked, in the default website language'])
                ->addColumn('value', 'text', ['null' => false, 'comment' => 'The answer as given; a choice as its label in the default language'])
                ->addColumn('option_id', 'integer', ['signed' => false, 'null' => true, 'default' => null])
                ->addColumn('sort_order', 'integer', ['default' => 0, 'null' => false])
                ->addColumn('created_at', 'datetime', ['null' => true])
                ->addIndex(['order_item_id', 'sort_order'], ['name' => 'idx_order_item_fields_item'])
                ->addForeignKey('order_item_id', 'order_items', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_order_item_fields_item'])
                ->create();
        }
    }

    /**
     * A typed translation table (App\Service\Language\TranslationTable):
     * id, owner, language_code, fields, timestamps; unique per owner and
     * language; the owner cascades, the language is RESTRICT.
     *
     * @param list<array{0: string, 1: int}> $fields name, length
     */
    private function translationTable(string $name, string $ownerColumn, string $ownerTable, string $constraintPrefix, array $fields): void
    {
        if ($this->hasTable($name)) {
            return;
        }

        $table = $this->table($name, ['id' => true])
            ->addColumn($ownerColumn, 'integer', ['signed' => false, 'null' => false])
            ->addColumn('language_code', 'string', [
                'limit' => 12,
                'null' => false,
                'encoding' => 'ascii',
                'collation' => 'ascii_bin',
                'comment' => 'site_languages.code',
            ]);

        foreach ($fields as [$field, $length]) {
            $table->addColumn($field, 'string', ['limit' => $length, 'null' => true, 'default' => null]);
        }

        $table->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex([$ownerColumn, 'language_code'], ['unique' => true, 'name' => 'uq_' . $name . '_owner_language'])
            ->addIndex(['language_code'], ['name' => 'idx_' . $name . '_language'])
            ->addForeignKey($ownerColumn, $ownerTable, 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => $constraintPrefix . '_owner'])
            ->addForeignKey('language_code', 'site_languages', 'code', ['delete' => 'RESTRICT', 'update' => 'RESTRICT', 'constraint' => $constraintPrefix . '_language'])
            ->create();
    }

    /** Forward-only (db/migrations/CLAUDE.md). */
    public function down(): void
    {
    }
}

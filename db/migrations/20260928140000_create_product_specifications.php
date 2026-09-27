<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Shop Product & Ordering 2.0, specificaties: a library of product
 * properties the owner reuses — Dikte, Hoogte, Materiaal — and their values
 * per product (App\Service\ProductSpecifications, MODULES.md
 * "Specificaties"). Presentation only: no filter, no search, no comparison.
 *
 *   product_specifications                    one property: an optional short
 *                                             unit ("mm", "kg") and its place
 *                                             in the list
 *   product_specification_translations        its name per website language
 *   product_specification_values              one property on one product,
 *                                             once, in the product's own order
 *   product_specification_value_translations  the value per language ("Berken
 *                                             multiplex" / "Birch plywood");
 *                                             a number is typed once and
 *                                             falls back
 *
 * A property deleted from the library takes its values off every product
 * (the owner is told how many first); a product deleted takes its values
 * with it. The translation tables have the shape of every typed translation
 * table (App\Service\Language\TranslationTable).
 *
 * NEW TABLES, nothing rewritten: no existing product has a specification.
 * No fresh-install guard; idempotent: every table is checked first.
 */
final class CreateProductSpecifications extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('product_specifications')) {
            $this->table('product_specifications', ['id' => true])
                ->addColumn('unit', 'string', ['limit' => 20, 'null' => true, 'default' => null, 'comment' => 'Optional short unit shown after a value, e.g. mm, cm, kg'])
                ->addColumn('sort_order', 'integer', ['default' => 0, 'null' => false])
                ->addColumn('created_at', 'datetime', ['null' => true])
                ->addColumn('updated_at', 'datetime', ['null' => true])
                ->create();
        }

        $this->translationTable('product_specification_translations', 'specification_id', 'product_specifications', 'fk_product_specification_translations', 'name', 100);

        if (!$this->hasTable('product_specification_values')) {
            $this->table('product_specification_values', ['id' => true])
                ->addColumn('product_id', 'integer', ['signed' => false, 'null' => false])
                ->addColumn('specification_id', 'integer', ['signed' => false, 'null' => false])
                ->addColumn('sort_order', 'integer', ['default' => 0, 'null' => false])
                ->addColumn('created_at', 'datetime', ['null' => true])
                ->addColumn('updated_at', 'datetime', ['null' => true])
                ->addIndex(['product_id', 'specification_id'], ['unique' => true, 'name' => 'uq_product_specification_values_product_spec'])
                ->addIndex(['specification_id'], ['name' => 'idx_product_specification_values_spec'])
                ->addForeignKey('product_id', 'products', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_product_specification_values_product'])
                ->addForeignKey('specification_id', 'product_specifications', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_product_specification_values_spec'])
                ->create();
        }

        $this->translationTable('product_specification_value_translations', 'value_id', 'product_specification_values', 'fk_product_specification_value_translations', 'value', 255);
    }

    private function translationTable(string $name, string $ownerColumn, string $ownerTable, string $constraintPrefix, string $field, int $length): void
    {
        if ($this->hasTable($name)) {
            return;
        }

        $this->table($name, ['id' => true])
            ->addColumn($ownerColumn, 'integer', ['signed' => false, 'null' => false])
            ->addColumn('language_code', 'string', [
                'limit' => 12,
                'null' => false,
                'encoding' => 'ascii',
                'collation' => 'ascii_bin',
                'comment' => 'site_languages.code',
            ])
            ->addColumn($field, 'string', ['limit' => $length, 'null' => true, 'default' => null])
            ->addColumn('created_at', 'datetime', ['null' => true])
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

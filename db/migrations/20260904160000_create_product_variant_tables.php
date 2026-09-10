<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Generic product variant support: a product can optionally have one or more
 * option groups (e.g. "Kleur"), each with one or more values (e.g. "Noten",
 * "Berken"). A variant is a purchasable combination of exactly one value per
 * option group, with an optional price/image override. No fixed columns like
 * color_1/color_2 — adding another option (e.g. "Maat") later needs no schema
 * change. See MAIN.MD.
 */
final class CreateProductVariantTables extends AbstractMigration
{
    public function change(): void
    {
        $this->table('product_options')
            ->addColumn('product_id', 'integer', ['signed' => false])
            ->addColumn('name', 'string', ['limit' => 100])
            ->addColumn('sort_order', 'integer', ['default' => 0])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addForeignKey('product_id', 'products', 'id', [
                'delete' => 'CASCADE',
                'update' => 'CASCADE',
            ])
            ->create();

        $this->table('product_option_values')
            ->addColumn('product_option_id', 'integer', ['signed' => false])
            ->addColumn('value', 'string', ['limit' => 100])
            ->addColumn('sort_order', 'integer', ['default' => 0])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addForeignKey('product_option_id', 'product_options', 'id', [
                'delete' => 'CASCADE',
                'update' => 'CASCADE',
            ])
            ->create();

        // A variant is the purchasable combination. price/image_path are
        // optional overrides of the parent product's own price/main photo.
        $this->table('product_variants')
            ->addColumn('product_id', 'integer', ['signed' => false])
            ->addColumn('price', 'decimal', ['precision' => 10, 'scale' => 2, 'null' => true])
            ->addColumn('image_path', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('active', 'boolean', ['default' => true])
            ->addColumn('sort_order', 'integer', ['default' => 0])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addForeignKey('product_id', 'products', 'id', [
                'delete' => 'CASCADE',
                'update' => 'CASCADE',
            ])
            ->create();

        // Junction: which option value each variant carries for each option
        // group. RESTRICT on the value side so a value that's part of an
        // existing variant can't be silently deleted out from under it.
        $this->table('product_variant_values', ['id' => false, 'primary_key' => ['variant_id', 'product_option_value_id']])
            ->addColumn('variant_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('product_option_value_id', 'integer', ['signed' => false, 'null' => false])
            ->addForeignKey('variant_id', 'product_variants', 'id', [
                'delete' => 'CASCADE',
                'update' => 'CASCADE',
            ])
            ->addForeignKey('product_option_value_id', 'product_option_values', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'CASCADE',
            ])
            ->create();
    }
}

<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * order_items records which variant (if any) was purchased. variant_label is
 * a text snapshot (e.g. "Kleur: Noten"), same idea as the existing unit_price
 * snapshot: it keeps historical orders understandable even if the option/
 * value is later renamed. RESTRICT on variant_id mirrors the existing
 * product_id guard — a variant that's on an order can't be deleted.
 */
final class AddVariantToOrderItems extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('order_items');
        $table
            ->addColumn('variant_id', 'integer', ['signed' => false, 'null' => true, 'after' => 'product_id'])
            ->addColumn('variant_label', 'string', ['limit' => 255, 'null' => true, 'after' => 'variant_id'])
            ->addForeignKey('variant_id', 'product_variants', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'CASCADE',
            ])
            ->update();
    }
}

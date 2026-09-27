<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Shop Product & Ordering 2.0, op aanvraag: how a product is sold
 * (`products.purchase_mode`, App\Service\PurchaseMode, MODULES.md "Op
 * aanvraag"):
 *
 *   'direct'   Direct bestellen: price, quantity and the cart, as every
 *              product was sold until now
 *   'inquiry'  Op aanvraag: visible with its name, pictures, description,
 *              variants and specifications, but no price anywhere and no way
 *              into the cart — the server refuses it too
 *
 * ADD-ONLY. One column, 'direct' for every existing product (the default
 * fills them), so every product is sold exactly as before. No row is
 * rewritten, no fresh-install guard, and idempotent: the column is checked
 * first.
 */
final class GiveProductsAPurchaseMode extends AbstractMigration
{
    public function up(): void
    {
        if ($this->table('products')->hasColumn('purchase_mode')) {
            return;
        }

        $this->table('products')
            ->addColumn('purchase_mode', 'string', [
                'limit' => 10,
                'null' => false,
                'default' => 'direct',
                'after' => 'in_personalization_catalog',
                'comment' => "'direct' (Direct bestellen) or 'inquiry' (Op aanvraag: no price, no cart)",
            ])
            ->update();
    }

    /** Forward-only (db/migrations/CLAUDE.md). */
    public function down(): void
    {
    }
}

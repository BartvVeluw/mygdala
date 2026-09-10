<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Removes the eyebrow/eyebrow_en feature (see MAIN.MD) — never used for real
 * product data. Kept as its own migration rather than editing the original
 * 20260903170247_add_translatable_fields_to_products.php, which has already
 * been applied.
 */
final class RemoveEyebrowFromProducts extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('products');
        $table
            ->removeColumn('eyebrow')
            ->removeColumn('eyebrow_en')
            ->update();
    }
}

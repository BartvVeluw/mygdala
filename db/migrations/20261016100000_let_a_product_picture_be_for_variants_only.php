<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Variant-only product pictures (v0.1.15 phase 12.1, MODULES.md "Shop"):
 * a picture of the product's one pool (`product_images`) can be marked as
 * meant for variants only.
 *
 *   variant_only  0 — a general picture: in the product's gallery, may also
 *                     be linked to variants (product_variant_images);
 *                 1 — only shown through a variant that links to it; never
 *                     in the general gallery, never the primary picture.
 *
 * EVERY EXISTING PICTURE STAYS GENERAL: the default is 0 and no row is
 * rewritten. Being linked to a variant today says nothing about being meant
 * for variants only, so nothing is inferred from product_variant_images.
 *
 * Idempotent: the column is checked first. Forward-only (db/migrations/CLAUDE.md).
 */
final class LetAProductPictureBeForVariantsOnly extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->table('product_images')->hasColumn('variant_only')) {
            $this->table('product_images')
                ->addColumn('variant_only', 'boolean', [
                    'null' => false,
                    'default' => false,
                    'after' => 'is_primary',
                    'comment' => '1: only shown through a variant that links to it',
                ])
                ->update();
        }
    }

    /** Forward-only (db/migrations/CLAUDE.md). */
    public function down(): void
    {
    }
}

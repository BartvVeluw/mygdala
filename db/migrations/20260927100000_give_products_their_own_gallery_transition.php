<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Product Gallery 2.0: a product may name how its gallery changes picture
 * (`products.gallery_transition`, App\Service\ProductGalleryTransition):
 *
 *   NULL    follow the Shop's default (site_settings.shop_gallery_transition,
 *           Shop-instellingen) — every existing product, and every new one
 *           until the editor chooses otherwise
 *   'none'  the next picture is simply there
 *   'fade'  the pictures cross-fade
 *   'slide' the next picture slides in
 *
 * ADD-ONLY. One nullable column and nothing written: every product keeps
 * following the Shop, and the Shop's default needs no row of its own
 * (App\Service\SiteSettings falls back to 'fade', which is what the gallery
 * already did). No picture, order or primary picture is touched.
 *
 * Why NULL rather than a copy of today's default: a product that follows the
 * Shop must keep following it when the default changes later.
 *
 * No fresh-install guard: a new installation and an upgraded one end on the
 * same table (db/migrations/CLAUDE.md). Idempotent: the column is checked
 * first.
 */
final class GiveProductsTheirOwnGalleryTransition extends AbstractMigration
{
    public function up(): void
    {
        if ($this->table('products')->hasColumn('gallery_transition')) {
            return;
        }

        $this->table('products')
            ->addColumn('gallery_transition', 'string', [
                'limit' => 10,
                'null' => true,
                'default' => null,
                'after' => 'requires_parcel',
                'comment' => 'App\Service\ProductGalleryTransition::ALL; NULL = the Shop default',
            ])
            ->update();
    }

    public function down(): void
    {
        if ($this->table('products')->hasColumn('gallery_transition')) {
            $this->table('products')->removeColumn('gallery_transition')->update();
        }
    }
}

<?php

declare(strict_types=1);

use Phinx\Seed\AbstractSeed;

final class ProductSeeder extends AbstractSeed
{
    public function run(): void
    {
        $now = date('Y-m-d H:i:s');

        // LET OP: dit zijn TEST-producten (nep/fictief), alleen om de webshop-flow
        // (lijst, kaarten, afbeeldingen, NL/EN-fallback) te kunnen testen.
        // Geen echte catalogusdata — zie MAIN.MD "Testcatalogus" voor uitleg.
        $products = [
            [
                'name' => 'Snijplank - Just Married',
                'slug' => 'snijplank-just-married-test',
                'description' => 'Houten snijplank met "Just Married" gravure, leuk als bruiloftscadeau. (testproduct)',
                'name_en' => null,
                'description_en' => null,
                'price' => 34.95,
                'image_path' => 'assets/images/snijplank-just-married.webp',
                'stock' => 10,
                'active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Naambordje - Olifant',
                'slug' => 'naambordje-olifant-test',
                'description' => 'Vrolijk gegraveerd naambordje in olifantvorm voor op de kinderkamerdeur. (testproduct)',
                'name_en' => null,
                'description_en' => null,
                'price' => 14.50,
                'image_path' => 'assets/images/naambordje-olifant.webp',
                'stock' => 18,
                'active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Naambordje - Dinosaurus',
                'slug' => 'naambordje-dinosaurus-test',
                'description' => 'Gepersonaliseerd naambordje in dinosaurusvorm, inclusief eigen naam. (testproduct)',
                'name_en' => null,
                'description_en' => null,
                'price' => 14.50,
                'image_path' => 'assets/images/naambordje-dinosaurus.webp',
                'stock' => 18,
                'active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Visitekaartje - Aluminium',
                'slug' => 'visitekaartje-aluminium-test',
                'description' => 'Stoer metalen visitekaartje met lasergravure, opvallend en duurzaam. (testproduct)',
                'name_en' => null,
                'description_en' => null,
                'price' => 4.25,
                'image_path' => 'assets/images/hero-visitekaartje-aluminium.webp',
                'stock' => 100,
                'active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Skyline Nijmegen - Wanddecoratie',
                'slug' => 'skyline-nijmegen-hout-test',
                'description' => 'Houten wanddecoratie met de skyline van Nijmegen, gelaserd op massief hout. (testproduct)',
                'name_en' => null,
                'description_en' => null,
                'price' => 49.00,
                'image_path' => 'assets/images/skyline-nijmegen-hout.webp',
                'stock' => 6,
                'active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Hartvormige Urn-ketting',
                'slug' => 'urn-hartvormig-ketting-test',
                'description' => 'Kleine gegraveerde herinneringsketting in hartvorm, met ruimte voor een naam of datum. (testproduct)',
                'name_en' => null,
                'description_en' => null,
                'price' => 22.95,
                'image_path' => 'assets/images/urn-hartvormig-ketting.webp',
                'stock' => 12,
                'active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Sleutelhanger - Acryl',
                'slug' => 'sleutelhanger-acryl-test',
                'description' => 'Transparante acryl sleutelhanger met lasergravure, naar keuze gepersonaliseerd. (testproduct)',
                'name_en' => null,
                'description_en' => null,
                'price' => 8.50,
                // Bewust geen afbeelding: test hiermee de fallback-icoon weergave.
                'image_path' => null,
                'stock' => 25,
                'active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        $this->table('products')->insert($products)->saveData();

        // Mirror each seeded products.image_path into a primary
        // product_images row too, same as api/admin/_product_image_helpers.php
        // does for admin-created products — the multi-image system, not the
        // legacy column, is what shop.html/product.html actually rely on.
        $slugs = array_column($products, 'slug');
        $pdo = $this->getAdapter()->getConnection();
        $placeholders = implode(',', array_fill(0, count($slugs), '?'));
        $stmt = $pdo->prepare("SELECT id, image_path FROM products WHERE slug IN ({$placeholders})");
        $stmt->execute($slugs);

        $images = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($row['image_path'] === null || $row['image_path'] === '') {
                continue;
            }

            $images[] = [
                'product_id' => $row['id'],
                'image_path' => $row['image_path'],
                'sort_order' => 0,
                'is_primary' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($images !== []) {
            $this->table('product_images')->insert($images)->saveData();
        }
    }
}

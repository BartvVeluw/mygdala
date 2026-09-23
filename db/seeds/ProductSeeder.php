<?php

declare(strict_types=1);

use Phinx\Seed\AbstractSeed;

final class ProductSeeder extends AbstractSeed
{
    public function run(): void
    {
        $now = date('Y-m-d H:i:s');

        // TEST products (fictional), only there to exercise the shop flow:
        // the list, the cards, the images and the empty-image fallback. Not
        // real catalogue data. A product's name and description are words in
        // a website language (product_translations, Multilingual 2.0), so
        // they are written below in the website's default language only; an
        // editor adds the other languages in the admin.
        $products = [
            [
                'slug' => 'snijplank-just-married-test',
                'name' => 'Snijplank - Just Married',
                'description' => 'Houten snijplank met "Just Married" gravure, leuk als bruiloftscadeau. (testproduct)',
                'price' => 34.95,
                'image_path' => 'assets/images/snijplank-just-married.webp',
                'stock' => 10,
            ],
            [
                'slug' => 'naambordje-olifant-test',
                'name' => 'Naambordje - Olifant',
                'description' => 'Vrolijk gegraveerd naambordje in olifantvorm voor op de kinderkamerdeur. (testproduct)',
                'price' => 14.50,
                'image_path' => 'assets/images/naambordje-olifant.webp',
                'stock' => 18,
            ],
            [
                'slug' => 'naambordje-dinosaurus-test',
                'name' => 'Naambordje - Dinosaurus',
                'description' => 'Gepersonaliseerd naambordje in dinosaurusvorm, inclusief eigen naam. (testproduct)',
                'price' => 14.50,
                'image_path' => 'assets/images/naambordje-dinosaurus.webp',
                'stock' => 18,
            ],
            [
                'slug' => 'visitekaartje-aluminium-test',
                'name' => 'Visitekaartje - Aluminium',
                'description' => 'Stoer metalen visitekaartje met lasergravure, opvallend en duurzaam. (testproduct)',
                'price' => 4.25,
                'image_path' => 'assets/images/hero-visitekaartje-aluminium.webp',
                'stock' => 100,
            ],
            [
                'slug' => 'skyline-nijmegen-hout-test',
                'name' => 'Skyline Nijmegen - Wanddecoratie',
                'description' => 'Houten wanddecoratie met de skyline van Nijmegen, gelaserd op massief hout. (testproduct)',
                'price' => 49.00,
                'image_path' => 'assets/images/skyline-nijmegen-hout.webp',
                'stock' => 6,
            ],
            [
                'slug' => 'urn-hartvormig-ketting-test',
                'name' => 'Hartvormige Urn-ketting',
                'description' => 'Kleine gegraveerde herinneringsketting in hartvorm, met ruimte voor een naam of datum. (testproduct)',
                'price' => 22.95,
                'image_path' => 'assets/images/urn-hartvormig-ketting.webp',
                'stock' => 12,
            ],
            [
                'slug' => 'sleutelhanger-acryl-test',
                'name' => 'Sleutelhanger - Acryl',
                'description' => 'Transparante acryl sleutelhanger met lasergravure, naar keuze gepersonaliseerd. (testproduct)',
                'price' => 8.50,
                // Deliberately no image: shows the fallback icon.
                'image_path' => null,
                'stock' => 25,
            ],
        ];

        $pdo = $this->getAdapter()->getConnection();
        $language = (string) ($pdo->query('SELECT code FROM site_languages WHERE is_default = 1 LIMIT 1')->fetchColumn() ?: 'nl');

        $insertProduct = $pdo->prepare(
            'INSERT INTO products (slug, price, image_path, stock, active, created_at, updated_at)
             VALUES (:slug, :price, :image_path, :stock, 1, :created_at, :updated_at)'
        );
        $insertWords = $pdo->prepare(
            'INSERT INTO product_translations (product_id, language_code, name, description, created_at, updated_at)
             VALUES (:product_id, :language_code, :name, :description, :created_at, :updated_at)'
        );
        // Mirrors the primary image into product_images too, as
        // App\Service\ProductGallery does for a product made in the admin:
        // the product's pool of pictures is what the shop pages read.
        $insertImage = $pdo->prepare(
            'INSERT INTO product_images (product_id, image_path, sort_order, is_primary) VALUES (:product_id, :image_path, 0, 1)'
        );

        foreach ($products as $product) {
            $insertProduct->execute([
                'slug' => $product['slug'],
                'price' => $product['price'],
                'image_path' => $product['image_path'],
                'stock' => $product['stock'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $productId = (int) $pdo->lastInsertId();

            $insertWords->execute([
                'product_id' => $productId,
                'language_code' => $language,
                'name' => $product['name'],
                'description' => $product['description'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($product['image_path'] !== null) {
                $insertImage->execute(['product_id' => $productId, 'image_path' => $product['image_path']]);
            }
        }
    }
}

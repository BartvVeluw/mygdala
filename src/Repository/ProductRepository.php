<?php

namespace App\Repository;

/**
 * All product-related SQL lives here.
 */
class ProductRepository extends Repository
{
    /**
     * Active products for the public shop.
     *
     * With $collectionId given, only the products in that collection, in
     * that collection's own order (`collection_products.sort_order` — see
     * App\Repository\CollectionRepository). Deliberately an optional filter
     * on the one existing query rather than a second method: the shop grid
     * and a collection page then can never drift apart on which columns a
     * product card gets, and there stays exactly one place that knows the
     * public product column list.
     *
     * With $productIds given, only those products, in exactly that order —
     * what a Related Products section and the public Personalisatie page ask
     * for (see App\Service\RelatedProductsContent,
     * App\Service\Personalization\PersonalizationCatalog and
     * api/products.php's ?ids=). The `active = 1` filter still applies, so a
     * submitted id list can only ever narrow the public catalogue, never
     * widen it: an inactive or deleted product is silently absent from the
     * result rather than an error. An empty list means "nothing selected"
     * and returns [] without touching the database.
     *
     * The ids path deliberately does NOT filter on `in_shop`: its callers
     * have already decided which products they are listing, and one of them
     * is the Personalisatie page, whose whole point is products that are not
     * in the shop. Related products cannot leak a hidden product through it
     * either, because the ids it passes came out of the collection query
     * below, which does filter.
     *
     * $collectionId and $productIds are alternative filters; passing both is
     * not a supported combination and $productIds wins.
     *
     * @param array<int, mixed>|null $productIds
     *
     * @return array<int, array<string, mixed>>
     */
    public function findAllActive(?int $collectionId = null, ?array $productIds = null): array
    {
        if ($productIds !== null) {
            $ids = array_values(array_unique(array_map('intval', $productIds)));
            if ($ids === []) {
                return [];
            }

            // FIELD(id, ...) reproduces the caller's order — the order the
            // admin dragged the products into, which the caller resolved
            // from the database before building this list.
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $this->db->prepare(
                "SELECT id, slug, price, image_path, stock
                 FROM products
                 WHERE active = 1 AND id IN ({$placeholders})
                 ORDER BY FIELD(id, {$placeholders})"
            );
            $stmt->execute(array_merge($ids, $ids));

            return $stmt->fetchAll();
        }

        if ($collectionId !== null) {
            $stmt = $this->db->prepare(
                'SELECT p.id, p.slug, p.price, p.image_path, p.stock
                 FROM products p
                 INNER JOIN collection_products cp
                    ON cp.product_id = p.id AND cp.collection_id = :collection_id
                 WHERE p.active = 1 AND p.in_shop = 1
                 ORDER BY cp.sort_order ASC, p.id ASC'
            );
            $stmt->execute(['collection_id' => $collectionId]);

            return $stmt->fetchAll();
        }

        $stmt = $this->db->prepare(
            'SELECT id, slug, price, image_path, stock
             FROM products
             WHERE active = 1 AND in_shop = 1
             ORDER BY id ASC'
        );
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * The products the public Personalisatie page lists: publicly visible AND
     * flagged for that catalogue. Ordered by id, because this page has no
     * curated order of its own the way a collection has — and since
     * Multilingual 2.0 phase 5 wave C a name is not a column to sort on, so
     * ordering alphabetically here would mean ordering by ONE language's
     * words and shuffling the page on every other one.
     *
     * Whether each of them can ACTUALLY be personalized (an enabled
     * configuration with a preview image and a usable zone) is not decided
     * here — that is App\Service\Personalization\ProductPersonalizationContent's
     * job, and the catalogue service filters on it. Keeping the two apart is
     * what stops this repository from having to know the personalization
     * rules.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findPersonalizationCatalog(): array
    {
        $stmt = $this->db->query(
            'SELECT id, slug, price, image_path
             FROM products
             WHERE active = 1 AND in_personalization_catalog = 1
             ORDER BY id ASC'
        );

        return $stmt->fetchAll();
    }

    /**
     * One publicly visible product — a personalization-only one included:
     * such a product keeps its own product page, which is exactly where the
     * Personalisatie catalogue links to.
     *
     * The availability channels are deliberately NOT selected here. This row
     * is echoed verbatim as JSON by api/product.php, and which catalogue a
     * product sits in is nothing the browser needs — the one caller that has
     * to know asks isShopPurchasable() below, which answers the actual
     * question rather than handing out two columns to re-derive it from.
     */
    public function findActiveById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, slug, price, image_path, stock
             FROM products
             WHERE id = :id AND active = 1
             LIMIT 1'
        );
        $stmt->execute(['id' => $id]);

        $product = $stmt->fetch();

        return $product === false ? null : $product;
    }

    /**
     * Whether a publicly visible product may be bought the ORDINARY way.
     * False for a personalization-only product, and false for anything that
     * is not publicly visible at all — the safe direction in both cases.
     */
    public function isShopPurchasable(int $id): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM products WHERE id = :id AND active = 1 AND in_shop = 1 LIMIT 1'
        );
        $stmt->execute(['id' => $id]);

        return $stmt->fetch() !== false;
    }

    /**
     * One active product with its editable SEO columns attached — what
     * App\Service\ProductSeo needs to build a product page's <head> and its
     * Product JSON-LD.
     *
     * Deliberately a separate method rather than widening findActiveById()
     * above: that row is echoed verbatim as JSON by api/product.php, and the
     * SEO columns have no business in the shop's product API payload.
     */
    public function findActiveByIdWithSeo(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, slug, price, image_path,
                    og_image_path
             FROM products
             WHERE id = :id AND active = 1
             LIMIT 1'
        );
        $stmt->execute(['id' => $id]);

        $product = $stmt->fetch();

        return $product === false ? null : $product;
    }

    /**
     * Every publicly indexable product, as App\Service\Sitemap needs it: the
     * id its canonical URL is built from, plus the row's own last-modified
     * timestamp. The same `active = 1` visibility rule findAllActive() and
     * the product page apply, so a product can never be listed in the
     * sitemap while its own page 404s.
     *
     * @return array<int, array{id:int, updated_at:?string}>
     */
    public function findActiveForSitemap(): array
    {
        $stmt = $this->db->query(
            'SELECT id, updated_at FROM products WHERE active = 1 ORDER BY id ASC'
        );

        return array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'updated_at' => $row['updated_at'] !== null ? (string) $row['updated_at'] : null,
            ],
            $stmt->fetchAll()
        );
    }

    /**
     * Admin product overview: every product regardless of active state.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findAllForAdmin(): array
    {
        $stmt = $this->db->query(
            'SELECT id, slug, price, image_path, active, in_shop, in_personalization_catalog, created_at
             FROM products
             ORDER BY id DESC'
        );

        return $stmt->fetchAll();
    }

    /**
     * Admin product detail/edit: same shape as findAllForAdmin() but one row,
     * regardless of active state (unlike findActiveById()).
     */
    public function findByIdForAdmin(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, slug, price, image_path, active, in_shop, in_personalization_catalog,
                    shipping_profile, shipping_weight_grams, requires_parcel,
                    og_image_path
             FROM products
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $id]);

        $product = $stmt->fetch();

        return $product === false ? null : $product;
    }

    public function slugExists(string $slug, ?int $excludeId = null): bool
    {
        if ($excludeId !== null) {
            $stmt = $this->db->prepare('SELECT 1 FROM products WHERE slug = :slug AND id != :id LIMIT 1');
            $stmt->execute(['slug' => $slug, 'id' => $excludeId]);
        } else {
            $stmt = $this->db->prepare('SELECT 1 FROM products WHERE slug = :slug LIMIT 1');
            $stmt->execute(['slug' => $slug]);
        }

        return $stmt->fetch() !== false;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, ?string>
     */
    /**
     * The two availability channels, defaulted the way an existing caller
     * that knows nothing about them would expect: in the shop, not in the
     * personalization catalogue. That keeps every seeder and test fixture
     * producing an ordinary shop product without being updated.
     *
     * @param array<string, mixed> $data
     * @return array{in_shop: int, in_personalization_catalog: int}
     */
    private function channelValues(array $data): array
    {
        return [
            'in_shop' => ($data['in_shop'] ?? true) ? 1 : 0,
            'in_personalization_catalog' => ($data['in_personalization_catalog'] ?? false) ? 1 : 0,
        ];
    }

    /**
     * Adds a product row. Its WORDS are not here: since Multilingual 2.0
     * phase 5 wave C the name, description and SEO copy live per website
     * language in product_translations and are written through
     * App\Service\ShopLocalization, in the same transaction as this row. What
     * is left is everything a shop DECIDES with: the slug, the price, the
     * stock channels, the shipping settings and the photo.
     *
     * @param array{slug:string,price:float,image_path:?string,active:bool,shipping_profile:string,shipping_weight_grams:int,requires_parcel:bool} $data
     */
    public function create(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO products
                (slug, price, image_path, active,
                 in_shop, in_personalization_catalog,
                 shipping_profile, shipping_weight_grams, requires_parcel,
                 created_at, updated_at)
             VALUES
                (:slug, :price, :image_path, :active,
                 :in_shop, :in_personalization_catalog,
                 :shipping_profile, :shipping_weight_grams, :requires_parcel,
                 NOW(), NOW())'
        );
        $stmt->execute([
            'slug' => $data['slug'],
            'price' => number_format($data['price'], 2, '.', ''),
            'image_path' => $data['image_path'],
            'active' => $data['active'] ? 1 : 0,
            'shipping_profile' => $data['shipping_profile'],
            'shipping_weight_grams' => $data['shipping_weight_grams'],
            'requires_parcel' => $data['requires_parcel'] ? 1 : 0,
        ] + $this->channelValues($data));

        return (int) $this->db->lastInsertId();
    }

    /**
     * Updates every editable field except image_path and og_image_path — see
     * updateImagePath() / updateOgImagePath(). Kept as a separate method so
     * saving the form (e.g. a text-only edit) can never accidentally clear
     * or replace an uploaded photo.
     *
     * Its words are saved separately and per language, through
     * App\Service\ShopLocalization, in the same transaction.
     *
     * @param array{price:float,active:bool,shipping_profile:string,shipping_weight_grams:int,requires_parcel:bool} $data
     */
    public function update(int $id, array $data): void
    {
        $stmt = $this->db->prepare(
            'UPDATE products SET
                price = :price, active = :active,
                in_shop = :in_shop,
                in_personalization_catalog = :in_personalization_catalog,
                shipping_profile = :shipping_profile,
                shipping_weight_grams = :shipping_weight_grams,
                requires_parcel = :requires_parcel,
                updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'price' => number_format($data['price'], 2, '.', ''),
            'active' => $data['active'] ? 1 : 0,
            'shipping_profile' => $data['shipping_profile'],
            'shipping_weight_grams' => $data['shipping_weight_grams'],
            'requires_parcel' => $data['requires_parcel'] ? 1 : 0,
            'id' => $id,
        ] + $this->channelValues($data));
    }

    public function updateImagePath(int $id, ?string $imagePath): void
    {
        $stmt = $this->db->prepare('UPDATE products SET image_path = :image_path, updated_at = NOW() WHERE id = :id');
        $stmt->execute(['image_path' => $imagePath, 'id' => $id]);
    }

    /**
     * The optional dedicated social-sharing image. Separate from update()
     * for the same reason updateImagePath() is: it is written only when a
     * file was actually uploaded or the admin explicitly asked to remove it,
     * so an ordinary text save can never drop it. NULL restores the automatic
     * fallback to the product's own photo (App\Service\ProductSeo).
     */
    public function updateOgImagePath(int $id, ?string $imagePath): void
    {
        $stmt = $this->db->prepare('UPDATE products SET og_image_path = :og_image_path, updated_at = NOW() WHERE id = :id');
        $stmt->execute(['og_image_path' => $imagePath, 'id' => $id]);
    }

    public function setActive(int $id, bool $active): bool
    {
        $stmt = $this->db->prepare('UPDATE products SET active = :active, updated_at = NOW() WHERE id = :id');
        $stmt->execute(['active' => $active ? 1 : 0, 'id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * True when this product appears on at least one historical order.
     *
     * No longer a deletion guard: since db/migrations/
     * 20260908120000_relax_order_item_product_foreign_keys.php the
     * order_items.product_id foreign key is ON DELETE SET NULL, and every
     * order line carries its own title/variant/quantity/price snapshot, so an
     * ordered product can be deleted without touching order history (see
     * App\Service\ProductDeletionService). This is now purely informational —
     * "has this ever been sold?" — for admin screens that want to say so.
     */
    public function isReferencedByOrders(int $id): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM order_items WHERE product_id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);

        return $stmt->fetch() !== false;
    }

    /**
     * All product ids that appear on at least one order_item — used by the
     * admin product list to word the delete confirmation, which reassures the
     * admin that existing orders stay unchanged when the product they are
     * about to delete has actually been sold. It no longer disables anything:
     * every product is deletable (see isReferencedByOrders() above).
     *
     * @return array<int, int>
     */
    public function referencedProductIds(): array
    {
        $stmt = $this->db->query('SELECT DISTINCT product_id FROM order_items');

        return array_map('intval', array_column($stmt->fetchAll(), 'product_id'));
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM products WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Loads the current, authoritative PRICE for a set of product ids. Used by
     * checkout so the backend never has to trust prices sent by the frontend.
     * Inactive/unknown ids are simply absent from the result — the caller
     * decides how to react (e.g. reject the checkout).
     *
     * It used to load the name too, for the snapshot an order line keeps.
     * Since Multilingual 2.0 phase 5 wave C that name is words, so checkout
     * asks App\Service\ShopLocalization for them — and this method stays what
     * it is for: money and identity.
     *
     * @param array<int, int> $ids
     * @return array<int, array<string, mixed>> keyed by product id
     */
    public function findActiveByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare(
            "SELECT id, price, image_path
             FROM products
             WHERE active = 1 AND id IN ({$placeholders})"
        );
        $stmt->execute($ids);

        $byId = [];
        foreach ($stmt->fetchAll() as $row) {
            $byId[(int) $row['id']] = $row;
        }

        return $byId;
    }

    /**
     * Loads the authoritative shipping settings (profile, weight, whether
     * parcel is forced) for a set of product ids. Used exclusively by
     * App\Service\Shipping\ShippingCalculationService — never trust shipping
     * weight/profile/method sent by the frontend, always re-read here.
     * Inactive/unknown ids are simply absent from the result; the caller
     * (the calculator) treats a missing id as "shipping unavailable" rather
     * than assuming a default, so a data problem can never silently produce
     * a €0 or wrong-weight shipping cost.
     *
     * @param array<int, int> $ids
     * @return array<int, array{shipping_profile:string, shipping_weight_grams:int, requires_parcel:bool}> keyed by product id
     */
    public function findShippingDataByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare(
            "SELECT id, shipping_profile, shipping_weight_grams, requires_parcel
             FROM products
             WHERE active = 1 AND id IN ({$placeholders})"
        );
        $stmt->execute($ids);

        $byId = [];
        foreach ($stmt->fetchAll() as $row) {
            $byId[(int) $row['id']] = [
                'shipping_profile' => (string) $row['shipping_profile'],
                'shipping_weight_grams' => (int) $row['shipping_weight_grams'],
                'requires_parcel' => (bool) $row['requires_parcel'],
            ];
        }

        return $byId;
    }
}

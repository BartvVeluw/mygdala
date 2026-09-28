<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * All SQL of the Uitgelicht product block (`featured_products`,
 * App\Service\Blocks\FeaturedProductBlock): one row per instance, addressed by
 * (page_slug, section_key) like every block, holding which product is on show
 * and how THIS block presents it. The product's own data is never stored
 * here: it is read from the Shop at every render (App\Service\ProductDetail).
 * The block's words (intro, button label) live in block_translations.
 */
final class FeaturedProductRepository extends Repository
{
    /** The columns update() writes, and nothing else: never a name from a request. */
    private const SETTINGS = [
        'product_id',
        'show_name',
        'show_price',
        'show_description',
        'show_specifications',
        'image_mode',
        'image_position',
        'image_size',
        'content_align',
        'ordering',
        'show_product_link',
    ];

    public function findBySlugAndKey(string $pageSlug, string $sectionKey): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM featured_products WHERE page_slug = :page_slug AND section_key = :section_key LIMIT 1'
        );
        $stmt->execute(['page_slug' => $pageSlug, 'section_key' => $sectionKey]);

        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM featured_products WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);

        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Creates the instance's row: no product yet, and every other column at
     * its default (the table's own), shown. It renders nothing until a
     * product is chosen, so a block can be placed first and set up later.
     */
    public function createSection(string $pageSlug, string $sectionKey): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO featured_products (page_slug, section_key, is_active, created_at, updated_at)
             VALUES (:page_slug, :section_key, 1, NOW(), NOW())'
        );
        $stmt->execute(['page_slug' => $pageSlug, 'section_key' => $sectionKey]);
    }

    /**
     * Writes the settings of one instance. Every value has been checked by
     * the caller (App\Service\FeaturedProductContent's closed lists, a product
     * that exists); keys that are not settings are ignored.
     *
     * @param array<string, int|string|bool|null> $settings
     */
    public function update(int $id, array $settings): void
    {
        $sets = [];
        $params = ['id' => $id];

        foreach (self::SETTINGS as $column) {
            if (!array_key_exists($column, $settings)) {
                continue;
            }

            $value = $settings[$column];
            $sets[] = $column . ' = :' . $column;
            $params[$column] = is_bool($value) ? (int) $value : $value;
        }

        if ($sets === []) {
            return;
        }

        $stmt = $this->db->prepare('UPDATE featured_products SET ' . implode(', ', $sets) . ', updated_at = NOW() WHERE id = :id');
        $stmt->execute($params);
    }

    /**
     * Permanently removes one instance — used by the page builder's "Delete
     * section" action via App\Service\SectionRegistry::delete(). The product
     * it showed is not touched.
     */
    public function deleteSection(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM featured_products WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }
}

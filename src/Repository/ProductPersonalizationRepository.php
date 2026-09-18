<?php

namespace App\Repository;

use App\Service\Personalization\Money;
use App\Service\Personalization\PersonalizationFonts;
use App\Service\Personalization\PersonalizationRules;

/**
 * All product-personalization CONFIGURATION SQL:
 * `product_personalization_settings` (one optional row per product),
 * `product_personalization_views` (its preview sides) and
 * `product_personalization_zones` (the engraving areas on those views).
 *
 * The absence of a settings row is the default for every product that has
 * never been configured, and reads as "personalization disabled" — which is
 * what keeps this feature invisible to every other product.
 *
 * Writes are deliberately GRANULAR (one method per thing an administrator
 * does: add a view, rename it, move it, add a zone, edit a zone, ...) rather
 * than one save-everything call. Two reasons: the CMS drives each action from
 * its own small endpoint, the way product options, variants and photos
 * already work here; and a full-form save is exactly what makes it possible
 * for editing one zone to silently rewrite another.
 *
 * Customer personalization DATA is not here; that belongs to the order line
 * (see App\Repository\OrderItemPersonalizationRepository).
 */
class ProductPersonalizationRepository extends Repository
{
    /**
     * NO WORDS. A zone's label, instructions and placeholder live per website
     * language in product_personalization_zone_translations since
     * Multilingual 2.0 phase 5 wave D, read through
     * App\Service\Personalization\PersonalizationLocalization. What is left
     * here is the configuration: the key an order points at, the geometry,
     * and the rules.
     */
    private const ZONE_COLUMNS =
        'id, settings_id, view_id, zone_key, allow_text, allow_image, is_enabled, is_required,
         allow_rotation, max_text_length, default_font, allowed_fonts, surcharge,
         area_x, area_y, area_width, area_height, sort_order';

    /** Likewise: a view's label is a word, its key and its image are not. */
    private const VIEW_COLUMNS =
        'id, settings_id, view_key, preview_image_path, sort_order';

    /* ------------------------------------------------------------------ */
    /* Reads                                                               */
    /* ------------------------------------------------------------------ */

    /**
     * The complete configuration for one product — settings, its views, and
     * each view's zones — or null when the product was never configured.
     *
     * Two queries total, never one per view: an editor with several views
     * must not turn into an N+1.
     *
     * @return array{settings: array<string, mixed>, views: array<int, array<string, mixed>>}|null
     */
    public function findForProduct(int $productId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, product_id, is_enabled, personalization_mode, created_at, updated_at
             FROM product_personalization_settings
             WHERE product_id = :product_id
             LIMIT 1'
        );
        $stmt->execute(['product_id' => $productId]);

        $settings = $stmt->fetch();

        if ($settings === false) {
            return null;
        }

        return [
            'settings' => $settings,
            'views' => $this->findViewsWithZones((int) $settings['id']),
        ];
    }

    /**
     * @return array<int, array<string, mixed>> each view with a `zones` list
     */
    public function findViewsWithZones(int $settingsId): array
    {
        $viewStmt = $this->db->prepare(
            'SELECT ' . self::VIEW_COLUMNS . '
             FROM product_personalization_views
             WHERE settings_id = :settings_id
             ORDER BY sort_order ASC, id ASC'
        );
        $viewStmt->execute(['settings_id' => $settingsId]);
        $views = $viewStmt->fetchAll();

        $zoneStmt = $this->db->prepare(
            'SELECT ' . self::ZONE_COLUMNS . '
             FROM product_personalization_zones
             WHERE settings_id = :settings_id
             ORDER BY sort_order ASC, id ASC'
        );
        $zoneStmt->execute(['settings_id' => $settingsId]);

        $zonesByView = [];
        foreach ($zoneStmt->fetchAll() as $zone) {
            // A zone whose view_id is somehow NULL (only reachable if a row
            // predates the Phase 2 backfill) is attached to the first view
            // rather than silently disappearing from the editor.
            $viewId = $zone['view_id'] !== null ? (int) $zone['view_id'] : 0;
            $zonesByView[$viewId][] = $zone;
        }

        $orphans = $zonesByView[0] ?? [];

        foreach ($views as $index => $view) {
            $zones = $zonesByView[(int) $view['id']] ?? [];
            if ($index === 0 && $orphans !== []) {
                $zones = array_merge($zones, $orphans);
            }
            $views[$index]['zones'] = $zones;
        }

        return $views;
    }

    /**
     * One view plus the product it belongs to — every admin endpoint needs
     * that product id to authorise the request and to redirect back to the
     * right editor, so it is joined here rather than looked up again.
     */
    public function findViewById(int $viewId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT v.id, v.settings_id, v.view_key,
                    v.preview_image_path, v.sort_order, s.product_id
             FROM product_personalization_views v
             INNER JOIN product_personalization_settings s ON s.id = v.settings_id
             WHERE v.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $viewId]);

        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function findZoneById(int $zoneId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT z.id, z.settings_id, z.view_id, z.zone_key,
                    z.allow_text, z.allow_image, z.is_enabled, z.is_required, z.allow_rotation,
                    z.max_text_length, z.default_font, z.allowed_fonts, z.surcharge,
                    z.area_x, z.area_y, z.area_width, z.area_height, z.sort_order,
                    s.product_id
             FROM product_personalization_zones z
             INNER JOIN product_personalization_settings s ON s.id = z.settings_id
             WHERE z.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $zoneId]);

        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function settingsIdForProduct(int $productId): ?int
    {
        $stmt = $this->db->prepare(
            'SELECT id FROM product_personalization_settings WHERE product_id = :product_id LIMIT 1'
        );
        $stmt->execute(['product_id' => $productId]);

        $row = $stmt->fetch();

        return $row === false ? null : (int) $row['id'];
    }

    public function viewKeyExists(int $settingsId, string $viewKey, ?int $excludeViewId = null): bool
    {
        $sql = 'SELECT 1 FROM product_personalization_views WHERE settings_id = :settings_id AND view_key = :view_key';
        $params = ['settings_id' => $settingsId, 'view_key' => $viewKey];

        if ($excludeViewId !== null) {
            $sql .= ' AND id != :exclude_id';
            $params['exclude_id'] = $excludeViewId;
        }

        $stmt = $this->db->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);

        return $stmt->fetch() !== false;
    }

    /**
     * Zone keys are unique per PRODUCT, not per view: an order line's
     * personalization is keyed by zone_key alone, so the same key on two
     * views would make a historical order ambiguous.
     */
    public function zoneKeyExists(int $settingsId, string $zoneKey, ?int $excludeZoneId = null): bool
    {
        $sql = 'SELECT 1 FROM product_personalization_zones WHERE settings_id = :settings_id AND zone_key = :zone_key';
        $params = ['settings_id' => $settingsId, 'zone_key' => $zoneKey];

        if ($excludeZoneId !== null) {
            $sql .= ' AND id != :exclude_id';
            $params['exclude_id'] = $excludeZoneId;
        }

        $stmt = $this->db->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);

        return $stmt->fetch() !== false;
    }

    public function countViews(int $settingsId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM product_personalization_views WHERE settings_id = :id');
        $stmt->execute(['id' => $settingsId]);

        return (int) $stmt->fetchColumn();
    }

    public function countZonesInView(int $viewId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM product_personalization_zones WHERE view_id = :id');
        $stmt->execute(['id' => $viewId]);

        return (int) $stmt->fetchColumn();
    }

    /* ------------------------------------------------------------------ */
    /* Settings                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * Creates or updates the product-level row and returns its id. Touches
     * only the product-level fields — never a view, never a zone — so saving
     * the top of the form can't disturb the configuration below it.
     *
     * The general instructions are NOT written here: they are words, and
     * the endpoint saves the one language it carries through
     * App\Service\Personalization\PersonalizationLocalization, in the same
     * transaction as this row.
     *
     * @param array{is_enabled: bool, personalization_mode: string} $settings
     */
    public function saveSettings(int $productId, array $settings): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO product_personalization_settings
                (product_id, is_enabled, personalization_mode, created_at, updated_at)
             VALUES (:product_id, :is_enabled, :personalization_mode, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                is_enabled = VALUES(is_enabled),
                personalization_mode = VALUES(personalization_mode),
                updated_at = NOW()'
        );
        $stmt->execute([
            'product_id' => $productId,
            'is_enabled' => $settings['is_enabled'] ? 1 : 0,
            'personalization_mode' => PersonalizationRules::purchaseMode($settings['personalization_mode'] ?? null),
        ]);

        $settingsId = $this->settingsIdForProduct($productId);

        if ($settingsId === null) {
            throw new \RuntimeException('Personalisatie-instellingen konden niet worden opgeslagen.');
        }

        return $settingsId;
    }

    /**
     * ENROLS an existing shop product into the Personalisatie module: creates
     * its settings row (disabled, optional, no views yet) and returns its id,
     * or null when the product already has one.
     *
     * This creates NO product. `products` stays the single source of truth
     * for the name, description, price, variants, photos and visibility of
     * everything in the shop; a personalization configuration is an optional
     * satellite of one of those rows, never a copy of it.
     *
     * Returning null rather than throwing is what makes the "already added"
     * case a message in the CMS instead of an error page — and the UNIQUE
     * index on product_id means two simultaneous adds still cannot produce
     * two configurations for one product.
     */
    public function createForProduct(int $productId): ?int
    {
        if ($this->settingsIdForProduct($productId) !== null) {
            return null;
        }

        $stmt = $this->db->prepare(
            'INSERT INTO product_personalization_settings
                (product_id, is_enabled, personalization_mode, created_at, updated_at)
             VALUES (:product_id, 0, :mode, NOW(), NOW())'
        );
        $stmt->execute([
            'product_id' => $productId,
            'mode' => PersonalizationRules::PURCHASE_OPTIONAL,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Removes a product's whole personalization configuration — settings,
     * views and zones (the last two by cascade).
     *
     * It deletes NOTHING from `products`: the shop product itself, its
     * photos, variants and orders are untouched, and it simply becomes an
     * ordinary product again. Historical orders are untouched too — their
     * personalization is a snapshot on the order line, not a reference to
     * this configuration.
     *
     * @return list<string> the preview image paths that are now unreferenced,
     *         so the caller can delete the files its own uploader owns
     */
    public function deleteForProduct(int $productId): array
    {
        $settingsId = $this->settingsIdForProduct($productId);

        if ($settingsId === null) {
            return [];
        }

        $imageStmt = $this->db->prepare(
            'SELECT preview_image_path FROM product_personalization_views WHERE settings_id = :settings_id'
        );
        $imageStmt->execute(['settings_id' => $settingsId]);

        $images = [];
        foreach ($imageStmt->fetchAll() as $row) {
            $path = trim((string) ($row['preview_image_path'] ?? ''));
            if ($path !== '') {
                $images[] = $path;
            }
        }

        $delete = $this->db->prepare('DELETE FROM product_personalization_settings WHERE id = :id');
        $delete->execute(['id' => $settingsId]);

        return $images;
    }

    /**
     * The Personalisatie overview: every product that has a personalization
     * configuration, joined to the product it belongs to, and counted.
     *
     * One query, never one per product: the overview is a list and must not
     * become an N+1. `image_path` is the PRODUCT's own thumbnail — the
     * overview identifies which shop product a configuration belongs to, and
     * that is exactly what a product photo is for. The personalization
     * PREVIEW images are a different thing entirely and are only ever shown
     * inside the editor.
     *
     * NO PRODUCT NAME, and a language-neutral order. A product's name lives
     * per website language in `product_translations` since Multilingual 2.0
     * phase 5 wave C, so the screen adds it
     * (App\Service\ShopLocalization::productName()) and decides the
     * alphabetical order it lists them in.
     *
     * @return list<array<string, mixed>>
     */
    public function findAllConfigured(): array
    {
        $stmt = $this->db->query(
            "SELECT s.id AS settings_id, s.product_id, s.is_enabled, s.personalization_mode,
                    s.updated_at,
                    p.active AS product_active, p.image_path,
                    p.in_shop, p.in_personalization_catalog,
                    (SELECT COUNT(*) FROM product_personalization_views v
                      WHERE v.settings_id = s.id) AS view_count,
                    (SELECT COUNT(*) FROM product_personalization_views v
                      WHERE v.settings_id = s.id
                        AND v.preview_image_path IS NOT NULL
                        AND v.preview_image_path <> '') AS view_with_image_count,
                    (SELECT COUNT(*) FROM product_personalization_zones z
                      WHERE z.settings_id = s.id) AS zone_count
             FROM product_personalization_settings s
             INNER JOIN products p ON p.id = s.product_id
             ORDER BY p.id ASC"
        );

        return $stmt->fetchAll();
    }

    /**
     * The shop products that are NOT yet enrolled, for the "Product
     * toevoegen" picker — which is what makes adding the same product twice
     * impossible from the UI, while the UNIQUE index makes it impossible
     * regardless.
     *
     * INACTIVE products are deliberately included: configuring the
     * personalization of a product before publishing it is an ordinary order
     * of work.
     *
     * The picker's labels come from App\Service\ShopLocalization, for the
     * reason findAllConfigured() above gives.
     *
     * @return list<array<string, mixed>>
     */
    public function findProductsWithoutConfiguration(): array
    {
        $stmt = $this->db->query(
            'SELECT p.id, p.active
             FROM products p
             LEFT JOIN product_personalization_settings s ON s.product_id = p.id
             WHERE s.id IS NULL
             ORDER BY p.id ASC'
        );

        return $stmt->fetchAll();
    }

    /* ------------------------------------------------------------------ */
    /* Views                                                               */
    /* ------------------------------------------------------------------ */

    /**
     * Its label is a word and is saved separately, per language, through
     * App\Service\Personalization\PersonalizationLocalization.
     *
     * @param array{view_key: string} $data
     */
    public function createView(int $settingsId, array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO product_personalization_views
                (settings_id, view_key, preview_image_path, sort_order, created_at, updated_at)
             VALUES (:settings_id, :view_key, NULL, :sort_order, NOW(), NOW())'
        );
        $stmt->execute([
            'settings_id' => $settingsId,
            'view_key' => $data['view_key'],
            'sort_order' => $this->nextViewSortOrder($settingsId),
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Marks a view as changed. Its NAME is a word and is written per language
     * by App\Service\Personalization\PersonalizationLocalization, so all this
     * row has left to record is that it was touched. `view_key` is
     * deliberately NOT updatable: order rows store it, so changing it would
     * rewrite what a historical order says it was engraved on.
     */
    public function touchView(int $viewId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE product_personalization_views SET updated_at = NOW() WHERE id = :id'
        );
        $stmt->execute(['id' => $viewId]);
    }

    /**
     * The view's preview image, on its own writer for the same reason the
     * product photo has one: an ordinary save of the view's name must never
     * be able to drop the image its zones are positioned against.
     */
    public function updateViewPreviewImagePath(int $viewId, ?string $imagePath): void
    {
        $stmt = $this->db->prepare(
            'UPDATE product_personalization_views
             SET preview_image_path = :preview_image_path, updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute(['preview_image_path' => $imagePath, 'id' => $viewId]);
    }

    public function deleteView(int $viewId): bool
    {
        // Its zones cascade away with it (see the migration's foreign key).
        $stmt = $this->db->prepare('DELETE FROM product_personalization_views WHERE id = :id');
        $stmt->execute(['id' => $viewId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Swaps a view with its neighbour. Same up/down model the product image
     * and variant editors already use, rather than a drag-and-drop payload.
     */
    public function moveView(int $viewId, string $direction): bool
    {
        return $this->move('product_personalization_views', 'settings_id', $viewId, $direction);
    }

    /* ------------------------------------------------------------------ */
    /* Zones                                                               */
    /* ------------------------------------------------------------------ */

    /**
     * @param array<string, mixed> $data
     */
    public function createZone(int $settingsId, int $viewId, array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO product_personalization_zones
                (settings_id, view_id, zone_key, allow_text, allow_image, is_enabled, is_required,
                 allow_rotation, max_text_length, default_font, allowed_fonts, surcharge,
                 area_x, area_y, area_width, area_height, sort_order, created_at, updated_at)
             VALUES
                (:settings_id, :view_id, :zone_key, :allow_text, :allow_image, :is_enabled, :is_required,
                 :allow_rotation, :max_text_length, :default_font, :allowed_fonts, :surcharge,
                 :area_x, :area_y, :area_width, :area_height, :sort_order, NOW(), NOW())'
        );
        $stmt->execute([
            'settings_id' => $settingsId,
            'view_id' => $viewId,
            'zone_key' => $data['zone_key'],
            'sort_order' => $this->nextZoneSortOrder($viewId),
        ] + $this->zoneValues($data));

        return (int) $this->db->lastInsertId();
    }

    /**
     * Updates one zone's properties. `zone_key` is not updatable, for the
     * same reason a view key is not: order rows point at it.
     *
     * @param array<string, mixed> $data
     */
    public function updateZone(int $zoneId, array $data): void
    {
        $stmt = $this->db->prepare(
            'UPDATE product_personalization_zones SET
                allow_text = :allow_text, allow_image = :allow_image,
                is_enabled = :is_enabled, is_required = :is_required,
                allow_rotation = :allow_rotation,
                max_text_length = :max_text_length,
                default_font = :default_font, allowed_fonts = :allowed_fonts,
                surcharge = :surcharge,
                area_x = :area_x, area_y = :area_y,
                area_width = :area_width, area_height = :area_height,
                updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute(['id' => $zoneId] + $this->zoneValues($data));
    }

    public function deleteZone(int $zoneId): bool
    {
        $stmt = $this->db->prepare('DELETE FROM product_personalization_zones WHERE id = :id');
        $stmt->execute(['id' => $zoneId]);

        return $stmt->rowCount() > 0;
    }

    public function moveZone(int $zoneId, string $direction): bool
    {
        return $this->move('product_personalization_zones', 'view_id', $zoneId, $direction);
    }

    /* ------------------------------------------------------------------ */
    /* Internals                                                           */
    /* ------------------------------------------------------------------ */

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function zoneValues(array $data): array
    {
        // `default_font`/`allowed_fonts` are LEGACY columns as of Phase 3:
        // fonts became a global library the customer picks from (see
        // App\Service\Personalization\PersonalizationFonts), so a zone no
        // longer configures them and the CMS no longer submits them. The
        // columns are still written — as NULL for anything created or edited
        // from here on — rather than dropped, so a Phase 2 row keeps its
        // values and this migration stays reversible. Nothing reads them.
        $allowedFonts = PersonalizationFonts::sanitize($data['allowed_fonts'] ?? null);

        return [
            'allow_text' => $data['allow_text'] ? 1 : 0,
            'allow_image' => $data['allow_image'] ? 1 : 0,
            'is_enabled' => $data['is_enabled'] ? 1 : 0,
            'is_required' => $data['is_required'] ? 1 : 0,
            'allow_rotation' => $data['allow_rotation'] ? 1 : 0,
            'max_text_length' => $data['max_text_length'],
            'default_font' => $data['default_font'] ?? null,
            'allowed_fonts' => $allowedFonts === [] ? null : implode(',', $allowedFonts),
            'surcharge' => Money::format((int) $data['surcharge_cents']),
            'area_x' => number_format((float) $data['area_x'], 3, '.', ''),
            'area_y' => number_format((float) $data['area_y'], 3, '.', ''),
            'area_width' => number_format((float) $data['area_width'], 3, '.', ''),
            'area_height' => number_format((float) $data['area_height'], 3, '.', ''),
        ];
    }

    private function nextViewSortOrder(int $settingsId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COALESCE(MAX(sort_order), -1) + 1 FROM product_personalization_views WHERE settings_id = :id'
        );
        $stmt->execute(['id' => $settingsId]);

        return (int) $stmt->fetchColumn();
    }

    private function nextZoneSortOrder(int $viewId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COALESCE(MAX(sort_order), -1) + 1 FROM product_personalization_zones WHERE view_id = :id'
        );
        $stmt->execute(['id' => $viewId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Swaps a row's sort_order with its nearest neighbour inside the same
     * parent. Shared by views and zones because the operation is identical;
     * the table and parent column are the only difference, and both are
     * class constants here, never request input.
     */
    private function move(string $table, string $parentColumn, int $id, string $direction): bool
    {
        if (!in_array($table, ['product_personalization_views', 'product_personalization_zones'], true)
            || !in_array($parentColumn, ['settings_id', 'view_id'], true)
            || !in_array($direction, ['up', 'down'], true)) {
            return false;
        }

        $currentStmt = $this->db->prepare(
            "SELECT id, {$parentColumn} AS parent_id, sort_order FROM {$table} WHERE id = :id LIMIT 1"
        );
        $currentStmt->execute(['id' => $id]);
        $current = $currentStmt->fetch();

        if ($current === false || $current['parent_id'] === null) {
            return false;
        }

        $comparison = $direction === 'up' ? '<' : '>';
        $order = $direction === 'up' ? 'DESC' : 'ASC';

        $neighbourStmt = $this->db->prepare(
            "SELECT id, sort_order FROM {$table}
             WHERE {$parentColumn} = :parent_id
               AND (sort_order {$comparison} :sort_order OR (sort_order = :same_sort_order AND id {$comparison} :self_id))
             ORDER BY sort_order {$order}, id {$order}
             LIMIT 1"
        );
        $neighbourStmt->execute([
            'parent_id' => (int) $current['parent_id'],
            'sort_order' => (int) $current['sort_order'],
            'same_sort_order' => (int) $current['sort_order'],
            'self_id' => $id,
        ]);
        $neighbour = $neighbourStmt->fetch();

        if ($neighbour === false) {
            return false;
        }

        $update = $this->db->prepare("UPDATE {$table} SET sort_order = :sort_order, updated_at = NOW() WHERE id = :id");
        $update->execute(['sort_order' => (int) $neighbour['sort_order'], 'id' => $id]);
        $update->execute(['sort_order' => (int) $current['sort_order'], 'id' => (int) $neighbour['id']]);

        // Two rows sharing a sort_order (only possible for rows created
        // before this method existed) would otherwise swap to a no-op.
        if ((int) $neighbour['sort_order'] === (int) $current['sort_order']) {
            $this->renumber($table, $parentColumn, (int) $current['parent_id']);
        }

        return true;
    }

    private function renumber(string $table, string $parentColumn, int $parentId): void
    {
        $stmt = $this->db->prepare(
            "SELECT id FROM {$table} WHERE {$parentColumn} = :parent_id ORDER BY sort_order ASC, id ASC"
        );
        $stmt->execute(['parent_id' => $parentId]);

        $update = $this->db->prepare("UPDATE {$table} SET sort_order = :sort_order WHERE id = :id");
        foreach ($stmt->fetchAll() as $index => $row) {
            $update->execute(['sort_order' => $index, 'id' => (int) $row['id']]);
        }
    }
}

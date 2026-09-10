<?php

namespace App\Repository;

/**
 * All `order_item_personalizations` SQL — the immutable, order-side record of
 * what one customer asked to have engraved on one order line.
 *
 * Everything here is a SNAPSHOT. `text_value` is the exact string the
 * customer typed, `transform_json` is where they put it, and
 * `config_snapshot_json` is the product's personalization configuration as it
 * was at the moment of purchase (engraving area, maximum text length, preview
 * image, what was allowed). Changing the product afterwards — a different
 * engraving zone, a new preview photo, personalization switched off entirely
 * — can therefore never rewrite an existing order, exactly like the
 * `product_name`/`unit_price` snapshot columns on `order_items` itself.
 *
 * A row is optional: an order line without personalization simply has none,
 * and every historical order predates this table.
 */
class OrderItemPersonalizationRepository extends Repository
{
    /**
     * One row per personalized ZONE of an order line. `view_key`, `font_key`
     * and `surcharge` are Phase 2 additions and are NULL/0.00 on every Phase 1
     * row, which reads exactly as that row always meant: one zone, no view, no
     * font choice, nothing extra charged.
     *
     * `surcharge` is what was actually charged for this zone on this order,
     * stored as a decimal string built from integer cents — money never passes
     * through a float here (see App\Service\Personalization\Money).
     *
     * `font_label`, `font_stack` and `font_file_path` are the order's OWN copy
     * of the font it was engraved in, taken from the global library at the
     * moment of purchase (Phase 3). They are what make that library safely
     * editable: deactivating, renaming or even deleting a font can never
     * change what a historical order shows, because the order stopped
     * depending on the library row the second it was placed. All three are
     * NULL on a Phase 1/2 row, which reads as "look the key up in the
     * library" — and that still works, because every Phase 2 key was
     * backfilled into it.
     *
     * `text_color` is the palette KEY the customer previewed their text in
     * (App\Service\Personalization\PersonalizationColors), never a CSS value.
     * NULL on every row placed before the palette existed, which reads as the
     * palette's default — exactly what those orders were rendered in.
     *
     * @param array{zone_key: string, view_key: ?string, upload_id: ?int, text_value: ?string, font_key: ?string, font_label?: ?string, font_stack?: ?string, font_file_path?: ?string, text_color?: ?string, surcharge_cents: int, transform: array<string, mixed>, config_snapshot: array<string, mixed>} $data
     */
    public function create(int $orderItemId, array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO order_item_personalizations
                (order_item_id, zone_key, view_key, upload_id, text_value, font_key,
                 font_label, font_stack, font_file_path, text_color, surcharge,
                 transform_json, config_snapshot_json, created_at, updated_at)
             VALUES
                (:order_item_id, :zone_key, :view_key, :upload_id, :text_value, :font_key,
                 :font_label, :font_stack, :font_file_path, :text_color, :surcharge,
                 :transform_json, :config_snapshot_json, NOW(), NOW())'
        );
        $stmt->execute([
            'order_item_id' => $orderItemId,
            'zone_key' => $data['zone_key'],
            'view_key' => $data['view_key'] ?? null,
            'upload_id' => $data['upload_id'],
            'text_value' => $data['text_value'],
            'font_key' => $data['font_key'] ?? null,
            'font_label' => $data['font_label'] ?? null,
            'font_stack' => $data['font_stack'] ?? null,
            'font_file_path' => $data['font_file_path'] ?? null,
            'text_color' => $data['text_color'] ?? null,
            'surcharge' => \App\Service\Personalization\Money::format((int) ($data['surcharge_cents'] ?? 0)),
            'transform_json' => json_encode($data['transform'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'config_snapshot_json' => json_encode($data['config_snapshot'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Every personalization on one order, with the retained upload's own
     * metadata joined in, grouped by order line so the admin order page can
     * render each line's block without a query per row.
     *
     * The join to `personalization_uploads` is a LEFT JOIN: `upload_id` is
     * ON DELETE SET NULL, and a text-only personalization never has one.
     *
     * @return array<int, array<int, array<string, mixed>>> keyed by order_item_id
     */
    public function findByOrderIdGrouped(int $orderId): array
    {
        $stmt = $this->db->prepare(
            'SELECT oip.id, oip.order_item_id, oip.zone_key, oip.view_key, oip.upload_id,
                    oip.text_value, oip.font_key, oip.font_label, oip.font_stack,
                    oip.font_file_path, oip.text_color, oip.surcharge,
                    oip.transform_json, oip.config_snapshot_json, oip.created_at,
                    u.token AS upload_token, u.original_filename, u.mime_type,
                    u.image_width, u.image_height, u.byte_size
             FROM order_item_personalizations oip
             INNER JOIN order_items oi ON oi.id = oip.order_item_id
             LEFT JOIN personalization_uploads u ON u.id = oip.upload_id
             WHERE oi.order_id = :order_id
             ORDER BY oip.order_item_id ASC, oip.id ASC'
        );
        $stmt->execute(['order_id' => $orderId]);

        $grouped = [];
        foreach ($stmt->fetchAll() as $row) {
            $grouped[(int) $row['order_item_id']][] = $row;
        }

        return $grouped;
    }

    /**
     * One personalization plus the order it belongs to — what the
     * authenticated admin file endpoint needs to answer "does this record
     * exist, and which stored file is it allowed to serve". The stored
     * filenames come from the database, never from the request.
     */
    public function findWithUploadForAdmin(int $personalizationId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT oip.id, oip.order_item_id, oip.upload_id, oip.zone_key,
                    oi.order_id,
                    u.stored_filename, u.preview_filename, u.original_filename, u.mime_type
             FROM order_item_personalizations oip
             INNER JOIN order_items oi ON oi.id = oip.order_item_id
             LEFT JOIN personalization_uploads u ON u.id = oip.upload_id
             WHERE oip.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $personalizationId]);

        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }
}

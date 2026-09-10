<?php

namespace App\Repository;

/**
 * All `personalization_fonts` SQL — the GLOBAL library of engraving fonts a
 * customer may pick from, managed in the CMS under Personalisatie -> Fonts.
 *
 * One registry for the whole shop, deliberately not a per-product or per-zone
 * list: the owner curates the fonts once, and every text zone on every
 * personalizable product offers exactly the fonts that are active right now.
 * A font is therefore never "assigned" to anything, which is what makes
 * adding one a single upload instead of an edit of every product.
 *
 * Two kinds of row live here, distinguished by `source`:
 *   'builtin' — a family the browser already has, or one the site's own
 *               stylesheet loads. `css_stack` is the whole definition.
 *   'upload'  — a font file the owner uploaded. `file_path` points at a
 *               server-generated filename under assets/fonts/personalization/;
 *               `original_filename` is display metadata only and is never
 *               used to build a path.
 *
 * DELETION is intentionally blunt here and guarded above (see
 * App\Service\Personalization\PersonalizationFonts::isReferencedByOrder() and
 * the delete endpoint): an order row keeps its own copy of the font's label,
 * stack and file, so removing a library row cannot silently rewrite history —
 * but the CMS still prefers deactivating over deleting, and refuses to delete
 * a font that a historical order actually used.
 */
class PersonalizationFontRepository extends Repository
{
    private const COLUMNS =
        'id, font_key, label, source, css_stack, file_path, file_format,
         original_filename, byte_size, is_active, sort_order, created_at, updated_at';

    /**
     * Every font, active or not, in library order. What the CMS screen lists.
     *
     * @return list<array<string, mixed>>
     */
    public function findAll(): array
    {
        $stmt = $this->db->query(
            'SELECT ' . self::COLUMNS . ' FROM personalization_fonts ORDER BY sort_order ASC, id ASC'
        );

        return $stmt->fetchAll();
    }

    /**
     * Only the fonts a customer may actually pick. This is the one query the
     * storefront ever runs against this table.
     *
     * @return list<array<string, mixed>>
     */
    public function findActive(): array
    {
        $stmt = $this->db->query(
            'SELECT ' . self::COLUMNS . '
             FROM personalization_fonts
             WHERE is_active = 1
             ORDER BY sort_order ASC, id ASC'
        );

        return $stmt->fetchAll();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT ' . self::COLUMNS . ' FROM personalization_fonts WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);

        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function findByKey(string $fontKey): ?array
    {
        $stmt = $this->db->prepare('SELECT ' . self::COLUMNS . ' FROM personalization_fonts WHERE font_key = :font_key LIMIT 1');
        $stmt->execute(['font_key' => $fontKey]);

        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function keyExists(string $fontKey, ?int $excludeId = null): bool
    {
        $sql = 'SELECT 1 FROM personalization_fonts WHERE font_key = :font_key';
        $params = ['font_key' => $fontKey];

        if ($excludeId !== null) {
            $sql .= ' AND id != :exclude_id';
            $params['exclude_id'] = $excludeId;
        }

        $stmt = $this->db->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);

        return $stmt->fetch() !== false;
    }

    /**
     * @param array{font_key: string, label: string, source: string, css_stack: ?string, file_path: ?string, file_format: ?string, original_filename: ?string, byte_size: ?int, is_active: bool} $data
     */
    public function create(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO personalization_fonts
                (font_key, label, source, css_stack, file_path, file_format,
                 original_filename, byte_size, is_active, sort_order, created_at, updated_at)
             VALUES
                (:font_key, :label, :source, :css_stack, :file_path, :file_format,
                 :original_filename, :byte_size, :is_active, :sort_order, NOW(), NOW())'
        );
        $stmt->execute([
            'font_key' => $data['font_key'],
            'label' => $data['label'],
            'source' => $data['source'],
            'css_stack' => $data['css_stack'],
            'file_path' => $data['file_path'],
            'file_format' => $data['file_format'],
            'original_filename' => $data['original_filename'],
            'byte_size' => $data['byte_size'],
            'is_active' => $data['is_active'] ? 1 : 0,
            'sort_order' => $this->nextSortOrder(),
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Renames a font and/or flips its active state. `font_key` is deliberately
     * NOT updatable: order rows record it, and a zone's stored font list
     * refers to it.
     */
    public function update(int $id, string $label, bool $isActive): void
    {
        $stmt = $this->db->prepare(
            'UPDATE personalization_fonts
             SET label = :label, is_active = :is_active, updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute(['label' => $label, 'is_active' => $isActive ? 1 : 0, 'id' => $id]);
    }

    public function setActive(int $id, bool $isActive): void
    {
        $stmt = $this->db->prepare(
            'UPDATE personalization_fonts SET is_active = :is_active, updated_at = NOW() WHERE id = :id'
        );
        $stmt->execute(['is_active' => $isActive ? 1 : 0, 'id' => $id]);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM personalization_fonts WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * True when at least one PLACED ORDER was engraved in this font. The CMS
     * refuses a destructive delete in that case and offers deactivation
     * instead — an order's own font copy would survive it, but an owner
     * deleting a font almost never means "and lose the file behind an order I
     * still have to produce".
     */
    public function isUsedByOrder(string $fontKey): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM order_item_personalizations WHERE font_key = :font_key LIMIT 1'
        );
        $stmt->execute(['font_key' => $fontKey]);

        return $stmt->fetch() !== false;
    }

    /**
     * Swaps a font with its neighbour in library order — the same up/down
     * model every other ordered list in this CMS uses.
     */
    public function move(int $id, string $direction): bool
    {
        if (!in_array($direction, ['up', 'down'], true)) {
            return false;
        }

        $currentStmt = $this->db->prepare('SELECT id, sort_order FROM personalization_fonts WHERE id = :id LIMIT 1');
        $currentStmt->execute(['id' => $id]);
        $current = $currentStmt->fetch();

        if ($current === false) {
            return false;
        }

        $comparison = $direction === 'up' ? '<' : '>';
        $order = $direction === 'up' ? 'DESC' : 'ASC';

        $neighbourStmt = $this->db->prepare(
            "SELECT id, sort_order FROM personalization_fonts
             WHERE (sort_order {$comparison} :sort_order OR (sort_order = :same_sort_order AND id {$comparison} :self_id))
             ORDER BY sort_order {$order}, id {$order}
             LIMIT 1"
        );
        $neighbourStmt->execute([
            'sort_order' => (int) $current['sort_order'],
            'same_sort_order' => (int) $current['sort_order'],
            'self_id' => $id,
        ]);
        $neighbour = $neighbourStmt->fetch();

        if ($neighbour === false) {
            return false;
        }

        $update = $this->db->prepare('UPDATE personalization_fonts SET sort_order = :sort_order, updated_at = NOW() WHERE id = :id');
        $update->execute(['sort_order' => (int) $neighbour['sort_order'], 'id' => $id]);
        $update->execute(['sort_order' => (int) $current['sort_order'], 'id' => (int) $neighbour['id']]);

        if ((int) $neighbour['sort_order'] === (int) $current['sort_order']) {
            $this->renumber();
        }

        return true;
    }

    private function renumber(): void
    {
        $stmt = $this->db->query('SELECT id FROM personalization_fonts ORDER BY sort_order ASC, id ASC');
        $update = $this->db->prepare('UPDATE personalization_fonts SET sort_order = :sort_order WHERE id = :id');

        foreach ($stmt->fetchAll() as $index => $row) {
            $update->execute(['sort_order' => $index, 'id' => (int) $row['id']]);
        }
    }

    private function nextSortOrder(): int
    {
        return (int) $this->db->query('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM personalization_fonts')->fetchColumn();
    }
}

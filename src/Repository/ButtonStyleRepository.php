<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\ModuleRegistry;

/**
 * All SQL of the button style library (Button Styles 2.0,
 * App\Service\Theme\ButtonStyles): the styles, the two default roles, and
 * the one UPDATE that stores a content button's choice.
 *
 * THE SLOTS. A content button's choice is a nullable `…button_style_id`
 * column on its block's row (NULL = the default), with a RESTRICT foreign
 * key (db/migrations/20261005100000). One closed list names them, Core's
 * below and a module's through ModuleDefinition::buttonStyleSlots(), asked of
 * every registered module on or off — a switched-off Shop's featured
 * products still hold on to their style. It is the pattern of
 * App\Repository\ResponsiveImageRepository: a table and a column are names
 * from that list, never from a request, and the value is bound.
 *
 * NOT HERE: the rest of a block's row (its own repository writes that, in
 * the same transaction) and anything that decides what a style looks like.
 */
final class ButtonStyleRepository extends Repository
{
    /** The columns of button_styles a style is made of, besides its name. */
    public const COLUMNS = [
        'appearance', 'shape', 'size', 'fill_color', 'fill_gradient', 'text_color',
        'border_width', 'border_color', 'shadow', 'font_weight', 'font_role',
        'uppercase', 'underline', 'icon', 'icon_position', 'icon_gap', 'icon_motion',
        'hover_effect', 'hover_fill_color', 'hover_text_color', 'hover_border_color',
    ];

    public const ROLES = ['primary', 'secondary'];

    /**
     * Core's tables whose rows carry an editor's button, and their columns.
     *
     * @var array<string, list<string>>
     */
    public const SLOTS = [
        'homepage_hero' => ['primary_button_style_id', 'secondary_button_style_id'],
        'cta_bands' => ['primary_button_style_id', 'secondary_button_style_id'],
        'rich_text_sections' => ['button_style_id'],
        'text_image_split_items' => ['button_style_id'],
        'carousel_cards' => ['button_style_id'],
        'hover_card_grid_items' => ['button_style_id'],
        'detail_sections' => ['button_style_id'],
        'contact_cards' => ['button_style_id'],
        'item_galleries' => ['button_style_id'],
    ];

    /** @return list<array<string, mixed>> every style, by name */
    public function findAll(): array
    {
        return $this->db->query('SELECT * FROM button_styles ORDER BY name ASC, id ASC')->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM button_styles WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function nameTaken(string $name, ?int $exceptId = null): bool
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM button_styles WHERE name = :name AND id <> :id');
        $stmt->execute(['name' => $name, 'id' => $exceptId ?? 0]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /** @param array<string, string|int|null> $values name + COLUMNS */
    public function create(array $values): int
    {
        $values = $this->storable($values, true);
        $columns = array_keys($values);

        $this->db->prepare(
            'INSERT INTO button_styles (' . implode(', ', $columns) . ', created_at, updated_at)'
            . ' VALUES (:' . implode(', :', $columns) . ', NOW(), NOW())'
        )->execute($values);

        return (int) $this->db->lastInsertId();
    }

    /** @param array<string, string|int|null> $values name and/or COLUMNS */
    public function update(int $id, array $values): void
    {
        $values = $this->storable($values, false);
        if ($values === []) {
            return;
        }

        $sets = array_map(static fn (string $column): string => $column . ' = :' . $column, array_keys($values));
        $values['id'] = $id;

        $this->db->prepare('UPDATE button_styles SET ' . implode(', ', $sets) . ', updated_at = NOW() WHERE id = :id')
            ->execute($values);
    }

    /**
     * Deletes a style no default and no content button uses. The foreign
     * keys refuse a style that is still referenced, whatever was checked
     * before: false then, and nothing changed.
     */
    public function delete(int $id): bool
    {
        try {
            $stmt = $this->db->prepare('DELETE FROM button_styles WHERE id = :id');
            $stmt->execute(['id' => $id]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                return false;
            }

            throw $e;
        }

        return $stmt->rowCount() > 0;
    }

    /** @return array<string, int> role => style id */
    public function defaults(): array
    {
        $out = [];
        foreach ($this->db->query('SELECT role, button_style_id FROM button_style_defaults')->fetchAll() as $row) {
            if (in_array($row['role'], self::ROLES, true)) {
                $out[(string) $row['role']] = (int) $row['button_style_id'];
            }
        }

        return $out;
    }

    public function setDefault(string $role, int $styleId): void
    {
        if (!in_array($role, self::ROLES, true)) {
            throw new \InvalidArgumentException('Unknown button role ' . $role . '.');
        }

        $this->db->prepare(
            'INSERT INTO button_style_defaults (role, button_style_id, updated_at) VALUES (:role, :style, NOW())
             ON DUPLICATE KEY UPDATE button_style_id = VALUES(button_style_id), updated_at = NOW()'
        )->execute(['role' => $role, 'style' => $styleId]);
    }

    /**
     * Stores one content button's choice. NULL = the default.
     */
    public function saveChoice(string $table, string $column, int $rowId, ?int $styleId): void
    {
        if (!in_array($column, self::slots()[$table] ?? [], true)) {
            throw new \InvalidArgumentException('No button style is stored in ' . $table . '.' . $column . '.');
        }

        $this->db->prepare('UPDATE `' . $table . '` SET `' . $column . '` = :style WHERE id = :row_id')
            ->execute(['style' => $styleId, 'row_id' => $rowId]);
    }

    /**
     * How many content buttons chose each style, over every slot.
     *
     * @return array<int, int> style id => count
     */
    public function usageCounts(): array
    {
        $selects = $this->slotSelects();
        if ($selects === []) {
            return [];
        }

        // One round trip for every slot: a page asks this once per request.
        $counts = [];
        $rows = $this->db->query(
            'SELECT style_id, SUM(uses) AS uses FROM (' . implode(' UNION ALL ', $selects) . ') AS slots GROUP BY style_id'
        )->fetchAll();
        foreach ($rows as $row) {
            $counts[(int) $row['style_id']] = (int) $row['uses'];
        }

        return $counts;
    }

    /**
     * Every Core and module slot.
     *
     * @return array<string, list<string>>
     */
    public static function slots(): array
    {
        $slots = self::SLOTS;
        foreach (ModuleRegistry::all() as $module) {
            foreach ($module->buttonStyleSlots() as $table => $columns) {
                $slots[$table] = array_values(array_unique([...($slots[$table] ?? []), ...$columns]));
            }
        }

        return $slots;
    }

    /** @return list<string> one grouped SELECT per slot column */
    private function slotSelects(): array
    {
        $selects = [];
        foreach (self::slots() as $table => $columns) {
            if (preg_match('/^[a-z_]+$/', $table) !== 1) {
                continue;
            }

            foreach ($columns as $column) {
                if (preg_match('/^[a-z_]+$/', $column) !== 1) {
                    continue;
                }

                $selects[] = 'SELECT `' . $column . '` AS style_id, COUNT(*) AS uses FROM `' . $table . '`'
                    . ' WHERE `' . $column . '` IS NOT NULL GROUP BY `' . $column . '`';
            }
        }

        return $selects;
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, string|int|null>
     */
    private function storable(array $values, bool $complete): array
    {
        $out = [];
        foreach (['name', ...self::COLUMNS] as $column) {
            if (array_key_exists($column, $values)) {
                $value = $values[$column];
                $out[$column] = is_bool($value) ? (int) $value : $value;
            } elseif ($complete) {
                throw new \InvalidArgumentException('A button style needs ' . $column . '.');
            }
        }

        return $out;
    }
}

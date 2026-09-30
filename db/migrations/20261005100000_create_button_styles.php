<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Button Styles 2.0: named button designs the website keeps in one library,
 * and a choice per content button of which design it uses (THEMING.md,
 * "Knopstijlen").
 *
 *   button_styles           one row per design: a unique name and closed
 *                           words for the appearance, shape, size, border,
 *                           shadow, type, icon and hover. A colour is a word
 *                           for a theme colour (`primary`, `text`, …) or a
 *                           fixed #RRGGBB, never CSS.
 *   button_style_defaults   which design a button without a choice of its own
 *                           uses: `primary` (the website's standard button,
 *                           every plain .btn) and `secondary` (the second
 *                           button next to it, every .btn--ghost). A real
 *                           foreign key per role, RESTRICT, the pattern of
 *                           theme_font_roles (20261004100000).
 *   <block>.…button_style_id  on every table whose rows carry an editor's
 *                           button: NULL = "Standaard" (the role's default),
 *                           else the design, RESTRICT, so a design in use
 *                           cannot be deleted from under a block.
 *
 * THE CURRENT LOOK IS KEPT. "Primair" and "Secundair" are exactly what
 * assets/css/core.css drew as .btn and .btn--ghost, with the button shape the
 * website has now (theme_settings.button_shape, pill or rounded); they become
 * the two defaults. Every existing block gets NULL, so every existing button
 * renders exactly as before, and a site whose defaults still equal the
 * shipped look prints no button CSS at all. Two more designs ("Outline",
 * "Tekstlink") are ready to choose and cost nothing until one is chosen.
 *
 * ONE SOURCE OF TRUTH. Once the two defaults hold the shape, the
 * `button_shape` row leaves theme_settings; App\Service\Theme\ThemeSettings
 * reads and writes that key through the default designs from now on (the
 * Setup Wizard still asks for it). Idempotent: every step checks first, the
 * seed runs only on an empty table, the defaults only when unset. Fresh
 * install and upgrade end on the same schema and the same four designs.
 */
final class CreateButtonStyles extends AbstractMigration
{
    /**
     * The tables whose rows carry an editor's button, and the columns this
     * adds — App\Repository\ButtonStyleRepository::SLOTS plus the Shop's
     * featured_products (ShopModule::buttonStyleSlots()).
     */
    private const SLOTS = [
        'homepage_hero' => ['primary_button_style_id', 'secondary_button_style_id'],
        'cta_bands' => ['primary_button_style_id', 'secondary_button_style_id'],
        'rich_text_sections' => ['button_style_id'],
        'text_image_split_items' => ['button_style_id'],
        'carousel_cards' => ['button_style_id'],
        'hover_card_grid_items' => ['button_style_id'],
        'detail_sections' => ['button_style_id'],
        'contact_cards' => ['button_style_id'],
        'item_galleries' => ['button_style_id'],
        'featured_products' => ['button_style_id'],
    ];

    /** The shape keys the old setting could hold; anything else was pill. */
    private const LEGACY_SHAPES = ['pill', 'rounded'];

    public function up(): void
    {
        if (!$this->hasTable('button_styles')) {
            $word = static fn (string $default, string $comment): array => ['limit' => 16, 'null' => false, 'default' => $default, 'comment' => $comment];
            $colour = static fn (?string $default, string $comment): array => ['limit' => 16, 'null' => $default === null, 'default' => $default, 'comment' => $comment];

            $this->table('button_styles', ['id' => true])
                ->addColumn('name', 'string', ['limit' => 80, 'null' => false, 'comment' => 'CMS label only; CSS uses btn-style-<id>'])
                ->addColumn('appearance', 'string', $word('filled', 'filled|outline|ghost|text'))
                ->addColumn('shape', 'string', $word('pill', 'square|soft|rounded|round|pill'))
                ->addColumn('size', 'string', $word('normal', 'compact|normal|large'))
                ->addColumn('fill_color', 'string', $colour('primary', 'theme colour word or #RRGGBB'))
                ->addColumn('fill_gradient', 'boolean', ['null' => false, 'default' => 0])
                ->addColumn('text_color', 'string', $colour('on_primary', 'theme colour word or #RRGGBB'))
                ->addColumn('border_width', 'string', $word('none', 'none|thin|normal|thick'))
                ->addColumn('border_color', 'string', $colour('primary', 'theme colour word or #RRGGBB'))
                ->addColumn('shadow', 'string', $word('none', 'none|subtle|normal|strong'))
                ->addColumn('font_weight', 'string', $word('bold', 'normal|semibold|bold'))
                ->addColumn('font_role', 'string', $word('body', 'body|heading'))
                ->addColumn('uppercase', 'boolean', ['null' => false, 'default' => 0])
                ->addColumn('underline', 'boolean', ['null' => false, 'default' => 0])
                ->addColumn('icon', 'string', $word('none', 'App\Service\Theme\ButtonIcons key'))
                ->addColumn('icon_position', 'string', $word('after', 'before|after'))
                ->addColumn('icon_gap', 'string', $word('normal', 'small|normal|large'))
                ->addColumn('icon_motion', 'boolean', ['null' => false, 'default' => 1])
                ->addColumn('hover_effect', 'string', $word('glow', 'none|lift|glow|shadow|brighten'))
                ->addColumn('hover_fill_color', 'string', $colour(null, 'NULL = unchanged'))
                ->addColumn('hover_text_color', 'string', $colour(null, 'NULL = unchanged'))
                ->addColumn('hover_border_color', 'string', $colour(null, 'NULL = unchanged'))
                ->addColumn('created_at', 'datetime', ['null' => true, 'default' => null])
                ->addColumn('updated_at', 'datetime', ['null' => true, 'default' => null])
                ->addIndex(['name'], ['unique' => true, 'name' => 'uq_button_styles_name'])
                ->create();
        }

        if (!$this->hasTable('button_style_defaults')) {
            $this->table('button_style_defaults', ['id' => false, 'primary_key' => ['role']])
                ->addColumn('role', 'string', ['limit' => 10, 'null' => false, 'comment' => 'primary|secondary'])
                ->addColumn('button_style_id', 'integer', ['signed' => false, 'null' => false])
                ->addColumn('updated_at', 'datetime', ['null' => true, 'default' => null])
                ->addIndex(['button_style_id'], ['name' => 'idx_button_style_defaults_style'])
                ->create();
        }

        if (!$this->hasForeignKeyNamed('button_style_defaults', 'fk_button_style_defaults_style')) {
            $this->table('button_style_defaults')
                ->addForeignKey('button_style_id', 'button_styles', 'id', [
                    'delete' => 'RESTRICT',
                    'update' => 'CASCADE',
                    'constraint' => 'fk_button_style_defaults_style',
                ])
                ->update();
        }

        foreach (self::SLOTS as $table => $columns) {
            if (!$this->hasTable($table)) {
                continue;
            }

            foreach ($columns as $column) {
                if (!$this->table($table)->hasColumn($column)) {
                    $this->table($table)->addColumn($column, 'integer', [
                        'signed' => false,
                        'null' => true,
                        'default' => null,
                        'comment' => 'NULL = the default button; else button_styles.id',
                    ])->addIndex([$column], ['name' => 'idx_' . $table . '_' . substr($column, 0, -3)])->update();
                }

                $constraint = 'fk_' . $table . '_' . substr($column, 0, -3);
                if (!$this->hasForeignKeyNamed($table, $constraint)) {
                    $this->table($table)
                        ->addForeignKey($column, 'button_styles', 'id', [
                            'delete' => 'RESTRICT',
                            'update' => 'CASCADE',
                            'constraint' => $constraint,
                        ])
                        ->update();
                }
            }
        }

        $shape = $this->currentShape();

        $count = $this->fetchRow('SELECT COUNT(*) AS c FROM button_styles');
        if ((int) ($count['c'] ?? 0) === 0) {
            foreach ($this->seeds($shape) as $seed) {
                $connection = $this->getAdapter()->getConnection();
                $columns = array_keys($seed);
                $values = array_map(
                    static fn (mixed $value): string => $value === null ? 'NULL' : $connection->quote((string) $value),
                    array_values($seed)
                );
                $this->execute(
                    'INSERT INTO button_styles (' . implode(', ', $columns) . ', created_at, updated_at)'
                    . ' VALUES (' . implode(', ', $values) . ', NOW(), NOW())'
                );
            }
        }

        foreach (['primary' => 'Primair', 'secondary' => 'Secundair'] as $role => $name) {
            $set = $this->fetchRow("SELECT COUNT(*) AS c FROM button_style_defaults WHERE role = '" . $role . "'");
            if ((int) ($set['c'] ?? 0) > 0) {
                continue;
            }

            // The seeded design by name, or (a library someone already
            // renamed) the oldest one: a role is never left without a design.
            $row = $this->fetchRow("SELECT id FROM button_styles WHERE name = '" . $name . "' LIMIT 1")
                ?: $this->fetchRow('SELECT id FROM button_styles ORDER BY id ASC LIMIT 1');
            if ($row) {
                $this->execute(sprintf(
                    "INSERT INTO button_style_defaults (role, button_style_id, updated_at) VALUES ('%s', %d, NOW())",
                    $role,
                    (int) $row['id']
                ));
            }
        }

        // Only now that the defaults hold the shape: remove the second copy.
        if ($this->hasTable('theme_settings')) {
            $this->execute("DELETE FROM theme_settings WHERE setting_key = 'button_shape'");
        }
    }

    /** Forward-only (db/migrations/CLAUDE.md). */
    public function down(): void
    {
    }

    /** The shape the website's buttons have now: the stored row, or pill. */
    private function currentShape(): string
    {
        if (!$this->hasTable('theme_settings')) {
            return 'pill';
        }

        $row = $this->fetchRow("SELECT setting_value FROM theme_settings WHERE setting_key = 'button_shape' LIMIT 1");
        $value = is_array($row) ? trim((string) ($row['setting_value'] ?? '')) : '';

        return in_array($value, self::LEGACY_SHAPES, true) ? $value : 'pill';
    }

    /**
     * The four shipped designs. "Primair" and "Secundair" are core.css's .btn
     * and .btn--ghost, word for word (App\Service\Theme\ButtonStyleCss::
     * LEGACY_PRIMARY / LEGACY_SECONDARY pin that).
     *
     * @return list<array<string, string|int|null>>
     */
    private function seeds(string $shape): array
    {
        $base = [
            'appearance' => 'filled', 'shape' => $shape, 'size' => 'normal',
            'fill_color' => 'primary', 'fill_gradient' => 0, 'text_color' => 'on_primary',
            'border_width' => 'none', 'border_color' => 'primary', 'shadow' => 'none',
            'font_weight' => 'bold', 'font_role' => 'body', 'uppercase' => 0, 'underline' => 0,
            'icon' => 'none', 'icon_position' => 'after', 'icon_gap' => 'normal', 'icon_motion' => 1,
            'hover_effect' => 'glow', 'hover_fill_color' => null, 'hover_text_color' => null, 'hover_border_color' => null,
        ];

        return [
            ['name' => 'Primair', 'fill_gradient' => 1] + $base,
            ['name' => 'Secundair', 'appearance' => 'outline', 'text_color' => 'text', 'border_width' => 'normal',
                'border_color' => 'line_strong', 'hover_effect' => 'lift', 'hover_fill_color' => 'primary_wash',
                'hover_text_color' => 'primary_bright', 'hover_border_color' => 'primary'] + $base,
            ['name' => 'Outline', 'appearance' => 'outline', 'shape' => 'pill', 'text_color' => 'primary', 'border_width' => 'normal',
                'border_color' => 'primary', 'hover_effect' => 'lift', 'hover_fill_color' => 'primary_wash',
                'hover_text_color' => 'primary_bright', 'hover_border_color' => 'primary_bright'] + $base,
            ['name' => 'Tekstlink', 'appearance' => 'text', 'shape' => 'pill', 'text_color' => 'primary', 'font_weight' => 'semibold',
                'icon' => 'arrow_right', 'hover_effect' => 'none', 'hover_text_color' => 'primary_bright'] + $base,
        ];
    }

    /**
     * Phinx's own hasForeignKey() matches on columns; the name is what this
     * migration creates, so that is what it checks.
     */
    private function hasForeignKeyNamed(string $table, string $constraint): bool
    {
        $row = $this->fetchRow(sprintf(
            "SELECT COUNT(*) AS c FROM information_schema.referential_constraints
              WHERE constraint_schema = DATABASE() AND table_name = '%s' AND constraint_name = '%s'",
            $table,
            $constraint
        ));

        return (int) ($row['c'] ?? 0) > 0;
    }
}

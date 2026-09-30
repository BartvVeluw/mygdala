<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Font Library 1.0 (Branding & Design 2.0, THEMING.md "Font Library"):
 * font families an administrator uploads, and where the site and a page
 * theme use one.
 *
 *   font_families      one row per family: a name for the CMS (never CSS),
 *                      its kind (serif or sans, which picks the fallback
 *                      stack) and an optional source or licence link.
 *   font_files         one row per variant: weight (100-900) and style
 *                      (normal/italic), unique per family; the verified
 *                      format and the generated file name under
 *                      assets/fonts/library/. Cascades with its family: the
 *                      rows of a deleted family go with it (the CMS deletes
 *                      the files).
 *   theme_font_roles   the website's own choice per role ('heading',
 *                      'body'). No row = the role follows the font pairing
 *                      (theme_settings.font_pairing), exactly as before.
 *   page_themes        heading_font_family_id / body_font_family_id, NULL =
 *                      the theme's font pairing, as before.
 *
 * Every use is a real foreign key, ON DELETE RESTRICT: a family the website
 * or a page theme uses cannot be deleted, not by the CMS (which says who
 * uses it) and not by hand. There is no silent fallback after a delete.
 *
 * NOTHING EXISTING CHANGES: new tables and two nullable columns, no row
 * written. theme_settings, color_palettes and every page theme's
 * font_pairing stay exactly as they were, so every site and every page
 * theme keeps its fonts and no uploaded font is ever switched on by this.
 * A fresh install gets the same schema and an empty library. Idempotent:
 * every step checks first.
 */
final class CreateFontLibrary extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('font_families')) {
            $this->table('font_families', ['id' => true])
                ->addColumn('name', 'string', ['limit' => 80, 'null' => false, 'comment' => 'CMS label only; CSS uses mygdala-font-<id>'])
                ->addColumn('category', 'string', ['limit' => 10, 'null' => false, 'default' => 'sans', 'comment' => 'sans|serif: the fallback stack'])
                ->addColumn('source_url', 'string', ['limit' => 500, 'null' => true, 'default' => null, 'comment' => 'optional source or licence link (http/https)'])
                ->addColumn('created_at', 'datetime', ['null' => true, 'default' => null])
                ->addColumn('updated_at', 'datetime', ['null' => true, 'default' => null])
                ->addIndex(['name'], ['unique' => true, 'name' => 'uq_font_families_name'])
                ->create();
        }

        if (!$this->hasTable('font_files')) {
            $this->table('font_files', ['id' => true])
                ->addColumn('font_family_id', 'integer', ['signed' => false, 'null' => false])
                ->addColumn('weight', 'smallinteger', ['signed' => false, 'null' => false, 'comment' => '100-900'])
                ->addColumn('style', 'string', ['limit' => 6, 'null' => false, 'comment' => 'normal|italic'])
                ->addColumn('format', 'string', ['limit' => 5, 'null' => false, 'comment' => 'woff2|woff|ttf|otf, from the bytes'])
                ->addColumn('file_name', 'string', ['limit' => 40, 'null' => false, 'comment' => 'generated, under assets/fonts/library/'])
                ->addColumn('original_filename', 'string', ['limit' => 255, 'null' => false, 'comment' => 'display only, never a path'])
                ->addColumn('byte_size', 'integer', ['signed' => false, 'null' => false])
                ->addColumn('created_at', 'datetime', ['null' => true, 'default' => null])
                ->addColumn('updated_at', 'datetime', ['null' => true, 'default' => null])
                ->addIndex(['font_family_id', 'weight', 'style'], ['unique' => true, 'name' => 'uq_font_files_variant'])
                ->addIndex(['file_name'], ['unique' => true, 'name' => 'uq_font_files_file_name'])
                ->create();
        }

        if (!$this->hasForeignKeyNamed('font_files', 'fk_font_files_family')) {
            $this->table('font_files')
                ->addForeignKey('font_family_id', 'font_families', 'id', [
                    'delete' => 'CASCADE',
                    'update' => 'CASCADE',
                    'constraint' => 'fk_font_files_family',
                ])
                ->update();
        }

        if (!$this->hasTable('theme_font_roles')) {
            $this->table('theme_font_roles', ['id' => false, 'primary_key' => ['role']])
                ->addColumn('role', 'string', ['limit' => 10, 'null' => false, 'comment' => 'heading|body'])
                ->addColumn('font_family_id', 'integer', ['signed' => false, 'null' => false])
                ->addColumn('updated_at', 'datetime', ['null' => true, 'default' => null])
                ->addIndex(['font_family_id'], ['name' => 'idx_theme_font_roles_family'])
                ->create();
        }

        if (!$this->hasForeignKeyNamed('theme_font_roles', 'fk_theme_font_roles_family')) {
            $this->table('theme_font_roles')
                ->addForeignKey('font_family_id', 'font_families', 'id', [
                    'delete' => 'RESTRICT',
                    'update' => 'CASCADE',
                    'constraint' => 'fk_theme_font_roles_family',
                ])
                ->update();
        }

        if ($this->hasTable('page_themes')) {
            $themes = $this->table('page_themes');
            foreach (['heading_font_family_id' => 'font_pairing', 'body_font_family_id' => 'heading_font_family_id'] as $column => $after) {
                if (!$themes->hasColumn($column)) {
                    $this->table('page_themes')->addColumn($column, 'integer', [
                        'signed' => false,
                        'null' => true,
                        'default' => null,
                        'after' => $after,
                        'comment' => 'NULL = the font pairing; else font_families.id',
                    ])->addIndex([$column], ['name' => 'idx_page_themes_' . substr($column, 0, -strlen('_font_family_id')) . '_font'])->update();
                    $themes = $this->table('page_themes');
                }
            }

            foreach (['heading', 'body'] as $role) {
                $constraint = 'fk_page_themes_' . $role . '_font';
                if (!$this->hasForeignKeyNamed('page_themes', $constraint)) {
                    $this->table('page_themes')
                        ->addForeignKey($role . '_font_family_id', 'font_families', 'id', [
                            'delete' => 'RESTRICT',
                            'update' => 'CASCADE',
                            'constraint' => $constraint,
                        ])
                        ->update();
                }
            }
        }
    }

    /** Forward-only (db/migrations/CLAUDE.md). */
    public function down(): void
    {
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

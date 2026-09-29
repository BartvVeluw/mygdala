<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Page Themes 1.0 (the optional module `page_themes`, THEMING.md,
 * "Paginathema's"): a named colour palette plus a font pairing that ONE
 * ordinary CMS page may use instead of the site theme.
 *
 *   page_themes          one row per named theme. The five colours are the
 *                        same five roles as the site theme (theme_settings),
 *                        stored in the one canonical form #RRGGBB; the font
 *                        pairing is a key of App\Service\Theme\ThemeFonts.
 *                        Both are validated again whenever they are read, so
 *                        a hand-edited row can never reach a stylesheet.
 *   pages.page_theme_id  NULL = the site theme (every existing page, and
 *                        every new one). No parent inheritance: only the
 *                        page's own value counts.
 *
 * The foreign key is RESTRICT on delete: a theme that a page still uses
 * cannot be removed, by the CMS (which refuses with the list of those pages)
 * or by hand. Switching the module off never touches either table — the
 * relation is kept and simply ignored while the module is off (MODULES.md).
 *
 * A NEW TABLE AND ONE NULLABLE COLUMN, nothing rewritten: every existing page
 * reads exactly as before. Idempotent: every step checks first.
 */
final class CreatePageThemes extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('page_themes')) {
            $this->table('page_themes', ['id' => true])
                ->addColumn('name', 'string', ['limit' => 80, 'null' => false])
                ->addColumn('slug', 'string', ['limit' => 80, 'null' => false, 'comment' => '[a-z0-9-]: the data-page-theme attribute value'])
                ->addColumn('primary_color', 'char', ['limit' => 7, 'null' => false])
                ->addColumn('on_primary_color', 'char', ['limit' => 7, 'null' => false])
                ->addColumn('background_color', 'char', ['limit' => 7, 'null' => false])
                ->addColumn('surface_color', 'char', ['limit' => 7, 'null' => false])
                ->addColumn('text_color', 'char', ['limit' => 7, 'null' => false])
                ->addColumn('font_pairing', 'string', ['limit' => 40, 'null' => false, 'comment' => 'App\\Service\\Theme\\ThemeFonts key'])
                ->addColumn('created_at', 'datetime', ['null' => true, 'default' => null])
                ->addColumn('updated_at', 'datetime', ['null' => true, 'default' => null])
                ->addIndex(['name'], ['unique' => true, 'name' => 'uq_page_themes_name'])
                ->addIndex(['slug'], ['unique' => true, 'name' => 'uq_page_themes_slug'])
                ->create();
        }

        $pages = $this->table('pages');
        if (!$pages->hasColumn('page_theme_id')) {
            $pages->addColumn('page_theme_id', 'integer', [
                'signed' => false,
                'null' => true,
                'default' => null,
                'after' => 'owner_type',
                'comment' => 'NULL = the site theme; else page_themes.id (module page_themes)',
            ])->addIndex(['page_theme_id'], ['name' => 'idx_pages_page_theme'])->update();
        }

        if (!$this->hasForeignKeyNamed('pages', 'fk_pages_page_theme')) {
            $this->table('pages')
                ->addForeignKey('page_theme_id', 'page_themes', 'id', [
                    'delete' => 'RESTRICT',
                    'update' => 'CASCADE',
                    'constraint' => 'fk_pages_page_theme',
                ])
                ->update();
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

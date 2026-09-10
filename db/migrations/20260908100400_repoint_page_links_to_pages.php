<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Re-points the CMS-page link target of nav_items and footer_links from
 * information_pages.id to pages.id.
 *
 * Both tables already store a stable numeric page id rather than a slug (see
 * 20260907210000_create_nav_items_table.php and App\Service\LinkResolver) —
 * that design is unchanged, only the table it points at changes, because
 * every information page is now a row in the unified `pages` table
 * (20260908100000_create_pages_table.php). Values are remapped by slug,
 * which is unique in both tables and was copied across verbatim, so every
 * existing navigation and footer link keeps resolving to exactly the same
 * page. A link whose target no longer exists is set to NULL rather than left
 * pointing at a stale id (LinkResolver already renders such a row as if it
 * didn't exist, instead of emitting a dead link).
 *
 * The foreign key is recreated with ON DELETE RESTRICT instead of the
 * previous SET NULL: deleting a page that is still linked from the menu or
 * the footer must not silently blank those links out. The admin delete flow
 * (App\Service\PageService::delete()) checks for references first and
 * refuses with a message naming how many navigation/footer links still point
 * at the page; this constraint is the defence-in-depth net behind that
 * check, matching how nav_items.parent_id already uses RESTRICT for the
 * "item with children" case.
 *
 * Constraint names are looked up from information_schema rather than assumed
 * to be MySQL's auto-generated `<table>_ibfk_N` — that numbering depends on
 * the order constraints happened to be created in.
 */
final class RepointPageLinksToPages extends AbstractMigration
{
    private const TABLES = ['nav_items', 'footer_links'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (!$this->hasTable($table)) {
                continue;
            }

            $this->dropTargetPageForeignKey($table);

            if ($this->hasTable('information_pages')) {
                $this->execute(
                    "UPDATE {$table} t
                        JOIN information_pages ip ON ip.id = t.target_page_id
                        JOIN pages p ON p.slug = ip.slug
                     SET t.target_page_id = p.id
                     WHERE t.target_page_id IS NOT NULL"
                );
            }

            // Anything that still doesn't match a real page (a target that
            // was already dangling) becomes NULL, so the new FK can be added
            // and LinkResolver skips the row instead of rendering a dead link.
            $this->execute(
                "UPDATE {$table} t
                    LEFT JOIN pages p ON p.id = t.target_page_id
                 SET t.target_page_id = NULL
                 WHERE t.target_page_id IS NOT NULL AND p.id IS NULL"
            );

            $this->table($table)
                ->addForeignKey('target_page_id', 'pages', 'id', [
                    'delete' => 'RESTRICT',
                    'update' => 'CASCADE',
                ])
                ->update();
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (!$this->hasTable($table)) {
                continue;
            }

            $this->dropTargetPageForeignKey($table);

            if ($this->hasTable('information_pages')) {
                $this->execute(
                    "UPDATE {$table} t
                        JOIN pages p ON p.id = t.target_page_id
                        JOIN information_pages ip ON ip.slug = p.slug
                     SET t.target_page_id = ip.id
                     WHERE t.target_page_id IS NOT NULL"
                );

                $this->execute(
                    "UPDATE {$table} t
                        LEFT JOIN information_pages ip ON ip.id = t.target_page_id
                     SET t.target_page_id = NULL
                     WHERE t.target_page_id IS NOT NULL AND ip.id IS NULL"
                );

                $this->table($table)
                    ->addForeignKey('target_page_id', 'information_pages', 'id', [
                        'delete' => 'SET_NULL',
                        'update' => 'CASCADE',
                    ])
                    ->update();
            }
        }
    }

    /**
     * Drops whichever foreign key currently constrains <table>.target_page_id,
     * whatever it happens to be named.
     */
    private function dropTargetPageForeignKey(string $table): void
    {
        $rows = $this->fetchAll(
            "SELECT CONSTRAINT_NAME
               FROM information_schema.KEY_COLUMN_USAGE
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = '{$table}'
                AND COLUMN_NAME = 'target_page_id'
                AND REFERENCED_TABLE_NAME IS NOT NULL"
        );

        foreach ($rows as $row) {
            $name = (string) $row['CONSTRAINT_NAME'];
            $this->execute("ALTER TABLE {$table} DROP FOREIGN KEY `{$name}`");
        }
    }
}

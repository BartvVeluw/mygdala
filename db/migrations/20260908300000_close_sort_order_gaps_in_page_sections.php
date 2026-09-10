<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Repairs the "one page = one contiguous ordered list" invariant that
 * db/migrations/20260908250000_flatten_page_sections_into_one_list.php
 * established.
 *
 * Deleting a block from the page builder used to leave a hole in that page's
 * `sort_order` (the DELETE renumbered nothing), so any page an editor had
 * removed a section from was numbered 0,2,3,… instead of 0,1,2,…. Harmless
 * for rendering — everything is ORDER BY sort_order — but the invariant is
 * what the page builder, the flatten migration and
 * Tests\Repository\PageSectionsBackfillTest all assume, so the holes are
 * closed here and prevented from coming back in
 * App\Repository\PageSectionRepository::delete(), which now resequences the
 * page it just deleted from.
 *
 * Renumbering only: every page keeps the exact order it renders in today, no
 * row is added, removed or moved relative to another. Idempotent (a page
 * that is already 0..n-1 is left untouched) and forward-only.
 * MySQL/Vimexx-compatible: plain SELECT/UPDATE, no CTEs and no window
 * functions.
 */
final class CloseSortOrderGapsInPageSections extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('page_sections')) {
            return;
        }

        $pages = $this->query('SELECT DISTINCT page_id FROM page_sections ORDER BY page_id ASC')
            ->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($pages as $page) {
            $this->resequence((int) $page['page_id']);
        }
    }

    /**
     * A renumbering has no meaningful inverse — reintroducing the gaps would
     * be a regression, not a rollback.
     */
    public function down(): void
    {
    }

    private function resequence(int $pageId): void
    {
        $rows = $this->query(
            'SELECT id, sort_order FROM page_sections WHERE page_id = ' . $pageId
            . ' ORDER BY sort_order ASC, id ASC'
        )->fetchAll(\PDO::FETCH_ASSOC);

        foreach (array_values($rows) as $position => $row) {
            if ((int) $row['sort_order'] === $position) {
                continue;
            }

            $this->execute(
                'UPDATE page_sections SET sort_order = ' . $position
                . ', updated_at = NOW() WHERE id = ' . (int) $row['id']
            );
        }
    }
}

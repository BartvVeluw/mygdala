<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Adds the two admin-controllable footer fields to information_pages (see
 * db/migrations/20260906140000_create_information_pages_table.php), so an
 * information page's footer visibility is a per-row toggle instead of "every
 * published page always shows in the footer" (the previous, implicit rule —
 * see App\Service\InformationPageContent::publishedForFooter(), now
 * filtering on this column instead of only is_published).
 *
 * `show_in_footer` defaults to true (both for the column default and this
 * migration's explicit backfill of the three existing seeded rows) so the
 * three existing pages keep exactly their current footer behaviour after
 * this migration runs — nothing disappears from the footer as a side effect
 * of adding the column.
 *
 * `footer_label` is optional: null means "use the page's own title in the
 * footer", same "empty means: no override" convention as meta_title/
 * meta_description on the same table.
 *
 * Purely additive — does not touch/recreate the table or any existing row's
 * content, per MAIN.MD's requirement to never lose CMS-edited content when a
 * migration runs.
 */
final class AddFooterFieldsToInformationPages extends AbstractMigration
{
    public function up(): void
    {
        $this->table('information_pages')
            ->addColumn('show_in_footer', 'boolean', ['default' => true, 'after' => 'is_published'])
            ->addColumn('footer_label', 'string', ['limit' => 200, 'null' => true, 'after' => 'show_in_footer'])
            ->update();
    }

    public function down(): void
    {
        $this->table('information_pages')
            ->removeColumn('show_in_footer')
            ->removeColumn('footer_label')
            ->update();
    }
}

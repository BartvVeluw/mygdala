<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Header buttons become navigation items: `nav_items` learns how an item is
 * PRESENTED, and the one call-to-action button that lived in `site_settings`
 * is copied into it as the first button.
 *
 * WHY NOT A SECOND TABLE. A header button already had exactly the shape of a
 * menu item — two labels, a link_type with one companion field, a new-tab
 * flag — and went through the same App\Service\LinkResolver (see
 * db/migrations/20260909220000_pin_header_cta_and_footer_slogan.php). What
 * it lacked was an order, a visibility switch and the chance to exist more
 * than once, and `nav_items` has all three. One model means one resolver,
 * one page-usage list (App\Service\PageUsage) and one "this page is still
 * linked, unlink it first" rule (App\Service\PageService::references()) for
 * every link in the header, instead of a parallel system that would have to
 * grow each of those again. HEADER-FOOTER.md records the choice.
 *
 * THE TWO COLUMNS, both closed lists validated in PHP rather than as a MySQL
 * ENUM, the convention link_type set (App\Service\NavigationPresentation):
 *
 *   presentation    'link'    an entry of the menu list (every existing row)
 *                   'button'  a button in the header's action area
 *   button_variant  'primary' the filled .btn the old CTA always had
 *                   'ghost'   the existing .btn--ghost, for a second, quieter
 *                             button; ignored while presentation is 'link'
 *
 * NOT NULL with a default, so ADD COLUMN writes 'link' and 'primary' into
 * every existing row: the menu an installation shows today is the menu it
 * shows after this migration, item for item.
 *
 * THE COPY. What the header rendered is what the header keeps rendering:
 *
 *   - label, link type, the companion field of THAT type and the new-tab flag
 *     are taken over as stored;
 *   - the button is visible exactly when HeaderCta rendered it: switched on
 *     AND with a Dutch label. A configured button that was switched off is
 *     copied hidden rather than dropped, so nothing an editor typed is lost;
 *   - a page id that no longer exists becomes NULL. The foreign key on
 *     target_page_id (RESTRICT) would refuse it, and LinkResolver treats a
 *     page link without a page as "render nothing" — which is what the old
 *     button did for a deleted page too;
 *   - an unconfigured button (a fresh install: no rows at all) copies nothing.
 *
 * THE OLD ROWS STAY. The `header_cta_*` settings are not deleted and not
 * emptied; nothing reads them any more (partials/header.php renders
 * App\Service\NavigationService::header()), and removing them is a separate,
 * destructive decision. Same approach as page_heroes.breadcrumb_label_*.
 *
 * Schema first and unconditionally, data after it (db/migrations/CLAUDE.md).
 * Idempotent: the columns are checked before the ALTER, and the copy is
 * skipped when any button row already exists, so a second run never adds a
 * second button. MySQL/Vimexx: plain statements, no CTEs.
 */
final class MoveTheHeaderButtonIntoTheNavigation extends AbstractMigration
{
    private const LINK_TYPES = ['page', 'route', 'external'];

    public function up(): void
    {
        if (!$this->hasTable('nav_items')) {
            return;
        }

        $this->addPresentationColumns();
        $this->copyTheHeaderButton();
    }

    private function addPresentationColumns(): void
    {
        $table = $this->table('nav_items');

        if (!$table->hasColumn('presentation')) {
            $table->addColumn('presentation', 'string', [
                'limit' => 20,
                'null' => false,
                'default' => 'link',
                'after' => 'open_in_new_tab',
                'comment' => 'link or button; see App\Service\NavigationPresentation',
            ])->update();
        }

        $table = $this->table('nav_items');

        if (!$table->hasColumn('button_variant')) {
            $table->addColumn('button_variant', 'string', [
                'limit' => 20,
                'null' => false,
                'default' => 'primary',
                'after' => 'presentation',
                'comment' => 'primary or ghost, only read for a button',
            ])->update();
        }
    }

    private function copyTheHeaderButton(): void
    {
        if (!$this->hasTable('site_settings')) {
            return;
        }

        $existing = $this->fetchRow("SELECT COUNT(*) AS c FROM nav_items WHERE presentation = 'button'");
        if ((int) $existing['c'] > 0) {
            return;
        }

        $settings = [];
        foreach ($this->fetchAll("SELECT setting_key, setting_value FROM site_settings WHERE setting_key LIKE 'header\\_cta\\_%'") as $row) {
            $settings[(string) $row['setting_key']] = (string) ($row['setting_value'] ?? '');
        }

        $value = static fn (string $key): string => trim($settings[$key] ?? '');

        $enabled = $value('header_cta_enabled') === '1';
        $labelNl = $value('header_cta_label_nl');
        $labelEn = $value('header_cta_label_en');
        $pageIdRaw = $value('header_cta_target_page_id');
        $route = $value('header_cta_target_route');
        $externalUrl = $value('header_cta_external_url');

        $configured = $enabled || $labelNl !== '' || $labelEn !== ''
            || $pageIdRaw !== '' || $route !== '' || $externalUrl !== '';

        if (!$configured) {
            return;
        }

        $linkType = in_array($value('header_cta_link_type'), self::LINK_TYPES, true)
            ? $value('header_cta_link_type')
            : 'page';

        $pageId = null;
        if ($linkType === 'page' && ctype_digit($pageIdRaw)) {
            $page = $this->fetchRow('SELECT id FROM pages WHERE id = ' . (int) $pageIdRaw);
            if (is_array($page) && isset($page['id'])) {
                $pageId = (int) $page['id'];
            }
        }

        $now = date('Y-m-d H:i:s');

        $this->execute(
            'INSERT INTO nav_items
                (label_nl, label_en, link_type, target_page_id, target_route, external_url, open_in_new_tab,
                 presentation, button_variant, parent_id, sort_order, is_visible, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, \'button\', \'primary\', NULL, 0, ?, ?, ?)',
            [
                mb_substr($labelNl, 0, 100),
                mb_substr($labelEn, 0, 100),
                $linkType,
                $pageId,
                $linkType === 'route' && $route !== '' ? mb_substr($route, 0, 50) : null,
                $linkType === 'external' && $externalUrl !== '' ? $externalUrl : null,
                $value('header_cta_open_in_new_tab') === '1' ? 1 : 0,
                $enabled && $labelNl !== '' ? 1 : 0,
                $now,
                $now,
            ]
        );
    }

    /**
     * Forward-only in practice (db/migrations/CLAUDE.md). The copied button
     * is ordinary content by now and the old settings rows were never
     * touched, so there is nothing to undo that would not lose an edit.
     */
    public function down(): void
    {
    }
}

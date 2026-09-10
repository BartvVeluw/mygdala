<?php

declare(strict_types=1);

use App\Install\InstallState;
use Phinx\Migration\AbstractMigration;

/**
 * Global, CMS-managed footer link columns — replaces the hardcoded
 * "Navigatie"/"Materialen"/"Informatie"/"Contact" columns in
 * partials/footer.php. Company identity (logo/name/email/phone/KVK) is
 * NOT duplicated here — see the follow-up settings-seed migration and
 * App\Service\FooterService; this table only owns the link columns
 * themselves.
 *
 * footer_columns: an ordered, hideable group of links (title only — no
 * company data). footer_links: same link-target shape as nav_items (see
 * that migration's docblock) plus 'action', for a controlled, non-arbitrary
 * behaviour instead of a navigation destination — currently only
 * 'cookie_preferences' (opens the existing cookie-consent modal; see
 * App\Service\LinkResolver::ALLOWED_ACTIONS and partials/cookie-consent.php).
 * No footer link ever stores or executes arbitrary JavaScript.
 *
 * column_id CASCADEs on delete: a footer link only ever makes sense inside
 * its column, unlike nav_items' parent_id (RESTRICT) where a *sibling*
 * subtree must be preserved — deleting a footer column deleting its own
 * links is exactly the safe, expected behaviour (same reasoning as
 * portfolio_item_categories' "owning side" CASCADE).
 *
 * Backfill matches partials/footer.php's current 4 columns exactly, so the
 * public footer's output is unchanged immediately after this migration
 * runs (see MAIN.MD, "Global Navigation + Footer" — the Contact column's
 * email/city move into the new footer_show_email/company-block settings
 * instead of staying a column link, since they aren't really "links"; the
 * bottom legal bar — cookie policy + Cookie-instellingen — is deliberately
 * left as-is, a fixed utility strip outside the column system, same
 * KIND_FUNCTIONAL-style judgement call as AdminPageRegistry makes for the
 * quicknav/lightbox/filter bar).
 */
final class CreateFooterTables extends AbstractMigration
{
    public function up(): void
    {
        $this->table('footer_columns', ['id' => true])
            ->addColumn('title_nl', 'string', ['limit' => 100])
            ->addColumn('title_en', 'string', ['limit' => 100])
            ->addColumn('sort_order', 'integer', ['default' => 0])
            ->addColumn('is_visible', 'boolean', ['default' => true])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->create();

        $this->table('footer_links', ['id' => true])
            ->addColumn('column_id', 'integer', ['signed' => false])
            ->addColumn('label_nl', 'string', ['limit' => 100])
            ->addColumn('label_en', 'string', ['limit' => 100])
            ->addColumn('link_type', 'string', ['limit' => 20])
            ->addColumn('target_page_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('target_route', 'string', ['limit' => 50, 'null' => true])
            ->addColumn('external_url', 'string', ['limit' => 2048, 'null' => true])
            ->addColumn('action_key', 'string', ['limit' => 50, 'null' => true])
            ->addColumn('open_in_new_tab', 'boolean', ['default' => false])
            ->addColumn('sort_order', 'integer', ['default' => 0])
            ->addColumn('is_visible', 'boolean', ['default' => true])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['column_id', 'sort_order'])
            ->addForeignKey('column_id', 'footer_columns', 'id', [
                'delete' => 'CASCADE',
                'update' => 'CASCADE',
            ])
            ->addForeignKey('target_page_id', 'information_pages', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'CASCADE',
            ])
            ->create();

        $existing = $this->fetchRow('SELECT COUNT(*) AS c FROM footer_columns');
        if ((int) $existing['c'] > 0) {
            return;
        }

        if (InstallState::isFreshInstall($this)) {
            // Four columns of Van Veluw Laserdesign links, half of them
            // pointing at pages a generic install does not have. A new
            // install starts with an empty footer and builds its own — see
            // src/Install/InstallState.php.
            return;
        }

        $now = date('Y-m-d H:i:s');
        $pdo = $this->getAdapter()->getConnection();

        $insertColumn = $pdo->prepare(
            'INSERT INTO footer_columns (title_nl, title_en, sort_order, is_visible, created_at, updated_at)
             VALUES (:title_nl, :title_en, :sort_order, 1, :now, :now)'
        );
        $insertLink = $pdo->prepare(
            'INSERT INTO footer_links
                (column_id, label_nl, label_en, link_type, target_page_id, target_route, external_url, action_key, open_in_new_tab, sort_order, is_visible, created_at, updated_at)
             VALUES
                (:column_id, :label_nl, :label_en, :link_type, :target_page_id, :target_route, :external_url, :action_key, 0, :sort_order, 1, :now, :now)'
        );

        $insertColumn->execute(['title_nl' => 'Navigatie', 'title_en' => 'Navigation', 'sort_order' => 0, 'now' => $now]);
        $navigatieId = (int) $pdo->lastInsertId();
        foreach ([
            ['Home', 'Home', 'home'],
            ['Diensten', 'Services', 'diensten'],
            ['Portfolio', 'Portfolio', 'portfolio'],
            ['Shop', 'Shop', 'shop'],
            ['Over mij', 'About', 'over-mij'],
        ] as $position => [$labelNl, $labelEn, $route]) {
            $insertLink->execute([
                'column_id' => $navigatieId, 'label_nl' => $labelNl, 'label_en' => $labelEn,
                'link_type' => 'route', 'target_page_id' => null, 'target_route' => $route,
                'external_url' => null, 'action_key' => null, 'sort_order' => $position, 'now' => $now,
            ]);
        }

        $insertColumn->execute(['title_nl' => 'Materialen', 'title_en' => 'Materials', 'sort_order' => 1, 'now' => $now]);
        $materialenId = (int) $pdo->lastInsertId();
        foreach ([
            ['Hout graveren', 'Wood engraving', '/diensten.php#hout'],
            ['Metaal graveren', 'Metal engraving', '/diensten.php#metaal'],
            ['Acryl & glas', 'Acrylic & glass', '/diensten.php#acryl-glas'],
            ['Zakelijk', 'Business', '/diensten.php#zakelijk'],
        ] as $position => [$labelNl, $labelEn, $url]) {
            $insertLink->execute([
                'column_id' => $materialenId, 'label_nl' => $labelNl, 'label_en' => $labelEn,
                'link_type' => 'external', 'target_page_id' => null, 'target_route' => null,
                'external_url' => $url, 'action_key' => null, 'sort_order' => $position, 'now' => $now,
            ]);
        }

        $insertColumn->execute(['title_nl' => 'Informatie', 'title_en' => 'Information', 'sort_order' => 2, 'now' => $now]);
        $informatieId = (int) $pdo->lastInsertId();
        $infoPages = $this->fetchAll(
            'SELECT id, title FROM information_pages WHERE is_published = 1 AND show_in_footer = 1 ORDER BY sort_order ASC, id ASC'
        );
        $position = 0;
        foreach ($infoPages as $infoPage) {
            // Same non-translated title the original hardcoded loop used
            // (partials/footer.php never applied data-nl/data-en to these).
            $insertLink->execute([
                'column_id' => $informatieId, 'label_nl' => $infoPage['title'], 'label_en' => $infoPage['title'],
                'link_type' => 'page', 'target_page_id' => (int) $infoPage['id'], 'target_route' => null,
                'external_url' => null, 'action_key' => null, 'sort_order' => $position, 'now' => $now,
            ]);
            $position++;
        }
        $insertLink->execute([
            'column_id' => $informatieId, 'label_nl' => 'Herroepingsrecht', 'label_en' => 'Right of withdrawal',
            'link_type' => 'route', 'target_page_id' => null, 'target_route' => 'herroeping',
            'external_url' => null, 'action_key' => null, 'sort_order' => $position, 'now' => $now,
        ]);

        $insertColumn->execute(['title_nl' => 'Contact', 'title_en' => 'Contact', 'sort_order' => 3, 'now' => $now]);
        $contactId = (int) $pdo->lastInsertId();
        $insertLink->execute([
            'column_id' => $contactId, 'label_nl' => 'Offerte aanvragen', 'label_en' => 'Request a quote',
            'link_type' => 'route', 'target_page_id' => null, 'target_route' => 'contact',
            'external_url' => null, 'action_key' => null, 'sort_order' => 0, 'now' => $now,
        ]);
    }

    public function down(): void
    {
        $this->table('footer_links')->drop()->save();
        $this->table('footer_columns')->drop()->save();
    }
}

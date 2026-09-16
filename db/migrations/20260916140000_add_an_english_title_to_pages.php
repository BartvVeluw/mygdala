<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * An English title for a CMS page, and the one place its English name could
 * already be: the Paginakop's old breadcrumb label.
 *
 * WHY A SECOND MIGRATION rather than a line in 20260916120000, which is on
 * this same branch and not on main yet. That one has already RUN — on this
 * branch's test databases, and on any installation somebody tried the branch
 * on. Phinx records a migration as done by version, so extending it would
 * leave exactly those databases without this column while phinx believes
 * there is nothing left to do. Forward-only means a new file every time
 * something has already run, wherever it has run (db/migrations/CLAUDE.md).
 *
 * WHY THE COLUMN. `pages.title` is the page's name, and it is not only an
 * admin-facing identity: App\Service\PageContent::seoTitle() falls back to it
 * for the <title> tag, and since 20260916120000 the breadcrumb prints it. Both
 * therefore showed an English visitor the Dutch name. Every other piece of
 * editor text in this project is a `<column>` / `<column>_en` pair, and this
 * makes the page title one too. NULL is "not translated", which
 * App\Service\Language\LocalizedValue reads as "the same as the primary
 * language" — so every existing page reads exactly as it does today.
 *
 * NOTHING ABOUT URLs CHANGES. `slug` and `route_path` are untouched: both
 * languages keep living on one URL (MULTILINGUAL.md, "Wat V1 bewust niet
 * doet").
 *
 * THE BACKFILL is the backwards-compatibility half. Before 20260916120000 an
 * editor could type an English breadcrumb label on the Paginakop, and that
 * label was the page's English name — page_heroes seeds the Dutch one with
 * the page's own title (App\Service\PageHeroContent::startingValues()). The
 * relation is unambiguous: page_heroes.page_slug IS pages.content_key, the
 * page's immutable storage key, and both sides are unique on it. So a
 * non-empty English label moves to title_en exactly once, and only where:
 *
 *   - the page still exists;
 *   - title_en is still NULL, so nothing an editor typed is ever overwritten
 *     and a second run changes nothing;
 *   - the label differs from the Dutch title, because copying it in would
 *     turn "not translated" into "translated" for a value that is neither.
 *
 * It cannot make a <title> worse: seoTitle() only reaches the page title when
 * meta_title_en and meta_title are both empty, which is precisely the case
 * where an English visitor is getting the Dutch title today.
 *
 * breadcrumb_label_nl/en are NOT removed, read or written here. They stay as
 * legacy data (20260916120000); dropping them is a separate decision.
 *
 * The schema half runs on every installation, the backfill needs no
 * fresh-install guard: a fresh database simply has no rows to move
 * (db/migrations/CLAUDE.md).
 */
final class AddAnEnglishTitleToPages extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('pages')) {
            return;
        }

        if (!$this->table('pages')->hasColumn('title_en')) {
            $this->table('pages')
                ->addColumn('title_en', 'string', [
                    'limit' => 200,
                    'null' => true,
                    'default' => null,
                    'after' => 'title',
                    'comment' => 'The page name in English; NULL = not translated, see App\Service\PageContent',
                ])
                ->update();
        }

        if (!$this->hasTable('page_heroes')) {
            return;
        }

        $this->execute(
            "UPDATE pages p
                JOIN page_heroes ph ON ph.page_slug = p.content_key
                SET p.title_en = TRIM(ph.breadcrumb_label_en)
              WHERE p.title_en IS NULL
                AND ph.breadcrumb_label_en IS NOT NULL
                AND TRIM(ph.breadcrumb_label_en) <> ''
                AND TRIM(ph.breadcrumb_label_en) <> TRIM(p.title)"
        );
    }

    public function down(): void
    {
        if ($this->hasTable('pages') && $this->table('pages')->hasColumn('title_en')) {
            $this->table('pages')->removeColumn('title_en')->update();
        }
    }
}

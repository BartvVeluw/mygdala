<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Multilingual 2.0, phase 2: one row per page per website language
 * (docs/multilingual/ARCHITECTURE.md).
 *
 * THE FIRST TYPED TRANSLATION TABLE. Pages are a real domain entity, so their
 * text gets real columns rather than the generic block store phase 3 builds:
 *
 *   title             the page's name: the breadcrumb, the automatic <title>
 *   meta_title        the complete <title> when set
 *   meta_description  the search-result summary
 *
 * Every column NULL means "this language has no words for this field", which
 * is what App\Service\PageLocalization falls back from. The default language
 * gets a row like any other; no language lives in `pages` any more once
 * 20260917150000 has moved the old columns in.
 *
 * WHAT IS NOT HERE, on purpose. `slug` and `status` stay on `pages`: the
 * public URL of a page is still one URL for every language until the routing
 * phase, and a second slug column now would be a second source of truth
 * nothing reads.
 *
 * THE LANGUAGE IS A FOREIGN KEY on site_languages.code, not a free string.
 * A row can only name a registered language, whatever writes it. The column
 * therefore copies that column's type exactly (ascii, ascii_bin, 12 wide).
 * Deleting a language that still has page text is REFUSED rather than
 * cascaded: switching a language off is how an owner stops publishing it,
 * and a delete that silently took every translation along is the kind of
 * invisible loss this project blocks everywhere else
 * (App\Service\PageService::delete()).
 *
 * The page is a foreign key with ON DELETE CASCADE: a page's text has no
 * meaning without the page.
 *
 * SCHEMA ONLY. The rows arrive with 20260917150000, in the same commit that
 * switches every reader over and drops the old columns, so there is never a
 * state in which two copies of a page's text are both being read.
 */
final class CreateThePageTranslationsTable extends AbstractMigration
{
    private const TABLE = 'page_translations';

    public function up(): void
    {
        if ($this->hasTable(self::TABLE) || !$this->hasTable('pages') || !$this->hasTable('site_languages')) {
            return;
        }

        $this->table(self::TABLE, ['id' => true])
            ->addColumn('page_id', 'integer', [
                'signed' => false,
                'null' => false,
            ])
            ->addColumn('language_code', 'string', [
                'limit' => 12,
                'null' => false,
                'encoding' => 'ascii',
                'collation' => 'ascii_bin',
                'comment' => 'site_languages.code',
            ])
            ->addColumn('title', 'string', [
                'limit' => 200,
                'null' => true,
                'default' => null,
                'comment' => 'The page name in this language; NULL = none, see App\Service\PageLocalization',
            ])
            ->addColumn('meta_title', 'string', ['limit' => 255, 'null' => true, 'default' => null])
            ->addColumn('meta_description', 'string', ['limit' => 500, 'null' => true, 'default' => null])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['page_id', 'language_code'], ['unique' => true, 'name' => 'uq_page_translations_page_language'])
            ->addIndex(['language_code'], ['name' => 'idx_page_translations_language'])
            ->addForeignKey('page_id', 'pages', 'id', [
                'delete' => 'CASCADE',
                'update' => 'CASCADE',
                'constraint' => 'fk_page_translations_page',
            ])
            ->addForeignKey('language_code', 'site_languages', 'code', [
                'delete' => 'RESTRICT',
                'update' => 'RESTRICT',
                'constraint' => 'fk_page_translations_language',
            ])
            ->create();
    }

    /**
     * Forward-only (db/migrations/CLAUDE.md). Once 20260917150000 has run,
     * this table is where every page's text lives, and dropping it would
     * delete that text.
     */
    public function down(): void
    {
    }
}

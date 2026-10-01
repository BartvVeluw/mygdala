<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Articles 1.0 (v0.1.15, phase 6, ARTICLES.md): the second kind of content
 * on the Publishing Engine (docs/publishing/ARCHITECTURE.md), as its own
 * optional module with its own tables. Nothing here touches a Blog table.
 *
 *   article_topics              one flat list of topics ("Onderwerpen")
 *   article_topic_translations  a topic's name, description and address per
 *                               website language
 *   articles                    the language-neutral half of an article:
 *                               status and moment (the engine's two columns),
 *                               the free byline, the featured image (Media
 *                               Library, RESTRICT), at most ONE topic (SET
 *                               NULL: deleting a topic never deletes an
 *                               article) and noindex
 *   article_translations        title, intro, SEO texts and the ADDRESS per
 *                               website language. No neutral slug column and
 *                               no body: an article is new, so there is no
 *                               old URL to keep and no classic text to keep
 *   article_content_pages       article -> its content page (the blocks),
 *                               the owner model a product, a project and a
 *                               blog post use. RESTRICT both ways; deleting
 *                               goes through ContentPages::deleteFor() first
 *
 * The translation tables have the shape every typed translation table has
 * (App\Service\Language\TranslationTable): UNIQUE(owner, language), owner
 * CASCADE, language RESTRICT, and UNIQUE(language, slug) for the address.
 *
 * Switching the module off touches none of this (MODULES.md). Idempotent;
 * forward-only (db/migrations/CLAUDE.md).
 */
final class CreateTheArticlesModuleTables extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('site_languages') || !$this->hasTable('media') || !$this->hasTable('pages')) {
            return;
        }

        if (!$this->hasTable('article_topics')) {
            $this->table('article_topics', ['id' => true])
                ->addColumn('sort_order', 'integer', ['null' => false, 'default' => 0])
                ->addColumn('created_at', 'datetime', ['null' => true])
                ->addColumn('updated_at', 'datetime', ['null' => true])
                ->addIndex(['sort_order'])
                ->create();
        }

        if (!$this->hasTable('article_topic_translations')) {
            $this->translations(
                $this->table('article_topic_translations', ['id' => true]),
                'article_topics',
                'article_topic_id',
                ['slug' => 170, 'name' => 150, 'description' => 500]
            );
        }

        if (!$this->hasTable('articles')) {
            $this->table('articles', ['id' => true])
                ->addColumn('status', 'string', ['limit' => 20, 'null' => false, 'default' => 'draft'])
                ->addColumn('published_at', 'datetime', ['null' => true])
                ->addColumn('author_name', 'string', ['limit' => 120, 'null' => true])
                ->addColumn('featured_media_id', 'integer', ['signed' => false, 'null' => true])
                ->addColumn('topic_id', 'integer', ['signed' => false, 'null' => true])
                ->addColumn('noindex', 'boolean', ['null' => false, 'default' => false])
                ->addColumn('created_at', 'datetime', ['null' => true])
                ->addColumn('updated_at', 'datetime', ['null' => true])
                // Every public read: listed or reachable, newest first.
                ->addIndex(['status', 'published_at'], ['name' => 'idx_articles_status_published'])
                ->addIndex(['topic_id'], ['name' => 'idx_articles_topic'])
                ->addForeignKey('featured_media_id', 'media', 'id', [
                    'delete' => 'RESTRICT',
                    'update' => 'CASCADE',
                    'constraint' => 'fk_articles_featured_media',
                ])
                ->addForeignKey('topic_id', 'article_topics', 'id', [
                    'delete' => 'SET_NULL',
                    'update' => 'CASCADE',
                    'constraint' => 'fk_articles_topic',
                ])
                ->create();
        }

        if (!$this->hasTable('article_translations')) {
            $this->translations(
                $this->table('article_translations', ['id' => true]),
                'articles',
                'article_id',
                ['slug' => 170, 'title' => 200, 'excerpt' => 500, 'meta_title' => 255, 'meta_description' => 500]
            );
        }

        if (!$this->hasTable('article_content_pages')) {
            $this->table('article_content_pages', ['id' => false, 'primary_key' => ['article_id']])
                ->addColumn('article_id', 'integer', ['signed' => false, 'null' => false])
                ->addColumn('page_id', 'integer', ['signed' => false, 'null' => false])
                ->addColumn('created_at', 'datetime', ['null' => true, 'default' => null])
                ->addIndex(['page_id'], ['unique' => true, 'name' => 'uq_article_content_pages_page'])
                ->addForeignKey('article_id', 'articles', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE', 'constraint' => 'fk_article_content_pages_article'])
                ->addForeignKey('page_id', 'pages', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE', 'constraint' => 'fk_article_content_pages_page'])
                ->create();
        }
    }

    /**
     * One typed translation table, with `slug` as the address column.
     *
     * @param array<string, int> $fields field => maximum length
     */
    private function translations(\Phinx\Db\Table $table, string $ownerTable, string $ownerColumn, array $fields): void
    {
        $name = $table->getName();

        $table
            ->addColumn($ownerColumn, 'integer', ['signed' => false, 'null' => false])
            ->addColumn('language_code', 'string', [
                'limit' => 12,
                'null' => false,
                'encoding' => 'ascii',
                'collation' => 'ascii_bin',
                'comment' => 'site_languages.code',
            ]);

        foreach ($fields as $field => $maxLength) {
            $table->addColumn($field, 'string', [
                'limit' => $maxLength,
                'null' => true,
                'default' => null,
                'comment' => $field === 'slug'
                    ? 'This language\'s public address; NULL = this language has no public route'
                    : 'The words in this language; a language without words has no row',
            ]);
        }

        $table
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex([$ownerColumn, 'language_code'], ['unique' => true, 'name' => 'uq_' . $name . '_owner_language'])
            ->addIndex(['language_code', 'slug'], ['unique' => true, 'name' => 'uq_' . $name . '_language_slug'])
            ->addForeignKey($ownerColumn, $ownerTable, 'id', [
                'delete' => 'CASCADE',
                'update' => 'CASCADE',
                'constraint' => 'fk_' . $name . '_owner',
            ])
            ->addForeignKey('language_code', 'site_languages', 'code', [
                'delete' => 'RESTRICT',
                'update' => 'RESTRICT',
                'constraint' => 'fk_' . $name . '_language',
            ])
            ->create();
    }

    /** Forward-only (db/migrations/CLAUDE.md). */
    public function down(): void
    {
    }
}

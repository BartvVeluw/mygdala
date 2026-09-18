<?php

declare(strict_types=1);

use Phinx\Db\Adapter\MysqlAdapter;
use Phinx\Migration\AbstractMigration;

/**
 * Multilingual 2.0, phase 5 wave B: the words of the Blog, one row per owner
 * per website language (docs/multilingual/ARCHITECTURE.md, BLOG.md).
 *
 *   blog_post_translations      title, excerpt, body, meta_title, meta_description
 *   blog_category_translations  name, description
 *   blog_tag_translations       name
 *
 * TYPED TABLES, like page_translations, the phase 4 tables and the Portfolio's
 * of wave A. Every table has the shape App\Service\Language\TranslationTable
 * declares:
 *
 *   - the owner is a foreign key with ON DELETE CASCADE: words have no meaning
 *     without their post, category or tag;
 *   - the language is a foreign key on site_languages.code with ON DELETE
 *     RESTRICT, the same type as that column (ascii, ascii_bin, 12 wide):
 *     deleting a language that still has words is refused, never cascaded;
 *   - UNIQUE(owner, language_code): one row per owner per language, the
 *     default language included. A language without words has no row.
 *
 * WHAT IS NOT HERE, on purpose: THE SLUG. `blog_posts.slug`,
 * `blog_categories.slug` and `blog_tags.slug` are single and language-neutral
 * today — /blog/<slug>, /blog/categorie/<slug> and /blog/tag/<slug> are one
 * address each — and they stay that way. A slug per language needs a router
 * that uses it, which is phase 6; adding one now would be a second source of
 * truth that nothing reads. Neither is the rest of the row: status, publication
 * date, author, the featured and share image, noindex, is_active and sort
 * order are the same in every language.
 *
 * `body` is the only RICH field (App\Service\RichTextSanitizer, as before) and
 * gets MEDIUMTEXT, like block_translations.value, so no value that fit in the
 * old TEXT column can fail to fit here. `excerpt` and `description` are plain
 * text with the length their editor already validated.
 *
 * NOT A MODULE QUESTION. These tables are created whether the Blog is on or
 * off: a switched-off module keeps its content, and a fresh install with it
 * off must end on the same schema as one with it on.
 *
 * SCHEMA ONLY. The words arrive with 20260918190000, in the same commit that
 * switches every reader over and drops the old columns.
 */
final class CreateTheBlogTranslationTables extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('site_languages')) {
            return;
        }

        // Each name written out in $this->table('…'), so the schema check in
        // Tests\Install\MigrationTableNamesTest can see it being created.
        if (!$this->hasTable('blog_post_translations') && $this->hasTable('blog_posts')) {
            $this->create(
                $this->table('blog_post_translations', ['id' => true]),
                'blog_posts',
                'blog_post_id',
                ['title' => 200, 'excerpt' => 500, 'body' => 0, 'meta_title' => 255, 'meta_description' => 500]
            );
        }

        if (!$this->hasTable('blog_category_translations') && $this->hasTable('blog_categories')) {
            $this->create(
                $this->table('blog_category_translations', ['id' => true]),
                'blog_categories',
                'blog_category_id',
                ['name' => 150, 'description' => 500]
            );
        }

        if (!$this->hasTable('blog_tag_translations') && $this->hasTable('blog_tags')) {
            $this->create(
                $this->table('blog_tag_translations', ['id' => true]),
                'blog_tags',
                'blog_tag_id',
                ['name' => 100]
            );
        }
    }

    /**
     * One typed translation table. A field's length is the length its editor
     * validates; 0 means the field is rich text, and the column is MEDIUMTEXT.
     *
     * @param array<string, int> $fields field => maximum length, 0 for rich text
     */
    private function create(\Phinx\Db\Table $table, string $ownerTable, string $ownerColumn, array $fields): void
    {
        $name = $table->getName();

        $table
            ->addColumn($ownerColumn, 'integer', [
                'signed' => false,
                'null' => false,
            ])
            ->addColumn('language_code', 'string', [
                'limit' => 12,
                'null' => false,
                'encoding' => 'ascii',
                'collation' => 'ascii_bin',
                'comment' => 'site_languages.code',
            ]);

        foreach ($fields as $field => $maxLength) {
            $table->addColumn($field, $maxLength === 0 ? 'text' : 'string', [
                'limit' => $maxLength === 0 ? MysqlAdapter::TEXT_MEDIUM : $maxLength,
                'null' => true,
                'default' => null,
                'comment' => 'The words in this language; a language without words has no row',
            ]);
        }

        $table
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex([$ownerColumn, 'language_code'], ['unique' => true, 'name' => 'uq_' . $name . '_owner_language'])
            ->addIndex(['language_code'], ['name' => 'idx_' . $name . '_language'])
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

    /**
     * Forward-only (db/migrations/CLAUDE.md). Once 20260918190000 has run,
     * these tables are where the Blog's words live.
     */
    public function down(): void
    {
    }
}

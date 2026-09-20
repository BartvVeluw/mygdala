<?php

declare(strict_types=1);

use App\Service\Blog\BlogSlug;
use Phinx\Migration\AbstractMigration;

/**
 * Multilingual 2.0, phase 6: an address per language for the module entities
 * that have one (docs/multilingual/ROUTING.md).
 *
 * The same move 20260920100000 made for pages, for the four other things this
 * project routes by slug:
 *
 *   blog_post_translations       /blog/<slug>
 *   blog_category_translations   /blog/categorie/<slug>
 *   blog_tag_translations        /blog/tag/<slug>
 *   collection_translations      /collecties/<slug>
 *
 * WHAT IS DELIBERATELY NOT HERE:
 *
 *   - PRODUCTS. A product has no slug URL at all: it is one page at
 *     /product.php?id=… however many collections it appears in
 *     (App\Service\ProductSeo), and `products.slug` reaches no URL. Giving it
 *     one is a URL decision with nothing to do with language, so a
 *     `product_translations.slug` would be a column nothing reads — the
 *     mistake 20260917140000 avoided for pages three phases ago.
 *   - PORTFOLIO ITEMS. /portfolio/<slug> is a compatibility route since phase
 *     4B: it redirects to the CMS page an item links to, and that page is a
 *     `pages` row which already has its address per language. Translating the
 *     legacy address would create new URLs for a route that is being retired.
 *   - PORTFOLIO CATEGORIES. A filter value inside a block, never a URL.
 *
 * THE NEUTRAL `slug` COLUMNS STAY, exactly as `pages.slug` did: they are what
 * every existing link and indexed URL names, what the Redirect Manager's
 * stored sources were written against, and what the default language's
 * address is kept byte-identical to. Not one existing URL moves.
 *
 * THE BACKFILL, per row: the default language gets the neutral slug
 * unchanged; every other language that has WORDS but no address gets one
 * generated from those words with the module's own slugger
 * (App\Service\Blog\BlogSlug::sanitize(), the rule every blog slug already
 * follows), made unique inside that language with the same "-2" suffix. A
 * language with no words gets nothing and therefore has no public route,
 * which is the whole point: a URL exists when the version behind it exists.
 */
final class GiveModuleEntitiesASlugPerLanguage extends AbstractMigration
{
    /**
     * table => [owner column, the neutral table it belongs to, the field whose
     * words a generated slug is made from, the slug column width].
     */
    private const TABLES = [
        'blog_post_translations' => ['blog_post_id', 'blog_posts', 'title', 170],
        'blog_category_translations' => ['blog_category_id', 'blog_categories', 'name', 170],
        'blog_tag_translations' => ['blog_tag_id', 'blog_tags', 'name', 120],
        'collection_translations' => ['collection_id', 'collections', 'name', 170],
    ];

    public function up(): void
    {
        if (!$this->hasTable('site_languages')) {
            return;
        }

        foreach (self::TABLES as $table => [$ownerColumn, $ownerTable, $wordsField, $width]) {
            if (!$this->hasTable($table) || !$this->hasTable($ownerTable)) {
                continue;
            }

            $this->addSlugColumn($table, $width);
            $this->backfillDefaultLanguage($table, $ownerColumn, $ownerTable);
            $this->backfillOtherLanguages($table, $ownerColumn, $wordsField, $width);
        }
    }

    /** Forward-only: dropping these columns would delete every localized URL. */
    public function down(): void
    {
    }

    private function addSlugColumn(string $table, int $width): void
    {
        $definition = $this->table($table);

        if ($definition->hasColumn('slug')) {
            return;
        }

        $definition
            ->addColumn('slug', 'string', [
                'limit' => $width,
                'null' => true,
                'default' => null,
                'after' => 'language_code',
                'comment' => 'This language\'s public address; NULL = this language has no public route',
            ])
            ->addIndex(['language_code', 'slug'], [
                'unique' => true,
                'name' => 'uq_' . $table . '_language_slug',
            ])
            ->update();
    }

    private function backfillDefaultLanguage(string $table, string $ownerColumn, string $ownerTable): void
    {
        $default = $this->defaultLanguage();

        if ($default === null) {
            return;
        }

        $this->execute(sprintf(
            "INSERT INTO `%s` (`%s`, language_code, slug, created_at, updated_at)
             SELECT o.id, %s, o.slug, NOW(), NOW()
               FROM `%s` o
              WHERE o.slug IS NOT NULL AND o.slug <> ''
             ON DUPLICATE KEY UPDATE slug = VALUES(slug), updated_at = NOW()",
            $table,
            $ownerColumn,
            $this->quote($default),
            $ownerTable
        ));
    }

    private function backfillOtherLanguages(string $table, string $ownerColumn, string $wordsField, int $width): void
    {
        $default = $this->defaultLanguage();

        foreach ($this->activeLanguages() as $language) {
            if ($language === $default) {
                continue;
            }

            $rows = $this->fetchAll(sprintf(
                "SELECT `%s` AS owner_id, `%s` AS words
                   FROM `%s`
                  WHERE language_code = %s
                    AND (slug IS NULL OR slug = '')
                    AND `%s` IS NOT NULL AND `%s` <> ''
                  ORDER BY `%s` ASC",
                $ownerColumn,
                $wordsField,
                $table,
                $this->quote($language),
                $wordsField,
                $wordsField,
                $ownerColumn
            ));

            foreach ($rows as $row) {
                $slug = $this->uniqueSlug(BlogSlug::sanitize((string) $row['words']), $table, $language, $width);

                if ($slug === '') {
                    continue;
                }

                $this->execute(sprintf(
                    'UPDATE `%s` SET slug = %s, updated_at = NOW() WHERE `%s` = %d AND language_code = %s',
                    $table,
                    $this->quote($slug),
                    $ownerColumn,
                    (int) $row['owner_id'],
                    $this->quote($language)
                ));
            }
        }
    }

    private function uniqueSlug(string $base, string $table, string $language, int $width): string
    {
        if ($base === '') {
            return '';
        }

        $base = substr($base, 0, $width - 10);
        $slug = $base;
        $suffix = 2;

        while ($this->slugTaken($table, $slug, $language)) {
            $slug = $base . '-' . $suffix;
            $suffix++;

            if ($suffix > 200) {
                return '';
            }
        }

        return $slug;
    }

    private function slugTaken(string $table, string $slug, string $language): bool
    {
        $row = $this->fetchRow(sprintf(
            'SELECT 1 AS taken FROM `%s` WHERE language_code = %s AND slug = %s LIMIT 1',
            $table,
            $this->quote($language),
            $this->quote($slug)
        ));

        return $row !== false && $row !== null;
    }

    /** @return list<string> */
    private function activeLanguages(): array
    {
        $rows = $this->fetchAll('SELECT code FROM site_languages WHERE is_active = 1 ORDER BY sort_order ASC, id ASC');

        return array_map(static fn (array $row): string => (string) $row['code'], $rows);
    }

    private function defaultLanguage(): ?string
    {
        $row = $this->fetchRow('SELECT code FROM site_languages WHERE is_default = 1 LIMIT 1');

        return ($row === false || $row === null) ? null : (string) $row['code'];
    }

    /** The same literal quoter every data migration in this folder uses. */
    private function quote(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }
}

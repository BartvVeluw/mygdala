<?php

declare(strict_types=1);

use App\Service\PageService;
use Phinx\Migration\AbstractMigration;

/**
 * Multilingual 2.0, phase 6: a page's public address, per language
 * (docs/multilingual/ROUTING.md).
 *
 * 20260917140000 deliberately left `slug` out of `page_translations`, with a
 * comment saying why: "the public URL of a page is still one URL for every
 * language until the routing phase, and a second slug column now would be a
 * second source of truth nothing reads". This is that phase, and this is that
 * column.
 *
 * `pages.slug` IS NOT DROPPED, and not by accident. It stays as the page's
 * neutral key: the value the Redirect Manager's stored sources were written
 * against, what `content_key` was derived from, and the address every
 * existing link and every indexed URL already names. What this migration
 * establishes is that the DEFAULT LANGUAGE'S localized slug is byte-identical
 * to it, so not one existing URL moves. Whether the old column can ever go is
 * a question for the final cleanup, not for the phase that has to prove the
 * new contract first.
 *
 * THE BACKFILL, per page:
 *
 *   - a ROUTE-BOUND page (`route_path`: the homepage, /shop.php, …) gets NO
 *     slug in any language. Its address is its route, and a slug there would
 *     be a value nothing reads — exactly the mistake this column avoided
 *     three phases ago;
 *   - the DEFAULT language gets `pages.slug`, unchanged;
 *   - EVERY OTHER language that has a TITLE but no slug gets one generated
 *     from that title, through App\Service\PageService::sanitizeSlug() — the
 *     same slugger the CMS uses, so an editor gets a slug they could have
 *     typed themselves rather than a second transliteration convention. A
 *     collision inside that language takes the "-2" suffix
 *     PageService::generateSlug() has always used;
 *   - a language with no title gets nothing, and therefore has no public
 *     route at all. That is the point of the whole phase: a URL exists when
 *     the version behind it exists, and never because another language's
 *     words could be shown in its place.
 *
 * UNIQUE PER LANGUAGE, not globally: /over-ons and /en/over-ons are different
 * URLs and may both exist. NULL is allowed many times over, which is what
 * makes "no version in this language" storable at all.
 *
 * WHAT IT REFUSES TO DO SILENTLY. A page whose slug is now a reserved word —
 * a language code, or a word a fixed route segment spells — is REPORTED and
 * left alone. Renaming somebody's live URL without asking is not a migration's
 * decision; the page keeps its row, and the CMS will refuse the next save of
 * that slug with a message the editor can act on.
 */
final class GiveEveryPageASlugPerLanguage extends AbstractMigration
{
    private const TABLE = 'page_translations';

    public function up(): void
    {
        if (!$this->hasTable(self::TABLE) || !$this->hasTable('pages') || !$this->hasTable('site_languages')) {
            return;
        }

        $this->addColumn();
        $this->backfillDefaultLanguage();
        $this->backfillOtherLanguages();
        $this->reportReservedSlugs();
    }

    /** Forward-only: dropping this column would delete every localized URL. */
    public function down(): void
    {
    }

    private function addColumn(): void
    {
        $table = $this->table(self::TABLE);

        if ($table->hasColumn('slug')) {
            return;
        }

        $table
            ->addColumn('slug', 'string', [
                'limit' => PageService::MAX_SLUG_LENGTH,
                'null' => true,
                'default' => null,
                'after' => 'language_code',
                'comment' => 'This language\'s public address; NULL = this language has no public route',
            ])
            ->addIndex(['language_code', 'slug'], [
                'unique' => true,
                'name' => 'uq_page_translations_language_slug',
            ])
            ->update();
    }

    /**
     * The default language's slug IS the page's existing slug. One statement,
     * and the reason every URL of every existing installation keeps answering.
     *
     * ON DUPLICATE KEY UPDATE rather than a plain INSERT: a page whose
     * default-language row already exists (almost all of them, from
     * 20260917150000) must get its slug without its title being touched, and a
     * page that somehow has no row at all still needs one to be routable.
     */
    private function backfillDefaultLanguage(): void
    {
        $default = $this->defaultLanguage();

        if ($default === null) {
            return;
        }

        $this->execute(sprintf(
            "INSERT INTO page_translations (page_id, language_code, slug, created_at, updated_at)
             SELECT p.id, %s, p.slug, NOW(), NOW()
               FROM pages p
              WHERE p.slug IS NOT NULL
                AND p.slug <> ''
                AND (p.route_path IS NULL OR p.route_path = '')
             ON DUPLICATE KEY UPDATE slug = VALUES(slug), updated_at = NOW()",
            $this->quote($default)
        ));
    }

    /**
     * Every other active language that has words but no address yet.
     *
     * Row by row rather than in SQL, because making a slug unique needs the
     * slugs already decided in this same language — including the ones this
     * loop just wrote.
     */
    private function backfillOtherLanguages(): void
    {
        $default = $this->defaultLanguage();

        foreach ($this->activeLanguages() as $language) {
            if ($language === $default) {
                continue;
            }

            $rows = $this->fetchAll(sprintf(
                "SELECT t.page_id, t.title
                   FROM page_translations t
                   JOIN pages p ON p.id = t.page_id
                  WHERE t.language_code = %s
                    AND (t.slug IS NULL OR t.slug = '')
                    AND t.title IS NOT NULL
                    AND t.title <> ''
                    AND (p.route_path IS NULL OR p.route_path = '')
                  ORDER BY t.page_id ASC",
                $this->quote($language)
            ));

            foreach ($rows as $row) {
                $slug = $this->uniqueSlug(PageService::sanitizeSlug((string) $row['title']), $language);

                if ($slug === '') {
                    continue;
                }

                $this->execute(sprintf(
                    'UPDATE page_translations SET slug = %s, updated_at = NOW()
                      WHERE page_id = %d AND language_code = %s',
                    $this->quote($slug),
                    (int) $row['page_id'],
                    $this->quote($language)
                ));
            }
        }
    }

    /**
     * A slug that is free in this language, with the CMS's own "-2" suffix.
     * Returns '' when the title yields nothing usable at all, which leaves the
     * language without a route rather than inventing "pagina-7" for it.
     */
    private function uniqueSlug(string $base, string $language): string
    {
        if ($base === '') {
            return '';
        }

        $base = substr($base, 0, PageService::MAX_SLUG_LENGTH - 10);
        $slug = $base;
        $suffix = 2;

        while ($this->slugTaken($slug, $language)) {
            $slug = $base . '-' . $suffix;
            $suffix++;

            if ($suffix > 200) {
                return '';
            }
        }

        return $slug;
    }

    private function slugTaken(string $slug, string $language): bool
    {
        $row = $this->fetchRow(sprintf(
            'SELECT 1 AS taken FROM page_translations WHERE language_code = %s AND slug = %s LIMIT 1',
            $this->quote($language),
            $this->quote($slug)
        ));

        return $row !== false && $row !== null;
    }

    /**
     * Pages whose address is now a word the router owns. Nothing is changed:
     * this is a message to whoever runs the upgrade, not a repair.
     */
    private function reportReservedSlugs(): void
    {
        $words = $this->activeLanguages();

        // The words a fixed route segment can spell are code, not data, and
        // reading them here would tie a migration to a catalogue that changes
        // per release. The language codes are the ones that actually became
        // reserved by THIS migration's contract, and they are in the database.
        if ($words === []) {
            return;
        }

        $quoted = implode(',', array_map(fn (string $code): string => $this->quote($code), $words));

        $clashes = $this->fetchAll(
            "SELECT id, slug FROM pages
              WHERE (route_path IS NULL OR route_path = '')
                AND slug IN ({$quoted})"
        );

        foreach ($clashes as $clash) {
            $this->output->writeln(sprintf(
                '<error>Page %d has the slug "%s", which is now a language prefix. '
                . 'Its address is shadowed by /%s/ and it needs a new slug.</error>',
                (int) $clash['id'],
                (string) $clash['slug'],
                (string) $clash['slug']
            ));
        }
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

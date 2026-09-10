<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The page builder's tenth section type: a plain rich-text block
 * (App\Service\RichTextContent, partials/section-rich-text.php,
 * admin/rich-text.php).
 *
 * Why this type has to exist: until now, the only way to put ordinary
 * long-form body copy on a CMS page was `information_pages.content_html` —
 * a whole separate page system with its OWN editor, outside the page
 * builder entirely. Unifying the two page models (see
 * 20260908100000_create_pages_table.php) without a rich-text SECTION type
 * would have meant either losing that body content, or keeping a second
 * page-content editor alongside the page builder. So the content moves here
 * instead, as a normal, addable/reorderable/removable section, and every
 * page — old or new — composes its body the same way.
 *
 * Schema follows the exact convention of the other repeater section types
 * (feature_grids, faq_sections, stat_strips, ...): keyed by
 * (page_slug, section_key) — page_slug being the owning page's immutable
 * pages.content_key — with its own is_active flag, independent from
 * page_sections.is_active.
 *
 * NL/EN: unlike the other section types this stores a single content body
 * rather than *_nl/*_en pairs, because that is exactly what
 * information_pages.content_html was (the existing information pages have
 * no English body, and informatiepagina.php rendered one language only).
 * Inventing an empty English body for every migrated page — and a second
 * rich-text editor next to it — would have added a field the site owner
 * never had and never asked for. A future EN column can be added additively
 * if that changes.
 *
 * Backfill: one row per existing information page, carrying its content_html
 * across verbatim (no re-sanitizing, no reformatting — the stored HTML was
 * already sanitized by RichTextSanitizer on save, and
 * App\Service\LegalPages hashes exactly this string for the Terms &
 * Conditions acceptance record stored on orders, so the value must stay
 * byte-identical). section_key is the fixed literal 'content' for a migrated
 * body; sections added later get a random custom-xxxxxxxx key like every
 * other repeater type. Idempotent via a (page_slug, section_key) existence
 * check.
 */
final class CreateRichTextSectionsTable extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('rich_text_sections')) {
            $this->table('rich_text_sections', ['id' => true])
                ->addColumn('page_slug', 'string', ['limit' => 100])
                ->addColumn('section_key', 'string', ['limit' => 100])
                ->addColumn('content_html', 'text', ['null' => true])
                ->addColumn('is_active', 'boolean', ['default' => true])
                ->addColumn('created_at', 'datetime', ['null' => true])
                ->addColumn('updated_at', 'datetime', ['null' => true])
                ->addIndex(['page_slug', 'section_key'], ['unique' => true])
                ->create();
        }

        if (!$this->hasTable('information_pages')) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $rows = $this->fetchAll('SELECT slug, content_html, created_at, updated_at FROM information_pages ORDER BY id ASC');

        foreach ($rows as $row) {
            $pageSlug = (string) $row['slug'];

            $existing = $this->query(
                'SELECT id FROM rich_text_sections WHERE page_slug = ? AND section_key = ?',
                [$pageSlug, 'content']
            );
            if ($existing->fetch() !== false) {
                continue;
            }

            $this->execute(
                'INSERT INTO rich_text_sections
                    (page_slug, section_key, content_html, is_active, created_at, updated_at)
                 VALUES (?, ?, ?, 1, ?, ?)',
                [
                    $pageSlug,
                    'content',
                    $row['content_html'],
                    $row['created_at'] ?? $now,
                    $row['updated_at'] ?? $now,
                ]
            );
        }
    }

    public function down(): void
    {
        $this->table('rich_text_sections')->drop()->save();
    }
}

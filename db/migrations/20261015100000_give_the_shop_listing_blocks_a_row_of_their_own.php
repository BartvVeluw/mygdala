<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The Shop's Productgrid (`product_grid`) and Collectie-tegels
 * (`shop_collections`) get a row of their own, so they can carry an optional
 * head — an eyebrow, a title and a text (App\Service\Blocks\BlockHead) — per
 * website language in block_translations, like every other block's words.
 *
 * Until now neither had a content row: page_sections.section_id was the page's
 * own id (0 for the historical storefront), and nothing could hang words on
 * that. Both blocks now share one table, `shop_listing_blocks`
 * (App\Repository\ShopListingRepository): one row per instance, nothing but
 * its address and the "Actief" switch. Products and collections stay in the
 * Shop's own tables and are never copied here.
 *
 * EVERY EXISTING BLOCK gets its row, and its page_sections row is pointed at
 * it (section_id, and a section_key where it had none). Nothing else of the
 * page_sections row changes: its page, position, visibility and Extra
 * vormgeving stay exactly as they were, and with no words the block renders
 * exactly what it did. The table's AUTO_INCREMENT starts above every
 * section_id those two types used, so no new id can collide with a row that
 * is still to be pointed under UNIQUE(section_type, section_id).
 *
 * Idempotent: a page_sections row that already points at a row of this table
 * (by its address) is left alone. Forward-only, no fresh-install guard: a
 * fresh install has no such blocks yet, or the ones its bootstrap placed, and
 * those get their row the same way.
 */
final class GiveTheShopListingBlocksARowOfTheirOwn extends AbstractMigration
{
    private const TABLE = 'shop_listing_blocks';

    private const TYPES = ['product_grid', 'shop_collections'];

    public function up(): void
    {
        if (!$this->hasTable(self::TABLE)) {
            $this->table(self::TABLE, ['id' => true, 'comment' => 'App\Repository\ShopListingRepository: the own row of a Productgrid or Collectie-tegels block'])
                ->addColumn('page_slug', 'string', ['limit' => 100])
                ->addColumn('section_key', 'string', ['limit' => 100])
                ->addColumn('is_active', 'boolean', ['default' => true])
                ->addColumn('created_at', 'datetime', ['null' => true])
                ->addColumn('updated_at', 'datetime', ['null' => true])
                ->addIndex(['page_slug', 'section_key'], ['unique' => true])
                ->create();
        }

        if (!$this->hasTable('page_sections')) {
            return;
        }

        $types = "'" . implode("', '", self::TYPES) . "'";
        $sections = $this->fetchAll(
            "SELECT ps.id, ps.section_type, ps.section_key, ps.section_id, ps.page_slug, p.content_key
             FROM page_sections ps
             LEFT JOIN pages p ON p.id = ps.page_id
             WHERE ps.section_type IN ({$types})
             ORDER BY ps.id"
        );

        $pending = [];
        foreach ($sections as $section) {
            $slug = (string) ($section['page_slug'] ?? '');
            if ($slug === '') {
                $slug = (string) ($section['content_key'] ?? '');
            }
            $key = (string) ($section['section_key'] ?? '');

            if ($slug === '') {
                // A row without a page to address it by cannot have an editor;
                // it keeps rendering as it did, without a head.
                continue;
            }

            if ($key !== '' && $this->rowFor($slug, $key) !== null && (int) $this->rowFor($slug, $key)['id'] === (int) $section['section_id']) {
                continue;
            }

            $pending[] = ['section' => $section, 'slug' => $slug, 'key' => $key];
        }

        if ($pending === []) {
            return;
        }

        $highest = (int) ($this->fetchRow("SELECT COALESCE(MAX(section_id), 0) AS m FROM page_sections WHERE section_type IN ({$types})")['m'] ?? 0);
        $next = (int) ($this->fetchRow('SELECT COALESCE(MAX(id), 0) AS m FROM ' . self::TABLE)['m'] ?? 0);
        $this->execute('ALTER TABLE ' . self::TABLE . ' AUTO_INCREMENT = ' . (max($highest, $next) + 1));

        $pdo = $this->getAdapter()->getConnection();
        $insert = $pdo->prepare(
            'INSERT INTO ' . self::TABLE . ' (page_slug, section_key, is_active, created_at, updated_at) VALUES (:slug, :key, 1, NOW(), NOW())'
        );
        $point = $pdo->prepare('UPDATE page_sections SET section_id = :row, section_key = :key WHERE id = :id');

        foreach ($pending as $item) {
            $key = $item['key'];
            if ($key === '' || $this->rowFor($item['slug'], $key) !== null) {
                do {
                    $key = 'custom-' . bin2hex(random_bytes(4));
                } while ($this->rowFor($item['slug'], $key) !== null);
            }

            $insert->execute(['slug' => $item['slug'], 'key' => $key]);
            $point->execute(['row' => (int) $pdo->lastInsertId(), 'key' => $key, 'id' => (int) $item['section']['id']]);
        }
    }

    /** @return array<string, mixed>|null */
    private function rowFor(string $slug, string $key): ?array
    {
        $pdo = $this->getAdapter()->getConnection();
        $stmt = $pdo->prepare('SELECT id FROM ' . self::TABLE . ' WHERE page_slug = :slug AND section_key = :key LIMIT 1');
        $stmt->execute(['slug' => $slug, 'key' => $key]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function down(): void
    {
    }
}

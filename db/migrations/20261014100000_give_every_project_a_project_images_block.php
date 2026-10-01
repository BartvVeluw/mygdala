<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Portfolio 3.0: a project page is a FIXED HEAD (main picture, title, text —
 * partials/project-hero.php, laid out by the project layout) followed by its
 * PAGE CONTENT (its content blocks). The extra photos leave the head and
 * become one of those blocks, Projectafbeeldingen
 * (App\Service\Blocks\ProjectImagesBlock); the Projectinformatie block and the
 * "free" project layout it served are retired.
 *
 * PER PROJECT (every portfolio_gallery_items row, in id order):
 *
 *   1. its content page, made when it has none (exactly what
 *      App\Service\ContentOwners\ContentPages::ensure() makes: a `pages` row
 *      with owner_type 'portfolio_project' and content_key
 *      'portfolio_project_<id>', linked in portfolio_content_pages);
 *   2. ONE Projectafbeeldingen row in page_sections, its section_id the
 *      project's id (so UNIQUE(section_type, section_id) allows one per
 *      project), placed where the photos were on the page until now:
 *        - a fixed layout (picture left, right or top): the photos stood
 *          inside the head, above every block — the block goes FIRST, shown;
 *        - the free layout with a Projectinformatie block: the photos stood
 *          inside that block, wherever it was — the new block takes ITS
 *          place, shown only when that block showed its photos (shown in the
 *          list, shown in its own editor, "Meer afbeeldingen tonen" on);
 *        - the free layout without one: no photos were shown — the block
 *          goes first, HIDDEN, so nothing new appears;
 *      every other block keeps its order relative to the rest;
 *   3. a free project gets the fixed layout of the head it showed: the
 *      picture where its Projectinformatie block put it (left, right, top),
 *      left when it had none.
 *
 * THE PORTFOLIO DEFAULT. A default of "free" becomes "image_left", the
 * default every site had before; a project that followed it and whose
 * Projectinformatie block put the picture right or on top gets that as its
 * own choice, so its head keeps its picture where it was.
 *
 * WHAT CHANGES ON A FREE PROJECT, and cannot be avoided once the head is no
 * block: a block the editor had placed ABOVE the Projectinformatie block now
 * follows the head, and a free project without that block shows its head
 * again (its title and main picture; its photos stay hidden).
 *
 * RETIRED: every page_sections row of type 'project_info' (its search text
 * goes by CASCADE), its drafts in content_block_drafts, and the table
 * portfolio_project_infos — which never held a word or picture of a project,
 * only "where the picture sits" and "show the photos", both carried over
 * above. No project, photo, media reference or translation is touched.
 *
 * IDEMPOTENT. A project that already has its block is skipped; the
 * Projectinformatie part runs only while its table exists; a second run
 * changes nothing.
 */
final class GiveEveryProjectAProjectImagesBlock extends AbstractMigration
{
    private const KIND = 'portfolio_project';
    private const TYPE = 'project_images';
    private const POSITIONS = ['left' => 'image_left', 'right' => 'image_right', 'top' => 'image_top'];
    private const FIXED_LAYOUTS = ['image_left', 'image_right', 'image_top'];

    public function up(): void
    {
        if (!$this->hasTable('portfolio_gallery_items') || !$this->hasTable('portfolio_content_pages')) {
            return;
        }

        $pdo = $this->getAdapter()->getConnection();
        $hasInfos = $this->hasTable('portfolio_project_infos');

        $settingRow = $this->fetchRow("SELECT setting_value FROM site_settings WHERE setting_key = 'portfolio_project_layout' LIMIT 1");
        $storedDefault = $settingRow === false ? '' : (string) $settingRow['setting_value'];
        $defaultIsFree = $storedDefault === 'free';
        $default = in_array($storedDefault, self::FIXED_LAYOUTS, true) ? $storedDefault : 'image_left';

        // Phinx may already run this migration in a transaction of its own;
        // then that one covers it.
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
        }

        try {
            foreach ($this->fetchAll('SELECT id, project_layout FROM portfolio_gallery_items ORDER BY id') as $item) {
                $this->migrateProject($pdo, (int) $item['id'], $item['project_layout'] === null ? null : (string) $item['project_layout'], $defaultIsFree, $default, $hasInfos);
            }

            if ($defaultIsFree) {
                $pdo->prepare("UPDATE site_settings SET setting_value = 'image_left', updated_at = NOW() WHERE setting_key = 'portfolio_project_layout'")->execute();
            }

            // Whatever is left of the retired block: rows on pages that are
            // no project's, and drafts never placed.
            $pdo->prepare("DELETE FROM page_sections WHERE section_type = 'project_info'")->execute();
            if ($this->hasTable('content_block_drafts')) {
                $pdo->prepare("DELETE FROM content_block_drafts WHERE section_type = 'project_info'")->execute();
            }

            if ($own) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }

        // DDL commits by itself in MySQL, so after the data is safely moved.
        if ($hasInfos) {
            $this->table('portfolio_project_infos')->drop()->save();
        }
    }

    private function migrateProject(\PDO $pdo, int $itemId, ?string $ownLayout, bool $defaultIsFree, string $default, bool $hasInfos): void
    {
        $exists = $pdo->prepare('SELECT id FROM page_sections WHERE section_type = :type AND section_id = :id LIMIT 1');
        $exists->execute(['type' => self::TYPE, 'id' => $itemId]);
        if ($exists->fetch() !== false) {
            return;
        }

        $page = $this->contentPage($pdo, $itemId);
        $sections = $pdo->prepare('SELECT id, section_type, section_key, is_active FROM page_sections WHERE page_id = :page ORDER BY sort_order, id');
        $sections->execute(['page' => (int) $page['id']]);
        $rows = $sections->fetchAll(\PDO::FETCH_ASSOC);

        $followsDefault = !in_array($ownLayout, [...self::FIXED_LAYOUTS, 'free'], true);
        $isFree = $ownLayout === 'free' || ($followsDefault && $defaultIsFree);

        // Where the photos stood, and whether they showed.
        $position = 0;
        $shown = true;
        $picture = 'left';

        if ($isFree) {
            $info = null;
            foreach ($rows as $index => $row) {
                if ($row['section_type'] === 'project_info') {
                    $info = $row;
                    $position = $index;
                    break;
                }
            }

            $settings = $info !== null && $hasInfos ? $this->infoSettings($pdo, (string) $page['content_key'], (string) $info['section_key']) : null;
            $shown = $info !== null
                && (int) $info['is_active'] === 1
                && $settings !== null
                && (int) $settings['is_active'] === 1
                && (int) $settings['show_gallery'] === 1;
            if ($settings !== null && isset(self::POSITIONS[(string) $settings['image_position']])) {
                $picture = (string) $settings['image_position'];
            }
        }

        $insert = $pdo->prepare(
            'INSERT INTO page_sections (page_id, page_slug, section_type, section_key, section_id, sort_order, is_active, created_at, updated_at)
             VALUES (:page, :slug, :type, NULL, :id, 0, :active, NOW(), NOW())'
        );
        $insert->execute([
            'page' => (int) $page['id'],
            'slug' => (string) $page['content_key'],
            'type' => self::TYPE,
            'id' => $itemId,
            'active' => $shown ? 1 : 0,
        ]);
        $newId = (int) $pdo->lastInsertId();

        // The new order: the block at its place, the Projectinformatie rows
        // gone, every other row in the order it had.
        $order = [];
        foreach ($rows as $index => $row) {
            if ($index === $position) {
                $order[] = $newId;
            }
            if ($row['section_type'] !== 'project_info') {
                $order[] = (int) $row['id'];
            }
        }
        if ($rows === []) {
            $order[] = $newId;
        }

        $delete = $pdo->prepare('DELETE FROM page_sections WHERE id = :id');
        foreach ($rows as $row) {
            if ($row['section_type'] === 'project_info') {
                $delete->execute(['id' => (int) $row['id']]);
            }
        }

        $sort = $pdo->prepare('UPDATE page_sections SET sort_order = :sort WHERE id = :id');
        foreach ($order as $sortOrder => $sectionId) {
            $sort->execute(['sort' => $sortOrder, 'id' => $sectionId]);
        }

        // A free project gets the fixed head it showed.
        if ($ownLayout === 'free') {
            $this->setLayout($pdo, $itemId, self::POSITIONS[$picture]);
        } elseif ($isFree && self::POSITIONS[$picture] !== $default) {
            $this->setLayout($pdo, $itemId, self::POSITIONS[$picture]);
        }
    }

    /** @return array{id: int|string, content_key: string} */
    private function contentPage(\PDO $pdo, int $itemId): array
    {
        $find = $pdo->prepare(
            'SELECT p.id, p.content_key FROM portfolio_content_pages l JOIN pages p ON p.id = l.page_id WHERE l.portfolio_item_id = :id LIMIT 1'
        );
        $find->execute(['id' => $itemId]);
        $page = $find->fetch(\PDO::FETCH_ASSOC);
        if ($page !== false) {
            return $page;
        }

        $contentKey = self::KIND . '_' . $itemId;
        $pdo->prepare(
            "INSERT INTO pages (parent_id, admin_group, content_key, slug, status, is_system, route_path, owner_type, sort_order, created_at, updated_at)
             VALUES (NULL, 'website', :content_key, NULL, 'draft', 0, NULL, :owner_type, 0, NOW(), NOW())"
        )->execute(['content_key' => $contentKey, 'owner_type' => self::KIND]);
        $pageId = (int) $pdo->lastInsertId();

        $pdo->prepare('INSERT INTO portfolio_content_pages (portfolio_item_id, page_id, created_at) VALUES (:item, :page, NOW())')
            ->execute(['item' => $itemId, 'page' => $pageId]);

        return ['id' => $pageId, 'content_key' => $contentKey];
    }

    /** @return array<string, mixed>|null */
    private function infoSettings(\PDO $pdo, string $pageSlug, string $sectionKey): ?array
    {
        $stmt = $pdo->prepare('SELECT is_active, image_position, show_gallery FROM portfolio_project_infos WHERE page_slug = :slug AND section_key = :section_key LIMIT 1');
        $stmt->execute(['slug' => $pageSlug, 'section_key' => $sectionKey]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    private function setLayout(\PDO $pdo, int $itemId, string $layout): void
    {
        $pdo->prepare('UPDATE portfolio_gallery_items SET project_layout = :layout WHERE id = :id')
            ->execute(['layout' => $layout, 'id' => $itemId]);
    }
}

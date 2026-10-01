<?php

declare(strict_types=1);

namespace Tests\Install;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\ScratchInstall;

/**
 * db/migrations/20261014100000_give_every_project_a_project_images_block.php
 * (Portfolio 3.0) on two upgraded installations, one with the Portfolio
 * default "image_left" and one with the default "free":
 *
 *   - every project ends with exactly ONE Projectafbeeldingen row, its
 *     section_id the project's id, on its own content page (made when it had
 *     none);
 *   - a fixed-layout project: the block first, shown, every other block in
 *     its old order after it;
 *   - a free project: the block where its Projectinformatie block stood,
 *     shown only when that block showed its photos; without one, first and
 *     hidden; and the fixed layout of the head it showed;
 *   - a free default becomes image_left, a follower whose picture stood
 *     elsewhere gets that as its own layout;
 *   - no Projectinformatie row, draft or table is left, and no project,
 *     photo or translation changed;
 *   - running it again changes nothing.
 */
#[Group('migration-backfill')]
final class ProjectImagesBlockMigrationTest extends TestCase
{
    private const UPGRADED = 'mygdala_scratch_pf3_upgraded';
    private const FREE_DEFAULT = 'mygdala_scratch_pf3_free_default';

    private const BEFORE = '20261013100000';
    private const MIGRATION = '20261014100000';

    private const UNTOUCHED = ['portfolio_item_images', 'portfolio_item_translations', 'media'];

    private static ?ScratchInstall $upgraded = null;
    private static ?ScratchInstall $freeDefault = null;

    /** @var array<string, int> project name => id */
    private static array $projects = [];

    /** @var array<string, int> block name => page_sections.id */
    private static array $blocks = [];

    /** @var array<string, list<array<string, mixed>>> */
    private static array $untouchedBefore = [];

    /** @var array<string, list<array<string, mixed>>> */
    private static array $after = [];

    /** @var array<string, list<array<string, mixed>>> */
    private static array $afterReplay = [];

    public static function setUpBeforeClass(): void
    {
        if (!ScratchInstall::available()) {
            return;
        }

        self::$upgraded = ScratchInstall::upTo(self::UPGRADED, self::BEFORE);
        self::seed(self::$upgraded);
        self::$untouchedBefore = self::untouched(self::$upgraded);
        self::$upgraded->catchUp(self::MIGRATION);
        self::$after = self::state(self::$upgraded);
        self::$upgraded->replay(self::MIGRATION, self::MIGRATION);
        self::$afterReplay = self::state(self::$upgraded);

        self::$freeDefault = ScratchInstall::upTo(self::FREE_DEFAULT, self::BEFORE);
        self::seedFreeDefault(self::$freeDefault);
        self::$freeDefault->catchUp(self::MIGRATION);
    }

    protected function setUp(): void
    {
        if (!ScratchInstall::available()) {
            $this->markTestSkipped('A from-zero install needs the MySQL root account (DB_ROOT_PASSWORD in .env).');
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::$upgraded?->drop();
        self::$freeDefault?->drop();
        self::$upgraded = null;
        self::$freeDefault = null;
    }

    public function testEveryProjectHasExactlyOneBlockOnItsOwnContentPage(): void
    {
        foreach ([self::$upgraded, self::$freeDefault] as $install) {
            $items = $install->rows('SELECT id FROM portfolio_gallery_items ORDER BY id');
            $this->assertNotEmpty($items);

            foreach ($items as $item) {
                $blocks = $install->rows(
                    "SELECT s.page_id, s.page_slug, s.section_key, l.page_id AS linked_page, p.owner_type, p.content_key
                       FROM page_sections s
                       JOIN pages p ON p.id = s.page_id
                       LEFT JOIN portfolio_content_pages l ON l.portfolio_item_id = s.section_id
                      WHERE s.section_type = 'project_images' AND s.section_id = ?",
                    [(int) $item['id']]
                );

                $this->assertCount(1, $blocks, 'project #' . $item['id']);
                $this->assertSame((int) $blocks[0]['linked_page'], (int) $blocks[0]['page_id'], 'on the project\'s own content page');
                $this->assertSame('portfolio_project', $blocks[0]['owner_type']);
                $this->assertSame('portfolio_project_' . $item['id'], $blocks[0]['content_key']);
                $this->assertSame($blocks[0]['content_key'], $blocks[0]['page_slug']);
                $this->assertNull($blocks[0]['section_key']);
            }
        }
    }

    public function testAFixedProjectWithoutBlocksGetsTheBlockShownAndAlone(): void
    {
        $this->assertSame([['project_images', 1]], self::listOf('fixed-no-blocks'));
        $this->assertNull(self::layoutOf('fixed-no-blocks'));
    }

    public function testAFixedProjectKeepsItsBlocksInOrderBelowThePhotos(): void
    {
        $this->assertSame(
            [['project_images', 1], ['rich_text', 1], ['rich_text', 0], ['rich_text', 1]],
            self::listOf('fixed-with-blocks')
        );
        $ids = array_column(self::sectionsOf('fixed-with-blocks'), 'id');
        $this->assertSame([self::$blocks['first'], self::$blocks['hidden'], self::$blocks['last']], array_map('intval', array_slice($ids, 1)));
        $this->assertSame('image_right', self::layoutOf('fixed-with-blocks'), 'a fixed choice is kept');
    }

    public function testAProjectWithoutPhotosStillGetsItsBlock(): void
    {
        $this->assertSame([['project_images', 1]], self::listOf('no-photos'));
        $this->assertSame('image_top', self::layoutOf('no-photos'));
    }

    public function testAFreeProjectGetsTheBlockWhereItsProjectinformatieStood(): void
    {
        $this->assertSame(
            [['rich_text', 1], ['project_images', 1], ['rich_text', 1]],
            self::listOf('free-info-middle')
        );
        $this->assertSame('image_right', self::layoutOf('free-info-middle'), 'the head keeps its picture on the right');
    }

    public function testAFreeProjectWhosePhotosDidNotShowGetsAHiddenBlock(): void
    {
        $this->assertSame([['project_images', 0], ['rich_text', 1]], self::listOf('free-info-hidden'));
        $this->assertSame('image_top', self::layoutOf('free-info-hidden'));

        $this->assertSame([['project_images', 0]], self::listOf('free-no-gallery'));
        $this->assertSame('image_left', self::layoutOf('free-no-gallery'));

        $this->assertSame([['project_images', 0], ['rich_text', 1]], self::listOf('free-without-info'));
        $this->assertSame('image_left', self::layoutOf('free-without-info'), 'no picture position to keep: the default every site had');
    }

    public function testNothingOfProjectinformatieIsLeft(): void
    {
        foreach ([self::$upgraded, self::$freeDefault] as $install) {
            $this->assertFalse($install->hasTable('portfolio_project_infos'));
            $this->assertSame([], $install->rows("SELECT id FROM page_sections WHERE section_type = 'project_info'"));
            $this->assertSame([], $install->rows("SELECT id FROM content_block_drafts WHERE section_type = 'project_info'"));
            $this->assertSame([], $install->rows("SELECT id FROM portfolio_gallery_items WHERE project_layout = 'free'"));
        }
    }

    public function testNoProjectPhotoOrTranslationChanged(): void
    {
        $this->assertSame(self::$untouchedBefore, self::untouched(self::$upgraded));
        $this->assertCount(3, self::$untouchedBefore['portfolio_item_images']);
    }

    public function testAFreeDefaultBecomesImageLeftAndAFollowerKeepsItsPicture(): void
    {
        $install = self::$freeDefault;

        $this->assertSame('image_left', $install->rows("SELECT setting_value FROM site_settings WHERE setting_key = 'portfolio_project_layout'")[0]['setting_value']);

        $layouts = [];
        foreach ($install->rows('SELECT slug, project_layout FROM portfolio_gallery_items ORDER BY id') as $row) {
            $layouts[$row['slug']] = $row['project_layout'];
        }

        $this->assertSame('image_top', $layouts['zz-pf3-follower-top'], 'its block put the picture on top');
        $this->assertNull($layouts['zz-pf3-follower-left'], 'left is the new default: it keeps following');
        $this->assertNull($layouts['zz-pf3-follower-none']);
        $this->assertSame('image_right', $layouts['zz-pf3-own-right'], 'an own fixed choice is not touched');

        $followerTop = (int) $install->rows("SELECT id FROM portfolio_gallery_items WHERE slug = 'zz-pf3-follower-top'")[0]['id'];
        $this->assertSame(
            [['project_images', 1]],
            array_map(
                static fn (array $row): array => [$row['section_type'], (int) $row['is_active']],
                $install->rows('SELECT s.section_type, s.is_active FROM page_sections s JOIN portfolio_content_pages l ON l.page_id = s.page_id WHERE l.portfolio_item_id = ? ORDER BY s.sort_order, s.id', [$followerTop])
            )
        );
        // A follower of a free default that is not free itself shows its
        // photos above its blocks, as every fixed project did.
        $ownRight = (int) $install->rows("SELECT id FROM portfolio_gallery_items WHERE slug = 'zz-pf3-own-right'")[0]['id'];
        $this->assertSame(1, (int) $install->rows("SELECT is_active FROM page_sections WHERE section_type = 'project_images' AND section_id = ?", [$ownRight])[0]['is_active']);
    }

    public function testRunningItAgainChangesNothing(): void
    {
        $this->assertSame(self::$after, self::$afterReplay);
    }

    /** @return list<array{0: string, 1: int}> */
    private static function listOf(string $project): array
    {
        return array_map(
            static fn (array $row): array => [(string) $row['section_type'], (int) $row['is_active']],
            self::sectionsOf($project)
        );
    }

    /** @return list<array<string, mixed>> */
    private static function sectionsOf(string $project): array
    {
        return self::$upgraded->rows(
            'SELECT s.id, s.section_type, s.is_active FROM page_sections s
               JOIN portfolio_content_pages l ON l.page_id = s.page_id
              WHERE l.portfolio_item_id = ? ORDER BY s.sort_order, s.id',
            [self::$projects[$project]]
        );
    }

    private static function layoutOf(string $project): ?string
    {
        return self::$upgraded->rows('SELECT project_layout FROM portfolio_gallery_items WHERE id = ?', [self::$projects[$project]])[0]['project_layout'];
    }

    private static function seed(ScratchInstall $install): void
    {
        $pdo = $install->pdo();
        $gallery = self::gallery($install);
        $pdo->exec("DELETE FROM site_settings WHERE setting_key = 'portfolio_project_layout'");

        self::$projects['fixed-no-blocks'] = self::project($install, $gallery, 'zz-pf3-fixed-no-blocks', null);
        foreach ([1, 2, 3] as $position) {
            $pdo->exec('INSERT INTO portfolio_item_images (portfolio_item_id, image_path, thumbnail_path, sort_order, created_at, updated_at)
                        VALUES (' . self::$projects['fixed-no-blocks'] . ", 'assets/images/portfolio/zz-pf3-{$position}.jpg', 'assets/images/portfolio/zz-pf3-{$position}-thumb.jpg', {$position}, NOW(), NOW())");
        }
        $pdo->exec('INSERT INTO portfolio_item_translations (portfolio_item_id, language_code, title, intro, created_at, updated_at)
                    SELECT ' . self::$projects['fixed-no-blocks'] . ", code, 'ZZ PF3 project', '<p>Inleiding</p>', NOW(), NOW() FROM site_languages WHERE is_default = 1");

        self::$projects['fixed-with-blocks'] = self::project($install, $gallery, 'zz-pf3-fixed-with-blocks', 'image_right');
        $page = self::contentPage($install, self::$projects['fixed-with-blocks']);
        self::$blocks['first'] = self::richText($install, $page, 10, 1);
        self::$blocks['hidden'] = self::richText($install, $page, 20, 0);
        self::$blocks['last'] = self::richText($install, $page, 30, 1);

        self::$projects['no-photos'] = self::project($install, $gallery, 'zz-pf3-no-photos', 'image_top');

        self::$projects['free-info-middle'] = self::project($install, $gallery, 'zz-pf3-free-info-middle', 'free');
        $page = self::contentPage($install, self::$projects['free-info-middle']);
        self::richText($install, $page, 10, 1);
        self::projectInfo($install, $page, 20, 1, 1, 'right', 1);
        self::richText($install, $page, 30, 1);

        self::$projects['free-info-hidden'] = self::project($install, $gallery, 'zz-pf3-free-info-hidden', 'free');
        $page = self::contentPage($install, self::$projects['free-info-hidden']);
        self::projectInfo($install, $page, 10, 0, 1, 'top', 1);
        self::richText($install, $page, 20, 1);
        // A Projectinformatie draft never placed.
        $pdo->exec("INSERT INTO content_block_drafts (page_id, section_type, section_key, section_id, created_at)
                    VALUES ({$page['id']}, 'project_info', 'zz-pf3-draft', 999999, NOW())");

        self::$projects['free-no-gallery'] = self::project($install, $gallery, 'zz-pf3-free-no-gallery', 'free');
        $page = self::contentPage($install, self::$projects['free-no-gallery']);
        self::projectInfo($install, $page, 10, 1, 1, 'left', 0);

        self::$projects['free-without-info'] = self::project($install, $gallery, 'zz-pf3-free-without-info', 'free');
        $page = self::contentPage($install, self::$projects['free-without-info']);
        self::richText($install, $page, 10, 1);
    }

    private static function seedFreeDefault(ScratchInstall $install): void
    {
        $pdo = $install->pdo();
        $gallery = self::gallery($install);
        $pdo->exec("DELETE FROM site_settings WHERE setting_key = 'portfolio_project_layout'");
        $pdo->exec("INSERT INTO site_settings (setting_key, setting_value, created_at, updated_at) VALUES ('portfolio_project_layout', 'free', NOW(), NOW())");

        $top = self::project($install, $gallery, 'zz-pf3-follower-top', null);
        self::projectInfo($install, self::contentPage($install, $top), 10, 1, 1, 'top', 1);

        $left = self::project($install, $gallery, 'zz-pf3-follower-left', null);
        self::projectInfo($install, self::contentPage($install, $left), 10, 1, 1, 'left', 1);

        self::project($install, $gallery, 'zz-pf3-follower-none', null);
        self::project($install, $gallery, 'zz-pf3-own-right', 'image_right');
    }

    private static function gallery(ScratchInstall $install): int
    {
        $existing = $install->rows('SELECT id FROM portfolio_galleries ORDER BY id LIMIT 1');
        if ($existing !== []) {
            return (int) $existing[0]['id'];
        }

        $install->pdo()->exec('INSERT INTO portfolio_galleries (created_at, updated_at) VALUES (NOW(), NOW())');

        return (int) $install->pdo()->lastInsertId();
    }

    private static function project(ScratchInstall $install, int $gallery, string $slug, ?string $layout): int
    {
        $statement = $install->pdo()->prepare(
            "INSERT INTO portfolio_gallery_items (portfolio_gallery_id, image_path, sort_order, is_active, has_detail_page, slug, project_layout, created_at, updated_at)
             VALUES (:gallery, 'assets/images/sections/zz-pf3.jpg', 0, 1, 1, :slug, :layout, NOW(), NOW())"
        );
        $statement->execute(['gallery' => $gallery, 'slug' => $slug, 'layout' => $layout]);

        return (int) $install->pdo()->lastInsertId();
    }

    /** @return array{id: int, content_key: string} */
    private static function contentPage(ScratchInstall $install, int $projectId): array
    {
        $pdo = $install->pdo();
        $key = 'portfolio_project_' . $projectId;
        $pdo->exec("INSERT INTO pages (parent_id, admin_group, content_key, slug, status, is_system, route_path, owner_type, sort_order, created_at, updated_at)
                    VALUES (NULL, 'website', '{$key}', NULL, 'draft', 0, NULL, 'portfolio_project', 0, NOW(), NOW())");
        $pageId = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO portfolio_content_pages (portfolio_item_id, page_id, created_at) VALUES ({$projectId}, {$pageId}, NOW())");

        return ['id' => $pageId, 'content_key' => $key];
    }

    /** @param array{id: int, content_key: string} $page */
    private static function richText(ScratchInstall $install, array $page, int $sort, int $active): int
    {
        $pdo = $install->pdo();
        $key = 'custom-zzpf3' . $sort . $page['id'];
        $pdo->exec("INSERT INTO rich_text_sections (page_slug, section_key, is_active, created_at, updated_at)
                    VALUES ('{$page['content_key']}', '{$key}', 1, NOW(), NOW())");
        $section = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO page_sections (page_id, page_slug, section_type, section_key, section_id, sort_order, is_active, created_at, updated_at)
                    VALUES ({$page['id']}, '{$page['content_key']}', 'rich_text', '{$key}', {$section}, {$sort}, {$active}, NOW(), NOW())");

        return (int) $pdo->lastInsertId();
    }

    /** @param array{id: int, content_key: string} $page */
    private static function projectInfo(ScratchInstall $install, array $page, int $sort, int $listActive, int $rowActive, string $position, int $showGallery): void
    {
        $pdo = $install->pdo();
        $key = 'custom-zzpf3info' . $page['id'];
        $pdo->exec("INSERT INTO portfolio_project_infos (page_slug, section_key, is_active, image_position, show_gallery, created_at, updated_at)
                    VALUES ('{$page['content_key']}', '{$key}', {$rowActive}, '{$position}', {$showGallery}, NOW(), NOW())");
        $section = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO page_sections (page_id, page_slug, section_type, section_key, section_id, sort_order, is_active, created_at, updated_at)
                    VALUES ({$page['id']}, '{$page['content_key']}', 'project_info', '{$key}', {$section}, {$sort}, {$listActive}, NOW(), NOW())");
    }

    /** @return array<string, list<array<string, mixed>>> */
    private static function untouched(ScratchInstall $install): array
    {
        $rows = [];
        foreach (self::UNTOUCHED as $table) {
            $rows[$table] = $install->rows('SELECT * FROM `' . $table . '` ORDER BY 1');
        }

        return $rows;
    }

    /** @return array<string, list<array<string, mixed>>> */
    private static function state(ScratchInstall $install): array
    {
        return [
            'page_sections' => $install->rows('SELECT * FROM page_sections ORDER BY id'),
            'pages' => $install->rows('SELECT * FROM pages ORDER BY id'),
            'portfolio_content_pages' => $install->rows('SELECT * FROM portfolio_content_pages ORDER BY portfolio_item_id'),
            'portfolio_gallery_items' => $install->rows('SELECT * FROM portfolio_gallery_items ORDER BY id'),
            'site_settings' => $install->rows("SELECT * FROM site_settings WHERE setting_key = 'portfolio_project_layout'"),
        ];
    }
}

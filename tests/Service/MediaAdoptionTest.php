<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\MediaRepository;
use App\Service\Branding;
use App\Service\Media\BlockImage;
use App\Service\Media\MediaService;
use App\Service\Media\MediaUploader;
use App\Service\SiteSettings;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * What the adoption migration promised: the images this site was ALREADY
 * showing are in the Media Library, exactly once each, still at the paths
 * they were at, and every feature that used them still points at the same
 * file.
 *
 * These are historical checks in the sense TESTING.md means: they assert what
 * `20260909270000_adopt_existing_cms_images_into_the_media_library` did, not
 * what the library contains today. So they never assert a TOTAL — an image an
 * editor adds later is ordinary CMS data and must not make them fail — only
 * that nothing the migration touched was lost, duplicated or moved.
 */
#[Group('migration-backfill')]
final class MediaAdoptionTest extends TestCase
{
    /**
     * Every table the migration adopted, as [table, path column, media id
     * column].
     */
    private const ADOPTED = [
        ['text_image_split_images', 'image_path', 'media_id'],
        ['detail_sections', 'main_image_path', 'main_media_id'],
        ['detail_section_images', 'image_path', 'media_id'],
        ['carousel_cards', 'image_path', 'media_id'],
        ['pages', 'og_image_path', 'og_media_id'],
    ];

    protected function tearDown(): void
    {
        MediaService::clearCache();
        SiteSettings::clearCache();
    }

    /* ------------------------------------------------------------------ */
    /* Nothing was left behind                                             */
    /* ------------------------------------------------------------------ */

    public function testEveryAdoptedRowGotAMediaReference(): void
    {
        $db = Database::connection();

        foreach (self::ADOPTED as [$table, $pathColumn, $mediaColumn]) {
            $orphans = (int) $db->query(
                'SELECT COUNT(*) FROM `' . $table . '`'
                . ' WHERE `' . $pathColumn . '` IS NOT NULL AND `' . $pathColumn . "` <> ''"
                . ' AND `' . $mediaColumn . '` IS NULL'
                . " AND `" . $pathColumn . "` NOT LIKE 'http%'"
            )->fetchColumn();

            $this->assertSame(
                0,
                $orphans,
                $table . '.' . $pathColumn . ' still has rows with an image but no media reference'
            );
        }
    }

    /**
     * A row's media item must describe the SAME file the row already showed.
     * If these ever diverge, an adopted page silently changed its picture.
     */
    public function testEveryAdoptedReferencePointsAtTheFileTheRowAlreadyShowed(): void
    {
        $db = Database::connection();

        foreach (self::ADOPTED as [$table, $pathColumn, $mediaColumn]) {
            $mismatches = $db->query(
                // `stored` would be a reserved word in MySQL 8 (generated columns).
                'SELECT t.`' . $pathColumn . '` AS row_path, m.path AS media_path'
                . ' FROM `' . $table . '` t'
                . ' JOIN media m ON m.id = t.`' . $mediaColumn . '`'
                . ' WHERE TRIM(LEADING \'/\' FROM t.`' . $pathColumn . '`) <> m.path'
            )->fetchAll();

            $this->assertSame([], $mismatches, $table . ' points at a different file than it used to show');
        }
    }

    /**
     * ONE row per file. Two rows for one image would make "where is this
     * used" answer for half the usages, which is the failure this whole
     * design exists to prevent.
     */
    public function testNoFileWasAdoptedTwice(): void
    {
        $duplicates = Database::connection()
            ->query('SELECT path, COUNT(*) AS n FROM media GROUP BY path HAVING n > 1')
            ->fetchAll();

        $this->assertSame([], $duplicates, 'media.path must be unique — a file is one item');
    }

    /**
     * ADOPT IN PLACE. A legacy file keeps the path it had; only NEW uploads
     * go to the library's own folder. If the migration had moved files, every
     * one of these would now start with assets/media/.
     */
    public function testAdoptedFilesKeptTheirOriginalPaths(): void
    {
        $adopted = Database::connection()
            ->query("SELECT path FROM media WHERE path LIKE 'assets/images/%'")
            ->fetchAll();

        $this->assertNotSame([], $adopted, 'this site had images before the library; some must have been adopted');

        foreach ($adopted as $row) {
            $this->assertStringStartsNotWith(
                MediaUploader::PUBLIC_PREFIX,
                (string) $row['path'],
                'an adopted file must not have been moved into the library folder'
            );
        }
    }

    /**
     * The same photo used by two features became ONE item referenced twice —
     * which is the entire point of the exercise. This site's development data
     * genuinely contains such a file.
     */
    public function testAFileUsedByTwoFeaturesBecameOneSharedItem(): void
    {
        $shared = Database::connection()->query(
            'SELECT m.id, COUNT(*) AS n FROM media m
             JOIN (
                 SELECT media_id AS id FROM text_image_split_images WHERE media_id IS NOT NULL
                 UNION ALL
                 SELECT media_id FROM detail_section_images WHERE media_id IS NOT NULL
                 UNION ALL
                 SELECT media_id FROM carousel_cards WHERE media_id IS NOT NULL
             ) refs ON refs.id = m.id
             GROUP BY m.id HAVING n > 1'
        )->fetchAll();

        $this->assertNotSame(
            [],
            $shared,
            'at least one image on this site is used in more than one place and must be a single media item'
        );
    }

    /* ------------------------------------------------------------------ */
    /* Alt text was carried over, not replaced                             */
    /* ------------------------------------------------------------------ */

    public function testAdoptedItemsInheritedTheAltTextTheirFeatureAlreadyHad(): void
    {
        $rows = Database::connection()->query(
            "SELECT i.alt_nl, m.alt_text
               FROM text_image_split_images i
               JOIN media m ON m.id = i.media_id
              WHERE i.alt_nl IS NOT NULL AND i.alt_nl <> ''
              LIMIT 5"
        )->fetchAll();

        $this->assertNotSame([], $rows, 'this site has captioned images to inherit from');

        foreach ($rows as $row) {
            $this->assertNotSame('', (string) $row['alt_text'], 'the media item should have inherited an alt text');
        }
    }

    /**
     * The per-feature alt columns were NOT emptied. A photo captioned
     * differently in two places must stay captioned differently, so the local
     * value still wins over the central default.
     */
    public function testTheLocalAltTextStillWinsOverTheCentralOne(): void
    {
        MediaService::overrideForTests([
            42 => ['path' => 'assets/media/x.png', 'alt_text' => 'Centrale omschrijving', 'mime_type' => 'image/png'],
        ]);

        $local = BlockImage::fromRow(['media_id' => 42, 'alt_nl' => 'Lokale omschrijving', 'alt_en' => '']);
        $this->assertSame('Lokale omschrijving', $local['alt_nl']);
        $this->assertSame('Lokale omschrijving', $local['alt_en'], 'empty English still means "same as Dutch"');

        $inherited = BlockImage::fromRow(['media_id' => 42, 'alt_nl' => '', 'alt_en' => '']);
        $this->assertSame('Centrale omschrijving', $inherited['alt_nl'], 'an empty local field falls back to the library');

        MediaService::overrideForTests(null);
    }

    /* ------------------------------------------------------------------ */
    /* Branding survived unchanged                                         */
    /* ------------------------------------------------------------------ */

    public function testTheSitesOwnBrandingStillResolvesToTheSameFiles(): void
    {
        MediaService::clearCache();
        SiteSettings::clearCache();

        foreach (Branding::MEDIA_KEYS as $pathKey => $mediaKey) {
            $storedPath = trim(SiteSettings::get($pathKey));
            $mediaId = (int) SiteSettings::get($mediaKey);

            if ($storedPath === '' || $mediaId < 1) {
                continue;
            }

            $item = MediaService::find($mediaId);
            $this->assertNotNull($item, $mediaKey . ' points at a media item that does not exist');
            $this->assertSame(
                ltrim($storedPath, '/'),
                $item->path,
                $mediaKey . ' must describe the same file ' . $pathKey . ' already did'
            );
        }
    }

    /**
     * The site's logo is an SVG. The uploaders refuse one — it can carry
     * script — but the adoption gave it an identity anyway, so it has central
     * alt text and a usage count like everything else while staying a file
     * only a deploy can replace.
     */
    public function testAnSvgLogoWasAdoptedEvenThoughOneCouldNeverBeUploaded(): void
    {
        $svg = (new MediaRepository())->findByPath('assets/images/vanveluwlaserdesignlogo.svg');

        if ($svg === null) {
            $this->markTestSkipped('this install does not use an SVG logo');
        }

        $this->assertSame('image/svg+xml', $svg['mime_type']);
        $this->assertNull($svg['width'], 'getimagesize() cannot size an SVG, and the row says so honestly');
    }

    /**
     * A path that is not a file this site owns is not adopted: an absolute
     * URL cannot be inspected, deleted, or described.
     */
    public function testAnAbsoluteUrlWasNeverAdopted(): void
    {
        $absolute = Database::connection()
            ->query("SELECT COUNT(*) FROM media WHERE path LIKE 'http%'")
            ->fetchColumn();

        $this->assertSame(0, (int) $absolute);
    }
}

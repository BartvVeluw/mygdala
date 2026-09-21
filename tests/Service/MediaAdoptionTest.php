<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\Media\BlockImage;
use App\Service\Media\MediaService;
use App\Service\Media\MediaUploader;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\ScratchInstall;

/**
 * What `20260909270000_adopt_existing_cms_images_into_the_media_library`
 * promises every installation that had images before the Media Library: each
 * image a CMS feature was already showing is in the library, exactly once,
 * still at the path it was at, and every feature that used it points at that
 * same file.
 *
 * Proven on a throwaway database that stands where such an installation
 * stood — migrated up to the migration that added the media columns — holding
 * images this test places itself: one file used by two features with two
 * different captions, a file that only gets a caption from its second user,
 * a page's social image, an SVG logo, an external URL, and a row an editor
 * had already pointed at another item. The rest of the migrations then run
 * exactly as `phinx migrate` runs them on a real upgrade
 * (Tests\Support\ScratchInstall).
 *
 * None of the files exist on disk. That is deliberate and part of the
 * contract: a path whose file is gone is adopted with unknown dimensions
 * rather than skipped, so the admin can show it as broken.
 */
#[Group('migration-backfill')]
final class MediaAdoptionTest extends TestCase
{
    private const DATABASE = 'mygdala_scratch_media_adoption';

    /** The migration that added the media id columns: where an older installation stood. */
    private const BEFORE_ADOPTION = '20260909260000';

    private const ADOPTION_MIGRATION = '20260909270000';

    /** The migration that gave every item a name of its own (MEDIA.md, "Bestandsnaam"). */
    private const DISPLAY_NAME_MIGRATION = '20260914100000';

    /**
     * The last migration before the alt texts of the Tekst met afbeelding,
     * Detailsectie and Kaarten-carrousel blocks moved into block_translations
     * (Multilingual 2.0 phase 3B, 20260917200000). This test is about the
     * adoption, which reads those alt columns, so its installation stops
     * there and keeps storing them the way the adoption found them; that the
     * move carries them over is Tests\Install\RemainingBlockWordsMigrationTest's.
     */
    private const BEFORE_BLOCK_WORDS = '20260917190000';

    private const SHARED = 'assets/images/zz-media-adoption/werkplaats.jpg';
    private const CAPTIONED_LATER = 'assets/images/zz-media-adoption/detail.png';
    private const SOCIAL = 'assets/images/zz-media-adoption/delen.webp';
    private const LOGO = 'assets/images/zz-media-adoption/logo.svg';
    private const REPLACED = 'assets/images/zz-media-adoption/vervangen.jpg';
    private const EDITOR_CHOICE = 'assets/media/zz-gekozen-door-redacteur.webp';
    private const NAMELESS = 'assets/media/zz-zonder-naam.png';
    private const EXTERNAL_IMAGE = 'https://cdn.example.com/kaart.jpg';
    private const EXTERNAL_FAVICON = 'https://cdn.example.com/favicon.ico';

    /**
     * Every table the migration adopts, as [table, path column, media id
     * column].
     */
    private const ADOPTED = [
        ['text_image_split_images', 'image_path', 'media_id'],
        ['detail_sections', 'main_image_path', 'main_media_id'],
        ['detail_section_images', 'image_path', 'media_id'],
        ['carousel_cards', 'image_path', 'media_id'],
        ['pages', 'og_image_path', 'og_media_id'],
    ];

    private static ?ScratchInstall $install = null;

    /** @var array<string, int> fixture name => row id */
    private static array $ids = [];

    /** @var array<string, list<array<string, mixed>>> */
    private static array $afterFirstRun = [];

    public static function setUpBeforeClass(): void
    {
        if (!ScratchInstall::available()) {
            return;
        }

        self::$install = ScratchInstall::upTo(self::DATABASE, self::BEFORE_ADOPTION);
        $slug = 'zz-media-' . bin2hex(random_bytes(4));

        self::$ids['editor_item'] = self::insert('media', [
            'path' => self::EDITOR_CHOICE,
            'original_filename' => basename(self::EDITOR_CHOICE),
            'mime_type' => 'image/webp',
            'alt_text' => 'Door de redacteur gekozen',
        ]);

        // A row that never had an original filename: it is named after its
        // own stored file when names arrive.
        self::$ids['nameless_item'] = self::insert('media', [
            'path' => self::NAMELESS,
            'original_filename' => '',
            'mime_type' => 'image/png',
        ]);

        // One file, two features, two captions — the second with a leading
        // slash, the way some older columns stored it.
        self::$ids['section'] = self::insert('detail_sections', [
            'page_slug' => $slug,
            'section_key' => 'main',
            'title_nl' => 'Sectie',
            'main_image_path' => '/' . self::SHARED,
            'main_image_alt_nl' => 'Werkplaats',
        ]);
        self::$ids['section_image'] = self::insert('detail_section_images', [
            'section_id' => self::$ids['section'],
            'image_path' => self::SHARED,
            'alt_nl' => 'Werkplaats van dichtbij',
        ]);

        // A file whose first user has no caption and whose second does.
        self::$ids['uncaptioned_section'] = self::insert('detail_sections', [
            'page_slug' => $slug,
            'section_key' => 'tweede',
            'title_nl' => 'Tweede sectie',
            'main_image_path' => self::CAPTIONED_LATER,
            'main_image_alt_nl' => '',
        ]);

        // A row an editor already pointed at a different library item.
        self::$ids['repointed_image'] = self::insert('detail_section_images', [
            'section_id' => self::$ids['section'],
            'image_path' => self::REPLACED,
            'alt_nl' => 'Oude foto',
            'media_id' => self::$ids['editor_item'],
        ]);

        $carousel = self::insert('card_carousels', ['page_slug' => $slug, 'section_key' => 'main']);
        self::$ids['captioned_card'] = self::insert('carousel_cards', [
            'carousel_id' => $carousel,
            'title_nl' => 'Detail',
            'image_path' => self::CAPTIONED_LATER,
            'image_alt_nl' => 'Later beschreven',
        ]);
        self::$ids['external_card'] = self::insert('carousel_cards', [
            'carousel_id' => $carousel,
            'title_nl' => 'Extern',
            'image_path' => self::EXTERNAL_IMAGE,
        ]);

        self::$ids['page'] = self::insert('pages', [
            'content_key' => $slug,
            'slug' => $slug,
            'title' => 'Mediapagina',
            'status' => 'published',
            'og_image_path' => self::SOCIAL,
        ]);

        self::insert('site_settings', ['setting_key' => 'logo_path', 'setting_value' => self::LOGO]);
        self::insert('site_settings', ['setting_key' => 'favicon_path', 'setting_value' => self::EXTERNAL_FAVICON]);

        self::$install->catchUp(self::BEFORE_BLOCK_WORDS);
        self::$afterFirstRun = self::adoptionSnapshot();

        self::$install->replay(self::ADOPTION_MIGRATION, self::BEFORE_BLOCK_WORDS);
    }

    public static function tearDownAfterClass(): void
    {
        self::$install?->drop();
        self::$install = null;
    }

    protected function tearDown(): void
    {
        MediaService::clearCache();
    }

    /* ------------------------------------------------------------------ */
    /* Nothing was left behind                                             */
    /* ------------------------------------------------------------------ */

    public function testEveryLocalImageGotAMediaReference(): void
    {
        $this->assertNotNull($this->row('detail_sections', self::$ids['section'])['main_media_id']);
        $this->assertNotNull($this->row('detail_sections', self::$ids['uncaptioned_section'])['main_media_id']);
        $this->assertNotNull($this->row('detail_section_images', self::$ids['section_image'])['media_id']);
        $this->assertNotNull($this->row('carousel_cards', self::$ids['captioned_card'])['media_id']);
        $this->assertNotNull($this->row('pages', self::$ids['page'])['og_media_id'], 'a page\'s own social image is adopted too');

        foreach (self::ADOPTED as [$table, $pathColumn, $mediaColumn]) {
            $orphans = $this->install()->rows(
                'SELECT id FROM `' . $table . '`'
                . ' WHERE `' . $pathColumn . '` IS NOT NULL AND `' . $pathColumn . "` <> ''"
                . ' AND `' . $mediaColumn . '` IS NULL'
                . ' AND `' . $pathColumn . "` NOT LIKE 'http%'"
            );

            $this->assertSame([], $orphans, $table . '.' . $pathColumn . ' still has rows with an image but no media reference');
        }
    }

    /**
     * A row's media item must describe the SAME file the row already showed.
     * If these ever diverge, an adopted page silently changed its picture.
     */
    public function testEveryAdoptedReferencePointsAtTheFileTheRowAlreadyShowed(): void
    {
        foreach (self::ADOPTED as [$table, $pathColumn, $mediaColumn]) {
            $mismatches = $this->install()->rows(
                'SELECT t.`' . $pathColumn . '` AS row_path, m.path AS media_path'
                . ' FROM `' . $table . '` t'
                . ' JOIN media m ON m.id = t.`' . $mediaColumn . '`'
                . ' WHERE TRIM(LEADING \'/\' FROM t.`' . $pathColumn . '`) <> m.path'
                // The one row allowed to differ: an editor chose another item
                // before the migration ran, and that choice is theirs.
                . ' AND NOT (? = ? AND t.id = ?)',
                [$table, 'detail_section_images', self::$ids['repointed_image']]
            );

            $this->assertSame([], $mismatches, $table . ' points at a different file than it used to show');
        }
    }

    /**
     * The same photo used by two features became ONE item referenced twice —
     * which is the entire point of the exercise.
     */
    public function testAFileUsedByTwoFeaturesBecameOneSharedItem(): void
    {
        $this->assertSame(
            (int) $this->row('detail_sections', self::$ids['section'])['main_media_id'],
            (int) $this->row('detail_section_images', self::$ids['section_image'])['media_id'],
            'one file, two features, one media item'
        );
        $this->assertSame(
            (int) $this->row('detail_sections', self::$ids['uncaptioned_section'])['main_media_id'],
            (int) $this->row('carousel_cards', self::$ids['captioned_card'])['media_id'],
            'the same holds across two different block types'
        );
    }

    /**
     * ONE row per file. Two rows for one image would make "where is this
     * used" answer for half the usages.
     */
    public function testNoFileWasAdoptedTwice(): void
    {
        $this->assertSame(
            [],
            $this->install()->rows('SELECT path, COUNT(*) AS n FROM media GROUP BY path HAVING n > 1'),
            'media.path must be unique — a file is one item'
        );
    }

    /**
     * ADOPT IN PLACE. A legacy file keeps the path it had, without the
     * leading slash the media table never stores; only NEW uploads go to the
     * library's own folder. The feature rows themselves are not rewritten.
     */
    public function testAdoptedFilesKeptTheirOriginalPaths(): void
    {
        foreach ([self::SHARED, self::CAPTIONED_LATER, self::SOCIAL, self::LOGO] as $path) {
            $item = $this->mediaAt($path);

            $this->assertStringStartsNotWith(
                MediaUploader::PUBLIC_PREFIX,
                (string) $item['path'],
                'an adopted file must not have been moved into the library folder'
            );
        }

        $this->assertSame('/' . self::SHARED, (string) $this->row('detail_sections', self::$ids['section'])['main_image_path']);
    }

    public function testAPathWhoseFileIsMissingIsStillAdoptedWithUnknownDimensions(): void
    {
        $item = $this->mediaAt(self::SHARED);

        $this->assertSame('image/jpeg', (string) $item['mime_type'], 'the type still follows from the extension');
        $this->assertNull($item['width']);
        $this->assertNull($item['height']);
        $this->assertNull($item['file_size']);
        $this->assertSame(basename(self::SHARED), (string) $item['original_filename']);
    }

    /* ------------------------------------------------------------------ */
    /* Alt text was carried over, not replaced                             */
    /* ------------------------------------------------------------------ */

    public function testTheFirstNonEmptyAltTextBecameTheItemsDefault(): void
    {
        $this->assertSame('Werkplaats', (string) $this->mediaAt(self::SHARED)['alt_text'], 'the first caption wins');
        $this->assertSame(
            'Later beschreven',
            (string) $this->mediaAt(self::CAPTIONED_LATER)['alt_text'],
            'a file adopted without a caption takes one from the next place that has it'
        );
    }

    /**
     * The per-feature alt columns were NOT emptied. A photo captioned
     * differently in two places stays captioned differently.
     */
    public function testTheLocalAltTextsWereLeftWhereTheyWere(): void
    {
        $this->assertSame('Werkplaats', (string) $this->row('detail_sections', self::$ids['section'])['main_image_alt_nl']);
        $this->assertSame('Werkplaats van dichtbij', (string) $this->row('detail_section_images', self::$ids['section_image'])['alt_nl']);
    }

    /** ... and that local value still wins over the central default at render time. */
    public function testTheLocalAltTextStillWinsOverTheCentralOne(): void
    {
        MediaService::overrideForTests([
            42 => ['path' => 'assets/media/x.png', 'alt_text' => 'Centrale omschrijving', 'mime_type' => 'image/png'],
        ]);

        // The block's own alt text arrives already in the language of the
        // request, its fallback applied (BlockLocalization::text()).
        $ownWords = BlockImage::fromOwner(['media_id' => 42], 'Lokale omschrijving');
        $this->assertSame('Lokale omschrijving', $ownWords['alt']);

        $noWords = BlockImage::fromOwner(['media_id' => 42], '');
        $this->assertSame('Centrale omschrijving', $noWords['alt'], 'no alt text of its own: the library\'s');

        $noneAsked = BlockImage::fromOwner(['media_id' => 42], null);
        $this->assertSame('Centrale omschrijving', $noneAsked['alt'], 'a block without an alt field: the library\'s');

        MediaService::overrideForTests(null);
    }

    /* ------------------------------------------------------------------ */
    /* Branding, external URLs and an editor's own choice                  */
    /* ------------------------------------------------------------------ */

    public function testTheBrandingSettingNowAlsoPointsAtAnItemForTheSameFile(): void
    {
        $mediaId = $this->setting('logo_media_id');
        $this->assertNotNull($mediaId, 'logo_path was set, so logo_media_id must be');

        $this->assertSame((int) $this->mediaAt(self::LOGO)['id'], (int) $mediaId);
        $this->assertSame(self::LOGO, $this->setting('logo_path'), 'the path keeps working as the fallback');
    }

    /**
     * The uploaders refuse an SVG — it can carry script — but an SVG logo a
     * site already had is adopted anyway, so it has central alt text and a
     * usage count like everything else.
     */
    public function testAnSvgLogoIsAdoptedEvenThoughOneCouldNeverBeUploaded(): void
    {
        $svg = $this->mediaAt(self::LOGO);

        $this->assertSame('image/svg+xml', (string) $svg['mime_type']);
        $this->assertNull($svg['width'], 'getimagesize() cannot size an SVG, and the row says so honestly');
    }

    /**
     * An absolute URL is not a file this site owns: it cannot be inspected,
     * deleted, or described.
     */
    public function testAnAbsoluteUrlIsNeverAdopted(): void
    {
        $this->assertSame([], $this->install()->rows("SELECT id FROM media WHERE path LIKE 'http%'"));
        $this->assertNull($this->row('carousel_cards', self::$ids['external_card'])['media_id']);
        $this->assertNull($this->setting('favicon_media_id'), 'an external favicon gets no library reference');
    }

    public function testARowAnEditorAlreadyRepointedIsLeftAlone(): void
    {
        $this->assertSame(
            self::$ids['editor_item'],
            (int) $this->row('detail_section_images', self::$ids['repointed_image'])['media_id'],
            'a media reference that is already set is never overwritten'
        );
        $this->assertSame(
            [],
            $this->install()->rows('SELECT id FROM media WHERE path = ?', [self::REPLACED]),
            'and the path it replaced is not adopted behind the editor\'s back'
        );
    }

    public function testRunningTheAdoptionAgainChangesNothing(): void
    {
        $this->install();

        $this->assertNotSame([], self::$afterFirstRun['media']);
        $this->assertSame(
            self::$afterFirstRun,
            self::adoptionSnapshot(),
            'a second run must not add, move, re-caption or re-point anything'
        );
    }

    /* ------------------------------------------------------------------ */
    /* Names (20260914100000)                                              */
    /* ------------------------------------------------------------------ */

    /**
     * An upgrade names every item it brings along the way the library already
     * showed it, so an editor sees no difference the day names arrive: the
     * original filename, or — for a row that never had one — the stored
     * file's own name.
     */
    public function testEveryItemAnUpgradeBringsAlongKeepsTheNameItAlreadyShowed(): void
    {
        $this->assertSame(basename(self::SHARED), (string) $this->mediaAt(self::SHARED)['display_name'], 'an adopted file');
        $this->assertSame(basename(self::EDITOR_CHOICE), (string) $this->row('media', self::$ids['editor_item'])['display_name'], 'an uploaded file');
        $this->assertSame(basename(self::NAMELESS), (string) $this->row('media', self::$ids['nameless_item'])['display_name'], 'a row without an original filename');
        $this->assertSame([], $this->install()->rows("SELECT id FROM media WHERE display_name = ''"), 'no item is left without a name');
    }

    /** Run a second time, the naming migration changes nothing the first run wrote. */
    public function testNamingTheLibraryAgainChangesNothing(): void
    {
        $before = $this->install()->rows('SELECT id, display_name FROM media ORDER BY id');

        $this->install()->replay(self::DISPLAY_NAME_MIGRATION, self::BEFORE_BLOCK_WORDS);

        $this->assertNotSame([], $before);
        $this->assertSame($before, $this->install()->rows('SELECT id, display_name FROM media ORDER BY id'));
    }

    // --------------------------------------------------------------- helpers

    private function install(): ScratchInstall
    {
        if (self::$install === null) {
            $this->markTestSkipped(
                'Replaying an upgrade needs the MySQL root account (DB_ROOT_PASSWORD in .env).'
            );
        }

        return self::$install;
    }

    /** @return array<string, mixed> */
    private function row(string $table, int $id): array
    {
        $rows = $this->install()->rows('SELECT * FROM `' . $table . '` WHERE id = ?', [$id]);
        $this->assertCount(1, $rows, "fixture row {$table}#{$id} is gone");

        return $rows[0];
    }

    /** @return array<string, mixed> */
    private function mediaAt(string $path): array
    {
        $rows = $this->install()->rows('SELECT * FROM media WHERE path = ?', [$path]);
        $this->assertCount(1, $rows, "\"{$path}\" must be in the library exactly once");

        return $rows[0];
    }

    private function setting(string $key): ?string
    {
        $rows = $this->install()->rows('SELECT setting_value FROM site_settings WHERE setting_key = ?', [$key]);

        return $rows === [] ? null : (string) $rows[0]['setting_value'];
    }

    /**
     * Everything the adoption writes: the library itself and every
     * reference it can set.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private static function adoptionSnapshot(): array
    {
        $snapshot = [
            'media' => self::$install->rows('SELECT * FROM media ORDER BY id'),
            'settings' => self::$install->rows("SELECT setting_key, setting_value FROM site_settings WHERE setting_key LIKE '%media_id' ORDER BY setting_key"),
        ];

        foreach (self::ADOPTED as [$table, , $mediaColumn]) {
            $snapshot[$table] = self::$install->rows('SELECT id, `' . $mediaColumn . '` FROM `' . $table . '` ORDER BY id');
        }

        return $snapshot;
    }

    /** @param array<string, mixed> $values */
    private static function insert(string $table, array $values): int
    {
        $now = date('Y-m-d H:i:s');
        $values += ['created_at' => $now, 'updated_at' => $now];

        $pdo = self::$install->pdo();
        $pdo->prepare(
            'INSERT INTO `' . $table . '` (`' . implode('`, `', array_keys($values)) . '`)'
            . ' VALUES (' . implode(', ', array_fill(0, count($values), '?')) . ')'
        )->execute(array_values($values));

        return (int) $pdo->lastInsertId();
    }
}

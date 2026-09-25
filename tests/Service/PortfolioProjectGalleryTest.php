<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\PortfolioGalleryRepository;
use App\Repository\PortfolioItemImageRepository;
use App\Service\Media\MediaService;
use App\Service\Media\MediaUsageRegistry;
use App\Service\PortfolioGalleryContent;
use App\Service\PortfolioLocalization;
use App\Service\PortfolioProjectGallery;
use App\Service\PortfolioSeo;
use App\Service\PortfolioSlug;
use PHPUnit\Framework\TestCase;

/**
 * The model behind a Portfolio project page (Portfolio 2.0), against the test
 * database:
 *
 *   - the gallery: tokens resolved in order, never the main picture, never a
 *     double, never another item's photo; the relation replaced, a removed
 *     row handed back, a library item never touched;
 *   - the library knows both uses — main picture and gallery photo;
 *   - the project page reads its photos with layered alt text;
 *   - the slug: normalised, unique, and a rename only redirects a page that
 *     was and stays public;
 *   - its metadata: title, description fallback, canonical, share image.
 *
 * Everything made here is its own, marked zz-, and removed in tearDown().
 */
final class PortfolioProjectGalleryTest extends TestCase
{
    /** @var list<int> */
    private array $itemIds = [];

    /** @var list<int> */
    private array $mediaIds = [];

    /** @var list<int> */
    private array $redirectIds = [];

    protected function tearDown(): void
    {
        $gallery = new PortfolioGalleryRepository();
        foreach ($this->itemIds as $id) {
            $gallery->deleteItem($id);
        }

        $pdo = Database::connection();
        foreach ($this->mediaIds as $id) {
            $pdo->prepare('DELETE FROM media WHERE id = ?')->execute([$id]);
        }

        foreach (array_unique($this->redirectIds) as $id) {
            $pdo->prepare('DELETE FROM redirects WHERE id = ?')->execute([$id]);
        }

        $this->itemIds = [];
        $this->mediaIds = [];
        $this->redirectIds = [];
        MediaService::clearCache();
        PortfolioGalleryContent::clearCache();
    }

    public function testTokensResolveInOrderWithoutTheMainPictureDoublesOrForeignPhotos(): void
    {
        $main = $this->media();
        $a = $this->media();
        $b = $this->media();
        $itemId = $this->item($main);
        $otherId = $this->item($this->media());
        $images = new PortfolioItemImageRepository();

        $images->replaceForItem($otherId, [['media_id' => $a, 'image_path' => 'assets/media/zz-a.webp', 'thumbnail_path' => null]]);
        $foreign = (int) $images->findByPortfolioItemId($otherId)[0]['id'];

        $photos = PortfolioProjectGallery::resolve(
            ['media:' . $b, 'media:' . $main, 'photo:' . $foreign, 'media:' . $a, 'media:' . $b, 'rubbish', 'media:0', 'media:99999999', ['nested']],
            $images->findByPortfolioItemId($itemId),
            $main
        );

        $this->assertSame([$b, $a], array_column($photos, 'media_id'));
        $this->assertSame([], PortfolioProjectGallery::resolve('not a list', [], null));
    }

    public function testTheRelationIsReplacedAndARemovedRowIsHandedBack(): void
    {
        $a = $this->media();
        $b = $this->media();
        $itemId = $this->item($this->media());
        $images = new PortfolioItemImageRepository();

        $images->replaceForItem($itemId, PortfolioProjectGallery::resolve(['media:' . $a, 'media:' . $b], [], null));
        $rows = $images->findByPortfolioItemId($itemId);
        $this->assertSame([$a, $b], array_map(static fn (array $r): int => (int) $r['media_id'], $rows));

        // Reordered, and one taken off.
        $removed = $images->replaceForItem($itemId, PortfolioProjectGallery::resolve(['photo:' . $rows[1]['id']], $rows, null));
        $this->assertSame([$b], array_map(static fn (array $r): int => (int) $r['media_id'], $images->findByPortfolioItemId($itemId)));
        $this->assertSame([(int) $rows[0]['id']], array_map(static fn (array $r): int => (int) $r['id'], $removed));
        $this->assertSame($a, (int) $removed[0]['media_id'], 'the caller learns it was a library photo, whose file it must leave');

        MediaService::clearCache();
        $this->assertNotNull(MediaService::find($a), 'the library item itself stays');
    }

    public function testTheLibraryKnowsBothTheMainPictureAndAGalleryPhoto(): void
    {
        $main = $this->media();
        $photo = $this->media();
        $itemId = $this->item($main);
        (new PortfolioItemImageRepository())->replaceForItem($itemId, [['media_id' => $photo, 'image_path' => 'assets/media/zz-p.webp', 'thumbnail_path' => null]]);

        $usages = MediaUsageRegistry::usagesFor([$main, $photo]);

        $this->assertCount(1, $usages[$main] ?? []);
        $this->assertStringNotContainsString('(galerij)', $usages[$main][0]->label);
        $this->assertCount(1, $usages[$photo] ?? []);
        $this->assertStringEndsWith('(galerij)', $usages[$photo][0]->label);
        $this->assertSame('/admin/portfolio-item.php?id=' . $itemId, $usages[$photo][0]->editUrl);
    }

    public function testAProjectPageReadsItsPhotosWithLayeredAltText(): void
    {
        $withAlt = $this->media('ZZ bibliotheek-alt');
        $itemId = $this->item($this->media());
        $slug = 'zz-project-' . bin2hex(random_bytes(4));
        (new PortfolioGalleryRepository())->setItemProjectPage($itemId, true, $slug);

        $images = new PortfolioItemImageRepository();
        $images->replaceForItem($itemId, [['media_id' => $withAlt, 'image_path' => 'assets/media/zz-alt.webp', 'thumbnail_path' => 'assets/media/thumbs/zz-alt.webp']]);

        $project = PortfolioGalleryContent::itemForDetailPage($slug);
        $this->assertNotNull($project);
        $this->assertSame($slug, $project['slug']);
        $this->assertSame('ZZ bibliotheek-alt', $project['images'][0]['alt'], 'no alt of its own: the library one');
        $this->assertSame('assets/media/thumbs/zz-alt.webp', $project['images'][0]['thumbnail_path']);

        $photoId = (int) $images->findByPortfolioItemId($itemId)[0]['id'];
        PortfolioLocalization::images()->save($photoId, PortfolioLocalization::defaultLanguage(), [PortfolioLocalization::ALT => 'ZZ eigen alt']);
        PortfolioGalleryContent::clearCache();
        $this->assertSame('ZZ eigen alt', PortfolioGalleryContent::itemForDetailPage($slug)['images'][0]['alt'], 'its own wins');
    }

    public function testASlugIsNormalisedMadeUniqueAndRefusedWhenTaken(): void
    {
        $repository = new PortfolioGalleryRepository();
        $marker = bin2hex(random_bytes(3));
        $firstId = $this->item($this->media());
        $secondId = $this->item($this->media());

        $this->assertSame('zz-een-mooi-werk', PortfolioSlug::normalise('  ZZ Één Mooi Werk!! '));
        $this->assertSame(PortfolioSlug::FALLBACK, PortfolioSlug::suggest($repository, '!!!', $firstId));

        $slug = PortfolioSlug::suggest($repository, 'ZZ Werk ' . $marker, $firstId);
        $this->assertSame('zz-werk-' . $marker, $slug);
        $repository->setItemProjectPage($firstId, true, $slug);

        $this->assertSame('zz-werk-' . $marker . '-2', PortfolioSlug::suggest($repository, 'ZZ Werk ' . $marker, $secondId), 'another item gets -2');
        $this->assertSame($slug, PortfolioSlug::suggest($repository, 'ZZ Werk ' . $marker, $firstId), 'an item keeps its own');
        $this->assertNotNull(PortfolioSlug::problem($repository, $slug, $secondId));
        $this->assertNull(PortfolioSlug::problem($repository, $slug, $firstId));
        $this->assertNotNull(PortfolioSlug::problem($repository, '', $firstId));

        // The site's reserved words are not this namespace's: /portfolio/contact is a project.
        $this->assertNull(PortfolioSlug::problem($repository, 'contact', $firstId));
    }

    public function testOnlyARenameOfAPageThatWasAndStaysPublicIsRecorded(): void
    {
        $marker = bin2hex(random_bytes(3));
        $old = 'zz-oud-' . $marker;
        $new = 'zz-nieuw-' . $marker;

        $this->assertSame(0, PortfolioSlug::recordRename(false, $old, true, $new), 'never public');
        $this->assertSame(0, PortfolioSlug::recordRename(true, $old, false, $new), 'hidden in the same save');
        $this->assertSame(0, PortfolioSlug::recordRename(true, $old, true, $old), 'no change');
        $this->assertSame([], $this->redirectsFrom($old), 'none of those wrote a row');

        $languages = \App\Service\Language\SiteLanguages::activeCodes();
        $this->assertSame(count($languages), PortfolioSlug::recordRename(true, $old, true, $new), 'one redirect per active language');

        $rows = $this->redirectsFrom($old);
        $this->assertCount(count($languages), $rows);
        foreach ($rows as $row) {
            $this->assertSame('slug_change', $row['origin']);
            $this->assertStringEndsWith('/portfolio/' . $new, (string) $row['target_value']);
        }

        $this->assertTrue(PortfolioSlug::isPublic(true, true, 'x'));
        $this->assertFalse(PortfolioSlug::isPublic(true, true, ''));
        $this->assertFalse(PortfolioSlug::isPublic(true, false, 'x'));
        $this->assertFalse(PortfolioSlug::isPublic(false, true, 'x'));
    }

    public function testAProjectPagesMetadata(): void
    {
        $project = [
            'slug' => 'zz-meta',
            'title' => 'ZZ Snijplank',
            'subtitle' => '',
            'intro' => '<p>ZZ Een <strong>korte</strong> inleiding &amp; meer.</p>',
            'description' => '<p>ZZ Lang verhaal.</p>',
            'image_path' => 'assets/media/zz-meta.webp',
        ];

        $seo = PortfolioSeo::forProject($project);
        $this->assertStringStartsWith('ZZ Snijplank | Portfolio — ', $seo->title);
        $this->assertSame('ZZ Een korte inleiding & meer.', $seo->description, 'no short text: the intro, as plain text');
        $this->assertSame(PortfolioGalleryContent::canonicalUrlForSlug('zz-meta'), $seo->canonical);
        $this->assertStringContainsString('zz-meta.webp', (string) $seo->ogImageUrl);

        $this->assertSame('ZZ kort', PortfolioSeo::forProject(['subtitle' => 'ZZ kort'] + $project)->description, 'the short text first');
        $this->assertSame('ZZ Lang verhaal.', PortfolioSeo::forProject(['intro' => ''] + $project)->description, 'then the description');

        $versions = PortfolioSeo::alternates('zz-meta');
        $this->assertSame(\App\Service\Language\SiteLanguages::activeCodes(), array_keys($versions));
        $this->assertContains('/portfolio/zz-meta', $versions, 'the default language unprefixed');
    }

    /* ------------------------------------------------------------------ */

    /**
     * The redirect rows whose source is this old project address in any
     * language, removed again in tearDown().
     *
     * @return list<array<string, mixed>>
     */
    private function redirectsFrom(string $oldSlug): array
    {
        $stmt = Database::connection()->prepare("SELECT * FROM redirects WHERE source_path LIKE ? ORDER BY id");
        $stmt->execute(['%/portfolio/' . $oldSlug]);
        $rows = $stmt->fetchAll();
        foreach ($rows as $row) {
            $this->redirectIds[] = (int) $row['id'];
        }

        return $rows;
    }

    private function media(string $alt = ''): int
    {
        $marker = bin2hex(random_bytes(4));
        $pdo = Database::connection();
        $pdo->prepare(
            "INSERT INTO media (path, thumbnail_path, original_filename, display_name, mime_type, width, height, file_size, alt_text, checksum, created_at, updated_at)
             VALUES (?, NULL, 'zz.webp', ?, 'image/webp', 10, 10, 100, ?, NULL, NOW(), NOW())"
        )->execute(['assets/media/zz-gallery-' . $marker . '.webp', 'zz-' . $marker . '.webp', $alt]);
        $id = (int) $pdo->lastInsertId();
        $this->mediaIds[] = $id;

        return $id;
    }

    private function item(int $mediaId): int
    {
        $repository = new PortfolioGalleryRepository();
        $id = $repository->createItem((int) $repository->ensureCatalogue()['id'], [
            'media_id' => $mediaId,
            'image_path' => 'assets/media/zz-main-' . $mediaId . '.webp',
            'thumbnail_path' => null,
        ]);
        $this->itemIds[] = $id;
        PortfolioLocalization::saveItem($id, PortfolioLocalization::defaultLanguage(), [PortfolioLocalization::TITLE => 'ZZ Item ' . $id]);

        return $id;
    }
}

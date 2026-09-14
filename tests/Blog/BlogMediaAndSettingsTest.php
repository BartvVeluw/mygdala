<?php

declare(strict_types=1);

namespace Tests\Blog;

use App\Module\BlogModule;
use App\Module\ModuleRegistry;
use App\Repository\BlogPostRepository;
use App\Service\AdminPermissions;
use App\Service\Blog\BlogContent;
use App\Service\Blog\BlogPostStatus;
use App\Service\Blog\BlogSettings;
use App\Service\Blog\BlogSlug;
use App\Service\Media\MediaService;
use App\Service\Media\MediaUsageRegistry;
use App\Service\Media\VisibleMediaUsages;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestMediaUploader;

/**
 * The Blog's two connections to the rest of the CMS that are not routes: the
 * Media Library, and its own settings store.
 *
 * The media half is the one that protects an editor from themself — a
 * featured image that is still on a post must not be deletable — so it is
 * asserted against a REAL media item, uploaded through the real uploader with
 * the same seam Tests\Service\MediaLibraryTest uses.
 */
final class BlogMediaAndSettingsTest extends TestCase
{
    private const PREFIX = 'zz-blogmedia-';

    private BlogPostRepository $posts;
    private MediaService $media;

    /** @var list<int> */
    private array $createdPosts = [];
    /** @var list<int> */
    private array $createdMedia = [];
    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->posts = new BlogPostRepository();
        $this->media = new MediaService(null, new TestMediaUploader());
        ModuleRegistry::overrideForTests(['shop' => true, 'personalization' => true, 'blog' => true]);
    }

    protected function tearDown(): void
    {
        foreach ($this->createdPosts as $id) {
            $this->posts->delete($id);
        }
        // The posts are gone by now, so nothing reports these as in use and
        // the library's own delete() removes both the row and the file.
        foreach ($this->createdMedia as $id) {
            $this->media->delete($id);
        }
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        $this->createdPosts = $this->createdMedia = $this->tempFiles = [];

        BlogSettings::overrideForTests(null);
        ModuleRegistry::overrideForTests(null);
        MediaService::clearCache();
    }

    /* ------------------------------------------------------------------ */
    /* Media                                                               */
    /* ------------------------------------------------------------------ */

    public function testAFeaturedImageComesStraightFromTheLibraryIncludingItsAltTextAndSize(): void
    {
        $mediaId = $this->upload('blog-featured.png', 'Een gegraveerd paneel');
        $item = MediaService::find($mediaId);

        $postId = $this->createPost(['featured_media_id' => $mediaId]);
        $decorated = BlogContent::decorateForAdmin((array) $this->posts->find($postId));

        $this->assertTrue($decorated['has_image']);
        $this->assertSame('/' . $item->path, $decorated['image']['image_path']);
        $this->assertSame('Een gegraveerd paneel', $decorated['image']['alt_nl']);
        $this->assertSame('Een gegraveerd paneel', $decorated['image']['alt_en'], 'no EN override means the same text');
        $this->assertSame($item->width, $decorated['image']['width']);
        $this->assertSame($item->height, $decorated['image']['height']);
    }

    public function testAPostWithoutAFeaturedImageSaysSoRatherThanRenderingAnEmptyOne(): void
    {
        $decorated = BlogContent::decorateForAdmin((array) $this->posts->find($this->createPost()));

        $this->assertFalse($decorated['has_image']);
        $this->assertSame('', $decorated['image']['image_path']);
    }

    /** The wording the requirement asks for, and the link that goes with it. */
    public function testTheLibraryReportsAFeaturedImageAsBlogbericht(): void
    {
        $mediaId = $this->upload('blog-usage.png');
        $postId = $this->createPost(['featured_media_id' => $mediaId, 'title' => 'Testbericht met beeld']);

        $usages = MediaUsageRegistry::usagesFor([$mediaId])[$mediaId] ?? [];
        $labels = array_map(static fn ($usage): string => $usage->label, $usages);

        $this->assertContains('Blogbericht: Testbericht met beeld', $labels);
        $this->assertSame(
            '/admin/blog-post.php?id=' . $postId,
            $usages[array_search('Blogbericht: Testbericht met beeld', $labels, true)]->editUrl
        );
    }

    public function testAnImageThatIsStillOnAPostCannotBeDeleted(): void
    {
        $mediaId = $this->upload('blog-protected.png');
        $this->createPost(['featured_media_id' => $mediaId, 'title' => 'Testbericht beschermd beeld']);

        $result = $this->media->delete($mediaId);

        $this->assertFalse($result['deleted']);
        $this->assertSame('in_use', $result['reason']);
        $this->assertNotNull(MediaService::find($mediaId), 'and it is still there');
    }

    /** A post's own social image is a second, separately reported use. */
    public function testASocialImageIsReportedSeparatelyFromTheFeaturedOne(): void
    {
        $featured = $this->upload('blog-featured-two.png');
        $social = $this->upload('blog-social.png');

        $this->createPost([
            'featured_media_id' => $featured,
            'og_media_id' => $social,
            'title' => 'Testbericht met twee beelden',
        ]);

        $usages = MediaUsageRegistry::usagesFor([$featured, $social]);

        $this->assertContains(
            'Blogbericht: Testbericht met twee beelden',
            array_map(static fn ($u): string => $u->label, $usages[$featured])
        );
        $this->assertContains(
            'Deel-afbeelding van blogbericht: Testbericht met twee beelden',
            array_map(static fn ($u): string => $u->label, $usages[$social])
        );
    }

    /**
     * Which post uses an image is named only to whoever may open that post:
     * managing the library, or reading the post overview, does not name it.
     * The image stays undeletable for everybody.
     */
    public function testOnlyWhoeverMayEditPostsIsToldWhichPostUsesAnImage(): void
    {
        $mediaId = $this->upload('blog-reader.png');
        $this->createPost(['featured_media_id' => $mediaId, 'title' => 'Testbericht voor wie mag lezen']);

        $usages = MediaUsageRegistry::usagesFor([$mediaId])[$mediaId] ?? [];
        $this->assertCount(1, $usages);
        $this->assertSame(BlogModule::BLOG_MANAGE, $usages[0]->permission, 'the permission admin/blog-post.php demands');

        $reader = static fn (array $granted): \Closure => static fn (string $permission): bool => AdminPermissions::userHas(
            ['is_super_admin' => false, 'permissions' => AdminPermissions::expand($granted)],
            $permission
        );

        foreach ([[AdminPermissions::MEDIA_MANAGE], [AdminPermissions::MEDIA_MANAGE, BlogModule::BLOG_VIEW]] as $granted) {
            $told = VisibleMediaUsages::of($usages, $reader($granted));

            $this->assertSame([], $told->shown, implode(', ', $granted));
            $this->assertSame(1, $told->hidden, implode(', ', $granted));
            $this->assertStringNotContainsString('Testbericht voor wie mag lezen', $told->keptSentence('blog-reader.png'));
        }

        $editor = VisibleMediaUsages::of($usages, $reader([AdminPermissions::MEDIA_MANAGE, BlogModule::BLOG_MANAGE]));

        $this->assertSame(['Blogbericht: Testbericht voor wie mag lezen'], array_map(static fn ($u): string => $u->label, $editor->shown));
        $this->assertStringContainsString('Testbericht voor wie mag lezen', $editor->keptSentence('blog-reader.png'));

        $this->assertFalse($this->media->delete($mediaId)['deleted'], 'and nobody can delete it while the post shows it');
    }

    /** Deleting a post frees its image again; the FILE is never touched. */
    public function testDeletingAPostReleasesItsImageWithoutRemovingTheFile(): void
    {
        $mediaId = $this->upload('blog-released.png');
        $postId = $this->createPost(['featured_media_id' => $mediaId]);
        $item = MediaService::find($mediaId);

        $this->posts->delete($postId);
        $this->createdPosts = array_values(array_diff($this->createdPosts, [$postId]));

        $this->assertTrue($item->fileExists(), 'the file belongs to the library, not to the post');
        $this->assertSame([], MediaUsageRegistry::usagesFor([$mediaId])[$mediaId] ?? []);
    }

    /** A disabled module is not part of the running site, so it reports nothing. */
    public function testTheBlogReportsNoUsageWhileTheModuleIsOff(): void
    {
        $mediaId = $this->upload('blog-disabled-usage.png');
        $this->createPost(['featured_media_id' => $mediaId, 'title' => 'Testbericht module uit']);

        ModuleRegistry::overrideForTests(['shop' => true, 'personalization' => true, 'blog' => false]);

        $labels = array_map(
            static fn ($usage): string => $usage->label,
            MediaUsageRegistry::usagesFor([$mediaId])[$mediaId] ?? []
        );

        $this->assertNotContains('Blogbericht: Testbericht module uit', $labels);
    }

    /* ------------------------------------------------------------------ */
    /* Settings                                                            */
    /* ------------------------------------------------------------------ */

    public function testAFreshInstallationHasCoherentBlogDefaults(): void
    {
        BlogSettings::overrideForTests([]);

        $this->assertSame(BlogSettings::DEFAULT_TITLE, BlogSettings::title('nl'));
        $this->assertSame('', BlogSettings::intro('nl'));
        $this->assertSame(BlogSettings::DEFAULT_POSTS_PER_PAGE, BlogSettings::postsPerPage());
        $this->assertTrue(BlogSettings::showDate());
        $this->assertTrue(BlogSettings::showAuthor());
        $this->assertTrue(BlogSettings::relatedPostsEnabled());
        $this->assertTrue(BlogSettings::rssEnabled());
    }

    public function testASettingIsReadBackAndAnEmptyEnglishTitleFallsBack(): void
    {
        BlogSettings::overrideForTests([
            BlogSettings::TITLE => 'Werkplaatslogboek',
            BlogSettings::TITLE_EN => '',
            BlogSettings::INTRO => 'Wat er bij ons gebeurt.',
        ]);

        $this->assertSame('Werkplaatslogboek', BlogSettings::title('nl'));
        $this->assertSame('Werkplaatslogboek', BlogSettings::title('en'));
        $this->assertSame('Wat er bij ons gebeurt.', BlogSettings::intro('en'));
    }

    public function testAnImpossiblePageSizeFallsBackToTheDefault(): void
    {
        foreach (['0', '-5', '5000', 'onzin', ''] as $stored) {
            BlogSettings::overrideForTests([BlogSettings::POSTS_PER_PAGE => $stored]);

            $this->assertSame(
                BlogSettings::DEFAULT_POSTS_PER_PAGE,
                BlogSettings::postsPerPage(),
                'stored "' . $stored . '" must not make an endless page'
            );
        }
    }

    public function testOnlyAnExplicitOffSwitchesAnOptionalPieceOff(): void
    {
        BlogSettings::overrideForTests([BlogSettings::SHOW_AUTHOR => '0', BlogSettings::RSS_ENABLED => 'false']);
        $this->assertFalse(BlogSettings::showAuthor());
        $this->assertFalse(BlogSettings::rssEnabled());

        BlogSettings::overrideForTests([BlogSettings::SHOW_AUTHOR => 'onzin']);
        $this->assertTrue(BlogSettings::showAuthor(), 'an unreadable value must not remove something');
    }

    /** An author line is one question with one answer, asked in one place. */
    public function testTheAuthorIsHiddenWhenTheSettingSaysSo(): void
    {
        $post = (array) $this->posts->find($this->createPost(['author_name' => 'Testauteur']));

        BlogSettings::overrideForTests([BlogSettings::SHOW_AUTHOR => '1']);
        $this->assertSame('Testauteur', BlogContent::author($post));

        BlogSettings::overrideForTests([BlogSettings::SHOW_AUTHOR => '0']);
        $this->assertSame('', BlogContent::author($post));
    }

    public function testTheSettingsStoreIgnoresAKeyItDoesNotOwn(): void
    {
        $keys = BlogSettings::keys();

        $this->assertContains(BlogSettings::TITLE, $keys);
        $this->assertNotContains('site_name', $keys, 'Core identity settings are not the Blog\'s to write');
    }

    /* ------------------------------------------------------------------ */

    /**
     * A real upload through the real uploader, with the one seam
     * Tests\Support\TestMediaUploader opens — the same helper
     * Tests\Service\MediaLibraryTest uses, so this test cannot pass against
     * a pipeline the library itself would reject.
     */
    private function upload(string $filename, string $altText = 'Testafbeelding'): int
    {
        $image = imagecreatetruecolor(120, 90);
        imagefilledrectangle($image, 0, 0, 120, 90, imagecolorallocate($image, 40, 90, 160));
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        $path = sys_get_temp_dir() . '/blog-media-' . bin2hex(random_bytes(8)) . '-' . $filename;
        file_put_contents($path, $bytes);
        $this->tempFiles[] = $path;

        $result = $this->media->upload([
            'name' => $filename,
            'tmp_name' => $path,
            'error' => UPLOAD_ERR_OK,
            'size' => strlen($bytes),
        ], $altText);

        $id = (int) $result['item']->id;
        $this->createdMedia[] = $id;

        return $id;
    }

    /** @param array<string, mixed> $values */
    private function createPost(array $values = []): int
    {
        $title = (string) ($values['title'] ?? 'Testbericht media');
        $values['title'] = $title;
        $values['slug'] = BlogSlug::unique(
            self::PREFIX . BlogSlug::sanitize($title),
            $title,
            fn (string $candidate): bool => $this->posts->slugExists($candidate, null)
        );
        $values['status'] ??= BlogPostStatus::DRAFT;

        $id = $this->posts->create($values);
        $this->createdPosts[] = $id;

        return $id;
    }
}

<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\MediaBannerRepository;
use App\Repository\MediaRepository;
use App\Repository\PageRepository;
use App\Repository\PageSectionRepository;
use App\Service\AdminPermissions;
use App\Service\Media\ImageFocus;
use App\Service\Media\MediaService;
use App\Service\Media\MediaType;
use App\Service\Media\MediaUploader;
use App\Service\Media\MediaUsageRegistry;
use App\Service\MediaBannerContent;
use App\Service\PageContent;
use App\Service\PageService;
use App\Service\SectionRegistry;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestSession;
use Tests\Support\BuiltInServer;
use Tests\Support\PageFixture;

require_once dirname(__DIR__, 2) . '/partials/section-media-banner.php';

/**
 * Mediabanner through the real editor and endpoint, over PHP's built-in
 * server, and what MediaBannerContent then gives the page:
 *
 *   - the four guards and the `<page>:<key>` gate;
 *   - a picture or a video from the Media Library is chosen, replaced and
 *     removed; an unknown id, a document or a file no kind claims is refused;
 *   - the width and the height come from closed lists, the picture's
 *     presentation (Responsive Media 2.0: focus point, fit, a phone's own
 *     height) from numbers and closed lists; an unknown value is refused at
 *     its field, and a form without the field keeps what is stored;
 *   - the video options: switches are "1" or absent, a video without
 *     autoplay needs controls, a poster is a picture; a picture never stores
 *     posted video options or a poster, a video never stores a focus point;
 *   - both library references are usages ("Mediabanner op …") and protected
 *     from deletion, by the library and by the foreign key;
 *   - the picker behind the field lists and uploads pictures and videos
 *     (MediaType::VISUAL) and nothing else;
 *   - the editor: one picker for both kinds, what a kind does not need hidden.
 *
 * The page, its banners, the accounts and the library items (and any file an
 * upload wrote) are this test's own and are removed in tearDown(). Without a
 * server the test skips itself.
 */
final class MediaBannerHttpTest extends TestCase
{
    private const KEY = 'zz-media-banner-test';
    private const ENDPOINT = '/api/admin/update-media-banner.php';

    private static ?BuiltInServer $server = null;

    private AdminTestSession $accounts;

    /** @var list<int> */
    private array $mediaIds = [];

    /** @var list<string> */
    private array $tempFiles = [];

    public static function setUpBeforeClass(): void
    {
        self::$server = BuiltInServer::start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        self::$server = null;
    }

    protected function setUp(): void
    {
        $this->accounts = new AdminTestSession();

        if (self::$server === null || !self::$server->answers()) {
            $this->markTestSkipped("could not start PHP's built-in web server for this test");
        }

        $this->removePage();
        PageFixture::create(['content_key' => self::KEY, 'slug' => self::KEY, 'status' => PageContent::STATUS_PUBLISHED], 'Mediabanner-test');
    }

    protected function tearDown(): void
    {
        $this->removePage();

        MediaService::clearCache();
        foreach ($this->mediaIds as $id) {
            $item = MediaService::find($id);
            if ($item !== null && str_contains($item->path, 'assets/media/') && !str_contains($item->path, '__mb_')) {
                (new MediaUploader())->deleteFile($item->path);
                (new MediaUploader())->deleteFile($item->thumbnailPath);
            }
            (new MediaRepository())->delete($id);
        }
        $this->mediaIds = [];
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }

        $this->accounts->forget();
        $this->clearCaches();
    }

    // ------------------------------------------------------------- guards

    public function testTheFourGuardsAndTheSectionGate(): void
    {
        [$section] = $this->place();
        [$session, $csrf] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);

        self::assertSame(401, self::$server->request('POST', self::ENDPOINT, null, ['section' => $section])['status'], 'not signed in');

        [$other, $otherCsrf] = $this->accounts->signIn([AdminPermissions::SETTINGS_MANAGE]);
        self::assertSame(403, self::$server->request('POST', self::ENDPOINT, $other, ['section' => $section, 'csrf_token' => $otherCsrf])['status'], 'no pages.manage');

        self::assertSame(405, self::$server->request('GET', self::ENDPOINT . '?section=' . urlencode($section), $session)['status']);
        self::assertSame(403, self::$server->request('POST', self::ENDPOINT, $session, ['section' => $section, 'csrf_token' => 'wrong'])['status']);

        foreach ([self::KEY . ':custom-nothere', 'no-such-page:custom-x', self::KEY, ''] as $unknown) {
            self::assertSame(404, self::$server->request('POST', self::ENDPOINT, $session, ['section' => $unknown, 'csrf_token' => $csrf])['status'], $unknown);
        }
        self::assertSame(404, self::$server->request('GET', '/admin/media-banner.php?section=' . urlencode(self::KEY . ':custom-nothere'), $session)['status']);
    }

    // -------------------------------------------------------------- media

    public function testANewBannerStartsEmptyAndShowsNothing(): void
    {
        [$section] = $this->place();
        $row = $this->row($section);

        self::assertSame(
            [null, 'content', 'medium', 50, 50, 0, 0, 1, null, 1],
            [$row['media_id'], $row['width'], $row['height'], (int) $row['image_focus_x'], (int) $row['image_focus_y'], (int) $row['video_autoplay'], (int) $row['video_loop'], (int) $row['video_controls'], $row['poster_media_id'], (int) $row['is_active']]
        );
        self::assertSame('', $this->content($section)['kind']);
        self::assertSame('', trim($this->render($this->content($section))));
    }

    public function testAPictureIsChosenReplacedAndRemoved(): void
    {
        [$section] = $this->place();
        $session = $this->signIn();
        $first = $this->libraryItem('image/jpeg', 'jpg', 'Zicht op de werkplaats');
        $second = $this->libraryItem('image/webp', 'webp');

        $this->assertSaved($this->save($session, $section, ['media_id' => (string) $first]));
        self::assertSame($first, (int) $this->row($section)['media_id']);
        $content = $this->content($section);
        self::assertSame([MediaType::IMAGE, MediaService::find($first)?->publicPath(), 'Zicht op de werkplaats'], [$content['kind'], $content['src'], $content['alt']]);
        self::assertStringContainsString('alt="Zicht op de werkplaats"', $this->render($content), 'the library\'s alt text');

        $this->assertSaved($this->save($session, $section, ['media_id' => (string) $second]));
        self::assertSame($second, (int) $this->row($section)['media_id'], 'replaced');

        $this->assertSaved($this->save($session, $section, ['media_id' => '']));
        self::assertNull($this->row($section)['media_id'], 'removed');
        self::assertSame('', trim($this->render($this->content($section))), 'and nothing shows');
    }

    public function testAVideoIsChosenWithItsOptions(): void
    {
        [$section] = $this->place();
        $session = $this->signIn();
        $video = $this->libraryItem('video/mp4', 'mp4');
        $poster = $this->libraryItem('image/jpeg', 'jpg');

        $this->assertSaved($this->post($session, [
            'media_id' => (string) $video, 'video_autoplay' => '1', 'video_loop' => '1', 'poster_media_id' => (string) $poster,
        ] + $this->without('video_controls', $section)));

        $row = $this->row($section);
        self::assertSame([1, 1, 0, $poster], [(int) $row['video_autoplay'], (int) $row['video_loop'], (int) $row['video_controls'], (int) $row['poster_media_id']]);

        $html = $this->render($this->content($section));
        self::assertStringContainsString('<video class="media-banner__media" src="' . MediaService::find($video)?->publicPath() . '" poster="' . MediaService::find($poster)?->publicPath() . '" playsinline preload="metadata" autoplay muted data-media-banner-autoplay loop aria-hidden="true"></video>', $html);

        $this->assertSaved($this->save($session, $section, ['media_id' => (string) $video]), 'controls on, no autoplay');
        self::assertStringContainsString('playsinline preload="metadata" controls>', $this->render($this->content($section)));
        self::assertNull($this->row($section)['poster_media_id'], 'the poster was let go of');
    }

    public function testAnythingButAPictureOrAVideoIsRefused(): void
    {
        [$section] = $this->place();
        $session = $this->signIn();
        $document = $this->libraryItem('application/pdf', 'pdf');
        $unknown = $this->libraryItem('', 'bin');
        $audio = $this->libraryItem('audio/mpeg', 'mp3');

        foreach (['999999999' => 'unknown id', (string) $document => 'a document', (string) $unknown => 'no kind', (string) $audio => 'audio', 'abc' => 'not a number', '-5' => 'negative'] as $posted => $what) {
            $response = $this->save($session, $section, ['media_id' => $posted]);
            $this->assertRefused($response, $what);
            self::assertArrayHasKey('media_id', (array) $this->accounts->read($session, 'admin_media_banner_field_errors'), $what . ': the message is at its field');
            self::assertNull($this->row($section)['media_id'], $what);
        }

        $this->assertRefused($this->post($session, ['media_id' => ['1']] + $this->fields($section)), 'an array');
    }

    // ------------------------------------------------------------- layout

    public function testEveryWidthAndHeightIsStoredFromItsList(): void
    {
        [$section] = $this->place();
        $session = $this->signIn();

        foreach (MediaBannerContent::WIDTHS as $width) {
            foreach (MediaBannerContent::HEIGHTS as $height) {
                $this->assertSaved($this->save($session, $section, ['width' => $width, 'height' => $height]), $width . '/' . $height);
                self::assertSame([$width, $height], [$this->row($section)['width'], $this->row($section)['height']]);
            }
        }
    }

    public function testAnUnknownWordIsRefusedAtItsFieldAndNothingIsStored(): void
    {
        [$section] = $this->place();
        $session = $this->signIn();
        $picture = $this->libraryItem('image/jpeg', 'jpg');
        $this->assertSaved($this->save($session, $section, ['media_id' => (string) $picture]));
        $before = $this->row($section);

        foreach ([
            'width' => ['100vw', 'width'],
            'height' => ['500px', 'height'],
            // Responsive Media 2.0: a point is two numbers, and a refusal is
            // the presentation's own.
            'image_focus_x' => ['10% 20%', 'presentation.focus'],
            'image_fit' => ['stretch', 'presentation.fit'],
            'image_mobile_height' => ['9999px', 'presentation.mobile_height'],
        ] as $field => [$value, $errorKey]) {
            $response = $this->save($session, $section, ['media_id' => (string) $picture, $field => $value]);
            $this->assertRefused($response, $field);
            self::assertArrayHasKey($errorKey, (array) $this->accounts->read($session, 'admin_media_banner_field_errors'), $field . ': the message is at its field');
            self::assertSame($before, $this->row($section), $field . ': nothing stored');
        }

        $this->assertRefused($this->post($session, ['height' => ['large']] + $this->fields($section)), 'an array');
        self::assertSame($before, $this->row($section));
    }

    public function testAFormWithoutAChoiceKeepsWhatIsStored(): void
    {
        [$section] = $this->place();
        $session = $this->signIn();
        $picture = $this->libraryItem('image/jpeg', 'jpg');

        $this->assertSaved($this->save($session, $section, ['media_id' => (string) $picture, 'width' => 'full', 'height' => 'xlarge', 'image_focus_x' => '100', 'image_focus_y' => '100']));
        $fields = $this->fields($section);
        unset($fields['width'], $fields['height'], $fields['image_focus_x'], $fields['image_focus_y'], $fields['image_presentation'], $fields['image_mobile_source']);
        $this->assertSaved($this->post($session, ['media_id' => (string) $picture] + $fields));

        $row = $this->row($section);
        self::assertSame(['full', 'xlarge', 100, 100], [$row['width'], $row['height'], (int) $row['image_focus_x'], (int) $row['image_focus_y']]);
    }

    // -------------------------------------------------------------- video

    public function testSwitchesAreOnOrOffAndNothingElse(): void
    {
        [$section] = $this->place();
        $session = $this->signIn();
        $video = $this->libraryItem('video/mp4', 'mp4');
        $this->assertSaved($this->save($session, $section, ['media_id' => (string) $video]));
        $before = $this->row($section);

        foreach (['video_autoplay' => 'yes', 'video_loop' => 'true', 'video_controls' => '2'] as $field => $value) {
            $this->assertRefused($this->save($session, $section, ['media_id' => (string) $video, $field => $value]), $field);
            self::assertArrayHasKey($field, (array) $this->accounts->read($session, 'admin_media_banner_field_errors'), $field);
            self::assertSame($before, $this->row($section), $field . ': nothing stored');
        }
        $this->assertRefused($this->post($session, ['media_id' => (string) $video, 'video_autoplay' => ['1']] + $this->fields($section)), 'an array');
    }

    public function testAVideoThatDoesNotPlayByItselfNeedsControls(): void
    {
        [$section] = $this->place();
        $session = $this->signIn();
        $video = $this->libraryItem('video/webm', 'webm');

        $this->assertRefused($this->post($session, ['media_id' => (string) $video] + $this->without('video_controls', $section)), 'no autoplay, no controls');
        self::assertArrayHasKey('video_controls', (array) $this->accounts->read($session, 'admin_media_banner_field_errors'));
        self::assertNull($this->row($section)['media_id'], 'nothing stored');

        $this->assertSaved($this->post($session, ['media_id' => (string) $video, 'video_autoplay' => '1'] + $this->without('video_controls', $section)), 'autoplay without controls is a decoration');
        self::assertSame([1, 0], [(int) $this->row($section)['video_autoplay'], (int) $this->row($section)['video_controls']]);
    }

    public function testAPosterIsAPicture(): void
    {
        [$section] = $this->place();
        $session = $this->signIn();
        $video = $this->libraryItem('video/mp4', 'mp4');
        $otherVideo = $this->libraryItem('video/mp4', 'mp4');
        $document = $this->libraryItem('application/pdf', 'pdf');

        foreach ([(string) $otherVideo => 'a video', (string) $document => 'a document', '999999999' => 'unknown'] as $posted => $what) {
            $this->assertRefused($this->save($session, $section, ['media_id' => (string) $video, 'poster_media_id' => $posted]), $what);
            self::assertArrayHasKey('poster_media_id', (array) $this->accounts->read($session, 'admin_media_banner_field_errors'), $what);
            self::assertNull($this->row($section)['media_id'], $what . ': nothing stored');
        }
    }

    public function testAPictureStoresNoVideoOptionsAndAVideoNoFocusPoint(): void
    {
        [$section] = $this->place();
        $session = $this->signIn();
        $video = $this->libraryItem('video/mp4', 'mp4');
        $picture = $this->libraryItem('image/jpeg', 'jpg');
        $poster = $this->libraryItem('image/png', 'png');

        $this->assertSaved($this->save($session, $section, ['media_id' => (string) $picture, 'image_focus_x' => '50', 'image_focus_y' => '0']));
        $point = fn (): array => [(int) $this->row($section)['image_focus_x'], (int) $this->row($section)['image_focus_y']];

        // A video posted with a focus point: the stored point stays.
        $this->assertSaved($this->save($session, $section, [
            'media_id' => (string) $video, 'image_focus_x' => '50', 'image_focus_y' => '100', 'video_autoplay' => '1', 'video_loop' => '1', 'poster_media_id' => (string) $poster,
        ]));
        self::assertSame([50, 0], $point());

        // A picture posted with other video options (autoplay and loop off)
        // and a poster: none of it is stored, the stored options stay, and
        // the poster goes.
        $this->assertSaved($this->save($session, $section, [
            'media_id' => (string) $picture, 'image_focus_x' => '0', 'image_focus_y' => '50', 'poster_media_id' => (string) $poster,
        ]));
        $row = $this->row($section);
        self::assertSame([0, 50, 1, 1, 1, null], [(int) $row['image_focus_x'], (int) $row['image_focus_y'], (int) $row['video_autoplay'], (int) $row['video_loop'], (int) $row['video_controls'], $row['poster_media_id']]);

        $content = $this->content($section);
        self::assertSame([false, false, false, ''], [$content['autoplay'], $content['loop'], $content['controls'], $content['poster']], 'and none of it reaches the page');
        self::assertStringNotContainsString('<video', $this->render($content));

        // An invalid switch next to a picture is still refused.
        $this->assertRefused($this->save($session, $section, ['media_id' => (string) $picture, 'video_loop' => 'on']));
    }

    // -------------------------------------------------------------- usage

    public function testBothReferencesAreUsagesAndCannotBeDeleted(): void
    {
        [$section] = $this->place();
        $session = $this->signIn();
        $video = $this->libraryItem('video/mp4', 'mp4');
        $poster = $this->libraryItem('image/jpeg', 'jpg');

        $this->assertSaved($this->save($session, $section, ['media_id' => (string) $video, 'poster_media_id' => (string) $poster]));

        $service = new MediaService(new MediaRepository());
        foreach ([$video => 'Mediabanner op "' . self::KEY . '"', $poster => 'Mediabanner (poster) op "' . self::KEY . '"'] as $id => $label) {
            $usages = $service->usagesOf($id);
            self::assertCount(1, $usages, $label);
            self::assertSame($label, $usages[0]->label);
            self::assertSame('/admin/media-banner.php?section=' . rawurlencode($section), $usages[0]->editUrl);
            self::assertSame([$id => 1], MediaUsageRegistry::countsFor([$id]));

            $refused = $service->delete($id);
            self::assertFalse($refused['deleted'], $label);
            self::assertSame('in_use', $refused['reason']);
        }

        // The foreign keys are the second line of defence.
        foreach ([$video, $poster] as $id) {
            try {
                Database::connection()->prepare('DELETE FROM media WHERE id = ?')->execute([$id]);
                self::fail('the foreign key let a used item go: ' . $id);
            } catch (\PDOException $e) {
                self::assertStringContainsString('foreign key', strtolower($e->getMessage()));
            }
        }
    }

    public function testAPictureUsageAndAnItemLetGoOfIsDeletableAgain(): void
    {
        [$section] = $this->place();
        $session = $this->signIn();
        $picture = $this->libraryItem('image/jpeg', 'jpg');

        $this->assertSaved($this->save($session, $section, ['media_id' => (string) $picture]));
        self::assertSame('Mediabanner op "' . self::KEY . '"', (new MediaService(new MediaRepository()))->usagesOf($picture)[0]->label);

        $this->assertSaved($this->save($session, $section, ['media_id' => '']));
        MediaService::clearCache();
        self::assertSame([], (new MediaService(new MediaRepository()))->usagesOf($picture));
    }

    // ------------------------------------------------------------- picker

    public function testThePickerListsPicturesAndVideosAndNothingElse(): void
    {
        [$session] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE, AdminPermissions::MEDIA_VIEW]);
        $marker = 'zzmbpick' . bin2hex(random_bytes(3));
        $ids = [];
        foreach (['jpg' => 'image/jpeg', 'svg' => 'image/svg+xml', 'mp4' => 'video/mp4', 'webm' => 'video/webm', 'pdf' => 'application/pdf', 'bin' => ''] as $extension => $mime) {
            $ids[$extension] = $this->libraryItem($mime, $extension, '', $marker . '-' . $extension);
        }

        $listed = static function (array $response): array {
            self::assertSame(200, $response['status'], $response['body']);

            return array_map(static fn (array $item): int => (int) $item['id'], (array) json_decode($response['body'], true)['items']);
        };

        $visual = $listed(self::$server->request('GET', '/api/admin/media-list.php?type=visual&q=' . $marker, $session));
        sort($visual);
        $expected = [$ids['jpg'], $ids['svg'], $ids['mp4'], $ids['webm']];
        sort($expected);
        self::assertSame($expected, $visual);

        $images = $listed(self::$server->request('GET', '/api/admin/media-list.php?type=image&q=' . $marker, $session));
        sort($images);
        self::assertSame([$ids['jpg'], $ids['svg']], $images, 'an image field still lists only images');
    }

    public function testThePickerUploadsAPictureOrAVideoForTheBanner(): void
    {
        [$session, $csrf] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE, AdminPermissions::MEDIA_VIEW]);

        $png = $this->tempFile('zz-banner.png', $this->pngBytes());
        $mp4 = $this->tempFile('zz-banner.mp4', "\x00\x00\x00\x18ftypisom\x00\x00\x02\x00isomiso2" . str_repeat("\x00", 64));
        $pdf = $this->tempFile('zz-banner.pdf', "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n");

        foreach ([[$png, 'image/png', 'zz-banner.png', MediaType::IMAGE], [$mp4, 'video/mp4', 'zz-banner.mp4', MediaType::VIDEO]] as [$path, $type, $name, $kind]) {
            $response = self::$server->request('POST', '/api/admin/media-upload.php', $session, ['csrf_token' => $csrf, 'kind' => MediaType::VISUAL], ['file' => new \CURLFile($path, $type, $name)]);
            self::assertSame(200, $response['status'], $name . ': ' . $response['body']);
            $item = (array) json_decode($response['body'], true)['item'];
            $this->mediaIds[] = (int) $item['id'];
            self::assertSame($kind, $item['kind'], $name);
        }

        $refused = self::$server->request('POST', '/api/admin/media-upload.php', $session, ['csrf_token' => $csrf, 'kind' => MediaType::VISUAL], ['file' => new \CURLFile($pdf, 'application/pdf', 'zz-banner.pdf')]);
        self::assertSame(422, $refused['status'], 'a document is no media for a banner');
    }

    // ------------------------------------------------------------- editor

    public function testTheEditorHasOnePickerForBothKindsAndShowsWhatTheKindNeeds(): void
    {
        [$section] = $this->place();
        $session = $this->signIn();
        $url = '/admin/media-banner.php?section=' . urlencode($section);

        $xpath = $this->xpath(self::$server->request('GET', $url, $session)['body']);
        self::assertSame(4, $xpath->query('//form[@data-media-banner-form]/section[contains(@class, "admin-card")]/h2')->length, 'Media, Weergave, Afspelen, Diavoorstelling');
        self::assertSame(0, $xpath->query('//input[@type="file" and not(ancestor::*[@data-media-modal])]')->length, 'no upload field of its own');
        self::assertSame(1, $xpath->query('//*[@data-media-picker and @data-media-picker-kind="visual"]//input[@name="media_id"]')->length, 'one picker for a picture or a video');
        self::assertSame(1, $xpath->query('//*[@data-media-picker and @data-media-picker-kind="image"]//input[@name="poster_media_id"]')->length, 'the poster is a picture');
        self::assertSame(0, $xpath->query('//select[contains(@name, "type") or contains(@name, "kind")]')->length, 'no image/video switch');
        foreach (['width' => MediaBannerContent::WIDTHS, 'height' => MediaBannerContent::HEIGHTS] as $name => $list) {
            $values = [];
            foreach ($xpath->query('//input[@type="radio" and @name="' . $name . '"]') as $radio) {
                $values[] = $radio->getAttribute('value');
            }
            self::assertSame($list, $values, $name . ': exactly the closed list');
        }
        self::assertSame(count(ImageFocus::keys()), $xpath->query('//*[@data-rm-focus="desktop"]//button[@data-rm-preset]')->length, 'the nine one-click points');
        self::assertSame(1, $xpath->query('//input[@type="range" and @name="image_focus_x"]')->length, 'and the point itself');
        self::assertSame(0, $xpath->query('//*[@data-media-banner-needs and not(@hidden)]')->length, 'nothing chosen: no focus point, no playing options, no further items');

        $picture = $this->libraryItem('image/jpeg', 'jpg');
        $this->assertSaved($this->save($session, $section, ['media_id' => (string) $picture]));
        $xpath = $this->xpath(self::$server->request('GET', $url, $session)['body']);
        self::assertSame(1, $xpath->query('//*[@data-media-banner-needs="image" and not(@hidden)]')->length);
        self::assertSame(0, $xpath->query('//*[@data-media-banner-needs="video" and not(@hidden)]')->length);
        self::assertSame(1, $xpath->query('//*[@data-media-banner-needs="play" and @hidden]')->length, 'one picture does not play');
        self::assertSame(1, $xpath->query('//*[@data-media-banner-needs="main" and not(@hidden)]')->length, 'more items can follow the first');
        self::assertSame('Afbeelding', trim((string) $xpath->query('//*[@data-media-picker-kind="visual"]//*[contains(@class, "admin-media-picker__kind")]')->item(0)?->textContent));
        self::assertSame('image', $xpath->query('//*[@data-media-picker-kind="visual"]')->item(0)?->getAttribute('data-media-picker-chosen'));

        $video = $this->libraryItem('video/mp4', 'mp4');
        $this->assertSaved($this->save($session, $section, ['media_id' => (string) $video]));
        $xpath = $this->xpath(self::$server->request('GET', $url, $session)['body']);
        self::assertSame(1, $xpath->query('//*[@data-media-banner-needs="image" and @hidden]')->length);
        self::assertSame(0, $xpath->query('//*[@data-media-banner-needs="video" and @hidden]')->length);
        self::assertSame(0, $xpath->query('//*[@data-media-banner-needs="play" and @hidden]')->length);
        self::assertSame(1, $xpath->query('//*[@data-media-banner-needs="main-video" and not(@hidden)]')->length, 'the poster belongs to the first video');
        self::assertSame(1, $xpath->query('//*[@data-media-banner-needs="sequence" and @hidden]')->length, 'one item is no sequence');
        self::assertSame('Video', trim((string) $xpath->query('//*[@data-media-picker-kind="visual"]//*[contains(@class, "admin-media-picker__kind")]')->item(0)?->textContent));
        foreach (['video_autoplay', 'video_loop', 'video_controls'] as $name) {
            self::assertSame(1, $xpath->query('//input[@type="checkbox" and @role="switch" and @name="' . $name . '" and @value="1"]')->length, $name);
        }
    }

    public function testARefusedSaveHandsEverythingBack(): void
    {
        [$section] = $this->place();
        $session = $this->signIn();
        $video = $this->libraryItem('video/mp4', 'mp4');

        $this->assertRefused($this->post($session, ['media_id' => (string) $video, 'width' => 'full', 'height' => 'large', 'video_loop' => '1'] + $this->without('video_controls', $section)));
        $xpath = $this->xpath(self::$server->request('GET', '/admin/media-banner.php?section=' . urlencode($section), $session)['body']);

        self::assertSame('full', $xpath->query('//input[@name="width" and @checked]')->item(0)?->getAttribute('value'));
        self::assertSame('large', $xpath->query('//input[@name="height" and @checked]')->item(0)?->getAttribute('value'));
        self::assertSame((string) $video, $xpath->query('//input[@name="media_id"]')->item(0)?->getAttribute('value'));
        self::assertSame(1, $xpath->query('//input[@name="video_loop" and @checked]')->length);
        self::assertSame(0, $xpath->query('//input[@name="video_controls" and @checked]')->length);
        self::assertSame(1, $xpath->query('//input[@name="video_controls" and @aria-invalid="true"]')->length, 'the message is at the switch');
        self::assertSame(1, $xpath->query('//form[@data-save-bar-unsaved]')->length);
    }

    // ------------------------------------------------------------ helpers

    /** @return array{string, int} the section parameter and the banner id */
    private function place(): array
    {
        [$id, $key] = SectionRegistry::create('media_banner', self::KEY);
        $page = (new PageRepository())->findByContentKey(self::KEY);
        self::assertNotNull($page);
        (new PageSectionRepository())->create((int) $page['id'], self::KEY, 'media_banner', $key, $id);
        $this->clearCaches();

        return [self::KEY . ':' . $key, (int) $id];
    }

    /** @return array<string, mixed> a complete form as the editor posts it: nothing chosen, every choice at its default */
    private function fields(string $section): array
    {
        return [
            'section' => $section,
            'media_id' => '',
            'width' => 'content',
            'height' => 'medium',
            'image_presentation' => '1',
            'image_focus_x' => '50',
            'image_focus_y' => '50',
            'image_mobile_source' => 'desktop',
            'image_fit' => 'cover',
            'image_mobile_fit' => '',
            'image_mobile_height' => '',
            'video_controls' => '1',
            'poster_media_id' => '',
        ];
    }

    /**
     * The default form without one field — how a browser posts a switch that
     * is off. For post(), which adds nothing back.
     *
     * @return array<string, mixed>
     */
    private function without(string $field, string $section): array
    {
        $fields = $this->fields($section);
        unset($fields[$field]);

        return $fields;
    }

    /** @param array<string, mixed> $changes */
    private function save(string $session, string $section, array $changes): array
    {
        return $this->post($session, $changes + $this->fields($section));
    }

    /**
     * @param array<string, mixed> $fields
     * @return array{status: int, location: string, body: string, headers: string}
     */
    private function post(string $session, array $fields): array
    {
        $response = self::$server->request('POST', self::ENDPOINT, $session, $fields + [
            'csrf_token' => (string) $this->accounts->read($session, 'csrf_token'),
        ]);
        $this->clearCaches();

        return $response;
    }

    /** @return array<string, mixed> */
    private function row(string $section): array
    {
        [, $key] = explode(':', $section, 2);
        $row = (new MediaBannerRepository())->findBySlugAndKey(self::KEY, $key);
        self::assertNotNull($row);
        unset($row['updated_at'], $row['created_at']);

        return $row;
    }

    /** @return array<string, mixed> */
    private function content(string $section): array
    {
        [, $key] = explode(':', $section, 2);
        $this->clearCaches();

        return MediaBannerContent::forSection(self::KEY, $key);
    }

    private function signIn(): string
    {
        [$session] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);

        return $session;
    }

    /** A media row for a file that does not exist: neither the form nor the save reads the disk. */
    private function libraryItem(string $mimeType, string $extension, string $alt = '', string $name = ''): int
    {
        $id = (new MediaRepository())->create([
            'path' => 'assets/media/__mb_' . bin2hex(random_bytes(4)) . '__.' . $extension,
            'original_filename' => ($name !== '' ? $name : 'banner') . '.' . $extension,
            'display_name' => $name !== '' ? $name . '.' . $extension : '',
            'mime_type' => $mimeType,
            'alt_text' => $alt,
        ]);
        $this->mediaIds[] = $id;
        MediaService::clearCache();

        return $id;
    }

    private function tempFile(string $name, string $bytes): string
    {
        $path = sys_get_temp_dir() . '/' . bin2hex(random_bytes(6)) . '-' . $name;
        file_put_contents($path, $bytes);
        $this->tempFiles[] = $path;

        return $path;
    }

    private function pngBytes(): string
    {
        $image = imagecreatetruecolor(8, 4);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 30, 90, 150));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    /** @param array<string, mixed> $content */
    private function render(array $content): string
    {
        ob_start();
        try {
            render_section_media_banner($content);
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }

    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new \DOMXPath($document);
    }

    private function clearCaches(): void
    {
        MediaBannerContent::clearCache();
        MediaService::clearCache();
        PageContent::clearCache();
    }

    /** @param array{location: string} $response */
    private function assertSaved(array $response, string $what = ''): void
    {
        self::assertStringContainsString('saved=1', $response['location'], $what . ' ' . $response['body']);
    }

    /** @param array{location: string} $response */
    private function assertRefused(array $response, string $what = ''): void
    {
        self::assertStringNotContainsString('saved=1', $response['location'], $what);
        self::assertStringStartsWith('/admin/media-banner.php', $response['location'], $what . ': back to the editor');
    }

    private function removePage(): void
    {
        $page = (new PageRepository())->findByContentKey(self::KEY);
        if ($page !== null) {
            PageService::delete($page);
        }

        PageContent::clearCache();
    }
}

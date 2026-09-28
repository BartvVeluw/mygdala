<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Repository\MediaBannerRepository;
use App\Repository\MediaRepository;
use App\Repository\PageHeroRepository;
use App\Repository\PageRepository;
use App\Repository\PageSectionRepository;
use App\Service\AdminPermissions;
use App\Service\Breadcrumbs\BreadcrumbItem;
use App\Service\Breadcrumbs\BreadcrumbTrail;
use App\Service\Media\MediaService;
use App\Service\Media\MediaType;
use App\Service\MediaBannerContent;
use App\Service\PageContent;
use App\Service\PageHeroContent;
use App\Service\PageService;
use App\Service\SectionRegistry;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestSession;
use Tests\Support\BuiltInServer;
use Tests\Support\PageFixture;

require_once dirname(__DIR__, 2) . '/partials/section-page-hero.php';
require_once dirname(__DIR__, 2) . '/partials/section-media-banner.php';

/**
 * The media sequence (App\Service\Media\MediaSequence) through the real
 * editors and endpoints of its two blocks, and what they then render:
 *
 * Paginakop
 *   - the pictures after the header's own one are stored in order, the own
 *     one never twice; a video, an unknown id or too many refuse the save;
 *   - transition and time per picture are closed lists; a form without them
 *     or without the list keeps what is stored;
 *   - "Geen afbeelding" empties the list; a cleared own picture takes the
 *     list's first;
 *   - behind the text the whole sequence is decoration with a pause button;
 *     beside it every picture is content; one picture prints the markup it
 *     always printed; the trail is the header's top zone either way;
 *   - every further picture is a usage and cannot be deleted.
 *
 * Mediabanner
 *   - pictures and videos after the first, in order; the first cleared takes
 *     the list's first; controls, transition and time are closed lists;
 *   - a sequence that does not play by itself needs arrows or dots, and its
 *     videos their controls;
 *   - the frame is a region with the chosen buttons, and a pause button only
 *     when it plays by itself; the poster stays the first video's;
 *   - every further item is a usage.
 */
final class MediaSequenceHttpTest extends TestCase
{
    private const HERO_PAGE = '__test_media_sequence_hero__';
    private const BANNER_PAGE = 'zz-media-sequence-banner';
    private const HERO_ENDPOINT = '/api/admin/update-page-hero.php';
    private const BANNER_ENDPOINT = '/api/admin/update-media-banner.php';

    private static ?BuiltInServer $server = null;

    private AdminTestSession $accounts;

    /** @var list<int> */
    private array $mediaIds = [];

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

        $this->removePages();

        $heroPage = PageFixture::create(['content_key' => self::HERO_PAGE, 'slug' => self::HERO_PAGE, 'status' => PageContent::STATUS_PUBLISHED], 'Mediareeks-kop');
        [$heroId, $heroKey] = SectionRegistry::create('page_hero', self::HERO_PAGE);
        (new PageSectionRepository())->create($heroPage, self::HERO_PAGE, 'page_hero', $heroKey, $heroId);

        PageFixture::create(['content_key' => self::BANNER_PAGE, 'slug' => self::BANNER_PAGE, 'status' => PageContent::STATUS_PUBLISHED], 'Mediareeks-banner');
    }

    protected function tearDown(): void
    {
        $this->removePages();

        foreach ($this->mediaIds as $id) {
            (new MediaRepository())->delete($id);
        }
        $this->mediaIds = [];

        $this->accounts->forget();
        $this->clearCaches();
    }

    // ----------------------------------------------------------- Paginakop

    public function testAHeadersFurtherPicturesAreStoredInOrderAndItsOwnNeverTwice(): void
    {
        [$session, $csrf] = $this->signIn();
        [$own, $second, $third] = [$this->picture('Eigen'), $this->picture('Tweede'), $this->picture('Derde')];

        $this->assertSaved($this->hero($session, $csrf, [
            'media_id' => (string) $own, 'image_mode' => 'background',
            'sequence' => ['media:' . $third, 'media:' . $own, 'media:' . $second], 'slide_transition' => 'slide', 'slide_duration' => '3',
        ]), '/admin/page-hero.php');

        $row = $this->heroRow();
        self::assertSame([$third, $second], (new PageHeroRepository())->findImageIds((int) $row['id']), 'in order, the own picture not repeated');
        self::assertSame(['slide', 3], [$row['slide_transition'], (int) $row['slide_duration']]);

        $content = $this->heroContent();
        self::assertSame(['Derde', 'Tweede'], array_column($content['slides'], 'alt'), 'the library\'s alt texts');
        self::assertSame(['slide', 3], [$content['slide_transition'], $content['slide_duration']]);
    }

    public function testAHeadersListRefusesAnythingButItsPicturesAndKeepsWhatAFormDoesNotSend(): void
    {
        [$session, $csrf] = $this->signIn();
        $own = $this->picture();
        $video = $this->item('video/mp4', 'mp4');

        $this->assertSaved($this->hero($session, $csrf, ['media_id' => (string) $own, 'image_mode' => 'left', 'sequence' => ['media:' . $this->picture()]]), '/admin/page-hero.php');
        $stored = (new PageHeroRepository())->findImageIds((int) $this->heroRow()['id']);

        $tooMany = array_map(fn (): string => 'media:' . $this->picture(), range(1, 12));
        foreach ([
            'a video' => ['sequence' => ['media:' . $video]],
            'an unknown id' => ['sequence' => ['media:999999999']],
            'another token' => ['sequence' => ['image:' . $own]],
            'too many' => ['sequence' => $tooMany],
            'an unknown transition' => ['slide_transition' => 'wipe'],
            'an unknown time' => ['slide_duration' => '11'],
        ] as $what => $changes) {
            $this->assertRefused($this->hero($session, $csrf, ['media_id' => (string) $own, 'image_mode' => 'left'] + $changes), '/admin/page-hero.php', $what);
        }
        self::assertSame($stored, (new PageHeroRepository())->findImageIds((int) $this->heroRow()['id']), 'a refused save stores nothing');

        $this->assertSaved($this->hero($session, $csrf, ['media_id' => (string) $own, 'image_mode' => 'left'], false), '/admin/page-hero.php');
        self::assertSame($stored, (new PageHeroRepository())->findImageIds((int) $this->heroRow()['id']), 'a form without the list keeps it');
    }

    public function testNoPictureEmptiesTheListAndAClearedOwnPictureTakesTheFirstOfIt(): void
    {
        [$session, $csrf] = $this->signIn();
        [$own, $second, $third] = [$this->picture(), $this->picture(), $this->picture()];

        $this->assertSaved($this->hero($session, $csrf, ['media_id' => '', 'image_mode' => 'background', 'image_alt' => 'Oude tekst', 'sequence' => ['media:' . $second, 'media:' . $third]]), '/admin/page-hero.php');
        $row = $this->heroRow();
        self::assertSame($second, (int) $row['media_id'], 'the list\'s first became the own picture');
        self::assertSame([$third], (new PageHeroRepository())->findImageIds((int) $row['id']));

        $this->assertSaved($this->hero($session, $csrf, ['media_id' => (string) $own, 'image_mode' => 'none', 'sequence' => ['media:' . $third]]), '/admin/page-hero.php');
        $row = $this->heroRow();
        self::assertNull($row['media_id']);
        self::assertSame([], (new PageHeroRepository())->findImageIds((int) $row['id']), 'no picture, no sequence');
    }

    public function testBehindTheTextTheSequenceIsDecorationWithAPauseButton(): void
    {
        [$session, $csrf] = $this->signIn();
        [$own, $second] = [$this->picture('Eigen'), $this->picture('Tweede')];
        $this->assertSaved($this->hero($session, $csrf, ['media_id' => (string) $own, 'image_mode' => 'background', 'content_position' => 'center', 'sequence' => ['media:' . $second], 'slide_duration' => '2']), '/admin/page-hero.php');

        $html = $this->renderHero($this->trail());

        self::assertMatchesRegularExpression('#<section class="page-hero page-hero--background page-hero--content-center page-hero--sequence" data-media-sequence data-media-sequence-transition="fade" data-media-sequence-duration="2" data-media-sequence-autoplay data-media-sequence-loop>#', $html);
        self::assertMatchesRegularExpression('#<div class="page-hero__media">\s*<div class="media-sequence media-sequence--fade" data-media-sequence-track aria-hidden="true">#', $html, 'the pictures inside the header\'s own layer, hidden as a whole');
        self::assertSame(2, substr_count($html, 'data-media-sequence-slide'));
        self::assertStringNotContainsString('alt="Eigen"', $html);
        self::assertStringNotContainsString('alt="Tweede"', $html);
        self::assertStringContainsString('media-sequence__controls page-hero__sequence-controls', $html);
        self::assertStringContainsString('data-media-sequence-pause', $html);
        self::assertStringNotContainsString('data-media-sequence-prev', $html, 'no arrows in a header');
        self::assertMatchesRegularExpression('#</div>\s*<nav class="breadcrumb-bar" aria-label="Kruimelpad">\s*<div class="container">#', $html, 'the trail is its own zone at the top');
    }

    public function testBesideTheTextEveryPictureIsContentAndOnePictureIsUnchanged(): void
    {
        [$session, $csrf] = $this->signIn();
        [$own, $second] = [$this->picture('Bibliotheek'), $this->picture('Tweede')];
        $this->assertSaved($this->hero($session, $csrf, ['media_id' => (string) $own, 'image_mode' => 'right', 'image_alt' => 'Eigen tekst', 'sequence' => ['media:' . $second]]), '/admin/page-hero.php');

        $html = $this->renderHero(null);
        self::assertMatchesRegularExpression('#<figure class="page-hero__figure page-hero__figure--sequence" role="region" aria-roledescription="carousel" aria-label="Diavoorstelling" data-media-sequence[^>]*>\s*<div class="media-sequence media-sequence--fade" data-media-sequence-track>#', $html);
        self::assertStringContainsString('alt="Eigen tekst"', $html, 'the header\'s own alt text on its own picture');
        self::assertStringContainsString('alt="Tweede"', $html, 'the library\'s on a further one');
        self::assertStringContainsString('aria-label="2 van 2"', $html);

        $this->assertSaved($this->hero($session, $csrf, ['media_id' => (string) $own, 'image_mode' => 'right', 'image_alt' => 'Eigen tekst', 'sequence' => []]), '/admin/page-hero.php');
        $single = $this->renderHero(null);
        self::assertMatchesRegularExpression('#<figure class="page-hero__figure">\s*<img src="[^"]+" alt="Eigen tekst"#', $single, 'one picture: the markup it always printed');
        self::assertStringNotContainsString('media-sequence', $single);
        self::assertStringNotContainsString('page-hero--sequence', $single);
    }

    public function testAHeadersFurtherPicturesAreUsages(): void
    {
        [$session, $csrf] = $this->signIn();
        [$own, $second] = [$this->picture(), $this->picture()];
        $this->assertSaved($this->hero($session, $csrf, ['media_id' => (string) $own, 'image_mode' => 'background', 'sequence' => ['media:' . $second]]), '/admin/page-hero.php');

        $service = new MediaService(new MediaRepository());
        $usages = $service->usagesOf($second);
        self::assertCount(1, $usages);
        self::assertSame('Paginakop (diavoorstelling) op "' . self::HERO_PAGE . '"', $usages[0]->label);
        self::assertSame('/admin/page-hero.php?slug=' . rawurlencode(self::HERO_PAGE), $usages[0]->editUrl);
        self::assertFalse($service->delete($second)['deleted']);
    }

    public function testTheHeaderEditorShowsTheListOnceThereIsAPictureAndItsChoicesOnceItHasOne(): void
    {
        [$session, $csrf] = $this->signIn();
        $own = $this->picture();

        $xpath = $this->xpath(self::$server->request('GET', '/admin/page-hero.php?slug=' . rawurlencode(self::HERO_PAGE), $session)['body']);
        self::assertSame(1, $xpath->query('//div[@data-page-hero-needs-image and @hidden][.//*[@data-media-sequence-list]]')->length, 'no picture yet: no list');
        self::assertSame(1, $xpath->query('//*[@data-media-sequence-list]//*[@data-media-picker-collect and @data-media-picker-kind="image"]')->length, 'pictures, several at once');

        $this->assertSaved($this->hero($session, $csrf, ['media_id' => (string) $own, 'image_mode' => 'background', 'sequence' => ['media:' . $this->picture()]]), '/admin/page-hero.php');
        $xpath = $this->xpath(self::$server->request('GET', '/admin/page-hero.php?slug=' . rawurlencode(self::HERO_PAGE), $session)['body']);
        self::assertSame(1, $xpath->query('//*[@data-media-sequence-list]//li[@data-gallery-item]')->length);
        self::assertSame(1, $xpath->query('//*[@data-media-sequence-needs and not(@hidden)]//select[@name="slide_transition"]')->length);
        self::assertSame(['1', '2', '3', '4', '5', '6', '7', '8', '9', '10'], array_map(static fn (\DOMElement $option): string => $option->getAttribute('value'), iterator_to_array($xpath->query('//select[@name="slide_duration"]/option'))));
    }

    // ---------------------------------------------------------- Mediabanner

    public function testABannersFurtherItemsArePicturesAndVideosInOrder(): void
    {
        [$section] = $this->placeBanner();
        [$session, $csrf] = $this->signIn();
        [$first, $video, $picture] = [$this->picture('Eerste'), $this->item('video/mp4', 'mp4'), $this->picture('Derde')];

        $this->assertSaved($this->banner($session, $csrf, $section, [
            'media_id' => (string) $first,
            'sequence' => ['media:' . $video, 'media:' . $first, 'media:' . $picture],
            'video_autoplay' => '1', 'video_loop' => '1',
            'slide_transition' => 'none', 'slide_duration' => '7', 'slide_controls' => 'arrows',
        ]), '/admin/media-banner.php');

        $row = $this->bannerRow($section);
        self::assertSame([$video, $picture], (new MediaBannerRepository())->findItemIds((int) $row['id']));
        self::assertSame(['none', 7, 'arrows', 1, 1], [$row['slide_transition'], (int) $row['slide_duration'], $row['slide_controls'], (int) $row['video_autoplay'], (int) $row['video_loop']]);

        $content = $this->bannerContent($section);
        self::assertSame([MediaType::IMAGE, MediaType::VIDEO, MediaType::IMAGE], array_column($content['items'], 'kind'));
        self::assertTrue($content['autoplay'], 'a sequence plays by itself');
        self::assertTrue($content['loop']);
        self::assertSame('arrows', $content['nav']);
    }

    public function testASequenceThatDoesNotPlayByItselfNeedsAWayToMove(): void
    {
        [$section] = $this->placeBanner();
        [$session, $csrf] = $this->signIn();
        [$first, $second, $video] = [$this->picture(), $this->picture(), $this->item('video/mp4', 'mp4')];

        $this->assertRefused($this->banner($session, $csrf, $section, ['media_id' => (string) $first, 'sequence' => ['media:' . $second], 'slide_controls' => 'none']), '/admin/media-banner.php', 'no autoplay, no buttons');
        $this->assertRefused($this->banner($session, $csrf, $section, ['media_id' => (string) $first, 'sequence' => ['media:' . $video], 'slide_controls' => 'dots'], ['video_controls']), '/admin/media-banner.php', 'a video that cannot be started');
        foreach (['slide_transition' => 'wipe', 'slide_duration' => '0', 'slide_controls' => 'all'] as $choice => $word) {
            $this->assertRefused($this->banner($session, $csrf, $section, ['media_id' => (string) $first, 'sequence' => ['media:' . $second], $choice => $word]), '/admin/media-banner.php', $choice);
        }

        $this->assertSaved($this->banner($session, $csrf, $section, ['media_id' => (string) $first, 'sequence' => ['media:' . $second], 'slide_controls' => 'dots']), '/admin/media-banner.php');
        $html = $this->renderBanner($this->bannerContent($section));
        self::assertMatchesRegularExpression('#<div class="media-banner media-banner--medium media-banner--sequence" data-reveal role="region" aria-roledescription="carousel" aria-label="Diavoorstelling" data-media-sequence[^>]*>#', $html);
        self::assertStringContainsString('data-media-sequence-dot="1"', $html);
        self::assertStringNotContainsString('data-media-sequence-prev', $html);
        self::assertStringNotContainsString('data-media-sequence-pause', $html, 'nothing to pause');
        self::assertStringNotContainsString('data-media-sequence-autoplay', $html);
    }

    public function testAPlayingSequenceHasAPauseButtonAndThePosterStaysTheFirstVideos(): void
    {
        [$section] = $this->placeBanner();
        [$session, $csrf] = $this->signIn();
        [$video, $poster, $picture] = [$this->item('video/mp4', 'mp4'), $this->picture(), $this->picture('Beeld')];

        $this->assertSaved($this->banner($session, $csrf, $section, [
            'media_id' => (string) $video, 'poster_media_id' => (string) $poster, 'sequence' => ['media:' . $picture],
            'video_autoplay' => '1', 'slide_controls' => 'none',
        ]), '/admin/media-banner.php');

        $html = $this->renderBanner($this->bannerContent($section));
        self::assertStringContainsString('data-media-sequence-pause', $html, 'something that moves by itself can be stopped');
        self::assertStringContainsString('data-media-sequence-autoplay data-media-sequence-hover-pause>', $html, 'no swipe without buttons');
        self::assertMatchesRegularExpression('#<video class="media-banner__media" src="[^"]+" poster="' . preg_quote((string) MediaService::find($poster)?->publicPath(), '#') . '"[^>]* muted autoplay#', $html);
        self::assertStringContainsString('alt="Beeld"', $html);

        // The first item cleared: the list's first takes its place, and a
        // picture has no poster.
        $this->assertSaved($this->banner($session, $csrf, $section, ['media_id' => '', 'poster_media_id' => (string) $poster, 'sequence' => ['media:' . $picture], 'video_autoplay' => '1']), '/admin/media-banner.php');
        $row = $this->bannerRow($section);
        self::assertSame([$picture, null], [(int) $row['media_id'], $row['poster_media_id']]);
        self::assertSame([], (new MediaBannerRepository())->findItemIds((int) $row['id']));
    }

    public function testABannersFurtherItemsAreUsages(): void
    {
        [$section] = $this->placeBanner();
        [$session, $csrf] = $this->signIn();
        [$first, $video] = [$this->picture(), $this->item('video/mp4', 'mp4')];
        $this->assertSaved($this->banner($session, $csrf, $section, ['media_id' => (string) $first, 'sequence' => ['media:' . $video], 'video_autoplay' => '1']), '/admin/media-banner.php');

        $service = new MediaService(new MediaRepository());
        self::assertSame('Mediabanner (reeks) op "' . self::BANNER_PAGE . '"', $service->usagesOf($video)[0]->label ?? null);
        self::assertFalse($service->delete($video)['deleted']);
    }

    // ------------------------------------------------------------- helpers

    /** @return array{string, string} session and CSRF token */
    private function signIn(): array
    {
        [$session, $csrf] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);

        return [$session, $csrf];
    }

    /**
     * The header form as the editor posts it, the list included unless
     * $withList is false (a form from before it).
     *
     * @param array<string, mixed> $changes
     * @return array{status: int, location: string, body: string, headers: string}
     */
    private function hero(string $session, string $csrf, array $changes, bool $withList = true): array
    {
        $fields = array_merge([
            'csrf_token' => $csrf,
            'slug' => self::HERO_PAGE,
            'language_code' => 'nl',
            'eyebrow' => '',
            'title' => 'Een kop met beelden',
            'lead' => '',
            'image_alt' => '',
            'media_id' => '',
            'content_position' => 'left',
            'title_size' => 'normal',
            'text_size' => 'normal',
            'image_mode' => 'none',
            'hero_height' => 'medium',
            'image_focus' => 'center',
            'slide_transition' => 'fade',
            'slide_duration' => '5',
            'is_active' => '1',
        ], $withList ? ['sequence_submitted' => '1'] : [], $changes);
        if (!$withList) {
            unset($fields['sequence'], $fields['slide_transition'], $fields['slide_duration']);
        }

        $response = self::$server->request('POST', self::HERO_ENDPOINT, $session, $fields);
        $this->clearCaches();

        return $response;
    }

    /**
     * @param array<string, mixed> $changes
     * @param list<string>         $off switches left out, as a browser does
     * @return array{status: int, location: string, body: string, headers: string}
     */
    private function banner(string $session, string $csrf, string $section, array $changes, array $off = []): array
    {
        $fields = array_merge([
            'csrf_token' => $csrf,
            'section' => $section,
            'media_id' => '',
            'width' => 'content',
            'height' => 'medium',
            'image_focus' => 'center',
            'video_controls' => '1',
            'poster_media_id' => '',
            'sequence_submitted' => '1',
            'slide_transition' => 'fade',
            'slide_duration' => '5',
            'slide_controls' => 'both',
        ], $changes);
        foreach ($off as $name) {
            unset($fields[$name]);
        }

        $response = self::$server->request('POST', self::BANNER_ENDPOINT, $session, $fields);
        $this->clearCaches();

        return $response;
    }

    /** @return array{string, int} */
    private function placeBanner(): array
    {
        [$id, $key] = SectionRegistry::create('media_banner', self::BANNER_PAGE);
        $page = (new PageRepository())->findByContentKey(self::BANNER_PAGE);
        self::assertNotNull($page);
        (new PageSectionRepository())->create((int) $page['id'], self::BANNER_PAGE, 'media_banner', $key, $id);

        return [self::BANNER_PAGE . ':' . $key, (int) $id];
    }

    /** @return array<string, mixed> */
    private function heroRow(): array
    {
        $row = (new PageHeroRepository())->findBySlug(self::HERO_PAGE);
        self::assertNotNull($row);

        return $row;
    }

    /** @return array<string, mixed> */
    private function heroContent(): array
    {
        $this->clearCaches();

        return PageHeroContent::forSlug(self::HERO_PAGE);
    }

    /** @return array<string, mixed> */
    private function bannerRow(string $section): array
    {
        [, $key] = explode(':', $section, 2);
        $row = (new MediaBannerRepository())->findBySlugAndKey(self::BANNER_PAGE, $key);
        self::assertNotNull($row);

        return $row;
    }

    /** @return array<string, mixed> */
    private function bannerContent(string $section): array
    {
        [, $key] = explode(':', $section, 2);
        $this->clearCaches();

        return MediaBannerContent::forSection(self::BANNER_PAGE, $key);
    }

    private function trail(): BreadcrumbTrail
    {
        return BreadcrumbTrail::home()->to(BreadcrumbItem::current('Mediareeks-kop'));
    }

    private function renderHero(?BreadcrumbTrail $trail): string
    {
        ob_start();
        render_section_page_hero($this->heroContent(), null, $trail);

        return (string) ob_get_clean();
    }

    /** @param array<string, mixed> $content */
    private function renderBanner(array $content): string
    {
        ob_start();
        render_section_media_banner($content);

        return (string) ob_get_clean();
    }

    private function picture(string $alt = ''): int
    {
        return $this->item('image/jpeg', 'jpg', $alt);
    }

    /** A media row for a file that does not exist: neither the form nor the save reads the disk. */
    private function item(string $mimeType, string $extension, string $alt = ''): int
    {
        $id = (new MediaRepository())->create([
            'path' => 'assets/media/__seq_' . bin2hex(random_bytes(4)) . '__.' . $extension,
            'original_filename' => 'reeks.' . $extension,
            'mime_type' => $mimeType,
            'alt_text' => $alt,
            'width' => $mimeType === 'video/mp4' ? null : 1600,
            'height' => $mimeType === 'video/mp4' ? null : 900,
        ]);
        $this->mediaIds[] = $id;
        MediaService::clearCache();

        return $id;
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
        PageHeroContent::clearCache();
        MediaBannerContent::clearCache();
        MediaService::clearCache();
        PageContent::clearCache();
    }

    /** @param array{location: string} $response */
    private function assertSaved(array $response, string $editor): void
    {
        self::assertStringContainsString('saved=1', $response['location'], $response['body']);
        self::assertStringStartsWith($editor, $response['location']);
    }

    /** @param array{location: string} $response */
    private function assertRefused(array $response, string $editor, string $what = ''): void
    {
        self::assertStringNotContainsString('saved=1', $response['location'], $what);
        self::assertStringStartsWith($editor, $response['location'], $what . ': back to the editor');
    }

    private function removePages(): void
    {
        foreach ([self::HERO_PAGE, self::BANNER_PAGE] as $key) {
            $page = (new PageRepository())->findByContentKey($key);
            if ($page !== null) {
                PageService::delete($page);
            }
        }

        PageContent::clearCache();
    }
}

<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Repository\CardCarouselRepository;
use App\Repository\FeatureGridRepository;
use App\Repository\MediaRepository;
use App\Repository\PageRepository;
use App\Repository\PageSectionRepository;
use App\Repository\RichTextRepository;
use App\Service\AdminPermissions;
use App\Service\Blocks\BlockLocalization;
use App\Service\CardCarouselContent;
use App\Service\FeatureGridContent;
use App\Service\Language\SiteLanguages;
use App\Service\Media\ImageFocus;
use App\Service\Media\MediaService;
use App\Service\Media\Usage\ContentBlockMediaUsage;
use App\Service\PageContent;
use App\Service\PageService;
use App\Service\RichTextContent;
use App\Service\SectionRegistry;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestSession;
use Tests\Support\BuiltInServer;
use Tests\Support\PageFixture;

/**
 * The Content Blocks UX polish phase over real HTTP, block by block:
 *
 *   Kenmerken in kaartjes   a card without a title renders no heading; the
 *                           icon is none, a standard one, or an SVG from the
 *                           library's Iconen, refused when it is no SVG, and
 *                           counted as a use of that SVG while it is chosen
 *   Tekstblok               left, centre or right, and an optional button
 *                           through the shared link field, whose label is
 *                           required only while there is a button
 *   Kaarten-carrousel       an empty number shows nothing; a card's focus
 *                           point is stored, rendered as object-position and
 *                           shown the same way in the editor's preview
 *
 * The page, its blocks, the accounts and the library items are this test's
 * own and are removed in tearDown(). Without a server the test skips itself.
 */
final class ContentBlocksPolishHttpTest extends TestCase
{
    private const KEY = 'zz-content-blocks-polish-test';

    private static ?BuiltInServer $server = null;

    private AdminTestSession $accounts;

    private int $pageId = 0;

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

        if (BlockLocalization::defaultLanguage() !== 'nl' || !SiteLanguages::isActive('en')) {
            $this->markTestSkipped('this test expects the test database to publish nl (default) and en');
        }

        $this->removePage();
        $this->pageId = PageFixture::create(
            ['content_key' => self::KEY, 'slug' => self::KEY, 'status' => PageContent::STATUS_PUBLISHED],
            'Polijsttest'
        );
    }

    protected function tearDown(): void
    {
        $this->removePage();

        foreach ($this->mediaIds as $id) {
            (new MediaRepository())->delete($id);
        }
        $this->mediaIds = [];
        MediaService::clearCache();

        $this->accounts->forget();
        BlockLocalization::clearCache();
        FeatureGridContent::clearCache();
        RichTextContent::clearCache();
        CardCarouselContent::clearCache();
        PageContent::clearCache();
    }

    // ------------------------------------------------------ Kenmerken in kaartjes

    public function testACardWithoutATitleIsSavedAndRendersNoHeading(): void
    {
        [$section, $gridId] = $this->place('feature_grid');
        $session = $this->signIn();

        $this->assertSaved($this->post($session, '/api/admin/update-feature-grid.php', [
            'section' => $section,
            'is_active' => '1',
            'title' => 'Waarom wij',
            'items_present' => '1',
            'items' => ['new0' => ['icon_key' => FeatureGridContent::ICON_NONE, 'title' => '', 'body' => 'Alleen een tekst.', 'active' => '1']],
        ]));

        $content = FeatureGridContent::forSection(self::KEY, explode(':', $section)[1]);
        self::assertCount(1, $content['items'], 'a card with only a text is a card');
        self::assertSame('', $content['items'][0]['title']);
        self::assertSame(FeatureGridContent::ICON_NONE, $content['items'][0]['icon_key']);

        $html = $this->render(static fn () => render_section_feature_grid($content, 'zz'));
        self::assertStringContainsString('<div class="feature-card"', $html);
        self::assertStringNotContainsString('<h3', $html, 'no empty heading');
        self::assertStringNotContainsString('feature-card__icon', $html, 'no icon, no empty circle');
        self::assertStringContainsString('<p>Alleen een tekst.</p>', $html);
        self::assertCount(1, (new FeatureGridRepository())->findItemsByGridId($gridId));
    }

    public function testACustomIconMustBeAnSvgFromTheLibraryAndCountsAsItsUse(): void
    {
        [$section, $gridId] = $this->place('feature_grid');
        $session = $this->signIn();
        $svg = $this->libraryItem('image/svg+xml', 'svg');
        $png = $this->libraryItem('image/png', 'png');
        $card = static fn (string $iconKey, string $mediaId): array => [
            'section' => $section,
            'is_active' => '1',
            'title' => 'Waarom wij',
            'items_present' => '1',
            'items' => ['new0' => ['icon_key' => $iconKey, 'icon_media_id' => $mediaId, 'title' => 'Snel', 'body' => 'Binnen een week.', 'active' => '1']],
        ];

        foreach (['' => 'no icon chosen', (string) $png => 'a PNG', '999999999' => 'no such item'] as $mediaId => $what) {
            $refused = $this->post($session, '/api/admin/update-feature-grid.php', $card(FeatureGridContent::ICON_CUSTOM, (string) $mediaId));
            $this->assertRefused($refused, $what);
        }
        self::assertSame([], (new FeatureGridRepository())->findItemsByGridId($gridId), 'a refused save stores nothing');

        $screen = self::$server->request('GET', '/admin/feature-grid.php?section=' . urlencode($section), $session)['body'];
        self::assertStringContainsString('Kies een eigen icoon (SVG) of kies een ander icoon.', $screen, 'the message next to the picker');

        $this->assertSaved($this->post($session, '/api/admin/update-feature-grid.php', $card(FeatureGridContent::ICON_CUSTOM, (string) $svg)));
        $item = (new FeatureGridRepository())->findItemsByGridId($gridId)[0];
        self::assertSame([FeatureGridContent::ICON_CUSTOM, $svg], [$item['icon_key'], (int) $item['icon_media_id']]);

        FeatureGridContent::clearCache();
        $content = FeatureGridContent::forSection(self::KEY, explode(':', $section)[1]);
        $icon = (string) MediaService::find($svg)?->publicPath();
        self::assertSame($icon, $content['items'][0]['icon_url']);

        $html = $this->render(static fn () => render_section_feature_grid($content, 'zz'));
        self::assertStringContainsString(
            '<div class="feature-card__icon feature-card__icon--custom" aria-hidden="true"><img src="' . htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') . '" alt=""',
            $html,
            'decorative: the title and the text carry the meaning'
        );

        $usages = (new ContentBlockMediaUsage())->usagesFor([$svg]);
        self::assertCount(1, $usages[$svg] ?? [], 'the library knows the card uses the icon');
        self::assertSame('/admin/feature-grid.php?section=' . rawurlencode($section), $usages[$svg][0]->editUrl);
        self::assertStringContainsString('icoon', $usages[$svg][0]->label);

        // Back to a standard icon: the SVG is no longer in use.
        $id = (string) $item['id'];
        $this->assertSaved($this->post($session, '/api/admin/update-feature-grid.php', [
            'section' => $section, 'is_active' => '1', 'title' => 'Waarom wij', 'items_present' => '1',
            'items' => [$id => ['icon_key' => 'heart', 'icon_media_id' => (string) $svg, 'title' => 'Snel', 'body' => 'Binnen een week.', 'active' => '1']],
        ]));
        $item = (new FeatureGridRepository())->findItemsByGridId($gridId)[0];
        self::assertSame(['heart', null], [$item['icon_key'], $item['icon_media_id']]);
        self::assertSame([], (new ContentBlockMediaUsage())->usagesFor([$svg])[$svg] ?? []);
    }

    public function testTheCardEditorOffersNoIconAStandardIconAndAnIconPicker(): void
    {
        [$section] = $this->place('feature_grid');
        $session = $this->signIn();

        $screen = $this->xpath(self::$server->request('GET', '/admin/feature-grid.php?section=' . urlencode($section), $session)['body']);

        $options = [];
        foreach ($screen->query('(//select[@data-feature-icon-select])[1]//option') as $option) {
            $options[] = $option->getAttribute('value');
        }
        self::assertSame(['none', 'precision', 'heart', 'diamond', 'location', 'custom'], $options);
        self::assertGreaterThan(0, $screen->query('//*[@data-media-picker-kind="icon"]')->length, 'the icon picker lists and uploads icons only');
        self::assertSame(0, $screen->query('//*[@data-media-picker-kind="image"]')->length, 'no ordinary image field on this screen');
        self::assertSame(1, $screen->query('//*[@data-media-modal]')->length, 'one shared picker modal');
    }

    // ------------------------------------------------------------------ Tekstblok

    public function testTheTextBlockIsAlignedAndHasAButtonToAPage(): void
    {
        [$section, $id] = $this->place('rich_text');
        $session = $this->signIn();

        $this->assertSaved($this->post($session, '/api/admin/update-rich-text-section.php', [
            'section' => $section,
            'is_active' => '1',
            'body' => '<p>Een alinea met <a href="/x">een link</a>.</p>',
            'text_align' => 'center',
            'button_link_type' => 'page',
            'button_link_target' => ['page' => (string) $this->pageId],
            'button_url' => '',
            'button_label' => 'Lees verder',
        ]));

        $row = (new RichTextRepository())->findById($id);
        self::assertSame(['center', 'page', $this->pageId], [$row['text_align'], $row['button_link_type'], (int) $row['button_link_target_id']]);

        RichTextContent::clearCache();
        $content = RichTextContent::forSection(self::KEY, explode(':', $section)[1]);
        $href = PageContent::publicUrl((new PageRepository())->findById($this->pageId));
        self::assertSame(['center', 'Lees verder', $href], [$content['align'], $content['button_label'], $content['button_href']]);

        $html = $this->render(static fn () => render_section_rich_text($content));
        self::assertStringContainsString('<div class="container container--narrow rich-text--center">', $html);
        self::assertStringContainsString('<p class="rich-text__actions"><a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" class="btn">Lees verder</a></p>', $html);

        foreach (['left' => '<div class="container container--narrow">', 'right' => 'rich-text--right', 'justify' => '<div class="container container--narrow">'] as $align => $expected) {
            $this->assertSaved($this->post($session, '/api/admin/update-rich-text-section.php', [
                'section' => $section, 'is_active' => '1', 'body' => '<p>Tekst</p>', 'text_align' => $align, 'button_link_type' => 'none',
            ]), $align);
            RichTextContent::clearCache();
            $content = RichTextContent::forSection(self::KEY, explode(':', $section)[1]);
            self::assertStringContainsString($expected, $this->render(static fn () => render_section_rich_text($content)), $align);
        }
        self::assertSame('left', (new RichTextRepository())->findById($id)['text_align'], 'an unknown alignment is the default');
        self::assertSame('', $content['button_href'], '"Geen knop" is no button');
    }

    public function testTheButtonLabelIsOnlyRequiredWhileThereIsAButton(): void
    {
        [$section, $id] = $this->place('rich_text');
        $session = $this->signIn();
        $base = ['section' => $section, 'is_active' => '1', 'body' => '<p>Tekst</p>', 'text_align' => 'left'];

        $this->assertRefused($this->post($session, '/api/admin/update-rich-text-section.php', $base + [
            'button_link_type' => 'url', 'button_url' => '/contact', 'button_label' => '',
        ]), 'a button without a text');
        self::assertNull((new RichTextRepository())->findById($id)['button_link_type'], 'nothing stored');
        $screen = self::$server->request('GET', '/admin/rich-text.php?section=' . urlencode($section), $session)['body'];
        self::assertStringContainsString('Geef de knop een tekst, of kies Geen knop.', $screen);

        $this->assertRefused($this->post($session, '/api/admin/update-rich-text-section.php', $base + [
            'button_link_type' => 'url', 'button_url' => 'javascript:alert(1)', 'button_label' => 'Klik',
        ]), 'the shared link rule');

        $this->assertSaved($this->post($session, '/api/admin/update-rich-text-section.php', $base + [
            'button_link_type' => 'none', 'button_url' => '', 'button_label' => '',
        ]), 'no button, no text: nothing to check');

        $this->assertSaved($this->post($session, '/api/admin/update-rich-text-section.php', $base + [
            'button_link_type' => 'url', 'button_url' => '/contact', 'button_label' => 'Contact',
        ]));
        $this->assertSaved($this->post($session, '/api/admin/update-rich-text-section.php', ['language_code' => 'en'] + $base + [
            'button_link_type' => 'url', 'button_url' => '/contact', 'button_label' => '',
        ]), 'a translation may leave the text empty: it falls back');
    }

    public function testTheTextBlockScreenUsesTheSharedLinkField(): void
    {
        [$section] = $this->place('rich_text');
        $session = $this->signIn();

        $screen = $this->xpath(self::$server->request('GET', '/admin/rich-text.php?section=' . urlencode($section), $session)['body']);

        $aligns = [];
        foreach ($screen->query('//*[@role="radiogroup"]//input[@type="radio" and @name="text_align"]') as $radio) {
            $aligns[] = $radio->getAttribute('value') . ($radio->hasAttribute('checked') ? '*' : '');
        }
        self::assertSame(['left*', 'center', 'right'], $aligns);
        self::assertSame(1, $screen->query('//select[@name="button_link_type" and @data-nav-link-type]')->length, 'admin/_link_target_field.php');
        self::assertSame(1, $screen->query('//input[@name="button_url"]')->length);
        self::assertSame(0, $screen->query('//input[@name="button_label" and @required]')->length, 'never required on its own');
    }

    // -------------------------------------------------------------- Kaarten-carrousel

    public function testACardsFocusPointIsStoredRenderedAndPreviewedAlike(): void
    {
        [$section, $carouselId] = $this->place('card_carousel');
        $session = $this->signIn();
        $repository = new CardCarouselRepository();
        $card = $repository->createCard($carouselId, true);
        BlockLocalization::save('carousel_cards', $card, 'nl', ['title' => 'Hout']);
        $picture = $this->libraryItem('image/webp', 'webp');
        $save = fn (array $fields): array => $this->post($session, '/api/admin/update-carousel-card.php', ['card_id' => (string) $card, 'is_active' => '1', 'title' => 'Hout', 'link_type' => 'none', 'media_id' => (string) $picture] + $fields);

        self::assertSame('center', $repository->findCardById($card)['image_focus'], 'a card starts in the middle');

        foreach (['top-left' => '0% 0%', 'bottom' => '50% 100%', 'right' => '100% 50%'] as $focus => $position) {
            $this->assertSaved($save(['image_focus' => $focus]), $focus);
            self::assertSame($focus, $repository->findCardById($card)['image_focus']);

            CardCarouselContent::clearCache();
            $content = CardCarouselContent::forSection(self::KEY, explode(':', $section)[1]);
            self::assertSame($position, $content['cards'][0]['image_position']);
            self::assertStringContainsString('style="object-position: ' . $position . '"', $this->render(static fn () => render_section_card_carousel($content)));

            $screen = $this->xpath(self::$server->request('GET', '/admin/carousel-card.php?card_id=' . $card, $session)['body']);
            self::assertSame(9, $screen->query('//fieldset[@data-image-focus]//input[@type="radio" and @name="image_focus"]')->length);
            self::assertSame($focus, $screen->query('//input[@name="image_focus" and @checked]')->item(0)?->getAttribute('value'));
            self::assertSame('object-position: ' . $position, $screen->query('//img[@data-image-focus-preview]')->item(0)?->getAttribute('style'), 'the preview uses the same setting');
        }

        $this->assertSaved($save(['image_focus' => 'everywhere']));
        self::assertSame(ImageFocus::DEFAULT, $repository->findCardById($card)['image_focus'], 'an unknown point is the middle');

        $this->assertSaved($save(['image_focus' => 'top']));
        $this->assertSaved($save([]));
        self::assertSame('top', $repository->findCardById($card)['image_focus'], 'a form without the field keeps it');
    }

    public function testAnEmptyNumberShowsNoLabel(): void
    {
        [$section, $carouselId] = $this->place('card_carousel');
        $session = $this->signIn();
        $repository = new CardCarouselRepository();
        $card = $repository->createCard($carouselId, true);
        BlockLocalization::save('carousel_cards', $card, 'nl', ['title' => 'Hout']);

        $content = CardCarouselContent::forSection(self::KEY, explode(':', $section)[1]);
        self::assertSame('', $content['cards'][0]['index_label']);
        self::assertStringNotContainsString('service-row__index', $this->render(static fn () => render_section_card_carousel($content)));

        $screen = self::$server->request('GET', '/admin/carousel-card.php?card_id=' . $card, $session)['body'];
        self::assertMatchesRegularExpression('/id="card-number"[^>]*value=""[^>]*placeholder="Leeg = geen nummer"/', $screen);

        $this->assertSaved($this->post($session, '/api/admin/update-carousel-card.php', ['card_id' => (string) $card, 'is_active' => '1', 'title' => 'Hout', 'number_label' => '07', 'link_type' => 'none']));
        CardCarouselContent::clearCache();
        $content = CardCarouselContent::forSection(self::KEY, explode(':', $section)[1]);
        self::assertStringContainsString('<span class="service-row__index">07</span>', $this->render(static fn () => render_section_card_carousel($content)));
    }

    // ------------------------------------------------------------------ helpers

    /** @return array{string, int} the block's address and its content row id */
    private function place(string $type): array
    {
        [$id, $key] = SectionRegistry::create($type, self::KEY);
        (new PageSectionRepository())->create($this->pageId, self::KEY, $type, $key, $id);

        return [self::KEY . ':' . $key, (int) $id];
    }

    private function signIn(): string
    {
        [$session] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);

        return $session;
    }

    /**
     * @param array<string, mixed> $fields
     * @return array{status: int, location: string, body: string, headers: string}
     */
    private function post(string $session, string $endpoint, array $fields): array
    {
        $response = self::$server->request('POST', $endpoint, $session, $fields + [
            'csrf_token' => (string) $this->accounts->read($session, 'csrf_token'),
            'language_code' => 'nl',
        ]);
        BlockLocalization::clearCache();
        FeatureGridContent::clearCache();
        RichTextContent::clearCache();
        CardCarouselContent::clearCache();

        return $response;
    }

    /** A media row for a file that does not exist: neither the form nor the save reads the disk. */
    private function libraryItem(string $mimeType, string $extension): int
    {
        $id = (new MediaRepository())->create([
            'path' => 'assets/media/__cb_polish_' . bin2hex(random_bytes(4)) . '__.' . $extension,
            'original_filename' => 'icoon.' . $extension,
            'mime_type' => $mimeType,
            'alt_text' => '',
        ]);
        $this->mediaIds[] = $id;
        MediaService::clearCache();

        return $id;
    }

    private function render(callable $render): string
    {
        ob_start();
        try {
            $render();
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

    /** @param array{location: string} $response */
    private function assertSaved(array $response, string $what = ''): void
    {
        self::assertStringContainsString('saved=1', $response['location'], $what . ' ' . $response['body']);
    }

    /** @param array{location: string} $response */
    private function assertRefused(array $response, string $what = ''): void
    {
        self::assertStringNotContainsString('saved=1', $response['location'], $what);
        self::assertStringStartsWith('/admin/', $response['location'], $what . ': back to the editor');
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

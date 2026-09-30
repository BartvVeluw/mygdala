<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Repository\CtaBandRepository;
use App\Repository\MediaRepository;
use App\Repository\PageRepository;
use App\Repository\PageSectionRepository;
use App\Service\AdminPermissions;
use App\Service\Blocks\BlockLocalization;
use App\Service\Blocks\ContentBlockDrafts;
use App\Service\CtaBandContent;
use App\Service\Language\SiteLanguages;
use App\Service\Media\MediaService;
use App\Service\PageContent;
use App\Service\PageService;
use App\Service\SectionRegistry;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestSession;
use Tests\Support\BuiltInServer;
use Tests\Support\PageFixture;
use Tests\Support\SavedRedirect;

require_once dirname(__DIR__, 2) . '/partials/section-cta-band.php';

/**
 * The minimum height of the Oproep met knop through the real editor and
 * endpoint, over PHP's built-in server (CONTENT-BLOCKS.md, "De hoogte van het
 * achtergrondvlak"):
 *
 *   - a band that never had the setting, and a new one, are 'auto' on both
 *     screens, which is the band as it was;
 *   - every word of both lists is stored, read back and reopened in the
 *     editor; an own height stores its pixels, any other word stores none;
 *   - an unknown word, pixels outside the range, a unit, a decimal or an
 *     empty own height are refused at their field and nothing is stored, and
 *     the refused form comes back as typed;
 *   - changing the height leaves the background picture, its focus points and
 *     zooms, and both buttons' styles exactly as they were;
 *   - a new band follows the draft lifecycle: its first save places it with
 *     its height, and Annuleren leaves nothing behind.
 *
 * The page, its bands, the account and the library item are this test's own
 * and are removed in tearDown(). Without a server the test skips itself.
 */
final class CtaBandHeightHttpTest extends TestCase
{
    private const KEY = 'zz-cta-height-test';
    private const ENDPOINT = '/api/admin/update-cta-band.php';

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

        if (BlockLocalization::defaultLanguage() !== 'nl' || !SiteLanguages::isActive('nl')) {
            $this->markTestSkipped('this test expects the test database to publish nl as its default language');
        }

        $this->removePage();
        $this->pageId = PageFixture::create(
            ['content_key' => self::KEY, 'slug' => self::KEY, 'status' => PageContent::STATUS_PUBLISHED],
            'CTA-hoogte-test'
        );
    }

    protected function tearDown(): void
    {
        $this->removePage();

        foreach ($this->mediaIds as $id) {
            (new MediaRepository())->delete($id);
        }
        $this->mediaIds = [];

        $this->accounts->forget();
        $this->clearCaches();
    }

    // -------------------------------------------------------------- defaults

    public function testABandWithoutTheSettingKeepsTheOldDefault(): void
    {
        [$section, $id] = $this->place();
        // A band as an older save left it: the columns at their defaults,
        // written without any height key (CtaBandRepository::DEFAULTS).
        [, $key] = explode(':', $section, 2);
        (new CtaBandRepository())->upsertSection(self::KEY, $key, ['primary_url' => '/contact', 'secondary_url' => '', 'is_active' => true]);

        $row = $this->row($section);
        self::assertSame(['auto', null, 'auto', null], [$row['min_height'], $row['min_height_px'], $row['mobile_min_height'], $row['mobile_min_height_px']]);

        $content = $this->content($section);
        self::assertSame(['auto', null, 'auto', null], [$content['height'], $content['height_px'], $content['mobile_height'], $content['mobile_height_px']]);
        self::assertGreaterThan(0, $id);
    }

    public function testANewBandIsAutomaticAndTheEditorSaysSo(): void
    {
        [$section] = $this->place();
        $xpath = $this->editor($section, $this->signIn());

        self::assertSame('auto', $this->checked($xpath, 'min_height'));
        self::assertSame('auto', $this->checked($xpath, 'mobile_min_height'));
        self::assertSame(CtaBandContent::HEIGHTS, $this->radioValues($xpath, 'min_height'));
        self::assertSame(CtaBandContent::MOBILE_HEIGHTS, $this->radioValues($xpath, 'mobile_min_height'));
        self::assertSame(2, $xpath->query('//*[@data-cta-needs-custom and @hidden]')->length, 'no pixels without Eigen hoogte');
        self::assertSame(1, $xpath->query('//input[@type="number" and @name="min_height_px" and @min="200" and @max="1000"]')->length);
        self::assertSame(1, $xpath->query('//input[@type="number" and @name="mobile_min_height_px" and @min="160" and @max="800"]')->length);
        self::assertStringContainsString('minimale hoogte', (string) $xpath->query('//*[@data-cta-height]')->item(0)?->textContent);
        self::assertSame(0, $xpath->query('//*[@data-cta-needs-image]//*[@data-cta-height]')->length, 'the height is there with or without a picture');
    }

    // ------------------------------------------------------ store and reload

    public function testEveryPresetIsStoredAndReopened(): void
    {
        [$section] = $this->place();
        $session = $this->signIn();

        foreach (['auto', 'compact', 'normal', 'tall'] as $height) {
            foreach (['auto', 'text', 'compact', 'normal', 'tall'] as $mobile) {
                $this->assertSaved($this->save($session, $section, ['min_height' => $height, 'mobile_min_height' => $mobile, 'min_height_px' => '9999', 'mobile_min_height_px' => 'abc']), $height . '/' . $mobile);
                $row = $this->row($section);
                self::assertSame([$height, null, $mobile, null], [$row['min_height'], $row['min_height_px'], $row['mobile_min_height'], $row['mobile_min_height_px']], 'a preset stores no pixels, whatever the hidden field holds');
            }
        }

        $this->assertSaved($this->save($session, $section, ['min_height' => 'tall', 'mobile_min_height' => 'text']));
        $xpath = $this->editor($section, $session);
        self::assertSame(['tall', 'text'], [$this->checked($xpath, 'min_height'), $this->checked($xpath, 'mobile_min_height')]);
        self::assertSame('tall', $this->content($section)['height']);
        self::assertStringContainsString('cta-height--tall cta-height-phone--text', $this->render($this->content($section)));
        self::assertStringContainsString('--admin-rm-desktop-ratio: 1152 / 560', $this->body($section, $session), 'the focus frame takes the band\'s shape');
    }

    public function testAnOwnDesktopHeightIsStoredAndReopened(): void
    {
        [$section] = $this->place();
        $session = $this->signIn();

        $this->assertSaved($this->save($session, $section, ['min_height' => 'custom', 'min_height_px' => '640']));
        $row = $this->row($section);
        self::assertSame(['custom', 640, 'auto', null], [$row['min_height'], (int) $row['min_height_px'], $row['mobile_min_height'], $row['mobile_min_height_px']]);

        $xpath = $this->editor($section, $session);
        self::assertSame('custom', $this->checked($xpath, 'min_height'));
        self::assertSame('640', $xpath->query('//input[@name="min_height_px"]')->item(0)?->getAttribute('value'));
        self::assertSame(1, $xpath->query('//*[@data-cta-needs-custom="min_height_px" and not(@hidden)]')->length);

        self::assertStringContainsString('style="--cta-min-height: 640px;"', $this->render($this->content($section)));

        foreach (['200', '1000'] as $edge) {
            $this->assertSaved($this->save($session, $section, ['min_height' => 'custom', 'min_height_px' => $edge]), $edge . ' is inside the range');
            self::assertSame((int) $edge, (int) $this->row($section)['min_height_px']);
        }

        $this->assertSaved($this->save($session, $section, ['min_height' => 'auto', 'min_height_px' => '640']));
        self::assertSame(['auto', null], [$this->row($section)['min_height'], $this->row($section)['min_height_px']], 'back to Automatisch');
        self::assertStringNotContainsString('cta-height', $this->render($this->content($section)), 'the band as it was');
    }

    public function testAnOwnPhoneHeightIsStoredAndReopened(): void
    {
        [$section] = $this->place();
        $session = $this->signIn();

        $this->assertSaved($this->save($session, $section, ['min_height' => 'tall', 'mobile_min_height' => 'custom', 'mobile_min_height_px' => '280']));
        $row = $this->row($section);
        self::assertSame(['tall', null, 'custom', 280], [$row['min_height'], $row['min_height_px'], $row['mobile_min_height'], (int) $row['mobile_min_height_px']]);

        $xpath = $this->editor($section, $session);
        self::assertSame('custom', $this->checked($xpath, 'mobile_min_height'));
        self::assertSame('280', $xpath->query('//input[@name="mobile_min_height_px"]')->item(0)?->getAttribute('value'));
        self::assertStringContainsString('cta-height--tall cta-height-phone--custom" style="--cta-min-height-phone: 280px;"', $this->render($this->content($section)));
    }

    // ------------------------------------------------------------ refused

    public function testAnInvalidOrOutOfRangeValueIsRefusedAtItsField(): void
    {
        [$section] = $this->place();
        $session = $this->signIn();
        $this->assertSaved($this->save($session, $section, ['min_height' => 'normal']));
        $before = $this->row($section);

        foreach ([
            'min_height' => [['min_height' => '100vh']],
            'mobile_min_height' => [['mobile_min_height' => 'huge']],
            'min_height_px' => [
                ['min_height' => 'custom', 'min_height_px' => '199'],
                ['min_height' => 'custom', 'min_height_px' => '1001'],
                ['min_height' => 'custom', 'min_height_px' => '480px'],
                ['min_height' => 'custom', 'min_height_px' => '480.5'],
                ['min_height' => 'custom', 'min_height_px' => '-480'],
                ['min_height' => 'custom', 'min_height_px' => ''],
                ['min_height' => 'custom', 'min_height_px' => '480;color:red'],
            ],
            'mobile_min_height_px' => [
                ['mobile_min_height' => 'custom', 'mobile_min_height_px' => '159'],
                ['mobile_min_height' => 'custom', 'mobile_min_height_px' => '801'],
            ],
        ] as $field => $attempts) {
            foreach ($attempts as $changes) {
                $label = $field . ' ' . json_encode($changes);
                $this->assertRefused($this->save($session, $section, $changes), $label);
                self::assertArrayHasKey($field, (array) $this->accounts->read($session, 'admin_cta_band_field_errors'), $label . ': the message is at its field');
                self::assertSame($before, $this->row($section), $label . ': nothing stored');
                $this->editor($section, $session); // hand the flash back
            }
        }

        // The refused form comes back as typed, so the number can be corrected.
        $this->assertRefused($this->save($session, $section, ['min_height' => 'custom', 'min_height_px' => '1500']));
        $xpath = $this->editor($section, $session);
        self::assertSame('custom', $this->checked($xpath, 'min_height'));
        self::assertSame('1500', $xpath->query('//input[@name="min_height_px"]')->item(0)?->getAttribute('value'));
        self::assertSame('true', $xpath->query('//input[@name="min_height_px"]')->item(0)?->getAttribute('aria-invalid'));
        self::assertSame(1, $xpath->query('//form[@data-save-bar-unsaved]')->length);
    }

    public function testAFormWithoutTheHeightKeepsWhatIsStored(): void
    {
        [$section] = $this->place();
        $session = $this->signIn();
        $this->assertSaved($this->save($session, $section, ['min_height' => 'custom', 'min_height_px' => '720', 'mobile_min_height' => 'compact']));

        $fields = $this->fields($section);
        unset($fields['min_height'], $fields['min_height_px'], $fields['mobile_min_height'], $fields['mobile_min_height_px']);
        $this->assertSaved($this->post($session, $fields));

        $row = $this->row($section);
        self::assertSame(['custom', 720, 'compact'], [$row['min_height'], (int) $row['min_height_px'], $row['mobile_min_height']]);
    }

    // ---------------------------------------- picture, focus, zoom, buttons

    public function testTheHeightLeavesThePictureItsFocusAndZoomAndTheButtonStyles(): void
    {
        [$section] = $this->place();
        $session = $this->signIn();
        $picture = $this->libraryItem();

        $withPicture = [
            'background_media_id' => (string) $picture,
            'background_focus_x' => '20',
            'background_focus_y' => '80',
            'background_zoom' => '140',
            'background_mobile_focus_own' => '1',
            'background_mobile_focus_x' => '60',
            'background_mobile_focus_y' => '10',
            'background_mobile_zoom' => '120',
        ];
        $this->assertSaved($this->save($session, $section, $withPicture));
        $before = $this->row($section);
        self::assertSame([20, 80, 140, 60, 10, 120], $this->presentationOf($before));

        foreach ([['min_height' => 'tall'], ['min_height' => 'custom', 'min_height_px' => '900', 'mobile_min_height' => 'custom', 'mobile_min_height_px' => '700'], ['min_height' => 'auto']] as $height) {
            // The editor posts every field again, the picture's as they are.
            $this->assertSaved($this->save($session, $section, $height + $withPicture));
            $after = $this->row($section);
            self::assertSame($this->presentationOf($before), $this->presentationOf($after), json_encode($height) . ': focus and zoom untouched');
            self::assertSame($picture, (int) $after['background_media_id']);
            self::assertSame(
                [$before['primary_button_style_id'], $before['secondary_button_style_id']],
                [$after['primary_button_style_id'], $after['secondary_button_style_id']],
                'the button styles are untouched'
            );

            $html = $this->render($this->content($section));
            self::assertStringContainsString('object-position: 20% 80%', $html);
            self::assertStringContainsString('scale: 1.4', $html);
            self::assertStringContainsString('<a href="/contact" class="btn">Contact', $html, 'the first button as always');
        }
    }

    // ------------------------------------------------------------ lifecycle

    public function testANewBandFollowsTheDraftLifecycle(): void
    {
        $page = (array) (new PageRepository())->findById($this->pageId);
        $session = $this->signIn();

        // Opslaan: the draft joins its page with the height it was given.
        $draft = ContentBlockDrafts::open($page, 'cta_band');
        $section = self::KEY . ':' . $draft['section_key'];
        self::assertSame([], $this->sectionIds(), 'a draft is not on the page');
        self::assertStringContainsString('data-block-draft', $this->body($section, $session));

        $saved = $this->save($session, $section, ['min_height' => 'normal', 'mobile_min_height' => 'text']);
        self::assertMatchesRegularExpression(SavedRedirect::PATTERN, $saved['location']);
        self::assertCount(1, $this->sectionIds(), 'exactly one block');
        self::assertSame(['normal', 'text'], [$this->row($section)['min_height'], $this->row($section)['mobile_min_height']]);

        // Annuleren: nothing of the second draft is left.
        $second = ContentBlockDrafts::open($page, 'cta_band');
        $secondSection = self::KEY . ':' . $second['section_key'];
        $cancelled = self::$server->request('POST', '/api/admin/discard-block-draft.php', $session, [
            'csrf_token' => (string) $this->accounts->read($session, 'csrf_token'),
            'section_type' => 'cta_band',
            'section' => $secondSection,
        ]);
        self::assertSame(302, $cancelled['status']);
        self::assertNull((new CtaBandRepository())->findBySlugAndKey(self::KEY, (string) $second['section_key']));
        self::assertCount(1, $this->sectionIds(), 'the page keeps only the saved band');
    }

    // ---------------------------------------------------------------- helpers

    /** @return array{string, int} the section parameter and the band id */
    private function place(): array
    {
        [$id, $key] = SectionRegistry::create('cta_band', self::KEY);
        (new PageSectionRepository())->create($this->pageId, self::KEY, 'cta_band', $key, $id);
        $this->clearCaches();

        return [self::KEY . ':' . $key, (int) $id];
    }

    /** @return array<string, mixed> a complete form: one button to /contact, every choice at its default */
    private function fields(string $section): array
    {
        return [
            'section' => $section,
            'is_active' => '1',
            'eyebrow' => '',
            'title' => 'Kort',
            'lead' => '',
            'primary_label' => 'Contact',
            'secondary_label' => '',
            'primary_link_type' => 'url',
            'primary_url' => '/contact',
            'secondary_link_type' => 'none',
            'secondary_url' => '',
            'primary_button_style_id' => '',
            'secondary_button_style_id' => '',
            'content_align' => 'center',
            'lead_width' => 'narrow',
            'background_overlay' => 'medium',
            'text_panel_opacity' => 'strong',
            'background_presentation' => '1',
            'background_focus_x' => '50',
            'background_focus_y' => '50',
            'background_mobile_source' => 'desktop',
            'background_media_id' => '',
            'min_height' => 'auto',
            'min_height_px' => '',
            'mobile_min_height' => 'auto',
            'mobile_min_height_px' => '',
        ];
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
            'language_code' => 'nl',
        ]);
        $this->clearCaches();

        return $response;
    }

    private function assertSaved(array $response, string $message = ''): void
    {
        self::assertSame(302, $response['status'], $message);
        self::assertMatchesRegularExpression(SavedRedirect::PATTERN, $response['location'], $message . ' ' . $response['location']);
    }

    private function assertRefused(array $response, string $message = ''): void
    {
        self::assertSame(302, $response['status'], $message);
        self::assertStringStartsWith('/admin/cta-band.php?section=', $response['location'], $message);
        self::assertStringNotContainsString('saved=', $response['location'], $message);
    }

    /** @return array<string, mixed> */
    private function row(string $section): array
    {
        [, $key] = explode(':', $section, 2);
        $row = (new CtaBandRepository())->findBySlugAndKey(self::KEY, $key);
        self::assertNotNull($row);
        unset($row['updated_at'], $row['created_at']);

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return list<int|null> focus x/y, zoom, phone focus x/y, phone zoom
     */
    private function presentationOf(array $row): array
    {
        return array_map(
            static fn (mixed $value): ?int => $value === null ? null : (int) $value,
            [$row['background_focus_x'], $row['background_focus_y'], $row['background_zoom'], $row['background_mobile_focus_x'], $row['background_mobile_focus_y'], $row['background_mobile_zoom']]
        );
    }

    /** @return array<string, mixed> */
    private function content(string $section): array
    {
        [, $key] = explode(':', $section, 2);
        CtaBandContent::clearCache();

        return CtaBandContent::forSection(self::KEY, $key);
    }

    private function signIn(): string
    {
        [$session] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);

        return $session;
    }

    private function body(string $section, string $session): string
    {
        return self::$server->request('GET', '/admin/cta-band.php?section=' . urlencode($section), $session)['body'];
    }

    private function editor(string $section, string $session): \DOMXPath
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">' . $this->body($section, $session));
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new \DOMXPath($document);
    }

    private function checked(\DOMXPath $xpath, string $name): string
    {
        return (string) $xpath->query('//input[@type="radio" and @name="' . $name . '" and @checked]')->item(0)?->getAttribute('value');
    }

    /** @return list<string> */
    private function radioValues(\DOMXPath $xpath, string $name): array
    {
        $values = [];
        foreach ($xpath->query('//input[@type="radio" and @name="' . $name . '"]') as $radio) {
            $values[] = $radio->getAttribute('value');
        }

        return $values;
    }

    /** @param array<string, mixed> $content */
    private function render(array $content): string
    {
        ob_start();
        try {
            render_section_cta_band($content);
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }

    /** @return list<int> */
    private function sectionIds(): array
    {
        return array_map(static fn (array $row): int => (int) $row['id'], (new PageSectionRepository())->findForPage($this->pageId));
    }

    /** A media row for a file that does not exist: neither the form nor the save reads the disk. */
    private function libraryItem(): int
    {
        $id = (new MediaRepository())->create([
            'path' => 'assets/media/__cta_height_' . bin2hex(random_bytes(4)) . '__.jpg',
            'original_filename' => 'achtergrond.jpg',
            'mime_type' => 'image/jpeg',
            'alt_text' => '',
        ]);
        $this->mediaIds[] = $id;
        MediaService::clearCache();

        return $id;
    }

    private function removePage(): void
    {
        $page = (new PageRepository())->findByContentKey(self::KEY);
        if ($page !== null) {
            PageService::delete($page);
        }
        $this->clearCaches();
    }

    private function clearCaches(): void
    {
        BlockLocalization::clearCache();
        CtaBandContent::clearCache();
        MediaService::clearCache();
        PageContent::clearCache();
    }
}

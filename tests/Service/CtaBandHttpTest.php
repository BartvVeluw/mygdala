<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\CtaBandRepository;
use App\Repository\MediaRepository;
use App\Repository\PageRepository;
use App\Repository\PageSectionRepository;
use App\Service\AdminPermissions;
use App\Service\Blocks\BlockLocalization;
use App\Service\CtaBandContent;
use App\Service\Language\SiteLanguages;
use App\Service\LinkResolver;
use App\Service\Media\MediaService;
use App\Service\Media\MediaUsageRegistry;
use App\Service\PageContent;
use App\Service\PageLocalization;
use App\Service\PagePath;
use App\Service\PageService;
use App\Service\PageTranslation;
use App\Service\Routing\LinkTargets;
use App\Service\Routing\RequestLanguage;
use App\Service\SectionRegistry;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestSession;
use Tests\Support\BuiltInServer;
use Tests\Support\PageFixture;

require_once dirname(__DIR__, 2) . '/partials/section-cta-band.php';

/**
 * CTA 2.0 through the real editor and endpoint, over PHP's built-in server,
 * and what CtaBandContent then gives the page:
 *
 *   - every presentation choice is stored from its closed list, an unknown one
 *     is refused at its field, and a form without it keeps what is stored;
 *   - the background picture is a Media Library picture: chosen, replaced,
 *     removed, refused when unknown or a video, reported as a usage and
 *     protected from deletion while chosen;
 *   - no, one or two buttons through the shared link field: "Geen knop" stores
 *     no address, the second button goes with the first, a destination needs
 *     its label in the default language, and a stale address never brings a
 *     button back;
 *   - a button to a page follows its nested address, its language and a slug
 *     change; an unknown page and a dangerous address are refused;
 *   - a band from before CTA 2.0 (no link types, no presentation) keeps its
 *     buttons and its look.
 *
 * The page, its bands, the accounts and the library items are this test's own
 * and are removed in tearDown(). Without a server the test skips itself.
 */
final class CtaBandHttpTest extends TestCase
{
    private const KEY = 'zz-cta-2-test';
    private const PARENT = 'zz-cta-2-ouder';
    private const CHILD = 'zz-cta-2-kind';
    private const ENDPOINT = '/api/admin/update-cta-band.php';

    private static ?BuiltInServer $server = null;

    private AdminTestSession $accounts;

    private int $pageId = 0;

    private int $childId = 0;

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

        $this->removePages();
        $this->pageId = PageFixture::create(
            ['content_key' => self::KEY, 'slug' => self::KEY, 'status' => PageContent::STATUS_PUBLISHED],
            'CTA-test'
        );
    }

    protected function tearDown(): void
    {
        $this->removePages();

        foreach ($this->mediaIds as $id) {
            (new MediaRepository())->delete($id);
        }
        $this->mediaIds = [];

        $this->accounts->forget();
        RequestLanguage::reset();
        $this->clearCaches();
    }

    // ------------------------------------------------------------ presentation

    public function testANewBandLooksLikeEveryBandBefore(): void
    {
        [$section] = $this->place();
        $content = $this->content($section);

        self::assertSame(
            ['center', 'narrow', false, null, '', '/'],
            [$content['align'], $content['lead_width'], $content['full_width'], $content['background'], $content['panel'], $content['primary_url']]
        );
        self::assertSame('url', $this->row($section)['primary_link_type'], 'a new band starts with one button to the site root');
    }

    public function testEveryChoiceIsStoredFromItsList(): void
    {
        [$section] = $this->place();
        $session = $this->signIn();

        foreach (CtaBandContent::ALIGNMENTS as $align) {
            foreach (CtaBandContent::LEAD_WIDTHS as $width) {
                $this->assertSaved($this->save($session, $section, ['content_align' => $align, 'lead_width' => $width]), $align . '/' . $width);
                self::assertSame([$align, $width], [$this->row($section)['content_align'], $this->row($section)['lead_width']]);
            }
        }

        foreach (CtaBandContent::OVERLAYS as $overlay) {
            $this->assertSaved($this->save($session, $section, ['background_overlay' => $overlay]));
            self::assertSame($overlay, $this->row($section)['background_overlay']);
        }

        foreach (CtaBandContent::PANEL_OPACITIES as $opacity) {
            $this->assertSaved($this->save($session, $section, ['text_panel' => '1', 'text_panel_opacity' => $opacity]));
            self::assertSame($opacity, $this->content($section)['panel']);
        }

        $this->assertSaved($this->save($session, $section, ['text_panel_opacity' => 'subtle']), 'panel off');
        self::assertSame('', $this->content($section)['panel'], 'the switch decides, the opacity is kept for next time');
        self::assertSame('subtle', $this->row($section)['text_panel_opacity']);

        $this->assertSaved($this->save($session, $section, ['full_width' => '1']));
        self::assertTrue($this->content($section)['full_width']);
        $this->assertSaved($this->save($session, $section, []));
        self::assertFalse($this->content($section)['full_width']);
    }

    public function testAnUnknownWordIsRefusedAtItsFieldAndNothingIsStored(): void
    {
        [$section] = $this->place();
        $session = $this->signIn();
        $before = $this->row($section);

        foreach ([
            'content_align' => 'justify',
            'lead_width' => '900px',
            'background_overlay' => '0.3',
            'text_panel_opacity' => 'rgba(0,0,0,.5)',
        ] as $field => $value) {
            $response = $this->save($session, $section, [$field => $value]);
            $this->assertRefused($response, $field);
            self::assertArrayHasKey($field, (array) $this->accounts->read($session, 'admin_cta_band_field_errors'), $field . ': the message is at its field');
            self::assertSame($before, $this->row($section), $field . ': nothing stored');
        }

        // The background's focus point (Responsive Media 2.0) is two numbers:
        // anything else is refused at the presentation's own field.
        $response = $this->save($session, $section, ['background_focus_x' => '10% 20%']);
        $this->assertRefused($response, 'background_focus_x');
        self::assertArrayHasKey('presentation.focus', (array) $this->accounts->read($session, 'admin_cta_band_field_errors'));
        self::assertSame($before, $this->row($section), 'background_focus_x: nothing stored');
    }

    public function testAFormWithoutAChoiceKeepsWhatIsStored(): void
    {
        [$section] = $this->place();
        $session = $this->signIn();

        $this->assertSaved($this->save($session, $section, ['content_align' => 'right', 'lead_width' => 'wide']));
        $fields = $this->fields($section);
        unset($fields['content_align'], $fields['lead_width']);
        $this->assertSaved($this->post($session, $fields));

        self::assertSame(['right', 'wide'], [$this->row($section)['content_align'], $this->row($section)['lead_width']]);
    }

    // ------------------------------------------------------------- background

    public function testABackgroundPictureIsChosenReplacedAndRemoved(): void
    {
        [$section] = $this->place();
        $session = $this->signIn();
        $first = $this->libraryItem('image/jpeg', 'jpg');
        $second = $this->libraryItem('image/webp', 'webp');

        $this->assertSaved($this->save($session, $section, ['background_media_id' => (string) $first, 'background_focus_x' => '50', 'background_focus_y' => '0']));
        self::assertSame($first, (int) $this->row($section)['background_media_id']);
        $content = $this->content($section);
        self::assertSame(MediaService::find($first)?->publicPath(), $content['background']['image_path']);
        self::assertSame('50% 0%', $content['picture']['position']);

        $html = $this->render($content);
        self::assertStringContainsString('<img src="' . MediaService::find($first)?->publicPath() . '" alt=""', $html, 'decorative');

        $this->assertSaved($this->save($session, $section, ['background_media_id' => (string) $second]));
        self::assertSame($second, (int) $this->row($section)['background_media_id'], 'replaced');

        $this->assertSaved($this->save($session, $section, ['background_media_id' => '']));
        self::assertNull($this->row($section)['background_media_id'], 'removed');
        self::assertNull($this->content($section)['background']);
        self::assertStringNotContainsString('cta-band__media', $this->render($this->content($section)));
    }

    public function testAnUnknownIdOrAVideoIsNoBackground(): void
    {
        [$section] = $this->place();
        $session = $this->signIn();
        $video = $this->libraryItem('video/mp4', 'mp4');

        foreach (['999999999' => 'unknown', (string) $video => 'a video', 'abc' => 'not a number'] as $posted => $what) {
            $this->assertRefused($this->save($session, $section, ['background_media_id' => $posted]), $what);
            self::assertNull($this->row($section)['background_media_id'], $what);
        }
    }

    public function testAChosenBackgroundIsAUsageAndCannotBeDeleted(): void
    {
        [$section] = $this->place();
        $session = $this->signIn();
        $picture = $this->libraryItem('image/jpeg', 'jpg');

        $this->assertSaved($this->save($session, $section, ['background_media_id' => (string) $picture]));

        $service = new MediaService(new MediaRepository());
        $usages = $service->usagesOf($picture);
        self::assertCount(1, $usages);
        self::assertSame('Oproep met knop (achtergrond) op "' . self::KEY . '"', $usages[0]->label);
        self::assertSame('/admin/cta-band.php?section=' . rawurlencode($section), $usages[0]->editUrl);
        self::assertSame([$picture => 1], MediaUsageRegistry::countsFor([$picture]));

        $refused = $service->delete($picture);
        self::assertFalse($refused['deleted']);
        self::assertSame('in_use', $refused['reason']);

        // The foreign key is the second line of defence.
        $this->expectException(\PDOException::class);
        Database::connection()->prepare('DELETE FROM media WHERE id = ?')->execute([$picture]);
    }

    public function testABackgroundLetGoOfIsDeletableAgain(): void
    {
        [$section] = $this->place();
        $session = $this->signIn();
        $picture = $this->libraryItem('image/jpeg', 'jpg');

        $this->assertSaved($this->save($session, $section, ['background_media_id' => (string) $picture]));
        $this->assertSaved($this->save($session, $section, ['background_media_id' => '']));
        MediaService::clearCache();

        self::assertSame([], (new MediaService(new MediaRepository()))->usagesOf($picture));
    }

    // ---------------------------------------------------------------- buttons

    public function testNoOneOrTwoButtons(): void
    {
        [$section] = $this->place();
        $session = $this->signIn();

        $this->assertSaved($this->save($session, $section, ['primary_link_type' => 'none', 'primary_label' => '']), 'no button');
        $content = $this->content($section);
        self::assertSame(['', ''], [$content['primary_url'], $content['secondary_url']]);
        self::assertStringNotContainsString('<a ', $this->render($content));

        $this->assertSaved($this->save($session, $section, []), 'one button');
        self::assertSame(['/contact', ''], [$this->content($section)['primary_url'], $this->content($section)['secondary_url']]);

        $this->assertSaved($this->save($session, $section, ['secondary_link_type' => 'url', 'secondary_url' => '/werk', 'secondary_label' => 'Werk']), 'two buttons');
        $content = $this->content($section);
        self::assertSame(['/contact', '/werk'], [$content['primary_url'], $content['secondary_url']]);
        self::assertSame(2, substr_count($this->render($content), '<a '));
    }

    public function testNoFirstButtonMeansNoSecondOneStored(): void
    {
        [$section] = $this->place();
        $session = $this->signIn();

        $this->assertSaved($this->save($session, $section, [
            'primary_link_type' => 'none', 'primary_url' => '/oud', 'primary_label' => '',
            'secondary_link_type' => 'url', 'secondary_url' => '/werk', 'secondary_label' => 'Werk',
        ]));

        $row = $this->row($section);
        self::assertSame([null, '', null, null], [$row['primary_link_type'], $row['primary_url'], $row['secondary_link_type'], $row['secondary_url']]);
        self::assertSame('', $this->content($section)['secondary_url']);
    }

    public function testASecondButtonStoredBehindAMissingFirstOneNeverRenders(): void
    {
        [$section, $id] = $this->place();
        BlockLocalization::save('cta_bands', $id, 'nl', ['primary_label' => '', 'secondary_label' => 'Werk']);
        Database::connection()->prepare("UPDATE cta_bands SET primary_link_type = NULL, primary_url = '', secondary_link_type = 'url', secondary_url = '/werk' WHERE id = ?")->execute([$id]);
        $this->clearCaches();

        self::assertSame(['', ''], [$this->content($section)['secondary_url'], $this->content($section)['secondary_label']]);
    }

    public function testGeenKnopStoresNoAddressAndIgnoresTheHiddenLabel(): void
    {
        [$section] = $this->place();
        $session = $this->signIn();

        $this->assertSaved($this->save($session, $section, ['secondary_link_type' => 'none', 'secondary_url' => '/blijft-niet', 'secondary_label' => 'Oud']));
        $row = $this->row($section);
        self::assertSame([null, null], [$row['secondary_link_type'], $row['secondary_url']], 'no address left for LinkChoice::storedType() to find');
        self::assertSame('', $this->content($section)['secondary_url']);
        self::assertSame('none', $this->chosenKind($session, $section, 'secondary_link_type'), 'the editor reopens on Geen knop');
    }

    public function testADestinationNeedsItsLabelInTheDefaultLanguage(): void
    {
        [$section] = $this->place();
        $session = $this->signIn();

        $this->assertRefused($this->save($session, $section, ['primary_label' => '']), 'first button without a text');
        $this->assertRefused($this->save($session, $section, ['secondary_link_type' => 'url', 'secondary_url' => '/werk', 'secondary_label' => '']), 'second button without a text');
        $this->assertRefused($this->save($session, $section, ['secondary_link_type' => 'url', 'secondary_url' => '/werk'], 'en'), 'an English save cannot add a second button the default language has no text for');
    }

    public function testAStaleAddressWithoutALabelIsNoButton(): void
    {
        [$section, $id] = $this->place();
        // A legacy row: no types, addresses on both buttons, only the first has a label.
        BlockLocalization::save('cta_bands', $id, 'nl', ['title' => 'Oud', 'primary_label' => 'Contact', 'secondary_label' => '']);
        Database::connection()->prepare("UPDATE cta_bands SET primary_link_type = NULL, primary_url = '/contact', secondary_link_type = NULL, secondary_url = '/oud' WHERE id = ?")->execute([$id]);
        $this->clearCaches();

        $content = $this->content($section);
        self::assertSame(['/contact', '', ''], [$content['primary_url'], $content['secondary_url'], $content['secondary_label']]);
    }

    public function testABandFromBeforeCta2KeepsItsButtonsAndItsLook(): void
    {
        [$section, $id] = $this->place();
        BlockLocalization::save('cta_bands', $id, 'nl', ['title' => 'Oud', 'lead' => 'Tekst', 'primary_label' => 'Contact', 'secondary_label' => 'Werk']);
        Database::connection()->prepare("UPDATE cta_bands SET primary_link_type = NULL, primary_url = '/contact', secondary_link_type = NULL, secondary_url = '/werk' WHERE id = ?")->execute([$id]);
        $this->clearCaches();

        $html = $this->render($this->content($section));
        self::assertStringContainsString('class="cta-band cta-band--card cta-band--align-center cta-band--lead-narrow"', $html);
        self::assertStringContainsString('<a href="/contact" class="btn">Contact', $html);
        self::assertStringContainsString('<a href="/werk" class="btn btn--ghost">Werk</a>', $html);
        self::assertSame('url', $this->chosenKind($this->signIn(), $section, 'primary_link_type'), 'the editor opens an old address as an own address');
    }

    // ------------------------------------------------------------------ links

    public function testAButtonToAPageFollowsItsNestedAddressItsLanguageAndASlugChange(): void
    {
        [$section] = $this->place();
        $session = $this->signIn();
        $this->pages();

        $this->assertSaved($this->save($session, $section, ['primary_link_type' => 'page', 'primary_link_target' => ['page' => (string) $this->childId], 'primary_url' => '']));
        $row = $this->row($section);
        self::assertSame(['page', $this->childId], [$row['primary_link_type'], (int) $row['primary_link_target_id']]);

        self::assertSame('/' . self::PARENT . '/' . self::CHILD, $this->content($section)['primary_url'], 'Pages 2.0 nesting');

        RequestLanguage::set('en', true);
        $this->clearCaches();
        self::assertSame('/en/' . self::PARENT . '-en/' . self::CHILD . '-en', $this->content($section)['primary_url'], 'the English address');
        RequestLanguage::reset();

        PageLocalization::save($this->childId, 'nl', [PageTranslation::TITLE => 'Kind'], 'zz-cta-2-nieuw-kind');
        $this->clearCaches();
        self::assertSame('/' . self::PARENT . '/zz-cta-2-nieuw-kind', $this->content($section)['primary_url'], 'a slug change is followed without a save');
    }

    public function testAnUnknownPageOrADangerousAddressIsRefused(): void
    {
        [$section] = $this->place();
        $session = $this->signIn();
        $before = $this->row($section);

        $this->assertRefused($this->save($session, $section, ['primary_link_type' => 'page', 'primary_link_target' => ['page' => '999999999']]), 'unknown page');
        $this->assertRefused($this->save($session, $section, ['primary_link_type' => 'url', 'primary_url' => 'javascript:alert(1)']), 'javascript:');
        $this->assertRefused($this->save($session, $section, ['secondary_link_type' => 'url', 'secondary_url' => 'data:text/html,x', 'secondary_label' => 'X']), 'data:');
        $this->assertRefused($this->save($session, $section, ['primary_link_type' => 'App\\Evil']), 'an unknown kind');

        self::assertSame($before, $this->row($section));
    }

    // ----------------------------------------------------------------- editor

    public function testTheEditorGroupsTheChoicesAndUsesTheSharedFields(): void
    {
        [$section] = $this->place();
        $xpath = $this->xpath(self::$server->request('GET', '/admin/cta-band.php?section=' . urlencode($section), $this->signIn())['body']);

        self::assertSame(5, $xpath->query('//form[@data-cta-band-form]/section[contains(@class, "admin-card")]/h2')->length, 'Inhoud, Weergave, Achtergrond, Tekstvlak, Knoppen');
        self::assertSame(0, $xpath->query('//input[@type="file" and not(ancestor::*[@data-media-modal])]')->length, 'no upload field of its own: only the library modal uploads');
        self::assertSame(1, $xpath->query('//*[@data-media-picker]//input[@name="background_media_id"]')->length, 'the Media Picker');
        self::assertSame(2, $xpath->query('//select[@data-nav-link-type]')->length, 'the shared link field, twice');
        self::assertSame(1, $xpath->query('//*[@data-nav-link-group]//*[@data-nav-link-group]//select[@name="secondary_link_type"]')->length, 'the second button lives inside the first');

        foreach (['content_align' => CtaBandContent::ALIGNMENTS, 'lead_width' => CtaBandContent::LEAD_WIDTHS, 'background_overlay' => CtaBandContent::OVERLAYS, 'text_panel_opacity' => CtaBandContent::PANEL_OPACITIES] as $name => $list) {
            $values = [];
            foreach ($xpath->query('//input[@type="radio" and @name="' . $name . '"]') as $radio) {
                $values[] = $radio->getAttribute('value');
            }
            self::assertSame($list, $values, $name . ': exactly the closed list');
        }

        self::assertSame(1, $xpath->query('//*[@data-cta-needs-image and @hidden]')->length, 'without a picture the overlay and focus hide');
        self::assertSame(1, $xpath->query('//*[@data-cta-needs-panel and @hidden]')->length, 'without a panel its opacity hides');
    }

    public function testARefusedSaveHandsEverythingBack(): void
    {
        [$section] = $this->place();
        $session = $this->signIn();

        $this->assertRefused($this->save($session, $section, ['content_align' => 'right', 'lead_width' => 'full', 'text_panel' => '1', 'primary_label' => '']));
        $xpath = $this->xpath(self::$server->request('GET', '/admin/cta-band.php?section=' . urlencode($section), $session)['body']);

        self::assertSame('right', $xpath->query('//input[@name="content_align" and @checked]')->item(0)?->getAttribute('value'));
        self::assertSame('full', $xpath->query('//input[@name="lead_width" and @checked]')->item(0)?->getAttribute('value'));
        self::assertSame(1, $xpath->query('//input[@name="text_panel" and @checked]')->length);
        self::assertSame(1, $xpath->query('//form[@data-save-bar-unsaved]')->length);
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
            'title' => 'Samenwerken?',
            'lead' => '',
            'primary_label' => 'Contact',
            'secondary_label' => '',
            'primary_link_type' => 'url',
            'primary_url' => '/contact',
            'secondary_link_type' => 'none',
            'secondary_url' => '',
            'content_align' => 'center',
            'lead_width' => 'narrow',
            'background_overlay' => 'medium',
            'text_panel_opacity' => 'strong',
            'background_presentation' => '1',
            'background_focus_x' => '50',
            'background_focus_y' => '50',
            'background_mobile_source' => 'desktop',
            'background_media_id' => '',
        ];
    }

    /** @param array<string, mixed> $changes */
    private function save(string $session, string $section, array $changes, string $language = 'nl'): array
    {
        return $this->post($session, $changes + $this->fields($section), $language);
    }

    /**
     * @param array<string, mixed> $fields
     * @return array{status: int, location: string, body: string, headers: string}
     */
    private function post(string $session, array $fields, string $language = 'nl'): array
    {
        $response = self::$server->request('POST', self::ENDPOINT, $session, $fields + [
            'csrf_token' => (string) $this->accounts->read($session, 'csrf_token'),
            'language_code' => $language,
        ]);
        $this->clearCaches();

        return $response;
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

    /** A media row for a file that does not exist: neither the form nor the save reads the disk. */
    private function libraryItem(string $mimeType, string $extension): int
    {
        $id = (new MediaRepository())->create([
            'path' => 'assets/media/__cta_2_' . bin2hex(random_bytes(4)) . '__.' . $extension,
            'original_filename' => 'achtergrond.' . $extension,
            'mime_type' => $mimeType,
            'alt_text' => '',
        ]);
        $this->mediaIds[] = $id;
        MediaService::clearCache();

        return $id;
    }

    /** A published parent with a published child under it, both with an English slug. */
    private function pages(): void
    {
        $parentId = PageFixture::create(['content_key' => self::PARENT, 'slug' => self::PARENT, 'status' => PageContent::STATUS_PUBLISHED], 'Ouder');
        $this->childId = PageFixture::create(['content_key' => self::CHILD, 'slug' => self::CHILD, 'status' => PageContent::STATUS_PUBLISHED, 'parent_id' => $parentId], 'Kind');
        PageLocalization::save($parentId, 'en', [PageTranslation::TITLE => 'Parent'], self::PARENT . '-en');
        PageLocalization::save($this->childId, 'en', [PageTranslation::TITLE => 'Child'], self::CHILD . '-en');
        $this->clearCaches();
    }

    /** The link kind the editor's select opens on. */
    private function chosenKind(string $session, string $section, string $name): string
    {
        $xpath = $this->xpath(self::$server->request('GET', '/admin/cta-band.php?section=' . urlencode($section), $session)['body']);

        return (string) $xpath->query('//select[@name="' . $name . '"]/option[@selected]')->item(0)?->getAttribute('value');
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
        BlockLocalization::clearCache();
        CtaBandContent::clearCache();
        MediaService::clearCache();
        PageContent::clearCache();
        PageLocalization::clearCache();
        PagePath::clearCache();
        LinkResolver::clearCache();
        LinkTargets::reset();
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

    private function removePages(): void
    {
        foreach ([self::CHILD, self::PARENT, self::KEY] as $contentKey) {
            $page = (new PageRepository())->findByContentKey($contentKey);
            if ($page !== null) {
                PageService::delete($page);
            }
        }

        PageContent::clearCache();
    }
}

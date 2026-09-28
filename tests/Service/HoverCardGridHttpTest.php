<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\HoverCardGridRepository;
use App\Repository\MediaRepository;
use App\Repository\PageRepository;
use App\Repository\PageSectionRepository;
use App\Service\AdminPermissions;
use App\Service\Blocks\BlockLocalization;
use App\Service\HoverCardGridContent;
use App\Service\Language\SiteLanguages;
use App\Service\Media\MediaService;
use App\Service\Media\MediaUsageRegistry;
use App\Service\PageContent;
use App\Service\PageService;
use App\Service\Routing\RequestLanguage;
use App\Service\SectionRegistry;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestSession;
use Tests\Support\BuiltInServer;
use Tests\Support\PageFixture;

require_once dirname(__DIR__, 2) . '/partials/section-hover-card-grid.php';

/**
 * Hover kaarten grid through the real editor and endpoint, over PHP's
 * built-in server, and what HoverCardGridContent then gives the page:
 *
 *   - the four guards and the `<page>:<key>` gate;
 *   - a new grid is empty and renders nothing; the heading is optional;
 *   - every choice is a closed list: an unknown word is refused at its
 *     field, a form without the field keeps what is stored;
 *   - a card needs a picture of the library (never a video), its second
 *     picture is optional, its words are optional, and a card with a link
 *     needs a title or a link label; "Geen link" stores no address;
 *   - cards keep their id, their order and their words per language, and a
 *     removed card takes its words along;
 *   - both pictures are usages and cannot be deleted;
 *   - the editor: every card folds, the veil choice only for overlay cards,
 *     no upload field; a refused save hands everything back.
 *
 * The page, its grids, the accounts and the library rows are this test's own
 * and are removed in tearDown(). Without a server the test skips itself.
 */
final class HoverCardGridHttpTest extends TestCase
{
    private const KEY = 'zz-hover-card-grid-test';
    private const ENDPOINT = '/api/admin/update-hover-card-grid.php';

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

        $this->removePage();
        PageFixture::create(['content_key' => self::KEY, 'slug' => self::KEY, 'status' => PageContent::STATUS_PUBLISHED], 'Hover-kaarten-test');
    }

    protected function tearDown(): void
    {
        $this->removePage();

        foreach ($this->mediaIds as $id) {
            (new MediaRepository())->delete($id);
        }
        $this->mediaIds = [];

        RequestLanguage::reset();
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
        self::assertSame(404, self::$server->request('GET', '/admin/hover-card-grid.php?section=' . urlencode(self::KEY . ':custom-nothere'), $session)['status']);
    }

    // ----------------------------------------------------------- the grid

    public function testANewGridIsEmptyAndRendersNothing(): void
    {
        [$section] = $this->place();
        $row = $this->row($section);

        self::assertSame(
            ['overlay', 'rounded', '3', 'medium', 'normal', 'left', 1],
            [$row['layout'], $row['shape'], $row['columns'], $row['overlay'], $row['effect'], $row['header_align'], (int) $row['is_active']]
        );
        self::assertSame([], (new HoverCardGridRepository())->findItemsByGridId((int) $row['id']));
        self::assertSame([], $this->content($section)['cards']);
        self::assertSame('', trim($this->render($this->content($section))));
    }

    public function testAHeadingAndCardsAreSavedAndShownWithTheLibrarysAltText(): void
    {
        [$section] = $this->place();
        $session = $this->signIn();
        $first = $this->libraryItem('image/jpeg', 'jpg', 'Een werkbank');
        $second = $this->libraryItem('image/webp', 'webp', 'Tweede beeld');
        $third = $this->libraryItem('image/png', 'png', 'Derde beeld');

        $this->assertSaved($this->save($session, $section, [
            'eyebrow' => '', 'title' => 'Onze diensten', 'lead' => '',
            'cards' => [
                'new0' => $this->card(['media_id' => (string) $first, 'hover_media_id' => (string) $second, 'badge' => 'Nieuw', 'title' => 'Maatwerk', 'body' => 'Precies zoals jij het wilt.', 'link_type' => 'url', 'link_url' => '/maatwerk', 'link_label' => 'Bekijk']),
                'new1' => $this->card(['media_id' => (string) $third]),
            ],
        ]));

        $content = $this->content($section);
        self::assertSame(['', 'Onze diensten', ''], [$content['eyebrow'], $content['title'], $content['lead']]);
        self::assertCount(2, $content['cards']);

        [$card, $bare] = $content['cards'];
        self::assertSame(['Nieuw', 'Maatwerk', 'Precies zoals jij het wilt.', 'Bekijk', '/maatwerk'], [$card['badge'], $card['title'], $card['body'], $card['link_label'], $card['href']]);
        self::assertSame('Een werkbank', $card['image']['alt'], 'the library\'s alt text');
        self::assertSame(MediaService::find($second)?->publicPath(), $card['hover_image']['src'] ?? null);
        self::assertSame(['', '', '', '', ''], [$bare['badge'], $bare['title'], $bare['body'], $bare['link_label'], $bare['href']], 'a picture alone is a card');
        self::assertNull($bare['hover_image']);

        $html = $this->render($content);
        self::assertStringContainsString('<a class="hover-card__cta hover-card__link" href="/maatwerk">Bekijk<span class="visually-hidden">: Maatwerk</span>', $html);
        self::assertStringContainsString('alt="Derde beeld"', $html);
    }

    public function testEveryChoiceIsAClosedListAndAFormWithoutItKeepsWhatIsStored(): void
    {
        [$section] = $this->place();
        $session = $this->signIn();

        $this->assertSaved($this->save($session, $section, ['layout' => 'open', 'shape' => 'organic', 'columns' => '4', 'overlay' => 'dark', 'effect' => 'subtle', 'header_align' => 'center']));
        $row = $this->row($section);
        self::assertSame(['open', 'organic', '4', 'dark', 'subtle', 'center'], [$row['layout'], $row['shape'], $row['columns'], $row['overlay'], $row['effect'], $row['header_align']]);

        foreach (['layout' => 'grid', 'shape' => 'star', 'columns' => '5', 'overlay' => 'rgba(0,0,0,.4)', 'effect' => 'wild', 'header_align' => 'justify'] as $choice => $word) {
            $response = $this->save($session, $section, [$choice => $word]);
            $this->assertRefused($response, $choice);
            self::assertSame(1, $this->xpath($this->editor($section, $session))->query('//fieldset[@aria-invalid="true"][.//input[@name="' . $choice . '"]]')->length, $choice . ': the message is at its field');
        }
        self::assertSame('open', $this->row($section)['layout'], 'a refused save stores nothing');

        $fields = $this->fields($section);
        unset($fields['layout'], $fields['shape']);
        $this->assertSaved($this->post($session, $fields));
        self::assertSame(['open', 'organic'], [$this->row($section)['layout'], $this->row($section)['shape']], 'a form without them keeps them');
    }

    // ---------------------------------------------------------- the cards

    public function testACardNeedsAPictureOfTheLibraryNeverAVideo(): void
    {
        [$section] = $this->place();
        $session = $this->signIn();
        $video = $this->libraryItem('video/mp4', 'mp4');
        $picture = $this->libraryItem('image/jpeg', 'jpg');

        foreach (['no picture' => '', 'a video' => (string) $video, 'nothing' => '999999999'] as $what => $mediaId) {
            $this->assertRefused($this->save($session, $section, ['cards' => ['new0' => $this->card(['media_id' => $mediaId, 'title' => 'Iets'])]]), $what);
        }
        $this->assertRefused($this->save($session, $section, ['cards' => ['new0' => $this->card(['media_id' => (string) $picture, 'hover_media_id' => (string) $video])]]), 'a video as the second picture');
        self::assertSame([], $this->items($section), 'nothing stored');

        $this->assertSaved($this->save($session, $section, ['cards' => ['new0' => $this->card(['media_id' => (string) $picture, 'hover_media_id' => (string) $picture])]]));
        $items = $this->items($section);
        self::assertCount(1, $items);
        self::assertNull($items[0]['hover_media_id'], 'the same picture twice is stored once');
    }

    public function testACardWithALinkNeedsATitleOrALabelAndNoLinkStoresNoAddress(): void
    {
        [$section] = $this->place();
        $session = $this->signIn();
        $picture = $this->libraryItem('image/jpeg', 'jpg');
        $page = (int) (new PageRepository())->findByContentKey(self::KEY)['id'];

        $this->assertRefused($this->save($session, $section, ['cards' => ['new0' => $this->card(['media_id' => (string) $picture, 'link_type' => 'url', 'link_url' => '/ergens'])]]), 'a nameless link');
        $this->assertRefused($this->save($session, $section, ['cards' => ['new0' => $this->card(['media_id' => (string) $picture, 'title' => 'Iets', 'link_type' => 'url', 'link_url' => 'javascript:alert(1)'])]]), 'a scheme a link may not have');

        $this->assertSaved($this->save($session, $section, ['cards' => [
            'new0' => $this->card(['media_id' => (string) $picture, 'title' => 'Een pagina', 'link_type' => 'page', 'link_target' => ['page' => (string) $page]]),
            'new1' => $this->card(['media_id' => (string) $picture, 'link_label' => 'Lees meer', 'link_type' => 'url', 'link_url' => 'https://example.com/']),
            'new2' => $this->card(['media_id' => (string) $picture, 'title' => 'Nergens', 'link_type' => 'none', 'link_url' => '/achtergebleven']),
        ]]));

        $items = $this->items($section);
        self::assertSame(['page', $page, null], [$items[0]['link_type'], (int) $items[0]['link_target_id'], $items[0]['link_url']]);
        self::assertSame(['url', 'https://example.com/'], [$items[1]['link_type'], $items[1]['link_url']]);
        self::assertSame([null, null], [$items[2]['link_type'], $items[2]['link_url']], '"Geen link" stores no address either');

        $cards = $this->content($section)['cards'];
        self::assertSame(PageContent::publicUrl((new PageRepository())->findByContentKey(self::KEY)), $cards[0]['href'], 'a page by id, resolved per render');
        self::assertSame('https://example.com/', $cards[1]['href']);
        self::assertSame('', $cards[2]['href']);
    }

    public function testCardsKeepTheirIdsTheirOrderAndLoseTheirWordsWhenRemoved(): void
    {
        [$section] = $this->place();
        $session = $this->signIn();
        $picture = $this->libraryItem('image/jpeg', 'jpg');

        $this->assertSaved($this->save($session, $section, ['cards' => [
            'new0' => $this->card(['media_id' => (string) $picture, 'title' => 'Een']),
            'new1' => $this->card(['media_id' => (string) $picture, 'title' => 'Twee']),
            'new2' => $this->card(['media_id' => (string) $picture, 'title' => 'Drie']),
        ]]));
        [$one, $two, $three] = array_map(static fn (array $item): int => (int) $item['id'], $this->items($section));

        // Drie first, Een second, Twee removed: as the editor posts it.
        $this->assertSaved($this->save($session, $section, ['cards' => [
            (string) $three => $this->card(['media_id' => (string) $picture, 'title' => 'Drie']),
            (string) $one => $this->card(['media_id' => (string) $picture, 'title' => 'Een']),
            (string) $two => $this->card(['media_id' => (string) $picture, 'title' => 'Twee', 'remove' => '1']),
        ]]));

        self::assertSame([$three, $one], array_map(static fn (array $item): int => (int) $item['id'], $this->items($section)), 'the same ids, in the new order');
        self::assertSame(['Drie', 'Een'], array_column($this->content($section)['cards'], 'title'));
        self::assertSame([], BlockLocalization::translations(HoverCardGridContent::ITEMS, $two), 'a removed card takes its words along');
    }

    public function testWordsArePerLanguageAndTheDefaultLanguageDecides(): void
    {
        if (!SiteLanguages::isActive('en')) {
            $this->markTestSkipped('this database has no English website language');
        }

        [$section] = $this->place();
        $session = $this->signIn();
        $picture = $this->libraryItem('image/jpeg', 'jpg');

        $this->assertSaved($this->save($session, $section, ['title' => 'Onze diensten', 'cards' => ['new0' => $this->card(['media_id' => (string) $picture, 'title' => 'Maatwerk'])]]));
        $cardId = (int) $this->items($section)[0]['id'];

        $this->assertSaved($this->save($session, $section, [
            'language_code' => 'en', 'title' => 'Our services', 'lead' => 'Only in English',
            'cards' => [(string) $cardId => $this->card(['media_id' => (string) $picture, 'title' => 'Tailor-made', 'badge' => 'New'])],
        ]));

        RequestLanguage::set('en', true);
        $this->clearCaches();
        $english = $this->content($section);
        self::assertSame('Our services', $english['title']);
        self::assertSame('', $english['lead'], 'a word only the translation has does not show');
        self::assertSame(['Tailor-made', ''], [$english['cards'][0]['title'], $english['cards'][0]['badge']]);

        RequestLanguage::reset();
        $this->clearCaches();
        self::assertSame(['Onze diensten', 'Maatwerk'], [$this->content($section)['title'], $this->content($section)['cards'][0]['title']], 'the default language is untouched');
    }

    // --------------------------------------------------------------- media

    public function testBothPicturesAreUsagesAndCannotBeDeleted(): void
    {
        [$section] = $this->place();
        $session = $this->signIn();
        $picture = $this->libraryItem('image/jpeg', 'jpg');
        $hover = $this->libraryItem('image/jpeg', 'jpg');

        $this->assertSaved($this->save($session, $section, ['cards' => ['new0' => $this->card(['media_id' => (string) $picture, 'hover_media_id' => (string) $hover])]]));

        $service = new MediaService(new MediaRepository());
        foreach ([$picture => 'Hover kaarten grid op "' . self::KEY . '"', $hover => 'Hover kaarten grid (tweede afbeelding) op "' . self::KEY . '"'] as $id => $label) {
            $usages = $service->usagesOf($id);
            self::assertCount(1, $usages, $label);
            self::assertSame($label, $usages[0]->label);
            self::assertSame('/admin/hover-card-grid.php?section=' . rawurlencode($section), $usages[0]->editUrl);
            self::assertSame([$id => 1], MediaUsageRegistry::countsFor([$id]));
            self::assertFalse($service->delete($id)['deleted'], $label);

            try {
                Database::connection()->prepare('DELETE FROM media WHERE id = ?')->execute([$id]);
                self::fail('the foreign key let a used item go: ' . $id);
            } catch (\PDOException $e) {
                self::assertStringContainsString('foreign key', strtolower($e->getMessage()));
            }
        }
    }

    public function testAHiddenGridRendersNothing(): void
    {
        [$section, $gridId] = $this->place();
        $session = $this->signIn();
        $picture = $this->libraryItem('image/jpeg', 'jpg');
        $this->assertSaved($this->save($session, $section, ['cards' => ['new0' => $this->card(['media_id' => (string) $picture, 'title' => 'Iets'])]]));

        (new HoverCardGridRepository())->updateSettings($gridId, ['is_active' => false]);
        $content = $this->content($section);

        self::assertSame(HoverCardGridContent::STATE_HIDDEN, $content['state']);
        self::assertSame([], $content['cards']);
    }

    // -------------------------------------------------------------- editor

    public function testTheEditorFoldsEveryCardAndOffersTheVeilOnlyForOverlayCards(): void
    {
        [$section] = $this->place();
        $session = $this->signIn();
        $picture = $this->libraryItem('image/jpeg', 'jpg');
        $this->assertSaved($this->save($session, $section, ['cards' => [
            'new0' => $this->card(['media_id' => (string) $picture, 'title' => 'Een']),
            'new1' => $this->card(['media_id' => (string) $picture, 'title' => 'Twee']),
        ]]));

        $xpath = $this->xpath($this->editor($section, $session));

        $stored = '//fieldset[@data-row-list-row][not(ancestor::template)][not(ancestor::noscript)]';
        self::assertSame(2, $xpath->query($stored . '/details[contains(@class, "admin-row-card__collapse")]')->length, 'every card folds');
        self::assertSame(0, $xpath->query($stored . '/details[@open]')->length, 'a stored card of a longer list starts folded');
        self::assertSame('Kaart 1 — Een', trim((string) preg_replace('/\s+/', ' ', (string) $xpath->query('//details[contains(@class, "admin-row-card__collapse")]/summary')->item(0)?->textContent)));
        self::assertSame(1, $xpath->query('//*[@data-row-list="hover-card-grid-cards" and @data-admin-collapse-group="hover-card-grid-cards"]')->length);
        self::assertSame(0, $xpath->query('//input[@type="file" and not(ancestor::*[@data-media-modal])]')->length, 'no upload field of its own');
        self::assertGreaterThan(0, $xpath->query('//*[@data-media-picker]//input[contains(@name, "[hover_media_id]")]')->length, 'a picker for the second picture');
        self::assertSame(1, $xpath->query('//fieldset[@data-hover-cards-needs="overlay" and not(@hidden)]')->length, 'the veil, for overlay cards');
        self::assertSame(0, $xpath->query('//input[@name="title" and @required]')->length, 'the heading is optional');

        $this->assertSaved($this->save($session, $section, ['layout' => 'open']));
        self::assertSame(1, $this->xpath($this->editor($section, $session))->query('//fieldset[@data-hover-cards-needs="overlay" and @hidden]')->length, 'an open card has no veil');
    }

    public function testARefusedSaveHandsEverythingBack(): void
    {
        [$section] = $this->place();
        $session = $this->signIn();

        $this->assertRefused($this->save($session, $section, [
            'title' => 'Mijn titel', 'shape' => 'circle',
            'cards' => ['new0' => $this->card(['title' => 'Zonder foto', 'body' => 'Een tekst'])],
        ]));

        $xpath = $this->xpath($this->editor($section, $session));
        self::assertSame('Mijn titel', $xpath->query('//input[@name="title"]')->item(0)?->getAttribute('value'));
        self::assertSame('circle', $xpath->query('//input[@name="shape" and @checked]')->item(0)?->getAttribute('value'));
        self::assertSame('Zonder foto', $xpath->query('//input[@name="cards[new0][title]"]')->item(0)?->getAttribute('value'));
        self::assertSame(1, $xpath->query('//details[@open and @data-admin-collapse-open]')->length, 'the card with the message is open');
        self::assertSame(1, $xpath->query('//form[@data-save-bar-unsaved]')->length);
    }

    // ------------------------------------------------------------ helpers

    /** @return array{string, int} the section parameter and the grid id */
    private function place(): array
    {
        [$id, $key] = SectionRegistry::create('hover_card_grid', self::KEY);
        $page = (new PageRepository())->findByContentKey(self::KEY);
        self::assertNotNull($page);
        (new PageSectionRepository())->create((int) $page['id'], self::KEY, 'hover_card_grid', $key, $id);
        $this->clearCaches();

        return [self::KEY . ':' . $key, (int) $id];
    }

    /** @return array<string, mixed> the form as the editor posts it: nothing typed, every choice at its default */
    private function fields(string $section): array
    {
        return [
            'section' => $section,
            'language_code' => 'nl',
            'eyebrow' => '',
            'title' => '',
            'lead' => '',
            'layout' => 'overlay',
            'shape' => 'rounded',
            'columns' => '3',
            'overlay' => 'medium',
            'effect' => 'normal',
            'header_align' => 'left',
            'cards_present' => '1',
        ];
    }

    /**
     * One card as the editor posts it.
     *
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private function card(array $values): array
    {
        return $values + [
            'present' => '1',
            'media_id' => '',
            'hover_media_id' => '',
            'badge' => '',
            'title' => '',
            'body' => '',
            'link_type' => 'none',
            'link_target' => [],
            'link_url' => '',
            'link_label' => '',
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
        ]);
        $this->clearCaches();

        return $response;
    }

    private function editor(string $section, string $session): string
    {
        $response = self::$server->request('GET', '/admin/hover-card-grid.php?section=' . urlencode($section), $session);
        self::assertSame(200, $response['status'], $response['body']);

        return $response['body'];
    }

    /** @return array<string, mixed> */
    private function row(string $section): array
    {
        [, $key] = explode(':', $section, 2);
        $row = (new HoverCardGridRepository())->findBySlugAndKey(self::KEY, $key);
        self::assertNotNull($row);

        return $row;
    }

    /** @return list<array<string, mixed>> */
    private function items(string $section): array
    {
        return (new HoverCardGridRepository())->findItemsByGridId((int) $this->row($section)['id']);
    }

    /** @return array<string, mixed> */
    private function content(string $section): array
    {
        [, $key] = explode(':', $section, 2);
        $this->clearCaches();

        return HoverCardGridContent::forSection(self::KEY, $key);
    }

    private function signIn(): string
    {
        [$session] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);

        return $session;
    }

    /** A media row for a file that does not exist: neither the form nor the save reads the disk. */
    private function libraryItem(string $mimeType, string $extension, string $alt = ''): int
    {
        $id = (new MediaRepository())->create([
            'path' => 'assets/media/__hc_' . bin2hex(random_bytes(4)) . '__.' . $extension,
            'original_filename' => 'kaart.' . $extension,
            'display_name' => '',
            'mime_type' => $mimeType,
            'alt_text' => $alt,
            'width' => 1200,
            'height' => 1500,
        ]);
        $this->mediaIds[] = $id;
        MediaService::clearCache();

        return $id;
    }

    /** @param array<string, mixed> $content */
    private function render(array $content): string
    {
        ob_start();
        try {
            render_section_hover_card_grid($content, 'hover_card_grid-1');
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
        HoverCardGridContent::clearCache();
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
        self::assertStringStartsWith('/admin/hover-card-grid.php', $response['location'], $what . ': back to the editor');
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

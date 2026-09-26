<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\PageRepository;
use App\Repository\PageSectionRepository;
use App\Service\AdminPermissions;
use App\Service\Blocks\BlockLocalization;
use App\Service\LinkResolver;
use App\Service\Language\SiteLanguages;
use App\Service\PageContent;
use App\Service\PageLocalization;
use App\Service\PagePath;
use App\Service\PageService;
use App\Service\PageTranslation;
use App\Service\Routing\LinkTargets;
use App\Service\Routing\RequestLanguage;
use App\Service\SectionRegistry;
use App\Service\TextImageSplitContent;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestSession;
use Tests\Support\BuiltInServer;
use Tests\Support\PageFixture;

/**
 * Content Blocks Polish 1 through the real editors and endpoints, over PHP's
 * built-in server: every new choice is stored from its closed list and an
 * unknown value is refused (a request without it keeps what is stored); the
 * Witruimte editor; and the button of a Tekst met afbeelding item with the
 * shared destination field (LinkChoice): a page by id, nested (Pages 2.0),
 * in the request's language, following a slug change, a typed address, an
 * item from before the type, and the refusals. Last, the items fold
 * (admin/_editor_rows.php's collapsible rows).
 *
 * What the partials make of the choices is
 * Tests\Service\SimpleBlocksPolishRenderTest.
 */
final class SimpleBlocksPolishHttpTest extends TestCase
{
    private const KEY = 'zz-simple-polish-test';
    private const PARENT = 'zz-sbp-ouder';
    private const CHILD = 'zz-sbp-kind';

    private static ?BuiltInServer $server = null;

    private AdminTestSession $accounts;

    private int $pageId = 0;

    private int $childId = 0;

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
            'Polijsttest'
        );
    }

    protected function tearDown(): void
    {
        $this->removePages();
        $this->accounts->forget();
        RequestLanguage::reset();
        $this->clearCaches();
    }

    // ------------------------------------------------------------ Tekstblok

    public function testTheTextBlockWidthIsStoredAndAnUnknownOneIsRefused(): void
    {
        $section = $this->place('rich_text');
        $session = $this->signIn();
        $fields = ['body' => '<p>Tekst</p>', 'text_align' => 'left', 'button_link_type' => 'none', 'is_active' => '1'];

        self::assertSame('medium', $this->column('rich_text_sections', 'content_width', $section), 'a new block is the narrow column');

        $this->assertSaved($this->post($session, '/api/admin/update-rich-text-section.php', $section, $fields + ['content_width' => 'large']));
        self::assertSame('large', $this->column('rich_text_sections', 'content_width', $section));

        $this->assertRefused($this->post($session, '/api/admin/update-rich-text-section.php', $section, $fields + ['content_width' => 'huge']));
        self::assertSame('large', $this->column('rich_text_sections', 'content_width', $section), 'a refused save stores nothing');

        $this->assertSaved($this->post($session, '/api/admin/update-rich-text-section.php', $section, $fields));
        self::assertSame('large', $this->column('rich_text_sections', 'content_width', $section), 'a request without it keeps what is stored');

        // A fresh session: a refused save's handback waits in the old one until
        // its screen is shown.
        $screen = $this->get($this->signIn(), '/admin/rich-text.php?section=' . urlencode($section));
        self::assertMatchesRegularExpression('/name="content_width" value="large" checked/', $screen);
        self::assertMatchesRegularExpression('/name="content_width" value="medium">/', $screen);
    }

    // ------------------------------------------------------------ Formulier

    public function testTheFormHeadingAlignmentIsStoredAndAnUnknownOneIsRefused(): void
    {
        $section = $this->place('form');
        $session = $this->signIn();
        $fields = ['form_id' => '', 'title' => 'Kop', 'intro' => 'Inleiding', 'is_active' => '1'];

        self::assertSame('left', $this->column('form_blocks', 'header_align', $section));

        foreach (['center', 'right', 'left'] as $align) {
            $this->assertSaved($this->post($session, '/api/admin/update-form-block.php', $section, $fields + ['header_align' => $align]), $align);
            self::assertSame($align, $this->column('form_blocks', 'header_align', $section));
        }

        $this->assertSaved($this->post($session, '/api/admin/update-form-block.php', $section, $fields + ['header_align' => 'right']));
        $this->assertRefused($this->post($session, '/api/admin/update-form-block.php', $section, $fields + ['header_align' => 'justify']));
        self::assertSame('right', $this->column('form_blocks', 'header_align', $section));
        $this->assertSaved($this->post($session, '/api/admin/update-form-block.php', $section, $fields));
        self::assertSame('right', $this->column('form_blocks', 'header_align', $section), 'a request without it keeps what is stored');

        // A fresh session: a refused save's handback waits in the old one until
        // its screen is shown.
        $screen = $this->get($this->signIn(), '/admin/form-block.php?section=' . urlencode($section));
        self::assertMatchesRegularExpression('/name="header_align" value="right" checked/', $screen);
    }

    // ------------------------------------------------------------ Kaarten-carrousel

    public function testTheCarouselChoicesAreStoredAndUnknownOnesAreRefused(): void
    {
        $section = $this->place('card_carousel');
        $session = $this->signIn();
        $fields = ['title' => 'Carrousel', 'desktop_layout' => 'orbit', 'is_active' => '1'];

        self::assertSame(['left', 'medium'], [$this->column('card_carousels', 'header_align', $section), $this->column('card_carousels', 'image_height', $section)]);

        foreach ([['center', 'small'], ['right', 'large'], ['left', 'medium']] as [$align, $height]) {
            $this->assertSaved($this->post($session, '/api/admin/update-card-carousel.php', $section, $fields + ['header_align' => $align, 'image_height' => $height]));
            self::assertSame([$align, $height], [$this->column('card_carousels', 'header_align', $section), $this->column('card_carousels', 'image_height', $section)]);
        }

        $this->assertSaved($this->post($session, '/api/admin/update-card-carousel.php', $section, $fields + ['header_align' => 'center', 'image_height' => 'large']));
        $this->assertRefused($this->post($session, '/api/admin/update-card-carousel.php', $section, $fields + ['header_align' => 'middle', 'image_height' => 'large']));
        $this->assertRefused($this->post($session, '/api/admin/update-card-carousel.php', $section, $fields + ['header_align' => 'center', 'image_height' => '300px']));
        self::assertSame(['center', 'large'], [$this->column('card_carousels', 'header_align', $section), $this->column('card_carousels', 'image_height', $section)]);

        $this->assertSaved($this->post($session, '/api/admin/update-card-carousel.php', $section, $fields));
        self::assertSame(['center', 'large'], [$this->column('card_carousels', 'header_align', $section), $this->column('card_carousels', 'image_height', $section)], 'a request without them keeps what is stored');

        // A fresh session: a refused save's handback waits in the old one until
        // its screen is shown.
        $screen = $this->get($this->signIn(), '/admin/card-carousel.php?section=' . urlencode($section));
        self::assertMatchesRegularExpression('/name="header_align" value="center" checked/', $screen);
        self::assertMatchesRegularExpression('/name="image_height" value="large" checked/', $screen);
    }

    // ------------------------------------------------------------ Witruimte

    public function testTheSpacerEditorOffersOnlyTheHeights(): void
    {
        $section = $this->place('spacer');
        $session = $this->signIn();

        $screen = $this->get($session, '/admin/spacer.php?section=' . urlencode($section));
        preg_match_all('/<option value="([a-z]+)"( selected)?>/', $screen, $options, PREG_SET_ORDER);
        self::assertSame(['small', 'medium', 'large', 'xlarge'], array_column($options, 1));
        self::assertSame('medium', array_values(array_filter($options, static fn (array $o): bool => ($o[2] ?? '') !== ''))[0][1]);
        preg_match('#<form method="post" action="/api/admin/update-spacer.php"[\s\S]*?</form>#', $screen, $form);
        self::assertSame(1, preg_match_all('/<(input|select|textarea)\b(?![^>]*type="hidden")/', $form[0] ?? ''), 'one control: the height');

        foreach (['small', 'large', 'xlarge', 'medium'] as $size) {
            $this->assertSaved($this->post($session, '/api/admin/update-spacer.php', $section, ['size' => $size]), $size);
            self::assertSame($size, $this->column('spacers', 'size', $section));
        }

        foreach (['huge', '', '48px', 'medium"><script>'] as $size) {
            $this->assertRefused($this->post($session, '/api/admin/update-spacer.php', $section, ['size' => $size]), $size);
        }
        self::assertSame('medium', $this->column('spacers', 'size', $section));
    }

    public function testTheSpacerEndpointTrustsNoSectionFromTheRequest(): void
    {
        $this->place('spacer');
        $session = $this->signIn();

        self::assertSame(404, $this->post($session, '/api/admin/update-spacer.php', self::KEY . ':custom-nothere', ['size' => 'large'])['status']);
        self::assertSame(404, $this->post($session, '/api/admin/update-spacer.php', 'no-such-page:custom-x', ['size' => 'large'])['status']);
        self::assertSame(404, self::$server->request('GET', '/admin/spacer.php?section=' . urlencode(self::KEY . ':custom-nothere'), $session)['status']);
    }

    // ------------------------------------------------------------ Tekst met afbeelding: the button

    public function testAButtonToAPageFollowsItsNestedAddressItsLanguageAndASlugChange(): void
    {
        $section = $this->place('text_image_split');
        $session = $this->signIn();
        $this->pages();

        $this->assertSaved($this->saveItems($session, $section, ['new0' => $this->item(['button_link_type' => 'page', 'button_link_target' => ['page' => (string) $this->childId]])]));
        self::assertSame([['button_link_type' => 'page', 'button_link_target_id' => $this->childId, 'button_url' => null]], $this->links($section));

        // Pages 2.0: the child's address is under its parent's.
        self::assertSame('/' . self::PARENT . '/' . self::CHILD, $this->buttonHref($section));

        // In English, the English slugs under /en/.
        RequestLanguage::set('en', true);
        $this->clearCaches();
        self::assertSame('/en/' . self::PARENT . '-en/' . self::CHILD . '-en', $this->buttonHref($section));
        RequestLanguage::reset();

        // An id, not an address: a new slug is followed without a save.
        PageLocalization::save($this->childId, 'nl', [PageTranslation::TITLE => 'Kind'], 'zz-sbp-nieuw-kind');
        $this->clearCaches();
        self::assertSame('/' . self::PARENT . '/zz-sbp-nieuw-kind', $this->buttonHref($section));
    }

    public function testATypedAddressStaysATypedAddress(): void
    {
        $section = $this->place('text_image_split');
        $session = $this->signIn();

        $this->assertSaved($this->saveItems($session, $section, ['new0' => $this->item(['button_link_type' => 'url', 'button_url' => 'https://example.test/pad'])]));
        self::assertSame([['button_link_type' => 'url', 'button_link_target_id' => null, 'button_url' => 'https://example.test/pad']], $this->links($section));
        self::assertSame('https://example.test/pad', $this->buttonHref($section));
    }

    public function testAnUnknownPageOrADangerousAddressIsRefusedAtTheItem(): void
    {
        $section = $this->place('text_image_split');
        $session = $this->signIn();

        $this->assertRefused($this->saveItems($session, $section, ['new0' => $this->item(['button_link_type' => 'page', 'button_link_target' => ['page' => '99999999']])]));
        $this->assertRefused($this->saveItems($session, $section, ['new0' => $this->item(['button_link_type' => 'page', 'button_link_target' => ['page' => '']])]));
        $this->assertRefused($this->saveItems($session, $section, ['new0' => $this->item(['button_link_type' => 'url', 'button_url' => 'javascript:alert(1)'])]));
        $this->assertRefused($this->saveItems($session, $section, ['new0' => $this->item(['button_link_type' => 'url', 'button_url' => ''])]));
        $this->assertRefused($this->saveItems($session, $section, ['new0' => $this->item(['button_link_type' => 'admin_route', 'button_url' => '/x'])]), 'a kind that is not in the list');
        self::assertSame([], $this->links($section), 'nothing of a refused save is stored');

        // The message sits at the item, which is open.
        $screen = $this->get($session, '/admin/text-image-split.php?section=' . urlencode($section));
        self::assertMatchesRegularExpression('/<select[^>]*name="items\[new0\]\[button_link_type\]"[^>]*aria-invalid="true"/', $screen);
        self::assertMatchesRegularExpression('/<details class="admin-collapse admin-row-card__collapse" data-admin-collapse-id="new0" open data-admin-collapse-open>/', $screen);
    }

    public function testAButtonNeedsItsLabelInTheDefaultLanguage(): void
    {
        $section = $this->place('text_image_split');
        $session = $this->signIn();

        $this->assertRefused($this->saveItems($session, $section, ['new0' => $this->item(['button_label' => '', 'button_link_type' => 'url', 'button_url' => '/contact'])]));
        $screen = $this->get($session, '/admin/text-image-split.php?section=' . urlencode($section));
        self::assertMatchesRegularExpression('/name="items\[new0\]\[button_label\]"[^>]*aria-invalid="true"/', $screen);
    }

    public function testAnItemFromBeforeTheTypeKeepsItsAddress(): void
    {
        $section = $this->place('text_image_split');
        $session = $this->signIn();
        $this->assertSaved($this->saveItems($session, $section, ['new0' => $this->item(['button_link_type' => 'none'])]));
        [$itemId] = array_column($this->links($section, true), 'id');

        // As an item was stored before the type existed: an address, no type.
        Database::connection()->prepare('UPDATE text_image_split_items SET button_link_type = NULL, button_link_target_id = NULL, button_url = ? WHERE id = ?')
            ->execute(['/over-ons', $itemId]);
        $this->clearCaches();
        self::assertSame('/over-ons', $this->buttonHref($section), 'it renders as it always did');

        // The editor shows it as a typed address, and saving it unchanged keeps it.
        $screen = $this->get($session, '/admin/text-image-split.php?section=' . urlencode($section));
        self::assertMatchesRegularExpression('/<option value="url" selected>/', $screen);
        self::assertStringContainsString('name="items[' . $itemId . '][button_url]" maxlength="255" value="/over-ons"', $screen);

        $this->assertSaved($this->saveItems($session, $section, [(string) $itemId => $this->item(['button_link_type' => 'url', 'button_url' => '/over-ons'])]));
        self::assertSame([['button_link_type' => 'url', 'button_link_target_id' => null, 'button_url' => '/over-ons']], $this->links($section));

        // A request without a kind, as an older screen sent it: label and address are a button.
        $legacy = $this->item(['button_url' => '/contact']);
        unset($legacy['button_link_type'], $legacy['button_link_target']);
        $this->assertSaved($this->saveItems($session, $section, [(string) $itemId => $legacy]));
        self::assertSame('/contact', $this->buttonHref($section));
    }

    public function testNoButtonStoresNoLeftoverAddress(): void
    {
        $section = $this->place('text_image_split');
        $session = $this->signIn();

        $this->assertSaved($this->saveItems($session, $section, ['new0' => $this->item(['button_link_type' => 'none', 'button_url' => '/contact'])]));
        self::assertSame([['button_link_type' => null, 'button_link_target_id' => null, 'button_url' => null]], $this->links($section));
        self::assertSame('', $this->buttonHref($section), 'no button, and none comes back');
    }

    // ------------------------------------------------------------ Tekst met afbeelding: folding items

    public function testEveryItemFoldsOnItsOwnWithAButtonThatSaysWhichItemItIs(): void
    {
        $section = $this->place('text_image_split');
        $session = $this->signIn();

        $this->assertSaved($this->saveItems($session, $section, [
            'new0' => $this->item(['title' => 'Over ons']),
            'new1' => $this->item(['title' => '', 'body' => '<p>Zonder titel</p>']),
            'new2' => $this->item(['title' => 'Werkwijze']),
        ]));
        $screen = $this->get($session, '/admin/text-image-split.php?section=' . urlencode($section));
        $ids = array_column($this->links($section, true), 'id');

        self::assertStringContainsString('data-row-list="text-image-split-items" data-admin-collapse-group="text-image-split-items"', $screen);
        foreach ($ids as $position => $id) {
            // Stored items of a longer list start folded; the browser's own
            // <summary> is the button (expanded state, keyboard, tab order).
            self::assertMatchesRegularExpression('/<details class="admin-collapse admin-row-card__collapse" data-admin-collapse-id="' . $id . '">\s*<summary class="admin-collapse__summary">/', $screen);
            self::assertStringContainsString('<legend class="admin-visually-hidden">Item <span data-row-list-number>' . ($position + 1) . '</span></legend>', $screen);
        }
        self::assertStringContainsString('Item <span data-row-list-number>1</span><span data-row-list-title> — Over ons</span>', $screen);
        self::assertStringContainsString('Item <span data-row-list-number>2</span><span data-row-list-title></span>', $screen);
        self::assertStringContainsString('Item <span data-row-list-number>3</span><span data-row-list-title> — Werkwijze</span>', $screen);

        // The tools stay outside the fold: a folded item can be moved and removed.
        self::assertMatchesRegularExpression('/data-row-list-move="up"[\s\S]*?<\/span>\s*<label class="admin-btn-text admin-btn-text--danger admin-row-card__remove">[\s\S]*?<\/div>\s*<p class="admin-row-card__removing">[^<]*<\/p>\s*<details/', $screen);

        // A new item arrives open.
        preg_match('/<template data-row-list-template="text-image-split-items">([\s\S]*?)<\/template>/', $screen, $template);
        self::assertStringContainsString('data-admin-collapse-id="__KEY__" open>', $template[1] ?? '');
        self::assertStringContainsString('data-row-list-title-source', $template[1] ?? '');

        // Folding changes nothing that is saved: the same form, order and ↑↓ work as before.
        $this->assertSaved($this->saveItems($session, $section, [
            (string) $ids[2] => $this->item(['title' => 'Werkwijze']),
            (string) $ids[0] => $this->item(['title' => 'Over ons']),
            (string) $ids[1] => $this->item(['title' => '', 'body' => '<p>Zonder titel</p>', 'remove' => '1']),
        ]));
        self::assertSame([$ids[2], $ids[0]], array_column($this->links($section, true), 'id'));
    }

    public function testALoneItemStartsOpen(): void
    {
        $section = $this->place('text_image_split');
        $session = $this->signIn();
        $this->assertSaved($this->saveItems($session, $section, ['new0' => $this->item([])]));
        [$id] = array_column($this->links($section, true), 'id');

        $screen = $this->get($session, '/admin/text-image-split.php?section=' . urlencode($section));
        self::assertStringContainsString('data-admin-collapse-id="' . $id . '" open>', $screen);
    }

    // ------------------------------------------------------------ helpers

    /** Places a block of $type on the test page and returns its `<page>:<key>`. */
    private function place(string $type): string
    {
        [$id, $key] = SectionRegistry::create($type, self::KEY);
        (new PageSectionRepository())->create($this->pageId, self::KEY, $type, $key, $id);

        return self::KEY . ':' . $key;
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

    /** @param array<string, mixed> $fields */
    private function item(array $fields): array
    {
        return $fields + [
            'present' => '1', 'title' => 'Een item', 'body' => '', 'eyebrow' => '', 'alt' => '', 'media_id' => '',
            'image_side' => 'left', 'image_column' => '50', 'image_height' => 'medium', 'image_focus' => 'center',
            'button_label' => 'Lees meer', 'button_link_type' => 'none', 'button_url' => '',
        ];
    }

    /** @param array<string, array<string, mixed>> $items */
    private function saveItems(string $session, string $section, array $items): array
    {
        return $this->post($session, '/api/admin/update-text-image-split-section.php', $section, [
            'is_active' => '1', 'items_present' => '1', 'items' => $items,
        ]);
    }

    /** @param array<string, mixed> $fields */
    private function post(string $session, string $path, string $section, array $fields): array
    {
        return self::$server->request('POST', $path, $session, $fields + [
            'csrf_token' => (string) $this->accounts->read($session, 'csrf_token'),
            'section' => $section,
            'language_code' => 'nl',
        ]);
    }

    /** @param array{location: string} $response */
    private function assertSaved(array $response, string $what = ''): void
    {
        self::assertStringContainsString('saved=1', $response['location'], $what);
    }

    /** @param array{location: string} $response */
    private function assertRefused(array $response, string $what = ''): void
    {
        self::assertStringNotContainsString('saved=1', $response['location'], $what);
        self::assertStringStartsWith('/admin/', $response['location'], $what . ': back to the editor');
    }

    private function get(string $session, string $path): string
    {
        $response = self::$server->request('GET', $path, $session);
        self::assertSame(200, $response['status'], $path);

        return $response['body'];
    }

    private function signIn(): string
    {
        [$session] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);

        return $session;
    }

    private function column(string $table, string $column, string $section): string
    {
        [$pageSlug, $key] = explode(':', $section, 2);
        $stmt = Database::connection()->prepare("SELECT {$column} FROM {$table} WHERE page_slug = ? AND section_key = ?");
        $stmt->execute([$pageSlug, $key]);

        return (string) $stmt->fetchColumn();
    }

    /** @return list<array<string, mixed>> */
    private function links(string $section, bool $withId = false): array
    {
        [$pageSlug, $key] = explode(':', $section, 2);
        $stmt = Database::connection()->prepare(
            'SELECT i.id, i.button_link_type, i.button_link_target_id, i.button_url FROM text_image_split_items i
               JOIN text_image_splits s ON s.id = i.text_image_split_id
              WHERE s.page_slug = ? AND s.section_key = ? ORDER BY i.sort_order, i.id'
        );
        $stmt->execute([$pageSlug, $key]);

        return array_map(static function (array $row) use ($withId): array {
            $row['button_link_target_id'] = $row['button_link_target_id'] === null ? null : (int) $row['button_link_target_id'];
            $row['id'] = (int) $row['id'];
            if (!$withId) {
                unset($row['id']);
            }

            return $row;
        }, $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /** The first item's button as the website renders it, in the current request language. */
    private function buttonHref(string $section): string
    {
        [$pageSlug, $key] = explode(':', $section, 2);
        TextImageSplitContent::clearCache();
        $content = TextImageSplitContent::forSection($pageSlug, $key);

        return (string) ($content['items'][0]['button_url'] ?? '');
    }

    private function clearCaches(): void
    {
        PageContent::clearCache();
        PageLocalization::clearCache();
        PagePath::clearCache();
        LinkResolver::clearCache();
        LinkTargets::reset();
        TextImageSplitContent::clearCache();
    }

    private function removePages(): void
    {
        foreach ([self::CHILD, self::PARENT, self::KEY] as $contentKey) {
            $page = (new PageRepository())->findByContentKey($contentKey);
            if ($page !== null) {
                PageService::delete($page);
            }
        }

        $this->clearCaches();
    }
}

<?php

declare(strict_types=1);

namespace Tests\Module;

use App\Module\PortfolioModule;
use App\Repository\PortfolioGalleryRepository;
use App\Service\PortfolioGalleryContent;
use App\Service\PortfolioLocalization;
use App\Service\PortfolioRelatedProjects;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestSession;
use Tests\Support\BuiltInServer;

/**
 * The "Gerelateerde projecten" section of a project's editor
 * (admin/portfolio-item.php) and its save (api/admin/update-portfolio-item.php),
 * over PHP's built-in server:
 *
 *   - the section folds away, and while it is off its fields are hidden but
 *     on the form; "Toon op homepage" is nowhere;
 *   - one Opslaan stores the settings, the picked projects in their order and
 *     the heading and lead in the language on screen, with the other language
 *     left alone;
 *   - an unknown choice is refused, nothing is stored and everything typed
 *     comes back; the project itself, a repeated and a deleted project are
 *     left out of the picked list, not refused;
 *   - a form that never showed the section changes none of it;
 *   - a posted `is_featured` is not read at all.
 */
final class PortfolioRelatedEditorHttpTest extends TestCase
{
    private const ENDPOINT = '/api/admin/update-portfolio-item.php';

    private static ?BuiltInServer $server = null;

    private AdminTestSession $accounts;

    /** @var list<int> */
    private array $itemIds = [];

    public static function setUpBeforeClass(): void
    {
        self::$server = BuiltInServer::start(['MODULE_PORTFOLIO_ENABLED' => 'true'], 'tests/Support/dispatcher-router.php');
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        self::$server = null;
    }

    protected function setUp(): void
    {
        if (self::$server === null || !self::$server->answers()) {
            $this->markTestSkipped("could not start PHP's built-in web server for this test");
        }
        $this->accounts = new AdminTestSession();
    }

    protected function tearDown(): void
    {
        $gallery = new PortfolioGalleryRepository();
        foreach ($this->itemIds as $id) {
            $gallery->deleteItem($id);
        }
        $this->itemIds = [];
        $this->accounts->forget();
        PortfolioGalleryContent::clearCache();
    }

    public function testTheEditorFoldsTheSectionAwayAndHasNoHomepageSwitch(): void
    {
        $item = $this->item('ZZ Editor ' . self::marker());
        [$session] = $this->accounts->signIn([PortfolioModule::PORTFOLIO_MANAGE]);

        $html = (string) self::$server->request('GET', '/admin/portfolio-item.php?id=' . $item, $session)['body'];

        self::assertMatchesRegularExpression('#<details class="admin-card admin-portfolio-collapsible admin-portfolio-related" data-related-projects>#', $html, 'off: folded');
        self::assertStringContainsString('name="related_enabled" value="1" data-related-switch>', $html, 'off: not ticked');
        self::assertMatchesRegularExpression('#<div class="admin-portfolio-related__fields" data-related-needs="on" hidden>#', $html, 'its fields hidden but on the form');
        self::assertStringContainsString('<input type="hidden" name="related_submitted" value="1">', $html);
        self::assertStringContainsString('name="related_mode" value="hybrid"', $html);
        self::assertStringContainsString('data-item-picker', $html, 'the shared picker');
        self::assertStringContainsString('placeholder="Gerelateerde projecten"', $html, 'the built-in heading as the empty title\'s placeholder');
        self::assertStringNotContainsString('is_featured', $html);
        self::assertStringNotContainsString('Toon op homepage', $html);
        self::assertStringContainsString('admin-save-bar', $html, 'the save bar watches the form');
    }

    public function testOneSaveStoresTheSettingsThePickedOrderAndTheWordsOfOneLanguage(): void
    {
        $m = self::marker();
        $item = $this->item('ZZ Huidig ' . $m);
        $a = $this->item('ZZ A ' . $m);
        $b = $this->item('ZZ B ' . $m);
        PortfolioLocalization::saveItem($item, 'nl', [PortfolioLocalization::RELATED_TITLE => 'ZZ Eigen kop']);

        [$session, $csrf] = $this->accounts->signIn([PortfolioModule::PORTFOLIO_MANAGE]);
        $response = $this->save($session, $csrf, $item, [
            'related_enabled' => '1',
            'related_mode' => 'hybrid',
            'related_max' => '4',
            'related_sort' => 'random',
            'related_fallback' => 'fill',
            'related_layout' => 'large',
            'related_items' => [(string) $b, (string) $a],
            'related_title' => 'ZZ English heading',
            'related_lead' => 'ZZ A short line.',
            'language_code' => 'en',
        ]);

        self::assertStringContainsString('updated=1', $response['location']);
        $row = (new PortfolioGalleryRepository())->findItemById($item);
        self::assertSame(
            ['related_enabled' => true, 'related_mode' => 'hybrid', 'related_max' => 4, 'related_sort' => 'random', 'related_fallback' => 'fill', 'related_layout' => 'large', 'related_show_text' => false],
            PortfolioRelatedProjects::settings($row)
        );
        self::assertSame([$b, $a], (new PortfolioGalleryRepository())->relatedItemIds($item));

        PortfolioLocalization::clearCache();
        self::assertSame('ZZ English heading', PortfolioLocalization::rawItemValue($item, PortfolioLocalization::RELATED_TITLE, 'en'));
        self::assertSame('ZZ A short line.', PortfolioLocalization::rawItemValue($item, PortfolioLocalization::RELATED_LEAD, 'en'));
        self::assertSame('ZZ Eigen kop', PortfolioLocalization::rawItemValue($item, PortfolioLocalization::RELATED_TITLE, 'nl'), 'the other language left alone');
    }

    public function testAnUnknownChoiceIsRefusedAndNothingIsStored(): void
    {
        $m = self::marker();
        $item = $this->item('ZZ Huidig ' . $m);
        $a = $this->item('ZZ A ' . $m);

        [$session, $csrf] = $this->accounts->signIn([PortfolioModule::PORTFOLIO_MANAGE]);
        $response = $this->save($session, $csrf, $item, [
            'related_enabled' => '1',
            'related_mode' => 'everything',
            'related_max' => '5',
            'related_items' => [(string) $a],
        ]);

        self::assertStringNotContainsString('updated=1', $response['location']);
        self::assertSame(['Kies bij Gerelateerde projecten een van de mogelijkheden uit de lijst.'], $this->accounts->read($session, 'admin_portfolio_item_errors'));
        self::assertFalse(PortfolioRelatedProjects::settings((new PortfolioGalleryRepository())->findItemById($item))['related_enabled']);
        self::assertSame([], (new PortfolioGalleryRepository())->relatedItemIds($item));

        $html = (string) self::$server->request('GET', '/admin/portfolio-item.php?id=' . $item, $session)['body'];
        self::assertStringContainsString('data-save-bar-unsaved', $html, 'the form is still changed');
        self::assertStringContainsString('name="related_enabled" value="1" data-related-switch checked>', $html, 'what was typed comes back');
    }

    public function testItselfARepeatedAndADeletedProjectAreLeftOutOfThePickedList(): void
    {
        $m = self::marker();
        $item = $this->item('ZZ Huidig ' . $m);
        $a = $this->item('ZZ A ' . $m);
        $gone = $this->item('ZZ Weg ' . $m);
        (new PortfolioGalleryRepository())->deleteItem($gone);

        [$session, $csrf] = $this->accounts->signIn([PortfolioModule::PORTFOLIO_MANAGE]);
        $response = $this->save($session, $csrf, $item, [
            'related_enabled' => '1',
            'related_mode' => 'manual',
            'related_items' => [(string) $item, (string) $a, (string) $gone, (string) $a, 'x'],
        ]);

        self::assertStringContainsString('updated=1', $response['location']);
        self::assertSame([$a], (new PortfolioGalleryRepository())->relatedItemIds($item));
    }

    public function testAFormWithoutTheSectionChangesNoneOfItAndIsFeaturedIsNotRead(): void
    {
        $m = self::marker();
        $item = $this->item('ZZ Huidig ' . $m);
        $a = $this->item('ZZ A ' . $m);
        $repository = new PortfolioGalleryRepository();
        $repository->updateRelatedSettings($item, [
            'related_enabled' => true, 'related_mode' => 'manual', 'related_max' => 2, 'related_sort' => 'newest',
            'related_fallback' => 'available', 'related_layout' => 'compact', 'related_show_text' => false,
        ]);
        $repository->replaceRelatedItems($item, [$a]);

        [$session, $csrf] = $this->accounts->signIn([PortfolioModule::PORTFOLIO_MANAGE]);
        $response = self::$server->request('POST', self::ENDPOINT, $session, [
            'csrf_token' => $csrf,
            'item_id' => (string) $item,
            'language_code' => 'nl',
            'title' => 'ZZ Huidig ' . $m,
            'is_active' => '1',
            'is_featured' => '1',
            'featured_sort_order' => '3',
        ]);

        self::assertStringContainsString('updated=1', $response['location'], 'an old form with "Toon op homepage" still saves');
        $row = $repository->findItemById($item);
        self::assertArrayNotHasKey('is_featured', $row, 'there is no such column');
        self::assertSame('manual', PortfolioRelatedProjects::settings($row)['related_mode'], 'not sent: kept');
        self::assertSame([$a], $repository->relatedItemIds($item));
    }

    /**
     * The whole form as the editor sends it, the related section included.
     *
     * @param array<string, mixed> $related
     *
     * @return array{status: int, location: string, body: string, headers: string}
     */
    private function save(string $session, string $csrf, int $itemId, array $related): array
    {
        $fields = [
            'csrf_token' => $csrf,
            'item_id' => (string) $itemId,
            'language_code' => 'nl',
            'title' => PortfolioLocalization::rawItemValue($itemId, PortfolioLocalization::TITLE, 'nl'),
            'is_active' => '1',
            'related_submitted' => '1',
            'related_items_submitted' => '1',
            'related_mode' => 'automatic',
            'related_max' => '3',
            'related_sort' => 'relevance',
            'related_fallback' => 'available',
            'related_layout' => 'normal',
        ];
        $related = $related + $fields;

        // http_build_query writes a list as related_items[0]=…; PHP reads that as the same list.
        return self::$server->request('POST', self::ENDPOINT, $session, $related);
    }

    private function item(string $title): int
    {
        $repository = new PortfolioGalleryRepository();
        $id = $repository->createItem((int) $repository->ensureCatalogue()['id'], [
            'image_path' => 'assets/images/sections/zz-related-editor-' . self::marker() . '.jpg',
            'thumbnail_path' => null,
        ]);
        $this->itemIds[] = $id;
        PortfolioLocalization::saveItem($id, 'nl', [PortfolioLocalization::TITLE => $title]);

        return $id;
    }

    private static function marker(): string
    {
        return bin2hex(random_bytes(4));
    }
}

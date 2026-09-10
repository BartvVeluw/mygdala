<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Module\ShopModule;
use App\Database;
use App\Repository\CollectionRepository;
use App\Repository\SiteSettingRepository;
use App\Service\AdminNavigation;
use App\Service\AdminPermissions;
use App\Service\RelatedProductsContent;
use App\Service\SiteSettings;
use PHPUnit\Framework\TestCase;

/**
 * The "Gerelateerde producten" CMS screen: that its settings really round-trip
 * through the storage they claim to use, that it is wired into the sidebar as
 * an ordinary Shop section, and — by static source inspection, the technique
 * this project uses where there is no harness for authenticated admin POSTs
 * (see CollectionAdminSecurityTest) — that its save endpoint is guarded like
 * every other one and can never touch the catalogue.
 */
final class RelatedProductsAdminTest extends TestCase
{
    private const ENDPOINT = 'api/admin/update-related-products-settings.php';
    private const SCREEN = 'admin/related-products.php';

    private const SETTING_KEYS = [
        'related_products_enabled',
        'related_products_heading_nl',
        'related_products_heading_en',
        'related_products_max_items',
    ];

    private CollectionRepository $collections;

    /** @var list<int> */
    private array $collectionIds = [];
    /** @var array<string, string> */
    private array $originalSettings = [];

    protected function setUp(): void
    {
        $this->collections = new CollectionRepository();

        $stored = (new SiteSettingRepository())->findAll();
        foreach (self::SETTING_KEYS as $key) {
            $this->originalSettings[$key] = $stored[$key] ?? SiteSettings::defaults()[$key];
        }

        SiteSettings::clearCache();
        RelatedProductsContent::clearCache();
    }

    protected function tearDown(): void
    {
        (new SiteSettingRepository())->upsertMany($this->originalSettings);

        $db = Database::connection();
        foreach ($this->collectionIds as $id) {
            $db->prepare('DELETE FROM collections WHERE id = :id')->execute(['id' => $id]);
        }
        $this->collectionIds = [];
        $this->originalSettings = [];

        SiteSettings::clearCache();
        RelatedProductsContent::clearCache();
    }

    private function sourceOf(string $relativePath): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relativePath;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /**
     * The same file with every comment removed, so an assertion about what
     * the code does is not satisfied — or broken — by a docblock that merely
     * names the thing it promises not to do.
     */
    private function codeOf(string $relativePath): string
    {
        $code = '';
        foreach (token_get_all($this->sourceOf($relativePath)) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $code .= $token[1];
                continue;
            }
            $code .= $token;
        }

        return $code;
    }

    private function createCollection(string $name): int
    {
        $id = $this->collections->create([
            'name' => $name,
            'name_en' => null,
            'slug' => 'zz-test-relatedadmin-' . bin2hex(random_bytes(5)),
            'description' => null,
            'description_en' => null,
            'image_path' => null,
            'is_active' => true,
        ]);
        $this->collectionIds[] = $id;

        return $id;
    }

    /* ------------------------------------------------------------------ */
    /* Settings really persist                                             */
    /* ------------------------------------------------------------------ */

    public function testGlobalSettingsRoundTripThroughSiteSettings(): void
    {
        (new SiteSettingRepository())->upsertMany([
            'related_products_enabled' => '0',
            'related_products_heading_nl' => 'Bekijk ook',
            'related_products_heading_en' => 'Also have a look',
            'related_products_max_items' => '6',
        ]);
        SiteSettings::clearCache();
        RelatedProductsContent::clearCache();

        $this->assertFalse(RelatedProductsContent::isEnabled());
        $this->assertSame('Bekijk ook', SiteSettings::get('related_products_heading_nl'));
        $this->assertSame('Also have a look', SiteSettings::get('related_products_heading_en'));
        $this->assertSame(6, RelatedProductsContent::maxItems());
    }

    public function testAStoredZeroReallyTurnsTheFeatureOff(): void
    {
        // SiteSettings::all() only lets a stored value win when it is !== ''
        // — a loose falsy check there would make '0' fall back to the '1'
        // default and the global switch would silently do nothing.
        (new SiteSettingRepository())->upsertMany(['related_products_enabled' => '0']);
        SiteSettings::clearCache();

        $this->assertSame('0', SiteSettings::get('related_products_enabled'));
        $this->assertFalse(RelatedProductsContent::isEnabled());
    }

    public function testPerCollectionSettingsRoundTripAndAreIndependent(): void
    {
        $first = $this->createCollection('ZZ Admin een');
        $second = $this->createCollection('ZZ Admin twee');

        $this->collections->updateRelatedProductsSettings($first, false, null, null);
        $this->collections->updateRelatedProductsSettings($second, true, 'Meer hiervan', 'More of this');

        $storedFirst = $this->collections->findById($first);
        $storedSecond = $this->collections->findById($second);

        $this->assertNotNull($storedFirst);
        $this->assertNotNull($storedSecond);

        $this->assertSame(0, (int) $storedFirst['show_related_products']);
        $this->assertNull($storedFirst['related_heading_nl']);

        $this->assertSame(1, (int) $storedSecond['show_related_products']);
        $this->assertSame('Meer hiervan', $storedSecond['related_heading_nl']);
        $this->assertSame('More of this', $storedSecond['related_heading_en']);
    }

    public function testAnEmptyHeadingOverrideIsStoredAsNullNotAsAnEmptyString(): void
    {
        $id = $this->createCollection('ZZ Admin leeg');

        $this->collections->updateRelatedProductsSettings($id, true, 'Iets', 'Something');
        $this->collections->updateRelatedProductsSettings($id, true, '', '');

        $stored = $this->collections->findById($id);

        $this->assertNotNull($stored);
        $this->assertNull($stored['related_heading_nl'], 'clearing the field must mean "use the global heading"');
        $this->assertNull($stored['related_heading_en']);
    }

    public function testSavingRelatedProductsSettingsNeverTouchesTheCollectionsOwnContent(): void
    {
        $id = $this->createCollection('ZZ Admin inhoud');
        $before = $this->collections->findById($id);
        $this->assertNotNull($before);

        $this->collections->updateRelatedProductsSettings($id, false, 'Andere kop', null);

        $after = $this->collections->findById($id);
        $this->assertNotNull($after);

        foreach (['name', 'name_en', 'slug', 'description', 'description_en', 'image_path', 'is_active', 'sort_order'] as $column) {
            $this->assertSame($before[$column], $after[$column], $column . ' must be untouched by the settings screen');
        }
    }

    /* ------------------------------------------------------------------ */
    /* Sidebar wiring                                                      */
    /* ------------------------------------------------------------------ */

    public function testTheScreenIsAnOrdinaryShopSidebarEntry(): void
    {
        $entry = null;
        foreach (AdminNavigation::items() as $item) {
            if ($item['key'] === 'related_products') {
                $entry = $item;
                break;
            }
        }

        $this->assertNotNull($entry, 'the CMS needs a way in that is not a page-builder row');
        $this->assertSame('Gerelateerde producten', $entry['label']);
        $this->assertSame('/admin/related-products.php', $entry['url']);
        $this->assertSame(ShopModule::COLLECTIONS_MANAGE, $entry['permission']);
        $this->assertContains('related-products.php', $entry['scripts']);

        // Same group as Producten/Collecties — it configures the shop.
        $collectionsGroup = null;
        foreach (AdminNavigation::items() as $item) {
            if ($item['key'] === 'collections') {
                $collectionsGroup = $item['group'];
            }
        }
        $this->assertSame($collectionsGroup, $entry['group']);

        // The page-builder entry must NOT claim this script any more.
        foreach (AdminNavigation::items() as $item) {
            if ($item['key'] === 'pages') {
                $this->assertNotContains('related-products.php', $item['scripts']);
            }
        }
    }

    /* ------------------------------------------------------------------ */
    /* Endpoint guards                                                     */
    /* ------------------------------------------------------------------ */

    public function testTheEndpointChecksLoginThenPermissionThenCsrfBeforeAnyWrite(): void
    {
        $source = $this->sourceOf(self::ENDPOINT);

        $this->assertStringContainsString('AdminAuth::requireLoginForApi()', $source);
        $this->assertStringContainsString("AdminAuth::requirePermissionForApi('collections.manage')", $source);
        $this->assertStringContainsString("Csrf::validate(\$_POST['csrf_token'] ?? null)", $source);

        $authPos = strpos($source, 'AdminAuth::requireLoginForApi()');
        $permissionPos = strpos($source, 'AdminAuth::requirePermissionForApi(');
        $csrfPos = strpos($source, 'Csrf::validate(');

        $writePositions = [];
        foreach (['->upsertMany(', '->updateRelatedProductsSettings('] as $marker) {
            $pos = strpos($source, $marker);
            $this->assertNotFalse($pos, self::ENDPOINT . ' should contain ' . $marker);
            $writePositions[] = $pos;
        }

        $this->assertLessThan($permissionPos, $authPos);
        $this->assertLessThan($csrfPos, $permissionPos);
        $this->assertLessThan(min($writePositions), $csrfPos, 'CSRF must be validated before anything is written');
    }

    public function testTheEndpointRejectsNonPostRequests(): void
    {
        $source = $this->sourceOf(self::ENDPOINT);

        $this->assertStringContainsString("\$_SERVER['REQUEST_METHOD'] !== 'POST'", $source);
        $this->assertStringContainsString('http_response_code(405)', $source);
        $this->assertLessThan(
            strpos($source, '->upsertMany('),
            strpos($source, "\$_SERVER['REQUEST_METHOD'] !== 'POST'")
        );
    }

    public function testTheMaximumIsValidatedServerSideBeforeItIsStored(): void
    {
        $source = $this->sourceOf(self::ENDPOINT);

        $this->assertStringContainsString('RelatedProductsContent::validateMaxItems(', $source);
        $this->assertLessThan(
            strpos($source, '->upsertMany('),
            strpos($source, 'RelatedProductsContent::validateMaxItems('),
            'an invalid maximum must never reach the database'
        );
    }

    public function testSubmittedCollectionIdsAreCheckedAgainstRealCollections(): void
    {
        $source = $this->sourceOf(self::ENDPOINT);

        // The loop is driven by the collections that actually exist, never by
        // the keys of the submitted array — a forged collections[999][enabled]
        // cannot create or reach anything.
        $this->assertStringContainsString('relatedProductsCollectionInput($_POST[\'collections\'] ?? null, $collections)', $source);
        $this->assertStringContainsString('foreach ($collections as $collection)', $source);
        $this->assertStringNotContainsString('foreach ($submitted as', $source);
    }

    public function testTheSwitchesAreOnlySynchronisedWhenTheFormCarriedTheList(): void
    {
        $endpoint = $this->sourceOf(self::ENDPOINT);
        $screen = $this->sourceOf(self::SCREEN);

        $this->assertStringContainsString('name="collections_submitted"', $screen);
        $this->assertStringContainsString("isset(\$_POST['collections_submitted'])", $endpoint);
        $this->assertLessThan(
            strpos($endpoint, '->updateRelatedProductsSettings('),
            strpos($endpoint, "isset(\$_POST['collections_submitted'])"),
            'a save without the list must not read as "switched everything off"'
        );
    }

    public function testTheEndpointNeverTouchesProductsOrCollectionMembership(): void
    {
        $source = $this->codeOf(self::ENDPOINT);

        foreach ([
            'setCollectionProducts',
            'setProductCollections',
            'collection_products',
            'ProductRepository',
            'DELETE FROM',
        ] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $source,
                'configuring related products must never rewrite which products are in a collection'
            );
        }
    }

    public function testTheAdminScreenRequiresLoginAndThePermissionBeforeRenderingAnything(): void
    {
        $source = $this->sourceOf(self::SCREEN);

        $this->assertStringContainsString('AdminAuth::requireLogin()', $source);
        $this->assertStringContainsString("AdminAuth::requirePermission('collections.manage')", $source);

        $authPos = strpos($source, 'AdminAuth::requireLogin()');
        $htmlPos = strpos($source, '<!doctype html>');
        $this->assertNotFalse($htmlPos);
        $this->assertLessThan($htmlPos, $authPos);
    }

    public function testTheAdminScreenPostsWithACsrfTokenAndEscapesWhatItPrints(): void
    {
        $source = $this->sourceOf(self::SCREEN);

        $this->assertStringContainsString('name="csrf_token" value="<?= $h($csrfToken) ?>"', $source);
        $this->assertStringContainsString('method="post"', $source);
        $this->assertStringContainsString("<?= \$h((string) \$collection['name']) ?>", $source);
        $this->assertStringNotContainsString("<?= \$collection['name'] ?>", $source);
    }

    public function testTheRedirectTargetIsALiteral(): void
    {
        $source = $this->sourceOf(self::ENDPOINT);

        $this->assertStringContainsString("\$redirect = '/admin/related-products.php';", $source);
        $this->assertSame(
            0,
            preg_match('/header\(\'Location: \' \. \$redirect \. [^\'\n]*\$_(POST|GET)/', $source),
            'nothing from the request may end up in a redirect target'
        );
    }
}

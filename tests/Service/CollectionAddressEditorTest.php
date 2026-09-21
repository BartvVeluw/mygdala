<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Module\ShopModule;
use App\Repository\AdminUserRepository;
use App\Repository\CollectionRepository;
use App\Repository\ProductRepository;
use App\Repository\SiteLanguageRepository;
use App\Service\CollectionContent;
use App\Service\Language\SiteLanguages;
use App\Service\ShopLocalization;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestSession;
use Tests\Support\BuiltInServer;

/**
 * THE ADDRESS OF A COLLECTION, PER LANGUAGE, as an editor gives it one
 * (Multilingual 2.0 phase 6, docs/multilingual/ROUTING.md §8).
 *
 * The same contract Tests\Blog\BlogTaxonomyAddressEditorTest proves for the
 * two blog taxonomies, for the one Shop entity that has a slug URL: the field
 * belongs to the language being edited, saving one language never moves
 * another's address, a renamed collection keeps its URL, a language without
 * one gets a first address from its own name, collisions are checked inside
 * one language plus the neutral column, a refused save comes back with what
 * was typed, and the address really resolves.
 *
 * PLUS THE ONE THING ONLY A COLLECTION HAS: everything else about it must
 * come through untouched. Its id, its product membership AND that
 * membership's order, its related-products configuration and its published
 * state are language-neutral, and saving a translation may not move a single
 * one of them. That is what the last group here is for.
 *
 * Over real HTTP against PHP's built-in server with
 * tests/Support/dispatcher-router.php in front of it, like
 * Tests\Service\DispatcherRoutingTest. Every row and registry entry is this
 * test's own and is removed in tearDown(). Without a server the test skips
 * itself.
 */
final class CollectionAddressEditorTest extends TestCase
{
    private const PREFIX = 'zz-colladdr-';

    private static ?BuiltInServer $server = null;

    private AdminTestSession $accounts;

    private CollectionRepository $collections;
    private ProductRepository $products;

    /** @var list<int> */
    private array $createdCollections = [];
    /** @var list<int> */
    private array $createdProducts = [];

    private bool $addedGerman = false;

    public static function setUpBeforeClass(): void
    {
        self::$server = BuiltInServer::start([], 'tests/Support/dispatcher-router.php');
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
        $this->collections = new CollectionRepository();
        $this->products = new ProductRepository();

        self::assertSame('nl', ShopLocalization::defaultLanguage(), 'this test expects the Dutch-default test database');
    }

    protected function tearDown(): void
    {
        $db = Database::connection();

        foreach ($this->createdCollections as $id) {
            $this->collections->delete($id);
        }
        foreach ($this->createdProducts as $id) {
            $db->prepare('DELETE FROM products WHERE id = :id')->execute(['id' => $id]);
        }

        if ($this->addedGerman) {
            $db->prepare("DELETE FROM collection_translations WHERE language_code = 'de'")->execute();
            (new SiteLanguageRepository())->delete('de');
            $this->addedGerman = false;
        }

        $this->createdCollections = $this->createdProducts = [];

        $this->accounts->forget();
        ShopLocalization::clearCache();
        CollectionContent::clearCache();
        SiteLanguages::clearCache();
    }

    /* ------------------------------------------------------------------ */
    /* The screen                                                          */
    /* ------------------------------------------------------------------ */

    public function testTheDefaultLanguageShowsItsOwnAddress(): void
    {
        $id = $this->collection('Hout', self::PREFIX . 'hout');
        $session = $this->signIn(null);

        $html = $this->screen($session, '/admin/collection.php?id=' . $id);

        self::assertStringContainsString('name="language_code" value="nl"', $html);
        self::assertSame(self::PREFIX . 'hout', $this->slugField($html));
        self::assertStringContainsString('/collecties/' . self::PREFIX . 'hout', $html, 'and the public page it names');
    }

    public function testATranslationShowsAnEmptyAddressAndSaysThereIsNoUrlYet(): void
    {
        $id = $this->collection('Hout', self::PREFIX . 'hout2');
        $session = $this->signIn('en');

        $html = $this->screen($session, '/admin/collection.php?id=' . $id);

        self::assertStringContainsString('name="language_code" value="en"', $html);
        self::assertSame('', $this->slugField($html));
        self::assertStringContainsString('geen webadres in deze taal', $html);
    }

    /* ------------------------------------------------------------------ */
    /* Saving one language                                                 */
    /* ------------------------------------------------------------------ */

    public function testSavingOneLanguagesAddressLeavesEveryOtherOneAlone(): void
    {
        $id = $this->collection('Hout', self::PREFIX . 'hout3');
        $session = $this->signIn('en');

        $response = $this->save($session, $id, 'en', ['name' => 'Wood', 'slug' => self::PREFIX . 'wood']);

        self::assertStringContainsString('updated=1', $response['location']);
        ShopLocalization::clearCache();

        $collection = $this->collections->findById($id);
        self::assertSame(self::PREFIX . 'wood', ShopLocalization::collectionSlug($collection, 'en'));
        self::assertSame(self::PREFIX . 'hout3', ShopLocalization::collectionSlug($collection, 'nl'), 'the Dutch address did not move');
        self::assertSame(self::PREFIX . 'hout3', (string) $collection['slug'], 'and neither did the neutral key');
    }

    public function testATranslationGetsItsFirstAddressFromItsOwnNameAndKeepsItAfterARename(): void
    {
        $id = $this->collection('Gereedschap', self::PREFIX . 'gereedschap');
        $session = $this->signIn('en');

        $this->save($session, $id, 'en', ['name' => 'Zz Colladdr Tools', 'slug' => '']);
        ShopLocalization::clearCache();
        self::assertSame('zz-colladdr-tools', ShopLocalization::collectionSlug($this->collections->findById($id), 'en'));

        $this->save($session, $id, 'en', ['name' => 'Zz Colladdr Equipment', 'slug' => 'zz-colladdr-tools']);
        ShopLocalization::clearCache();
        self::assertSame('zz-colladdr-tools', ShopLocalization::collectionSlug($this->collections->findById($id), 'en'));
        self::assertSame('Zz Colladdr Equipment', ShopLocalization::rawCollection($id, ShopLocalization::NAME, 'en'));
    }

    /**
     * The DEFAULT language keeps the behaviour this endpoint always had: a
     * blank field regenerates from the (possibly renamed) name rather than
     * refusing to save, and an unchanged name keeps producing the slug the
     * collection already has.
     */
    public function testTheDefaultLanguageRegeneratesFromItsNameWhenTheFieldIsBlank(): void
    {
        $id = $this->collection('Zz Colladdr Basis', self::PREFIX . 'basis');
        $session = $this->signIn(null);

        $this->save($session, $id, 'nl', ['name' => 'Zz Colladdr Basis', 'slug' => '']);
        ShopLocalization::clearCache();

        $collection = $this->collections->findById($id);
        self::assertSame('zz-colladdr-basis', (string) $collection['slug']);
        self::assertSame('zz-colladdr-basis', ShopLocalization::collectionSlug($collection, 'nl'), 'the neutral key and the address stay identical');
    }

    public function testClearingATranslationsAddressTakesAwayItsPublicUrlAndNothingElse(): void
    {
        $id = $this->collection('Metaal', self::PREFIX . 'metaal');
        $session = $this->signIn('en');

        $this->save($session, $id, 'en', ['name' => 'Metal', 'slug' => self::PREFIX . 'metal']);
        $response = $this->save($session, $id, 'en', ['name' => 'Metal', 'slug' => '']);

        self::assertStringContainsString('updated=1', $response['location']);
        ShopLocalization::clearCache();

        $collection = $this->collections->findById($id);
        self::assertNull(ShopLocalization::collectionSlug($collection, 'en'));
        self::assertSame('Metal', ShopLocalization::rawCollection($id, ShopLocalization::NAME, 'en'), 'the words stay');
        self::assertSame(self::PREFIX . 'metaal', (string) $collection['slug']);
    }

    /* ------------------------------------------------------------------ */
    /* Collisions                                                          */
    /* ------------------------------------------------------------------ */

    public function testAnAddressAlreadyTakenInTheSameLanguageIsRefused(): void
    {
        $taken = $this->collection('Bezet', self::PREFIX . 'bezet');
        $other = $this->collection('Vrij', self::PREFIX . 'vrij');
        $session = $this->signIn('en');

        $this->save($session, $taken, 'en', ['name' => 'Taken', 'slug' => self::PREFIX . 'taken']);

        $response = $this->save($session, $other, 'en', ['name' => 'Free', 'slug' => self::PREFIX . 'taken']);

        self::assertStringNotContainsString('updated=1', $response['location']);
        self::assertNotEmpty($this->accounts->read($session, 'admin_collection_errors'));
        ShopLocalization::clearCache();
        self::assertNull(ShopLocalization::collectionSlug($this->collections->findById($other), 'en'), 'nothing was written');
    }

    public function testTheSameWordInTwoLanguagesIsNotACollision(): void
    {
        $id = $this->collection('Deelbaar', self::PREFIX . 'deelbaar');
        $session = $this->signIn('en');

        $response = $this->save($session, $id, 'en', ['name' => 'Shared', 'slug' => self::PREFIX . 'deelbaar']);

        self::assertStringContainsString('updated=1', $response['location']);
        ShopLocalization::clearCache();
        self::assertSame(self::PREFIX . 'deelbaar', ShopLocalization::collectionSlug($this->collections->findById($id), 'en'));
    }

    public function testADefaultLanguageAddressIsAlsoCheckedAgainstTheNeutralColumn(): void
    {
        $this->collection('Eerste', self::PREFIX . 'eerste');
        $second = $this->collection('Tweede', self::PREFIX . 'tweede');
        $session = $this->signIn(null);

        $response = $this->save($session, $second, 'nl', ['name' => 'Tweede', 'slug' => self::PREFIX . 'eerste']);

        self::assertStringNotContainsString('updated=1', $response['location']);
        self::assertSame(self::PREFIX . 'tweede', (string) $this->collections->findById($second)['slug']);
    }

    /**
     * A collection's namespace is /collecties/… in Dutch and /en/collections/…
     * in English, and both spellings are reserved for it — so a CMS page can
     * never claim either of them out from under a collection URL.
     */
    public function testBothSpellingsOfTheCollectionNamespaceAreReservedForPageSlugs(): void
    {
        self::assertTrue(\App\Service\Routing\ReservedPaths::isReserved('collecties'));
        self::assertTrue(\App\Service\Routing\ReservedPaths::isReserved('collections'));
    }

    /* ------------------------------------------------------------------ */
    /* A refused save                                                      */
    /* ------------------------------------------------------------------ */

    public function testARefusedSaveKeepsTheTypedAddressOnScreen(): void
    {
        $taken = $this->collection('Bezet', self::PREFIX . 'bezet2');
        $id = $this->collection('Geweigerd', self::PREFIX . 'geweigerd');
        $session = $this->signIn('en');

        $this->save($session, $taken, 'en', ['name' => 'Taken', 'slug' => self::PREFIX . 'taken2']);
        $this->save($session, $id, 'en', ['name' => 'Refused', 'slug' => self::PREFIX . 'taken2']);

        $html = $this->screen($session, '/admin/collection.php?id=' . $id);

        self::assertSame(self::PREFIX . 'taken2', $this->slugField($html), 'the typed address is still there');
        self::assertStringContainsString('admin-alert--error', $html, 'with the reason');

        $again = $this->screen($session, '/admin/collection.php?id=' . $id);
        self::assertSame('', $this->slugField($again), 'the next visit shows the stored collection');
    }

    /**
     * REGRESSION. The handed-back input belongs to the language it was typed
     * in, as it does on the page editor and the two blog taxonomy screens: an
     * editor who moves to another language before looking again must see
     * THAT language's stored words and address, not the ones just refused.
     */
    public function testARefusedSaveInOneLanguageNeverAppearsOnAnotherLanguagesForm(): void
    {
        $taken = $this->collection('Bezet', self::PREFIX . 'bezet3');
        $id = $this->collection('Taalgebonden', self::PREFIX . 'taalgebonden');
        $session = $this->signIn('en');

        $this->save($session, $taken, 'en', ['name' => 'Taken', 'slug' => self::PREFIX . 'taken3']);
        $this->save($session, $id, 'en', ['name' => 'Refused in English', 'slug' => self::PREFIX . 'taken3']);

        (new AdminUserRepository())->updateContentEditingLanguage(
            (int) $this->accounts->read($session, 'admin_user_id'),
            'nl'
        );

        $html = $this->screen($session, '/admin/collection.php?id=' . $id);

        self::assertSame(self::PREFIX . 'taalgebonden', $this->slugField($html), 'the Dutch form shows the Dutch address');
        self::assertStringNotContainsString('Refused in English', $html, 'and not the English words that were refused');
    }

    /* ------------------------------------------------------------------ */
    /* The address really resolves                                         */
    /* ------------------------------------------------------------------ */

    public function testEachLanguagesCollectionAnswersAtItsOwnAddressAndNowhereElse(): void
    {
        $id = $this->collection('Routebaar', self::PREFIX . 'routebaar');
        $session = $this->signIn('en');

        $this->save($session, $id, 'en', ['name' => 'Routable', 'slug' => self::PREFIX . 'routable']);

        self::assertSame(200, $this->get('/collecties/' . self::PREFIX . 'routebaar')['status']);
        self::assertSame(200, $this->get('/en/collections/' . self::PREFIX . 'routable')['status']);

        self::assertSame(404, $this->get('/collecties/' . self::PREFIX . 'routable')['status']);
        self::assertSame(404, $this->get('/en/collections/' . self::PREFIX . 'routebaar')['status']);
    }

    public function testAThirdLanguageNeedsOnlyARowInTheRegistry(): void
    {
        $this->registerGerman();

        $id = $this->collection('Derde taal', self::PREFIX . 'derde');
        $session = $this->signIn('de');

        $response = $this->save($session, $id, 'de', ['name' => 'Dritte Sprache', 'slug' => self::PREFIX . 'dritte']);

        self::assertStringContainsString('updated=1', $response['location']);
        ShopLocalization::clearCache();
        self::assertSame(self::PREFIX . 'dritte', ShopLocalization::collectionSlug($this->collections->findById($id), 'de'));

        // German has no word of its own for the collections segment, so it
        // uses the catalogue's default — a working URL, not a 404.
        self::assertSame(200, $this->get('/de/collecties/' . self::PREFIX . 'dritte')['status']);
    }

    /* ------------------------------------------------------------------ */
    /* Everything else about a collection is untouched                     */
    /* ------------------------------------------------------------------ */

    public function testATranslationSaveMovesNothingButThatLanguagesAddress(): void
    {
        $id = $this->collection('Compleet', self::PREFIX . 'compleet');
        $first = $this->product('Zz Colladdr product een');
        $second = $this->product('Zz Colladdr product twee');
        $this->collections->setCollectionProducts($id, [$second, $first]);
        $this->collections->updateRelatedProductsSettings($id, false);

        $before = $this->collections->findById($id);
        $session = $this->signIn('en');

        $response = $this->save($session, $id, 'en', [
            'name' => 'Complete',
            'slug' => self::PREFIX . 'complete',
            'products_submitted' => '1',
            'product_ids' => [(string) $second, (string) $first],
        ]);

        self::assertStringContainsString('updated=1', $response['location']);
        ShopLocalization::clearCache();

        $after = $this->collections->findById($id);

        self::assertSame((int) $before['id'], (int) $after['id']);
        self::assertSame((string) $before['slug'], (string) $after['slug'], 'the neutral slug is compatibility, and it stays');
        self::assertSame((int) $before['is_active'], (int) $after['is_active']);
        self::assertSame(
            (int) $before['show_related_products'],
            (int) $after['show_related_products'],
            'the related-products configuration is language-neutral'
        );
        self::assertSame(
            [$second, $first],
            $this->collections->productIdsForCollection($id),
            'the membership AND its order come through untouched'
        );
        self::assertSame(self::PREFIX . 'complete', ShopLocalization::collectionSlug($after, 'en'));
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                             */
    /* ------------------------------------------------------------------ */

    private function signIn(?string $editingLanguage): string
    {
        [$session] = $this->accounts->signIn([ShopModule::COLLECTIONS_MANAGE]);

        if ($editingLanguage !== null) {
            (new AdminUserRepository())->updateContentEditingLanguage(
                (int) $this->accounts->read($session, 'admin_user_id'),
                $editingLanguage
            );
        }

        return $session;
    }

    private function csrf(string $session): string
    {
        return (string) $this->accounts->read($session, 'csrf_token');
    }

    /** @return array{status: int, location: string, body: string, headers: string} */
    private function get(string $path): array
    {
        return self::$server->request('GET', $path);
    }

    private function screen(string $session, string $path): string
    {
        $response = self::$server->request('GET', $path, $session);
        self::assertSame(200, $response['status'], $path);

        return $response['body'];
    }

    /**
     * @param array<string, mixed> $fields
     * @return array{status: int, location: string, body: string, headers: string}
     */
    private function save(string $session, int $id, string $language, array $fields): array
    {
        return self::$server->request('POST', '/api/admin/update-collection.php', $session, $fields + [
            'csrf_token' => $this->csrf($session),
            'id' => (string) $id,
            'language_code' => $language,
            'description' => '',
            'meta_title' => '',
            'meta_description' => '',
            'is_active' => '1',
        ]);
    }

    /** The one slug input on the collection editor. */
    private function slugField(string $html): string
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_use_internal_errors($previous);

        $nodes = (new \DOMXPath($document))->query('//input[@name="slug"]');
        self::assertNotFalse($nodes);
        self::assertSame(1, $nodes->length, 'one slug field on the collection editor');

        return $nodes->item(0)->getAttribute('value');
    }

    private function collection(string $name, string $slug): int
    {
        $id = $this->collections->create([
            'slug' => $slug,
            'image_path' => null,
            'is_active' => true,
        ]);
        $this->createdCollections[] = $id;

        ShopLocalization::saveCollection($id, 'nl', [
            ShopLocalization::SLUG => $slug,
            ShopLocalization::NAME => $name,
        ]);
        ShopLocalization::clearCache();
        CollectionContent::clearCache();

        return $id;
    }

    private function product(string $name): int
    {
        $id = $this->products->create([
            'slug' => self::PREFIX . 'p-' . bin2hex(random_bytes(5)),
            'price' => 9.95,
            'image_path' => null,
            'active' => true,
            'in_shop' => true,
            'in_personalization_catalog' => false,
            'shipping_profile' => 'letter',
            'shipping_weight_grams' => 40,
            'requires_parcel' => false,
        ]);
        $this->createdProducts[] = $id;

        ShopLocalization::saveProduct($id, 'nl', [ShopLocalization::NAME => $name]);
        ShopLocalization::clearCache();

        return $id;
    }

    private function registerGerman(): void
    {
        (new SiteLanguageRepository())->create('de', 'German', 'Deutsch');
        $this->addedGerman = true;
        SiteLanguages::clearCache();
    }
}

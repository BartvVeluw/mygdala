<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Module\ModuleRegistry;
use App\Repository\PageSectionRepository;
use App\Service\Blocks\BlockCategories;
use App\Service\Blocks\BlockDefinitions;
use App\Service\Blocks\BlockSamples;
use App\Service\Language\AdminLocale;
use App\Service\Language\AdminTranslator;
use App\Service\SectionRegistry;
use PHPUnit\Framework\TestCase;

/**
 * The block picker and the page editor's save bar: the two pieces of UX the
 * page editor gained, checked where they can be checked without a browser.
 * The picker's search box, its two views and the view it remembers are
 * pinned here as well; what a click does with them is checked by hand
 * (PAGE-EDITOR.md).
 *
 * WHAT THIS FILE IS ABOUT. Both features are deliberately thin: the picker is
 * one ordinary POST form whose submit buttons happen to look like cards, and
 * the save bar drives the forms that were already on the screen. The risk is
 * therefore not that they misbehave, it is that they quietly become a SECOND
 * way to do something — a second list of addable blocks that disagrees with
 * the server's, a page-wide endpoint that bypasses per-block validation, a
 * save button that reaches the sidebar's logout form. Those are the
 * properties pinned below.
 *
 * The picker's markup is rendered in-process: admin/_block_picker.php is a
 * plain include that defines two output functions, so it can be required and
 * called with a real definition list without a login, a request or a
 * database. The repository it needs is stubbed for the same reason (see
 * FakePageSectionRepository at the bottom).
 *
 * Behaviour that genuinely needs the database — that creating really attaches
 * exactly one row to the right page, that a capped type stops being offered —
 * is Tests\Service\SectionRegistryTest's, and was already there before the
 * picker existed. This file does not repeat it.
 */
final class BlockPickerTest extends TestCase
{
    /** @var array<string, mixed> a `pages` row shaped like a real one */
    private const PAGE = [
        'id' => 4242,
        'content_key' => '__picker__',
        'slug' => '__picker__',
        'title' => 'Pickertestpagina',
        'status' => 'published',
    ];

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/admin/_block_picker.php';
    }

    protected function setUp(): void
    {
        AdminLocale::overrideForTests('nl');
    }

    protected function tearDown(): void
    {
        // overrideForTests(null) and not reset(): reset() forgets the caches
        // but KEEPS the override, so a test that switched the Shop off would
        // leave it off for the rest of the run.
        ModuleRegistry::overrideForTests(null);
        AdminLocale::overrideForTests(null);
        parent::tearDown();
    }

    /**
     * @param list<string> $existingTypes types already on the page
     */
    private function renderPicker(array $existingTypes = []): string
    {
        $available = SectionRegistry::availableDefinitionsForPage(
            self::PAGE,
            new FakePageSectionRepository($existingTypes)
        );

        ob_start();
        block_picker_modal($available, (int) self::PAGE['id'], 'test-csrf-token');

        return (string) ob_get_clean();
    }

    /**
     * The picker's cards as its markup posts them: one per block, or one per
     * preset of a block that offers presets (the gallery's Collectiegalerij
     * and Portfoliogalerij), each with the name and value its button posts.
     *
     * @param list<string> $existingTypes
     * @return list<array<string, mixed>>
     */
    private function cards(array $existingTypes = []): array
    {
        $available = SectionRegistry::availableDefinitionsForPage(self::PAGE, new FakePageSectionRepository($existingTypes));
        $cards = [];

        foreach (block_picker_cards($available) as $group) {
            foreach ($group as $card) {
                $card['name'] = $card['preset'] === null ? 'section_type' : 'section_preset';
                $card['value'] = $card['preset'] === null ? $card['type'] : $card['type'] . ':' . $card['preset'];
                $cards[] = $card;
            }
        }

        return $cards;
    }

    private function sourceOf(string $relativePath): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relativePath;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /** Markup read back as a document, so attribute order is free to change. */
    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8"?><div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new \DOMXPath($document);
    }

    private function one(\DOMXPath $xpath, string $query, ?\DOMNode $context = null): \DOMElement
    {
        $nodes = $xpath->query($query, $context);
        $this->assertNotFalse($nodes, $query);
        $this->assertSame(1, $nodes->length, 'expected exactly one match for ' . $query);

        $node = $nodes->item(0);
        $this->assertInstanceOf(\DOMElement::class, $node);

        return $node;
    }

    // --- The old control is really gone --------------------------------

    public function testThePageBuilderNoLongerAsksForASectionTypeInADropdown(): void
    {
        $source = $this->sourceOf('admin/page.php');

        $this->assertStringNotContainsString(
            'select name="section_type"',
            str_replace(['<select ', '  '], ['select ', ' '], $source),
            'the section-type dropdown must be gone — the picker replaced it'
        );
        $this->assertStringNotContainsString('Sectie toevoegen', $source);
        $this->assertStringNotContainsString('admin-add-section-form', $source);

        // The button is part of the block list every page editor shares
        // (admin/_content_blocks.php); the panel it opens is printed once, here.
        $this->assertStringContainsString('block_picker_button()', $this->sourceOf('admin/_content_blocks.php'));
        $this->assertStringContainsString('content_blocks_list(', $source);
        $this->assertStringContainsString('block_picker_modal(', $source);
    }

    // --- What the picker offers ----------------------------------------

    public function testThePickerShowsACardForEveryBlockTheServerWouldAccept(): void
    {
        $available = SectionRegistry::availableDefinitionsForPage(self::PAGE, new FakePageSectionRepository());
        $html = $this->renderPicker();

        $this->assertNotSame([], $available);

        $cardTypes = [];
        foreach ($this->cards() as $card) {
            $this->assertStringContainsString(
                'name="' . $card['name'] . '" value="' . $card['value'] . '"',
                $html,
                "{$card['value']} may be added to this page but has no card"
            );
            $this->assertStringContainsString(htmlspecialchars($card['label'], ENT_QUOTES, 'UTF-8'), $html);
            $this->assertStringContainsString(htmlspecialchars($card['description'], ENT_QUOTES, 'UTF-8'), $html);
            $cardTypes[$card['type']] = true;
        }

        $this->assertEqualsCanonicalizing(array_keys($available), array_keys($cardTypes), 'every available block has a card, and nothing else does');
        $this->assertSame(
            count($this->cards()),
            substr_count($html, 'data-block-card'),
            'one card per available block, or per preset of one, no more and no fewer'
        );
    }

    /**
     * The gallery is ONE block type that the picker shows as two presets
     * (App\Service\Blocks\OffersPickerPresets): Collectiegalerij under Shop,
     * Portfoliogalerij under Portfolio, each posting its type and its source.
     * Projecten sits under Portfolio too; no card of its own is left for the
     * gallery under Beeld & media, and nothing else moved.
     */
    public function testTheGalleryIsTwoPresetsUnderShopAndPortfolio(): void
    {
        $byValue = [];
        foreach ($this->cards() as $card) {
            $byValue[$card['value']] = [$card['name'], $card['category'], $card['label']];
        }

        $this->assertSame(['section_preset', 'shop', 'Collectiegalerij'], $byValue['item_gallery:collection']);
        $this->assertSame(['section_preset', 'portfolio', 'Portfoliogalerij'], $byValue['item_gallery:portfolio']);
        $this->assertArrayNotHasKey('item_gallery', $byValue, 'no plain gallery card beside its presets');
        $this->assertSame(['section_type', 'portfolio', 'Projecten'], $byValue['project_cards']);
        $this->assertSame('shop', $byValue['product_grid'][1]);
        $this->assertSame('shop', $byValue['shop_collections'][1]);

        $xpath = $this->xpath($this->renderPicker());
        $shop = $this->one($xpath, '//section[@data-block-picker-group="shop"]');
        $portfolio = $this->one($xpath, '//section[@data-block-picker-group="portfolio"]');
        $this->assertSame('Shop', trim($this->one($xpath, './h3', $shop)->textContent));
        $this->assertSame('Portfolio', trim($this->one($xpath, './h3', $portfolio)->textContent));
        $this->one($xpath, './/button[@value="item_gallery:collection"]', $shop);
        $this->one($xpath, './/button[@value="item_gallery:portfolio"]', $portfolio);
        $this->one($xpath, './/button[@value="project_cards"]', $portfolio);
        $this->assertSame(0, $xpath->query('//section[@data-block-picker-group="media"]//button[starts-with(@value, "item_gallery")]')->length);
        $this->assertSame(1, $xpath->query('//button[@value="item_gallery:collection"]')->length, 'not twice');
        $this->assertSame(1, $xpath->query('//button[@value="item_gallery:portfolio"]')->length, 'not twice');

        // Filed categories in BlockCategories order: Portfolio after Shop, at the end.
        $keys = BlockCategories::keys();
        $this->assertSame(['shop', 'portfolio'], array_slice($keys, -2));
    }

    public function testTheGalleryPresetsFollowTheirModules(): void
    {
        ModuleRegistry::overrideForTests(['shop' => true, 'portfolio' => false, 'multilingual' => true]);
        $values = array_column($this->cards(), 'value');
        $this->assertContains('item_gallery:collection', $values);
        $this->assertNotContains('item_gallery:portfolio', $values);
        $this->assertNotContains('project_cards', $values);
        $this->assertStringNotContainsString('data-block-picker-group="portfolio"', $this->renderPicker(), 'no empty Portfolio heading');
        $this->assertStringNotContainsString('data-block-picker-filter="portfolio"', $this->renderPicker());

        ModuleRegistry::overrideForTests(['shop' => false, 'portfolio' => true, 'multilingual' => true]);
        $values = array_column($this->cards(), 'value');
        $this->assertContains('item_gallery:portfolio', $values);
        $this->assertContains('project_cards', $values);
        $this->assertNotContains('item_gallery:collection', $values);
        $this->assertStringNotContainsString('data-block-picker-group="shop"', $this->renderPicker(), 'no empty Shop heading');

        ModuleRegistry::overrideForTests(['shop' => false, 'portfolio' => false, 'multilingual' => true]);
        $html = $this->renderPicker();
        $this->assertStringNotContainsString('item_gallery', $html, 'no source, no gallery card at all');
        $this->assertStringNotContainsString('data-block-picker-group="shop"', $html);
        $this->assertStringNotContainsString('data-block-picker-group="portfolio"', $html);

        ModuleRegistry::overrideForTests(null);
    }

    public function testTheGalleryPresetsSpeakTheEditorsLanguage(): void
    {
        AdminLocale::overrideForTests('en');
        try {
            $byValue = [];
            foreach ($this->cards() as $card) {
                $byValue[$card['value']] = $card['label'];
            }
            $this->assertSame('Collection gallery', $byValue['item_gallery:collection']);
            $this->assertSame('Portfolio gallery', $byValue['item_gallery:portfolio']);
            $this->assertSame('Portfolio', BlockCategories::label(BlockCategories::PORTFOLIO));
        } finally {
            AdminLocale::overrideForTests('nl');
        }

        $this->assertSame('Portfolio', BlockCategories::label(BlockCategories::PORTFOLIO));
    }

    /**
     * A preset posts `section_preset` = "<type>:<preset>", and the endpoint
     * accepts it only while the type is available AND the preset is one the
     * block offers right now; the block is created with that setting and is
     * the same type as one added without a preset.
     */
    public function testTheEndpointAcceptsOnlyAPresetTheBlockOffers(): void
    {
        $endpoint = $this->sourceOf('api/admin/add-page-section.php');

        $this->assertStringContainsString("\$presetChoice = \$_POST['section_preset'] ?? null;", $endpoint);
        $this->assertStringContainsString("preg_match('/^([a-z0-9_]{1,64}):([a-z0-9_]{1,64})\$/', \$presetChoice, \$choice)", $endpoint);
        $this->assertStringContainsString('($preset !== null && !SectionRegistry::offersPreset($sectionType, $preset))', $endpoint);
        $this->assertStringContainsString("SectionRegistry::create(\$sectionType, (string) \$page['content_key'], \$preset)", $endpoint);
        // The guards still come first.
        $this->assertLessThan(strpos($endpoint, 'section_preset'), strpos($endpoint, 'Csrf::validate'));

        $this->assertTrue(SectionRegistry::offersPreset('item_gallery', 'collection'));
        $this->assertTrue(SectionRegistry::offersPreset('item_gallery', 'portfolio'));
        $this->assertFalse(SectionRegistry::offersPreset('item_gallery', 'products'), 'not a source');
        $this->assertFalse(SectionRegistry::offersPreset('rich_text', 'collection'), 'a block without presets');
        $this->assertFalse(SectionRegistry::offersPreset('no_such_block', 'collection'));

        ModuleRegistry::overrideForTests(['shop' => false, 'portfolio' => true, 'multilingual' => true]);
        $this->assertFalse(SectionRegistry::offersPreset('item_gallery', 'collection'), 'a switched-off module offers no preset');
        ModuleRegistry::overrideForTests(null);
    }

    /**
     * A fixed block is on a page because the site put it there. It is not in
     * the picker, and posting its type is refused by the same list the picker
     * was drawn from — so a card that is not on screen is a request the
     * server does not honour either.
     */
    public function testABlockNobodyMayAddByHandIsNeitherOfferedNorAccepted(): void
    {
        $html = $this->renderPicker();
        $available = SectionRegistry::availableDefinitionsForPage(self::PAGE, new FakePageSectionRepository());

        foreach (BlockDefinitions::types() as $type) {
            if (SectionRegistry::isManuallyAddable($type)) {
                continue;
            }

            $this->assertArrayNotHasKey($type, $available, "{$type} is fixed and must not be offered");
            $this->assertStringNotContainsString('value="' . $type . '"', $html);
        }

        $this->assertArrayNotHasKey('quicknav', $available);
    }

    /**
     * The Shop's product grid and collection tiles are ordinary blocks since
     * the product overview became a page the owner chooses
     * (App\Service\ShopOverview): offered on an ordinary page while the Shop
     * runs, at most once per page. (Not with the Shop off:
     * testTheShopsBlocksFollowTheirModuleInAndOutOfTheCatalogue().)
     */
    public function testTheShopBlocksAreOfferedOnAnOrdinaryPageOncePerPage(): void
    {
        ModuleRegistry::overrideForTests(['shop' => true, 'multilingual' => true]);
        SectionRegistry::reset();

        foreach (['product_grid', 'shop_collections'] as $type) {
            $available = SectionRegistry::availableDefinitionsForPage(self::PAGE, new FakePageSectionRepository());
            $this->assertArrayHasKey($type, $available);
            $this->assertStringContainsString('value="' . $type . '"', $this->renderPicker());

            $this->assertArrayNotHasKey(
                $type,
                SectionRegistry::availableDefinitionsForPage(self::PAGE, new FakePageSectionRepository([$type])),
                'one per page'
            );
            $this->assertStringNotContainsString('value="' . $type . '"', $this->renderPicker([$type]));
        }
    }

    public function testACappedBlockDisappearsFromThePickerOnceThePageHasIt(): void
    {
        $before = $this->renderPicker();
        $this->assertStringContainsString('value="page_hero"', $before);

        $after = $this->renderPicker(['page_hero']);
        $this->assertStringNotContainsString('value="page_hero"', $after);

        // …while a repeatable one is still offered after the page has one.
        $this->assertStringContainsString('value="rich_text"', $after);
    }

    public function testABlockDeniedOnThisPageIsNotOffered(): void
    {
        $homepage = ['id' => 1, 'content_key' => 'index', 'slug' => '', 'title' => 'Home', 'status' => 'published'];
        $available = SectionRegistry::availableDefinitionsForPage($homepage, new FakePageSectionRepository());

        $this->assertArrayHasKey('homepage_hero', $available, 'the homepage has its own hero');
        $this->assertArrayNotHasKey('page_hero', $available, 'and the ordinary one is denied there');

        $this->assertArrayNotHasKey(
            'homepage_hero',
            SectionRegistry::availableDefinitionsForPage(self::PAGE, new FakePageSectionRepository()),
            'the homepage hero belongs to the homepage alone'
        );
    }

    // --- One click, one request ----------------------------------------

    public function testChoosingABlockIsTheOnlySubmitActionInThePicker(): void
    {
        $html = $this->renderPicker();

        $this->assertSame(1, substr_count($html, '<form '), 'the picker is one form');
        $this->assertStringContainsString('action="/api/admin/add-page-section.php"', $html);
        $this->assertStringContainsString('name="csrf_token" value="test-csrf-token"', $html);

        // Every submit button IS a block card: there is no separate
        // "toevoegen" button to press afterwards.
        $this->assertSame(
            substr_count($html, 'data-block-card'),
            substr_count($html, 'type="submit"'),
            'a second submit control would put the confirmation step back'
        );
    }

    public function testThePickerIsExcludedFromTheSaveBarsDirtyTracking(): void
    {
        $this->assertStringContainsString(
            'data-no-dirty-track',
            $this->renderPicker(),
            'choosing a block is not an edit that can be "saved later"'
        );
    }

    // --- Search and filter ---------------------------------------------

    public function testEveryCardCarriesTheTermsTheSearchBoxMatchesOn(): void
    {
        $available = SectionRegistry::availableDefinitionsForPage(self::PAGE, new FakePageSectionRepository());
        $html = $this->renderPicker();

        $this->assertSame(count($this->cards()), substr_count($html, 'data-block-terms='));
        $this->assertSame(count($this->cards()), substr_count($html, 'data-block-category='));

        $richText = $available['rich_text'];
        $this->assertMatchesRegularExpression(
            '/data-block-terms="[^"]*' . preg_quote(mb_strtolower($richText->label()), '/') . '[^"]*"/',
            $html,
            'the label must be searchable'
        );
        $this->assertMatchesRegularExpression(
            '/data-block-terms="[^"]*' . preg_quote(mb_strtolower(mb_substr($richText->description(), 0, 20)), '/') . '/',
            $html,
            'the description must be searchable'
        );
    }

    public function testSearchTermsNeverContainTheRegistryKey(): void
    {
        preg_match_all('/data-block-terms="([^"]*)"/', $this->renderPicker(), $matches);
        $this->assertNotSame([], $matches[1]);

        foreach ($matches[1] as $terms) {
            foreach (BlockDefinitions::types() as $type) {
                if (!str_contains($type, '_')) {
                    // A one-word key like `form` is also an ordinary word;
                    // only an identifier-shaped string is a leak. Same rule
                    // as Tests\Service\BlockPresentationTest.
                    continue;
                }

                $this->assertStringNotContainsString(
                    $type,
                    $terms,
                    'an editor never sees a registry key, so searching for one must not be a feature'
                );
            }
        }
    }

    public function testTheFilterOffersExactlyTheCategoriesThatHaveCards(): void
    {
        // The categories of the CARDS: a preset files its card under its own.
        $categories = array_values(array_unique(array_column($this->cards(), 'category')));

        $html = $this->renderPicker();

        foreach ($categories as $category) {
            $this->assertStringContainsString('data-block-picker-filter="' . $category . '"', $html);
            $this->assertStringContainsString('data-block-picker-group="' . $category . '"', $html);
        }

        // Plus the "Alles" reset, which filters on nothing.
        $this->assertSame(count($categories) + 1, substr_count($html, 'data-block-picker-filter='));
    }

    /**
     * The search box is the CMS's own search field (ADMIN-UI.md): never the
     * browser's white bar, and never a second search component to keep in step.
     */
    public function testTheSearchBoxIsTheSharedAdminSearchField(): void
    {
        $xpath = $this->xpath($this->renderPicker());

        $input = $this->one($xpath, '//input[@data-block-picker-search]');
        $this->assertSame('search', $input->getAttribute('type'));

        $label = $input->parentNode;
        $this->assertInstanceOf(\DOMElement::class, $label);
        $this->assertSame('label', $label->nodeName);
        $this->assertContains('admin-search', explode(' ', $label->getAttribute('class')));
        $this->assertSame('Zoek een contentblok', trim($this->one($xpath, './span[contains(@class, "admin-visually-hidden")]', $label)->textContent));

        $this->assertSame(1, $xpath->query('//input[@type="search"]')->length, 'one search box in the panel');

        // The primitive styles the field; the picker only sizes the label.
        $this->assertStringNotContainsString('.admin-block-picker__search input', $this->sourceOf('admin/assets/admin.css'));
    }

    public function testCardsAndAListAreTwoLayoutsOfTheSameButtons(): void
    {
        $available = SectionRegistry::availableDefinitionsForPage(self::PAGE, new FakePageSectionRepository());
        $html = $this->renderPicker();
        $xpath = $this->xpath($html);

        // Cards is what the server renders.
        $this->assertSame('cards', $this->one($xpath, '//*[@data-block-picker]')->getAttribute('data-block-picker-layout'));

        // A named group of two real buttons, the active one aria-pressed.
        $group = $this->one($xpath, '//*[@role="group"][.//button[@data-block-picker-view]]');
        $this->assertSame('Weergave', $group->getAttribute('aria-label'));

        $views = [];
        foreach ($xpath->query('.//button[@data-block-picker-view]', $group) ?: [] as $button) {
            $this->assertInstanceOf(\DOMElement::class, $button);
            $this->assertSame('button', $button->getAttribute('type'), 'switching the view must never submit the form');

            // The icon is decoration; the word is the name.
            foreach ($xpath->query('.//svg', $button) ?: [] as $icon) {
                $this->assertInstanceOf(\DOMElement::class, $icon);
                $this->assertSame('true', $icon->getAttribute('aria-hidden'));
            }

            $views[$button->getAttribute('data-block-picker-view')] = [$button->getAttribute('aria-pressed'), trim($button->textContent)];
        }

        $this->assertSame(['cards' => ['true', 'Kaarten'], 'list' => ['false', 'Lijst']], $views);

        // Still one submit button per card: the list is not a second copy.
        $this->assertSame(count($this->cards()), substr_count($html, 'data-block-card'));
        $this->assertSame(count($this->cards()), substr_count($html, 'type="submit"'));

        // …and admin.css really lays the same buttons out as rows.
        $css = $this->sourceOf('admin/assets/admin.css');
        $this->assertStringContainsString('.admin-block-picker[data-block-picker-layout="list"] .admin-block-card{', $css);
        $this->assertStringContainsString('.admin-block-picker[data-block-picker-layout="list"] .admin-block-visual{ display: none; }', $css);
    }

    public function testTheChosenViewIsRememberedPerBrowserUnderOneMygdalaKey(): void
    {
        $script = $this->sourceOf('admin/assets/block-picker.js');

        $this->assertSame(1, preg_match_all('/var VIEW_STORAGE_KEY = "([^"]+)";/', $script, $key));
        $this->assertSame('mygdalaAdminBlockPickerView', $key[1][0], 'named like mygdalaAdminHelp and mygdalaAdminTab:');

        preg_match_all('/localStorage\.(?:getItem|setItem|removeItem)\(([^,)]+)/', $script, $uses);
        $this->assertNotSame([], $uses[1], 'the view is remembered in localStorage, per browser');
        $this->assertSame(['VIEW_STORAGE_KEY'], array_values(array_unique(array_map('trim', $uses[1]))), 'every read and write goes through the one key');

        // Cards unless this browser chose otherwise — also when storage is blocked.
        $this->assertStringContainsString('var DEFAULT_VIEW = "cards";', $script);
        $this->assertMatchesRegularExpression('/try \{\s*var stored = window\.localStorage\.getItem\(VIEW_STORAGE_KEY\);[\s\S]{0,160}\} catch \(e\) \{[\s\S]{0,120}return DEFAULT_VIEW;/', $script);

        // A preference of this browser: no column, no request.
        $this->assertStringNotContainsString('sessionStorage', $script);
        $this->assertStringNotContainsString('fetch(', $script);
        $this->assertDoesNotMatchRegularExpression('/vvl/i', $script, "the old site's storage prefix");
    }

    public function testEveryCardNamesItsCategory(): void
    {
        $xpath = $this->xpath($this->renderPicker());

        foreach ($this->cards() as $card) {
            $type = $card['value'];
            $this->assertSame(
                BlockCategories::label($card['category']),
                trim($this->one($xpath, '//button[@value="' . $type . '"]//*[contains(@class, "admin-block-card__category")]')->textContent),
                "{$type}'s card does not say which category it is in"
            );
            $this->assertSame($card['category'], $this->one($xpath, '//button[@value="' . $type . '"]')->getAttribute('data-block-category'));
        }
    }


    // --- Category names: plain text in, escaped once on the way out ----

    /**
     * A category name is plain text in every CMS language: the picker and
     * the catalogue escape it on output ($h), so a catalogue entry that is
     * already escaped ("Images &amp; media") reaches the editor as the
     * literal "&amp;". NL always said "Beeld & media"; EN said it with the
     * entity until it was fixed at the source.
     */
    public function testCategoryNamesArePlainTextInEveryCmsLanguage(): void
    {
        foreach (['nl', 'en'] as $locale) {
            foreach (BlockCategories::keys() as $key) {
                $label = AdminTranslator::trans('blockcategory.' . $key, [], $locale);

                $this->assertSame(
                    html_entity_decode($label, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                    $label,
                    "blockcategory.{$key} ({$locale}) holds an HTML entity; write the character itself"
                );
                $this->assertStringNotContainsString('<', $label, "blockcategory.{$key} ({$locale}) holds markup");
            }
        }

        AdminLocale::overrideForTests('en');
        $this->assertSame('Images & media', BlockCategories::label(BlockCategories::MEDIA));
        $this->assertSame('Action & interaction', BlockCategories::label(BlockCategories::ACTION));
    }

    /**
     * The English picker shows "Images & media" and "Action & interaction",
     * escaped exactly once in the markup, and the Shop and Portfolio headings
     * and the gallery presets keep their names and their categories.
     */
    public function testTheEnglishPickerShowsItsCategoriesAsWritten(): void
    {
        ModuleRegistry::overrideForTests(['shop' => true, 'portfolio' => true, 'multilingual' => true]);
        AdminLocale::overrideForTests('en');

        $html = $this->renderPicker();
        $xpath = $this->xpath($html);

        $this->assertStringNotContainsString('&amp;amp;', $html, 'nothing is escaped twice');
        $this->assertStringContainsString('>Images &amp; media<', $html);
        $this->assertStringContainsString('>Action &amp; interaction<', $html);

        $chips = [];
        foreach ($xpath->query('//button[@data-block-picker-filter!=""]') as $chip) {
            $chips[$chip->getAttribute('data-block-picker-filter')] = trim($chip->textContent);
        }
        $headings = [];
        foreach ($xpath->query('//section[@data-block-picker-group]') as $group) {
            $headings[$group->getAttribute('data-block-picker-group')] = trim($this->one($xpath, './/h3', $group)->textContent);
        }
        foreach ([
            BlockCategories::MEDIA => 'Images & media',
            BlockCategories::ACTION => 'Action & interaction',
            BlockCategories::SHOP => 'Shop',
            BlockCategories::PORTFOLIO => 'Portfolio',
        ] as $category => $name) {
            $this->assertSame($name, $chips[$category] ?? null, "filter chip {$category}");
            $this->assertSame($name, $headings[$category] ?? null, "group heading {$category}");
        }

        $cards = [];
        foreach ($this->cards() as $card) {
            $cards[$card['value']] = [$card['label'], $card['category']];
        }
        $this->assertSame(['Collection gallery', BlockCategories::SHOP], $cards['item_gallery:collection']);
        $this->assertSame(['Portfolio gallery', BlockCategories::PORTFOLIO], $cards['item_gallery:portfolio']);
        $this->assertSame(['Projects', BlockCategories::PORTFOLIO], $cards['project_cards']);

        $this->assertStringContainsString('images & media', $this->one($xpath, '//button[@value="media_banner"]')->getAttribute('data-block-terms'), 'the search terms hold the name as written');
    }

    /**
     * Fixing the source did not switch escaping off: a category name that
     * really holds markup still reaches the page as text.
     */
    public function testACategoryNameIsStillEscapedOnTheWayOut(): void
    {
        AdminLocale::overrideForTests('en');
        $catalogs = new \ReflectionProperty(AdminTranslator::class, 'catalogs');
        $english = AdminTranslator::catalog('en');
        $english['blockcategory.media'] = '<script>alert(1)</script> & media';
        $catalogs->setValue(null, ['en' => $english] + $catalogs->getValue());

        try {
            $html = $this->renderPicker();
        } finally {
            AdminTranslator::clearCache();
        }

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('>&lt;script&gt;alert(1)&lt;/script&gt; &amp; media<', $html);
    }

    /**
     * A card never lists all of a block's example uses. They are searchable,
     * and each is on the card, hidden until a search matches it — the script
     * shows only the ones that matched.
     */
    public function testExampleUsesStayHiddenUntilASearchMatchesOne(): void
    {
        $xpath = $this->xpath($this->renderPicker());

        $checked = 0;
        foreach ($this->cards() as $card) {
            $type = $card['value'];
            $cases = $card['use_cases'];
            $containers = $xpath->query('//button[@value="' . $type . '"]//*[@data-block-uses]');
            $this->assertNotFalse($containers);

            if ($cases === []) {
                $this->assertSame(0, $containers->length, "{$type} has no example uses to show");
                continue;
            }

            $container = $this->one($xpath, '//button[@value="' . $type . '"]//*[@data-block-uses]');
            $this->assertTrue($container->hasAttribute('hidden'), "{$type} shows its example uses before anybody searched");

            $shown = [];
            foreach ($xpath->query('.//*[@data-block-use]', $container) ?: [] as $use) {
                $this->assertInstanceOf(\DOMElement::class, $use);
                $this->assertTrue($use->hasAttribute('hidden'));
                $this->assertSame(mb_strtolower(trim($use->textContent)), $use->getAttribute('data-block-use'));
                $shown[] = trim($use->textContent);
            }

            $this->assertSame($cases, $shown);
            $checked++;
        }

        $this->assertGreaterThan(0, $checked, 'no block on this page has example uses, so nothing was checked');

        $this->assertStringContainsString(
            '(use.getAttribute("data-block-use") || "").indexOf(term) !== -1',
            $this->sourceOf('admin/assets/block-picker.js'),
            'a use is shown only when the search term matches it'
        );
    }

    public function testSearchingStillFiltersOnTheSameTermsAndCategory(): void
    {
        // As LF whatever the checkout: with core.autocrlf=true the file on
        // disk has CRLF, and the setView pattern below spells a break as \n.
        $script = str_replace("\r\n", "\n", $this->sourceOf('admin/assets/block-picker.js'));

        $this->assertStringContainsString('(card.getAttribute("data-block-terms") || "").indexOf(term) !== -1', $script);
        $this->assertStringContainsString('card.getAttribute("data-block-category") === activeCategory', $script);
        $this->assertStringContainsString('searchInput.addEventListener("input", applyFilter);', $script);
        $this->assertStringContainsString('searchInput.addEventListener("search", applyFilter);', $script);

        // Switching the view filters, renders and moves nothing.
        $this->assertMatchesRegularExpression('/function setView\(view\) \{\n(?:(?!applyFilter|innerHTML|appendChild|insertBefore)[\s\S])*?\n  \}/', $script);
    }

    // --- A page without content yet -------------------------------------

    public function testTheEmptyStateInvitesTheFirstBlockThroughTheSamePicker(): void
    {
        ob_start();
        block_picker_empty_state(true);
        $xpath = $this->xpath((string) ob_get_clean());

        $empty = $this->one($xpath, '//*[@data-block-picker-empty]');
        $this->assertSame('Je pagina heeft nog geen inhoud.', trim($this->one($xpath, './/*[contains(@class, "admin-blocks-empty__title")]', $empty)->textContent));
        $this->assertSame('Voeg hieronder je eerste contentblok toe.', trim($this->one($xpath, './/*[contains(@class, "admin-blocks-empty__text")]', $empty)->textContent));

        // One real button, and it opens the picker the page already has: not
        // a second picker and not a form of its own.
        $button = $this->one($xpath, './/button', $empty);
        $this->assertSame('button', $button->getAttribute('type'));
        $this->assertTrue($button->hasAttribute('data-block-picker-open'));
        $this->assertSame('dialog', $button->getAttribute('aria-haspopup'));
        $this->assertStringContainsString('Contentblok toevoegen', $button->textContent);
        $this->assertSame(0, $xpath->query('//form')->length);
        $this->assertSame(0, $xpath->query('//*[@data-block-picker]')->length);

        // Plain words, nothing an editor would have to decode.
        foreach (['page_sections', 'sectie', 'section'] as $jargon) {
            $this->assertStringNotContainsStringIgnoringCase($jargon, $empty->textContent);
        }

        // block-picker.js opens the panel from every such button.
        $this->assertStringContainsString('document.querySelectorAll("[data-block-picker-open]")', $this->sourceOf('admin/assets/block-picker.js'));
    }

    public function testTheEmptyStateOffersNoButtonWhenNothingMayBeAdded(): void
    {
        ob_start();
        block_picker_empty_state(false);
        $xpath = $this->xpath((string) ob_get_clean());

        $this->assertSame(0, $xpath->query('//button')->length);
        $this->assertStringContainsString(
            'geen contentblok meer dat je kunt toevoegen',
            $this->one($xpath, '//*[@data-block-picker-empty]')->textContent
        );
    }

    /**
     * What decides between the invitation and the ordinary opener: a block
     * below the page's head. Read from the category, so no type is named.
     */
    public function testAPageHasContentOnceItHoldsABlockBeyondItsHead(): void
    {
        $row = static fn (string $type, int $active = 1): array => [
            'id' => 1,
            'page_id' => 1,
            'section_type' => $type,
            'section_key' => null,
            'section_id' => 1,
            'sort_order' => 0,
            'is_active' => $active,
        ];

        $this->assertFalse(SectionRegistry::hasContentBlocks([]));
        $this->assertFalse(SectionRegistry::hasContentBlocks([$row('page_hero')]), 'a heading alone is no content yet');
        $this->assertFalse(SectionRegistry::hasContentBlocks([$row('homepage_hero')]), "the homepage's own head is a head too");

        $this->assertTrue(SectionRegistry::hasContentBlocks([$row('page_hero'), $row('rich_text')]));
        $this->assertTrue(SectionRegistry::hasContentBlocks([$row('page_hero'), $row('rich_text', 0)]), "a hidden block is still the editor's content");
        $this->assertTrue(SectionRegistry::hasContentBlocks([$row('zz_no_such_block')]), 'a row the list shows is never "no content"');
    }

    // --- Modules --------------------------------------------------------

    public function testTheShopsBlocksFollowTheirModuleInAndOutOfTheCatalogue(): void
    {
        ModuleRegistry::overrideForTests(['shop' => true, 'multilingual' => true]);
        $shopPage = ['id' => 9, 'content_key' => 'shop', 'slug' => 'shop', 'title' => 'Shop', 'status' => 'published'];
        $this->assertArrayHasKey('product_grid', BlockDefinitions::all());

        ModuleRegistry::overrideForTests(['shop' => false, 'multilingual' => true]);
        SectionRegistry::reset();

        $html = $this->renderPicker();
        $this->assertStringNotContainsString('value="product_grid"', $html);
        $this->assertStringNotContainsString('value="shop_collections"', $html);

        $this->assertArrayNotHasKey(
            'product_grid',
            SectionRegistry::availableDefinitionsForPage($shopPage, new FakePageSectionRepository()),
            'a switched-off module\'s block is not addable anywhere'
        );

        // Core is unaffected.
        $this->assertStringContainsString('value="rich_text"', $html);
    }

    // --- Voorbeeld: the library's preview, from the picker ---------------

    /**
     * Every card has a Voorbeeld button beside it (not in it: the card is the
     * submit button), made by the one helper the Contentblokken library uses,
     * so the address, the frame title and the words are the library's own.
     * It is a plain button: it previews and adds nothing.
     */
    public function testEveryCardHasTheLibrarysPreviewButtonBesideIt(): void
    {
        $xpath = $this->xpath($this->renderPicker());

        foreach ($this->cards() as $card) {
            $definition = $card['definition'];
            $type = $card['type'];
            $slot = $this->one($xpath, '//*[@data-block-slot][button[@type="submit" and @value="' . $card['value'] . '"]]');
            $preview = $this->one($xpath, './button[@data-block-preview-open]', $slot);

            $this->assertSame('button', $preview->getAttribute('type'), "{$type}: previewing never submits");
            $this->assertSame('dialog', $preview->getAttribute('aria-haspopup'));
            $this->assertStringContainsString('Voorbeeld', $preview->textContent);
            $this->assertStringContainsString($card['label'], $preview->textContent, "{$type}: the button names its card");
            $this->assertSame(
                $definition->sampleContent(new BlockSamples()) !== null ? '/admin/block-preview.php?type=' . $type : '',
                $preview->getAttribute('data-block-preview-src'),
                "{$type}: the library's own preview address, and no other"
            );
            $this->assertSame('Voorbeeld van ' . $card['label'], $preview->getAttribute('data-block-preview-frame-title'));

            // The words the dialog copies, marked on the card.
            $this->one($xpath, './/*[@data-block-slot-name]', $slot);
            $this->one($xpath, './/*[@data-block-slot-description]', $slot);
            $this->one($xpath, './/*[@data-block-slot-category]', $slot);
        }

        $this->assertStringContainsString('function block_library_preview_button(', $this->sourceOf('admin/_block_library.php'));
        $this->assertStringContainsString('block_library_preview_button(', $this->sourceOf('admin/_block_picker.php'));
        $this->assertStringNotContainsString('block-preview.php', $this->sourceOf('admin/_block_picker.php'), 'no second preview address');
    }

    public function testThePickerPrintsTheLibrarysOneSandboxedPreviewDialog(): void
    {
        $xpath = $this->xpath($this->renderPicker());

        $dialog = $this->one($xpath, '//dialog[@data-block-preview]');
        $frame = $this->one($xpath, './/iframe[@data-block-preview-frame]', $dialog);
        $this->assertSame('allow-scripts', $frame->getAttribute('sandbox'));
        $this->assertSame('about:blank', $frame->getAttribute('src'));

        // Nothing to add, nothing to preview: no dialog either.
        ob_start();
        block_picker_modal([], (int) self::PAGE['id'], 'test-csrf-token');
        $this->assertStringNotContainsString('data-block-preview', (string) ob_get_clean());

        $page = $this->sourceOf('admin/page.php');
        $this->assertStringContainsString("AssetVersion::url('/admin/assets/block-library.js')", $page, 'the dialog\'s own script, reused');
    }

    /**
     * Keyboard: the preview is a native modal dialog over the picker. While
     * it is open the picker leaves Escape and Tab alone, so Escape closes the
     * preview and not the picker, and the dialog returns the focus to the
     * Voorbeeld button that opened it (block-library.js, lastFocused).
     */
    public function testThePreviewOwnsTheKeyboardWhileItIsOpenAndGivesTheFocusBack(): void
    {
        $picker = $this->sourceOf('admin/assets/block-picker.js');
        $this->assertMatchesRegularExpression(
            '/if \(panel\.hidden\) return;\s*\n\s*\/\/[^\n]*\n\s*if \(document\.querySelector\("dialog\[open\]"\)\) return;\s*\n\s*if \(event\.key === "Escape"\)/',
            $picker
        );
        $this->assertStringContainsString('card.closest("[data-block-slot]")', $picker, 'a filtered-out card takes its preview button along');

        $library = $this->sourceOf('admin/assets/block-library.js');
        $this->assertStringContainsString('button.closest("[data-block-library-card], [data-block-slot]")', $library);
        $this->assertStringContainsString('lastFocused = button;', $library);
        $this->assertMatchesRegularExpression('/button\.focus\(\);\s*dialog\.showModal\(\);/', $library, 'focused before it opens: the browser restores the focus there too');
        $this->assertStringContainsString('lastFocused.focus()', $library);
        $this->assertStringContainsString('dialog.addEventListener("cancel", finish);', $library, 'Escape closes it and returns the focus');

        $css = $this->sourceOf('admin/assets/admin.css');
        $this->assertStringContainsString('.admin-block-card-slot[hidden]{ display: none; }', $css);
        $this->assertStringContainsString('.admin-block-card__preview:focus-visible{', $css);
    }

    /** A module's blocks come with their preview, and go with it. */
    public function testAModulesBlocksHaveTheirPreviewWhileTheModuleIsOn(): void
    {
        $shopPage = ['id' => 9, 'content_key' => 'shop', 'slug' => 'shop', 'title' => 'Shop', 'status' => 'published'];
        $render = function () use ($shopPage): \DOMXPath {
            SectionRegistry::reset();
            ob_start();
            block_picker_modal(SectionRegistry::availableDefinitionsForPage($shopPage, new FakePageSectionRepository()), 9, 'test-csrf-token');

            return $this->xpath((string) ob_get_clean());
        };

        ModuleRegistry::overrideForTests(['shop' => true, 'multilingual' => true]);
        $on = $render();
        $collections = $this->one($on, '//*[@data-block-slot][button[@value="shop_collections"]]/button[@data-block-preview-open]');
        $this->assertSame('/admin/block-preview.php?type=shop_collections', $collections->getAttribute('data-block-preview-src'));
        $grid = $this->one($on, '//*[@data-block-slot][button[@value="product_grid"]]/button[@data-block-preview-open]');
        $this->assertFalse($grid->hasAttribute('data-block-preview-src'), 'the one block without a sample opens the dialog on its drawing');

        ModuleRegistry::overrideForTests(['shop' => false, 'multilingual' => true]);
        $off = $render();
        $this->assertSame(0, $off->query('//button[contains(@data-block-preview-src, "shop_collections") or contains(@data-block-preview-src, "product_grid")]')->length);
        SectionRegistry::reset();
    }

    // --- Accessibility and touch ---------------------------------------

    public function testThePickerIsADialogThatWorksWithoutAMouse(): void
    {
        $html = $this->renderPicker();

        $this->assertStringContainsString('role="dialog"', $html);
        $this->assertStringContainsString('aria-modal="true"', $html);
        $this->assertStringContainsString('aria-labelledby="admin-block-picker-title"', $html);
        $this->assertStringContainsString('aria-live="polite"', $html);
        $this->assertStringContainsString('aria-pressed=', $html);

        $script = $this->sourceOf('admin/assets/block-picker.js');
        $this->assertStringContainsString('Escape', $script, 'Escape must close the panel');
        $this->assertStringContainsString('lastFocused', $script, 'focus must return to the opener');
        $this->assertStringContainsString('Tab', $script, 'Tab must stay inside the open dialog');

        $this->assertStringNotContainsString(
            ':hover',
            $script,
            'nothing may be revealed only on hover — a touch device would never see it'
        );
    }

    // --- The save bar ---------------------------------------------------

    public function testEveryScreenReachedFromThePageBuilderCarriesTheSaveBar(): void
    {
        $editors = ['page.php'];
        foreach (BlockDefinitions::all() as $definition) {
            $url = $definition->editUrl([
                'id' => 1,
                'page_slug' => '__picker__',
                'section_type' => $definition->type(),
                'section_key' => 'custom-abcd1234',
                'section_id' => 1,
            ]);

            if ($url !== null && preg_match('#/admin/([a-z0-9-]+)\.php#', $url, $m) === 1) {
                $editors[] = $m[1] . '.php';
            }
        }

        foreach (array_unique($editors) as $editor) {
            $source = $this->sourceOf('admin/' . $editor);
            $this->assertStringContainsString('_save_bar.php', $source, "{$editor} does not include the save bar");
            $this->assertStringContainsString('save_bar()', $source, "{$editor} does not render the save bar");
            $this->assertStringContainsString('save_bar_script()', $source, "{$editor} does not load the save bar's script");
        }
    }

    /**
     * The save bar must never become a page-wide endpoint. It has no URL of
     * its own: it submits the forms that are already on the screen, to the
     * actions those forms already declare.
     */
    public function testTheSaveBarPostsOnlyToTheFormsOwnActions(): void
    {
        $script = $this->sourceOf('admin/assets/save-bar.js');

        $this->assertStringNotContainsString(
            '/api/admin/',
            $script,
            'a hardcoded endpoint here would be the page-wide POST this design refuses to build'
        );
        $this->assertStringContainsString('form.action', $script);
        $this->assertStringContainsString('requestSubmit()', $script, 'one dirty form is handed back to the browser');
    }

    /**
     * The sidebar's logout form is an ordinary POST form. A save button that
     * reached it would sign the editor out in the middle of an edit.
     */
    public function testTheSaveBarNeverReachesAFormOutsideThePagesOwnMain(): void
    {
        $script = $this->sourceOf('admin/assets/save-bar.js');

        $this->assertStringContainsString('main.admin-main form', $script);
        $this->assertStringContainsString('admin-inline-form', $script, 'the action-only forms are skipped');
        $this->assertStringContainsString('data-no-dirty-track', $script, 'a form may opt out by hand');
    }

    /**
     * "Opgeslagen" is the server's answer, not the browser's guess: every
     * write endpoint in this project appends ?saved=1 / ?updated=1 /
     * ?created=1 on success and redirects back without it on failure.
     */
    public function testTheSaveBarOnlyReportsSuccessTheServerConfirmed(): void
    {
        $script = $this->sourceOf('admin/assets/save-bar.js');

        $this->assertStringContainsString('saved|updated|created', $script);
        $this->assertStringContainsString('response.ok', $script);
        $this->assertStringContainsString('markClean', $script);

        $endpoints = (array) glob(dirname(__DIR__, 2) . '/api/admin/update-*.php');
        $this->assertNotSame([], $endpoints);

        $withMarker = 0;
        foreach ($endpoints as $endpoint) {
            $source = (string) file_get_contents($endpoint);
            if (preg_match('/[?&](saved|updated|created)=1/', $source) === 1) {
                $withMarker++;
            }
        }

        $this->assertGreaterThan(
            count($endpoints) * 0.9,
            $withMarker,
            'the success marker is what tells a saved redirect from a rejected one — it must stay near-universal'
        );
    }

    public function testTheLeaveWarningIsOnlyArmedWhileSomethingIsUnsaved(): void
    {
        $script = $this->sourceOf('admin/assets/save-bar.js');

        $this->assertMatchesRegularExpression(
            '/beforeunload[\s\S]{0,400}dirty\.length === 0/',
            $script,
            'a page with nothing unsaved must never warn on leaving'
        );
        $this->assertStringContainsString('leavingOnPurpose', $script, 'a save of our own must not warn');
    }

    /**
     * Two things only the server knows, said in markup. A form that shows
     * input which was sent but not written starts out unsaved — and after the
     * "just saved" flag, so it is never announced as saved. A link that
     * throws that input away on purpose stands the leave warning down, but
     * only for a plain click that navigates this tab.
     */
    public function testAScreenCanSayItsInputIsUnsentAndWhichLinkDiscardsIt(): void
    {
        $script = $this->sourceOf('admin/assets/save-bar.js');

        $this->assertStringContainsString('if (form.hasAttribute("data-save-bar-unsaved")) markDirty(form);', $script);
        $this->assertGreaterThan(
            strpos($script, 'sessionStorage.getItem(RELOAD_FLAG)'),
            strpos($script, 'data-save-bar-unsaved")) markDirty'),
            'an unsent form is marked after the reload flag, so "Opgeslagen" never covers it'
        );

        $this->assertMatchesRegularExpression(
            '/closest\("a\[href\]\[data-save-bar-discard\]"\)[\s\S]{0,200}event\.button !== 0[\s\S]{0,120}event\.ctrlKey \|\| event\.metaKey \|\| event\.shiftKey \|\| event\.altKey[\s\S]{0,80}leavingOnPurpose = true/',
            $script,
            'only a plain click on a discard link leaves without the warning'
        );

        $this->assertStringContainsString('data-save-bar-unsaved', $this->sourceOf('admin/_save_bar.php'), 'documented where a screen reads how to use the bar');
        $this->assertStringContainsString('data-save-bar-discard', $this->sourceOf('admin/_save_bar.php'));
    }

    /**
     * The rich-text editor rewrites its own field while it loads. Treating
     * that as an edit would mark every freshly opened editor dirty, which is
     * the mistake that makes a dirty indicator worth ignoring.
     */
    public function testARichTextEditorReportsUserEditsOnlyWhileLoadingSilently(): void
    {
        $source = $this->sourceOf('admin/assets/admin.js');

        $this->assertMatchesRegularExpression(
            '/quill\.on\("text-change"[\s\S]{0,600}source === "user"[\s\S]{0,200}dispatchEvent/',
            $source,
            'only a user edit may dispatch the input event the save bar listens for'
        );
    }
}

/**
 * A PageSectionRepository that answers from a fixed list instead of the
 * database, so "what may be added to this page" can be asked in the fast
 * tier. It overrides the constructor because the real one opens a
 * connection, and only findForPage() is ever called on this path.
 */
final class FakePageSectionRepository extends PageSectionRepository
{
    /** @param list<string> $types */
    public function __construct(private array $types = [])
    {
    }

    public function findForPage(int $pageId, bool $onlyActive = false): array
    {
        $rows = [];
        foreach ($this->types as $index => $type) {
            $rows[] = [
                'id' => $index + 1,
                'page_id' => $pageId,
                'section_type' => $type,
                'section_key' => 'custom-0000000' . $index,
                'section_id' => $index + 1,
                'sort_order' => $index,
                'is_active' => 1,
            ];
        }

        return $rows;
    }
}
